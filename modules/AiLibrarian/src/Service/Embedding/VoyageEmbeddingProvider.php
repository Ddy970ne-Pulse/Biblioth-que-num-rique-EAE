<?php

namespace AiLibrarian\Service\Embedding;

use RuntimeException;

/**
 * Fournisseur d'embeddings basé sur l'API Voyage AI — fournisseur
 * d'embeddings recommandé par Anthropic (qui n'expose pas d'API
 * d'embeddings en propre).
 *
 * https://docs.voyageai.com/reference/embeddings-api
 */
class VoyageEmbeddingProvider implements EmbeddingProviderInterface
{
    private const API_URL = 'https://api.voyageai.com/v1/embeddings';

    /** voyage-3 produit des vecteurs de dimension 1024. */
    private const DIMENSIONS = 1024;

    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'voyage-3')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function embed(string $text, string $type = self::TYPE_DOCUMENT): array
    {
        return $this->embedBatch([$text], $type)[0];
    }

    /** Nombre max de textes par requête HTTP. Voyage accepte jusqu'à 128,
     *  mais des batches plus modestes évitent les timeouts sur des chunks
     *  volumineux (OCR de PDF entiers → cellules abstract très longues). */
    private const BATCH_SIZE = 32;

    /** Timeout curl par requête (secondes). Suffisant pour un batch de 32
     *  chunks de ~800 caractères + latence réseau, avec marge. */
    private const HTTP_TIMEOUT = 180;

    public function embedBatch(array $texts, string $type = self::TYPE_DOCUMENT): array
    {
        if (empty($texts)) {
            return [];
        }

        // Découpe en sous-batches pour éviter de dépasser les limites
        // pratiques de l'API (timeout HTTP, payload trop gros).
        $texts = array_values($texts);
        $vectors = [];
        foreach (array_chunk($texts, self::BATCH_SIZE) as $batch) {
            $vectors = array_merge($vectors, $this->requestBatch($batch, $type));
        }
        return $vectors;
    }

    private function requestBatch(array $batch, string $type): array
    {
        $payload = json_encode([
            'input' => array_values($batch),
            'model' => $this->model,
            // 'document' pour indexation, 'query' pour requête utilisateur.
            // Voyage produit des vecteurs différents selon le mode : utiliser
            // 'document' pour la query dégrade sévèrement la pertinence
            // (tous les scores convergent autour d'une moyenne quasi identique).
            'input_type' => $type === self::TYPE_QUERY ? 'query' : 'document',
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Échec de l'appel à l'API Voyage AI : $error");
        }
        if ($status !== 200) {
            throw new RuntimeException("L'API Voyage AI a répondu avec le statut $status : $response");
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new RuntimeException('Réponse Voyage AI inattendue : ' . $response);
        }

        // L'API renvoie les résultats dans le même ordre que les entrées.
        return array_map(static fn (array $item) => $item['embedding'], $decoded['data']);
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS;
    }

    public function name(): string
    {
        return 'voyage:' . $this->model;
    }
}
