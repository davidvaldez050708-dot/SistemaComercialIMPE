#!/usr/bin/env bash
# Cambia SÓLO el modo predeterminado de conexión MySQL para todo el CRM.
# No publica cambios de telefonía, base de datos, secretos o vistas.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi

SOURCE="${IMPE_GLOBAL_MYSQL_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_GLOBAL_MYSQL_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_GLOBAL_MYSQL_BACKUPS:-$HOME/respaldos-crm}"
FILE="config/db_connection.php"
BASE="39a0cd1f3d50a7097b2dc6111250de350b5dded4"
TARGET="5e68b52138b8f63a3c91e829ae7b3dfbebc3c9f1"

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: repositorio de preparación o directorio público no disponible." >&2
  exit 1
}
[[ -f "$LIVE/$FILE" && ! -L "$LIVE/$FILE" ]] || {
  echo "ERROR: no se encontró el archivo publicado o es enlace simbólico: $FILE" >&2
  exit 1
}
for rev in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "${rev}^{commit}" || {
    echo "ERROR: faltan revisiones; ejecuta git pull --ff-only origin main." >&2
    exit 1
  }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage/config"
cp -p "$LIVE/$FILE" "$TMP/stage/$FILE"
ORIGINAL_SHA="$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)"
git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$FILE" > "$TMP/global.patch"
[[ -s "$TMP/global.patch" ]] || { echo "ERROR: parche vacío." >&2; exit 1; }
CAMBIO=0
if (cd "$TMP/stage" && git apply --reverse --check "$TMP/global.patch" >/dev/null 2>&1); then
  echo "YA ACTUALIZADO: $FILE"
elif (cd "$TMP/stage" && git apply --check "$TMP/global.patch" >/dev/null 2>&1); then
  (cd "$TMP/stage" && git apply "$TMP/global.patch")
  CAMBIO=1
  echo "PARCHE COMPATIBLE: $FILE"
else
  echo "CONFLICTO: $FILE no coincide con el parche. Producción intacta." >&2
  exit 1
fi

php -l "$TMP/stage/$FILE" >/dev/null
grep -Fq 'public function connect(bool $persistente = true)' "$TMP/stage/$FILE"
grep -Fq "str_starts_with(" "$TMP/stage/$FILE"
grep -Fq "'p:' . " "$TMP/stage/$FILE"
grep -Fq 'DB_PASSWORD,' "$TMP/stage/$FILE"
if [[ "$CAMBIO" -eq 0 ]]; then
  echo "OK: conexiones MySQL persistentes ya habilitadas globalmente."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parche compatible. PRODUCCIÓN INTACTA."
  echo "Cambio único: conexión MySQL persistente por defecto, opt-out connect(false)."
  exit 0
fi

[[ "$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)" == "$ORIGINAL_SHA" ]] || {
  echo "CONFLICTO: el archivo cambió durante la comprobación." >&2; exit 1;
}
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/mysql-global-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP/config"
cp -p "$LIVE/$FILE" "$BACKUP/$FILE"
rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    cp -p "$BACKUP/$FILE" "$LIVE/$FILE"
    echo "ERROR: restaurado desde $BACKUP" >&2
  fi
  exit "$status"
}
trap rollback EXIT
cp -p "$TMP/stage/$FILE" "$LIVE/$FILE"
cmp -s "$TMP/stage/$FILE" "$LIVE/$FILE"
php -l "$LIVE/$FILE" >/dev/null
trap - EXIT

echo "OK: conexión MySQL persistente global publicada. Respaldo: $BACKUP"
echo "Sin cambios en datos, contraseñas, grabaciones, extensiones o SDK WebRTC."
