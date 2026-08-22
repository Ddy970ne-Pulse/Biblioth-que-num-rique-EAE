<?php

namespace AiLibrarian\Service;

use AiLibrarian\Service\Embedding\EmbeddingProviderInterface;
use Doctrine\DBAL\Connection;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Découpe les items du corpus en extraits et calcule leur vecteur
 * d'embedding pour la recherche sémantique. Voir docs/architecture-ia.md.
 *
 * Seuls les items effectivement indexés ici sont visibles de la recherche
 * sémantique et de l'assistant IA — c'est le premier des deux niveaux de
 * cloisonnement du corpus décrits dans docs/architecture-ia.md.
 */
class CorpusIndexer
{
    /** Taille cible d'un extrait, en caractères.
     *  400 caractères ≈ 60-70 mots ≈ un paragraphe. Réduit vs les 800 initiaux
     *  pour que chaque chunk soit sémantiquement plus discriminant : un chunk
     *  trop long finit par diluer plusieurs sujets dans un seul vecteur, ce qui
     *  fait converger toutes les similarités autour d'une même valeur moyenne
     *  (~70 %). */
    private const CHUNK_SIZE = 400;

    /** Chevauchement entre deux extraits consécutifs, en caractères.
     *  Maintient un contexte de continuité entre chunks voisins sans exploser
     *  le nombre total de chunks (ratio 50/400 = 12,5 %, comme 100/800). */
    private const CHUNK_OVERLAP = 50;

    /** Nombre d'items traités par page lors d'une réindexation complète. */
    private const PAGE_SIZE = 25;

    private ApiManager $api;
    private Connection $connection;
    private EmbeddingProviderInterface $embeddingProvider;

    public function __construct(
        ApiManager $api,
        Connection $connection,
        EmbeddingProviderInterface $embeddingProvider
    ) {
        $this->api = $api;
        $this->connection = $connection;
        $this->embeddingProvider = $embeddingProvider;
    }

    /**
     * Réindexe l'intégralité du corpus. Retourne le nombre d'items traités.
     *
     * @param callable|null $progress Appelé après chaque item avec
     *   (int $processed, int $total) pour suivre l'avancement (ex. depuis
     *   le contrôleur d'administration).
     */
    public function reindexAll(?callable $progress = null): int
    {
        $total = $this->api->search('items', ['limit' => 0])->getTotalResults();
        $processed = 0;
        $page = 1;

        do {
            $response = $this->api->search('items', [
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'sort_by' => 'id',
            ]);
            $items = $response->getContent();

            foreach ($items as $item) {
                $this->indexItem($item);
                $processed++;
                if ($progress !== null) {
                    $progress($processed, $total);
                }
            }

            $page++;
        } while (count($items) === self::PAGE_SIZE);

        return $processed;
    }

    public function reindexItemById(int $itemId): void
    {
        $item = $this->api->read('items', $itemId)->getContent();
        $this->indexItem($item);
    }

    private function indexItem(ItemRepresentation $item): void
    {
        $text = $this->extractText($item);

        // Supprime les extraits existants avant de réindexer, pour éviter
        // d'accumuler des extraits obsolètes si le contenu a été raccourci.
        $this->connection->executeStatement(
            'DELETE FROM ai_librarian_chunk WHERE item_id = :item_id',
            ['item_id' => $item->id()]
        );

        if (trim($text) === '') {
            return;
        }

        $chunks = $this->splitIntoChunks($text);
        if (empty($chunks)) {
            return;
        }

        $vectors = $this->embeddingProvider->embedBatch($chunks);
        $providerName = $this->embeddingProvider->name();

        foreach ($chunks as $index => $chunk) {
            $this->connection->executeStatement(
                'INSERT INTO ai_librarian_chunk
                    (item_id, chunk_index, content, embedding, embedding_provider, created)
                 VALUES (:item_id, :chunk_index, :content, :embedding, :embedding_provider, NOW())',
                [
                    'item_id' => $item->id(),
                    'chunk_index' => $index,
                    'content' => $chunk,
                    'embedding' => json_encode($vectors[$index]),
                    'embedding_provider' => $providerName,
                ]
            );
        }
    }

    /**
     * Concatène les propriétés textuelles pertinentes de l'item (titre,
     * résumé, description/texte intégral, message clé) en un seul texte à
     * indexer, en dédupliquant les valeurs identiques et en filtrant le
     * bruit d'OCR (tables des matières, renvois de page, séquences de
     * pointillés). Adapter cette liste de propriétés si le modèle de
     * données (docs/modele-de-donnees.md) évolue.
     */
    private function extractText(ItemRepresentation $item): string
    {
        $parts = [$item->displayTitle()];
        $seen = []; // dédup exact string pour éviter abstract == description

        foreach (['dcterms:abstract', 'dcterms:description', 'mvt:messageCle'] as $term) {
            foreach ($item->value($term, ['all' => true]) as $value) {
                $text = (string) $value;
                $cleaned = $this->cleanText($text);
                if ($cleaned === '') {
                    continue;
                }
                $hash = md5($cleaned);
                if (isset($seen[$hash])) {
                    continue; // même contenu déjà pris (typiquement abstract == description)
                }
                $seen[$hash] = true;
                $parts[] = $cleaned;
            }
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Nettoie un texte extrait d'OCR pour améliorer la qualité sémantique
     * des chunks. Supprime les patterns de bruit typiques des PDF OCR :
     *  - lignes de table des matières « Titre .............. 12 »
     *  - renvois de page « (page 3-4) », « p. 12 », « voir page 5 »
     *  - séquences de pointillés/tirets/underscores de mise en page
     *  - lignes ne contenant que des numéros de page ou de section
     *
     * Ces éléments faisaient converger les similarités vectorielles (tous
     * les chunks d'un TOC se ressemblent) et diluaient le contenu doctrinal
     * dans le bruit typographique.
     */
    private function cleanText(string $text): string
    {
        // Chaque preg_replace peut retourner null en cas d'erreur regex
        // (typiquement chaîne trop longue qui dépasse pcre.backtrack_limit).
        // On préserve la valeur précédente si null pour éviter les warnings
        // « Passing null to parameter #3 » et une potentielle chaîne cassée.
        $safeReplace = static function (string $pattern, string $replacement, string $subject): string {
            $result = preg_replace($pattern, $replacement, $subject);
            return $result === null ? $subject : $result;
        };

        // Lignes de TOC : n'importe quoi + 4+ points/tirets/underscores + numéro final
        $text = $safeReplace('/^.*[.\-_]{4,}\s*\d+\s*$/mu', '', $text);
        // Renvois de page entre parenthèses : « (page 3-4) », « (p. 12) »
        $text = $safeReplace('/\(\s*p(?:age)?\.?\s*\d+(?:\s*[-–]\s*\d+)?\s*\)/iu', '', $text);
        // Séquences de 4+ pointillés/tirets/underscores dans une ligne
        $text = $safeReplace('/[.\-_]{4,}/u', ' ', $text);
        // Lignes ne contenant qu'un numéro de page/section
        $text = $safeReplace('/^\s*\d+\s*$/m', '', $text);
        // Normalisation des espaces (multiples → simple, retours à la ligne préservés)
        $text = $safeReplace('/[ \t]+/', ' ', $text);
        $text = $safeReplace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * @return string[]
     */
    private function splitIntoChunks(string $text): array
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        $length = mb_strlen($text, 'UTF-8');

        if ($length <= self::CHUNK_SIZE) {
            return [$text];
        }

        // Avance minimale à chaque itération : suffisante pour progresser
        // significativement, sinon on produit des dizaines de chunks
        // quasi-identiques qui décalent d'1 caractère à chaque tour (bug
        // observé sur les descriptions courtes riches en mots-clés).
        // Cible : ~87 % de CHUNK_SIZE (400 - 50 overlap = 350).
        $minAdvance = self::CHUNK_SIZE - self::CHUNK_OVERLAP;

        // Taille minimale d'un chunk pour être retenu — évite de produire
        // des chunks résiduels de moins de 50 caractères qui n'apportent
        // rien sémantiquement et polluent le top-K de la recherche.
        $minChunkSize = self::CHUNK_SIZE / 4;

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $chunk = mb_substr($text, $start, self::CHUNK_SIZE, 'UTF-8');

            // Évite de couper au milieu d'un mot : recule jusqu'au dernier
            // espace si l'extrait ne va pas jusqu'à la fin du texte, ET
            // uniquement si le recul reste raisonnable (au moins la moitié
            // de CHUNK_SIZE conservée) — sinon on garde le chunk complet
            // pour ne pas produire de chunks trop petits.
            if ($start + self::CHUNK_SIZE < $length) {
                $lastSpace = mb_strrpos($chunk, ' ', 0, 'UTF-8');
                if ($lastSpace !== false && $lastSpace > self::CHUNK_SIZE / 2) {
                    $chunk = mb_substr($chunk, 0, $lastSpace, 'UTF-8');
                }
            }

            $trimmed = trim($chunk);
            $chunkLen = mb_strlen($trimmed, 'UTF-8');

            // Ignore les chunks résiduels trop courts (typiquement la fin
            // du texte qui produit un chunk minuscule sans valeur).
            if ($chunkLen < $minChunkSize && $start > 0) {
                break;
            }

            if ($chunkLen > 0) {
                $chunks[] = $trimmed;
            }

            // Avance de la longueur du chunk moins l'overlap, mais AU MOINS
            // de $minAdvance caractères pour garantir un progrès net.
            // Sans ce plancher, la boucle avance parfois d'1 caractère
            // seulement quand chunkLen ≈ CHUNK_OVERLAP → boucle quasi-infinie.
            $start += max($minAdvance, mb_strlen($chunk, 'UTF-8') - self::CHUNK_OVERLAP);
        }

        return array_values(array_filter($chunks, static fn ($c) => $c !== ''));
    }
}
