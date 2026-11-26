#!/usr/bin/env bash
set -eu
umask 077
test "$#" -eq 2
SITIO="$1"
PRIVADO="$2"
PAQUETE="$(cat "$PRIVADO/reportes_vinculacion_paquete_actual")"
case "$PAQUETE" in "$PRIVADO"/reportes-vinculacion-*) ;; *) printf '%s\n' 'Paquete privado inválido.' >&2; exit 1;; esac
test ! -L "$PRIVADO/reportes_vinculacion_respaldo_actual"
test ! -L "$PRIVADO/reportes_vinculacion_rollback_paquete"
SALIDA="$(php "$PAQUETE/deploy.php" --root="$SITIO" --backup-root="$PRIVADO" --apply)"
printf '%s\n' "$SALIDA"
RESPALDO="${SALIDA##*backup=}"
printf '%s\n' "$RESPALDO" | grep -Eq '^reportes-[a-zA-Z0-9_-]+$'
printf '%s\n' "$PAQUETE" > "$PRIVADO/reportes_vinculacion_rollback_paquete"
printf '%s\n' "$RESPALDO" > "$PRIVADO/reportes_vinculacion_respaldo_actual"
