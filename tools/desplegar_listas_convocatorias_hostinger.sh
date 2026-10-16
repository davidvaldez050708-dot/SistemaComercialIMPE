#!/usr/bin/env bash
# Despliega únicamente los cambios de listas/desplegables de Convocatorias.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_listas_convocatorias_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_CONVOCATORIAS_SOURCE_DIR:-$PWD}"
if [[ ! -d "$SRC/.git" ]]; then
    SRC="${IMPE_CONVOCATORIAS_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -d "$SRC/.git" ]]; then
    echo "ERROR: no se encontró el repositorio Git local." >&2
    exit 1
fi

if [[ -n "${IMPE_CONVOCATORIAS_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_CONVOCATORIAS_PUBLIC_DIR"
else
    DST=""
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
    "app/views/convocatorias/index.php"
    "public/css/convocatorias.css"
    "public/css/crear_convocatoria.css"
)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for file in "${FILES[@]}"; do
    mkdir -p "$TMP/$(dirname "$file")"
    git show "origin/main:$file" > "$TMP/$file"
done

grep -Fq "convocatoria-filter-dropdown" "$TMP/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-filter-value" "$TMP/app/views/convocatorias/index.php"
grep -Fq "Desplegables uniformes del módulo Convocatorias" "$TMP/public/css/convocatorias.css"
grep -Fq "#223A84" "$TMP/public/css/convocatorias.css"
grep -Fq "#223A84" "$TMP/public/css/crear_convocatoria.css"

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
        echo "OK: los desplegables ya están actualizados en producción."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi
    exit 0
fi

BACKUP_ROOT="${IMPE_CONVOCATORIAS_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/listas-convocatorias-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

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

grep -Fq "convocatoria-filter-dropdown" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-filter-value" "$DST/app/views/convocatorias/index.php"
grep -Fq "#223A84" "$DST/public/css/convocatorias.css"
grep -Fq "#223A84" "$DST/public/css/crear_convocatoria.css"

echo
echo "OK: listas de Convocatorias actualizadas en producción."
echo "Respaldo: $BACKUP"
echo "Recarga con Ctrl+F5."
