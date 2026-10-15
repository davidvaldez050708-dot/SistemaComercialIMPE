#!/usr/bin/env bash
# UX de transferencia sin estirar el teléfono + búsqueda opcional por Caller ID.
# Sólo 5 archivos, sin operaciones SQL, PBX ni cambios a motor WebRTC.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_RECEPTION_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_RECEPTION_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_RECEPTION_BACKUPS:-$HOME/respaldos-crm}"
BASE="aa37af6778127dd0d33047d2d9282a853febb1f9"
TARGET="aa14190eeba2ac65bd170060f6ed4a512868f580"
FILES=(
  app/services/TelefoniaEnrutamientoService.php
  app/controllers/TelefoniaController.php
  app/views/telefonia/shell.php
  public/javascript/telefonia_enrutamiento.js
  public/css/telefonia_enrutamiento.css
)
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" ]] || {
  echo "ERROR: falta el repositorio o el sitio publicado." >&2; exit 1;
}
for revision in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "$revision^{commit}" || {
    echo "ERROR: falta historial de Git. Haz git pull --ff-only origin main." >&2
    exit 1
  }
done
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a MODIFIED=()
declare -A BEFORE_HASH=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: $file falta en producción o es un enlace." >&2; exit 1;
  }
  BEFORE_HASH["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  patch="$TMP/p_$i.patch"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$patch"
  [[ -s "$patch" ]] || { echo "ERROR: parche vacío: $file" >&2; exit 1; }
  if cmp -s "$TMP/stage/$file" <(git -C "$SOURCE" show "$TARGET:$file"); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    MODIFIED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file contiene cambios fuera de este ajuste. PRODUCCIÓN INTACTA." >&2
    exit 1
  fi
done
php -l "$TMP/stage/app/services/TelefoniaEnrutamientoService.php" >/dev/null
php -l "$TMP/stage/app/controllers/TelefoniaController.php" >/dev/null
php -l "$TMP/stage/app/views/telefonia/shell.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/stage/public/javascript/telefonia_enrutamiento.js"
fi
grep -Fq 'function identificarOrigen(' "$TMP/stage/app/services/TelefoniaEnrutamientoService.php"
grep -Fq 'telefono_verificado' "$TMP/stage/app/services/TelefoniaEnrutamientoService.php"
grep -Fq 'identificarOrigen($usuarioId, $rol, $telefonoOrigen)' \
  "$TMP/stage/app/controllers/TelefoniaController.php"
grep -Fq 'data-routing-identification' "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq 'data-incoming-register-finished' "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq 'IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL' "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq 'impeShellNavigation' "$TMP/stage/app/views/telefonia/shell.php"
grep -Fq 'document.body.appendChild(pane)' "$TMP/stage/public/javascript/telefonia_enrutamiento.js"
grep -Fq 'confirmedRecognition' "$TMP/stage/public/javascript/telefonia_enrutamiento.js"
grep -Fq '.impe-routing-panel.impe-routing-sidecar' "$TMP/stage/public/css/telefonia_enrutamiento.css"
if grep -Fq ':has(.impe-routing-panel' "$TMP/stage/public/css/telefonia_enrutamiento.css"; then
  echo "ERROR: CSS aún estira el teléfono." >&2
  exit 1
fi
if [[ "${#MODIFIED[@]}" -eq 0 ]]; then
  echo "OK: recepción rápida ya publicada."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: recepción rápida compatible. PRODUCCIÓN INTACTA."
  echo "Sin cambios a llamadas WebRTC, extensiones, MySQL ni grabaciones."
  exit 0
fi
for file in "${MODIFIED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${BEFORE_HASH[$file]}" ]] || {
    echo "CONFLICTO: $file cambió desde la comprobación." >&2; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/recepcion-rapida-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${MODIFIED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for file in "${MODIFIED[@]}"; do cp -p "$BACKUP/$file" "$LIVE/$file"; done
    echo "ERROR: archivos restaurados. Respaldo: $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${MODIFIED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/app/services/TelefoniaEnrutamientoService.php" >/dev/null
php -l "$LIVE/app/views/telefonia/shell.php" >/dev/null
trap - EXIT
echo "OK: recepción rápida publicada. Respaldo: $BACKUP"
echo "El teléfono y PBX quedan intactos; validar visualmente con llamada de prueba."
