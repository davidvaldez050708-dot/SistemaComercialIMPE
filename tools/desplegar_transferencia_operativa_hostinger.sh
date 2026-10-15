#!/usr/bin/env bash
# Despliegue selectivo: NO reemplaza WebRTC ni toca DB / PBX.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Uso: $0 --check|--apply" >&2; exit 2; }
SOURCE="${IMPE_OPERATIVE_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_OPERATIVE_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_OPERATIVE_BACKUPS:-$HOME/respaldos-crm}"
BASE="03c0028e9bb5b62c91f1ebba0266fae4eca521f4"
TARGET="748dba48f095abe0a8fc84b1de178208a751c36b"
MERGER="tools/fusionar_telefonia_transferencia_operativa_hostinger.py"
FILES=("app/views/telefonia/shell.php" "public/javascript/telefonia_shell.js"
       "public/javascript/telefonia_enrutamiento.js" "public/javascript/telefonia_host.js")
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" &&
   -f "$SOURCE/$MERGER" && ! -L "$SOURCE/$MERGER" ]] || {
  echo "ERROR: archivo fuente o producción no disponible. PRODUCCIÓN INTACTA." >&2; exit 1;
}
git -C "$SOURCE" cat-file -e "$BASE^{commit}"
git -C "$SOURCE" cat-file -e "$TARGET^{commit}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A HASHES=()
for file in "${FILES[@]}"; do
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: falta $file publicado. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
  HASHES["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  case "$file" in
    app/views/telefonia/shell.php)
      python3 "$SOURCE/$MERGER" "$TMP/stage/$file" view ;;
    public/javascript/telefonia_shell.js)
      python3 "$SOURCE/$MERGER" "$TMP/stage/$file" shell ;;
    *)
      PATCH="$TMP/$(basename "$file").patch"
      git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$PATCH"
      [[ -s "$PATCH" ]] || { echo "ERROR: parche vacío $file." >&2; exit 1; }
      if (cd "$TMP/stage" && git apply --reverse --check "$PATCH" >/dev/null 2>&1); then
        echo "YA ACTUALIZADO: $file"
      elif (cd "$TMP/stage" && git apply --check "$PATCH" >/dev/null 2>&1); then
        (cd "$TMP/stage" && git apply "$PATCH")
        echo "PARCHE COMPATIBLE: $file"
      else
        echo "CONFLICTO: $file personalizado incompatible. PRODUCCIÓN INTACTA." >&2; exit 1
      fi ;;
  esac
  if ! cmp -s "$LIVE/$file" "$TMP/stage/$file"; then CHANGED+=("$file"); fi
done
php -l "$TMP/stage/app/views/telefonia/shell.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  for file in "${FILES[@]}"; do
    [[ "$file" == *.js ]] && node --check "$TMP/stage/$file"
  done
fi
grep -Fq "'alwaysReceive' =>" "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq "sendPhoneCommand('SHOW_CONTROLS');" "$TMP/stage/public/javascript/telefonia_shell.js"
grep -Fq "Abrir teclado Zadarma" "$TMP/stage/public/javascript/telefonia_enrutamiento.js"
grep -Fq "await sendTransferDtmf(" "$TMP/stage/public/javascript/telefonia_host.js"
if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: transferencia asistida ya publicada."; exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: transferencia compatible. PRODUCCIÓN INTACTA."
  echo "Cambian sólo ${#CHANGED[@]} archivos telefónicos; no cambia MySQL."; exit 0
fi
for file in "${CHANGED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${HASHES[$file]}" ]] || {
    echo "ERROR: $file cambió entre check y apply." >&2; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/transferencia-operativa-$(date +%Y%m%d-%H%M%S)-$$"
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
    echo "ERROR: revertido desde $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$LIVE/$file" "$TMP/stage/$file"
done
php -l "$LIVE/app/views/telefonia/shell.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  for file in "${CHANGED[@]}"; do
    [[ "$file" == *.js ]] && node --check "$LIVE/$file"
  done
fi
trap - EXIT
echo "OK: transferencia asistida publicada. Respaldo: $BACKUP"
echo "Solo PBX confirma transferencia y contestación del destinatario."
