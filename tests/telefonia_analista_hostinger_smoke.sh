#!/usr/bin/env bash
# Simula Hostinger con una copia del commit anterior a la implementación.
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="cc7eba5c9f5819cb0b94c8f85c83e0f08ee105f3"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/sitio" "$TMP/respaldos"
git archive "$BASE" | tar -x -C "$TMP/sitio"
export IMPE_ENTRANTES_SOURCE="$PWD"
export IMPE_ENTRANTES_PUBLIC="$TMP/sitio"
export IMPE_ENTRANTES_BACKUPS="$TMP/respaldos"
bash tools/desplegar_entrantes_analista_hostinger.sh --check
[[ ! -f "$TMP/sitio/app/services/TelefoniaEntrantesAnalistaService.php" ]]
bash tools/desplegar_entrantes_analista_hostinger.sh --apply
[[ -f "$TMP/sitio/app/services/TelefoniaEntrantesAnalistaService.php" ]]
[[ -f "$TMP/sitio/app/views/seguimiento_vinculacion/llamadas_entrantes.php" ]]
[[ -f "$TMP/sitio/public/javascript/seguimiento_entrantes_analista.js" ]]
# El instalador histórico NO modifica el CSS del shell. Comparar con
# la versión base de su fixture, no con CSS moderno de navegación.
git show "$BASE:public/css/telefonia_shell.css" | cmp -s - "$TMP/sitio/public/css/telefonia_shell.css"
bash tools/desplegar_entrantes_analista_hostinger.sh --check
[[ -n "$(find "$TMP/respaldos" -name 'telefonia_shell.css' -o -name 'SeguimientoVinculacionModel.php')" ]]
echo "Despliegue de entrantes Analistas: OK (verificar, publicar, repetir, conservar telefonía)"
