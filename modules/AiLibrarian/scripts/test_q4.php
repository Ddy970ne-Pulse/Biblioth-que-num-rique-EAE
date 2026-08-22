<?php
/**
 * Test doctrinal Q4 : Biden = premier roi du septième royaume.
 * Appelle AnswerService::ask() directement (bypass HTTP), affiche la
 * réponse générée par Claude + les sources retenues.
 *
 * Usage :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/test_q4.php
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/test_q4.php "Ta question personnalisée"
 */

require_once '/var/www/html/bootstrap.php';

use AiLibrarian\Service\AnswerService;

$app = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$sm = $app->getServiceManager();
/** @var AnswerService $svc */
$svc = $sm->get(AnswerService::class);

$question = $argv[1] ?? 'Qui est le premier roi du septième royaume ?';

echo str_repeat('=', 80) . "\n";
echo "QUESTION : {$question}\n";
echo str_repeat('=', 80) . "\n\n";

$start = microtime(true);
$res = $svc->ask($question);
$ms = (int) ((microtime(true) - $start) * 1000);

echo "found       : " . ($res['found'] ? 'TRUE' : 'FALSE') . "\n";
echo "latence     : {$ms} ms\n";
echo "nb sources  : " . count($res['sources']) . "\n\n";

echo "=== RÉPONSE ===\n";
echo $res['answer'] . "\n\n";

echo "=== SOURCES ===\n";
foreach ($res['sources'] as $i => $src) {
    $n = $i + 1;
    $class = $src['resourceClass'] ?? 'non-classée';
    echo "[{$n}] item #{$src['item_id']} | {$class}\n    {$src['title']}\n";
}
