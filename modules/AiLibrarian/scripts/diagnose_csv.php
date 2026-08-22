<?php

/**
 * Diagnostic CSV/TSV côté PHP (utilise fgetcsv comme CSVImport).
 * Montre la distribution des colonnes et les lignes anormales.
 *
 * Usage :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/diagnose_csv.php <chemin> [<delim>]
 *   ex : docker compose exec omeka php /.../diagnose_csv.php /tmp/test.csv     (CSV)
 *   ex : docker compose exec omeka php /.../diagnose_csv.php /tmp/test.tsv tab (TSV)
 */

$file = $argv[1] ?? '/tmp/test.csv';
$delimArg = $argv[2] ?? 'comma';
$delim = $delimArg === 'tab' ? "\t" : ',';

if (!file_exists($file)) {
    echo "Fichier introuvable : $file" . PHP_EOL;
    exit(1);
}

echo "Fichier : $file (delimiter : " . ($delim === "\t" ? 'TAB' : 'COMMA') . ")" . PHP_EOL;

$counts = [];
$anomalies = [];
$h = fopen($file, 'r');
$line = 0;
$expectedCols = null;

while (($row = fgetcsv($h, 0, $delim, '"', '\\')) !== false) {
    $line++;
    $n = count($row);
    $counts[$n] = ($counts[$n] ?? 0) + 1;
    if ($line === 1) {
        $expectedCols = $n;
        echo "Header : $n colonnes" . PHP_EOL;
        continue;
    }
    if ($n !== $expectedCols) {
        $titre = isset($row[1]) ? substr($row[1], 0, 80) : '?';
        $anomalies[] = "Ligne $line : $n colonnes (titre : $titre)";
    }
}
fclose($h);

echo "Distribution des colonnes : " . json_encode($counts) . PHP_EOL;
echo "Total anomalies : " . count($anomalies) . PHP_EOL;
echo "Premieres anomalies (max 20) :" . PHP_EOL;
foreach (array_slice($anomalies, 0, 20) as $a) {
    echo "  $a" . PHP_EOL;
}
