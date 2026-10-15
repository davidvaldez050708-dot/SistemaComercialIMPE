#!/usr/bin/env bash
# Sólo actualiza el observador pasivo y el formulario de resultado.
# No altera motor/WebRTC/PBX, el shell histórico, CSS o MySQL.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash tools/desplegar_resultado_transferida_analista_hostinger.sh --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_RESULTADO_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_RESULTADO_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_RESULTADO_BACKUPS:-$HOME/respaldos-crm}"
BASE="bcaa4551db0bf0981900e6d6774398593f3e1331"
TARGET="cd3ea18d39c987b697ab08841ed362cf5cb6b2f6"
FILES=("public/javascript/telefonia_analista_historial_pasivo.js"
       "public/javascript/seguimiento_entrantes_analista.js")
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" ]] || {
  echo "ERROR: fuente o producción inaccesible. PRODUCCIÓN INTACTA." >&2; exit 1;
}
git -C "$SOURCE" cat-file -e "$BASE^{commit}"
git -C "$SOURCE" cat-file -e "$TARGET^{commit}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A CHECKSUM=()
for file in "${FILES[@]}"; do
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "CONFLICTO: $file no existe o es enlace. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
  CHECKSUM["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  PATCH="$TMP/$(basename "$file").patch"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$PATCH"
  [[ -s "$PATCH" ]] || { echo "ERROR: parche vacío $file." >&2; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$PATCH" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$PATCH" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$PATCH")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file personalizado no compatible. PRODUCCIÓN INTACTA." >&2; exit 1
  fi
  cmp -s "$LIVE/$file" "$TMP/stage/$file" || CHANGED+=("$file")
  if command -v node >/dev/null 2>&1; then node --check "$TMP/stage/$file"; fi
done
grep -Fq 'lastState?.answeredAtMs' "$TMP/stage/public/javascript/telefonia_analista_historial_pasivo.js"
grep -Fq 'IMPE_ANALYST_RESULT_SAVED' "$TMP/stage/public/javascript/telefonia_analista_historial_pasivo.js"
grep -Fq 'IMPE_ANALYST_RESULT_SAVED' "$TMP/stage/public/javascript/seguimiento_entrantes_analista.js"
if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: resumen y registro del Analista ya publicados."; exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: resultado de llamada compatible. PRODUCCIÓN INTACTA."
  echo "Cambiarán sólo ${#CHANGED[@]} JavaScript; WebRTC, shell y base de datos intactos."
  exit 0
fi
for file in "${FILES[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${CHECKSUM[$file]}" ]] || {
    echo "ERROR: archivo $file cambiado durante el chequeo. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/resultado-analista-transferida-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for file in "${CHANGED[@]}"; do cp -p "$BACKUP/$file" "$LIVE/$file"; done
    echo "ERROR: se restauró respaldo $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$LIVE/$file" "$TMP/stage/$file"
done
trap - EXIT
echo "OK: resultado de llamada del Analista publicado. Respaldo: $BACKUP"
