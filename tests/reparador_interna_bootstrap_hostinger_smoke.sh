#!/usr/bin/env bash
# Previene fallo fatal 255: helper Zadarma cargado desde dos rutas distintas.
# No ejecuta SQL ni llama Zadarma.
set -euo pipefail
cd "$(dirname "$0")/.."
REPAIR="tools/reparar_prueba_interna_100_101.php"
HELPER="app/helpers/ZadarmaPbxIdentifierHelper.php"
SERVICE="app/services/TelefoniaInternaGrabacionService.php"
php -l "$REPAIR" >/dev/null
php -l "$HELPER" >/dev/null
php -l "$SERVICE" >/dev/null

# La herramienta CLI debe importar SOLO desde la ruta de publicación.
grep -Fq "require_once \$root . '/app/helpers/ZadarmaPbxIdentifierHelper.php';" "$REPAIR"
if grep -Fq "__DIR__ . '/../app/helpers/ZadarmaPbxIdentifierHelper.php'" "$REPAIR"; then
  echo "ERROR: se detectó una segunda ruta de carga del helper." >&2
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PUBLISHED="$TMP/public_html"
mkdir -p "$PUBLISHED/app/helpers" "$PUBLISHED/app/services"
cp "$HELPER" "$PUBLISHED/app/helpers/ZadarmaPbxIdentifierHelper.php"
cp "$SERVICE" "$PUBLISHED/app/services/TelefoniaInternaGrabacionService.php"

# Emular la misma secuencia de includes que ejecuta el reparador en Hostinger.
php -d display_errors=stderr -d error_reporting=-1 -r '
    $root = $argv[1];
    require_once $root . "/app/services/TelefoniaInternaGrabacionService.php";
    require_once $root . "/app/helpers/ZadarmaPbxIdentifierHelper.php";
    if (!class_exists("TelefoniaInternaGrabacionService", false) ||
        !class_exists("ZadarmaPbxIdentifierHelper", false)) {
        exit(2);
    }
    echo "Bootstrap: clases cargadas exactamente una vez.\n";
' "$PUBLISHED"

echo "Reparador Hostinger: OK (sin doble clase, sin BD ni API)."
