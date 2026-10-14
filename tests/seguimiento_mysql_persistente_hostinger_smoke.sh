#!/usr/bin/env bash
# Preflight, publicación e idempotencia con copias temporales; sin conectar a MySQL.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
LIVE="$TMP/site"
mkdir -p "$LIVE/config" "$LIVE/prueba_telefonia/api" "$TMP/backups"
git show "1f41511a08d17162d9e2257b5949af4efd814482:config/db_connection.php" > "$LIVE/config/db_connection.php"
git show "bb9b350ec836e5a8b0abf4cffd1bcd50ade83f6f:prueba_telefonia/api/llamadas_seguimiento.php" > "$LIVE/prueba_telefonia/api/llamadas_seguimiento.php"
export IMPE_PERSIST_SOURCE="$ROOT"
export IMPE_PERSIST_PUBLIC="$LIVE"
export IMPE_PERSIST_BACKUPS="$TMP/backups"
bash tools/desplegar_mysql_persistente_historial_hostinger.sh --check | grep -q "PRODUCCIÓN INTACTA"
git show "1f41511a08d17162d9e2257b5949af4efd814482:config/db_connection.php" | cmp - "$LIVE/config/db_connection.php"
bash tools/desplegar_mysql_persistente_historial_hostinger.sh --apply | grep -q "parche MySQL publicado"
git show "bb9b350ec836e5a8b0abf4cffd1bcd50ade83f6f:config/db_connection.php" | cmp - "$LIVE/config/db_connection.php"
git show "f0229bc2b1ffe8db1bce6c9c9ba8aa6210b9a98b:prueba_telefonia/api/llamadas_seguimiento.php" | cmp - "$LIVE/prueba_telefonia/api/llamadas_seguimiento.php"
bash tools/desplegar_mysql_persistente_historial_hostinger.sh --check | grep -q "ya instalada"
[[ -n "$(find "$TMP/backups" -type f)" ]]
echo "Conexión MySQL persistente del historial: OK (check, apply, respaldo, idempotencia)"
