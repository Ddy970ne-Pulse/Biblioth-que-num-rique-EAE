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
    /** Texte à indexer côté corpus (embedding "document"). */
    public const TYPE_DOCUMENT = 'document';

    /** Texte tapé par l'utilisateur au moment de la recherche (embedding "query").
     *  Voyage AI (et OpenAI text-embedding-3, etc.) produit des vecteurs
     *  optimisés différemment pour query vs document : utiliser 'document'
     *  pour la query dégrade la pertinence (tous les scores convergent
     *  autour d'une moyenne quasi identique). */
    public const TYPE_QUERY = 'query';

    /**
     * @param string $type EmbeddingProviderInterface::TYPE_DOCUMENT | TYPE_QUERY
     * @return float[] Vecteur représentant le texte.
     */
    public function embed(string $text, string $type = self::TYPE_DOCUMENT): array;

    /**
     * Version en lot d'embed(), à privilégier lors de l'indexation pour
     * limiter le nombre d'appels réseau vers le fournisseur.
     *
     * @param string[] $texts
     * @param string $type EmbeddingProviderInterface::TYPE_DOCUMENT | TYPE_QUERY
     * @return float[][] Un vecteur par texte, dans le même ordre.
     */
    public function embedBatch(array $texts, string $type = self::TYPE_DOCUMENT): array;

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
