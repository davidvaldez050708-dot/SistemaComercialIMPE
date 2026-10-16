#!/usr/bin/env bash
# Despliega únicamente la estandarización visual de selectores.
# No modifica lógica de negocio ni estructura de datos.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_selectores_uniformes_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_UI_SOURCE_DIR:-$PWD}"
if [[ ! -d "$SRC/.git" ]]; then
    SRC="${IMPE_UI_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -d "$SRC/.git" ]]; then
    echo "ERROR: no se encontró el repositorio local." >&2
    exit 1
fi

DST="${IMPE_UI_PUBLIC_DIR:-}"

if [[ -z "$DST" ]]; then
    for candidate in         "$HOME/domains/fundacionrededumex.org/public_html"         "$HOME/domains/mediumturquoise-starling-552985.hostingersite.com/public_html"         "$HOME/public_html"
    do
        if [[ -f "$candidate/index.php" ]]; then
            DST="$candidate"
            break
        fi
    done
fi

if [[ -z "$DST" || ! -f "$DST/index.php" ]]; then
    echo "ERROR: no se encontró la instalación pública." >&2
    exit 1
fi

cd "$SRC"
git fetch origin main >/dev/null 2>&1

FILES=(
    "public/javascript/selectores_uniformes.js"
    "public/css/dashboard.css"
    "app/views/layout/dashboard_footer.php"
)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for file in "${FILES[@]}"; do
    mkdir -p "$TMP/$(dirname "$file")"
    git show "origin/main:$file" > "$TMP/$file"
done

grep -Fq "uniform-select-option.is-selected" "$TMP/public/css/dashboard.css"
grep -Fq "selectores_uniformes.js" "$TMP/app/views/layout/dashboard_footer.php"
grep -Fq "uniform-select-native" "$TMP/public/javascript/selectores_uniformes.js"

if [[ "$MODE" == "--check" ]]; then
    changes=0

    for file in "${FILES[@]}"; do
        if [[ ! -f "$DST/$file" ]]; then
            echo "NUEVO     $file"
            changes=$((changes + 1))
        elif cmp -s "$TMP/$file" "$DST/$file"; then
            echo "OK        $file"
        else
            echo "CAMBIA    $file"
            changes=$((changes + 1))
        fi
    done

    echo

    if [[ "$changes" -eq 0 ]]; then
        echo "OK: los selectores ya tienen el diseño uniforme en producción."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi

    exit 0
fi

BACKUP_ROOT="${IMPE_UI_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/selectores-uniformes-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

for file in "${FILES[@]}"; do
    if [[ -f "$DST/$file" ]]; then
        mkdir -p "$BACKUP/$(dirname "$file")"
        cp -p "$DST/$file" "$BACKUP/$file"
    fi

    mkdir -p "$DST/$(dirname "$file")"
    cp "$TMP/$file" "$DST/$file"
    chmod 0644 "$DST/$file"
    echo "PUBLICADO $file"
done

echo
echo "OK: diseño uniforme de selectores publicado."
echo "No se modificó la lógica funcional de los formularios."
echo "Respaldo: $BACKUP"
echo "Recarga con Ctrl+F5."
