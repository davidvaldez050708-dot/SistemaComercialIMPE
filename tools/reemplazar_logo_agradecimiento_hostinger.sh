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
    "public/img/brand/logo-duo-color.png"
    "public/img/brand/Logo-SEP-Negro.png"
)

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo

for file in "${FILES[@]}"; do
    if [[ ! -f "$SRC/$file" ]]; then
        echo "ERROR: falta en fuente $file" >&2
        exit 1
    fi
done

if [[ "$MODE" == "--check" ]]; then
    changes=0

    for file in "${FILES[@]}"; do
        if [[ ! -f "$DST/$file" ]]; then
            echo "NUEVO     $file"
            changes=$((changes + 1))
        elif cmp -s "$SRC/$file" "$DST/$file"; then
            echo "OK        $file"
        else
            echo "CAMBIA    $file"
            changes=$((changes + 1))
        fi
    done

    echo
    if [[ "$changes" -eq 0 ]]; then
        echo "OK: producción ya tiene ambos logos de agradecimiento actualizados."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi
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

grep -Fq "logo-duo-color.png"     "$DST/app/views/formularios/publico_gracias.php"

grep -Fq "logo-duo-color.png" "$DST/app/views/formularios/publico_gracias_inscripcion.php"
grep -Fq "Logo-SEP-Negro.png" "$DST/app/views/formularios/publico_gracias.php"
grep -Fq "Logo-SEP-Negro.png" "$DST/app/views/formularios/publico_gracias_inscripcion.php"
test -s "$DST/public/img/brand/logo-duo-color.png"
test -s "$DST/public/img/brand/Logo-SEP-Negro.png"

echo
echo "OK: logos Fundación/IMPE y SEP publicados correctamente."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
