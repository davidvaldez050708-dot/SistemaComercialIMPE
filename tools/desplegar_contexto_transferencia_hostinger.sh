#!/usr/bin/env bash
# Identificación segura de Recepción y navegación a expediente.
# No se modifica el motor SIP, el SDK de Zadarma, shell ni audio.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Uso: bash $0 --check|--apply"; exit 2; }
SOURCE="${IMPE_CONTEXT_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_CONTEXT_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CONTEXT_BACKUPS:-$HOME/respaldos-crm}"
BASE="97945c31785a44e7d2fa78e4f7357fa17bc8f9db"
TARGET="9971dfeb7659ede955f26f90cad1ce4787f6e67b"
NEW="app/services/TelefoniaTransferenciaContextoService.php"
FILES=(
  "app/controllers/TelefoniaController.php"
  "public/javascript/telefonia_enrutamiento.js"
  "public/javascript/telefonia_analista_historial_pasivo.js"
  "public/javascript/seguimiento_entrantes_analista.js"
)
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" &&
   -f "$SOURCE/$NEW" ]] || {
  echo "ERROR: rutas fuente/producción no disponibles. PRODUCCIÓN INTACTA."; exit 1;
}
git -C "$SOURCE" cat-file -e "$BASE^{commit}"
git -C "$SOURCE" cat-file -e "$TARGET^{commit}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A HASH=()
for f in "${FILES[@]}"; do
  [[ -f "$LIVE/$f" && ! -L "$LIVE/$f" ]] || {
    echo "CONFLICTO: archivo no regular $f. PRODUCCIÓN INTACTA."; exit 1;
  }
  HASH["$f"]="$(sha256sum "$LIVE/$f" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$f")"
  cp -p "$LIVE/$f" "$TMP/stage/$f"
  PATCH="$TMP/$(basename "$f").patch"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$f" > "$PATCH"
  [[ -s "$PATCH" ]] || { echo "ERROR: diff vacío para $f."; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$PATCH" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $f"
  elif (cd "$TMP/stage" && git apply --check "$PATCH" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$PATCH")
    echo "PARCHE COMPATIBLE: $f"
  else
    echo "CONFLICTO: $f difiere de GitHub. PRODUCCIÓN INTACTA."; exit 1;
  fi
  cmp -s "$LIVE/$f" "$TMP/stage/$f" || CHANGED+=("$f")
done
mkdir -p "$TMP/stage/$(dirname "$NEW")"
cp -p "$SOURCE/$NEW" "$TMP/stage/$NEW"
if [[ -e "$LIVE/$NEW" || -L "$LIVE/$NEW" ]]; then
  [[ -f "$LIVE/$NEW" && ! -L "$LIVE/$NEW" ]] || {
    echo "CONFLICTO: servicio contextual inválido. PRODUCCIÓN INTACTA."; exit 1;
  }
  HASH["$NEW"]="$(sha256sum "$LIVE/$NEW" | cut -d' ' -f1)"
  cmp -s "$LIVE/$NEW" "$TMP/stage/$NEW" || {
    echo "CONFLICTO: servicio contextual previamente modificado. PRODUCCIÓN INTACTA."; exit 1;
  }
else
  CHANGED+=("$NEW")
fi
for f in "$NEW" "app/controllers/TelefoniaController.php"; do
  php -l "$TMP/stage/$f" >/dev/null
done
if command -v node >/dev/null 2>&1; then
  for f in "${FILES[@]}"; do
    [[ "$f" == *.js ]] && node --check "$TMP/stage/$f"
  done
fi
grep -Fq 'contextoTransferencia()' "$TMP/stage/app/controllers/TelefoniaController.php"
grep -Fq 'seguimiento_id:String(' "$TMP/stage/public/javascript/telefonia_enrutamiento.js"
grep -Fq 'fetchReceptionContext' "$TMP/stage/public/javascript/telefonia_analista_historial_pasivo.js"
grep -Fq 'const linked = Number(data.resultado?.seguimiento_id' "$TMP/stage/public/javascript/seguimiento_entrantes_analista.js"
if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo 'OK: identidad y navegación del resultado ya publicadas.'; exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: identificación de transferencia compatible. PRODUCCIÓN INTACTA."
  echo "Cambiarán ${#CHANGED[@]} archivos, no toca motor SIP ni WebRTC."
  exit 0
fi
for f in "${FILES[@]}"; do
  [[ "$(sha256sum "$LIVE/$f" | cut -d' ' -f1)" == "${HASH[$f]}" ]] || {
    echo "CONFLICTO: $f cambió en producción. PRODUCCIÓN INTACTA."; exit 1;
  }
done
[[ ! -e "$LIVE/$NEW" || -n "${HASH[$NEW]:-}" ]] || {
  echo "CONFLICTO: servicio apareció durante el chequeo. PRODUCCIÓN INTACTA."; exit 1;
}
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/contexto-transferencia-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for f in "${CHANGED[@]}"; do
  if [[ -f "$LIVE/$f" ]]; then
    mkdir -p "$BACKUP/$(dirname "$f")"
    cp -p "$LIVE/$f" "$BACKUP/$f"
  fi
done
rollback() {
  local code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for f in "${CHANGED[@]}"; do
      if [[ -f "$BACKUP/$f" ]]; then cp -p "$BACKUP/$f" "$LIVE/$f"
      else rm -f "$LIVE/$f"; fi
    done
    echo "ERROR: cambios revertidos desde $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for f in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$f" "$LIVE/$f"
  chmod 0644 "$LIVE/$f"
  cmp -s "$LIVE/$f" "$TMP/stage/$f"
done
php -l "$LIVE/app/controllers/TelefoniaController.php" >/dev/null
php -l "$LIVE/$NEW" >/dev/null
trap - EXIT
echo "OK: contexto de transferencia publicado. Respaldo: $BACKUP"
echo "La institución sólo se precarga si Tania la confirmó y el servidor validó su asignación."
