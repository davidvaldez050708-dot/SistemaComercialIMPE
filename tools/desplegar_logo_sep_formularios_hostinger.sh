#!/usr/bin/env bash
# Despliegue del logo SEP junto al logotipo institucional en formularios públicos.
# --check: compara sin modificar producción.
# --apply: respalda y publica únicamente vistas, CSS y logo SEP.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_logo_sep_formularios_hostinger.sh --check|--apply" >&2
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
    "app/views/formularios/publico_registro.php"
    "app/views/formularios/publico_inscripcion.php"
    "public/css/formularios_publico.css"
    "public/img/brand/Logo-Sep-Blanco.png"
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
        echo "OK: producción ya contiene el logo SEP junto al logo institucional."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi
    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_FORM_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/logo-sep-formularios-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

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

grep -Fq "Logo-Sep-Blanco.png" "$DST/app/views/formularios/publico_registro.php"
grep -Fq "Logo-Sep-Blanco.png" "$DST/app/views/formularios/publico_inscripcion.php"
grep -Fq "formulario-publico-hero-logo-sep" "$DST/public/css/formularios_publico.css"
test -s "$DST/public/img/brand/Logo-Sep-Blanco.png"

echo
echo "OK: logo SEP publicado junto al logo institucional en Registro e Inscripción."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
