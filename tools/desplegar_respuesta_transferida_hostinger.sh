#!/usr/bin/env bash
# Corrige exclusivamente la aceptación de una entrante SDK de un Analista.
# NO toca transferencia DTMF, host de producción ajeno, modal shell ni BD.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Uso: bash $0 --check|--apply"; exit 2; }
SOURCE="${IMPE_ANSWER_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_ANSWER_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_ANSWER_BACKUPS:-$HOME/respaldos-crm}"
BASE="7fe4a57078380cac76af428e88e45a17e1960ae5"
TARGET="64f9d2a1a2c3cbb78c8d39fd185f147d150609cf"
FILES=("public/javascript/telefonia_host.js" "public/javascript/telefonia_sdk_entrantes_observador.js")
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" ]] || {
  echo "ERROR: rutas inválidas. PRODUCCIÓN INTACTA."; exit 1;
}
git -C "$SOURCE" cat-file -e "$BASE^{commit}"
git -C "$SOURCE" cat-file -e "$TARGET^{commit}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A HASHES=()
for f in "${FILES[@]}"; do
  [[ -f "$LIVE/$f" && ! -L "$LIVE/$f" ]] || {
    echo "CONFLICTO: falta o es enlace $f. PRODUCCIÓN INTACTA."; exit 1;
  }
  HASHES["$f"]="$(sha256sum "$LIVE/$f" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$f")"
  cp -p "$LIVE/$f" "$TMP/stage/$f"
  p="$TMP/$(basename "$f").patch"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$f" > "$p"
  [[ -s "$p" ]] || { echo "ERROR: parche vacío $f"; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$p" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $f"
  elif (cd "$TMP/stage" && git apply --check "$p" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$p")
    echo "PARCHE COMPATIBLE: $f"
  else
    echo "CONFLICTO: $f personalizado. PRODUCCIÓN INTACTA."; exit 1
  fi
  cmp -s "$LIVE/$f" "$TMP/stage/$f" || CHANGED+=("$f")
  if command -v node >/dev/null 2>&1; then node --check "$TMP/stage/$f"; fi
done
grep -Fq "onConnected: function ()" "$TMP/stage/public/javascript/telefonia_host.js"
grep -Fq "markAnswerRequested" "$TMP/stage/public/javascript/telefonia_sdk_entrantes_observador.js"
if [[ "${#CHANGED[@]}" == 0 ]]; then echo "OK: recepción WebRTC ya actualizada."; exit 0; fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: recepción transferida compatible. PRODUCCIÓN INTACTA."
  echo "Sólo ${#CHANGED[@]} archivos del control entrante; DTMF y shell intactos."
  exit 0
fi
for f in "${FILES[@]}"; do
  [[ "$(sha256sum "$LIVE/$f" | cut -d' ' -f1)" == "${HASHES[$f]}" ]] || {
    echo "CONFLICTO: cambió $f durante la verificación. PRODUCCIÓN INTACTA."; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/responder-transferida-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for f in "${CHANGED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$f")"
  cp -p "$LIVE/$f" "$BACKUP/$f"
done
rollback() {
  local code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for f in "${CHANGED[@]}"; do cp -p "$BACKUP/$f" "$LIVE/$f"; done
    echo "ERROR: revertido desde $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for f in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$f" "$LIVE/$f"
  chmod 0644 "$LIVE/$f"
  cmp -s "$LIVE/$f" "$TMP/stage/$f"
done
trap - EXIT
echo "OK: aceptación de transferencia publicada. Respaldo: $BACKUP"
