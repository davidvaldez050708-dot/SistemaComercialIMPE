#!/usr/bin/env bash
# Simula la instalación real de Hostinger sin conexión ni datos de usuario.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/domains/fundacionrededumex.org/public_html"

mkdir -p "$SITE/public/css" "$SITE/app/views/layout" "$SITE/app/views/data_territorial"
ln -s "$ROOT" "$TMP/crm-preparacion"
touch "$SITE/index.php"

# Simula producción anterior, sin el bloque CSS nuevo ni el JS añadido.
php -r '
$s = file_get_contents($argv[1]);
$m = "/* ======================================================================\n   INFORMACIÓN TERRITORIAL · MODALES DE ACTUALIZACIÓN OFICIAL";
$i = strpos($s, $m);
if ($i === false) {exit(2);}
file_put_contents($argv[2], substr($s, 0, $i));
' "$ROOT/public/css/responsive_sistema.css" "$SITE/public/css/responsive_sistema.css"

sed "/'data_territorial_modal_oficial.js',/d" \
    "$ROOT/app/views/layout/dashboard_footer.php" \
    > "$SITE/app/views/layout/dashboard_footer.php"

cat > "$SITE/app/views/data_territorial/index.php" <<'PHP'
<?php
// Producción incluye una opción que todavía no figura en la rama main.
echo 'Perfil educativo general 15+';
PHP

ORIGINAL="$(sha256sum "$SITE/app/views/data_territorial/index.php" | cut -d ' ' -f1)"
HOME="$TMP" bash "$ROOT/tools/desplegar_modales_oficiales_hostinger.sh"
HOME="$TMP" bash "$ROOT/tools/desplegar_modales_oficiales_hostinger.sh"

grep -Fq 'Perfil educativo general 15+' "$SITE/app/views/data_territorial/index.php"
test "$ORIGINAL" = "$(sha256sum "$SITE/app/views/data_territorial/index.php" | cut -d ' ' -f1)"
test "$(grep -Fc 'INFORMACIÓN TERRITORIAL · MODALES DE ACTUALIZACIÓN OFICIAL' "$SITE/public/css/responsive_sistema.css")" -eq 1
test "$(grep -Fc "'data_territorial_modal_oficial.js'," "$SITE/app/views/layout/dashboard_footer.php")" -eq 1
cmp -s "$ROOT/public/javascript/data_territorial_modal_oficial.js" \
    "$SITE/public/javascript/data_territorial_modal_oficial.js"
echo "Despliegue simulado: OK (sin duplicados ni pérdida de fuentes de producción)"
