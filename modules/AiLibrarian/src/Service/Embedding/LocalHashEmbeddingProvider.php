<?php

namespace AiLibrarian\Service\Embedding;

/**
 * Fournisseur d'embeddings de repli, sans dépendance externe ni clé API.
 *
 * Utilise la technique du "hashing trick" (vecteur de fréquence de termes
 * projeté par hachage) : chaque mot du texte incrémente une dimension du
 * vecteur déterminée par son hash. C'est une approximation grossière d'un
 * embedding sémantique — elle capture surtout un recouvrement de
 * vocabulaire, pas de véritables relations de sens entre les mots.
 *
 * Utile pour développer et tester la plateforme hors-ligne, avant de
 * brancher un vrai fournisseur (VoyageEmbeddingProvider). À ne pas utiliser
 * en production : la qualité de la recherche sémantique en dépend
 * directement.
 */
class LocalHashEmbeddingProvider implements EmbeddingProviderInterface
{
    private const DIMENSIONS = 512;

    public function embed(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);

        $tokens = $this->tokenize($text);
        foreach ($tokens as $token) {
            $hash = crc32($token);
            $index = $hash % self::DIMENSIONS;
            // Le bit de poids fort du hash détermine le signe, pour réduire
            // le biais introduit par les collisions de hachage.
            $sign = ($hash & 0x80000000) ? -1.0 : 1.0;
            $vector[$index] += $sign;
        }

        return $this->normalize($vector);
    }

    public function embedBatch(array $texts): array
    {
        return array_map([$this, 'embed'], $texts);
    }

    public function dimensions(): int
    {
        return self::DIMENSIONS;
    }

    public function name(): string
    {
        return 'local-hash-v1';
    }

    /**
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}]{3,}/u', $text, $matches);
        return $matches[0];
    }

    /**
     * @param float[] $vector
     * @return float[]
     */
    private function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn ($v) => $v * $v, $vector)));
        if ($norm === 0.0) {
            return $vector;
        }
        return array_map(static fn ($v) => $v / $norm, $vector);
    }
}
