#!/usr/bin/env bash
# Ingreso privado por SSH. No poner claves en argumentos ni en el historial.
set +x
set -euo pipefail
umask 077
MODE="${1:-}"
case "$MODE" in --recovery|--hostinger|--account) ;; *) echo 'Uso: --recovery CORREO | --hostinger | --account CORREO' >&2;exit 2;; esac
[[ "$#" -le 2 ]] || exit 2
[[ -t 0 ]] || { echo 'Ejecuta desde una terminal SSH interactiva.' >&2;exit 2; }
ROOT="$(cd "$(dirname "$0")/.." && pwd -P)"
EMAIL="${2:-}"
HOST='';PORT='587';TLS='tls'
SECRET=''
trap 'unset SECRET IMPE_CORREO_SETUP_SECRET' EXIT
if [[ "$MODE" != --hostinger && -z "$EMAIL" ]];then read -r -p 'Correo que se autenticará: ' EMAIL;fi
if [[ "$MODE" == --hostinger ]];then
 echo 'Configura la API compartida. No utiliza Gmail de recuperación.'
 if [[ -z "$EMAIL" ]];then
  echo 'Opcional: autoriza a un usuario del CRM a elegir los buzones disponibles en este token.'
  read -r -p 'Correo registrado de ese usuario (Enter: sólo buzón propio de cada empleado): ' EMAIL
 fi
 read -r -s -p 'Token ESPECÍFICO de Hostinger Mail API: ' SECRET;printf '\n'
else
 if [[ "$EMAIL" == *@gmail.com || "$EMAIL" == *@googlemail.com ]];then HOST='smtp.gmail.com';fi
 if [[ -z "$HOST" ]];then
  read -r -p 'Servidor SMTP de esa cuenta: ' HOST
  read -r -p 'Puerto SMTP [587]: ' VALUE;PORT="${VALUE:-587}"
  read -r -p 'Cifrado tls/ssl [tls]: ' VALUE;TLS="${VALUE:-tls}"
 fi
 echo 'Se comprobará TLS y autenticación, sin enviar mensajes.'
 if [[ "$HOST" == smtp.gmail.com ]];then read -r -s -p 'Clave de aplicación de ESTE Gmail (no la contraseña normal): ' SECRET
 else read -r -s -p 'Contraseña SMTP de ESTA cuenta: ' SECRET;fi
 printf '\n'
fi
[[ -n "$SECRET" ]] || { echo 'Clave vacía. Sin cambios.' >&2;exit 2; }
IMPE_CORREO_SETUP_SECRET="$SECRET" IMPE_CORREO_SETUP_EMAIL="$EMAIL" \
IMPE_CORREO_SETUP_HOST="$HOST" IMPE_CORREO_SETUP_PORT="$PORT" IMPE_CORREO_SETUP_TLS="$TLS" \
php "$ROOT/tools/configurar_correo_hostinger.php" "$MODE"
unset SECRET
