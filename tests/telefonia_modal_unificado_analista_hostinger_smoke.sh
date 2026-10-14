#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
DEPLOY="tools/desplegar_modal_unificado_analista_hostinger.sh"
BASE="9767043f693f4c1e292d28c7d88064ccb40d8dcc"
AFTER="785ef3ae9c4cd1341aa41699b40799beee5d73d6"
FILES=(
  public/javascript/telefonia_analista_historial_pasivo.js
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  public/css/seguimiento_entrantes_analista.css
)
PROTECTED=(
  public/javascript/telefonia_host.js
  public/javascript/telefonia_shell.js
  public/javascript/telefonia_sdk_entrantes_observador.js
  app/views/telefonia/host.php
  app/views/telefonia/shell.php
)
bash -n "$DEPLOY"
node --check public/javascript/telefonia_analista_historial_pasivo.js
node tests/telefonia_analista_historial_pasivo_smoke.js
php -l app/views/seguimiento_vinculacion/llamadas_entrantes.php >/dev/null

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
export IMPE_UNIFIED_SOURCE="$PWD" IMPE_UNIFIED_BACKUPS="$tmp/backups"
export IMPE_UNIFIED_PUBLIC="$tmp/production"
mkdir -p "$IMPE_UNIFIED_PUBLIC" "$tmp/protected"
git archive "$BASE" | tar -xf - -C "$IMPE_UNIFIED_PUBLIC"

for file in "${PROTECTED[@]}"; do
  mkdir -p "$tmp/protected/$(dirname "$file")"
  cp -p "$IMPE_UNIFIED_PUBLIC/$file" "$tmp/protected/$file"
done
before="$(bash "$DEPLOY" --check)"
grep -Fq 'diseño unificado compatible (3 archivos)' <<< "$before"
grep -Fq 'PRODUCCIÓN INTACTA' <<< "$before"
for file in "${FILES[@]}"; do
  git show "$BASE:$file" | cmp -s - "$IMPE_UNIFIED_PUBLIC/$file"
done
bash "$DEPLOY" --apply
for file in "${FILES[@]}"; do
  git show "$AFTER:$file" | cmp -s - "$IMPE_UNIFIED_PUBLIC/$file"
  [[ "$(stat -c %a "$IMPE_UNIFIED_PUBLIC/$file")" == '644' ]]
done
for file in "${PROTECTED[@]}"; do
  cmp -s "$IMPE_UNIFIED_PUBLIC/$file" "$tmp/protected/$file"
done
again="$(bash "$DEPLOY" --check)"
grep -Fq 'ya están publicados' <<< "$again"
[[ -d "$IMPE_UNIFIED_BACKUPS" ]]

# Un conflicto real en el mismo bloque del historial debe bloquear todos
# los cambios ANTES de tocar el archivo de recepción o el nuevo CSS.
export IMPE_UNIFIED_PUBLIC="$tmp/conflict"
mkdir -p "$IMPE_UNIFIED_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_UNIFIED_PUBLIC"
python3 - "$IMPE_UNIFIED_PUBLIC/app/views/seguimiento_vinculacion/llamadas_entrantes.php" <<'PY'
import sys
p=sys.argv[1]
with open(p) as f:s=f.read()
needle='<tr data-incoming-row="<?= $esc($pbxId) ?>">'
assert s.count(needle)==1
s=s.replace(needle,'<tr data-incoming-row="<?= $esc($pbxId) ?>" aria-live="polite">')
with open(p,'w') as f:f.write(s)
PY
cp -p "$IMPE_UNIFIED_PUBLIC/app/views/seguimiento_vinculacion/llamadas_entrantes.php" "$tmp/conflict-original"
if bash "$DEPLOY" --apply >"$tmp/error.log" 2>&1; then
  echo "ERROR: se publicó a pesar del conflicto" >&2
  exit 1
fi
grep -Fq 'CONFLICTO:' "$tmp/error.log"
cmp -s "$tmp/conflict-original" "$IMPE_UNIFIED_PUBLIC/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
git show "$BASE:public/javascript/telefonia_analista_historial_pasivo.js" |
  cmp -s - "$IMPE_UNIFIED_PUBLIC/public/javascript/telefonia_analista_historial_pasivo.js"
echo "Modal unificado Analista: OK (mismo panel, responsive, rollback y recepción intacta)"
