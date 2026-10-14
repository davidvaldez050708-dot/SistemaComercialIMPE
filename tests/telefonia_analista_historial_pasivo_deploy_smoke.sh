#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
DEPLOY="tools/desplegar_historial_pasivo_analista_hostinger.sh"
INJECT="tools/injectar_historial_pasivo_analista.php"
BASE="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
SIDE="public/javascript/telefonia_analista_historial_pasivo.js"
SERVICE="app/services/TelefoniaAnalistaWebrtcService.php"

node --check "$SIDE"
node tests/telefonia_analista_historial_pasivo_smoke.js
php -l "$INJECT" >/dev/null
bash -n "$DEPLOY"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
export IMPE_PASSIVE_SOURCE="$PWD"
export IMPE_PASSIVE_BACKUPS="$tmp/backups"
export IMPE_PASSIVE_PUBLIC="$tmp/live"
mkdir -p "$IMPE_PASSIVE_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_PASSIVE_PUBLIC"

# Cambios operativos en producción que NUNCA deben pisarse.
python3 - "$IMPE_PASSIVE_PUBLIC/public/javascript/telefonia_shell.js" <<'PY'
import sys
p=sys.argv[1]
with open(p) as f:s=f.read()
needle='!(cfg.analystIncomingResults && nativeVisible) &&'
assert s.count(needle)==1
s=s.replace(needle,'/* filtro nativo eliminado en producción para conservar recepción */ true &&')
with open(p,'w') as f:f.write(s)
PY
for file in public/javascript/telefonia_shell.js \\
  public/javascript/telefonia_host.js \\
  public/javascript/telefonia_sdk_entrantes_observador.js \\
  app/views/telefonia/host.php; do
  mkdir -p "$tmp/protected/$(dirname "$file")"
  cp -p "$IMPE_PASSIVE_PUBLIC/$file" "$tmp/protected/$file"
done
previous="$(bash "$DEPLOY" --check)"
grep -Fq 'PRODUCCIÓN INTACTA' <<< "$previous"
[[ ! -f "$IMPE_PASSIVE_PUBLIC/$SIDE" ]]
[[ ! -f "$IMPE_PASSIVE_PUBLIC/$SERVICE" ]]
bash "$DEPLOY" --apply
test -f "$IMPE_PASSIVE_PUBLIC/$SIDE"
test -f "$IMPE_PASSIVE_PUBLIC/$SERVICE"
grep -Fq 'IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL' "$IMPE_PASSIVE_PUBLIC/app/views/telefonia/shell.php"
grep -Fq 'window.IMPE_ANALYST_WEBRTC_JOURNAL' "$IMPE_PASSIVE_PUBLIC/app/views/telefonia/shell.php"
php -l "$IMPE_PASSIVE_PUBLIC/app/views/telefonia/shell.php" >/dev/null
[[ "$(stat -c %a "$IMPE_PASSIVE_PUBLIC/$SIDE")" == "644" ]]
for file in public/javascript/telefonia_shell.js \\
  public/javascript/telefonia_host.js \\
  public/javascript/telefonia_sdk_entrantes_observador.js \\
  app/views/telefonia/host.php; do
  cmp -s "$tmp/protected/$file" "$IMPE_PASSIVE_PUBLIC/$file"
done
again="$(bash "$DEPLOY" --check)"
grep -Fq 'ya publicada' <<< "$again"

# Un conflicto en backend debe detenerse ANTES de cambiar producción.
export IMPE_PASSIVE_PUBLIC="$tmp/conflict"
mkdir -p "$IMPE_PASSIVE_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_PASSIVE_PUBLIC"
python3 - "$IMPE_PASSIVE_PUBLIC/app/services/TelefoniaEntrantesAnalistaService.php" <<'PY'
import sys
p=sys.argv[1]
with open(p) as f:s=f.read()
needle='return [\\'extension\\' => $extension, \\'llamadas\\' => $llamadas];'
assert s.count(needle)==1
s=s.replace(needle,'return [\\'extension\\' => $extension, \\'llamadas\\' => array_values($llamadas)];')
with open(p,'w') as f:f.write(s)
PY
cp -p "$IMPE_PASSIVE_PUBLIC/app/services/TelefoniaEntrantesAnalistaService.php" "$tmp/conflict-original"
if bash "$DEPLOY" --apply > "$tmp/err.log" 2>&1; then
  echo 'ERROR: despliegue admitió conflicto inesperado' >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$tmp/err.log"
cmp -s "$tmp/conflict-original" "$IMPE_PASSIVE_PUBLIC/app/services/TelefoniaEntrantesAnalistaService.php"
[[ ! -f "$IMPE_PASSIVE_PUBLIC/$SIDE" ]]
[[ ! -f "$IMPE_PASSIVE_PUBLIC/$SERVICE" ]]
echo "Historial pasivo: OK (SIP intacto, rollback, fusión backend y despliegue seguro)"
