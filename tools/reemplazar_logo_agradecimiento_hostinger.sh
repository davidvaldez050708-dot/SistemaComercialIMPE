#!/usr/bin/env bash
# Publica el cambio de logo en las pantallas de agradecimiento.
# La imagen "logos juntos.png" debe existir previamente en producción.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/reemplazar_logo_agradecimiento_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_LOGO_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_LOGO_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ -n "${IMPE_LOGO_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_LOGO_PUBLIC_DIR"
else
    CANDIDATES=(
        "$HOME/domains/fundacionrededumex.org/public_html"
        "$HOME/domains/mediumturquoise-starling-552985.hostingersite.com/public_html"
        "$HOME/public_html"
    )
    DST=""
    for candidate in "${CANDIDATES[@]}"; do
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

FILES=(
    "app/views/formularios/publico_gracias.php"
    "app/views/formularios/publico_gracias_inscripcion.php"
    "public/css/formularios_publico.css"
)

LOGO="$DST/public/img/brand/logos juntos.png"

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo "Logo esperado      : $LOGO"
echo

if [[ ! -f "$LOGO" ]]; then
    echo "ERROR: no se encontró la imagen 'logos juntos.png' en:"
    echo "  $DST/public/img/brand/"
    echo
    echo "Coloca ahí la imagen antes de aplicar el cambio."
    exit 1
fi

if [[ "$MODE" == "--check" ]]; then
    echo "OK: se encontró la imagen logos juntos.png."
    echo

    for file in "${FILES[@]}"; do
        if [[ ! -f "$SRC/$file" ]]; then
            echo "FALTA EN FUENTE: $file"
            exit 1
        fi

        if [[ ! -f "$DST/$file" ]]; then
            echo "NUEVO     $file"
        elif cmp -s "$SRC/$file" "$DST/$file"; then
            echo "OK        $file"
        else
            echo "CAMBIA    $file"
        fi
    done

    echo
    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_LOGO_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/logo-agradecimiento-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

for file in "${FILES[@]}"; do
    if [[ -f "$DST/$file" ]]; then
        mkdir -p "$BACKUP/$(dirname "$file")"
        cp -p "$DST/$file" "$BACKUP/$file"
    fi

    mkdir -p "$DST/$(dirname "$file")"
    cp "$SRC/$file" "$DST/$file"
    chmod 0644 "$DST/$file"
    echo "PUBLICADO $file"
done

grep -Fq "logos%20juntos.png"     "$DST/app/views/formularios/publico_gracias.php"

grep -Fq "logos%20juntos.png"     "$DST/app/views/formularios/publico_gracias_inscripcion.php"

echo
echo "OK: el logo anterior fue sustituido por 'logos juntos.png'."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
