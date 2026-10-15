#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="748dba48f095abe0a8fc84b1de178208a751c36b"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_DTMF_SOURCE="$PWD"
export IMPE_DTMF_BACKUPS="$TMP/backups"
export IMPE_DTMF_PUBLIC="$TMP/live"
mkdir -p "$IMPE_DTMF_PUBLIC/app/views/telefonia" "$IMPE_DTMF_PUBLIC/public/javascript"
touch "$IMPE_DTMF_PUBLIC/index.php"
for file in app/views/telefonia/host.php public/javascript/telefonia_host.js public/javascript/telefonia_enrutamiento.js; do
  git show "$BASE:$file" > "$IMPE_DTMF_PUBLIC/$file"
done
echo '/* shell personalizado intocable */' > "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_shell.js"
sha_shell="$(sha256sum "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)"
node tests/telefonia_webrtc_dtmf_smoke.js
bash -n tools/desplegar_transferencia_dtmf_hostinger.sh
bash tools/desplegar_transferencia_dtmf_hostinger.sh --check | grep -Fq 'PRODUCCIÓN INTACTA'
! test -e "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_webrtc_dtmf.js"
bash tools/desplegar_transferencia_dtmf_hostinger.sh --apply | grep -Fq 'transferencia DTMF WebRTC publicada'
test -s "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_webrtc_dtmf.js"
grep -Fq 'dtmf.send(tones)' "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_host.js"
! grep -Fq 'Abrir teclado Zadarma' "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_enrutamiento.js"
[[ "$(sha256sum "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)" == "$sha_shell" ]]
test -n "$(find "$TMP/backups" -type f)"
bash tools/desplegar_transferencia_dtmf_hostinger.sh --check | grep -Fq 'ya publicada'
sed -i 's/<title>Telefonía<\/title>/<title>Telefonía personalizada<\/title>/' "$IMPE_DTMF_PUBLIC/app/views/telefonia/host.php"
bash tools/desplegar_transferencia_dtmf_hostinger.sh --check | grep -Fq 'ya publicada'

export IMPE_DTMF_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_DTMF_PUBLIC/app/views/telefonia" "$IMPE_DTMF_PUBLIC/public/javascript"
touch "$IMPE_DTMF_PUBLIC/index.php"
for file in app/views/telefonia/host.php public/javascript/telefonia_host.js public/javascript/telefonia_enrutamiento.js; do
  git show "$BASE:$file" > "$IMPE_DTMF_PUBLIC/$file"
done
sed -i 's/const sendTransferDtmf = async function (/const sendTransferDtmf = undefined; const disabled = function (/' "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_host.js"
sha="$(sha256sum "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)"
if bash tools/desplegar_transferencia_dtmf_hostinger.sh --apply > "$TMP/conflict.log" 2>&1; then
  echo 'ERROR: fallo de guardas de producción' >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.log"
[[ "$(sha256sum "$IMPE_DTMF_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)" == "$sha" ]]
echo 'Transferencia DTMF: OK (WebRTC, modal intacto, respaldo y rechazo seguro).'
