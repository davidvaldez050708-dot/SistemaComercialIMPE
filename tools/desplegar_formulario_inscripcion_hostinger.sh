#!/usr/bin/env bash
# Despliegue del formulario de Inscripción (verde) en Hostinger.
# --check: compara archivos sin modificar producción.
# --apply: respalda y publica únicamente los archivos de esta implementación.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_formulario_inscripcion_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_INSCRIPCION_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_INSCRIPCION_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -f "$SRC/index.php" ]]; then
    echo "ERROR: no se encontró el repositorio fuente." >&2
    exit 1
fi

if [[ -n "${IMPE_INSCRIPCION_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_INSCRIPCION_PUBLIC_DIR"
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
    echo "Puedes indicar la ruta con IMPE_INSCRIPCION_PUBLIC_DIR=/ruta/public_html." >&2
    exit 1
fi

FILES=(
    "index.php"
    "app/controllers/FormularioController.php"
    "app/controllers/FormularioPublicoController.php"
    "app/models/FormularioRegistroModel.php"
    "app/views/formularios/registro.php"
    "app/views/formularios/inscripcion.php"
    "app/views/formularios/publico_inscripcion.php"
    "app/views/formularios/publico_gracias_inscripcion.php"
    "public/javascript/formularios_registro.js"
    "public/css/formularios.css"
    "public/css/formularios_publico.css"
    "public/img/brand/logos-inscripcion.svg"
)

echo "Repositorio fuente : $SRC"
echo "Instalación pública: $DST"
echo

missing=0
for file in "${FILES[@]}"; do
    if [[ ! -f "$SRC/$file" ]]; then
        echo "FALTA EN FUENTE: $file"
        missing=1
    fi
done

if [[ "$missing" -ne 0 ]]; then
    echo "ERROR: faltan archivos fuente; no se continuará." >&2
    exit 1
fi

if [[ "$MODE" == "--check" ]]; then
    echo "Comprobando archivos de Formulario de Inscripción..."
    echo

    changes=0
    for file in "${FILES[@]}"; do
        if [[ ! -f "$DST/$file" ]]; then
            echo "NUEVO     $file"
            changes=$((changes + 1))
            continue
        fi

        src_hash="$(sha256sum "$SRC/$file" | awk '{print $1}')"
        dst_hash="$(sha256sum "$DST/$file" | awk '{print $1}')"

        if [[ "$src_hash" == "$dst_hash" ]]; then
            echo "OK        $file"
        else
            echo "CAMBIA    $file"
            changes=$((changes + 1))
        fi
    done

    echo
    if [[ "$changes" -eq 0 ]]; then
        echo "OK: producción ya coincide con la implementación de Inscripción."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
        echo "Ejecuta --apply para respaldar y publicar esos archivos."
    fi

    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_INSCRIPCION_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/formulario-inscripcion-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

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

# Validaciones mínimas de la implementación.
grep -Fq "generarEnlaceInscripcion" "$DST/app/controllers/FormularioController.php"
grep -Fq "guardarInscripcion" "$DST/app/controllers/FormularioPublicoController.php"
grep -Fq "formulario-publico-inscripcion" "$DST/app/views/formularios/publico_inscripcion.php"
grep -Fq "formularios-inscripcion-theme" "$DST/public/css/formularios.css"
grep -Fq "FORMULARIO PÚBLICO DE INSCRIPCIÓN · TEMA VERDE" "$DST/public/css/formularios_publico.css"
grep -Fq "'graciasInscripcion'" "$DST/index.php"

echo
echo "OK: Formulario de Inscripción publicado correctamente."
echo "Respaldo: $BACKUP"
echo "No requiere migración de base de datos."
echo "Recarga el navegador con Ctrl+F5."
