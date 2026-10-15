#!/usr/bin/env bash
# Despliega cambios de la vista de Convocatorias a Hostinger.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
    echo "Uso: bash tools/desplegar_convocatorias_hostinger.sh --check|--apply" >&2
    exit 2
fi

SRC="${IMPE_CONVOCATORIAS_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
    SRC="${IMPE_CONVOCATORIAS_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ -n "${IMPE_CONVOCATORIAS_PUBLIC_DIR:-}" ]]; then
    DST="$IMPE_CONVOCATORIAS_PUBLIC_DIR"
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
    "app/controllers/ConvocatoriaController.php"
    "app/models/ConvocatoriaModel.php"
    "app/views/convocatorias/index.php"
    "app/views/formularios/registro.php"
    "app/views/formularios/inscripcion.php"
    "public/css/convocatorias.css"
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
    php -l "$SRC/app/controllers/ConvocatoriaController.php" >/dev/null
    php -l "$SRC/app/models/ConvocatoriaModel.php" >/dev/null
    php -l "$SRC/app/views/convocatorias/index.php" >/dev/null
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
        echo "OK: la vista de Convocatorias ya está actualizada en producción."
    else
        echo "PENDIENTE: $changes archivo(s) requieren publicación."
    fi

    echo "No se modificó ningún archivo (--check)."
    exit 0
fi

BACKUP_ROOT="${IMPE_CONVOCATORIAS_BACKUP_DIR:-$HOME/respaldos-crm}"
mkdir -p "$BACKUP_ROOT"
BACKUP="$(mktemp -d "$BACKUP_ROOT/convocatorias-$(date +%Y%m%d-%H%M%S)-XXXXXX")"

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

if grep -Fq "convocatoria-month-page" "$DST/app/views/convocatorias/index.php"; then
    echo "ERROR: la vista mensual todavía aparece en producción." >&2
    exit 1
fi

grep -Fq "convocatoria-overview-types" "$DST/app/views/convocatorias/index.php"
grep -Fq "Administra las convocatorias de tu territorio" "$DST/app/views/convocatorias/index.php"
grep -Fq ">Titulación<" "$DST/app/views/convocatorias/index.php"
grep -Fq ">Bachillerato<" "$DST/app/views/convocatorias/index.php"

if grep -Fq "convocatoria-titulacion-cards" "$DST/app/views/convocatorias/index.php"; then
    echo "ERROR: la vista intermedia de subtipos todavía aparece en producción." >&2
    exit 1
fi

grep -Fq 'name="clasificacion"' "$DST/app/views/convocatorias/index.php"
grep -Fq "Todas las convocatorias" "$DST/app/views/convocatorias/index.php"
grep -Fq "Tipo de convocatoria" "$DST/app/views/convocatorias/index.php"
grep -Fq "convocatoria-month-groups" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-clasificacion" "$DST/app/views/convocatorias/index.php"
grep -Fq "convocatoria-month-group-header" "$DST/public/css/convocatorias.css"
grep -Fq "Personalizador completo de QR" "$DST/public/css/convocatorias.css"
grep -Fq "Corrección estructural del scroll interno" "$DST/public/css/convocatorias.css"
grep -Fq "Modal Nueva convocatoria más compacto" "$DST/public/css/convocatorias.css"
grep -Fq "data-convocatoria-form-share" "$DST/app/views/convocatorias/index.php"
grep -Fq "convocatoria_ref" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-aliado-id" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-estado-id" "$DST/app/views/convocatorias/index.php"
grep -Fq "modal-dialog-scrollable" "$DST/app/views/convocatorias/index.php"
grep -Fq "convocatoria-create-form" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-availability-dropdown" "$DST/app/views/convocatorias/index.php"
grep -Fq "CSS Convocatorias con cache busting" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-form-type" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-qr-modal" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-copy-qr" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-download-qr" "$DST/app/views/convocatorias/index.php"
grep -Fq "data-convocatoria-qr-image-enabled" "$DST/app/views/convocatorias/index.php"

if grep -Fq "Seleccionar otros estados" "$DST/app/views/convocatorias/index.php"; then
    echo "AVISO: el texto puede seguir existiendo en Editar convocatoria; la opción fue retirada de Nueva convocatoria."
fi

if grep -Fq "data-formulario-share" "$DST/app/views/formularios/registro.php"; then
    echo "ERROR: Registro todavía contiene generación de link/QR." >&2
    exit 1
fi

if grep -Fq "data-formulario-share" "$DST/app/views/formularios/inscripcion.php"; then
    echo "ERROR: Inscripción todavía contiene generación de link/QR." >&2
    exit 1
fi

if grep -Fq "convocatoria-list-table" "$DST/app/views/convocatorias/index.php"; then
    echo "ERROR: producción todavía contiene la tabla plana anterior." >&2
    exit 1
fi

echo
echo "OK: producción genera enlaces únicos por formulario, aliado, estado y convocatoria."
echo "Respaldo: $BACKUP"
echo "Recarga el navegador con Ctrl+F5."
