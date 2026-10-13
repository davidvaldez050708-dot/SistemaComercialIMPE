#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="1d130d13b7c3a12d7affe5d4d5d6a2fcc5a7d7de"
TARGET="6e426e4612b3457fccdb7b8d90cc72549826e686"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_RING_SOURCE="$PWD" IMPE_RING_BACKUPS="$TMP/backups"
export IMPE_RING_PUBLIC="$TMP/public_html"
mkdir -p "$IMPE_RING_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_RING_PUBLIC"
node tests/telefonia_sdk_entrantes_observador_smoke.js
bash tools/desplegar_timbrado_webRTC_analista_hostinger.sh --check | grep -Fq 'parche compatible'
[[ ! -e "$IMPE_RING_PUBLIC/public/javascript/telefonia_sdk_entrantes_observador.js" ]]
bash tools/desplegar_timbrado_webRTC_analista_hostinger.sh --apply
for p in app/views/telefonia/host.php public/javascript/telefonia_host.js \
  public/javascript/telefonia_sdk_entrantes_observador.js; do
  git show "$TARGET:$p" | cmp -s - "$IMPE_RING_PUBLIC/$p"
done
[[ "$(stat -c %a "$IMPE_RING_PUBLIC/public/javascript/telefonia_host.js")" == 644 ]]
[[ "$(stat -c %a "$IMPE_RING_PUBLIC/public/javascript/telefonia_sdk_entrantes_observador.js")" == 644 ]]
bash tools/desplegar_timbrado_webRTC_analista_hostinger.sh --check | grep -Fq 'ya publicado'
bash tools/desplegar_timbrado_webRTC_analista_hostinger.sh --apply | grep -Fq 'ya publicado'

# Conflicto simulado: nunca sobreescribir modificaciones del cliente.
export IMPE_RING_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_RING_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_RING_PUBLIC"
sed -i "s/const permiteEntrantes =/const permiteEntrantesCustom =/" \
  "$IMPE_RING_PUBLIC/public/javascript/telefonia_host.js"
cp "$IMPE_RING_PUBLIC/public/javascript/telefonia_host.js" "$TMP/unmodified.js"
if bash tools/desplegar_timbrado_webRTC_analista_hostinger.sh --apply > "$TMP/err.log" 2>&1; then
  echo 'ERROR: el despliegue ignoró modificaciones incompatibles.' >&2
  exit 1
fi
grep -q CONFLICTO "$TMP/err.log"
cmp -s "$TMP/unmodified.js" "$IMPE_RING_PUBLIC/public/javascript/telefonia_host.js"
[[ ! -e "$IMPE_RING_PUBLIC/public/javascript/telefonia_sdk_entrantes_observador.js" ]]

# Protección de rol y de evidencia verificable.
grep -Fq 'if ($analistaHostSdk):' app/views/telefonia/host.php
grep -Fq 'analystSdkIncoming' app/views/telefonia/host.php
grep -Fq 'type: '\''SDK_INCOMING'\''' public/javascript/telefonia_host.js
grep -Fq "pbxCallId: ''" public/javascript/telefonia_host.js
grep -Fq "state.context?.type !== 'SDK_INCOMING'" public/javascript/telefonia_host.js

echo 'Timbrado SIP Analista: OK (entrada real, sin Marketing, permisos públicos y conflictos)'
