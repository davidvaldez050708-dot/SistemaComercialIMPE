#!/usr/bin/env bash
# Ejecuta dos publicaciones simuladas sin acceso a Hostinger ni DENUE.
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
DESTINO="$TMP/domains/fundacionrededumex.org/public_html"
mkdir -p "$DESTINO/app/views/seguimiento_vinculacion" \
         "$DESTINO/app/views/layout" "$DESTINO/public/css" "$DESTINO/public/javascript"
ln -s "$RAIZ" "$TMP/crm-preparacion"
touch "$DESTINO/index.php"

git -C "$RAIZ" cat-file blob 1b3e28d02504f5411d4be0be359d64862a50da0e \
    > "$DESTINO/app/views/seguimiento_vinculacion/estado.php"
git -C "$RAIZ" cat-file blob ff0d85dd788c8c88926798a3dbd992c67d38f0a2 \
    > "$DESTINO/app/views/layout/dashboard_footer.php"
git -C "$RAIZ" cat-file blob 54ace51e6c58c8511ee63ed4826baae02aa409be \
    > "$DESTINO/public/css/seguimiento_filtros_layout.css"

# Ajustes locales independientes que deben permanecer intactos.
sed -i '1i<?php /* AJUSTE-LOCAL-PRODUCCION */ ?>' \
    "$DESTINO/app/views/seguimiento_vinculacion/estado.php"
sed -i '1i<?php /* AJUSTE-LOCAL-PRODUCCION */ ?>' \
    "$DESTINO/app/views/layout/dashboard_footer.php"
sed -i '1i/* AJUSTE-LOCAL-PRODUCCION */' \
    "$DESTINO/public/css/seguimiento_filtros_layout.css"

HOME="$TMP" bash "$RAIZ/tools/desplegar_paginacion_candidatos_hostinger.sh"
HOME="$TMP" bash "$RAIZ/tools/desplegar_paginacion_candidatos_hostinger.sh"

grep -Fq 'AJUSTE-LOCAL-PRODUCCION' \
    "$DESTINO/app/views/seguimiento_vinculacion/estado.php"
grep -Fq 'AJUSTE-LOCAL-PRODUCCION' \
    "$DESTINO/app/views/layout/dashboard_footer.php"
grep -Fq 'AJUSTE-LOCAL-PRODUCCION' \
    "$DESTINO/public/css/seguimiento_filtros_layout.css"

[[ "$(grep -Fc 'const mostrarPaginaLocal = function' "$DESTINO/app/views/seguimiento_vinculacion/estado.php")" -eq 1 ]]
[[ "$(grep -Fc "'seguimiento_candidatos_paginacion.js'" "$DESTINO/app/views/layout/dashboard_footer.php")" -eq 1 ]]
[[ "$(grep -Fc 'Buscar candidato · transición de paginación' "$DESTINO/public/css/seguimiento_filtros_layout.css")" -eq 1 ]]

echo "Despliegue simulado: OK (doble ejecución y ajustes locales conservados)"
