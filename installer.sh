#!/bin/bash
# ----------------------------------------------------------------------
# installer.sh — poser le greffon sur une instance Providence réelle.
#
# Ce que fait ce script, et rien d'autre : les quatre liens symboliques du README, la
# vérification que le socle porte ce que le connecteur attend, et le patch du Browse si on le
# demande. **Il ne bascule jamais le moteur de recherche.** Tout ce qu'il pose est inerte tant
# que `search_engine_plugin` vaut autre chose que Meilisearch :
#
#   – le connecteur n'est chargé que si le socle le sélectionne ;
#   – le greffon applicatif ne fait qu'ajouter une ligne à « Vérification de la configuration » ;
#   – le patch du Browse interroge le moteur *configuré* : SqlSearch2 ne porte pas
#     getBrowseFacetContent(), la délégation se désactive d'elle-même et le SQL reste seul maître.
#
# C'est voulu : sur une instance en service, poser et basculer sont deux décisions distinctes,
# prises à deux moments distincts, et seule la seconde se voit des usagers. La bascule est une
# ligne dans app/conf/local/app.conf, et son retour en arrière est la même ligne.
#
# Usage :
#   ./installer.sh /var/www/…/providence                  → pose les liens, vérifie
#   ./installer.sh /var/www/…/providence --browse         → pose aussi la délégation du Browse
#   ./installer.sh /var/www/…/providence --retirer        → retire tout, rétablit l'original
#
# Idempotent : relancer ne fait rien de plus. Le patch du Browse se reconnaît à sa présence et
# n'est jamais appliqué deux fois.
# ----------------------------------------------------------------------
set -euo pipefail

DEPOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

rouge()  { printf '\033[31m%s\033[0m\n' "$*"; }
vert()   { printf '\033[32m%s\033[0m\n' "$*"; }
jaune()  { printf '\033[33m%s\033[0m\n' "$*"; }
info()   { printf '  %s\n' "$*"; }

CA="${1:-}"
MODE="${2:-}"

if [ -z "$CA" ]; then
    rouge "Usage : $0 <racine de Providence> [--browse|--retirer]"
    exit 2
fi

CA="$(cd "$CA" 2>/dev/null && pwd)" || { rouge "Introuvable : ${1}"; exit 2; }

[ -f "$CA/setup.php" ] || { rouge "Ce n'est pas une racine de Providence (setup.php absent) : $CA"; exit 2; }

# La délégation du Browse se pose en *substituant* app/lib/Browse/BaseBrowse.php, une
# classe-crochet de cinquante lignes que toutes les classes de browse traversent et qui ne fait
# rien d'autre que déléguer à BrowseEngine. Le cœur de CollectiveAccess — BrowseEngine.php et ses
# 8 516 lignes — n'est **pas** modifié : une version antérieure du connecteur y insérait ses
# 36 lignes, invisibles à la première montée de version du socle.
BASE_BROWSE="$CA/app/lib/Browse/BaseBrowse.php"
LIENS=(
    "$CA/app/lib/Plugins/SearchEngine/Meilisearch.php|$DEPOT/MeilisearchSearchEngine/Meilisearch.php"
    "$CA/app/lib/Plugins/SearchEngine/Meilisearch|$DEPOT/MeilisearchSearchEngine"
    "$CA/app/lib/Plugins/SearchEngine/MeilisearchConfigurationSettings.php|$DEPOT/MeilisearchSearchEngine/MeilisearchConfigurationSettings.php"
    "$CA/app/plugins/Meilisearch|$DEPOT/MeilisearchAppPlugin"
)

# ----------------------------------------------------------------------
# Retrait
# ----------------------------------------------------------------------
if [ "$MODE" = "--retirer" ]; then
    for paire in "${LIENS[@]}"; do
        cible="${paire%%|*}"
        if [ -L "$cible" ]; then rm -f "$cible"; info "retiré : $cible"; fi
    done
    if [ -L "$BASE_BROWSE" ]; then
        rm -f "$BASE_BROWSE"
        if [ -f "$BASE_BROWSE.avant-meilisearch" ]; then
            mv "$BASE_BROWSE.avant-meilisearch" "$BASE_BROWSE"
            info "délégation du Browse retirée, BaseBrowse.php d'origine rétabli"
        else
            rouge "BaseBrowse.php retiré mais sans sauvegarde — le rétablir depuis le socle !"
        fi
    fi
    vert "Greffon retiré. Vérifier que search_engine_plugin ne vaut plus Meilisearch."
    exit 0
fi

# ----------------------------------------------------------------------
# Ce que le socle doit porter
# ----------------------------------------------------------------------
echo
echo "Socle : $CA"

MANQUE=0

# Le fork appelle flushContentBuffer() sans method_exists() en fin de réindexation : son absence
# côté connecteur tue rebuild-search-index *après* avoir tout indexé. On la fournit ; on vérifie
# ici que le socle est bien celui qui l'attend, pour que la surprise vienne au bon moment.
if grep -q 'flushContentBuffer' "$CA/app/lib/Search/SearchIndexer.php" 2>/dev/null; then
    info "SearchIndexer appelle flushContentBuffer() — le connecteur la fournit"
fi

if grep -q 'function getFieldDataForReindex' "$CA/app/lib/Search/SearchIndexer.php" 2>/dev/null; then
    info "SearchIndexer::getFieldDataForReindex() présent — les outils l'emprunteront"
else
    jaune "SearchIndexer::getFieldDataForReindex() absent — les outils passeront par le repli de tools/_socle.php"
fi

if [ ! -f "$CA/app/conf/local/app.conf" ]; then
    jaune "app/conf/local/app.conf absent : c'est là, et nulle part ailleurs, que la bascule s'écrira."
    jaune "  (app/conf/local/app_<nom>.conf n'est pas chargé par Configuration::load())"
fi

# ----------------------------------------------------------------------
# Les liens
# ----------------------------------------------------------------------
echo
for paire in "${LIENS[@]}"; do
    cible="${paire%%|*}"
    source="${paire##*|}"

    [ -e "$source" ] || { rouge "source absente : $source"; MANQUE=1; continue; }

    if [ -L "$cible" ] && [ "$(readlink "$cible")" = "$source" ]; then
        info "déjà en place : $(basename "$cible")"
        continue
    fi

    # Un fichier *réel* à la place d'un lien, c'est une installation antérieure faite autrement :
    # on ne l'écrase pas en silence.
    if [ -e "$cible" ] && [ ! -L "$cible" ]; then
        rouge "présent et n'est pas un lien : $cible — à écarter à la main"
        MANQUE=1
        continue
    fi

    ln -sfn "$source" "$cible"
    vert "  posé : $(basename "$cible")"
done

# ----------------------------------------------------------------------
# Le patch du Browse
# ----------------------------------------------------------------------
if [ "$MODE" = "--browse" ]; then
    echo
    if [ -L "$BASE_BROWSE" ]; then
        info "délégation du Browse : déjà posée"
    elif [ ! -f "$BASE_BROWSE" ]; then
        rouge "BaseBrowse.php introuvable — socle inattendu, délégation non posée"; MANQUE=1
    else
        # L'original est conservé à côté : c'est lui que --retirer remet en place. Sans cette
        # sauvegarde, retirer le greffon laisserait l'instance sans classe BaseBrowse du tout.
        cp -n "$BASE_BROWSE" "$BASE_BROWSE.avant-meilisearch"
        if ln -sfn "$DEPOT/MeilisearchSearchEngine/msearch_BaseBrowse.php" "$BASE_BROWSE"; then
            vert "  délégation du Browse posée (sauvegarde : $(basename "$BASE_BROWSE").avant-meilisearch)"
        else
            rouge "délégation du Browse impossible"; MANQUE=1
        fi
    fi
fi

# ----------------------------------------------------------------------
# Ce qui reste à faire, et qui ne se fait pas tout seul
# ----------------------------------------------------------------------
echo
if [ "$MANQUE" -ne 0 ]; then
    rouge "Installation incomplète — voir ci-dessus."
    exit 1
fi

vert "Greffon posé. Rien n'a changé pour les usagers : le moteur en service est inchangé."
echo
echo "Ensuite, dans l'ordre :"
echo
echo "  1. l'adresse du service, dans MeilisearchAppPlugin/conf/meilisearch.conf"
echo "     (ou CA_MEILISEARCH_URL, ou __CA_MEILISEARCH_URL__ dans setup.php)"
echo
echo "  2. éprouver le socle sans rien basculer :"
echo "       php $CA/app/plugins/Meilisearch/tools/verifier-socle.php"
echo
echo "  3. basculer — le seul geste que les usagers verront :"
echo "       search_engine_plugin = Meilisearch    dans $CA/app/conf/local/app.conf"
echo
echo "  4. réindexer :"
echo "       php $CA/app/plugins/Meilisearch/tools/reindexer.php"
echo
echo "  Retour en arrière : remettre la ligne du 3 à sa valeur d'avant. L'index reste en place."
echo
