#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="7fe4a57078380cac76af428e88e45a17e1960ae5"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_ANSWER_SOURCE="$PWD"
export IMPE_ANSWER_BACKUPS="$TMP/backups"
export IMPE_ANSWER_PUBLIC="$TMP/live"
mkdir -p "$IMPE_ANSWER_PUBLIC/public/javascript"
touch "$IMPE_ANSWER_PUBLIC/index.php"
for f in public/javascript/telefonia_host.js public/javascript/telefonia_sdk_entrantes_observador.js; do
  git show "$BASE:$f" > "$IMPE_ANSWER_PUBLIC/$f"
done
echo '// Shell de producción intacto' > "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_shell.js"
sha="$(sha256sum "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)"
node tests/telefonia_sdk_entrantes_observador_smoke.js
node tests/telefonia_entrantes_bridge_smoke.js
bash -n tools/desplegar_respuesta_transferida_hostinger.sh
bash tools/desplegar_respuesta_transferida_hostinger.sh --check | grep -Fq 'PRODUCCIÓN INTACTA'
bash tools/desplegar_respuesta_transferida_hostinger.sh --apply | grep -Fq 'aceptación de transferencia publicada'
grep -Fq "markAnswerRequested" "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_sdk_entrantes_observador.js"
[[ "$(sha256sum "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)" == "$sha" ]]
test -n "$(find "$TMP/backups" -type f)"
bash tools/desplegar_respuesta_transferida_hostinger.sh --check | grep -Fq 'ya actualizada'
# Hostinger contiene agregados propios incluso dentro de los hunks de Git.
# El fusionador debe conservarlos y transformar SOLO la respuesta SDK.
export IMPE_ANSWER_PUBLIC="$TMP/custom"
mkdir -p "$IMPE_ANSWER_PUBLIC/public/javascript"
touch "$IMPE_ANSWER_PUBLIC/index.php"
for f in public/javascript/telefonia_host.js public/javascript/telefonia_sdk_entrantes_observador.js; do
  git show "$BASE:$f" > "$IMPE_ANSWER_PUBLIC/$f"
done
python3 - "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
a="                onStopped: function () {"
assert s.count(a)==1
s=s.replace(a,"                // HOSTINGER_PERSONALIZACION: no modificar SIP\n"+a,1)
a="            actualizarEstadoTrasAccion();\n            return true;\n        },\n        decline: function () {"
assert s.count(a)==1
s=s.replace(a,"            // HOSTINGER_PERSONALIZACION: conservar monitoreo\n"+a,1)
p.write_text(s)
PY
custom_checksum="$(sha256sum "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)"
bash tools/desplegar_respuesta_transferida_hostinger.sh --check |
 grep -Fq 'PRODUCCIÓN INTACTA'
[[ "$(sha256sum "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)" == "$custom_checksum" ]]
bash tools/desplegar_respuesta_transferida_hostinger.sh --apply |
 grep -Fq 'aceptación de transferencia publicada'
grep -Fq 'HOSTINGER_PERSONALIZACION: no modificar SIP' "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js"
grep -Fq 'HOSTINGER_PERSONALIZACION: conservar monitoreo' "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js"
grep -Fq "onConnected: function ()" "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js"
grep -Fq "markAnswerRequested" "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js"
bash tools/desplegar_respuesta_transferida_hostinger.sh --check |
 grep -Fq 'ya actualizada'

# Una lógica nueva no prevista en la rama de Contestar se rechaza: no
# publicar una reversión que elimine personalizaciones funcionales.
export IMPE_ANSWER_PUBLIC="$TMP/unsafe"
mkdir -p "$IMPE_ANSWER_PUBLIC/public/javascript"
touch "$IMPE_ANSWER_PUBLIC/index.php"
for f in public/javascript/telefonia_host.js public/javascript/telefonia_sdk_entrantes_observador.js; do
  git show "$BASE:$f" > "$IMPE_ANSWER_PUBLIC/$f"
done
python3 - "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
a="                answeredAtMs = Date.now();\n                reportSdkEvent('answered', state);"
assert s.count(a)==1
s=s.replace(a,"                console.info('CODIGO_PERSONALIZADO_CRITICO');\n"+a,1)
p.write_text(s)
PY
unsafe_checksum="$(sha256sum "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)"
if bash tools/desplegar_respuesta_transferida_hostinger.sh --apply > "$TMP/unsafe.log" 2>&1; then
  echo 'ERROR: se borró lógica adicional de Contestar'; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/unsafe.log"
[[ "$(sha256sum "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)" == "$unsafe_checksum" ]]

export IMPE_ANSWER_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_ANSWER_PUBLIC/public/javascript"
touch "$IMPE_ANSWER_PUBLIC/index.php"
for f in public/javascript/telefonia_host.js public/javascript/telefonia_sdk_entrantes_observador.js; do
  git show "$BASE:$f" > "$IMPE_ANSWER_PUBLIC/$f"
done
sed -i "s/onStopped: function () {/onStopped: function (event) {/" \
 "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js"
checksum="$(sha256sum "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)"
if bash tools/desplegar_respuesta_transferida_hostinger.sh --apply > "$TMP/error.log" 2>&1; then
  echo "ERROR: instalador no detectó conflicto"; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/error.log"
[[ "$(sha256sum "$IMPE_ANSWER_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)" == "$checksum" ]]
echo 'Recepción SDK: OK (contestar, espera SIP, idempotencia y rollback).'
