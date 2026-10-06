#!/usr/bin/env bash
#
# Fabrique l'archive que le marchand installe dans WordPress.
#
# Pourquoi un script et pas un zip fait à la main
# -----------------------------------------------
# Une archive d'extension est livrée à un tiers : ce qu'on y oublie part chez
# lui, et ce qu'on y met en trop aussi. Les tests, les dépendances de
# développement et le dossier .git n'ont rien à faire sur son serveur — au
# mieux ils l'alourdissent, au pire ils exposent l'historique du projet. Le
# faire à la main, c'est l'oublier un jour.
#
# Pourquoi un dossier à la racine de l'archive
# --------------------------------------------
# WordPress dépose le contenu de l'archive dans wp-content/plugins/ tel quel.
# Sans dossier englobant, les fichiers s'éparpillent à côté des autres
# extensions, et la désinstallation ne retrouve plus rien.
#
# Usage : ./fabriquer-le-zip.sh   →  dist/felar-connect-<version>.zip

set -euo pipefail

racine="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$racine"

# La version fait foi là où WordPress la lit : l'en-tête du fichier principal.
version="$(sed -n 's/^ \* Version: *//p' felar-connect.php | tr -d '[:space:]')"
if [ -z "$version" ]; then
    echo "Version introuvable dans l'en-tête de felar-connect.php." >&2
    exit 1
fi

# Un écart entre l'en-tête et readme.txt se voit chez le marchand : WordPress
# affiche l'une et propose la mise à jour d'après l'autre. Mieux vaut refuser
# de fabriquer que livrer deux versions qui se contredisent.
stable="$(sed -n 's/^Stable tag: *//p' readme.txt | tr -d '[:space:]')"
if [ "$version" != "$stable" ]; then
    echo "felar-connect.php annonce $version et readme.txt annonce $stable." >&2
    echo "Alignez les deux avant de fabriquer l'archive." >&2
    exit 1
fi

nom="felar-connect"
sortie="dist/${nom}-${version}.zip"
atelier="$(mktemp -d)"
trap 'rm -rf "$atelier"' EXIT

mkdir -p "$atelier/$nom" dist

# Liste blanche, et non liste noire : ce qui n'est pas nommé ici ne part pas.
# Une exclusion oubliée livre un fichier ; une inclusion oubliée casse
# l'extension, ce qui se voit tout de suite.
for element in felar-connect.php uninstall.php readme.txt includes admin; do
    if [ ! -e "$element" ]; then
        echo "Élément attendu absent : $element" >&2
        exit 1
    fi
    cp -R "$element" "$atelier/$nom/"
done

rm -f "$sortie"

# `zip` sur une machine de développement Linux ou une intégration continue ;
# PowerShell sur un poste Windows, où il n'est pas installé par défaut. Sans
# cette bascule, le script ne tournerait pas là où l'extension est écrite.
if command -v zip > /dev/null 2>&1; then
    ( cd "$atelier" && zip -rq "$racine/$sortie" "$nom" -x '*.DS_Store' )
elif command -v powershell > /dev/null 2>&1; then
    source_win="$(cd "$atelier" && pwd -W 2>/dev/null || echo "$atelier")/$nom"
    sortie_win="$(pwd -W 2>/dev/null || pwd)/$sortie"
    powershell -NoProfile -Command \
        "Compress-Archive -Path '${source_win}' -DestinationPath '${sortie_win}' -Force" \
        > /dev/null
else
    echo "Ni zip ni powershell : impossible de fabriquer l'archive." >&2
    exit 1
fi

if [ ! -f "$sortie" ]; then
    echo "L'archive n'a pas été produite." >&2
    exit 1
fi

echo "Archive : $sortie"
echo "Version : $version"
echo "Taille  : $(du -h "$sortie" | cut -f1)"
