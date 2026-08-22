<?php
/**
 * Migration des embeddings MySQL (ai_librarian_chunk.embedding LONGTEXT JSON)
 * vers Postgres/pgvector (embeddings.embedding vector(1024)).
 *
 * À exécuter depuis le conteneur Omeka :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/migrate_to_pgvector.php
 *
 * Idempotent : ON CONFLICT (chunk_id) DO NOTHING permet une reprise après
 * interruption sans doublons. Streamé (unbuffered MySQL) pour ne pas charger
 * les 85k lignes en RAM d'un coup.
 */

$mysqlHost = getenv('MYSQL_HOST') ?: 'db';
$mysqlDb   = getenv('MYSQL_DATABASE');
$mysqlUser = getenv('MYSQL_USER');
$mysqlPwd  = getenv('MYSQL_PASSWORD');

$pgHost = getenv('VECTORS_HOST') ?: 'vectors';
$pgPort = getenv('VECTORS_PORT') ?: '5432';
$pgDb   = getenv('VECTORS_DATABASE') ?: 'vectors';
$pgUser = getenv('VECTORS_USER') ?: 'vectors';
$pgPwd  = getenv('VECTORS_PASSWORD') ?: 'vectors_local_pwd_2026';

$batchSize = 500;

echo "=== Migration ai_librarian_chunk (MySQL) → embeddings (pgvector) ===\n";
echo "MySQL: {$mysqlUser}@{$mysqlHost}/{$mysqlDb}\n";
echo "Postgres: {$pgUser}@{$pgHost}:{$pgPort}/{$pgDb}\n";
echo "Batch size: {$batchSize}\n\n";

try {
    $mysql = new PDO(
        "mysql:host={$mysqlHost};dbname={$mysqlDb};charset=utf8mb4",
        $mysqlUser,
        $mysqlPwd,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Streaming : ne charge pas les 85k lignes en RAM
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
        ]
    );

    $pg = new PDO(
        "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}",
        $pgUser,
        $pgPwd,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Erreur connexion : " . $e->getMessage() . "\n");
    exit(1);
}

// Total pour la barre de progression (via connexion secondaire bufferisée).
$mysqlCount = new PDO(
    "mysql:host={$mysqlHost};dbname={$mysqlDb};charset=utf8mb4",
    $mysqlUser, $mysqlPwd,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$total = (int) $mysqlCount->query('SELECT COUNT(*) FROM ai_librarian_chunk')->fetchColumn();
$mysqlCount = null;

$alreadyImported = (int) $pg->query('SELECT COUNT(*) FROM embeddings')->fetchColumn();
echo "Total MySQL: {$total} | Déjà en pgvector: {$alreadyImported}\n";
if ($alreadyImported >= $total) {
    echo "Migration déjà complète. Rien à faire.\n";
    exit(0);
}
echo "À migrer : " . ($total - $alreadyImported) . "\n\n";

$stmt = $mysql->query(
    'SELECT id, item_id, embedding, embedding_provider FROM ai_librarian_chunk ORDER BY id'
);

$batch = [];
$processed = 0;
$skipped = 0;
$inserted = 0;
$startTime = microtime(true);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $processed++;

    $embedding = json_decode($row['embedding'], true);
    if (!is_array($embedding) || count($embedding) !== 1024) {
        $skipped++;
        continue;
    }

    // Format pgvector : "[0.1,0.2,...]"
    $vectorStr = '[' . implode(',', $embedding) . ']';

    $batch[] = [
        (int) $row['id'],
        (int) $row['item_id'],
        $vectorStr,
        $row['embedding_provider'],
    ];

    if (count($batch) >= $batchSize) {
        $inserted += flushBatch($pg, $batch);
        $batch = [];
        printProgress($processed, $total, $startTime);
    }
}

if (!empty($batch)) {
    $inserted += flushBatch($pg, $batch);
    printProgress($processed, $total, $startTime);
}

echo "\n\n=== Terminé ===\n";
echo "Parcourus : {$processed}\n";
echo "Insérés (ou ignorés si déjà présents) : {$inserted}\n";
echo "Ignorés (embedding mal formé) : {$skipped}\n";

$finalCount = (int) $pg->query('SELECT COUNT(*) FROM embeddings')->fetchColumn();
echo "Total pgvector après migration : {$finalCount}\n";

function flushBatch(PDO $pg, array $batch): int
{
    if (empty($batch)) {
        return 0;
    }
    $placeholders = [];
    $values = [];
    foreach ($batch as $row) {
        $placeholders[] = '(?, ?, ?::vector, ?)';
        $values[] = $row[0];
        $values[] = $row[1];
        $values[] = $row[2];
        $values[] = $row[3];
    }
    $sql = 'INSERT INTO embeddings (chunk_id, item_id, embedding, embedding_provider) VALUES '
         . implode(',', $placeholders)
         . ' ON CONFLICT (chunk_id) DO NOTHING';
    $stmt = $pg->prepare($sql);
    $stmt->execute($values);
    return $stmt->rowCount();
}

function printProgress(int $processed, int $total, float $startTime): void
{
    $elapsed = microtime(true) - $startTime;
    $rate = $processed / max($elapsed, 0.001);
    $eta = ($total - $processed) / max($rate, 0.001);
    $pct = $total > 0 ? ($processed / $total * 100) : 0;
    printf(
        "\r[%3d%%] %d/%d chunks | %.0f/s | écoulé %ds | ETA %ds     ",
        $pct, $processed, $total, $rate, (int) $elapsed, (int) $eta
    );
}
