#!/usr/bin/env bash
# Despliegue del personalizador QR en Hostinger.
# --check: compara archivos sin modificar producción.
# --apply: respalda y publica únicamente los archivos del modal QR.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_personalizador_qr_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_QR_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_QR_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -f "$SRC/index.php" ]]; then
    echo "ERROR: no se encontró el repositorio fuente." >&2
    exit 1
fi

if [[ -n "${IMPE_QR_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_QR_PUBLIC_DIR"
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
    "app/views/formularios/registro.php"
    "app/views/formularios/inscripcion.php"
    "public/javascript/formularios_registro.js"
    "public/css/formularios.css"
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
    echo "=== PERSONALIZADOR QR ==="
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
        echo "OK: producción ya tiene el personalizador QR actualizado."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi

    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_QR_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/personalizador-qr-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

echo "Creando respaldo en: $BACKUP"

for file in "${FILES[@]}"; do
    if [[ -f "$DST/$file" ]]; then
        mkdir -p "$BACKUP/$(dirname "$file")"
        cp -p "$DST/$file" "$BACKUP/$file"
    fi
done

for file in "${FILES[@]}"; do
    mkdir -p "$DST/$(dirname "$file")"
    cp "$SRC/$file" "$DST/$file"
    chmod 0644 "$DST/$file"
    echo "PUBLICADO $file"
done

grep -Fq "data-qr-dot-style"     "$DST/app/views/formularios/registro.php"
grep -Fq "data-qr-dot-style"     "$DST/app/views/formularios/inscripcion.php"
grep -Fq "finderStyle"     "$DST/public/javascript/formularios_registro.js"
grep -Fq "FORMULARIOS · PERSONALIZADOR QR"     "$DST/public/css/formularios.css"
grep -Fq "data-download-form-qr"     "$DST/app/views/formularios/registro.php"
grep -Fq "data-copy-form-qr"     "$DST/app/views/formularios/registro.php"
grep -Fq "data-qr-image-size"     "$DST/app/views/formularios/registro.php"
grep -Fq "data-qr-image-size"     "$DST/app/views/formularios/inscripcion.php"
grep -Fq "centerImageSizeRatio"     "$DST/public/javascript/formularios_registro.js"

echo
echo "OK: personalizador QR publicado correctamente."
echo "Incluye colores, estilos, tamaño, margen, imagen central ajustable, copiar y descargar PNG."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
