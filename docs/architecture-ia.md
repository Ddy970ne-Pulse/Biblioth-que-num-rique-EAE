# Architecture de l'assistance IA (module `AiLibrarian`)

## Objectif et garde-fou

L'assistant doit permettre de rechercher par thème, croiser des études, et répondre à des questions — **exclusivement à partir du corpus indexé dans la bibliothèque**. Il ne doit jamais compléter une réponse avec des connaissances générales du modèle de langage sous-jacent.

Le corpus contient deux catégories de documents (voir `modele-de-donnees.md`), que l'assistant doit distinguer explicitement dans ses réponses :

- `mvt:Etude` — l'enseignement propre du mouvement. L'assistant ne peut restituer une source externe mentionnée à l'intérieur d'une étude que si elle y est explicitement citée (propriété `mvt:citationExterne`), et toujours en la signalant comme citation externe rapportée.
- `mvt:ArticleOuvrage` — un document historique/externe (sermon, manuscrit...) dont le mouvement a republié l'intégralité et qu'il indexe comme pièce à part entière du corpus (ex. sermon d'A.T. Jones, manuscrit d'Ellen G. White), sans en être l'auteur. Un extrait provenant de cette classe doit toujours être signalé comme source historique/externe reproduite par le mouvement — jamais présenté comme un enseignement propre du mouvement, même s'il est légitimement indexé et consultable dans la bibliothèque.

Ce garde-fou est implémenté à deux niveaux, redondants par conception :

1. **Récupération (retrieval)** : seuls des extraits provenant d'items Omeka S réellement indexés sont injectés dans le contexte envoyé au modèle. Le modèle n'a physiquement accès à rien d'autre.
2. **Instruction système** : le prompt système envoyé à Claude interdit explicitement de répondre en dehors des extraits fournis et impose la citation de la source pour chaque affirmation.

## Vue d'ensemble

```
┌─────────────────────────┐
│   Omeka S (items)       │  Études, métadonnées, texte intégral
└───────────┬──────────────┘
            │ lecture via API interne Omeka S (Api\Manager)
            ▼
┌─────────────────────────┐
│  CorpusIndexer            │  Découpe chaque étude en extraits (chunks),
│  (AiLibrarian\Service)    │  calcule un vecteur par extrait, stocke en base
└───────────┬──────────────┘
            │ écrit dans la table `ai_librarian_chunk` (MySQL, créée à l'installation du module)
            ▼
┌─────────────────────────┐
│  Recherche sémantique     │  Requête → vecteur → similarité cosinus
│  (AnswerService::search)  │  contre les extraits indexés → top-k résultats
└───────────┬──────────────┘
            │ top-k extraits + métadonnées de provenance
            ▼
┌─────────────────────────┐
│  Génération sourcée       │  Prompt système strict + extraits → Claude
│  (AnswerService::ask)     │  (Anthropic API, PHP SDK) → réponse + citations
└─────────────────────────┘
```

## Indexation (`CorpusIndexer`)

- Découpe le texte de chaque item en extraits d'une taille raisonnable (~800 caractères, avec chevauchement) pour préserver le contexte local tout en gardant des unités de récupération précises.
- Calcule un vecteur par extrait via un `EmbeddingProviderInterface` interchangeable :
  - `VoyageEmbeddingProvider` — fournisseur recommandé par Anthropic pour les embeddings (aucune offre d'embeddings native chez Anthropic), utilisé si `VOYAGE_API_KEY` est configurée.
  - `LocalHashEmbeddingProvider` — repli local, sans dépendance externe ni clé API, basé sur un vecteur de fréquence de termes. Qualité de recherche nettement inférieure à un vrai modèle d'embeddings, mais permet de développer/tester la plateforme hors-ligne avant de brancher un vrai fournisseur.
- Stocke `(item_id, chunk_index, contenu, vecteur, date)` dans la table `ai_librarian_chunk`.
- Déclenchable manuellement depuis Admin → AI Librarian → Réindexer, ou automatiquement après sauvegarde d'un item (à activer selon le volume — pour un grand corpus, préférer un déclenchement en tâche de fond plutôt qu'à chaque sauvegarde).

## Recherche sémantique

Requête utilisateur → même fournisseur d'embeddings → vecteur → similarité cosinus contre tous les extraits indexés (calcul en PHP ; pour un corpus de plusieurs dizaines de milliers d'extraits, envisager de migrer vers une extension vectorielle MySQL/MariaDB ou une base vectorielle dédiée). Retourne les *k* extraits les plus proches avec, pour chacun, l'item Omeka S d'origine.

## Génération de réponse sourcée (`AnswerService::ask`)

1. Recherche sémantique → top-*k* extraits.
2. Si aucun extrait suffisamment pertinent n'est trouvé (score sous un seuil), l'assistant répond qu'il n'a pas trouvé d'étude pertinente dans le corpus, sans tenter de générer une réponse générique.
3. Sinon, construction d'un prompt Claude (modèle `claude-opus-4-8` par défaut, configurable) avec :
   - un **prompt système** qui interdit toute réponse hors des extraits fournis, impose une citation (titre + lien) pour chaque affirmation, et impose le signalement explicite de toute citation externe rapportée par une source interne ;
   - les extraits récupérés, chacun annoté de sa provenance (titre de l'étude, auteur, date, URL Omeka S) ;
   - la question de l'utilisateur.
4. La réponse générée est retournée avec la liste des études citées, chacune pointant vers sa fiche Omeka S.

### Prompt système (extrait, voir `src/Service/AnswerService.php` pour le texte complet)

> Tu es l'assistant de recherche de cette bibliothèque numérique. Tu ne dois répondre qu'à partir des extraits fournis ci-dessous, qui proviennent tous d'études du corpus interne. N'utilise jamais de connaissances générales extérieures à ces extraits. Si les extraits ne permettent pas de répondre, dis-le explicitement. Pour chaque affirmation, indique la source (titre de l'étude) dont elle provient. Si un extrait rapporte lui-même une citation d'une source externe au mouvement, tu peux la restituer, mais uniquement en la signalant clairement comme « citation externe rapportée par [étude source] » — ne la présente jamais comme faisant partie du message enseigné par le mouvement.

## Pourquoi un module Omeka S plutôt qu'un service séparé

Construire l'IA comme module natif Omeka S (PHP) plutôt que comme micro-service Python séparé garde l'indexation synchronisée avec le cycle de vie du contenu (création/modification/suppression d'un item), évite une synchronisation de données dupliquée entre deux systèmes, et s'appuie sur l'authentification et les permissions déjà gérées par Omeka S. Le coût : la recherche vectorielle en PHP pur ne passera pas à l'échelle indéfiniment — au-delà de quelques dizaines de milliers d'extraits, prévoir une migration vers une base vectorielle dédiée (le point d'extension est `EmbeddingProviderInterface` / la couche de recherche dans `AnswerService`, conçue pour être remplacée sans toucher au reste du module).

## Configuration

Variables d'environnement (voir `.env.example`), lues par le module via les réglages Omeka S (Admin → AI Librarian → Configurer) qui les surchargent si renseignées dans l'interface :

- `ANTHROPIC_API_KEY` — génération des réponses (obligatoire pour activer les réponses sourcées).
- `ANTHROPIC_MODEL` — modèle Claude à utiliser (défaut : `claude-opus-4-8`).
- `VOYAGE_API_KEY` — fournisseur d'embeddings (optionnel ; sans elle, repli sur `LocalHashEmbeddingProvider`, à ne pas utiliser en production).
