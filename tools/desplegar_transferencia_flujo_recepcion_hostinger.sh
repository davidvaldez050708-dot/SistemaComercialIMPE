#!/usr/bin/env bash
# Corrige alternancia Institución/Prospecto, búsqueda sin estado y token
# CSRF de transferencias. NO toca PBX, extensión, CSS, grabaciones ni BD.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_RECEPTION_FIX_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_RECEPTION_FIX_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_RECEPTION_FIX_BACKUPS:-$HOME/respaldos-crm}"
BASE="470fe8d91afbf4c11a9c170a1691847d05b61341"
TARGET="3d9890350e9650ec54bdbfcb900efea37f9d9bf6"
FILES=(
  app/controllers/TelefoniaController.php
  app/services/TelefoniaEnrutamientoService.php
  public/javascript/telefonia_enrutamiento.js
)
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" ]] || {
  echo "ERROR: repositorio o producción no disponibles." >&2; exit 1;
}
for ref in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "$ref^{commit}" || {
    echo "ERROR: falta historial de Git; haz git pull --ff-only origin main." >&2
    exit 1
  }
done
TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
mkdir -p "$TEMP/stage"
declare -a MODIFIED=()
declare -A HASH=()
for i in "${!FILES[@]}"; do
  FILE="${FILES[$i]}"
  [[ -f "$LIVE/$FILE" && ! -L "$LIVE/$FILE" ]] || {
    echo "ERROR: falta el archivo regular $FILE en Hostinger." >&2; exit 1;
  }
  HASH["$FILE"]="$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)"
  mkdir -p "$TEMP/stage/$(dirname "$FILE")"
  cp -p "$LIVE/$FILE" "$TEMP/stage/$FILE"
  PATCH="$TEMP/patch_$i"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$FILE" > "$PATCH"
  [[ -s "$PATCH" ]] || { echo "ERROR: parche vacío para $FILE." >&2; exit 1; }
  if cmp -s "$TEMP/stage/$FILE" <(git -C "$SOURCE" show "$TARGET:$FILE") ||
      (cd "$TEMP/stage" && git apply --reverse --check "$PATCH" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $FILE"
  elif (cd "$TEMP/stage" && git apply --check "$PATCH" >/dev/null 2>&1); then
    (cd "$TEMP/stage" && git apply "$PATCH")
    MODIFIED+=("$FILE")
    echo "PARCHE COMPATIBLE: $FILE"
  else
    echo "CONFLICTO: $FILE tiene modificaciones incompatibles. PRODUCCIÓN INTACTA." >&2
    exit 1
  fi
done
PHP="$TEMP/stage/app/controllers/TelefoniaController.php"
SERVICE="$TEMP/stage/app/services/TelefoniaEnrutamientoService.php"
JS="$TEMP/stage/public/javascript/telefonia_enrutamiento.js"
php -l "$PHP" >/dev/null
php -l "$SERVICE" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$JS" >/dev/null; fi
for check in 'hash_equals($csrfSesion, $csrfRecibido)' "$_SESSION['csrf_token']"; do
  grep -Fq "$check" "$PHP" || {
    echo "ERROR: falta validación CSRF de transferencia. PRODUCCIÓN INTACTA." >&2; exit 1;
  }
done
grep -Fq "public function validarOrdenTransferencia()" "$PHP"
grep -Fq "public function autorizarTransferencia()" "$PHP"
grep -Fq "public function instituciones(" "$SERVICE"
grep -Fq "s.estado_id" "$SERVICE"
grep -Fq "mb_strlen(trim(\$busqueda), 'UTF-8') < 2" "$SERVICE"
grep -Fq "confirmedRecord" "$JS"
grep -Fq "phoneCheckedForCall = '';" "$JS"
grep -Fq "type === 'INSTITUCION' && text.length >= 2" "$JS"
grep -Fq "requestAuthorizedTransfer(data.token)" "$JS"
if [[ "${#MODIFIED[@]}" -eq 0 ]]; then
  echo "OK: flujo de transferencias y sesión ya corregidos."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: corrección de transferencia compatible. PRODUCCIÓN INTACTA."
  echo "Sólo tres archivos: controlador, búsqueda y panel; sin cambios en Zadarma."
  exit 0
fi
for FILE in "${MODIFIED[@]}"; do
  [[ "$(sha256sum "$LIVE/$FILE" | cut -d' ' -f1)" == "${HASH[$FILE]}" ]] || {
    echo "CONFLICTO: $FILE cambió durante las comprobaciones." >&2; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/transferencia-flujo-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for FILE in "${MODIFIED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$FILE")"
  cp -p "$LIVE/$FILE" "$BACKUP/$FILE"
done
rollback() {
  code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for FILE in "${MODIFIED[@]}"; do cp -p "$BACKUP/$FILE" "$LIVE/$FILE"; done
    echo "ERROR: se restauraron archivos desde $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for FILE in "${MODIFIED[@]}"; do
  cp -p "$TEMP/stage/$FILE" "$LIVE/$FILE"
  chmod 0644 "$LIVE/$FILE"
  cmp -s "$TEMP/stage/$FILE" "$LIVE/$FILE"
done
php -l "$LIVE/app/controllers/TelefoniaController.php" >/dev/null
php -l "$LIVE/app/services/TelefoniaEnrutamientoService.php" >/dev/null
trap - EXIT
echo "OK: identificación y token de transferencia corregidos. Respaldo: $BACKUP"
echo "Sin escrituras a base de datos, grabaciones ni motor Zadarma."
