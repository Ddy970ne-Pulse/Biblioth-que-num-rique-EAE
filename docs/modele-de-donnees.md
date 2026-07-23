# Modèle de données

Ce modèle est pensé pour répondre au besoin central du projet : non seulement retrouver une étude, mais **retracer l'histoire et l'évolution du message enseigné** sur un thème donné, à travers le temps.

Il s'appuie sur Dublin Core (natif Omeka S) complété par un vocabulaire personnalisé (`vocabularies/mouvement.ttl`) pour les notions propres au mouvement.

## Classes (types de ressources)

### `mvt:Etude`

L'unité documentaire centrale — une étude individuelle.

| Propriété | Source | Cardinalité | Description |
|---|---|---|---|
| `dcterms:title` | Dublin Core | 1 | Titre de l'étude |
| `dcterms:date` | Dublin Core | 1 | Date de l'étude (aussi précise que possible : jour, ou à défaut mois/année) |
| `dcterms:creator` | Dublin Core | 1..n | Auteur(s) — lien vers une ressource `mvt:Auteur` |
| `dcterms:abstract` | Dublin Core | 0..1 | Résumé |
| `dcterms:description` | Dublin Core | 0..1 | Texte intégral ou renvoi au fichier source |
| `mvt:theme` | Vocabulaire personnalisé | 1..n | Thème(s) abordés — lien vers `mvt:Theme` |
| `mvt:periode` | Vocabulaire personnalisé | 0..1 | Période/époque d'enseignement à laquelle rattacher l'étude, si le mouvement distingue des périodes (ex. phases successives d'enseignement) |
| `mvt:messageCle` | Vocabulaire personnalisé | 0..n | Formulation courte du message enseigné dans cette étude sur le(s) thème(s) traité(s) — sert de point d'ancrage pour la recherche sémantique et le suivi d'évolution |
| `mvt:precede` | Vocabulaire personnalisé | 0..n | Lien vers une étude antérieure que celle-ci prolonge, précise ou complète (chaîne chronologique explicite du message, indépendante de la simple date) |
| `mvt:corrige` | Vocabulaire personnalisé | 0..n | Lien vers une étude antérieure dont cette étude corrige ou nuance le contenu — essentiel pour la traçabilité de l'évolution du message |
| `mvt:citationExterne` | Vocabulaire personnalisé | 0..n | Référence à une source extérieure explicitement citée dans l'étude (nom, référence). Ce sont les **seules** sources externes que l'assistant IA est autorisé à mentionner, et toujours en les signalant comme telles. |

### `mvt:Auteur`

| Propriété | Source | Description |
|---|---|---|
| `foaf:name` ou `dcterms:creator` (litéral) | — | Nom de l'intervenant/auteur |
| `mvt:role` | Vocabulaire personnalisé | Rôle au sein du mouvement (fondateur, enseignant, etc.) — utile pour pondérer l'autorité d'une étude si nécessaire |

### `mvt:Theme`

Vocabulaire contrôlé, hiérarchisable (un thème peut avoir un thème parent) pour permettre une navigation par arborescence autant que par recherche libre.

| Propriété | Description |
|---|---|
| `dcterms:title` | Nom du thème |
| `dcterms:description` | Définition courte du thème telle qu'entendue dans le mouvement |
| `dcterms:isPartOf` | Thème parent, si arborescence |

### `mvt:Periode`

Optionnel — à activer seulement si le mouvement distingue des périodes ou phases d'enseignement identifiables (utile pour situer une étude dans le contexte historique du mouvement, au-delà de sa seule date).

| Propriété | Description |
|---|---|
| `dcterms:title` | Nom de la période |
| `dcterms:temporal` | Bornes temporelles |
| `dcterms:description` | Contexte de cette période |

## Pourquoi ce modèle et pas un simple classement chronologique

Un tri par date seule ne permet pas de répondre à « comment le message sur tel thème a-t-il évolué ? » — il faudrait parcourir manuellement toutes les études pour reconstituer le fil. Les propriétés `mvt:precede` et `mvt:corrige` rendent ce fil explicite et interrogeable : à partir d'une étude, on peut remonter ou descendre la chaîne des études liées sur un même thème, indépendamment du volume total du corpus.

Le module `AiLibrarian` (voir `architecture-ia.md`) s'appuie sur ce graphe pour construire des vues chronologiques par thème, et sur `mvt:citationExterne` pour distinguer strictement, dans ses réponses, le message interne des sources externes rapportées.

## Mise en œuvre dans Omeka S

1. Importer `vocabularies/mouvement.ttl` : Admin → Contenu → Vocabulaires → Ajouter un vocabulaire → Importer depuis un fichier.
2. Créer un gabarit de ressource (Resource Template) « Étude » associant les propriétés Dublin Core ci-dessus et les propriétés du vocabulaire `mvt`.
3. Créer les classes de ressources correspondantes pour `Auteur`, `Thème`, `Période`.
4. Pour l'import en lot du corpus, préparer un CSV avec une colonne par propriété et utiliser le module `CSV Import` (à installer séparément, non inclus dans ce dépôt).
