#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BEFORE="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
AFTER="f2e803029fdefb0bd272db059bed469f134c93a2"
DEPLOY="tools/desplegar_historial_entrantes_webRTC_analista_hostinger.sh"
files=(
  app/controllers/SeguimientoVinculacionController.php
  app/services/TelefoniaEntrantesAnalistaService.php
  app/services/TelefoniaAnalistaWebrtcService.php
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  app/views/telefonia/host.php
  app/views/telefonia/shell.php
  public/javascript/telefonia_host.js
  public/javascript/telefonia_shell.js
  public/javascript/telefonia_sdk_entrantes_observador.js
  public/javascript/seguimiento_entrantes_analista.js
)
for file in "${files[@]}"; do
  case "$file" in
    *.php) php -l "$file" >/dev/null;;
    *.js) node --check "$file";;
  esac
done
node tests/telefonia_sdk_entrantes_observador_smoke.js
bash -n "$DEPLOY"

# Servidor: sesiones por usuario/extensión, CSRF, no fingir PBX ni verificación.
grep -Fq "UNIQUE KEY uk_analista_webrtc_sesion (usuario_id, session_id)" app/services/TelefoniaAnalistaWebrtcService.php
grep -Fq "fecha_atendida = COALESCE(fecha_atendida, NOW())" app/services/TelefoniaAnalistaWebrtcService.php
grep -Fq 'No se confirmó el guardado de la sesión WebRTC.' app/services/TelefoniaAnalistaWebrtcService.php
grep -Fq "AND estado = 'ringing'" app/services/TelefoniaAnalistaWebrtcService.php
grep -Fq "eventoEntranteWebrtc" app/controllers/SeguimientoVinculacionController.php
grep -Fq 'hash_equals($expected, $received)' app/controllers/SeguimientoVinculacionController.php
grep -Fq 'obtenerFinalizada($usuarioId, $id)' app/services/TelefoniaEntrantesAnalistaService.php
grep -Fq "confirmacion_manual_sdk" app/services/TelefoniaEntrantesAnalistaService.php
grep -Fq "Las llamadas WebRTC sin evento PBX no acreditan verificaciones efectivas." app/services/TelefoniaEntrantesAnalistaService.php
grep -Fq "source:'SDK_WEBRTC'" public/javascript/telefonia_shell.js
grep -Fq "journalStatus !== 'saved'" public/javascript/telefonia_shell.js
grep -Fq "reportSdkEvent('answered', state)" public/javascript/telefonia_host.js
grep -Fq "phase: 'incoming-finished'" public/javascript/telefonia_host.js
grep -Fq "reportSdkEvent('ringing', call)" public/javascript/telefonia_host.js
grep -Fq "data-incoming-register-finished" app/views/telefonia/shell.php
grep -Fq "data-incoming-retry-save" app/views/telefonia/shell.php

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
export IMPE_HISTORY_SOURCE="$PWD" IMPE_HISTORY_PUBLIC="$tmp/public_html"
export IMPE_HISTORY_BACKUPS="$tmp/backups"
mkdir -p "$IMPE_HISTORY_PUBLIC"
git archive "$BEFORE" | tar -xf - -C "$IMPE_HISTORY_PUBLIC"
precheck="$(bash "$DEPLOY" --check)"
grep -Fq 'parche compatible' <<< "$precheck"
[[ ! -e "$IMPE_HISTORY_PUBLIC/app/services/TelefoniaAnalistaWebrtcService.php" ]]
bash "$DEPLOY" --apply
for f in "${files[@]}"; do
  git show "$AFTER:$f" | cmp -s - "$IMPE_HISTORY_PUBLIC/$f"
done
for f in public/javascript/telefonia_host.js public/javascript/telefonia_shell.js \
  public/javascript/telefonia_sdk_entrantes_observador.js public/javascript/seguimiento_entrantes_analista.js; do
  [[ "$(stat -c %a "$IMPE_HISTORY_PUBLIC/$f")" == '644' ]]
done
[[ "$(stat -c %a "$IMPE_HISTORY_PUBLIC/app/services/TelefoniaAnalistaWebrtcService.php")" == '644' ]]
git show "$BEFORE:public/javascript/telefonia_persistente.js" |
  cmp -s - "$IMPE_HISTORY_PUBLIC/public/javascript/telefonia_persistente.js"
again="$(bash "$DEPLOY" --apply)"
grep -Fq 'ya está publicada' <<< "$again"

# Personalización conflictiva en un archivo del parche: rechazar SIN cambios.
export IMPE_HISTORY_PUBLIC="$tmp/conflict"
mkdir -p "$IMPE_HISTORY_PUBLIC"
git archive "$BEFORE" | tar -xf - -C "$IMPE_HISTORY_PUBLIC"
sed -i "s/const incoming = Boolean(state?.active/const incomingPERSONAL = Boolean(state?.active/" \
  "$IMPE_HISTORY_PUBLIC/public/javascript/telefonia_shell.js"
cp "$IMPE_HISTORY_PUBLIC/public/javascript/telefonia_shell.js" "$tmp/unchanged.js"
if bash "$DEPLOY" --apply >"$tmp/err.log" 2>&1; then
  echo "ERROR: publicó sobre archivos incompatibles" >&2
  exit 1
fi
grep -Fq 'CONFLICTO' "$tmp/err.log"
cmp -s "$tmp/unchanged.js" "$IMPE_HISTORY_PUBLIC/public/javascript/telefonia_shell.js"
[[ ! -e "$IMPE_HISTORY_PUBLIC/app/services/TelefoniaAnalistaWebrtcService.php" ]]
echo "Historial y cierre WebRTC Analista: OK (modal, persistencia, aislamiento, respaldo y conflictos)"
