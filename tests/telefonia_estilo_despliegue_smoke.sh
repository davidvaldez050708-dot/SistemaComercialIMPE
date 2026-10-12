#!/usr/bin/env bash
# Hostinger simulado: el marcador actual está instalado y tiene estilos
# personalizados. Debe preservar cambios locales y poder ejecutarse dos veces.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE" "$TMP/backups"
touch "$SITE/index.php"

FILES=(
  "app/views/telefonia/shell.php"
  "public/javascript/telefonia_teclado.js"
  "public/css/telefonia_teclado.css"
  "public/css/telefonia_shell.css"
)
BASES=(
  "e1766f7de4f22ba7433092294fd337256988f1d9"
  "b3008fd7efb9096c6f8f8eea52db771a91c3fb4e"
  "8fc1f37caf003feafbc929c98768f108ed2a11b9"
  "1be5ce91f7ce0d30104faba62ca3529abef3598f"
)
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  mkdir -p "$SITE/$(dirname "$file")"
  git -C "$ROOT" cat-file blob "${BASES[$i]}" > "$SITE/$file"
done

echo '/* MI-AJUSTE-LOCAL-HOSTINGER */' >> "$SITE/public/css/telefonia_teclado.css"
echo '/* MI-AJUSTE-SHELL-HOSTINGER */' >> "$SITE/public/css/telefonia_shell.css"

run() {
  IMPE_DIALER_STYLE_SOURCE_DIR="$ROOT" \
  IMPE_DIALER_STYLE_PUBLIC_DIR="$SITE" \
  IMPE_DIALER_STYLE_BACKUP_DIR="$TMP/backups" \
  bash "$ROOT/tools/desplegar_telefonia_estilo_hostinger.sh"
}
run
grep -Fq 'MI-AJUSTE-LOCAL-HOSTINGER' "$SITE/public/css/telefonia_teclado.css"
grep -Fq 'MI-AJUSTE-SHELL-HOSTINGER' "$SITE/public/css/telefonia_shell.css"
grep -Fq 'fonts.googleapis.com/css2?family=Manrope' "$SITE/app/views/telefonia/shell.php"
grep -Fq 'bi bi-backspace' "$SITE/public/javascript/telefonia_teclado.js"
grep -Fq 'font-size:13px;font-weight:700' "$SITE/public/css/telefonia_teclado.css"
sha1="$(sha256sum "$SITE/public/css/telefonia_teclado.css" | cut -d' ' -f1)"
run
sha2="$(sha256sum "$SITE/public/css/telefonia_teclado.css" | cut -d' ' -f1)"
[[ "$sha1" == "$sha2" ]]
[[ "$(grep -Fc 'MI-AJUSTE-LOCAL-HOSTINGER' "$SITE/public/css/telefonia_teclado.css")" -eq 1 ]]
[[ "$(find "$TMP/backups" -type f | wc -l)" -eq 4 ]]
echo 'Teléfono visual: OK (respaldo, conservar personalizaciones, dos ejecuciones)'
