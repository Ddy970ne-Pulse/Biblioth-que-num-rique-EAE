# Cahier des charges

Rédigé selon la méthodologie IdNum de construction d'une bibliothèque numérique (4 phases : analyse préliminaire, analyse fonctionnelle, sélection technologique, cahier des charges). Source : https://www.idnum.fr/methodoc/construire-une-bibliotheque-numerique/

Les sections marquées **[À COMPLÉTER]** demandent une décision propre au mouvement concerné (nom, corpus exact, droits, public) que cette plateforme ne présume pas.

## 1. Analyse préliminaire

### 1.1 Pourquoi cette ressource ?

- Centraliser l'ensemble des études produites au sein du mouvement depuis 1989, aujourd'hui dispersées.
- Permettre de retracer l'histoire et l'évolution du message enseigné : quand un thème a-t-il été abordé pour la première fois, comment l'enseignement sur ce thème a-t-il évolué, quelles études se répondent ou se corrigent entre elles.
- Offrir un outil de recherche transversal par thème, auteur, période, plutôt qu'un classement uniquement chronologique ou par auteur.
- Fournir une assistance à la compréhension (recherche sémantique, synthèse sourcée) sans jamais diluer le message avec des sources extérieures non identifiées comme telles.

### 1.2 Corpus

- Nature des documents : études internes au mouvement (texte, éventuellement audio/vidéo transcrits, PDF numérisés).
- Période couverte : 1989 → aujourd'hui.
- Volume estimé : **[À COMPLÉTER]**
- Formats sources disponibles : **[À COMPLÉTER]** (Word, PDF, scans papier à océriser, transcriptions audio ?)
- Droits de diffusion (public / réservé aux membres / usage interne uniquement) : **[À COMPLÉTER]**

### 1.3 Public cible

- **[À COMPLÉTER]** — membres du mouvement uniquement ? Chercheurs externes ? Grand public ? Ce choix conditionne l'accès (authentification requise ou non) et le niveau de mise en contexte à fournir dans les réponses de l'assistant IA.

### 1.4 Conservation

- Les fichiers originaux (PDF, scans) sont conservés tels quels dans le stockage Omeka S (dossier `files/`), jamais modifiés lors de l'indexation.
- Toute extraction de texte (OCR, transcription) est stockée séparément comme donnée dérivée, traçable à son fichier source.
- Sauvegardes : **[À COMPLÉTER]** (fréquence, lieu de stockage secondaire).

## 2. Analyse fonctionnelle

### 2.1 Services aux utilisateurs

- Recherche simple (plein texte sur titre, résumé, contenu des études).
- Recherche avancée par facettes : auteur, thème(s), période, date de l'étude.
- Vue chronologique d'un thème : liste ordonnée des études qui l'abordent, avec repérage des évolutions ou corrections du message dans le temps (voir modèle de données).
- Export de références (citation d'une étude : titre, auteur, date, identifiant pérenne).
- Téléchargement du document source (si les droits l'autorisent).
- Assistant IA : question en langage naturel → réponse synthétique **uniquement sourcée sur le corpus indexé**, avec citations explicites des études utilisées (titre + lien direct vers l'étude dans la bibliothèque). Voir `architecture-ia.md`.

### 2.2 Capacités administratives

- Gestion des collections (regroupement d'études par série, cycle d'enseignement, auteur).
- Indexation : import en lot (CSV / API), saisie manuelle via l'admin Omeka S.
- Gestion du vocabulaire contrôlé des thèmes (arborescence extensible sans redéploiement).
- Déclenchement de la ré-indexation sémantique (embeddings) après ajout/modification de contenu.
- Journal des requêtes de l'assistant IA (pour audit — vérifier qu'aucune réponse ne dérive du corpus).

### 2.3 Interopérabilité

- API REST native d'Omeka S (lecture/écriture des items, exposée par défaut).
- OAI-PMH : disponible via le module officiel `OaiPmhRepository` (à activer si un moissonnage externe — ex. portail fédérateur — est souhaité).
- IIIF : disponible via le module officiel `IiifServer` + `Universal Viewer`, pertinent si le corpus comprend des scans/images à visionner en haute définition avec zoom.
- SRU : non couvert nativement par Omeka S ; à évaluer selon besoin réel d'interconnexion avec un catalogue externe.

## 3. Sélection technologique

| Niveau | Choix | Justification |
|---|---|---|
| Logiciel spécialisé | **Omeka S** | Outil de référence pour bibliothèques/collections numériques (utilisé par Europeana), gestion fine des métadonnées via vocabulaires RDF, API REST native, écosystème de modules matures (IIIF, OAI-PMH, CSV Import). |
| Base de données | MySQL 8 | Requis par Omeka S. |
| Assistance IA | Module Omeka S personnalisé (`AiLibrarian`) | Recherche sémantique (embeddings) + génération de réponses sourcées (Claude, via l'API Anthropic), avec cloisonnement strict au corpus indexé. Construit comme module natif plutôt que service séparé, pour rester intégré au cycle de vie du contenu Omeka S (ré-indexation automatique). |

Alternatives écartées à ce stade et pourquoi :

- **CMS générique (WordPress/Drupal)** : plus simple pour du contenu éditorial, mais pas conçu pour la recherche documentaire structurée par métadonnées ni pour le croisement de données entre milliers d'items.
- **Infrastructure mutualisée (Gallica Marque Blanche)** : pertinente pour une institution patrimoniale publique s'appuyant sur la BnF ; ne convient pas à un corpus interne à un mouvement avec des exigences de cloisonnement de contenu spécifiques.

## 4. Exigences formelles

### 4.1 Ergonomie

- Recherche accessible dès la page d'accueil, sans jargon technique.
- Résultats systématiquement accompagnés de leur source (titre, auteur, date) — jamais de réponse IA sans traçabilité vers l'étude d'origine.
- Navigation chronologique par thème pensée comme un parcours de lecture, pas seulement une liste triée par date.

### 4.2 Performance

- **[À COMPLÉTER selon volume réel]** — temps de réponse cible pour la recherche sémantique (dépend du volume du corpus et du fournisseur d'embeddings choisi).

### 4.3 Interopérabilité technique

- Export des métadonnées en JSON-LD (natif Omeka S) et Dublin Core.
- Identifiants pérennes pour chaque étude (URI Omeka S stable).

### 4.4 Garde-fou éditorial (spécifique à ce projet)

- Le module IA ne doit **jamais** répondre à partir de connaissances générales du modèle de langage sous-jacent. Toute réponse doit être bâtie exclusivement sur les extraits récupérés dans le corpus indexé.
- Si aucune étude pertinente n'est trouvée pour une question, l'assistant doit le dire explicitement plutôt que de générer une réponse plausible mais non sourcée.
- Une citation externe présente *dans* une étude source (ex. une étude cite un texte extérieur) peut être restituée, mais toujours explicitement signalée comme citation externe rapportée par la source interne — jamais présentée comme faisant partie du message du mouvement lui-même.
