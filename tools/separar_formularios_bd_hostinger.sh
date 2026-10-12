#!/usr/bin/env bash
# Separa los datos de Formulario de Registro y Formulario de Inscripción.
# --check: sólo inspecciona código y base de datos.
# --apply: migra las inscripciones de forma transaccional y publica el modelo actualizado.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/separar_formularios_bd_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_FORMULARIOS_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_FORMULARIOS_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -f "$SRC/index.php" ]]; then
    echo "ERROR: no se encontró el repositorio fuente." >&2
    exit 1
fi

if [[ -n "${IMPE_FORMULARIOS_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_FORMULARIOS_PUBLIC_DIR"
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

MODEL="app/models/FormularioRegistroModel.php"
MIGRATION="database/migrations/2026_10_12_separar_formularios_registro_inscripcion.sql"
HELPER="tools/separar_formularios_bd.php"

for file in "$MODEL" "$MIGRATION" "$HELPER"; do
    if [[ ! -f "$SRC/$file" ]]; then
        echo "ERROR: falta $file en el repositorio fuente." >&2
        exit 1
    fi
done

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo

if [[ "$MODE" == "--check" ]]; then
    echo "=== CÓDIGO ==="
    if [[ -f "$DST/$MODEL" ]] && cmp -s "$SRC/$MODEL" "$DST/$MODEL"; then
        echo "OK: modelo de producción ya está actualizado."
    else
        echo "PENDIENTE: el modelo de producción requiere actualización."
    fi

    echo
    echo "=== BASE DE DATOS ==="
    IMPE_RUNTIME_ROOT="$DST" IMPE_RUNTIME_ROOT="$DST" php "$SRC/$HELPER" --check
    echo
    echo "No se modificó ningún archivo ni dato (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_FORMULARIOS_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/separacion-formularios-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

echo "Respaldo de código: $BACKUP"
if [[ -f "$DST/$MODEL" ]]; then
    mkdir -p "$BACKUP/$(dirname "$MODEL")"
    cp -p "$DST/$MODEL" "$BACKUP/$MODEL"
fi

echo
echo "1/2 Separando datos en la base..."
IMPE_RUNTIME_ROOT="$DST" php "$SRC/$HELPER" --apply

echo
echo "2/2 Publicando modelo actualizado..."
mkdir -p "$DST/$(dirname "$MODEL")"
cp "$SRC/$MODEL" "$DST/$MODEL"
chmod 0644 "$DST/$MODEL"

mkdir -p "$DST/$(dirname "$MIGRATION")"
cp "$SRC/$MIGRATION" "$DST/$MIGRATION"
chmod 0644 "$DST/$MIGRATION"

grep -Fq "formulario_inscripciones" "$DST/$MODEL"

echo
echo "=== VERIFICACIÓN FINAL ==="
php "$SRC/$HELPER" --check

echo
echo "OK: Registro e Inscripción quedaron separados."
echo "Formulario de Registro -> formulario_registros"
echo "Formulario de Inscripción -> formulario_inscripciones"
echo "Respaldo del código anterior: $BACKUP"
