# Bibliothèque numérique — mouvement

Bibliothèque numérique dédiée aux enseignements d'un mouvement, couvrant l'ensemble des études produites depuis 1989. L'objectif : donner accès à chaque étude individuellement, permettre de retracer l'histoire et l'évolution du message enseigné dans le temps, croiser les données entre études et thèmes, et assister la recherche par de l'intelligence artificielle — strictement cantonnée au corpus interne.

Ce dépôt contient l'**architecture de la plateforme**, pas encore le corpus. Le corpus (études, métadonnées, scans) sera importé une fois la plateforme en place.

## Principe directeur : cloisonnement strict du corpus

Toute fonctionnalité de recherche ou d'assistance par IA doit répondre **exclusivement** à partir des documents indexés dans la bibliothèque. Aucune connaissance externe au modèle ne doit être injectée dans une réponse, sauf si cette source externe est explicitement citée à l'intérieur d'une étude du corpus — auquel cas elle doit être signalée comme telle (« citation externe, présente dans le document source »), jamais mêlée silencieusement au message du mouvement. Ce principe est implémenté au niveau du prompt système du module IA (voir `docs/architecture-ia.md`) et doit être respecté par toute évolution future de la plateforme.

## Méthodologie

La conception suit la méthodologie en 4 phases décrite par IdNum (https://www.idnum.fr/methodoc/construire-une-bibliotheque-numerique/) : analyse préliminaire, analyse fonctionnelle, sélection technologique, cahier des charges. Le détail est dans `docs/cahier-des-charges.md`.

## Choix technologique

- **Omeka S** — logiciel libre spécialisé pour bibliothèques numériques (utilisé notamment par Europeana), avec gestion fine des métadonnées (Dublin Core + vocabulaires personnalisés), API REST native, interopérabilité standard (OAI-PMH via module, IIIF, SRU).
- **MySQL** — base de données d'Omeka S.
- **Module `AiLibrarian`** (dans `modules/AiLibrarian`) — module Omeka S personnalisé ajoutant la recherche sémantique et les réponses assistées par IA, strictement sourcées et cloisonnées au corpus.

Voir `docs/architecture-ia.md` pour le détail du fonctionnement de la partie IA.

## Structure du dépôt

```
docs/                     Cahier des charges, modèle de données, architecture IA
vocabularies/              Vocabulaire RDF personnalisé (Étude, Auteur, Thème, Période...)
docker/                   Dockerfile Omeka S + scripts de démarrage
modules/AiLibrarian/       Module Omeka S : indexation + recherche sémantique + Q&R sourcée
docker-compose.yml         Environnement de développement (Omeka S + MySQL)
.env.example                Variables d'environnement à copier vers .env
```

## Démarrer l'environnement de développement

```bash
cp .env.example .env
# éditer .env : identifiants MySQL, ANTHROPIC_API_KEY, VOYAGE_API_KEY (optionnel)
docker compose up -d --build
```

Omeka S sera accessible sur `http://localhost:8080`. Suivre l'assistant d'installation web (première visite) pour créer le compte administrateur — il se connectera automatiquement à la base `omeka` définie dans `.env`.

Activer ensuite le module **AI Librarian** depuis Admin → Modules.

## Prochaines étapes

1. Finaliser le cahier des charges (`docs/cahier-des-charges.md`) avec les réponses spécifiques au mouvement (corpus exact, public cible, droits de diffusion).
2. Importer le vocabulaire personnalisé (`vocabularies/mouvement.ttl`) dans Omeka S (Contenu → Vocabulaires → Importer).
3. Créer les gabarits de ressources (Resource Templates) dans l'admin Omeka S à partir du modèle de données (`docs/modele-de-donnees.md`).
4. Importer le corpus (études depuis 1989) — en lot via l'API Omeka S ou le module CSV Import.
5. Configurer les clés API (Anthropic pour la génération, fournisseur d'embeddings pour la recherche sémantique) et lancer l'indexation depuis Admin → AI Librarian.
