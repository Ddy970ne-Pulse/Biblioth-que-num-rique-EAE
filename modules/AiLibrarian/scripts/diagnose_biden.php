<?php
/**
 * Diagnostic ciblé : où se classe la balise "Biden premier roi 7ème royaume"
 * dans le pipeline de recherche pour la query Q4 ?
 *
 * Retourne :
 *  - Tous les items dont le contenu mentionne à la fois Biden et roi/premier
 *  - Pour chacun : nb de chunks, présence dans le top 100 vectoriel/bm25/RRF
 *  - Le rang précis dans chaque signal
 *
 * Usage :
 *   docker compose exec omeka php /var/www/html/modules/AiLibrarian/scripts/diagnose_biden.php
 */

require_once '/var/www/html/bootstrap.php';

use AiLibrarian\Service\Embedding\EmbeddingProviderInterface;

$app = Omeka\Mvc\Application::init(require '/var/www/html/application/config/application.config.php');
$sm = $app->getServiceManager();

/** @var \Doctrine\DBAL\Connection $conn */
$conn = $sm->get('Omeka\Connection');
/** @var EmbeddingProviderInterface $emb */
$emb = $sm->get(EmbeddingProviderInterface::class);
$providerName = $emb->name();

$query = 'Qui est le premier roi du septième royaume ?';

echo "=== 1. Items du corpus mentionnant Biden + roi/premier ===\n\n";
$candidates = $conn->fetchAllAssociative(
    "SELECT item_id, COUNT(*) AS nb_chunks,
            MIN(id) AS first_chunk_id,
            SUBSTRING(GROUP_CONCAT(content SEPARATOR ' || '), 1, 300) AS aperçu
     FROM ai_librarian_chunk
     WHERE embedding_provider = :prov
       AND (content LIKE '%Biden%' AND (content LIKE '%premier%' OR content LIKE '%1er%' OR content LIKE '%roi%'))
     GROUP BY item_id
     ORDER BY nb_chunks DESC
     LIMIT 20",
    ['prov' => $providerName]
);

if (empty($candidates)) {
    echo "AUCUN chunk trouvé avec Biden + roi/premier dans le corpus !\n";
    echo "Hypothèse A confirmée : la balise n'a pas survécu à la réindexation.\n";
    exit(0);
}

foreach ($candidates as $c) {
    printf("item #%d | %d chunks | 1er chunk #%d\n  \"%s\"\n\n",
        $c['item_id'], $c['nb_chunks'], $c['first_chunk_id'], $c['apercu']);
}
$targetItemIds = array_map(fn($c) => (int)$c['item_id'], $candidates);
$targetChunkIds = array_map(fn($c) => (int)$c['first_chunk_id'], $candidates);

echo "\n=== 2. Rangs dans le top 100 vectoriel (pgvector) ===\n\n";
$pgHost = getenv('VECTORS_HOST') ?: 'vectors';
$pg = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s',
        $pgHost, getenv('VECTORS_PORT') ?: '5432', getenv('VECTORS_DATABASE') ?: 'vectors'),
    getenv('VECTORS_USER') ?: 'vectors',
    getenv('VECTORS_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$qvec = $emb->embed($query, EmbeddingProviderInterface::TYPE_QUERY);
$vecStr = '[' . implode(',', $qvec) . ']';

$stmt = $pg->prepare(
    "SELECT chunk_id, item_id, 1 - (embedding <=> :qvec::vector) AS sim
     FROM embeddings WHERE embedding_provider = :prov
     ORDER BY embedding <=> :qvec::vector LIMIT 100"
);
$stmt->execute(['qvec' => $vecStr, 'prov' => $providerName]);
$top100 = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rankByItem = [];
foreach ($top100 as $i => $row) {
    $iid = (int)$row['item_id'];
    if (in_array($iid, $targetItemIds, true) && !isset($rankByItem[$iid])) {
        $rankByItem[$iid] = [
            'rank' => $i + 1,
            'sim' => round((float)$row['sim'], 4),
            'chunk_id' => (int)$row['chunk_id'],
        ];
    }
}

foreach ($targetItemIds as $iid) {
    if (isset($rankByItem[$iid])) {
        printf("item #%d : rang %d (sim=%.4f, chunk #%d)\n",
            $iid, $rankByItem[$iid]['rank'], $rankByItem[$iid]['sim'], $rankByItem[$iid]['chunk_id']);
    } else {
        printf("item #%d : HORS TOP 100 vectoriel\n", $iid);
    }
}

echo "\n=== 3. Rangs dans le top 100 BM25 (MySQL fulltext) ===\n\n";
$rows = $conn->fetchAllAssociative(
    "SELECT id, item_id,
            MATCH(content) AGAINST(:q IN NATURAL LANGUAGE MODE) AS score
     FROM ai_librarian_chunk
     WHERE embedding_provider = :prov
       AND MATCH(content) AGAINST(:q IN NATURAL LANGUAGE MODE)
     ORDER BY score DESC LIMIT 100",
    ['q' => $query, 'prov' => $providerName]
);

$rankBm = [];
foreach ($rows as $i => $r) {
    $iid = (int)$r['item_id'];
    if (in_array($iid, $targetItemIds, true) && !isset($rankBm[$iid])) {
        $rankBm[$iid] = ['rank' => $i + 1, 'score' => round((float)$r['score'], 4)];
    }
}

foreach ($targetItemIds as $iid) {
    if (isset($rankBm[$iid])) {
        printf("item #%d : rang BM25 %d (score=%.4f)\n",
            $iid, $rankBm[$iid]['rank'], $rankBm[$iid]['score']);
    } else {
        printf("item #%d : HORS TOP 100 BM25\n", $iid);
    }
}

echo "\n=== 4. Diagnostic final ===\n";
foreach ($targetItemIds as $iid) {
    $r1 = $rankByItem[$iid]['rank'] ?? '>100';
    $r2 = $rankBm[$iid]['rank'] ?? '>100';
    printf("item #%d : vec=%s | bm25=%s\n", $iid, $r1, $r2);
}
echo "\nSeuils : top 15 = affiché à l'utilisateur. RRF fusionne les top 50 de chaque signal.\n";
echo "Si un item est en rang >50 dans les deux, il n'a AUCUNE chance d'être dans le top 15 final.\n";
