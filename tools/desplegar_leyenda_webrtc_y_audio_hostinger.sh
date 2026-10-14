#!/usr/bin/env bash
# Sólo presentación del historial de llamadas recibidas:
# - oculta marcador técnico WebRTC, conservándolo en BD;
# - distingue grabación no vinculada de grabación inexistente.
# NO toca Zadarma, WebRTC, SIP, configuración MySQL ni base de datos.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
}
SOURCE="${IMPE_AUDIO_UI_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_AUDIO_UI_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_AUDIO_UI_BACKUPS:-$HOME/respaldos-crm}"
FILES=(
  "prueba_telefonia/api/llamadas_seguimiento.php"
  "public/javascript/seguimiento_llamadas_expediente.js"
)
BASES=(
  "c8166bf7c5136622b3ec7ca34a415c2bfb97237d"
  "348d7d616d5e63b0ff6a2c5407cdcbc06dd03831"
)
TARGETS=(
  "33732f671453c63b1104d4aaba7a427fcab140c2"
  "42acda3358033cc16abd7b8816d7879a467b72f5"
)

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: falta el repositorio o la publicación en Hostinger." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A ORIGINAL_SHA=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  for revision in "${BASES[$i]}" "${TARGETS[$i]}"; do
    git -C "$SOURCE" cat-file -e "${revision}^{commit}" || {
      echo "ERROR: faltan revisiones. Ejecuta git pull --ff-only origin main." >&2
      exit 1
    }
  done
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: archivo publicado no válido: $file" >&2
    exit 1
  }
  ORIGINAL_SHA["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
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
    echo "CONFLICTO: $file ha cambiado en producción. No se modifica el sitio." >&2
    exit 1
  fi
done

php -l "$TMP/stage/${FILES[0]}" >/dev/null
grep -Fq "'grabacion_sin_vincular' =>" "$TMP/stage/${FILES[0]}"
grep -Fq '$marcadorWebrtcSinPbx,' "$TMP/stage/${FILES[0]}"
grep -Fq "Grabación no vinculada" "$TMP/stage/${FILES[1]}"
grep -Fq "Contacto reportado" "$TMP/stage/${FILES[1]}"
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/stage/${FILES[1]}" >/dev/null
fi

if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: presentación de WebRTC y grabaciones ya corregida."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parches compatibles. PRODUCCIÓN INTACTA."
  echo "El marcador se conserva en BD. Sólo se ajusta la visualización."
  echo "Publica usando --apply."
  exit 0
fi

for file in "${CHANGED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${ORIGINAL_SHA[$file]}" ]] || {
    echo "CONFLICTO: $file cambió tras el diagnóstico. Sitio intacto." >&2
    exit 1
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/audio-webrtc-historial-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  codigo=$?
  trap - EXIT
  if [[ "$codigo" -ne 0 ]]; then
    for file in "${CHANGED[@]}"; do cp -p "$BACKUP/$file" "$LIVE/$file"; done
    echo "ERROR: publicación revertida desde $BACKUP." >&2
  fi
  exit "$codigo"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/${FILES[0]}" >/dev/null
if command -v node >/dev/null 2>&1; then node --check "$LIVE/${FILES[1]}" >/dev/null; fi
trap - EXIT
echo "OK: presentación de llamadas y grabaciones corregida. Respaldo: $BACKUP"
echo "Datos históricos, telefonía y credenciales sin modificar."
