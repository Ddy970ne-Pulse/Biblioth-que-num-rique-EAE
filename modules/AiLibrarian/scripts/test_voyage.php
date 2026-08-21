<?php

/**
 * Test rapide de la clé Voyage AI depuis le container Omeka.
 * Usage : docker compose exec omeka php /tmp/test_voyage.php
 */

$key = getenv('VOYAGE_API_KEY');
if (empty($key)) {
    echo "ERREUR : VOYAGE_API_KEY vide dans le container." . PHP_EOL;
    exit(1);
}

echo "Cle detectee (" . strlen($key) . " caracteres) : " . substr($key, 0, 6) . "..." . substr($key, -4) . PHP_EOL;

$ch = curl_init('https://api.voyageai.com/v1/embeddings');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'input' => ['ceci est un test de la cle voyage'],
        'model' => 'voyage-3',
        'input_type' => 'document',
    ]),
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP Status : $status" . PHP_EOL;
if ($error) {
    echo "Erreur curl : $error" . PHP_EOL;
    exit(1);
}

$decoded = json_decode($response, true);
if ($status === 200 && isset($decoded['data'][0]['embedding'])) {
    $emb = $decoded['data'][0]['embedding'];
    echo "SUCCES : embedding recu, dimension " . count($emb) . " (attendu 1024)" . PHP_EOL;
    echo "Premier vecteur : [" . implode(', ', array_slice($emb, 0, 3)) . " ...]" . PHP_EOL;
} else {
    echo "ECHEC : reponse Voyage : " . $response . PHP_EOL;
    exit(1);
}
