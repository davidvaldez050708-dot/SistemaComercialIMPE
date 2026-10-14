#!/usr/bin/env bash
# Quita sección temporal de Grabaciones en detalle de Seguimiento.
# Únicamente dos JS visuales; NO modifica DB, motor telefónico o PHP.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_GRABACIONES_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_GRABACIONES_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_GRABACIONES_BACKUPS:-$HOME/respaldos-crm}"
FILES=("public/javascript/seguimiento_llamadas_expediente.js" "public/javascript/seguimiento_llamadas_desplegable.js")
BASES=("4a4f9857d5bcec2e73e34f93e50aa97ba96afdc1" "ad2703830405c3fe32ecbe0b104a7539c3d92c5c")
TARGETS=("ad2703830405c3fe32ecbe0b104a7539c3d92c5c" "825fc857910f907d99af8fcb0ae89d9e8e5cea72")

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: falta repositorio o carpeta publicada." >&2; exit 1;
}
TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
mkdir -p "$TEMP/site"
declare -a CHANGED=()
declare -A HASH=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  for rev in "${BASES[$i]}" "${TARGETS[$i]}"; do
    git -C "$SOURCE" cat-file -e "${rev}^{commit}" || {
      echo "ERROR: faltan revisiones de GitHub; ejecuta git pull --ff-only origin main" >&2
      exit 1
    }
  done
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: archivo publicado ausente o simbólico: $file" >&2; exit 1;
  }
  HASH["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TEMP/site/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TEMP/site/$file"
  patch="$TEMP/$i.patch"
  git -C "$SOURCE" diff --binary "${BASES[$i]}" "${TARGETS[$i]}" -- "$file" > "$patch"
  [[ -s "$patch" ]] || { echo "ERROR: parche vacío." >&2; exit 1; }
  if (cd "$TEMP/site" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TEMP/site" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TEMP/site" && git apply "$patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file no coincide con la base. No se modificó producción." >&2
    exit 1
  fi
done
for file in "${FILES[@]}"; do
  if command -v node >/dev/null 2>&1; then node --check "$TEMP/site/$file"; fi
done
! grep -Fq 'linkage-call-recordings-section' "$TEMP/site/public/javascript/seguimiento_llamadas_expediente.js"
grep -Fq 'data-call-summary-recordings' "$TEMP/site/public/javascript/seguimiento_llamadas_expediente.js"
grep -Fq 'convertirBoton(tarjeta, llamada)' "$TEMP/site/public/javascript/seguimiento_llamadas_desplegable.js"
if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: el bloque duplicado ya fue eliminado."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parches compatibles. PRODUCCIÓN INTACTA."
  echo "Se conservarán las grabaciones en las tarjetas del historial."
  exit 0
fi
for file in "${CHANGED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${HASH[$file]}" ]] || {
    echo "ERROR: el archivo cambió desde la comprobación. Producción intacta." >&2; exit 1;
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/grabaciones-sin-parpadeo-$(date +%Y%m%d-%H%M%S)-$$"
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
    echo "ERROR: publicación revertida. Respaldo $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TEMP/site/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TEMP/site/$file" "$LIVE/$file"
  if command -v node >/dev/null 2>&1; then node --check "$LIVE/$file"; fi
done
trap - EXIT
echo "OK: grabaciones sin parpadeo. Respaldo: $BACKUP"
echo "Intactos: MySQL, recepción, extensiones, motor WebRTC y Zadarma."
