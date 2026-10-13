#!/usr/bin/env bash
# Sólo añade observación de timbrado SDK a los ANALISTAS en el host WebRTC.
# No modifica Marketing, SDK externo, webhooks, MySQL ni archivos de Seguimiento.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash tools/desplegar_timbrado_webRTC_analista_hostinger.sh --check|--apply" >&2
  exit 2
}
SRC="${IMPE_RING_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_RING_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_RING_BACKUPS:-$HOME/respaldos-crm}"
BASE="1d130d13b7c3a12d7affe5d4d5d6a2fcc5a7d7de"
TARGET="6e426e4612b3457fccdb7b8d90cc72549826e686"
HOST="app/views/telefonia/host.php"
ENGINE="public/javascript/telefonia_host.js"
OBSERVER="public/javascript/telefonia_sdk_entrantes_observador.js"
[[ -d "$SRC/.git" && -d "$LIVE" ]] || {
  echo "ERROR: faltan repositorio o directorio publicado." >&2
  exit 1
}
for v in "$BASE" "$TARGET"; do
  git -C "$SRC" cat-file -e "$v^{commit}" || {
    echo "ERROR: falta el commit $v. Actualiza crm-preparacion." >&2
    exit 1
  }
done
for p in "$HOST" "$ENGINE"; do
  [[ -f "$LIVE/$p" && ! -L "$LIVE/$p" ]] || {
    echo "ERROR: archivo no regular: $p" >&2
    exit 1
  }
done
[[ ! -L "$LIVE/$OBSERVER" ]] || {
  echo "ERROR: observador es enlace simbólico." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$SRC" diff --binary "$BASE" "$TARGET" -- "$HOST" "$ENGINE" "$OBSERVER" >"$TMP/ring.patch"
[[ -s "$TMP/ring.patch" ]] || {
  echo "ERROR: parche vacío." >&2
  exit 1
}
git -C "$SRC" show "$TARGET:$HOST" > "$TMP/host.php"
git -C "$SRC" show "$TARGET:$ENGINE" > "$TMP/engine.js"
git -C "$SRC" show "$TARGET:$OBSERVER" > "$TMP/observer.js"
php -l "$TMP/host.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/engine.js"
  node --check "$TMP/observer.js"
else
  # El hosting compartido no ofrece Node.js; el contenido se verifica
  # contra commits inmutables validados por GitHub Actions con Node.
  echo "AVISO: Node.js no está disponible en SSH; la sintaxis JS se validó en GitHub Actions."
fi
DEPLOYED=0
if (cd "$LIVE" && git apply --reverse --check "$TMP/ring.patch" >/dev/null 2>&1); then
  DEPLOYED=1
elif ! (cd "$LIVE" && git apply --check "$TMP/ring.patch" >/dev/null 2>&1); then
  echo "CONFLICTO: el host en producción tiene modificaciones no compatibles." >&2
  echo "No se modificó nada. Compárteme este mensaje para reconciliar." >&2
  exit 1
fi

if [[ "$DEPLOYED" == 1 ]]; then
  if [[ "$MODE" == "--apply" ]]; then
    # Los recursos JavaScript nuevos DEBEN poder leerse por HTTP:
    # umask 077 durante git apply podría generar 600 y provocar 403.
    chmod 0644 "$LIVE/$ENGINE" "$LIVE/$OBSERVER"
  fi
  echo "OK: observador de timbrado para Analistas ya publicado."
  stat -c 'Permisos de %n: %a' "$LIVE/$ENGINE" "$LIVE/$OBSERVER"
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parche compatible. Sólo lectura. Puedes ejecutar --apply."
  exit 0
fi

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/timbrado-sdk-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP/$(dirname "$HOST")" "$BACKUP/$(dirname "$ENGINE")"
cp -p "$LIVE/$HOST" "$BACKUP/$HOST"
cp -p "$LIVE/$ENGINE" "$BACKUP/$ENGINE"
rollback() {
  local code=$?
  if [[ "$code" -ne 0 ]]; then
    cp -p "$BACKUP/$HOST" "$LIVE/$HOST"
    cp -p "$BACKUP/$ENGINE" "$LIVE/$ENGINE"
    rm -f "$LIVE/$OBSERVER"
    echo "ERROR: cambios revertidos; respaldo en $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
(cd "$LIVE" && git apply "$TMP/ring.patch")
# Asegurar acceso público al JS sin relajar secretos ni configuraciones.
chmod 0644 "$LIVE/$ENGINE" "$LIVE/$OBSERVER"
php -l "$LIVE/$HOST" >/dev/null
(cd "$LIVE" && git apply --reverse --check "$TMP/ring.patch")
trap - EXIT
echo "OK: detector de timbrado WebRTC de Analistas publicado."
echo "Respaldo: $BACKUP"
echo "Sin cambios: Marketing, persistente.js, shell.js, webhook, PBX y MySQL."
