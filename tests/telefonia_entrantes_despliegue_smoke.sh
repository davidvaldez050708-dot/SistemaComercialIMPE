#!/usr/bin/env bash
# Prueba sin tocar Hostinger: publicación idempotente con personalizaciones,
# y rechazo atómico de un conflicto sin modificar otros archivos.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE" "$TMP/backups"
touch "$SITE/index.php"
FILES=(
  "public/css/telefonia_host.css"
  "public/javascript/telefonia_host.js"
  "public/css/telefonia_shell.css"
  "public/javascript/telefonia_shell.js"
  "public/javascript/telefonia_persistente.js"
)
BASES=(
  "3078506f6c25b9f3c6efa828ca475c29c26605bf"
  "4cf410f52d77807e70d2e39c6e0c75eb3b4c0f15"
  "ba77890e228cb52f0af500c12014ffb6673e637a"
  "590868cb2b14e9879edddecdf42f1f53574c4931"
  "10883087c37e5e5a9990165e98017c31eae5797e"
)
for i in "${!FILES[@]}"; do
  p="${FILES[$i]}"
  mkdir -p "$SITE/$(dirname "$p")"
  git -C "$ROOT" cat-file blob "${BASES[$i]}" > "$SITE/$p"
done

echo '// NOTA LOCAL DE HOSTINGER QUE NO SE DEBE PERDER' >> "$SITE/public/javascript/telefonia_shell.js"
echo '/* TEMA LOCAL DEL CLIENTE */' >> "$SITE/public/css/telefonia_host.css"

deploy() {
  IMPE_INCOMING_SOURCE_DIR="$ROOT" \
  IMPE_INCOMING_PUBLIC_DIR="$SITE" \
  IMPE_INCOMING_BACKUP_DIR="$TMP/backups" \
  bash "$ROOT/tools/desplegar_telefonia_entrantes_hostinger.sh"
}
deploy
grep -Fq 'TEMA LOCAL DEL CLIENTE' "$SITE/public/css/telefonia_host.css"
grep -Fq 'NOTA LOCAL DE HOSTINGER' "$SITE/public/javascript/telefonia_shell.js"
grep -Fq 'body:not(.telephony-host-incoming)' "$SITE/public/css/telefonia_host.css"
grep -Fq "classList.add('telephony-host-incoming')" "$SITE/public/javascript/telefonia_host.js"
grep -Fq 'incomingInShell' "$SITE/public/javascript/telefonia_persistente.js"
before="$(sha256sum "$SITE/public/css/telefonia_host.css" | cut -d' ' -f1)"
deploy
after="$(sha256sum "$SITE/public/css/telefonia_host.css" | cut -d' ' -f1)"
[[ "$before" == "$after" ]]
[[ "$(find "$TMP/backups" -type f | wc -l)" -eq 5 ]]
[[ "$(grep -Fc 'TEMA LOCAL DEL CLIENTE' "$SITE/public/css/telefonia_host.css")" -eq 1 ]]

# Un conflicto en un archivo impide cualquier publicación en los demás.
git -C "$ROOT" cat-file blob "${BASES[0]}" > "$SITE/${FILES[0]}"
git -C "$ROOT" cat-file blob "${BASES[1]}" > "$SITE/${FILES[1]}"
php -r '
  $f=$argv[1];
  $s=file_get_contents($f);
  $s=str_replace("nativeUiVisible = true;", "let nativeUiVisible = \"local custom\";", $s);
  file_put_contents($f,$s);
' "$SITE/${FILES[1]}"
control="$(sha256sum "$SITE/${FILES[0]}" | cut -d' ' -f1)"
if deploy > "$TMP/conflict.out" 2>&1; then
  echo "ERROR: el deploy aceptó un conflicto Git" >&2
  exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/conflict.out"
[[ "$control" == "$(sha256sum "$SITE/${FILES[0]}" | cut -d' ' -f1)" ]]
echo 'Telefonía entrante Hostinger: OK (dos despliegues, ajustes y abortar conflicto)'
