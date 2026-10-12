#!/usr/bin/env bash
# Reproduce una instalación Hostinger que tiene la shell previa y estilos
# locales propios. Sin conexiones remotas ni claves de Zadarma.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/production"
mkdir -p "$SITE" "$TMP/respaldos"
touch "$SITE/index.php"
FILES=(
  "app/views/telefonia/shell.php"
  "public/javascript/telefonia_shell.js"
  "public/javascript/telefonia_persistente.js"
  "public/javascript/telefonia_recepcion_marketing.js"
  "app/views/layout/dashboard_head.php"
)
BASES=(
  "fc72c621870829fe3f99e80c0e02a39146f56bac"
  "b014e07210a3fd09c2df832ab64bac95520b319e"
  "88e841eef127baf9e902dc6d5b6c3a88ac2d20a7"
  "badf5cd1e83bc15c5599516a5afde8e9c4984c68"
  "ef759c036be91121c29ecec54d206a631c5fa70d"
)
for i in "${!FILES[@]}"; do
  p="${FILES[$i]}"
  mkdir -p "$SITE/$(dirname "$p")"
  git -C "$ROOT" cat-file blob "${BASES[$i]}" > "$SITE/$p"
done
echo '<!-- cambio local de Hostinger -->' >> "$SITE/app/views/telefonia/shell.php"
echo '// cambio local de cliente' >> "$SITE/public/javascript/telefonia_shell.js"
echo '/* estilo local no afectado */' >> "$SITE/app/views/layout/dashboard_head.php"

deploy() {
  IMPE_DIALER_SOURCE_DIR="$ROOT" \
  IMPE_DIALER_PUBLIC_DIR="$SITE" \
  IMPE_DIALER_BACKUP_DIR="$TMP/respaldos" \
  bash "$ROOT/tools/desplegar_telefonia_marcador_hostinger.sh"
}
deploy
grep -Fq 'cambio local de Hostinger' "$SITE/app/views/telefonia/shell.php"
grep -Fq 'cambio local de cliente' "$SITE/public/javascript/telefonia_shell.js"
grep -Fq 'estilo local no afectado' "$SITE/app/views/layout/dashboard_head.php"
grep -Fq 'impeDialerStyles' "$SITE/app/views/telefonia/shell.php"
grep -Fq 'openDialer: openDialer' "$SITE/public/javascript/telefonia_persistente.js"
grep -Fq 'telefono.openDialer?.({' "$SITE/public/javascript/telefonia_recepcion_marketing.js"
grep -Fq '.impe-dialer-dialog' "$SITE/public/css/telefonia_teclado.css"
grep -Fq 'IMPE_TELEPHONY_DIALER' "$SITE/public/javascript/telefonia_teclado.js"
ONE="$(sha256sum "$SITE/public/javascript/telefonia_persistente.js" | cut -d' ' -f1)"
deploy
TWO="$(sha256sum "$SITE/public/javascript/telefonia_persistente.js" | cut -d' ' -f1)"
[[ "$ONE" == "$TWO" ]]
[[ "$(grep -Fc 'openDialer: openDialer' "$SITE/public/javascript/telefonia_persistente.js")" -eq 1 ]]
[[ "$(find "$TMP/respaldos" -type f | wc -l)" -eq 12 ]]
echo 'Marcador Hostinger: OK (dos despliegues, respalda y conserva ajustes)'
