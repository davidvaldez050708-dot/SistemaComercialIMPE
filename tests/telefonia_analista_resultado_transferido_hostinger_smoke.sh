#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="bcaa4551db0bf0981900e6d6774398593f3e1331"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_RESULTADO_SOURCE="$PWD"
export IMPE_RESULTADO_BACKUPS="$TMP/backups"
export IMPE_RESULTADO_PUBLIC="$TMP/live"
mkdir -p "$IMPE_RESULTADO_PUBLIC/public/javascript"
touch "$IMPE_RESULTADO_PUBLIC/index.php"
node tests/telefonia_analista_resultado_transferido_smoke.js
for f in public/javascript/telefonia_analista_historial_pasivo.js public/javascript/seguimiento_entrantes_analista.js; do
  git show "$BASE:$f" > "$IMPE_RESULTADO_PUBLIC/$f"
done
echo '/* NO TOCAR MOTOR SIP */' > "$IMPE_RESULTADO_PUBLIC/public/javascript/telefonia_shell.js"
checksum="$(sha256sum "$IMPE_RESULTADO_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)"
bash -n tools/desplegar_resultado_transferida_analista_hostinger.sh
bash tools/desplegar_resultado_transferida_analista_hostinger.sh --check |
 grep -Fq 'PRODUCCIÓN INTACTA'
! grep -Fq 'IMPE_ANALYST_RESULT_SAVED' "$IMPE_RESULTADO_PUBLIC/public/javascript/telefonia_analista_historial_pasivo.js"
bash tools/desplegar_resultado_transferida_analista_hostinger.sh --apply |
 grep -Fq 'resultado de llamada del Analista publicado'
grep -Fq 'IMPE_ANALYST_RESULT_SAVED' "$IMPE_RESULTADO_PUBLIC/public/javascript/telefonia_analista_historial_pasivo.js"
[[ "$(sha256sum "$IMPE_RESULTADO_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)" == "$checksum" ]]
test -n "$(find "$TMP/backups" -type f)"
bash tools/desplegar_resultado_transferida_analista_hostinger.sh --check |
 grep -Fq 'ya publicados'
export IMPE_RESULTADO_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_RESULTADO_PUBLIC/public/javascript"
touch "$IMPE_RESULTADO_PUBLIC/index.php"
for f in public/javascript/telefonia_analista_historial_pasivo.js public/javascript/seguimiento_entrantes_analista.js; do
  git show "$BASE:$f" > "$IMPE_RESULTADO_PUBLIC/$f"
done
sed -i "s/const end = function () {/const end = function (evt) {/" \
 "$IMPE_RESULTADO_PUBLIC/public/javascript/telefonia_analista_historial_pasivo.js"
before="$(sha256sum "$IMPE_RESULTADO_PUBLIC/public/javascript/telefonia_analista_historial_pasivo.js" | cut -d' ' -f1)"
if bash tools/desplegar_resultado_transferida_analista_hostinger.sh --apply > "$TMP/error.log" 2>&1; then
  echo 'ERROR: el script aceptó una versión incompatible' >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/error.log"
[[ "$(sha256sum "$IMPE_RESULTADO_PUBLIC/public/javascript/telefonia_analista_historial_pasivo.js" | cut -d' ' -f1)" == "$before" ]]
echo 'Resultado transferido: OK (cronómetro, registro, no actualización SIP y rollback).'
