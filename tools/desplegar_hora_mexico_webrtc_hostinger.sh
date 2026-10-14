#!/usr/bin/env bash
# Corrige únicamente la presentación de fechas WEBRTC UTC en el expediente.
# No modifica datos de MySQL, IDs PBX, telefonía SIP ni grabaciones.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_HORA_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_HORA_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_HORA_BACKUPS:-$HOME/respaldos-crm}"
HELPER="app/helpers/TelefoniaFechaHoraHelper.php"
FILES=(
  "app/services/TelefoniaAnalistaWebrtcService.php"
  "prueba_telefonia/api/llamadas_seguimiento.php"
  "app/controllers/SeguimientoVinculacionController.php"
)
BASES=(
  "089bea14b834206c14758c49e93b32a62004d443"
  "9a02aa20e7e96c3360a7feeeee3cc22579481986"
  "95e5ec098b73efcc83df367e95e2fcdfc0e53ce4"
)
TARGETS=(
  "9a02aa20e7e96c3360a7feeeee3cc22579481986"
  "95e5ec098b73efcc83df367e95e2fcdfc0e53ce4"
  "35a66c7bb29305b50ff345ea665e3c2304784b09"
)

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: falta el repositorio o el sitio público." >&2; exit 1;
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage/$(dirname "$HELPER")"
[[ -f "$SOURCE/$HELPER" && ! -L "$SOURCE/$HELPER" ]] || {
  echo "ERROR: falta helper de fechas en preparación." >&2; exit 1;
}
cp -p "$SOURCE/$HELPER" "$TMP/stage/$HELPER"
if [[ -e "$LIVE/$HELPER" ]]; then
  [[ -f "$LIVE/$HELPER" && ! -L "$LIVE/$HELPER" ]] || {
    echo "CONFLICTO: helper publicado no es archivo regular." >&2; exit 1;
  }
  [[ "$(sha256sum "$LIVE/$HELPER" | cut -d' ' -f1)" == "$(sha256sum "$TMP/stage/$HELPER" | cut -d' ' -f1)" ]] || {
    echo "CONFLICTO: helper ya existente difiere del preparado. Sitio intacto." >&2; exit 1;
  }
  HELPER_NUEVO=0
else
  HELPER_NUEVO=1
fi

declare -a CHANGED=()
declare -A HASH=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: archivo no encontrado o simbólico: $file" >&2; exit 1;
  }
  for revision in "${BASES[$i]}" "${TARGETS[$i]}"; do
    git -C "$SOURCE" cat-file -e "${revision}^{commit}" || {
      echo "ERROR: falta historial Git; ejecuta git pull --ff-only origin main." >&2; exit 1;
    }
  done
  HASH["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  patch="$TMP/$i.patch"
  git -C "$SOURCE" diff --binary "${BASES[$i]}" "${TARGETS[$i]}" -- "$file" > "$patch"
  [[ -s "$patch" ]] || { echo "ERROR: parche vacío para $file" >&2; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file no coincide con el código esperado. Producción intacta." >&2
    exit 1
  fi
done

php -l "$TMP/stage/$HELPER" >/dev/null
for file in "${FILES[@]}"; do php -l "$TMP/stage/$file" >/dev/null; done
grep -Fq 'UTC_TIMESTAMP()' "$TMP/stage/${FILES[0]}"
grep -Fq 'TelefoniaFechaHoraHelper::normalizarInteraccion' "$TMP/stage/${FILES[1]}"
grep -Fq "TelefoniaFechaHoraHelper::class" "$TMP/stage/${FILES[2]}"

if [[ "${#CHANGED[@]}" -eq 0 && "$HELPER_NUEVO" -eq 0 ]]; then
  echo "OK: zona horaria WebRTC ya corregida."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parches compatibles. PRODUCCIÓN INTACTA."
  echo "Únicamente UTC → America/Mexico_City para sesiones WebRTC."
  echo "El historial de datos, llamadas SIP y audios no se modifican."
  exit 0
fi
for file in "${CHANGED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${HASH[$file]}" ]] || {
    echo "CONFLICTO: cambió $file; operación cancelada." >&2; exit 1;
  }
done

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/hora-webrtc-mexico-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    for file in "${CHANGED[@]}"; do cp -p "$BACKUP/$file" "$LIVE/$file"; done
    if [[ "$HELPER_NUEVO" -eq 1 ]]; then rm -f "$LIVE/$HELPER"; fi
    echo "ERROR: publicación revertida. Respaldo: $BACKUP" >&2
  fi
  exit "$status"
}
trap rollback EXIT
if [[ "$HELPER_NUEVO" -eq 1 ]]; then
  mkdir -p "$LIVE/$(dirname "$HELPER")"
  cp -p "$TMP/stage/$HELPER" "$LIVE/$HELPER"
  chmod 0644 "$LIVE/$HELPER"
fi
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/$HELPER" >/dev/null
for file in "${FILES[@]}"; do php -l "$LIVE/$file" >/dev/null; done
trap - EXIT
echo "OK: hora WebRTC de Ciudad de México publicada. Respaldo: $BACKUP"
echo "No se modificaron datos MySQL, webhooks, SDK JS ni grabaciones."
