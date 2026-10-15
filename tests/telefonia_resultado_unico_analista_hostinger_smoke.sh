#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_ONE_RESULT_SOURCE="$PWD"
export IMPE_ONE_RESULT_BACKUPS="$TMP/backups"
export IMPE_ONE_RESULT_PUBLIC="$TMP/live"
SRC="public/javascript/telefonia_analista_historial_pasivo.js"
SHELL="public/javascript/telefonia_shell.js"
mkdir -p "$IMPE_ONE_RESULT_PUBLIC/public/javascript"
touch "$IMPE_ONE_RESULT_PUBLIC/index.php"
git show "23a42289d605da57e6cf178f4b0e666b73ac45ed:$SRC" > "$IMPE_ONE_RESULT_PUBLIC/$SRC"
echo '/* shell SIP intocable */' > "$IMPE_ONE_RESULT_PUBLIC/$SHELL"
printf '\n// HOSTINGER_PERSONALIZADO_CONSERVAR\n' >> "$IMPE_ONE_RESULT_PUBLIC/$SRC"
before="$(sha256sum "$IMPE_ONE_RESULT_PUBLIC/$SRC" | cut -d' ' -f1)"
shell="$(sha256sum "$IMPE_ONE_RESULT_PUBLIC/$SHELL" | cut -d' ' -f1)"
node tests/telefonia_analista_resultado_transferido_smoke.js
bash -n tools/desplegar_resultado_unico_analista_hostinger.sh
bash tools/desplegar_resultado_unico_analista_hostinger.sh --check |
 grep -Fq 'OK: resultado único del Analista compatible. PRODUCCIÓN INTACTA.'
[[ "$(sha256sum "$IMPE_ONE_RESULT_PUBLIC/$SRC" | cut -d' ' -f1)" == "$before" ]]
bash tools/desplegar_resultado_unico_analista_hostinger.sh --apply |
 grep -Fq 'OK: resultado único en modal publicado.'
grep -Fq 'HOSTINGER_PERSONALIZADO_CONSERVAR' "$IMPE_ONE_RESULT_PUBLIC/$SRC"
grep -Fq 'body.impe-sdk-result-in-modal [data-analyst-call-result]' "$IMPE_ONE_RESULT_PUBLIC/$SRC"
grep -Fq "'impe-sdk-result-in-modal', Boolean(active || finished)" "$IMPE_ONE_RESULT_PUBLIC/$SRC"
[[ "$(sha256sum "$IMPE_ONE_RESULT_PUBLIC/$SHELL" | cut -d' ' -f1)" == "$shell" ]]
test -n "$(find "$TMP/backups" -type f)"
bash tools/desplegar_resultado_unico_analista_hostinger.sh --check |
 grep -Fq 'ya actualizado'
export IMPE_ONE_RESULT_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_ONE_RESULT_PUBLIC/public/javascript"
touch "$IMPE_ONE_RESULT_PUBLIC/index.php"
git show "23a42289d605da57e6cf178f4b0e666b73ac45ed:$SRC" > "$IMPE_ONE_RESULT_PUBLIC/$SRC"
sed -i 's/const draw = function () {/const draw = () => {/' "$IMPE_ONE_RESULT_PUBLIC/$SRC"
checksum="$(sha256sum "$IMPE_ONE_RESULT_PUBLIC/$SRC" | cut -d' ' -f1)"
if bash tools/desplegar_resultado_unico_analista_hostinger.sh --apply > "$TMP/conflict.log" 2>&1; then
 echo 'ERROR: aplicó un parche sobre lógica desconocida' >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.log"
[[ "$(sha256sum "$IMPE_ONE_RESULT_PUBLIC/$SRC" | cut -d' ' -f1)" == "$checksum" ]]
echo 'Modal único del Analista: OK (CSS, UI, idempotencia, personalización, respaldo y rechazo seguro).'
