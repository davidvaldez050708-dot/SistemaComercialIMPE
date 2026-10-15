#!/usr/bin/env bash
# Cierre sincronizado del modal y del panel de transferencia de Recepción.
# Actualiza únicamente el shell de interfaz: NO altera WebRTC, PBX, BD ni estilos.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_CLOSE_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_CLOSE_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CLOSE_BACKUPS:-$HOME/respaldos-crm}"
BASE="adb523f075479b980b6f7d5be3188b8c47a3ff83"
TARGET="4075b6066b7ace57a2a4d436e88bf21af911c6e6"
FILE="public/javascript/telefonia_shell.js"

[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" &&
   -f "$LIVE/$FILE" && ! -L "$LIVE/$FILE" ]] || {
  echo "ERROR: repositorio o shell publicado no disponibles. PRODUCCIÓN INTACTA." >&2
  exit 1
}
for rev in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "$rev^{commit}" || {
    echo "ERROR: falta historial. Ejecuta git pull --ff-only origin main." >&2
    exit 1
  }
done

TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
mkdir -p "$TEMP/stage/public/javascript"
cp -p "$LIVE/$FILE" "$TEMP/stage/$FILE"
BEFORE="$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)"
git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$FILE" > "$TEMP/patch"
[[ -s "$TEMP/patch" ]] || {
  echo "ERROR: parche vacío. PRODUCCIÓN INTACTA." >&2; exit 1;
}
CHANGE=0
if cmp -s "$TEMP/stage/$FILE" <(git -C "$SOURCE" show "$TARGET:$FILE") ||
   (cd "$TEMP/stage" && git apply --reverse --check "$TEMP/patch" >/dev/null 2>&1); then
  echo "YA ACTUALIZADO: $FILE"
elif (cd "$TEMP/stage" && git apply --check "$TEMP/patch" >/dev/null 2>&1); then
  (cd "$TEMP/stage" && git apply "$TEMP/patch")
  echo "PARCHE COMPATIBLE: $FILE"
  CHANGE=1
else
  # Hostinger puede conservar lógica WebRTC y registro propia. No reemplazar
  # el shell ni eliminar esas personalizaciones: fusionar exclusivamente los
  # fragmentos del cierre, sobre la copia temporal y con comprobación reversible.
  FUSION="$SOURCE/tools/fusionar_cierre_transferencia_hostinger.py"
  [[ -f "$FUSION" && ! -L "$FUSION" ]] || {
    echo "ERROR: falta fusionador de cierre. Actualiza crm-preparacion." >&2
    exit 1
  }
  git -C "$SOURCE" show "$BASE:$FILE" > "$TEMP/base_shell.js"
  git -C "$SOURCE" show "$TARGET:$FILE" > "$TEMP/target_shell.js"
  python3 "$FUSION" "$TEMP/stage/$FILE" "$TEMP/base_shell.js" "$TEMP/target_shell.js"
  if [[ "$(sha256sum "$TEMP/stage/$FILE" | cut -d ' ' -f1)" != "$BEFORE" ]]; then
    CHANGE=1
  fi
fi
if command -v node >/dev/null 2>&1; then
  node --check "$TEMP/stage/$FILE"
fi
for marker in 'recentFinishedIncoming' 'finishingIncoming' \
  "transferToggle?.setAttribute('aria-expanded', 'false')" \
  'pendingOutgoingHangup' "'Llamada finalizada'"; do
  grep -Fq "$marker" "$TEMP/stage/$FILE" || {
    echo "ERROR: falta marcador $marker. PRODUCCIÓN INTACTA." >&2
    exit 1
  }
done
if [[ "$CHANGE" -eq 0 ]]; then
  echo "OK: cierre sincronizado ya publicado."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parche compatible. PRODUCCIÓN INTACTA."
  echo "Sólo cambia $FILE; no se reinicia ni reemplaza el SDK."
  exit 0
fi
[[ "$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)" == "$BEFORE" ]] || {
  echo "ERROR: archivo cambiado durante comprobación." >&2; exit 1;
}
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/cierre-transferencia-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP/$(dirname "$FILE")"
cp -p "$LIVE/$FILE" "$BACKUP/$FILE"
rollback() {
  code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    cp -p "$BACKUP/$FILE" "$LIVE/$FILE"
    echo "ERROR: cambio revertido desde $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
cp -p "$TEMP/stage/$FILE" "$LIVE/$FILE"
chmod 0644 "$LIVE/$FILE"
cmp -s "$TEMP/stage/$FILE" "$LIVE/$FILE"
if command -v node >/dev/null 2>&1; then node --check "$LIVE/$FILE"; fi
trap - EXIT
echo "OK: cierre de llamada y panel de transferencia publicados. Respaldo: $BACKUP"
echo "No se modificó Zadarma, registro de llamadas ni base de datos."
