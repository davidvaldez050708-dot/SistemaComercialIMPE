#!/usr/bin/env bash
# Reversión segura de los ocho archivos anteriores a la navegación embebida.
# Conserva los archivos nuevos (sin uso después de restaurar controlador).
# Uso: bash tools/revertir_telefonia_shell_hostinger.sh "RUTA_RESPALDO"
set -euo pipefail
BACKUP="${1:-}"
DST="${IMPE_SHELL_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
RESP="${IMPE_SHELL_BACKUP_DIR:-$HOME/respaldos-crm}"
if [[ -z "$BACKUP" || ! -d "$BACKUP" || ! -d "$RESP" ]]; then
  echo "Uso: bash tools/revertir_telefonia_shell_hostinger.sh RUTA_RESPALDO" >&2
  exit 2
fi
REAL_BACKUP="$(cd "$BACKUP" && pwd -P)"
REAL_ROOT="$(cd "$RESP" && pwd -P)"
if [[ "$REAL_BACKUP" != "$REAL_ROOT"/telefonia-shell-* ]]; then
  echo "ERROR: sólo se aceptan respaldos de telefonia-shell en $REAL_ROOT" >&2
  exit 1
fi
FILES=(
  "app/controllers/LoginController.php"
  "app/controllers/TelefoniaController.php"
  "public/javascript/telefonia_persistente.js"
  "public/javascript/telefonia_host.js"
  "app/views/telefonia/host.php"
  "public/css/telefonia_host.css"
  "app/views/telefonia/recepcion_marketing.php"
  "public/css/telefonia_recepcion_marketing.css"
)
for file in "${FILES[@]}"; do
  [[ -f "$REAL_BACKUP/$file" && -f "$DST/$file" ]] || {
    echo "ERROR: falta un archivo que debe restaurarse: $file" >&2
    exit 1
  }
done
for file in app/controllers/LoginController.php \
            app/controllers/TelefoniaController.php \
            app/views/telefonia/host.php; do
  php -l "$REAL_BACKUP/$file"
done
echo "Restaurando únicamente los ocho archivos guardados en:"
echo "$REAL_BACKUP"
for file in "${FILES[@]}"; do
  cp -p "$REAL_BACKUP/$file" "$DST/$file"
done
php -l "$DST/app/controllers/LoginController.php"
php -l "$DST/app/controllers/TelefoniaController.php"
echo "OK: modo anterior restaurado sin tocar BD, Zadarma ni datos del CRM."
echo "Vuelve a iniciar sesión para salir de la navegación persistente."
