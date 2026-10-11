#!/usr/bin/env bash
# Despliegue seguro del control de respuestas educativas en Hostinger.
# No sustituye las vistas PHP ni la sincronización 15+ de producción.
set -euo pipefail
umask 077

SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
JS="public/javascript/educacion_objetivo.js"
CSS="public/css/educacion_objetivo.css"
BASE_JS="2408192815e551ab3320c42ed45bcee3cbb81482^"
BASE_CSS="c1515e8bc2042b380c98ff0dfdd163aa0ea92326^"

for archivo in "$JS" "$CSS"; do
    test -f "$SRC/$archivo" || { echo "Falta el origen: $archivo" >&2; exit 1; }
    test -f "$DST/$archivo" || { echo "Falta el destino: $archivo" >&2; exit 1; }
done
test -f "$DST/index.php" || { echo "No se localizó el CRM en producción." >&2; exit 1; }

TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT

reconciliar() {
    local archivo="$1" base="$2" destino="$3"
    git -C "$SRC" show "$base:$archivo" > "$TEMP/base-$destino" || {
        echo "No existe la versión base en Git: $archivo" >&2
        exit 1
    }
    if ! git merge-file -p \
        "$DST/$archivo" "$TEMP/base-$destino" "$SRC/$archivo" \
        > "$TEMP/merge-$destino"; then
        echo "CONFLICTO: producción tiene otros cambios en $archivo." >&2
        echo "Se canceló la publicación. No se modificó ningún archivo." >&2
        exit 1
    fi
}

echo "1/4 Comparando y conservando ajustes de producción..."
reconciliar "$JS" "$BASE_JS" "educacion.js"
reconciliar "$CSS" "$BASE_CSS" "educacion.css"

if command -v node >/dev/null 2>&1; then
    node --check "$TEMP/merge-educacion.js"
fi
grep -Fq "consultarEducacionLocal" "$TEMP/merge-educacion.js"
grep -Fq "data-education-retry" "$TEMP/merge-educacion.css"

echo "2/4 Respaldando archivos existentes..."
mkdir -p "$HOME/respaldos-crm"
BACKUP="$(mktemp -d "$HOME/respaldos-crm/educacion-respuesta-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
mkdir -p "$BACKUP/public/javascript" "$BACKUP/public/css"
cp -p "$DST/$JS" "$BACKUP/$JS"
cp -p "$DST/$CSS" "$BACKUP/$CSS"

echo "3/4 Publicando corrección..."
cp "$TEMP/merge-educacion.js" "$DST/$JS"
cp "$TEMP/merge-educacion.css" "$DST/$CSS"

echo "4/4 Verificando publicación..."
grep -Fq "consultarEducacionLocal" "$DST/$JS"
grep -Fq "data-education-retry" "$DST/$CSS"
echo "OK: manejo de respuestas educativas actualizado."
echo "Las fuentes, vistas PHP y la base de datos no se modificaron."
echo "Respaldo: $BACKUP"
