<?php

/**
 * Réindexe un seul item (utile pour tester des ajustements sans relancer
 * les 960 items à chaque fois).
 *
 * Usage :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/reindex_one.php <item_id>
 */

chdir('/var/www/html');
require 'bootstrap.php';
require '/var/www/html/modules/AiLibrarian/vendor/autoload.php';

$app = \Laminas\Mvc\Application::init(require 'application/config/application.config.php');
$container = $app->getServiceManager();

$api = $container->get('Omeka\ApiManager');
$connection = $container->get('Omeka\Connection');
$settings = $container->get('Omeka\Settings');

$voyageKey = $settings->get('ailibrarian_voyage_api_key')
    ?: (getenv('VOYAGE_API_KEY') ?: null);

if (!empty($voyageKey)) {
    $provider = new \AiLibrarian\Service\Embedding\VoyageEmbeddingProvider($voyageKey);
    echo "Provider : voyage-3" . PHP_EOL;
} else {
    $provider = new \AiLibrarian\Service\Embedding\LocalHashEmbeddingProvider();
    echo "Provider : local-hash (fallback)" . PHP_EOL;
}

$indexer = new \AiLibrarian\Service\CorpusIndexer($api, $connection, $provider);

$itemId = (int) ($argv[1] ?? 983);
echo "Reindexation de l'item #$itemId..." . PHP_EOL;

$start = time();
$indexer->reindexItemById($itemId);
$duration = time() - $start;

// Vérifier
$count = $connection->fetchOne('SELECT COUNT(*) FROM ai_librarian_chunk WHERE item_id = ?', [$itemId]);
echo "Termine en {$duration}s : {$count} chunks pour l'item #{$itemId}" . PHP_EOL;
