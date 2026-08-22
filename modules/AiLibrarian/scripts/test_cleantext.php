<?php

/**
 * Test du nettoyage cleanText() sur un item concret : montre le texte extrait
 * avant chunking, longueur estimée après vs avant, et un aperçu du contenu
 * après filtrage. Permet de valider le fix anti-TOC sans réindexer tout.
 *
 * Usage :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/test_cleantext.php <item_id>
 *   ex : docker compose exec omeka php /.../test_cleantext.php 154
 */

chdir('/var/www/html');
require 'bootstrap.php';
require '/var/www/html/modules/AiLibrarian/vendor/autoload.php';

$app = \Laminas\Mvc\Application::init(require 'application/config/application.config.php');
$container = $app->getServiceManager();
$api = $container->get('Omeka\ApiManager');
$connection = $container->get('Omeka\Connection');

$provider = new \AiLibrarian\Service\Embedding\LocalHashEmbeddingProvider();
$indexer = new \AiLibrarian\Service\CorpusIndexer($api, $connection, $provider);

$itemId = (int) ($argv[1] ?? 154);
$item = $api->read('items', $itemId)->getContent();

// Accès aux propriétés brutes via reflection (car extractText/cleanText sont private)
$reflection = new \ReflectionClass($indexer);

$extractText = $reflection->getMethod('extractText');
$extractText->setAccessible(true);

$splitIntoChunks = $reflection->getMethod('splitIntoChunks');
$splitIntoChunks->setAccessible(true);

echo "=== Item #$itemId : " . $item->displayTitle() . " ===" . PHP_EOL . PHP_EOL;

// Texte brut (avant nettoyage) : concat abstract + description
$raw = $item->displayTitle();
foreach (['dcterms:abstract', 'dcterms:description', 'mvt:messageCle'] as $term) {
    foreach ($item->value($term, ['all' => true]) as $value) {
        $raw .= "\n\n" . (string) $value;
    }
}

// Texte extrait (après nettoyage + dédup)
$extracted = $extractText->invoke($indexer, $item);

// Chunks
$chunks = $splitIntoChunks->invoke($indexer, $extracted);

echo "Longueur brute (avec doublons + TOC) : " . mb_strlen($raw) . " caracteres" . PHP_EOL;
echo "Longueur nettoyee               : " . mb_strlen($extracted) . " caracteres" . PHP_EOL;
echo "Gain                            : " . round((1 - mb_strlen($extracted) / max(1, mb_strlen($raw))) * 100) . "%" . PHP_EOL;
echo "Nombre de chunks                : " . count($chunks) . PHP_EOL . PHP_EOL;

echo "=== APERCU premier chunk apres nettoyage ===" . PHP_EOL;
echo mb_substr($chunks[0] ?? '', 0, 400) . "..." . PHP_EOL . PHP_EOL;

if (count($chunks) > 3) {
    echo "=== APERCU chunk milieu (index " . (int)(count($chunks)/2) . ") ===" . PHP_EOL;
    echo mb_substr($chunks[(int)(count($chunks)/2)], 0, 400) . "..." . PHP_EOL;
}
