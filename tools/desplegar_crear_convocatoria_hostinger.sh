#!/usr/bin/env bash
# Despliega el módulo Marketing > Crear convocatoria en Hostinger.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_crear_convocatoria_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_CREAR_CONVOCATORIA_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_CREAR_CONVOCATORIA_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ -n "${IMPE_CREAR_CONVOCATORIA_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_CREAR_CONVOCATORIA_PUBLIC_DIR"
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
    "index.php"
    "app/models/CrearConvocatoriaModel.php"
    "app/controllers/CrearConvocatoriaController.php"
    "app/views/convocatorias/crear_desde_convenio.php"
    "app/views/layout/sidebar.php"
    "app/views/layout/dashboard_head.php"
    "public/css/crear_convocatoria.css"
    "public/javascript/crear_convocatoria.js"
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

if command -v php >/dev/null 2>&1; then
    php -l "$SRC/app/models/CrearConvocatoriaModel.php" >/dev/null
    php -l "$SRC/app/controllers/CrearConvocatoriaController.php" >/dev/null
    php -l "$SRC/app/views/convocatorias/crear_desde_convenio.php" >/dev/null
fi

# Evita falsos positivos si el repositorio local de Hostinger está atrasado.
if ! grep -Fq "aliados_asignaciones" "$SRC/app/models/CrearConvocatoriaModel.php"; then
    echo "ERROR: el repositorio fuente todavía tiene la versión anterior del modelo." >&2
    echo "Ejecuta: git fetch origin && git reset --hard origin/main" >&2
    exit 1
fi

if ! grep -Fq "Convenios de aliados" "$SRC/app/views/convocatorias/crear_desde_convenio.php"; then
    echo "ERROR: el repositorio fuente todavía tiene la vista anterior de Crear convocatoria." >&2
    echo "Ejecuta: git fetch origin && git reset --hard origin/main" >&2
    exit 1
fi

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
        echo "OK: Crear convocatoria ya está actualizado en producción."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi

    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_CREAR_CONVOCATORIA_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/crear-convocatoria-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

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

grep -Fq "case 'crearConvocatoria':" "$DST/index.php"
grep -Fq "Crear convocatoria" "$DST/app/views/layout/sidebar.php"
grep -Fq "crearConvocatoria" "$DST/app/views/layout/sidebar.php"
grep -Fq "aliados_asignaciones" "$DST/app/models/CrearConvocatoriaModel.php"
grep -Fq "convenio_formalizado_at" "$DST/app/models/CrearConvocatoriaModel.php"
grep -Fq "data-convenio-create-link" "$DST/app/views/convocatorias/crear_desde_convenio.php"
grep -Fq "CONVENIO · ALIADO" "$DST/app/views/convocatorias/crear_desde_convenio.php"
grep -Fq "data-convenio-modal-contact" "$DST/app/views/convocatorias/crear_desde_convenio.php"
grep -Fq "territorio_id" "$DST/public/javascript/crear_convocatoria.js"

echo
echo "OK: bandeja Marketing conectada al catálogo actual de Aliados."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
