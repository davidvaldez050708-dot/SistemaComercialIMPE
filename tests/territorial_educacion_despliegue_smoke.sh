#!/usr/bin/env bash
# Simula actualización del JS/CSS guardando personalizaciones de producción.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/domains/fundacionrededumex.org/public_html"
mkdir -p "$SITE/public/javascript" "$SITE/public/css"
ln -s "$ROOT" "$TMP/crm-preparacion"
touch "$SITE/index.php"

git -C "$ROOT" show '2408192815e551ab3320c42ed45bcee3cbb81482^:public/javascript/educacion_objetivo.js' \
    > "$SITE/public/javascript/educacion_objetivo.js"
git -C "$ROOT" show 'c1515e8bc2042b380c98ff0dfdd163aa0ea92326^:public/css/educacion_objetivo.css' \
    > "$SITE/public/css/educacion_objetivo.css"

# No se puede perder una personalización ajena al cambio.
printf '\n/* AJUSTE-LOCAL-SERVIDOR */\n' >> "$SITE/public/css/educacion_objetivo.css"
printf '\n// AJUSTE-LOCAL-SERVIDOR\n' >> "$SITE/public/javascript/educacion_objetivo.js"

HOME="$TMP" bash "$ROOT/tools/desplegar_respuesta_educacion_hostinger.sh"
HOME="$TMP" bash "$ROOT/tools/desplegar_respuesta_educacion_hostinger.sh"

grep -Fq 'AJUSTE-LOCAL-SERVIDOR' "$SITE/public/css/educacion_objetivo.css"
grep -Fq 'AJUSTE-LOCAL-SERVIDOR' "$SITE/public/javascript/educacion_objetivo.js"
test "$(grep -Fc 'const consultarEducacionLocal =' "$SITE/public/javascript/educacion_objetivo.js")" -eq 1
test "$(grep -Fc '.data-education-target-error .data-education-retry' "$SITE/public/css/educacion_objetivo.css")" -eq 1
echo 'Despliegue educativo simulado: OK (personalizaciones preservadas)'
