#!/usr/bin/env bash
# Despliega únicamente el estado Pendiente / Con convocatoria de convenios en Marketing.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_estado_convenios_marketing_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_CONVENIOS_SOURCE_DIR:-$PWD}"
if [[ ! -d "$SRC/.git" ]]; then
    SRC="${IMPE_CONVENIOS_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -d "$SRC/.git" ]]; then
    echo "ERROR: no se encontró el repositorio local." >&2
    exit 1
fi

DST="${IMPE_CONVENIOS_PUBLIC_DIR:-}"

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
    "index.php"
    "app/models/CrearConvocatoriaModel.php"
    "app/controllers/CrearConvocatoriaController.php"
    "app/controllers/ConvocatoriaController.php"
    "app/views/convocatorias/index.php"
    "app/views/convocatorias/crear_desde_convenio.php"
    "public/javascript/crear_convocatoria.js"
    "public/css/crear_convocatoria.css"
    "database/migrations/2026_10_16_marketing_convenio_atencion.sql"
    "tools/migrar_marketing_convenio_atencion.php"
)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

for file in "${FILES[@]}"; do
    mkdir -p "$TMP/$(dirname "$file")"
    git show "origin/main:$file" > "$TMP/$file"
done

grep -Fq "marketing_convenio_atencion" "$TMP/app/models/CrearConvocatoriaModel.php"
grep -Fq "seguimiento_origen_id" "$TMP/app/views/convocatorias/index.php"
grep -Fq "marcarConvenioAtendido" "$TMP/app/controllers/ConvocatoriaController.php"
grep -Fq "data-convenio-modal-attention" "$TMP/app/views/convocatorias/crear_desde_convenio.php"
grep -Fq "Con convocatoria" "$TMP/public/javascript/crear_convocatoria.js"
if grep -Fq "action=marcarAtendido" "$TMP/public/javascript/crear_convocatoria.js"; then
    echo "ERROR: el estado todavía se intenta cambiar al pulsar Crear convocatoria." >&2
    exit 1
fi
grep -Fq "Estado de atención de convenios" "$TMP/public/css/crear_convocatoria.css"

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
        echo "OK: el estado de convenios ya está actualizado en producción."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi

    exit 0
fi

BACKUP_ROOT="${IMPE_CONVENIOS_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/estado-convenios-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

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

php "$DST/tools/migrar_marketing_convenio_atencion.php"

echo
echo "OK: estado Pendiente / Con convocatoria publicado."
echo "Respaldo: $BACKUP"
echo "Recarga con Ctrl+F5."
