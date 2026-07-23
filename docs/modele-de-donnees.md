# Modèle de données

Ce modèle est pensé pour répondre au besoin central du projet : non seulement retrouver une étude, mais **retracer l'histoire et l'évolution du message enseigné** sur un thème donné, à travers le temps.

Il s'appuie sur Dublin Core (natif Omeka S) complété par un vocabulaire personnalisé (`vocabularies/mouvement.ttl`) pour les notions propres au mouvement.

## Classes (types de ressources)

### `mvt:Document` (classe abstraite)

Parent commun à `mvt:Etude` et `mvt:ArticleOuvrage`, pour que thème, période et les liens `mvt:precede`/`mvt:corrige` puissent relier les deux — par exemple une étude du mouvement qui s'appuie explicitement sur un article externe du corpus. On ne crée jamais de ressource directement de cette classe : toute ressource documentaire est soit une `mvt:Etude`, soit un `mvt:ArticleOuvrage`.

| Propriété | Source | Cardinalité | Description |
|---|---|---|---|
| `dcterms:title` | Dublin Core | 1 | Titre du document |
| `dcterms:date` | Dublin Core | 1 | Date du document (aussi précise que possible : jour, ou à défaut mois/année) |
| `dcterms:creator` | Dublin Core | 1..n | Auteur(s) — lien vers une ressource `mvt:Auteur` |
| `dcterms:abstract` | Dublin Core | 0..1 | Résumé |
| `dcterms:description` | Dublin Core | 0..1 | Texte intégral ou renvoi au fichier source |
| `mvt:theme` | Vocabulaire personnalisé | 1..n | Thème(s) abordés — lien vers `mvt:Theme` |
| `mvt:periode` | Vocabulaire personnalisé | 0..1 | Période/époque à laquelle rattacher le document, si le mouvement distingue des périodes (ex. phases successives d'enseignement) |
| `mvt:precede` | Vocabulaire personnalisé | 0..n | Lien vers un document antérieur (étude **ou** article/ouvrage) que celui-ci prolonge, précise ou complète (chaîne chronologique explicite du message, indépendante de la simple date) |
| `mvt:corrige` | Vocabulaire personnalisé | 0..n | Lien vers un document antérieur dont celui-ci corrige ou nuance le contenu — essentiel pour la traçabilité de l'évolution du message |

### `mvt:Etude`

Sous-classe de `mvt:Document` — une étude produite **par le mouvement lui-même**, par un de ses intervenants (Tess Lambert, Parminder Biant, CME, etc.). C'est l'enseignement propre du mouvement.

| Propriété | Source | Cardinalité | Description |
|---|---|---|---|
| `mvt:messageCle` | Vocabulaire personnalisé | 0..n | Formulation courte du message enseigné dans cette étude sur le(s) thème(s) traité(s) — sert de point d'ancrage pour la recherche sémantique et le suivi d'évolution |
| `mvt:citationExterne` | Vocabulaire personnalisé | 0..n | Référence à une source extérieure **mentionnée à l'intérieur** de l'étude (nom, référence) — ne s'applique pas quand le document entier est externe, voir `mvt:ArticleOuvrage` ci-dessous. Ce sont les **seules** sources externes que l'assistant IA est autorisé à mentionner depuis une étude, et toujours en les signalant comme telles. |

### `mvt:ArticleOuvrage`

Sous-classe de `mvt:Document` — un document dont l'auteur original est **extérieur au mouvement** (sermon, manuscrit, article historique), que le mouvement a traduit et/ou republié intégralement sur son site, et qu'il traite comme une pièce à part entière de son corpus (au lieu de simplement le citer). Catégorie créée le 2026-07-23 pour séparer explicitement ces documents des études propres du mouvement — exemples dans le corpus : le sermon d'A.T. Jones de 1892 (« L'Image de la Bête »), un manuscrit d'Ellen G. White de 1910 publié dans *Manuscript Releases*.

Différence avec `mvt:citationExterne` : `mvt:citationExterne` marque une source externe *rapportée à l'intérieur* d'une étude du mouvement ; `mvt:ArticleOuvrage` est le cas où **le document entier** est de source externe. `dcterms:creator` porte alors le nom de l'auteur original (ex. « A.T. Jones », « Ellen G. White »), pas un intervenant du mouvement.

Aucune propriété propre au-delà de celles héritées de `mvt:Document` — `mvt:messageCle` et `mvt:citationExterne` ne s'y appliquent pas : ce document n'enseigne pas un message du mouvement, il en est une source, et il n'a pas besoin de signaler ses propres citations comme externes puisqu'il l'est lui-même dans son intégralité.

**Garde-fou IA** : l'assistant doit toujours signaler un extrait provenant d'un `mvt:ArticleOuvrage` comme source historique/externe reproduite par le mouvement, jamais comme un enseignement propre du mouvement (voir `architecture-ia.md`).

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
