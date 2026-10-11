#!/usr/bin/env bash
# Aplicación incremental en Hostinger: no sobrescribe personalizaciones
# locales de la vista PHP ni las opciones adicionales en producción.
set -euo pipefail

SRC="$HOME/crm-preparacion"
DST="$HOME/domains/fundacionrededumex.org/public_html"
CSS="public/css/responsive_sistema.css"
JS="public/javascript/data_territorial_modal_oficial.js"
FOOTER="app/views/layout/dashboard_footer.php"
mkdir -p "$HOME/respaldos-crm"
BACKUP="$(mktemp -d "$HOME/respaldos-crm/modales-oficiales-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT

for file in "$SRC/$CSS" "$SRC/$JS" "$SRC/$FOOTER" \
            "$DST/$CSS" "$DST/$FOOTER" "$DST/index.php"; do
    if [ ! -f "$file" ]; then
        echo "ERROR: archivo requerido no encontrado: $file" >&2
        exit 1
    fi
done
if [ -f "$DST/$JS" ] && ! cmp -s "$SRC/$JS" "$DST/$JS"; then
    echo "ERROR: Ya existe un JavaScript personalizado distinto en producción. Revisarlo antes de sustituirlo." >&2
    exit 1
fi

echo "1/4 Preparando parche CSS sin perder ajustes locales..."
php -r '
$actual = file_get_contents($argv[1]);
$fuente = file_get_contents($argv[2]);
$marca = "/* ======================================================================\n   INFORMACIÓN TERRITORIAL · MODALES DE ACTUALIZACIÓN OFICIAL";
$indice = strpos($fuente, $marca);
if ($indice === false) {fwrite(STDERR, "No se encontró el bloque CSS versionado.\n"); exit(2);}
$bloque = substr($fuente, $indice);
if (strpos($actual, $marca) !== false && strpos($actual, $bloque) === false) {
    fwrite(STDERR, "El CSS de producción ya tiene otra versión de esta corrección.\n");
    exit(2);
}
$nuevo = strpos($actual, $marca) === false
    ? rtrim($actual) . "\n\n" . $bloque : $actual;
if (file_put_contents($argv[3], $nuevo) === false) {exit(2);}
' "$DST/$CSS" "$SRC/$CSS" "$STAGING/responsive_sistema.css"

echo "2/4 Integrando script sin sustituir el footer de producción..."
php -r '
$original = file_get_contents($argv[1]);
$ancla = "\x27educacion_objetivo.js\x27,";
$nombre = "\x27data_territorial_modal_oficial.js\x27,";
if (strpos($original, $nombre) === false) {
    if (substr_count($original, $ancla) !== 1) {
        fwrite(STDERR, "No se localizó un único punto de integración en el footer.\n");
        exit(2);
    }
    $original = str_replace($ancla, $ancla . "\n            " . $nombre, $original);
}
if (file_put_contents($argv[2], $original) === false) {exit(2);}
' "$DST/$FOOTER" "$STAGING/dashboard_footer.php"

php -l "$STAGING/dashboard_footer.php"
if command -v node >/dev/null 2>&1; then
    node --check "$SRC/$JS"
    (cd "$SRC" && node tests/territorial_modales_oficiales_smoke.js)
fi

echo "3/4 Respaldando y aplicando..."
mkdir -p "$BACKUP/$(dirname "$CSS")" "$BACKUP/$(dirname "$FOOTER")"
cp -p "$DST/$CSS" "$BACKUP/$CSS"
cp -p "$DST/$FOOTER" "$BACKUP/$FOOTER"
if [ -f "$DST/$JS" ]; then
    mkdir -p "$BACKUP/$(dirname "$JS")"
    cp -p "$DST/$JS" "$BACKUP/$JS"
fi
mkdir -p "$DST/$(dirname "$JS")"
cp "$STAGING/responsive_sistema.css" "$DST/$CSS"
cp "$STAGING/dashboard_footer.php" "$DST/$FOOTER"
cp "$SRC/$JS" "$DST/$JS"

echo "4/4 Validando archivos publicados..."
php -l "$DST/$FOOTER"
grep -Fq "data_territorial_modal_oficial.js" "$DST/$FOOTER"
grep -Fq "INFORMACIÓN TERRITORIAL · MODALES DE ACTUALIZACIÓN OFICIAL" "$DST/$CSS"
echo "OK: modales actualizados sin reemplazar las vistas o controladores de producción."
echo "Respaldo: $BACKUP"
