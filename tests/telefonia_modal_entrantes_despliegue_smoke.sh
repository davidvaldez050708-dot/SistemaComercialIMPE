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
echo "Modal Marketing Hostinger: OK (preflight, respaldo, idempotencia y conflicto)"
