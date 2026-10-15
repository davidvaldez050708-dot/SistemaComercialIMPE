#!/usr/bin/env bash
# Transferencia DTMF RTC: instalar sólo 4 archivos, SIN cambiar shell legacy.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo 'Uso: bash tools/desplegar_transferencia_dtmf_hostinger.sh --check|--apply' >&2; exit 2;
}
SOURCE="${IMPE_DTMF_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_DTMF_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_DTMF_BACKUPS:-$HOME/respaldos-crm}"
BASE="748dba48f095abe0a8fc84b1de178208a751c36b"
TARGET="0815b2c55fcad08db3b2d900957c4c4de34a564a"
EXISTING=(
  "app/views/telefonia/host.php"
  "public/javascript/telefonia_host.js"
  "public/javascript/telefonia_enrutamiento.js"
)
NEW="public/javascript/telefonia_webrtc_dtmf.js"
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" &&
   -f "$SOURCE/$NEW" ]] || {
  echo "ERROR: rutas inválidas. PRODUCCIÓN INTACTA." >&2; exit 1;
}
git -C "$SOURCE" cat-file -e "$BASE^{commit}"
git -C "$SOURCE" cat-file -e "$TARGET^{commit}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A HASHES=()
for file in "${EXISTING[@]}"; do
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "CONFLICTO: falta o symlink $file. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
  HASHES["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  if [[ "$file" == "app/views/telefonia/host.php" ]]; then
    python3 - "$TMP/stage/$file" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text(encoding='utf-8')
if "telefonia_webrtc_dtmf.js" in s:
    print('YA ACTUALIZADO: vista del host')
else:
    a='    <script\n        src="<?= BASE_URL ?>public/javascript/telefonia_host.js'
    if s.count(a)!=1: raise SystemExit(
        'CONFLICTO: vista host.php no tiene ancla única. PRODUCCIÓN INTACTA.')
    b="""    <script
        src="<?= BASE_URL ?>public/javascript/telefonia_webrtc_dtmf.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_webrtc_dtmf.js') ?>">
    </script>
"""
    p.write_text(s.replace(a,b+a,1),encoding='utf-8')
    print('FUSIÓN COMPATIBLE: host.php, configuración preservada')
PY
  else
    PATCH="$TMP/$(basename "$file").patch"
    git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$PATCH"
    [[ -s "$PATCH" ]] || {
      echo "ERROR: diff vacío: $file. PRODUCCIÓN INTACTA." >&2; exit 1;
    }
    if (cd "$TMP/stage" && git apply --reverse --check "$PATCH" >/dev/null 2>&1); then
      echo "YA ACTUALIZADO: $file"
    elif (cd "$TMP/stage" && git apply --check "$PATCH" >/dev/null 2>&1); then
      (cd "$TMP/stage" && git apply "$PATCH")
      echo "PARCHE COMPATIBLE: $file"
    else
      echo "CONFLICTO: $file personalizado no coincide con el parche. PRODUCCIÓN INTACTA." >&2; exit 1
    fi
  fi
  if ! cmp -s "$LIVE/$file" "$TMP/stage/$file"; then CHANGED+=("$file"); fi
done
mkdir -p "$TMP/stage/$(dirname "$NEW")"
cp -p "$SOURCE/$NEW" "$TMP/stage/$NEW"
if [[ -e "$LIVE/$NEW" || -L "$LIVE/$NEW" ]]; then
  [[ -f "$LIVE/$NEW" && ! -L "$LIVE/$NEW" ]] || {
    echo "CONFLICTO: recurso DTMF preexistente no regular. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
  HASHES["$NEW"]="$(sha256sum "$LIVE/$NEW" | cut -d' ' -f1)"
  cmp -s "$LIVE/$NEW" "$TMP/stage/$NEW" || {
    echo "CONFLICTO: archivo DTMF preexistente distinto. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
else
  CHANGED+=("$NEW")
fi

php -l "$TMP/stage/app/views/telefonia/host.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  for file in "$NEW" "public/javascript/telefonia_host.js" "public/javascript/telefonia_enrutamiento.js"; do
    node --check "$TMP/stage/$file"
  done
fi
grep -Fq 'telefonia_webrtc_dtmf.js' "$TMP/stage/app/views/telefonia/host.php"
grep -Fq 'dtmf.send(tones)' "$TMP/stage/public/javascript/telefonia_host.js"
! grep -Fq 'Abrir teclado Zadarma' "$TMP/stage/public/javascript/telefonia_enrutamiento.js"
! grep -Fq 'mostrarHostEntrante();' <(sed -n '/const sendTransferDtmf/,/const ejecutarTransferenciaAutorizada/p' "$TMP/stage/public/javascript/telefonia_host.js")

if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo 'OK: transferencia DTMF WebRTC ya publicada.'; exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: transferencia DTMF compatible. PRODUCCIÓN INTACTA."
  echo "Cambiarán ${#CHANGED[@]} archivos; telefonia_shell.js y MySQL permanecen intactos."
  exit 0
fi
for file in "${EXISTING[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${HASHES[$file]}" ]] || {
    echo "CONFLICTO: cambió $file antes de publicar. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
done
[[ ! -e "$LIVE/$NEW" || -n "${HASHES[$NEW]:-}" ]] || {
  echo "CONFLICTO: archivo nuevo apareció antes de publicar. PRODUCCIÓN INTACTA." >&2; exit 1;
}
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/transferencia-dtmf-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${EXISTING[@]}"; do
  if [[ " ${CHANGED[*]} " == *" $file "* ]]; then
    mkdir -p "$BACKUP/$(dirname "$file")"
    cp -p "$LIVE/$file" "$BACKUP/$file"
  fi
done
rollback() {
  code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for file in "${EXISTING[@]}"; do
      [[ -f "$BACKUP/$file" ]] && cp -p "$BACKUP/$file" "$LIVE/$file"
    done
    [[ -z "${HASHES[$NEW]:-}" ]] && rm -f "$LIVE/$NEW"
    echo "ERROR: revertido desde $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/app/views/telefonia/host.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  for file in "${CHANGED[@]}"; do
    [[ "$file" == *.js ]] && node --check "$LIVE/$file"
  done
fi
trap - EXIT
echo "OK: transferencia DTMF WebRTC publicada. Respaldo: $BACKUP"
echo "La transferencia real se confirma con evento PBX, no sólo por enviar los tonos."
