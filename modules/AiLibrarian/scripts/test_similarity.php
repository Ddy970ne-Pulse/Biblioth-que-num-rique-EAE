<?php

/**
 * Test de similarité cosinus directe entre une query et les chunks d'un item.
 * Permet de voir le score exact d'un chunk pour comprendre pourquoi il
 * ne remonte pas dans le top-K.
 *
 * Usage :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/test_similarity.php <item_id> "<query>"
 */

chdir('/var/www/html');
require 'bootstrap.php';
require '/var/www/html/modules/AiLibrarian/vendor/autoload.php';

$app = \Laminas\Mvc\Application::init(require 'application/config/application.config.php');
$container = $app->getServiceManager();
$connection = $container->get('Omeka\Connection');

$itemId = (int) ($argv[1] ?? 983);
$query = $argv[2] ?? 'Qui est le premier roi du 7eme royaume ?';

$voyageKey = getenv('VOYAGE_API_KEY');
$provider = new \AiLibrarian\Service\Embedding\VoyageEmbeddingProvider($voyageKey);

echo "Query : $query" . PHP_EOL;
echo "Item ID : $itemId" . PHP_EOL . PHP_EOL;

// Embed la query en mode TYPE_QUERY
$queryVector = $provider->embed(
    $query,
    \AiLibrarian\Service\Embedding\EmbeddingProviderInterface::TYPE_QUERY
);
echo "Query vecteur : dimension " . count($queryVector) . PHP_EOL . PHP_EOL;

// Récupérer les chunks de l'item
$rows = $connection->fetchAllAssociative(
    'SELECT chunk_index, content, embedding FROM ai_librarian_chunk WHERE item_id = :id',
    ['id' => $itemId]
);

echo "Chunks de l'item : " . count($rows) . PHP_EOL . PHP_EOL;

function cosine(array $a, array $b): float {
    $n = min(count($a), count($b));
    $dot = 0.0; $nA = 0.0; $nB = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $nA += $a[$i] * $a[$i];
        $nB += $b[$i] * $b[$i];
    }
    if ($nA === 0.0 || $nB === 0.0) return 0.0;
    return $dot / (sqrt($nA) * sqrt($nB));
}

foreach ($rows as $row) {
    $vec = json_decode($row['embedding'], true);
    $score = cosine($queryVector, $vec);
    printf("Chunk %d : score %.4f (%.1f%%)" . PHP_EOL, $row['chunk_index'], $score, $score * 100);
    echo "Contenu : " . substr($row['content'], 0, 200) . PHP_EOL . PHP_EOL;
}

// Comparaison avec la 15ème valeur du top actuel pour voir l'écart
echo "---" . PHP_EOL;
echo "Pour référence : le 15ème résultat du top-K pour cette query était vers 62% (voir dernier test Q4)." . PHP_EOL;
