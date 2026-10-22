#!/usr/bin/env bash
# Sólo token comercial; nunca pregunta ni utiliza SMTP de recuperación.
set +x
set -euo pipefail
umask 077
[[ $# == 0 ]] || { echo 'Uso: bash configurar_hostinger_comercial.sh' >&2;exit 2; }
[[ -t 0 ]] || { echo 'Ejecuta desde una terminal SSH interactiva.' >&2;exit 2; }
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
TOKEN=''
trap 'unset TOKEN IMPE_HOSTINGER_TOKEN_SETUP' EXIT
read -r -s -p 'Token ESPECÍFICO de Hostinger Mail API (entrada oculta): ' TOKEN
printf '\n'
IMPE_HOSTINGER_TOKEN_SETUP="$TOKEN" php "$ROOT/tools/diagnosticar_hostinger_comercial.php" --guardar-token
