#!/usr/bin/env bash
# Regresión del conflicto reportado: la primera versión del shell ya estaba
# instalada en Hostinger; se actualiza sin borrar personalizaciones locales.
set -euo pipefail
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITIO="$TMP/sitio"
mkdir -p "$SITIO" "$TMP/respaldos"
touch "$SITIO/index.php"

ARCHIVOS=(
  "app/controllers/LoginController.php"
  "app/controllers/TelefoniaController.php"
  "public/javascript/telefonia_persistente.js"
  "public/javascript/telefonia_host.js"
  "app/views/telefonia/host.php"
  "public/css/telefonia_host.css"
  "app/views/telefonia/recepcion_marketing.php"
  "public/css/telefonia_recepcion_marketing.css"
  "app/views/telefonia/shell.php"
  "public/javascript/telefonia_shell.js"
  "public/css/telefonia_shell.css"
)
PUBLICADO=(
  "1a516b6e85a43c7cae9788a7466f1b30a0069c13"
  "e9ad0ee9fb32206b4196d3b44fef372482917770"
  "88e841eef127baf9e902dc6d5b6c3a88ac2d20a7"
  "4cf410f52d77807e70d2e39c6e0c75eb3b4c0f15"
  "b5c0696a4ded8124d3014952e656bb49c711a8e4"
  "3078506f6c25b9f3c6efa828ca475c29c26605bf"
  "672bb7cac82909944923993477b2769028a481b9"
  "9230121a062cac5ef2162c65c2a4d84c046d8774"
  "837dd40a0ff0f6130efa7774fd7802d27a31a34d"
  "b014e07210a3fd09c2df832ab64bac95520b319e"
  "1be5ce91f7ce0d30104faba62ca3529abef3598f"
)
for i in "${!ARCHIVOS[@]}"; do
  p="${ARCHIVOS[$i]}"
  mkdir -p "$SITIO/$(dirname "$p")"
  git -C "$RAIZ" cat-file blob "${PUBLICADO[$i]}" > "$SITIO/$p"
done

# Personalizaciones reales no relacionadas con la protección CSS del shell.
echo '// Personalización local de Hostinger a conservar' >> "$SITIO/app/controllers/TelefoniaController.php"
echo '/* CSS local de Hostinger */' >> "$SITIO/public/css/telefonia_host.css"
echo '/* CSS local de recepción */' >> "$SITIO/public/css/telefonia_recepcion_marketing.css"
echo '<!-- Personalización local -->' >> "$SITIO/app/views/telefonia/shell.php"

ejecutar() {
  IMPE_SHELL_SOURCE_DIR="$RAIZ" \
  IMPE_SHELL_PUBLIC_DIR="$SITIO" \
  IMPE_SHELL_BACKUP_DIR="$TMP/respaldos" \
  bash "$RAIZ/tools/desplegar_telefonia_shell_hostinger.sh"
}
ejecutar
grep -Fq 'public function shell()' "$SITIO/app/controllers/TelefoniaController.php"
grep -Fq 'foreach ([' "$SITIO/app/controllers/TelefoniaController.php"
grep -Fq 'Personalización local de Hostinger a conservar' "$SITIO/app/controllers/TelefoniaController.php"
grep -Fq 'CSS local de Hostinger' "$SITIO/public/css/telefonia_host.css"
grep -Fq 'CSS local de recepción' "$SITIO/public/css/telefonia_recepcion_marketing.css"
grep -Fq 'Personalización local' "$SITIO/app/views/telefonia/shell.php"
grep -Fq '<style id="impeShellStyles">' "$SITIO/app/views/telefonia/shell.php"
grep -Fq '<script id="impeShellRuntime">' "$SITIO/app/views/telefonia/shell.php"
php -l "$SITIO/app/controllers/TelefoniaController.php"
php -l "$SITIO/app/views/telefonia/shell.php"

# Una segunda publicación debe ser segura e idempotente.
ejecutar
[[ "$(grep -Fc 'foreach ([' "$SITIO/app/controllers/TelefoniaController.php")" -ge 1 ]]
[[ "$(grep -Fc '<style id="impeShellStyles">' "$SITIO/app/views/telefonia/shell.php")" -eq 1 ]]
echo 'Hostinger upgrade shell: OK (conflicto original resuelto, personalizaciones conservadas, repetible)'
