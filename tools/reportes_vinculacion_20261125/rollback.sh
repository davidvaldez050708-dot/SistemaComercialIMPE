#!/usr/bin/env bash
set -eu
umask 077
test "$#" -eq 2
SITIO="$1"
PRIVADO="$2"
PAQUETE="$(cat "$PRIVADO/reportes_vinculacion_rollback_paquete")"
RESPALDO="$(cat "$PRIVADO/reportes_vinculacion_respaldo_actual")"
case "$PAQUETE" in "$PRIVADO"/reportes-vinculacion-*) ;; *) printf '%s\n' 'Paquete privado inválido.' >&2; exit 1;; esac
printf '%s\n' "$RESPALDO" | grep -Eq '^reportes-[a-zA-Z0-9_-]+$'
php "$PAQUETE/deploy.php" --root="$SITIO" --backup-root="$PRIVADO" --rollback="$RESPALDO"
