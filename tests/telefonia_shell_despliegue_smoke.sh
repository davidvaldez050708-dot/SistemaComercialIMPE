#!/usr/bin/env bash
# Ensayo del instalador en una réplica local, sin servicio externo ni MySQL.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
DEST="$TMP/prod"
mkdir -p "$DEST" "$TMP/backups"
touch "$DEST/index.php"

EXISTING=(
  "app/controllers/LoginController.php"
  "app/controllers/TelefoniaController.php"
  "public/javascript/telefonia_persistente.js"
  "public/javascript/telefonia_host.js"
  "app/views/telefonia/host.php"
  "public/css/telefonia_host.css"
  "app/views/telefonia/recepcion_marketing.php"
  "public/css/telefonia_recepcion_marketing.css"
)
BASES=(
  "c55fa9432161bf3830e2880b310071fe7a959d8d"
  "ae9cb736fcd968f1f70ba05597a2bc5f5686ff54"
  "6e662efdc9b4e88173f5f300a7237dca221d972b"
  "466023e271162f98452e29ab73d6a3d46fdb2454"
  "c886328f27abeebe289999e1412771932c0a7447"
  "a53b9ce0a6c6096788cd921e4999f4a981dbd6da"
  "bad0ff9e25ac5cc3d06e9021f6d7393e4700d792"
  "080cab618c94679cbe7da4bc74a169234f364ce0"
)
for i in "${!EXISTING[@]}"; do
  p="${EXISTING[$i]}"
  mkdir -p "$DEST/$(dirname "$p")"
  git -C "$ROOT" cat-file blob "${BASES[$i]}" > "$DEST/$p"
done
# Pruebas de cambios locales no relacionados a la edición.
echo '/* ajuste hostinger independiente */' >> "$DEST/public/css/telefonia_host.css"
echo '/* ajuste hostinger local */' >> "$DEST/public/css/telefonia_recepcion_marketing.css"

# Simular que el servidor ya tiene el shell de la versión anterior;
# se debe actualizar sin conflicto ni cambiar el diseño original.
mkdir -p "$DEST/app/views/telefonia"
git -C "$ROOT" cat-file blob "837dd40a0ff0f6130efa7774fd7802d27a31a34d" \
    > "$DEST/app/views/telefonia/shell.php"
echo '<!-- ajuste local Hostinger -->' >> "$DEST/app/views/telefonia/shell.php"

run() {
  IMPE_SHELL_SOURCE_DIR="$ROOT" \
  IMPE_SHELL_PUBLIC_DIR="$DEST" \
  IMPE_SHELL_BACKUP_DIR="$TMP/backups" \
  bash "$ROOT/tools/desplegar_telefonia_shell_hostinger.sh"
}
run | tee "$TMP/first-deploy.log"
FIRST_BACKUP="$(sed -n 's/^Respaldo: //p' "$TMP/first-deploy.log" | tail -1)"
HASH1="$(sha256sum "$DEST/public/css/telefonia_host.css" | cut -d' ' -f1)"
run
HASH2="$(sha256sum "$DEST/public/css/telefonia_host.css" | cut -d' ' -f1)"
[[ "$HASH1" = "$HASH2" ]]
grep -Fq 'ajuste hostinger independiente' "$DEST/public/css/telefonia_host.css"
grep -Fq 'ajuste hostinger local' "$DEST/public/css/telefonia_recepcion_marketing.css"
grep -Fq '<!-- ajuste local Hostinger -->' "$DEST/app/views/telefonia/shell.php"
grep -Fq '<style id="impeShellStyles">' "$DEST/app/views/telefonia/shell.php"
grep -Fq '<script id="impeShellRuntime">' "$DEST/app/views/telefonia/shell.php"
[[ "$(grep -Fc '/* WebRTC embebido:' "$DEST/public/css/telefonia_host.css")" -eq 1 ]]
[[ "$(grep -Fc '/* Configuración opcional de conexión automática' "$DEST/public/css/telefonia_recepcion_marketing.css")" -eq 1 ]]
[[ "$(find "$TMP/backups" -type f | wc -l)" -eq 19 ]]
IMPE_SHELL_PUBLIC_DIR="$DEST" IMPE_SHELL_BACKUP_DIR="$TMP/backups" \
bash "$ROOT/tools/revertir_telefonia_shell_hostinger.sh" "$FIRST_BACKUP" > /dev/null
! grep -Fq 'public function shell()' "$DEST/app/controllers/TelefoniaController.php"
! grep -Fq '$destinoInicio = ' "$DEST/app/controllers/LoginController.php"
echo 'Telefonía shell: OK (dos despliegues, fusión CSS, respaldo y reversión)'
