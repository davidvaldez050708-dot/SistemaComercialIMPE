#!/usr/bin/env bash
# Ensayo sin producción, sin base de datos ni telefonía.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
API="prueba_telefonia/api/llamadas_seguimiento.php"
JS="public/javascript/seguimiento_llamadas_expediente.js"
mkdir -p "$TMP/site/$(dirname "$API")" "$TMP/site/$(dirname "$JS")" "$TMP/backups"
git show "35bd6f6269426adb0c09530ffa67d9b281214924:$API" > "$TMP/site/$API"
git show "4ce7e5d74f30bc6a4ed108cbeaebaf0d8fc36e0e:$JS" > "$TMP/site/$JS"
export IMPE_HISTORIAL_SOURCE="$ROOT"
export IMPE_HISTORIAL_PUBLIC="$TMP/site"
export IMPE_HISTORIAL_BACKUPS="$TMP/backups"
bash tools/desplegar_historial_llamadas_expediente_hostinger.sh --check | grep -q "PRODUCCIÓN INTACTA"
bash tools/desplegar_historial_llamadas_expediente_hostinger.sh --apply | grep -q "historial telefónico actualizado"
git show "00bb0597d6932b255c895f90dc69dab83acc2710:$API" | cmp - "$TMP/site/$API"
git show "2773dcf6c827a506bc6ab8046592ad0b3bf44c29:$JS" | cmp - "$TMP/site/$JS"
bash tools/desplegar_historial_llamadas_expediente_hostinger.sh --check | grep -q "ya corregido"
[[ -n "$(find "$TMP/backups" -type f)" ]]
echo "Historial de llamadas Hostinger: OK (preflight, apply, idempotencia, backup)"
