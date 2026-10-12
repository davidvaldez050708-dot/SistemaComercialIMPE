#!/usr/bin/env bash
# Ensayo con archivos históricos, localizaciones distintas y un conflicto real.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/domains/fundacionrededumex.org/public_html"
mkdir -p "$SITE/public/javascript" "$SITE/public/css" "$SITE/app/views/telefonia"
touch "$SITE/index.php"
ln -s "$ROOT" "$TMP/crm-preparacion"
FILES="public/javascript/telefonia_host.js public/javascript/telefonia_shell.js public/css/telefonia_shell.css app/views/telefonia/host.php app/views/telefonia/shell.php"
for p in $FILES; do
  git -C "$ROOT" show "00e79124af6b77459e79fd8f47437ac2c2eaf1dc:$p" > "$SITE/$p"
done
echo '/* ESTILO-LOCAL-PRESERVAR */' >> "$SITE/public/css/telefonia_shell.css"
echo '// NOTA-LOCAL-PRESERVAR' >> "$SITE/public/javascript/telefonia_host.js"
deploy() { HOME="$TMP" bash "$ROOT/tools/desplegar_modal_entrantes_hostinger.sh" "$1"; }
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_host.js")"
deploy --check
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_host.js")" ]]
[[ ! -e "$SITE/public/javascript/telefonia_entrantes_bridge.js" ]]
deploy --apply
grep -Fq 'NOTA-LOCAL-PRESERVAR' "$SITE/public/javascript/telefonia_host.js"
grep -Fq 'ESTILO-LOCAL-PRESERVAR' "$SITE/public/css/telefonia_shell.css"
grep -Fq 'data-incoming-answer' "$SITE/app/views/telefonia/shell.php"
grep -Fq 'IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY' "$SITE/public/javascript/telefonia_host.js"
test -f "$SITE/public/javascript/telefonia_entrantes_bridge.js"
[[ "$(stat -c '%a' "$SITE/public/javascript/telefonia_entrantes_bridge.js")" == 644 ]]
chmod 0600 "$SITE/public/javascript/telefonia_entrantes_bridge.js"
deploy --check
[[ "$(stat -c '%a' "$SITE/public/javascript/telefonia_entrantes_bridge.js")" == 600 ]]
deploy --apply
[[ "$(stat -c '%a' "$SITE/public/javascript/telefonia_entrantes_bridge.js")" == 644 ]]
deploy --apply
[[ "$(find "$TMP/respaldos-crm" -type f | wc -l)" -eq 6 ]]
git -C "$ROOT" show "00e79124af6b77459e79fd8f47437ac2c2eaf1dc:public/javascript/telefonia_shell.js" \
  > "$SITE/public/javascript/telefonia_shell.js"
php -r '
$f=$argv[1]; $s=file_get_contents($f);
$old="    const callHelp = document.querySelector(";
if (!str_contains($s,$old)) exit(2);
$s=str_replace($old, "    const customHelp = document.querySelector(", $s);
file_put_contents($f,$s);
' "$SITE/public/javascript/telefonia_shell.js"
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_shell.js")"
if deploy --apply > "$TMP/conflict.out" 2>&1; then
  echo "ERROR: se aceptó una personalización incompatible." >&2
  exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/conflict.out"
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_shell.js")" ]]
# Caso de producción: Hostinger ya publicó el modal previo y el puente
# Zadarma de 39f306f. Asegurar que --check y --apply no lo rechacen.
for p in $FILES public/javascript/telefonia_entrantes_bridge.js; do
  git -C "$ROOT" show "39f306fd998de860a515df9222700669b2d96dab:$p" > "$SITE/$p"
done
deploy --check
deploy --apply
grep -Fq 'persistent-phone-panel impe-incoming-panel' "$SITE/app/views/telefonia/shell.php"
grep -Fq 'sdk[method].apply(sdk, args || [])' "$SITE/public/javascript/telefonia_entrantes_bridge.js"
grep -Fq "answer: () => perform('contestar', 'answer'" "$SITE/public/javascript/telefonia_entrantes_bridge.js"
grep -Fq "hangup: () => perform('colgar', 'finishCall'" "$SITE/public/javascript/telefonia_entrantes_bridge.js"
deploy --apply
echo "Modal Marketing Hostinger: OK (dos versiones históricas, respaldo y conflictos)"

