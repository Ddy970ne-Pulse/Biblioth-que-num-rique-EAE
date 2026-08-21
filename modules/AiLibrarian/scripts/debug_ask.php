<?php

/**
 * Script CLI pour reproduire l'endpoint /ai-librarian/ask sans passer par
 * Apache/HTTP, afin d'isoler l'étape qui pose problème (recherche vectorielle,
 * appel Anthropic, ou rendu vue).
 *
 * Usage :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/debug_ask.php "ta question"
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
$anthropicKey = $settings->get('ailibrarian_anthropic_api_key')
    ?: (getenv('ANTHROPIC_API_KEY') ?: null);
$model = $settings->get('ailibrarian_anthropic_model')
    ?: (getenv('ANTHROPIC_MODEL') ?: 'claude-opus-4-8');

$provider = new \AiLibrarian\Service\Embedding\VoyageEmbeddingProvider($voyageKey);
$answerService = new \AiLibrarian\Service\AnswerService(
    $api,
    $connection,
    $provider,
    $anthropicKey,
    $model
);

$query = $argv[1] ?? 'Qu\'est-ce que la ligne d\'Eden a Eden ?';
echo "Query : $query\n\n";

$t0 = microtime(true);
echo "[" . date('H:i:s') . "] Etape 1 : recherche vectorielle...\n";
$results = $answerService->search($query);
$t1 = microtime(true);
printf("  -> %d resultats en %.2fs\n", count($results), $t1 - $t0);
echo "  -> memoire : " . round(memory_get_peak_usage(true) / 1024 / 1024) . " MB\n\n";

foreach ($results as $i => $r) {
    printf("  #%d [%.0f%%] %s\n", $i + 1, $r['score'] * 100, $r['title']);
}
echo "\n";

echo "[" . date('H:i:s') . "] Etape 2 : appel Anthropic pour generation...\n";
$answer = $answerService->ask($query);
$t2 = microtime(true);
printf("  -> reponse en %.2fs\n", $t2 - $t1);
echo "  -> memoire finale : " . round(memory_get_peak_usage(true) / 1024 / 1024) . " MB\n\n";

echo "REPONSE GENEREE :\n";
echo "=================\n";
echo $answer['answer'] ?? '(pas de reponse)';
echo "\n\n";

echo "SOURCES :\n";
foreach ($answer['sources'] ?? [] as $s) {
    printf("  - [%s] %s (item #%d)\n", $s['resourceClass'] ?? '?', $s['title'], $s['item_id']);
}
