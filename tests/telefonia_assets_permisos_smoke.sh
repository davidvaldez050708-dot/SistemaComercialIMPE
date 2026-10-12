#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE/public/javascript" "$SITE/public/css"
touch "$SITE/index.php"
printf '// bridge\n' > "$SITE/public/javascript/telefonia_entrantes_bridge.js"
printf '// teclado\n' > "$SITE/public/javascript/telefonia_teclado.js"
printf '/* teclado */\n' > "$SITE/public/css/telefonia_teclado.css"
chmod 0600 "$SITE/public/javascript/telefonia_entrantes_bridge.js" \
  "$SITE/public/javascript/telefonia_teclado.js" "$SITE/public/css/telefonia_teclado.css"
run() { IMPE_ASSETS_PUBLIC_DIR="$SITE" bash "$ROOT/tools/reparar_assets_telefonia_hostinger.sh" "$1"; }
run --check
[[ "$(stat -c '%a' "$SITE/public/javascript/telefonia_entrantes_bridge.js")" == 600 ]]
run --apply
for p in "$SITE/public/javascript/telefonia_entrantes_bridge.js" \
         "$SITE/public/javascript/telefonia_teclado.js" \
         "$SITE/public/css/telefonia_teclado.css"; do
  [[ "$(stat -c '%a' "$p")" == 644 ]]
done
printf 'PRIVATE\n' > "$SITE/private.txt"
chmod 0600 "$SITE/private.txt"
run --apply
[[ "$(stat -c '%a' "$SITE/private.txt")" == 600 ]]
echo "Permisos de telefonía: OK (sin tocar secretos ni otros archivos)"
