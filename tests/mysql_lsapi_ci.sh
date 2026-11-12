#!/usr/bin/env bash
set -euo pipefail

cd /codigo
raiz="$(mktemp -d /tmp/mysql-capacidad-XXXXXXXX)"
# El observador comprueba que el nombre del datadir es el del laboratorio.
privado="/tmp/mysql-capacidad-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
mv "$raiz" "$privado"
raiz="$privado"
socket="$raiz/mariadb.sock"
db_pid=
limpiar() {
    if [[ -n "$db_pid" ]]; then
        kill "$db_pid" 2>/dev/null || true
        wait "$db_pid" || true
    fi
    chmod -R a+rX /evidencia
    rm -rf -- "$raiz"
}
trap limpiar EXIT

export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=/codigo
mkdir "$raiz/codigo"
git archive HEAD | tar -x -C "$raiz/codigo"
cd "$raiz/codigo"
php=/usr/local/lsws/lsphp82/bin/php
lsphp=/usr/local/lsws/lsphp82/bin/lsphp
"$php" -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 2 && function_exists("mysqli_get_client_stats") ? 0 : 1);'
mariadb-install-db --no-defaults --datadir="$raiz/data" --auth-root-authentication-method=normal --skip-test-db
mariadbd --no-defaults --user=root --datadir="$raiz/data" --socket="$socket" \
    --bind-address=127.0.0.1 --port=13306 --pid-file="$raiz/mysql.pid" --log-error=/evidencia/mariadb.log &
db_pid=$!
for _ in {1..30}; do
    if mariadb-admin --no-defaults --socket="$socket" -uroot ping --silent; then break; fi
    sleep 1
done
mariadb --no-defaults --socket="$socket" -uroot <<'SQL'
CREATE DATABASE impe_test_capacidad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'impe_capacity'@'127.0.0.1' WITH MAX_USER_CONNECTIONS 75;
GRANT ALL ON impe_test_capacidad.* TO 'impe_capacity'@'127.0.0.1';
SQL
python3 -B tests/mysql_lsapi.py --php "$php" --php-lsapi "$lsphp" --mysql mariadb \
    --base "$raiz/codigo" --port 13306 --usuarios "$1" --perfil frio --salida /evidencia/humo.json
