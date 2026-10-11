#!/usr/bin/env bash
# Reparación visual puntual, segura e idempotente del módulo Extensiones.
# No toca PHP, MySQL, tokens, permisos ni telefonía operativa.
set -euo pipefail

ORIGEN="${IMPE_TELEFONIA_SOURCE_DIR:-$HOME/crm-preparacion}"
DESTINO="${IMPE_TELEFONIA_PUBLIC_DIR:-$HOME/domains/fundacionrededumex.org/public_html}"
RESPALDOS="${IMPE_TELEFONIA_BACKUP_DIR:-$HOME/respaldos-crm}"
REL="public/css/telefonia.css"
INICIO="/* TELEFONIA_VISUAL_COMPACTA_V2"

[[ -f "$ORIGEN/$REL" && -f "$DESTINO/$REL" ]] || {
    echo "ERROR: no se encuentra telefonia.css en el repositorio o en producción." >&2
    exit 1
}
grep -Fq "$INICIO" "$ORIGEN/$REL" || {
    echo "ERROR: actualiza ~/crm-preparacion con git pull antes de ejecutar." >&2
    exit 1
}

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
# Anexar el bloque específico, sin reemplazar los cambios CSS de Hostinger.
# Si ya existe y coincide, no se duplica; si fue modificado, aborta.
php -r '
    $fuente = file_get_contents($argv[1]);
    $produccion = file_get_contents($argv[2]);
    $inicio = $argv[4];
    $pos = strpos($fuente, $inicio);
    if ($pos === false) {
        fwrite(STDERR, "No se encontró el parche visual en Git.\n");
        exit(2);
    }
    $bloque = substr($fuente, $pos);
    if (strpos($produccion, $inicio) !== false) {
        if (strpos($produccion, $bloque) === false) {
            fwrite(STDERR, "Hay una versión diferente del parche en Hostinger. No se modificó nada.\n");
            exit(2);
        }
        $nuevo = $produccion;
    } else {
        $nuevo = rtrim($produccion) . "\n\n" . ltrim($bloque);
    }
    if (file_put_contents($argv[3], $nuevo) === false) {
        exit(2);
    }
' "$ORIGEN/$REL" "$DESTINO/$REL" "$TMP" "$INICIO"

grep -Fq 'max-width: 620px !important;' "$TMP"
grep -Fq 'max-width: 440px !important;' "$TMP"
grep -Fq 'justify-content: center !important;' "$TMP"
if cmp -s "$TMP" "$DESTINO/$REL"; then
    echo "OK: la corrección visual ya estaba publicada. Sin cambios."
    exit 0
fi

mkdir -p "$RESPALDOS"
COPIA="$(mktemp "$RESPALDOS/telefonia-css-antes-v2-$(date +%Y%m%d-%H%M%S)-XXXXXX.css")"
cp -p "$DESTINO/$REL" "$COPIA"
cp "$TMP" "$DESTINO/$REL"

grep -Fq "$INICIO" "$DESTINO/$REL"
echo "OK: avatar centrado y modales compactos publicados."
echo "Respaldo: $COPIA"
echo "Verificación: $(grep -Fc "$INICIO" "$DESTINO/$REL") bloque visual en producción."
echo "Actualiza el navegador con Ctrl+F5 en Telefonía > Extensiones."
