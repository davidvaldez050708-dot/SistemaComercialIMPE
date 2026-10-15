#!/usr/bin/env bash
# Ajuste visual EXCLUSIVO de los selects del panel de transferencia.
# No modifica JS, PHP, llamadas, permisos, BD ni proveedor telefónico.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_SELECT_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_SELECT_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_SELECT_BACKUPS:-$HOME/respaldos-crm}"
BASE="393a1115c65ff81b99cbdec0f48de64df714bf06"
TARGET="975782be5b65d7d657ac4b58d8cea8478f9911a0"
FILE="public/css/telefonia_enrutamiento.css"
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" &&
   -f "$LIVE/$FILE" && ! -L "$LIVE" && ! -L "$LIVE/$FILE" ]] || {
  echo "ERROR: no se encontró la hoja de estilos publicada o el repositorio." >&2
  exit 1
}
for revision in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "$revision^{commit}" || {
    echo "ERROR: falta historial Git; actualiza crm-preparacion." >&2; exit 1;
  }
done
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage/public/css"
cp -p "$LIVE/$FILE" "$TMP/stage/$FILE"
INITIAL_SHA="$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)"
PATCH="$TMP/select.patch"
git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$FILE" > "$PATCH"
[[ -s "$PATCH" ]] || { echo "ERROR: no existe parche visual." >&2; exit 1; }
if cmp -s "$TMP/stage/$FILE" <(git -C "$SOURCE" show "$TARGET:$FILE") ||
  (cd "$TMP/stage" && git apply --reverse --check "$PATCH" >/dev/null 2>&1); then
  echo "YA ACTUALIZADO: selectores de transferencia."
  exit 0
elif (cd "$TMP/stage" && git apply --check "$PATCH" >/dev/null 2>&1); then
  (cd "$TMP/stage" && git apply "$PATCH")
  echo "PARCHE COMPATIBLE: estilos de selector, flecha y hover."
else
  echo "CONFLICTO: CSS publicado con modificaciones desconocidas. PRODUCCIÓN INTACTA." >&2
  exit 1
fi
# Preflight visual: sólo seleccionar regla CSS, jamás modificar overlay/teléfono.
CSS="$TMP/stage/$FILE"
for token in '.impe-routing-panel.impe-routing-sidecar' \
  '.impe-routing-panel .impe-routing-field select:hover:not(:disabled)' \
  '.impe-routing-panel .impe-routing-field select:focus' \
  '-webkit-appearance: none' '-moz-appearance: none' \
  'appearance: none' 'background-image: url("data:image/svg+xml' \
  'background-position: right 13px center' 'padding: 8px 40px 8px 11px'; do
  grep -Fq -- "$token" "$CSS" || {
    echo "ERROR: falta regla visual $token. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
done
if [[ "$MODE" == "--check" ]]; then
  echo "OK: ajuste visual de selectores compatible. PRODUCCIÓN INTACTA."
  echo "Sólo CSS, sin cambios al motor telefónico, transferencias o MySQL."
  exit 0
fi
[[ "$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)" == "$INITIAL_SHA" ]] || {
  echo "CONFLICTO: CSS cambió durante la comprobación. PRODUCCIÓN INTACTA." >&2
  exit 1
}
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/select-telefonia-$(date +%Y%m%d-%H%M%S)-$$.css"
cp -p "$LIVE/$FILE" "$BACKUP"
rollback() {
  exit_code=$?
  trap - EXIT
  if [[ "$exit_code" -ne 0 ]]; then
    cp -p "$BACKUP" "$LIVE/$FILE"
    echo "ERROR: respaldo restaurado $BACKUP" >&2
  fi
  exit "$exit_code"
}
trap rollback EXIT
cp -p "$CSS" "$LIVE/$FILE"
chmod 0644 "$LIVE/$FILE"
cmp -s "$CSS" "$LIVE/$FILE"
trap - EXIT
echo "OK: estilo de selectores corregido. Respaldo: $BACKUP"
echo "No se alteraron llamadas, extensiones, CRM ni base de datos."
