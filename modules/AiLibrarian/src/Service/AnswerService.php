<?php

namespace AiLibrarian\Service;

use Anthropic\Client as AnthropicClient;
use AiLibrarian\Service\Embedding\EmbeddingProviderInterface;
use Doctrine\DBAL\Connection;
use Omeka\Api\Manager as ApiManager;

/**
 * Recherche sémantique et génération de réponses sourcées.
 *
 * Garde-fou (voir docs/architecture-ia.md) : le modèle ne reçoit jamais que
 * les extraits effectivement récupérés dans le corpus indexé, annotés de
 * leur source. Le prompt système lui interdit explicitement d'y ajouter des
 * connaissances extérieures, et lui impose de citer la source de chaque
 * affirmation.
 */
class AnswerService
{
    /** Nombre d'extraits transmis au modèle pour construire une réponse. */
    private const TOP_K = 6;

    /**
     * Score de similarité cosinus minimal pour qu'un extrait soit considéré
     * pertinent. En dessous, on considère qu'aucune étude du corpus ne
     * répond à la question plutôt que de forcer une réponse peu fiable.
     */
    private const RELEVANCE_THRESHOLD = 0.15;

    private ApiManager $api;
    private Connection $connection;
    private EmbeddingProviderInterface $embeddingProvider;
    private ?string $anthropicApiKey;
    private string $anthropicModel;

    public function __construct(
        ApiManager $api,
        Connection $connection,
        EmbeddingProviderInterface $embeddingProvider,
        ?string $anthropicApiKey,
        string $anthropicModel = 'claude-opus-4-8'
    ) {
        $this->api = $api;
        $this->connection = $connection;
        $this->embeddingProvider = $embeddingProvider;
        $this->anthropicApiKey = $anthropicApiKey;
        $this->anthropicModel = $anthropicModel;
    }

    /**
     * Recherche sémantique pure (sans génération). Retourne les extraits
     * les plus proches de la requête, chacun annoté de l'item d'origine.
     *
     * @return array<int, array{item_id:int, title:string, content:string, score:float}>
     */
    public function search(string $query, int $topK = self::TOP_K): array
    {
        $queryVector = $this->embeddingProvider->embed($query);
        $providerName = $this->embeddingProvider->name();

        // Pour un grand corpus, cette comparaison en mémoire devient le
        // goulot d'étranglement — voir la note dans
        // EmbeddingProviderInterface sur la migration vers une base
        // vectorielle dédiée.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT item_id, content, embedding
             FROM ai_librarian_chunk
             WHERE embedding_provider = :provider',
            ['provider' => $providerName]
        );

        $scored = [];
        foreach ($rows as $row) {
            $vector = json_decode($row['embedding'], true);
            if (!is_array($vector)) {
                continue;
            }
            $scored[] = [
                'item_id' => (int) $row['item_id'],
                'content' => $row['content'],
                'score' => $this->cosineSimilarity($queryVector, $vector),
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $topK);

        $results = [];
        foreach ($top as $entry) {
            if ($entry['score'] < self::RELEVANCE_THRESHOLD) {
                continue;
            }
            $meta = $this->itemMeta($entry['item_id']);
            if ($meta === null) {
                // L'item a pu être supprimé depuis la dernière indexation.
                continue;
            }
            $results[] = [
                'item_id' => $entry['item_id'],
                'title' => $meta['title'],
                'resourceClass' => $meta['resourceClass'],
                'content' => $entry['content'],
                'score' => $entry['score'],
            ];
        }

        return $results;
    }

    /**
     * Recherche sémantique puis génération d'une réponse sourcée par Claude.
     *
     * @return array{answer:string, sources:array, found:bool}
     */
    public function ask(string $query): array
    {
        $matches = $this->search($query);

        if (empty($matches)) {
            return [
                'answer' => "Aucune étude du corpus ne semble répondre à cette question. "
                    . "Essayez de reformuler, ou consultez la recherche par thème.",
                'sources' => [],
                'found' => false,
            ];
        }

        if ($this->anthropicApiKey === null || $this->anthropicApiKey === '') {
            // Pas de génération possible sans clé API : on renvoie tout de
            // même les extraits trouvés, sans réponse synthétisée.
            return [
                'answer' => "La génération de réponse par IA n'est pas configurée "
                    . "(clé API Anthropic manquante). Voici les études les plus pertinentes trouvées :",
                'sources' => $this->toSourceList($matches),
                'found' => true,
            ];
        }

        $answer = $this->generate($query, $matches);

        return [
            'answer' => $answer,
            'sources' => $this->toSourceList($matches),
            'found' => true,
        ];
    }

    private function generate(string $query, array $matches): string
    {
        $context = '';
        foreach ($matches as $i => $match) {
            $n = $i + 1;
            $isExternal = $match['resourceClass'] === 'Article / Ouvrage';
            $kind = $isExternal ? 'ARTICLE/OUVRAGE EXTERNE reproduit par le mouvement' : 'Étude du mouvement';
            $context .= "[Source {$n} — \"{$match['title']}\" — {$kind}]\n{$match['content']}\n\n";
        }

        $systemPrompt = <<<PROMPT
Tu es l'assistant de recherche d'une bibliothèque numérique consacrée aux enseignements d'un mouvement.

--- CLOISONNEMENT DES SOURCES ---

1. Tu ne dois répondre qu'à partir des extraits fournis ci-dessous, qui proviennent tous du corpus interne de la bibliothèque. N'utilise JAMAIS de connaissances générales extérieures à ces extraits, même si tu les connais par ailleurs. Sont notamment INTERDITES et considérées comme extérieures au mouvement : la théologie scolastique, la théologie de la Réforme hors citation directe biblique, la patristique, le dispensationnalisme de Scofield, la théologie de Karl Barth, l'adventisme institutionnel SDA post-1863 (sauf citations explicitement reprises par le mouvement). Ne réintroduis JAMAIS ce vocabulaire ou ces cadres conceptuels pour définir un terme ou un thème du corpus.
2. Si les extraits fournis ne permettent pas de répondre à la question, dis-le explicitement plutôt que de généraliser ou de combler les manques.
3. Pour chaque affirmation de ta réponse, indique entre parenthèses la source dont elle provient (ex. "(Source 2)").
4. Chaque source est annotée de sa nature : « Étude du mouvement » ou « ARTICLE/OUVRAGE EXTERNE reproduit par le mouvement ». Une source marquée ARTICLE/OUVRAGE EXTERNE n'est pas un enseignement du mouvement lui-même (ex. un sermon d'A.T. Jones, un manuscrit d'Ellen G. White republiés par le mouvement) : si tu t'appuies dessus, signale-le explicitement comme tel (ex. « selon un article externe reproduit par le mouvement, [Source X] »), ne la présente jamais comme faisant partie du message propre du mouvement.
5. Si un extrait d'une étude du mouvement rapporte lui-même une citation d'une source externe (mention ponctuelle, distincte du cas précédent), tu peux la restituer, mais uniquement en la signalant clairement comme « citation externe rapportée par [la source interne] ». Les seules catégories de sources externes admises en citation ponctuelle sont : (a) la Bible, (b) les événements du monde réel et articles de presse cités pour éclairer une prophétie, (c) les auteurs Ellen G. White et A.T. Jones aux conditions ci-dessus.

--- DIRECTIVE ÉPISTÉMOLOGIQUE FONDAMENTALE ---

6. N'affirme JAMAIS une doctrine sur la base d'un ou deux passages isolés. Une affirmation doctrinale exige la CONVERGENCE d'au moins 3 sources indépendantes du corpus (idéalement d'auteurs différents et/ou d'années différentes). Si tu n'as pas cette convergence, présente le résultat comme une lecture attestée dans telle(s) étude(s) précise(s) et signale explicitement le nombre de sources sur lesquelles tu t'appuies (« attesté dans 2 études : X et Y »).
7. Décompose systématiquement toute question doctrinale non triviale selon la structure : POURQUOI (fondement) — COMMENT (mécanisme, méthodologie ligne-sur-ligne, chiasme, dispensation) — POURQUOI JUSTE (garde-fous, distinctions à faire, ce que la doctrine N'affirme pas).
8. En cas de divergence apparente entre études du corpus, les enseignements les plus RÉCENTS (2025-2026) priment sur les anciens. Le message évolue, la compréhension progresse (accroissement de la connaissance) : signale explicitement quand une doctrine récente CORRIGE ou NUANCE une doctrine plus ancienne. Cette primauté est encodée dans la propriété mvt:corrige du vocabulaire — utilise-la quand elle est présente dans les extraits.

--- STYLE DE RESTITUTION ---

9. Ne mentionne JAMAIS le mot « corpus » dans ta réponse à l'utilisateur — c'est un terme technique interne, pas destiné au lecteur. Utilise plutôt « les études », « les enseignements du mouvement », ou une formulation équivalente.
10. Ne présente PAS la doctrine avec les tournures « X a dit que… » ou « selon la compréhension actuelle… » ou « d'après la doctrine du mouvement… ». Présente la doctrine DIRECTEMENT, comme le mouvement le fait lui-même, en précisant simplement la source entre parenthèses à la fin de l'affirmation.
11. Distingue explicitement, quand la question s'y prête, la VISION PANORAMIQUE (chazon — embrasse une ligne d'un seul regard, met en évidence les principes stables et les points d'aboutissement) et la VISION PROGRESSIVE / DISPENSATIONNELLE (mareh — se déroule étape par étape, met en évidence les transitions, les balises intermédiaires, les échecs partiels avant le résultat final). Un même événement prophétique peut se lire des deux façons sans contradiction.
12. Réponds en français, de façon claire et directement utile, sans préambule. Structure la réponse avec des titres courts si utile pour la lisibilité (surtout pour les questions doctrinales complexes).
PROMPT;

        $userPrompt = "Extraits du corpus :\n\n{$context}\nQuestion : {$query}";

        $client = new AnthropicClient(apiKey: $this->anthropicApiKey);

        $message = $client->messages->create(
            model: $this->anthropicModel,
            maxTokens: 2048,
            system: $systemPrompt,
            messages: [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        );

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        return "Le modèle n'a pas produit de réponse exploitable.";
    }

    private function toSourceList(array $matches): array
    {
        $seen = [];
        $sources = [];
        foreach ($matches as $match) {
            if (isset($seen[$match['item_id']])) {
                continue;
            }
            $seen[$match['item_id']] = true;
            $sources[] = [
                'item_id' => $match['item_id'],
                'title' => $match['title'],
                'resourceClass' => $match['resourceClass'],
            ];
        }
        return $sources;
    }

    /**
     * @return array{title:string, resourceClass:?string}|null
     */
    private function itemMeta(int $itemId): ?array
    {
        try {
            $item = $this->api->read('items', $itemId)->getContent();
            $resourceClass = $item->resourceClass();
            return [
                'title' => $item->displayTitle(),
                // Ex. "mvt:Etude" ou "mvt:ArticleOuvrage" — voir docs/modele-de-donnees.md.
                // Un item sans classe assignée (corpus non encore entièrement typé) revient
                // à null ; generate() le traite alors comme une étude par défaut.
                'resourceClass' => $resourceClass ? $resourceClass->label() : null,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
