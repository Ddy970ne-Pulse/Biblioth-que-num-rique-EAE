<?php

namespace AiLibrarian\Service\Embedding;

/**
 * Convertit un texte en vecteur numérique utilisé pour la recherche
 * sémantique (similarité cosinus entre la question et les extraits
 * indexés du corpus).
 *
 * Point d'extension délibéré : au-delà de quelques dizaines de milliers
 * d'extraits, la comparaison en PHP pur devient le goulot d'étranglement.
 * Remplacer l'implémentation active (voir Module::getEmbeddingProvider)
 * par un client vers une base vectorielle dédiée sans toucher au reste
 * du module.
 */
interface EmbeddingProviderInterface
{
    /**
     * @return float[] Vecteur représentant le texte.
     */
    public function embed(string $text): array;

    /**
     * Version en lot d'embed(), à privilégier lors de l'indexation pour
     * limiter le nombre d'appels réseau vers le fournisseur.
     *
     * @param string[] $texts
     * @return float[][] Un vecteur par texte, dans le même ordre.
     */
    public function embedBatch(array $texts): array;

    /**
     * Dimension des vecteurs produits par ce fournisseur. Deux vecteurs de
     * dimensions différentes ne peuvent pas être comparés — nécessaire pour
     * détecter un changement de fournisseur qui invaliderait l'index existant.
     */
    public function dimensions(): int;

    /**
     * Identifiant court du fournisseur, stocké aux côtés de chaque vecteur
     * pour permettre de détecter un corpus indexé avec un ancien fournisseur.
     */
    public function name(): string;
}
