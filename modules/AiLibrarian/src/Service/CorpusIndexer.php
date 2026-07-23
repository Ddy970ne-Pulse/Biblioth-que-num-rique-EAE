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
    /** Taille cible d'un extrait, en caractères. */
    private const CHUNK_SIZE = 800;

    /** Chevauchement entre deux extraits consécutifs, en caractères. */
    private const CHUNK_OVERLAP = 100;

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
     * indexer. Adapter cette liste de propriétés si le modèle de données
     * (docs/modele-de-donnees.md) évolue.
     */
    private function extractText(ItemRepresentation $item): string
    {
        $parts = [$item->displayTitle()];

        foreach (['dcterms:abstract', 'dcterms:description', 'mvt:messageCle'] as $term) {
            foreach ($item->value($term, ['all' => true]) as $value) {
                $parts[] = (string) $value;
            }
        }

        return implode("\n\n", array_filter($parts));
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

        $chunks = [];
        $start = 0;

        while ($start < $length) {
            $chunk = mb_substr($text, $start, self::CHUNK_SIZE, 'UTF-8');

            // Évite de couper au milieu d'un mot : recule jusqu'au dernier
            // espace si l'extrait ne va pas jusqu'à la fin du texte.
            if ($start + self::CHUNK_SIZE < $length) {
                $lastSpace = mb_strrpos($chunk, ' ', 0, 'UTF-8');
                if ($lastSpace !== false && $lastSpace > 0) {
                    $chunk = mb_substr($chunk, 0, $lastSpace, 'UTF-8');
                }
            }

            $chunks[] = trim($chunk);
            $start += max(1, mb_strlen($chunk, 'UTF-8') - self::CHUNK_OVERLAP);
        }

        return array_values(array_filter($chunks, static fn ($c) => $c !== ''));
    }
}
