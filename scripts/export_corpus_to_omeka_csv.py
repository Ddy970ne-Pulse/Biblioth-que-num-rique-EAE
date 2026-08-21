"""
Convertit fulltext_index_enriched.json en CSV compatibles CSV Import Omeka S.

Génère 8 fichiers, DANS L'ORDRE D'IMPORT (les 6 premiers doivent être
importés AVANT les 2 derniers, sinon les références documentaires
seront cassées) :

    omeka_import_1_auteurs.csv           → mvt:Auteur         (~5-10 lignes)
    omeka_import_2_themes.csv            → mvt:Theme          (~44 lignes)
    omeka_import_3_lignes.csv            → mvt:LignePropetique (~10 lignes)
    omeka_import_4_balises.csv           → mvt:BaliseTemporelle (~20 lignes)
    omeka_import_5_evenements.csv        → mvt:Evenement      (~10 lignes)
    omeka_import_6_contextes.csv         → mvt:Contexte       (~16 lignes)
    omeka_import_7_etudes.csv            → mvt:Etude          (~800 lignes)
    omeka_import_8_articles_ouvrages.csv → mvt:ArticleOuvrage (~30 lignes)

Format : CSV UTF-8 avec BOM (Excel Windows), délimiteur `,`, multi-valeurs
séparées par `|`, toutes les cellules entre guillemets (QUOTE_ALL) pour
protéger les textes pleins qui contiennent virgules et sauts de ligne.

Chaque CSV utilise comme en-tête l'URI de la propriété Omeka S telle
qu'attendue par le module CSV Import, plus une colonne spéciale
`resource_class` pour typer la ressource.

Entrée :
    D:\\Google Drive\\Eden A Eden\\fulltext_index_enriched.json

Sortie :
    ./omeka_exports/omeka_import_*.csv

Usage :
    python export_corpus_to_omeka_csv.py                # génère les 8 CSV
    python export_corpus_to_omeka_csv.py --dry-run      # rapport, pas d'écriture
    python export_corpus_to_omeka_csv.py --limit 50     # limite à 50 documents (test)
    python export_corpus_to_omeka_csv.py --with-text    # inclut le texte plein
                                                        # (défaut : abstract 1500 car)
"""

import argparse
import csv
import json
import re
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parent
ENRICHED_JSON_CANDIDATES = [
    Path("D:/Google Drive/Eden A Eden/fulltext_index_enriched.json"),
    Path("D:/Google Drive/Eden A Eden/fulltext_index_full.json"),
    Path("/sessions/adoring-eager-turing/mnt/Eden A Eden/fulltext_index_enriched.json"),
    Path("/sessions/adoring-eager-turing/mnt/Eden A Eden/fulltext_index_full.json"),
]
OUT_DIR = SCRIPT_DIR.parent / "omeka_exports"

CSV_DELIM = ","
MULTI_DELIM = "|"

# Auteurs à classer comme "auteur historique externe reproduit" plutôt qu'"enseignant du mouvement"
# → leurs documents sont importés comme mvt:ArticleOuvrage, pas mvt:Etude
HISTORICAL_AUTHORS = {
    "Ellen G. White",
    "Ellen White",
    "A.T. Jones",
    "AT Jones",
    "Alonzo T. Jones",
    "A. T. Jones",
    "William Miller",
    # Adventistes historiques 19e-20e siècle (avant EAE)
    "S.N. Haskell",
    "S. N. Haskell",
    "M.L. Andreasen",
    "M. L. Andreasen",
    "L.F. Were",
    "L. F. Were",
    "E.A. Sutherland",
    "E. A. Sutherland",
    "H.H. Meyers",
    "H. H. Meyers",
    "H.E. Guenter",
    "H. E. Guenter",
    "R.F. Cottrell",
    "R. F. Cottrell",
    "R. Rodgers",
    "V. Fitchett",
    "M.A. Crews",
    "M. A. Crews",
    "Jeff Pippenger",  # Mouvement pré-EAE (Present Truth)
    "Jeff Larson",
}

# Consolidation des variantes de noms d'auteurs
# Format : nom canonique → liste de toutes les variantes reconnues
NAME_VARIANTS = {
    "A.T. Jones": ["A.T. Jones", "Alonzo T. Jones", "A. T. Jones", "AT Jones",
                   "Alonzo Trevier Jones"],
    "Ellen G. White": ["Ellen G. White", "Ellen White", "E.G. White", "E. G. White",
                       "EGW", "Ellen Gould White"],
    "S.N. Haskell": ["S.N. Haskell", "S. N. Haskell", "Stephen N. Haskell"],
    "M.L. Andreasen": ["M.L. Andreasen", "M. L. Andreasen"],
    "L.F. Were": ["L.F. Were", "L. F. Were"],
    "E.A. Sutherland": ["E.A. Sutherland", "E. A. Sutherland"],
    "H.H. Meyers": ["H.H. Meyers", "H. H. Meyers"],
    "H.E. Guenter": ["H.E. Guenter", "H. E. Guenter"],
    "R.F. Cottrell": ["R.F. Cottrell", "R. F. Cottrell"],
    "M.A. Crews": ["M.A. Crews", "M. A. Crews"],
    "CME": ["CME", "Cme"],
}

# Reverse-map pour normalisation rapide
_NAME_TO_CANONICAL = {}
for canonical, variants in NAME_VARIANTS.items():
    for v in variants:
        _NAME_TO_CANONICAL[v.lower().strip()] = canonical

# Mots-orphelins à filtrer (extraits OCR isolés, pas de vrais auteurs)
ORPHAN_WORDS = {
    "fin", "er", "me", "moi", "toi", "the", "le", "la", "les", "un", "une",
    "il", "elle", "on", "nous", "vous", "ils", "elles",
    "auteur", "author", "unknown", "inconnu", "?", "n/a", "na",
}

# Collectifs / organisations (à ne pas mettre dans les Auteurs individuels)
COLLECTIVE_NAMES = {
    "the little book ministries", "little book ministries",
    "eden à eden", "éden à éden", "eden a eden",
    "mouvement eden à eden", "mouvement",
    "le grand cri", "lgc", "grand cri",
    "à identifier", "a identifier",
    "future for america", "fro america",
}

# Rôle par défaut selon l'auteur (pour la table Auteurs)
ROLE_MAP = {
    "Parminder Biant": "Enseignant principal",
    "Tess Lambert": "Enseignante principale",
    "Blessing Nyoni": "Enseignant",
    "Curtis Robinson": "Enseignant",
    "Terrie Lambert": "Enseignante",
    "Arjan Den Heijer": "Enseignant",
    "Ellen G. White": "Auteure historique (source externe reproduite)",
    "A.T. Jones": "Auteur historique (source externe reproduite)",
    "William Miller": "Auteur historique (source externe reproduite)",
}

# --- Descriptions préalables des thèmes clés (le reste sera généré à la volée) ---
THEME_DESCRIPTIONS = {
    "Ligne d'Éden": "Ligne cosmique chiasmique englobant l'histoire humaine du péché originel à Éden restauré, avec la LD comme pivot.",
    "Ligne de l'Humanité": "Ligne unique post-2021 (aussi appelée ligne des 144 000) — dispensationnelle active depuis la FTG des trois lignes historiques.",
    "Loi du Dimanche": "Balise pivot des lignes prophétiques. Transition post-2024 : d'une législation religieuse à une politique de l'ordre mondial.",
    "FTG": "Fin du Temps de Grâce. Clôture de la probation. Aboutissement des lignes prophétiques.",
    "Cri de Minuit": "Balise dispensationnelle interne annonçant la LD. Point de bascule dans les lignes.",
    "Royaumes prophétiques": "Structure des royaumes de la prophétie biblique. 6ème royaume (USA démocratique, fin 2020-2021), 7ème royaume (post-Biden, tous les royaumes commencent bons).",
    "Alliances": "Ancienne Alliance (compréhension limitée) vs Nouvelle Alliance (compréhension complète). Structure typologique.",
    "Grâce": "Terme doctrinal du mouvement : la grâce c'est la loi. À définir selon Terrie Vœu 14.",
    "Actes 27": "Ligne des institutions — deux bateaux Adramytte/Alexandrie — histoire de l'échec du 6ème royaume.",
    "Apocalypse 17": "Clé pour comprendre la LD. Femme = Roi du Nord, 10 rois = 7ème royaume.",
    "Daniel 11": "Prophétie principale sur la LD et le démantèlement de l'Égypte (v.40-45).",
    "Chazon vs Mareh": "Distinction vision panoramique (chazon, embrasse une ligne d'un coup) vs vision progressive (mareh, dispensationnelle, étape par étape).",
    "2 Institutions Jumelles": "Principe doctrinal récurrent : USA et Adventisme comme deux institutions jumelles (Actes 27, Adramytte/Alexandrie).",
    "144 000": "Groupe des scellés. La Ligne de l'Humanité est aussi appelée Ligne des 144 000.",
    "Trois Anges": "Messages des trois anges d'Apocalypse 14. Cadre du message enseigné.",
    "Sabbat": "Impératif moral et prophétique. Objet de la controverse finale.",
    "Dispensations": "Méthodologie active post-FTG : Race → Sexisme → Homophobie (dispensations successives).",
    "OIL / Ordre International": "Ordre International Libéral. Rejeté par le 7ème royaume à la CNR du 18 juillet 2024.",
    "Trump": "Roi #1 : dernier président de la démocratie US (6ème royaume, fin 2016-2021). Roi #2 : fin mauvaise du 7ème royaume.",
    "Biden": "Premier roi du 7ème royaume (2020-2021), phase initiale bonne (tout royaume commence bon).",
    "Féminisme": "Féminisme radical vs libéral. QFMG (Question Féministe / Motifs de Genre). Point 1 du chiasme Éden à Éden.",
    "Ligne de l'Agriculture": "Modèle typologique : semaille → pluie ancienne → croissance → pluie arrière-saison (= LD) → moisson.",
    "Cri de Minuit": "Balise interne annonçant la LD. Dispensationnellement daté selon les lignes.",
    "Ellen G. White": "Auteure historique (esprit de prophétie) dont les manuscrits sont reproduits dans le corpus comme mvt:ArticleOuvrage.",
}


# --- DESCRIPTIONS des lignes prophétiques ---
LIGNE_DESCRIPTIONS = {
    "Éden à Éden": {
        "description": "Ligne cosmique chiasmique. Ciel → Éden originel → 1,2,3 → LD (pivot) → 3,2,1 → Éden restauré → Nouvelle Terre. Ni chazon ni mareh au sens strict : catégorie propre. Attestée par Parminder Biant IPR CM 26 août 2023 et reprise par Blessing Nyoni sous le nom « Ligne de la Restauration ».",
        "englobante": None,
        "caduque": False,
    },
    "Humanité (144K)": {
        "description": "Ligne unique dispensationnelle post-2021. Curtis Robinson (juillet 2026) : « Nos 4 lignes de réforme ne sont qu'une seule ligne d'une même histoire menant au mariage avec Dieu. » Aussi appelée Ligne des 144 000, Grande Ligne, Ligne de la Restauration.",
        "englobante": "Éden à Éden",
        "caduque": False,
    },
    "Prêtres": {
        "description": "Ligne dispensationnelle historique. Terminée en 2021 avec la FTG des trois lignes historiques. Reste utile rétrospectivement.",
        "englobante": "Humanité (144K)",
        "caduque": True,
    },
    "Lévites": {
        "description": "Ligne dispensationnelle historique. Terminée. Segment ultérieur des lignes historiques.",
        "englobante": "Humanité (144K)",
        "caduque": True,
    },
    "Néthiniens": {
        "description": "Ligne dispensationnelle historique. Terminée. Segment ultérieur des lignes historiques.",
        "englobante": "Humanité (144K)",
        "caduque": True,
    },
    "Millérites": {
        "description": "Ligne typologique historique 1798 → 22 octobre 1844. Modèle de référence pour toutes les lignes ultérieures. Grande Déception 22/10/1844.",
        "englobante": "Éden à Éden",
        "caduque": False,
    },
    "Christ": {
        "description": "Ligne typologique historique 27 → 34 ap. JC. Modèle de référence. Crucifixion / Pentecôte.",
        "englobante": "Éden à Éden",
        "caduque": False,
    },
    "Agriculture": {
        "description": "Ligne typologique modèle : semaille → pluie ancienne → croissance → pluie arrière-saison (= LD sur notre ligne) → moisson. Grille de lecture superposable à toute ligne.",
        "englobante": "Éden à Éden",
        "caduque": False,
    },
    "Rois": {
        "description": "Structure institutionnelle : Nimrod → 6ème royaume → 7ème royaume → FTG des royaumes. Grille de lecture politique.",
        "englobante": "Éden à Éden",
        "caduque": False,
    },
    "Institutions (Actes 27)": {
        "description": "Structure jumelle (2 bateaux Adramytte/Alexandrie = USA + Adventisme). Selon Parminder 2026, ce n'est PAS une 5ème ligne distincte mais un angle sur la ligne unique.",
        "englobante": "Humanité (144K)",
        "caduque": False,
    },
}


# --- BALISES : dates et rattachement à une ligne ---
BALISE_META = {
    "1798": {"date": "1798", "ligne": "Millérites"},
    "1844": {"date": "1844", "ligne": "Millérites"},
    "22 octobre 1844": {"date": "1844-10-22", "ligne": "Millérites"},
    "1863": {"date": "1863", "ligne": "Millérites"},
    "1888": {"date": "1888", "ligne": "Prêtres"},
    "1989": {"date": "1989", "ligne": "Prêtres"},
    "2001": {"date": "2001", "ligne": "Humanité (144K)"},
    "2014": {"date": "2014", "ligne": "Humanité (144K)"},
    "2016": {"date": "2016", "ligne": "Humanité (144K)"},
    "2020": {"date": "2020", "ligne": "Humanité (144K)"},
    "2021": {"date": "2021", "ligne": "Humanité (144K)"},
    "6 janvier 2021": {"date": "2021-01-06", "ligne": "Humanité (144K)"},
    "2024": {"date": "2024", "ligne": "Humanité (144K)"},
    "18 juillet 2024": {"date": "2024-07-18", "ligne": "Humanité (144K)"},
    "2025": {"date": "2025", "ligne": "Humanité (144K)"},
    "20 janvier 2025": {"date": "2025-01-20", "ligne": "Humanité (144K)"},
    "2026": {"date": "2026", "ligne": "Humanité (144K)"},
    "3 janvier 2026": {"date": "2026-01-03", "ligne": "Humanité (144K)"},
    "LD": {"date": "", "ligne": "Humanité (144K)"},
    "FTG": {"date": "", "ligne": "Humanité (144K)"},
    "2nde Venue": {"date": "", "ligne": "Éden à Éden"},
}


# --- CONTEXTES : descriptions ---
CONTEXT_DESCRIPTIONS = {
    "EAE": ("France EAE", "Ministère Éden à Éden — France (Guadeloupe et métropole)"),
    "EVE": ("Guadeloupe EVE", "Ministère EVE — Guadeloupe"),
    "GCM": ("Guadeloupe Camp Meeting", "Camp Meeting annuel en Guadeloupe"),
    "IPR": ("Institute for Prophetic Research", "Institution de recherche prophétique dirigée par Parminder Biant"),
    "TIN": ("TIN International", "Ministère TIN, événements internationaux Zoom"),
    "ICM": ("ICM France", "International Camp Meeting France"),
    "CM": ("Camp Meeting", "Camp Meeting générique (contexte non spécifié)"),
    "LGC": ("Le Grand Cri", "Newsletter Le Grand Cri (France)"),
    "PP": ("PP Slovaquie", "Ministère Slovaquie"),
    "OL": ("Brésil OL", "Brésil / Portugal (O Livrinho)"),
    "TMW": ("TMW", "TMW — contexte spécifique"),
    "MPS": ("MPS Columbia", "MPS Columbia — USA"),
    "MES": ("MES", "MES — contexte spécifique"),
    "CME": ("CME", "CME — Cri de Minuit Européen"),
    "NR": ("NR", "NR — contexte à documenter"),
}


# --- EVENT DESCRIPTIONS ---
EVENT_DESCRIPTIONS = {
    "Camp Meeting": "Rassemblement multi-jours du mouvement, généralement avec plusieurs enseignants.",
    "Vêpres": "Enseignement du vendredi soir (début du sabbat).",
    "IPR Meeting": "Rencontre organisée par l'Institute for Prophetic Research.",
    "ICM Meeting": "International Camp Meeting.",
    "Sabbat Zoom": "Enseignement du sabbat diffusé en visioconférence Zoom.",
    "Question & Réponse": "Session de questions-réponses avec un ou plusieurs enseignants.",
    "Newsletter": "Publication écrite périodique (LGC, EAE, etc.).",
    "Étude Interactive": "Étude publiée avec questionnaire d'accompagnement pour lecteur.",
    "Livrable": "Document consolidé produit par les équipes du mouvement (analyse, synthèse, comparatif).",
    "Séminaire": "Cycle d'enseignements sur un thème donné dans un lieu donné.",
    "Retranscription": "Transcription texte d'un enseignement vidéo/audio.",
}


def load_corpus():
    """Charge le corpus enrichi depuis le premier chemin candidat qui existe."""
    for candidate in ENRICHED_JSON_CANDIDATES:
        if candidate.exists():
            print(f"Chargement corpus : {candidate}")
            with candidate.open(encoding="utf-8") as f:
                return json.load(f)
    print("[ERR] Aucun fichier corpus trouvé dans les candidats :")
    for c in ENRICHED_JSON_CANDIDATES:
        print(f"  - {c}")
    sys.exit(1)


def truncate_text(text, max_chars=1500):
    """Tronque un texte proprement (fin de phrase si possible)."""
    if not text or len(text) <= max_chars:
        return text or ""
    truncated = text[:max_chars]
    # Chercher la dernière fin de phrase
    for punct in [". ", "! ", "? ", "\n"]:
        idx = truncated.rfind(punct)
        if idx > max_chars * 0.7:
            return truncated[:idx + 1] + " […]"
    return truncated + " […]"


def write_csv(path, rows, fieldnames):
    """Écrit un CSV UTF-8 with BOM, delimiter=comma, QUOTE_ALL."""
    with path.open("w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(
            f, fieldnames=fieldnames,
            delimiter=CSV_DELIM,
            quoting=csv.QUOTE_ALL,
            extrasaction="ignore",
        )
        w.writeheader()
        for row in rows:
            w.writerow(row)


def is_historical_author(name):
    """Détermine si un auteur est un auteur historique externe (→ mvt:ArticleOuvrage).
    Utilise le nom déjà canonisé pour matching exact + fallback matching partiel."""
    if not name:
        return False
    if name in HISTORICAL_AUTHORS:
        return True
    # Fallback : matching partiel (au cas où canonisation manquée)
    nlow = name.lower()
    for hist in HISTORICAL_AUTHORS:
        if hist.lower() in nlow or nlow in hist.lower():
            return True
    return False


def is_orphan(name):
    """Détecte les faux positifs orphelins (mots isolés extraits par erreur)."""
    if not name:
        return True
    nlow = name.lower().strip()
    if nlow in ORPHAN_WORDS:
        return True
    # Nom trop court (< 3 chars) après retrait de la ponctuation
    stripped = re.sub(r"[^\w]", "", nlow)
    if len(stripped) < 3:
        return True
    # Nom composé uniquement de chiffres
    if stripped.isdigit():
        return True
    return False


def is_collective(name):
    """Détermine si le 'speaker' est en fait un collectif/organisation (à exclure des Auteurs)."""
    if not name:
        return True
    nlow = name.lower().strip()
    if nlow in COLLECTIVE_NAMES:
        return True
    # Match partiel
    return any(c in nlow for c in COLLECTIVE_NAMES)


def clean_speaker(name):
    """Nettoie et canonise un nom d'auteur.
    - Retire parenthèses (ex. "Blessing Nyoni (Bn)" → "Blessing Nyoni")
    - Consolide les variantes (A.T. Jones = Alonzo T. Jones = A. T. Jones)
    """
    if not name:
        return ""
    name = re.sub(r"\s*\([^)]+\)$", "", name).strip()
    # Canonisation via NAME_VARIANTS
    canon = _NAME_TO_CANONICAL.get(name.lower().strip())
    if canon:
        return canon
    return name


def clean_ocr_abstract(text, max_chars=1500):
    """Nettoie les débuts OCR pollués (menu vertical, tableaux de titres, garde…).

    Heuristique : si les 400 premiers caractères ont un ratio (caractères-lettres /
    total) < 40%, ou si le contenu ressemble à une liste de titres non-articulés,
    on cherche le premier vrai paragraphe cohérent (>150 chars sans saut de ligne
    interne, contenant au moins 20 mots).
    """
    if not text:
        return ""

    def is_polluted(sample):
        if not sample:
            return False
        # Ratio caractères alphabétiques
        letters = sum(1 for c in sample if c.isalpha())
        ratio = letters / max(len(sample), 1)
        if ratio < 0.4:
            return True
        # Trop de mots courts isolés (typique liste titres)
        words = sample.split()
        if len(words) > 20:
            short_ratio = sum(1 for w in words if len(w) <= 3) / len(words)
            if short_ratio > 0.55:
                return True
        return False

    head = text[:400]
    if not is_polluted(head):
        # Rien à nettoyer, on retourne tronqué normal
        return truncate_text(text, max_chars)

    # Sinon on cherche le premier vrai paragraphe cohérent
    paragraphs = re.split(r"\n\s*\n", text)
    for para in paragraphs:
        para = para.strip()
        if len(para) < 150:
            continue
        # Doit contenir au moins 20 mots et ne pas être pollué à son tour
        words = para.split()
        if len(words) < 20:
            continue
        if is_polluted(para[:400]):
            continue
        # Trouvé ! On repart de ce paragraphe
        return truncate_text(para, max_chars)

    # Fallback : truncate normal si rien de mieux
    return truncate_text(text, max_chars)


# ============================================================================
# GÉNÉRATION DES CSV
# ============================================================================

def gen_auteurs(corpus):
    """CSV des auteurs individuels (exclut collectifs et orphelins OCR)."""
    counter = Counter()
    for doc in corpus:
        raw = doc.get("facets", {}).get("speaker_norm") or ""
        speaker = clean_speaker(raw)
        if not speaker:
            continue
        if is_collective(speaker):
            continue
        if is_orphan(speaker):
            continue
        counter[speaker] += 1

    rows = []
    for speaker, count in counter.most_common():
        # Rôle : historique si dans HISTORICAL_AUTHORS, sinon selon ROLE_MAP, sinon Enseignant
        if is_historical_author(speaker):
            role = ROLE_MAP.get(
                speaker,
                "Auteur historique (source externe reproduite)"
            )
        else:
            role = ROLE_MAP.get(speaker, "Enseignant")
        rows.append({
            "resource_class": "mvt:Auteur",
            "dcterms:title": speaker,
            "mvt:role": role,
            "dcterms:description": f"{count} documents dans la bibliothèque.",
        })
    return rows, ["resource_class", "dcterms:title", "mvt:role", "dcterms:description"]


def gen_themes(corpus):
    """CSV des thèmes (44 attestés)."""
    counter = Counter()
    for doc in corpus:
        for theme in doc.get("facets", {}).get("themes", []):
            counter[theme] += 1

    rows = []
    for theme, count in counter.most_common():
        desc = THEME_DESCRIPTIONS.get(
            theme,
            f"Thème abordé dans {count} documents du corpus."
        )
        rows.append({
            "resource_class": "mvt:Theme",
            "dcterms:title": theme,
            "dcterms:description": desc,
        })
    return rows, ["resource_class", "dcterms:title", "dcterms:description"]


def gen_lignes(corpus):
    """CSV des lignes prophétiques (10 attestées + descriptions doctrinales)."""
    counter = Counter()
    for doc in corpus:
        for ligne in doc.get("facets", {}).get("lignes_concernees", []):
            counter[ligne] += 1

    # On inclut TOUTES les lignes attestées, même celles avec 0 doc,
    # car elles sont référencées par mvt:balise ou mvt:ligneEnglobante
    all_lignes = set(counter.keys()) | set(LIGNE_DESCRIPTIONS.keys())

    rows = []
    for ligne in sorted(all_lignes):
        meta = LIGNE_DESCRIPTIONS.get(ligne, {
            "description": f"Ligne prophétique attestée dans {counter[ligne]} documents.",
            "englobante": None,
            "caduque": False,
        })
        rows.append({
            "resource_class": "mvt:LignePropetique",
            "dcterms:title": ligne,
            "dcterms:description": meta["description"],
            "mvt:ligneEnglobante": meta.get("englobante") or "",
            "mvt:ligneCaduque": "true" if meta.get("caduque") else "false",
        })
    return rows, ["resource_class", "dcterms:title", "dcterms:description",
                  "mvt:ligneEnglobante", "mvt:ligneCaduque"]


def gen_balises(corpus):
    """CSV des balises temporelles (20+ attestées + dates + lignes)."""
    counter = Counter()
    for doc in corpus:
        for balise in doc.get("facets", {}).get("balises", []):
            counter[balise] += 1

    all_balises = set(counter.keys()) | set(BALISE_META.keys())

    rows = []
    # Trier par date pour cohérence chronologique
    def sort_key(b):
        meta = BALISE_META.get(b, {})
        d = meta.get("date", "9999")
        return d if d else "9999"

    for balise in sorted(all_balises, key=sort_key):
        meta = BALISE_META.get(balise, {"date": "", "ligne": ""})
        rows.append({
            "resource_class": "mvt:BaliseTemporelle",
            "dcterms:title": balise,
            "mvt:baliseDate": meta.get("date", ""),
            "mvt:baliseLigne": meta.get("ligne", ""),
            "dcterms:description": f"Mentionnée dans {counter[balise]} documents." if counter[balise] else "",
        })
    return rows, ["resource_class", "dcterms:title", "mvt:baliseDate",
                  "mvt:baliseLigne", "dcterms:description"]


def gen_evenements(corpus):
    """CSV des types d'événement de diffusion."""
    counter = Counter()
    for doc in corpus:
        for event in doc.get("facets", {}).get("events", []):
            counter[event] += 1

    rows = []
    for event, count in counter.most_common():
        rows.append({
            "resource_class": "mvt:Evenement",
            "dcterms:title": event,
            "dcterms:description": EVENT_DESCRIPTIONS.get(
                event, f"Type d'événement — {count} documents."),
        })
    return rows, ["resource_class", "dcterms:title", "dcterms:description"]


def gen_contextes(corpus):
    """CSV des contextes géographiques/institutionnels."""
    counter = Counter()
    for doc in corpus:
        ctx = doc.get("facets", {}).get("context")
        if ctx and isinstance(ctx, dict):
            counter[ctx["code"]] += 1

    rows = []
    for code, count in counter.most_common():
        label, desc = CONTEXT_DESCRIPTIONS.get(code, (code, f"Contexte {code} — {count} documents."))
        rows.append({
            "resource_class": "mvt:Contexte",
            "dcterms:title": label,
            "dcterms:identifier": code,
            "dcterms:description": desc,
        })
    return rows, ["resource_class", "dcterms:title", "dcterms:identifier", "dcterms:description"]


def gen_documents(corpus, with_full_text=False):
    """CSV des Études (mvt:Etude) et Articles/Ouvrages (mvt:ArticleOuvrage)."""
    etudes = []
    articles = []

    for doc in corpus:
        facets = doc.get("facets", {})
        speaker_raw = facets.get("speaker_norm") or ""
        speaker = clean_speaker(speaker_raw)
        # Un orphelin OCR (Er, Me, Fin…) ne devient pas creator : la ligne
        # reste sans creator, ce qui la marque comme "à documenter"
        if is_orphan(speaker):
            speaker = ""
        text = doc.get("x") or ""
        # Nettoyage OCR : les débuts pollués (page de garde, sommaire, menu vertical)
        # sont sautés au profit du premier vrai paragraphe cohérent
        abstract = clean_ocr_abstract(text, 1500)
        description = text if with_full_text else abstract

        ctx = facets.get("context")
        ctx_label = ctx["label"] if isinstance(ctx, dict) else ""

        row = {
            "resource_class": "",  # rempli plus bas
            "dcterms:title": doc.get("t") or "",
            "dcterms:identifier": doc.get("i") or "",
            "dcterms:date": doc.get("d") or "",
            "dcterms:creator": speaker if speaker and not is_collective(speaker) else "",
            "dcterms:publisher": speaker if is_collective(speaker) else "",
            "dcterms:abstract": abstract,
            "dcterms:description": description,
            "dcterms:source": doc.get("p") or "",
            "mvt:theme": MULTI_DELIM.join(facets.get("themes", [])),
            "mvt:ligne": MULTI_DELIM.join(facets.get("lignes_concernees", [])),
            "mvt:balise": MULTI_DELIM.join(facets.get("balises", [])),
            "mvt:evenement": MULTI_DELIM.join(facets.get("events", [])),
            "mvt:contexte": ctx_label,
        }

        # Routage : historique → ArticleOuvrage, sinon → Etude
        if is_historical_author(speaker):
            row["resource_class"] = "mvt:ArticleOuvrage"
            articles.append(row)
        else:
            row["resource_class"] = "mvt:Etude"
            etudes.append(row)

    fieldnames = [
        "resource_class", "dcterms:title", "dcterms:identifier", "dcterms:date",
        "dcterms:creator", "dcterms:publisher", "dcterms:abstract",
        "dcterms:description", "dcterms:source",
        "mvt:theme", "mvt:ligne", "mvt:balise", "mvt:evenement", "mvt:contexte",
    ]
    return etudes, articles, fieldnames


# ============================================================================
# MAIN
# ============================================================================

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true", help="Rapport sans écrire")
    ap.add_argument("--limit", type=int, default=0, help="Limite N documents (test)")
    ap.add_argument("--with-text", action="store_true",
                    help="Inclut le texte plein dans dcterms:description (défaut : abstract 1500 car)")
    args = ap.parse_args()

    corpus = load_corpus()
    print(f"  -> {len(corpus)} documents chargés")

    if args.limit > 0:
        corpus = corpus[:args.limit]
        print(f"  -> limite appliquée : {len(corpus)} documents")

    # Vérif enrichissement
    enrichis = sum(1 for d in corpus if "facets" in d)
    if enrichis < len(corpus):
        print(f"  [WARN] {enrichis}/{len(corpus)} documents enrichis. Lance enrich_corpus_taxonomy.py --merge d'abord.")

    print("\n=== Génération des CSV ===\n")

    auteurs, auteurs_fn = gen_auteurs(corpus)
    themes, themes_fn = gen_themes(corpus)
    lignes, lignes_fn = gen_lignes(corpus)
    balises, balises_fn = gen_balises(corpus)
    evenements, evenements_fn = gen_evenements(corpus)
    contextes, contextes_fn = gen_contextes(corpus)
    etudes, articles, docs_fn = gen_documents(corpus, with_full_text=args.with_text)

    reports = [
        ("1_auteurs", auteurs, auteurs_fn),
        ("2_themes", themes, themes_fn),
        ("3_lignes", lignes, lignes_fn),
        ("4_balises", balises, balises_fn),
        ("5_evenements", evenements, evenements_fn),
        ("6_contextes", contextes, contextes_fn),
        ("7_etudes", etudes, docs_fn),
        ("8_articles_ouvrages", articles, docs_fn),
    ]

    for name, rows, _ in reports:
        print(f"  omeka_import_{name:22s} : {len(rows):5d} lignes")

    if args.dry_run:
        print("\n[DRY-RUN] Rien écrit.")
        return

    OUT_DIR.mkdir(exist_ok=True)
    for name, rows, fn in reports:
        path = OUT_DIR / f"omeka_import_{name}.csv"
        write_csv(path, rows, fn)
        size = path.stat().st_size
        print(f"  -> {path.name} ({size:,} octets)")

    # Manifeste
    manifest = OUT_DIR / "MANIFEST.md"
    with manifest.open("w", encoding="utf-8") as f:
        f.write(f"""# Manifeste d'import Omeka S

Généré le : {datetime.now().isoformat(timespec='seconds')}
Source : fulltext_index_enriched.json
Documents traités : {len(corpus)}

## Ordre d'import IMPÉRATIF

Les 6 premiers CSV doivent être importés AVANT les 2 derniers,
sinon les références (dcterms:creator, mvt:theme, mvt:ligne, mvt:balise,
mvt:evenement, mvt:contexte) ne pourront pas se résoudre.

| Ordre | Fichier | Type | Lignes | À importer avant |
|---|---|---|---|---|
""")
        for i, (name, rows, _) in enumerate(reports, 1):
            f.write(f"| {i} | omeka_import_{name}.csv | ")
            classe = {
                "1_auteurs": "mvt:Auteur",
                "2_themes": "mvt:Theme",
                "3_lignes": "mvt:LignePropetique",
                "4_balises": "mvt:BaliseTemporelle",
                "5_evenements": "mvt:Evenement",
                "6_contextes": "mvt:Contexte",
                "7_etudes": "mvt:Etude",
                "8_articles_ouvrages": "mvt:ArticleOuvrage",
            }[name]
            f.write(f"{classe} | {len(rows)} | ")
            f.write("les documents 7 & 8" if i <= 6 else "-")
            f.write(" |\n")
        f.write(f"""

## Procédure d'import dans Omeka S

1. Installer le module CSV Import : https://omeka.org/s/modules/CSVImport/
2. Importer d'abord le vocabulaire `vocabularies/mouvement.ttl`
   (Admin → Contenu → Vocabulaires → Ajouter → Importer depuis un fichier)
3. Pour chaque CSV, dans l'ordre ci-dessus :
   - Admin → CSV Import → Importer un fichier
   - Type de ressource : Items
   - Classe de ressource : lire la colonne `resource_class` du CSV
   - Délimiteur de colonne : `,`
   - Délimiteur multi-valeurs : `|`
   - Mapper chaque colonne CSV vers la propriété Omeka correspondante
     (l'en-tête du CSV = URI de la propriété — le module reconnaît
     `dcterms:title`, `dcterms:creator`, `mvt:theme`, etc. automatiquement
     si le vocabulaire est installé)
   - Lancer l'import et vérifier les logs

## Format des CSV

- Encodage : UTF-8 avec BOM (compatible Excel Windows)
- Délimiteur de colonne : virgule
- Délimiteur multi-valeurs : |
- Toutes les cellules entre guillemets (protection des textes pleins
  contenant virgules et sauts de ligne)
""")
    print(f"\n  -> MANIFEST.md ({manifest.stat().st_size} octets)")

    print(f"\n[OK] {len(reports)} fichiers CSV + manifeste dans {OUT_DIR}")
    print(f"     Total documents : études={len(etudes)}, articles={len(articles)}")
    print(f"     Références : auteurs={len(auteurs)}, thèmes={len(themes)}, "
          f"lignes={len(lignes)}, balises={len(balises)}, "
          f"événements={len(evenements)}, contextes={len(contextes)}")


if __name__ == "__main__":
    main()
