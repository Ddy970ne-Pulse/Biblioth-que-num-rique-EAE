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
            $title = $this->itemTitle($entry['item_id']);
            if ($title === null) {
                // L'item a pu être supprimé depuis la dernière indexation.
                continue;
            }
            $results[] = [
                'item_id' => $entry['item_id'],
                'title' => $title,
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
            $context .= "[Source {$n} — \"{$match['title']}\"]\n{$match['content']}\n\n";
        }

        $systemPrompt = <<<PROMPT
Tu es l'assistant de recherche d'une bibliothèque numérique consacrée aux enseignements d'un mouvement.

Règles impératives :
1. Tu ne dois répondre qu'à partir des extraits fournis ci-dessous, qui proviennent tous d'études du corpus interne de la bibliothèque. N'utilise JAMAIS de connaissances générales extérieures à ces extraits, même si tu les connais par ailleurs.
2. Si les extraits fournis ne permettent pas de répondre à la question, dis-le explicitement plutôt que de généraliser ou de combler les manques.
3. Pour chaque affirmation de ta réponse, indique entre parenthèses la source dont elle provient (ex. "(Source 2)").
4. Si un extrait rapporte lui-même une citation d'une source externe au mouvement, tu peux la restituer, mais uniquement en la signalant clairement comme « citation externe rapportée par [la source interne] » — ne la présente jamais comme faisant partie du message enseigné par le mouvement lui-même.
5. Réponds en français, de façon claire et directement utile, sans préambule.
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
            ];
        }
        return $sources;
    }

    private function itemTitle(int $itemId): ?string
    {
        try {
            $item = $this->api->read('items', $itemId)->getContent();
            return $item->displayTitle();
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
