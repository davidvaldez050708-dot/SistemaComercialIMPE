#!/usr/bin/env bash
# Valida la versión del conector global sin conectar a MySQL real.
set -euo pipefail
cd "$(dirname "$0")/.."
SOURCE="$PWD"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/site"
FILE="config/db_connection.php"
mkdir -p "$SITE/config" "$TMP/backups"
git show "39a0cd1f3d50a7097b2dc6111250de350b5dded4:$FILE" > "$SITE/$FILE"
export IMPE_GLOBAL_MYSQL_SOURCE="$SOURCE"
export IMPE_GLOBAL_MYSQL_PUBLIC="$SITE"
export IMPE_GLOBAL_MYSQL_BACKUPS="$TMP/backups"

bash tools/desplegar_mysql_persistente_global_hostinger.sh --check | grep -q 'PRODUCCIÓN INTACTA'
git show "39a0cd1f3d50a7097b2dc6111250de350b5dded4:$FILE" | cmp - "$SITE/$FILE"
bash tools/desplegar_mysql_persistente_global_hostinger.sh --apply | grep -q 'global publicada'
git show "5e68b52138b8f63a3c91e829ae7b3dfbebc3c9f1:$FILE" | cmp - "$SITE/$FILE"
bash tools/desplegar_mysql_persistente_global_hostinger.sh --check | grep -q 'ya habilitadas'
[[ -n "$(find "$TMP/backups" -type f)" ]]

# Sin acceso a una BD: cargar clase y verificar el default y la reversibilidad.
cat > "$SITE/config/config.php" <<'PHP'
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'usuario_falso');
define('DB_PASSWORD', 'falsa');
define('DB_NAME', 'base_falsa');
PHP
php -r '
require $argv[1];
$m = new ReflectionMethod(Database::class, "connect");
$p = $m->getParameters()[0] ?? null;
if (!$p || !$p->isOptional() || $p->getDefaultValue() !== true) exit(1);
' "$SITE/$FILE"
echo 'MySQL global Hostinger: OK (preflight, apply, respaldo, idempotencia, default)'
