#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
DEPLOY="tools/desplegar_botones_modal_final_analista_hostinger.sh"
BASE="818f0219a1d5eb8333fde9e3e82ea7d2daa0bb23"
TARGET="8a07d65f5417cfa6fad41e89aa314c3ac7c216d5"
JS="public/javascript/telefonia_analista_historial_pasivo.js"
PROTECTED=(
  public/javascript/telefonia_host.js
  public/javascript/telefonia_shell.js
  public/javascript/telefonia_sdk_entrantes_observador.js
  app/views/telefonia/host.php
  app/views/telefonia/shell.php
  public/css/telefonia_shell.css
  public/css/telefonia_persistente.css
  public/css/seguimiento_entrantes_analista.css
  app/services/TelefoniaEntrantesAnalistaService.php
  app/services/TelefoniaAnalistaWebrtcService.php
)
bash -n "$DEPLOY"
node --check "$JS"
node tests/telefonia_analista_historial_pasivo_smoke.js

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
export IMPE_BUTTONS_SOURCE="$PWD"
export IMPE_BUTTONS_PUBLIC="$tmp/production"
export IMPE_BUTTONS_BACKUPS="$tmp/backups"
mkdir -p "$IMPE_BUTTONS_PUBLIC" "$tmp/protected"
git archive "$BASE" | tar -xf - -C "$IMPE_BUTTONS_PUBLIC"
for file in "${PROTECTED[@]}"; do
  mkdir -p "$tmp/protected/$(dirname "$file")"
  cp -p "$IMPE_BUTTONS_PUBLIC/$file" "$tmp/protected/$file"
done
result="$(bash "$DEPLOY" --check)"
grep -Fq "1 JavaScript visual; PRODUCCIÓN INTACTA" <<< "$result"
git show "$BASE:$JS" | cmp -s - "$IMPE_BUTTONS_PUBLIC/$JS"
bash "$DEPLOY" --apply
git show "$TARGET:$JS" | cmp -s - "$IMPE_BUTTONS_PUBLIC/$JS"
[[ "$(stat -c %a "$IMPE_BUTTONS_PUBLIC/$JS")" == "644" ]]
for file in "${PROTECTED[@]}"; do
  cmp -s "$IMPE_BUTTONS_PUBLIC/$file" "$tmp/protected/$file"
done
[[ "$(find "$IMPE_BUTTONS_BACKUPS" -maxdepth 1 -name 'botones-modal-analista-*.js' | wc -l)" -eq 1 ]]
again="$(bash "$DEPLOY" --apply)"
grep -Fq 'ya alineados' <<< "$again"

# Un archivo de historial modificado en la zona de estilos debe bloquearse,
# y JAMÁS debe actualizarse el motor o los webhooks.
export IMPE_BUTTONS_PUBLIC="$tmp/conflict"
mkdir -p "$IMPE_BUTTONS_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_BUTTONS_PUBLIC"
php -r '
$p=$argv[1];
$s=file_get_contents($p);
$before="grid-column:auto!important;justify-self:stretch!important;";
$after="grid-column:1 / -1!important;justify-self:end!important;";
if (substr_count($s, $before) !== 1) exit(1);
file_put_contents($p, str_replace($before, $after, $s));
' "$IMPE_BUTTONS_PUBLIC/$JS"
cp "$IMPE_BUTTONS_PUBLIC/$JS" "$tmp/conflict-original.js"
if bash "$DEPLOY" --apply > "$tmp/error.log" 2>&1; then
  echo "ERROR: despliegue aceptó un conflicto no previsto" >&2
  exit 1
fi
grep -Fq "CONFLICTO" "$tmp/error.log"
cmp -s "$IMPE_BUTTONS_PUBLIC/$JS" "$tmp/conflict-original.js"
echo "Botones modal Analista: OK (misma fila, responsive, 1 archivo, respaldo, idempotencia y conflicto)"
