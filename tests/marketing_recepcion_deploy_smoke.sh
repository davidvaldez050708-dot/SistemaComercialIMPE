#!/usr/bin/env bash
# Simula Hostinger partiendo de los archivos antiguos, preservando ajustes
# y ejecutando la misma publicación dos veces sin duplicación.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# El publicador histórico sólo corresponde a la época en que Recepción
# vivía en Inicio. La pantalla actual está en Mis llamadas: verificar
# esa migración real sin intentar aplicar fragmentos obsoletos.
if ! grep -Fq "require __DIR__ . '/../telefonia/recepcion_marketing.php';" "$ROOT/app/views/dashboard/marketing.php"; then
    php "$ROOT/tests/telefonia_marketing_recepcion_integrada_smoke.php"
    echo 'Marketing recepción: ruta actual validada; publicador histórico no aplicable'
    exit 0
fi
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SRC="$ROOT"
SITE="$TMP/site"
mkdir -p "$SITE" "$TMP/backups"
touch "$SITE/index.php"
files=(
    "app/views/dashboard/marketing.php"
    "app/views/telefonia/recepcion_marketing.php"
    "app/views/telefonia/host.php"
    "public/css/telefonia_recepcion_marketing.css"
    "public/css/telefonia_host.css"
    "public/javascript/telefonia_persistente.js"
    "public/javascript/telefonia_host.js"
)
bases=(
    "d240a9ff42ecb0408cd12ca642084035812866df"
    "5881cebd24d53eb7fb9bd95249319abf203d92b4"
    "0163d8a1485f46ceaad4f82de19d6119035decfa"
    "79a16325fa981c0bc47909d5c1728cc4d82cd871"
    "0ec57dd7f3b763c24bca2cf1b24a1c86ec66ce69"
    "3146adb8b47e317e323a7a908cdac4ce0358ae86"
    "f415905da19a6d4113e6a996202863f3029ff102"
)
for i in "${!files[@]}"; do
    mkdir -p "$SITE/$(dirname "${files[$i]}")"
    git -C "$ROOT" cat-file blob "${bases[$i]}" > "$SITE/${files[$i]}"
done
echo '/* PERSONALIZACION-HOSTINGER-QUE-SE-DEBE-CONSERVAR */' >> "$SITE/public/css/telefonia_recepcion_marketing.css"

run() {
    IMPE_RECEPTION_SOURCE_DIR="$SRC" \
    IMPE_RECEPTION_PUBLIC_DIR="$SITE" \
    IMPE_RECEPTION_BACKUP_DIR="$TMP/backups" \
    bash "$ROOT/tools/desplegar_marketing_recepcion_hostinger.sh"
}
run
first="$(sha256sum "$SITE/public/css/telefonia_recepcion_marketing.css" | cut -d' ' -f1)"
run
second="$(sha256sum "$SITE/public/css/telefonia_recepcion_marketing.css" | cut -d' ' -f1)"

[[ "$first" == "$second" ]]
grep -Fq 'PERSONALIZACION-HOSTINGER-QUE-SE-DEBE-CONSERVAR' "$SITE/public/css/telefonia_recepcion_marketing.css"
[[ "$(grep -Fc '/* Recepción como prioridad operativa:' "$SITE/public/css/telefonia_recepcion_marketing.css")" -eq 1 ]]
[[ "$(grep -Fc 'data-marketing-reception-tab-mode' "$SITE/app/views/telefonia/recepcion_marketing.php")" -eq 1 ]]
[[ "$(find "$TMP/backups" -type f | wc -l)" -eq 14 ]]
echo 'Marketing recepción: OK (simulación de dos despliegues y preservación de estilos locales)'
