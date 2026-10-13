#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BEFORE="6e426e4612b3457fccdb7b8d90cc72549826e686"
AFTER="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
ENGINE="public/javascript/telefonia_host.js"
OBSERVER="public/javascript/telefonia_sdk_entrantes_observador.js"
SCRIPT="tools/desplegar_flujo_entrante_webRTC_analista_hostinger.sh"

node --check "$ENGINE"
node --check "$OBSERVER"
node tests/telefonia_sdk_entrantes_observador_smoke.js
bash -n "$SCRIPT"

# Protecciones estáticas del ciclo de vida: no hay SIP registro nuevo,
# no se atribuye un PBX ID ficticio y nunca se abre el widget sobre el modal.
grep -Fq "const isSdkOnlyIncoming = function () {" "$ENGINE"
grep -Fq "state.phase === 'incoming-ringing'" "$ENGINE"
grep -Fq "phase: 'in-progress'" "$ENGINE"
grep -Fq "answeredAtMs = Date.now();" "$ENGINE"
grep -Fq "if (sdkOnly) clearSdkOnlyIncoming('declined');" "$ENGINE"
grep -Fq "if (sdkOnly) clearSdkOnlyIncoming('local');" "$ENGINE"
grep -Fq "if (state.phase === 'incoming-ringing')" "$ENGINE"
grep -Fq "hooks.onIdle();" "$OBSERVER"
grep -Fq "if (!incomingInput && !hangup)" "$OBSERVER"
! grep -Fq "window.parent.IMPE_TELEPHONY_SHELL?.showProviderControls?.();" "$ENGINE"
grep -Fq "'SDK_INCOMING'" "$ENGINE"
grep -Fq "verified: false" "$ENGINE"
grep -Fq "pbxCallId: ''" "$ENGINE"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_FLOW_SOURCE="$PWD" IMPE_FLOW_BACKUPS="$TMP/backups"
export IMPE_FLOW_PUBLIC="$TMP/public_html"
mkdir -p "$IMPE_FLOW_PUBLIC"
git archive "$BEFORE" | tar -xf - -C "$IMPE_FLOW_PUBLIC"

# --check no crea ni altera archivos.
cp "$IMPE_FLOW_PUBLIC/$ENGINE" "$TMP/engine-before.js"
cp "$IMPE_FLOW_PUBLIC/$OBSERVER" "$TMP/observer-before.js"
response="$(bash "$SCRIPT" --check)"
grep -Fq 'parche compatible' <<< "$response"
cmp -s "$TMP/engine-before.js" "$IMPE_FLOW_PUBLIC/$ENGINE"
cmp -s "$TMP/observer-before.js" "$IMPE_FLOW_PUBLIC/$OBSERVER"

# --apply reemplaza únicamente 2 JS y establece permisos públicos.
bash "$SCRIPT" --apply
for file in "$ENGINE" "$OBSERVER"; do
  git show "$AFTER:$file" | cmp -s - "$IMPE_FLOW_PUBLIC/$file"
  [[ "$(stat -c %a "$IMPE_FLOW_PUBLIC/$file")" == '644' ]]
done
[[ -f "$IMPE_FLOW_PUBLIC/app/views/telefonia/host.php" ]]
repeated="$(bash "$SCRIPT" --apply)"
grep -Fq 'ya está actualizado' <<< "$repeated"

# Conflicto en la sección que se parchea: rechazar, sin mutación.
export IMPE_FLOW_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_FLOW_PUBLIC"
git archive "$BEFORE" | tar -xf - -C "$IMPE_FLOW_PUBLIC"
sed -i 's/showProviderControls/mostrarOtroControl/' "$IMPE_FLOW_PUBLIC/$ENGINE"
cp "$IMPE_FLOW_PUBLIC/$ENGINE" "$TMP/conflicting-engine.js"
if bash "$SCRIPT" --apply > "$TMP/error.log" 2>&1; then
  echo 'ERROR: el script aceptó una modificación no compatible.' >&2
  exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/error.log"
cmp -s "$TMP/conflicting-engine.js" "$IMPE_FLOW_PUBLIC/$ENGINE"
echo 'Flujo WebRTC Analista: OK (contestación, conversación, final, respaldo, idempotencia, conflicto)'
