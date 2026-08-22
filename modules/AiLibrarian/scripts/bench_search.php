<?php
/**
 * Benchmark de la recherche hybride (BM25 + pgvector RRF) sur les 7 questions
 * doctrinales de validation. Isole la latence de recherche (search()) et
 * n'appelle PAS Claude — pour mesurer uniquement le gain pgvector.
 *
 * Usage :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/bench_search.php
 */

require_once '/var/www/html/bootstrap.php';

use AiLibrarian\Service\AnswerService;

$app = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$serviceManager = $app->getServiceManager();
/** @var AnswerService $answer */
$answer = $serviceManager->get(AnswerService::class);

$questions = [
    'Q1' => 'Comment interpréter le message des trois anges ?',
    'Q2' => 'Que signifie la ligne de l\'Humanité ?',
    'Q3' => 'Quel est le rôle des balises prophétiques ?',
    'Q4' => 'Qui est le premier roi du septième royaume ?',
    'Q5' => 'Comment reconnaître les faux enseignants ?',
    'Q6' => 'Quelle est la différence entre chazon et mareh ?',
    'Q7' => 'Que dit le mouvement sur les 144 000 ?',
];

echo "=== Benchmark AnswerService::search() ===\n";
echo str_repeat('-', 80) . "\n";
printf("%-4s | %-60s | %8s | %5s\n", 'ID', 'Question', 'Latence', 'Top-K');
echo str_repeat('-', 80) . "\n";

$total = 0.0;
foreach ($questions as $id => $q) {
    $start = microtime(true);
    $results = $answer->search($q);
    $ms = (microtime(true) - $start) * 1000;
    $total += $ms;

    printf("%-4s | %-60s | %6.0f ms | %5d\n",
        $id, mb_substr($q, 0, 58), $ms, count($results));
}

echo str_repeat('-', 80) . "\n";
printf("Total : %d ms | Moyenne : %d ms/requête\n", (int) $total, (int) ($total / count($questions)));
