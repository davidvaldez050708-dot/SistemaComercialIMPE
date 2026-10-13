#!/usr/bin/env bash
# Publica el cambio de logo en las pantallas de agradecimiento.
# La imagen "logo-duo-color.png" debe existir previamente en el repositorio fuente.
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

LOGO="$DST/public/img/brand/logo-duo-color.png"
LOGO_SOURCE=""

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo "Logo esperado      : $LOGO"
echo

if [[ -f "$LOGO" ]]; then
    LOGO_SOURCE="$LOGO"
else
    LOGO_SOURCE="$(find "$DST" "$SRC" -type f -iname 'logo-duo-color.png' -print -quit 2>/dev/null || true)"

    if [[ -z "$LOGO_SOURCE" ]]; then
        LOGO_SOURCE="$(find "$HOME" -maxdepth 6 -type f -iname 'logo-duo-color.png' -print -quit 2>/dev/null || true)"
    fi

    if [[ -z "$LOGO_SOURCE" ]]; then
        echo "ERROR: no se encontró la imagen 'logo-duo-color.png' dentro de:"
        echo "  $DST"
        echo "  $SRC"
        echo "  $HOME (hasta 6 niveles)"
        echo
        echo "Puedes localizarla manualmente con:"
        echo "  find \"$HOME\" -type f -iname 'logo-duo-color.png' 2>/dev/null"
        exit 1
    fi

    echo "ENCONTRADA EN      : $LOGO_SOURCE"
fi

if [[ "$MODE" == "--check" ]]; then
    if [[ "$LOGO_SOURCE" == "$LOGO" ]]; then
        echo "OK: logo-duo-color.png ya está en la carpeta correcta."
    else
        echo "PENDIENTE: la imagen existe, pero está en otra ubicación."
        echo "Con --apply se copiará automáticamente a:"
        echo "  $LOGO"
    fi
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

if [[ "$LOGO_SOURCE" != "$LOGO" ]]; then
    mkdir -p "$(dirname "$LOGO")"
    cp "$LOGO_SOURCE" "$LOGO"
    chmod 0644 "$LOGO"
    echo "PUBLICADO public/img/brand/logo-duo-color.png"
fi

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

grep -Fq "logo-duo-color.png"     "$DST/app/views/formularios/publico_gracias.php"

grep -Fq "logo-duo-color.png"     "$DST/app/views/formularios/publico_gracias_inscripcion.php"

echo
echo "OK: el logo anterior fue sustituido por 'logo-duo-color.png'."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
