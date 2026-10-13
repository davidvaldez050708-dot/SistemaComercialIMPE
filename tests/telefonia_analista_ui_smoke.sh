#!/usr/bin/env bash
# Verifica que el diseño de llamadas sólo toque las vistas nuevas y su CSS.
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="6ab81c43dfc814ebcf58e3c4b4cf1c46d5713a3c"
TARGET="d6e08d520ffd5c167b4fde176202f3892151dd34"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/sitio" "$TMP/respaldos"
git archive "$BASE" | tar -x -C "$TMP/sitio"
export IMPE_UI_ENTRANTES_SOURCE="$PWD"
export IMPE_UI_ENTRANTES_PUBLIC="$TMP/sitio"
export IMPE_UI_ENTRANTES_BACKUPS="$TMP/respaldos"

# Primera simulación: archivo CSS ausente en el sitio, como el síntoma reportado.
rm -f "$TMP/sitio/public/css/seguimiento_entrantes_analista.css"
cp "$TMP/sitio/public/css/telefonia_shell.css" "$TMP/telefonia_inicial.css"
bash tools/desplegar_ui_entrantes_analista_hostinger.sh --check
[[ ! -f "$TMP/sitio/public/css/seguimiento_entrantes_analista.css" ]]
bash tools/desplegar_ui_entrantes_analista_hostinger.sh --apply
git show "$TARGET:public/css/seguimiento_entrantes_analista.css" |
    cmp -s - "$TMP/sitio/public/css/seguimiento_entrantes_analista.css"
cmp -s "$TMP/telefonia_inicial.css" "$TMP/sitio/public/css/telefonia_shell.css"
bash tools/desplegar_ui_entrantes_analista_hostinger.sh --check

# El botón está centrado y el CSS se carga en las tres rutas de Seguimiento.
grep -Fq 'linkage-incoming-entry' "$TMP/sitio/app/views/seguimiento_vinculacion/index.php"
grep -Fq 'linkage-incoming-entry' "$TMP/sitio/app/views/seguimiento_vinculacion/estado.php"
grep -Fq "'index', 'estado', 'llamadasentrantes'" "$TMP/sitio/app/views/layout/dashboard_head.php"
grep -Fq 'linkage-incoming-empty-panel' "$TMP/sitio/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
grep -Fq 'No es posible consultar tus llamadas' "$TMP/sitio/app/views/seguimiento_vinculacion/llamadas_entrantes.php"

# El script rechaza versiones inesperadas sin sobreescribirlas.
sed -i 's/Volver a Seguimiento/REGRESAR A SEGUIMIENTO/' \
    "$TMP/sitio/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
if bash tools/desplegar_ui_entrantes_analista_hostinger.sh --check >"$TMP/conflicto.log" 2>&1; then
    echo "FALLO: no detectó la alteración de producción." >&2
    exit 1
fi
echo "UI entrantes analista: OK (CSS faltante, alineación, publicación idempotente, conservación y conflicto)"
