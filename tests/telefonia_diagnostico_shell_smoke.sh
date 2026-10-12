#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE/public/javascript"
FILE="$SITE/public/javascript/telefonia_shell.js"
cp "$ROOT/public/javascript/telefonia_shell.js" "$FILE"
run() {
  IMPE_SHELL_SOURCE_DIR="$ROOT" IMPE_SHELL_PUBLIC_DIR="$SITE" \
    bash "$ROOT/tools/diagnosticar_shell_telefonia_hostinger.sh" "$1"
}
run --check > "$TMP/igual.txt"
grep -Fq 'Hostinger' "$TMP/igual.txt"
grep -Fq 'Estado acciones recientes' "$TMP/igual.txt"
grep -Fq 'OK' "$TMP/igual.txt"
git -C "$ROOT" show cf1c1644baa2ed4275759555db3814b96fe06f93:public/javascript/telefonia_shell.js > "$FILE"
sed -i 's/pendingIncomingAction/oldPendingAction/g' "$FILE"
BEFORE="$(sha256sum "$FILE")"
run --check > "$TMP/viejo.txt"
grep -Eq 'Estado acciones recientes.*FALTA.*OK.*FALTA' "$TMP/viejo.txt"
run --diff > "$TMP/diff.txt"
grep -Fq 'oldPendingAction' "$TMP/diff.txt"
[[ "$BEFORE" == "$(sha256sum "$FILE")" ]]
echo "Diagnóstico shell: OK (lectura, controles faltantes y diferencia local)"
