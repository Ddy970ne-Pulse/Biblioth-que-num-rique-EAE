# AI Librarian

Module Omeka S ajoutant la recherche sémantique et un assistant de questions/réponses strictement sourcé sur le corpus indexé. Voir `docs/architecture-ia.md` à la racine du dépôt pour l'architecture complète et le principe de cloisonnement au corpus.

## Installation

Ce module dépend du SDK PHP officiel Anthropic. Avant d'activer le module dans Omeka S, installer ses dépendances :

```bash
cd modules/AiLibrarian
composer install
```

Dans l'environnement Docker fourni à la racine du dépôt, le dossier du module est monté directement dans le conteneur Omeka S (`docker-compose.yml`) : exécuter `composer install` sur la machine hôte suffit, `vendor/` sera visible dans le conteneur.

Puis, dans l'administration Omeka S : Modules → AI Librarian → Installer, puis Configurer pour renseigner les clés API (ou les laisser vides pour utiliser les variables d'environnement `ANTHROPIC_API_KEY` / `VOYAGE_API_KEY` définies dans `.env`).

## Fonctionnement

- **Indexation** (`src/Service/CorpusIndexer.php`) : découpe chaque item en extraits, calcule leurs vecteurs d'embedding, les stocke dans la table `ai_librarian_chunk` (créée à l'installation du module). Déclenchable depuis Admin → AI Librarian → Réindexer le corpus.
- **Recherche sémantique** (`src/Service/AnswerService.php::search`) : compare la requête aux extraits indexés par similarité cosinus.
- **Assistant Q&R** (`src/Service/AnswerService.php::ask`) : recherche sémantique puis génération d'une réponse par Claude, avec un prompt système qui interdit toute réponse hors des extraits fournis (voir `docs/architecture-ia.md`).
- **Fournisseurs d'embeddings** (`src/Service/Embedding/`) : `VoyageEmbeddingProvider` (recommandé, nécessite une clé API Voyage AI) ou `LocalHashEmbeddingProvider` (repli local sans dépendance externe, qualité dégradée — développement uniquement).

## Routes

- Public : `/ai-librarian` (recherche), `/ai-librarian/ask` (assistant Q&R).
- Admin : `/admin/ai-librarian` (déclenchement de la réindexation).

## État de ce module

Scaffold initial : structure et logique conformes aux conventions Omeka S / Laminas, non encore exécutées contre une instance Omeka S réelle faute d'environnement de test disponible dans cette session. À vérifier après première activation : résolution des templates de vue, permissions ACL de la page admin, et comportement du SDK Anthropic PHP en conditions réelles. Signaler tout écart pour correction.
