#!/usr/bin/env bash
# Configuración administrativa central; nunca escribe el token Mail activo ni SMTP.
set +x
set -euo pipefail
umask 077
[[ $# == 0 || ( $# == 1 && "$1" == --reemplazar-admin ) ]] || { echo 'Uso: bash configurar_hostinger_administracion.sh [--reemplazar-admin]' >&2;exit 2; }
[[ -t 0 ]] || { echo 'Ejecuta desde una terminal SSH interactiva.' >&2;exit 2; }
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
if [[ $# == 0 ]] && php "$ROOT/tools/hostinger_orden_correo.php" --check-admin >/dev/null 2>&1;then
 echo 'La credencial administrativa existente es válida. Se conserva sin cambios.';exit 0
fi
TOKEN=''
trap 'unset TOKEN IMPE_HOSTINGER_ADMIN_SETUP' EXIT
read -r -s -p 'Token HOSTINGER API de hPanel (no Mail API; entrada oculta): ' TOKEN
printf '\n'
IMPE_HOSTINGER_ADMIN_SETUP="$TOKEN" php "$ROOT/tools/hostinger_orden_correo.php" --guardar-admin
