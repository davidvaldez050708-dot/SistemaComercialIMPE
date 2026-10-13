#!/usr/bin/env bash
# Reparar únicamente permisos HTTP de cuatro assets JS/CSS de telefonía.
# No modifica directorios, scripts PHP, configuraciones ni secretos.
set -euo pipefail
MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash tools/reparar_assets_telefonia_hostinger.sh --check|--apply" >&2
  exit 2
fi
PUBLIC="${IMPE_ASSETS_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
[[ -f "$PUBLIC/index.php" ]] || { echo "ERROR: no existe public_html del CRM." >&2; exit 1; }
FILES=(
  "public/javascript/telefonia_persistente.js"
  "public/javascript/telefonia_entrantes_bridge.js"
  "public/javascript/telefonia_teclado.js"
  "public/css/telefonia_teclado.css"
)
echo "Inspeccionando cuatro archivos estáticos telefónicos..."
for p in "${FILES[@]}"; do
  f="$PUBLIC/$p"
  if [[ ! -f "$f" || -L "$f" ]]; then
    echo "ERROR: archivo no regular o enlace simbólico: $p" >&2
    exit 1
  fi
  echo "$p: $(stat -c '%a' "$f")"
done
if [[ "$MODE" == "--check" ]]; then
  echo "Sólo lectura. Los archivos públicos normalmente requieren modo 644."
  echo "Para reparar exclusivamente estos cuatro assets, utiliza --apply."
  exit 0
fi
for p in "${FILES[@]}"; do
  chmod 0644 "$PUBLIC/$p"
  [[ "$(stat -c '%a' "$PUBLIC/$p")" == 644 ]] || {
    echo "ERROR: no se corrigió el modo de $p" >&2
    exit 1
  }
done
echo "OK: cuatro recursos JS/CSS telefónicos con permisos HTTP 644."
echo "Si persiste un 403, inspecciona las reglas .htaccess/WAF en hPanel."
