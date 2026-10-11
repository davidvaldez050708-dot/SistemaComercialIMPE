#!/usr/bin/env bash
# Simula Hostinger con el CSS anterior (sin el bloque v2)
# y un ajuste local que debe sobrevivir a dos ejecuciones.
set -euo pipefail
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/public/css" "$TMP/backups"
CSS="$TMP/public/css/telefonia.css"

# Escenario donde el CSS de Hostinger todavía tenía el parche anterior.
php -r '
    $css = file_get_contents($argv[1]);
    $inicio = strpos($css, "/* TELEFONIA_VISUAL_COMPACTA_V2");
    if ($inicio === false) exit(2);
    file_put_contents($argv[2], substr($css, 0, $inicio));
' "$SRC/public/css/telefonia.css" "$CSS"
echo '/* ajuste-privado-hostinger-que-no-debe-perderse */' >> "$CSS"

IMPE_TELEFONIA_SOURCE_DIR="$SRC" \
IMPE_TELEFONIA_PUBLIC_DIR="$TMP" \
IMPE_TELEFONIA_BACKUP_DIR="$TMP/backups" \
bash "$SRC/tools/desplegar_telefonia_visual_v2_hostinger.sh"

PRIMER_SHA="$(sha256sum "$CSS" | cut -d' ' -f1)"

IMPE_TELEFONIA_SOURCE_DIR="$SRC" \
IMPE_TELEFONIA_PUBLIC_DIR="$TMP" \
IMPE_TELEFONIA_BACKUP_DIR="$TMP/backups" \
bash "$SRC/tools/desplegar_telefonia_visual_v2_hostinger.sh"

SEGUNDO_SHA="$(sha256sum "$CSS" | cut -d' ' -f1)"
[[ "$PRIMER_SHA" == "$SEGUNDO_SHA" ]]
grep -Fq 'ajuste-privado-hostinger-que-no-debe-perderse' "$CSS"
[[ "$(grep -Fc '/* TELEFONIA_VISUAL_COMPACTA_V2' "$CSS")" -eq 1 ]]
[[ "$(find "$TMP/backups" -type f -name '*.css' | wc -l)" -eq 1 ]]
echo "Telefonía visual v2: publicación simulada OK (respaldo, idempotencia, CSS previo intacto)"
