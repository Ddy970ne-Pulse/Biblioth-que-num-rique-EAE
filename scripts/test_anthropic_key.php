<?php

/**
 * Test rapide de la clé Anthropic depuis le container Omeka.
 * Usage : docker compose exec omeka php /tmp/test_anthropic.php
 */

$key = getenv('ANTHROPIC_API_KEY');
$model = getenv('ANTHROPIC_MODEL') ?: 'claude-opus-4-8';

if (empty($key)) {
    echo "ERREUR : ANTHROPIC_API_KEY vide dans le container." . PHP_EOL;
    exit(1);
}

echo "Cle detectee (" . strlen($key) . " car) : " . substr($key, 0, 15) . "..." . substr($key, -6) . PHP_EOL;
echo "Modele : $model" . PHP_EOL;

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => $model,
        'max_tokens' => 20,
        'messages' => [['role' => 'user', 'content' => 'Dis bonjour en un mot.']],
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
if ($status === 200 && isset($decoded['content'][0]['text'])) {
    echo "SUCCES : reponse Claude : " . $decoded['content'][0]['text'] . PHP_EOL;
} else {
    echo "ECHEC : reponse Anthropic : " . $response . PHP_EOL;
    exit(1);
}
