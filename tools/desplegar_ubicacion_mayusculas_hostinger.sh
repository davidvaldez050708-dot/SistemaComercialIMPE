#!/usr/bin/env bash
# Despliegue de Estados y Municipios en mayúsculas.
# --check: compara sin modificar producción.
# --apply: respalda y publica únicamente vistas/JS de formularios.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_ubicacion_mayusculas_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_FORM_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_FORM_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -f "$SRC/index.php" ]]; then
    echo "ERROR: no se encontró el repositorio fuente." >&2
    exit 1
fi

if [[ -n "${IMPE_FORM_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_FORM_PUBLIC_DIR"
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
    "app/views/formularios/publico_registro.php"
    "app/views/formularios/publico_inscripcion.php"
    "public/javascript/formularios_registro.js"
    "public/javascript/formularios_registro_publico.js"
)

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo

for file in "${FILES[@]}"; do
    [[ -f "$SRC/$file" ]] || {
        echo "ERROR: falta en fuente $file" >&2
        exit 1
    }
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
        echo "OK: producción ya muestra Estados y Municipios en mayúsculas."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi
    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_FORM_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/ubicacion-mayusculas-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

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

grep -Fq "mb_strtoupper" "$DST/app/views/formularios/publico_registro.php"
grep -Fq "mb_strtoupper" "$DST/app/views/formularios/publico_inscripcion.php"
grep -Fq "toLocaleUpperCase('es-MX')" "$DST/public/javascript/formularios_registro.js"
grep -Fq "toLocaleUpperCase('es-MX')" "$DST/public/javascript/formularios_registro_publico.js"

echo
echo "OK: Estados y Municipios publicados en mayúsculas."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
