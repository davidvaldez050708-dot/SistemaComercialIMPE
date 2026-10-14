#!/usr/bin/env bash
# Corrección del formulario Registrar resultado (scroll + aviso WebRTC).
# Sólo 2 archivos de Seguimiento. No toca host WebRTC, shell, SDK ni BD.
set -euo pipefail
umask 077

MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi
SOURCE="${IMPE_RESULT_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_RESULT_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_RESULT_BACKUPS:-$HOME/respaldos-crm}"
BASE="488628f758e60b982ee09386504f2cd025b520be"
TARGET="b3a817802db581da3f8f8c49e365c7e7f44f9b54"
FILES=(
  "app/views/seguimiento_vinculacion/llamadas_entrantes.php"
  "public/css/seguimiento_entrantes_analista.css"
)

[[ -d "$SOURCE/.git" && -d "$LIVE" ]] || {
  echo "ERROR: falta repositorio de preparación o directorio público." >&2
  exit 1
}
for revision in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "${revision}^{commit}" || {
    echo "ERROR: falta $revision. Ejecuta git pull --ff-only origin main." >&2
    exit 1
  }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A ORIGINAL_SHA=()
for file in "${FILES[@]}"; do
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: falta un archivo publicado o es enlace simbólico: $file" >&2
    exit 1
  }
  ORIGINAL_SHA["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$TMP/one.patch"
  [[ -s "$TMP/one.patch" ]] || {
    echo "ERROR: parche vacío para $file" >&2
    exit 1
  }
  if (cd "$TMP/stage" && git apply --reverse --check "$TMP/one.patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$TMP/one.patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$TMP/one.patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file tiene modificaciones incompatibles." >&2
    echo "Producción permanece intacta. Comparte esta salida." >&2
    exit 1
  fi
done

PHP_FILE="$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
CSS_FILE="$TMP/stage/public/css/seguimiento_entrantes_analista.css"
php -l "$PHP_FILE" >/dev/null
# Este formulario contiene los controles Guardar y Cancelar dentro de <form>.
# El CSS debe convertir ese formulario en flex antes de limitar/scrollar body.
for marker in \
  'class="linkage-incoming-form"' \
  'data-incoming-scroll-region' \
  'linkage-incoming-form-footer' \
  'linkage-incoming-sdk-notice'; do
  grep -Fq "$marker" "$PHP_FILE" || {
    echo "ERROR: falta estructura del formulario: $marker" >&2
    exit 1
  }
done
for marker in \
  '#modalLlamadaEntranteAnalista .linkage-incoming-form {' \
  '#modalLlamadaEntranteAnalista .linkage-incoming-form > .modal-body {' \
  '#modalLlamadaEntranteAnalista .linkage-incoming-form-footer {' \
  'overflow-y: auto;' \
  'flex: 0 0 auto;' \
  '.linkage-incoming-sdk-notice[hidden]'; do
  grep -Fq "$marker" "$CSS_FILE" || {
    echo "ERROR: faltan estilos de scroll y pie visible: $marker" >&2
    exit 1
  }
done

if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: formulario del Analista ya actualizado."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: formulario y estilos compatibles (2 archivos). PRODUCCIÓN INTACTA."
  echo "Intactos: Zadarma, telefonia_host.js, telefonia_shell.js, historial, Marketing."
  echo "Para publicar ejecuta --apply."
  exit 0
fi
for file in "${CHANGED[@]}"; do
  current="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  [[ "$current" == "${ORIGINAL_SHA[$file]}" ]] || {
    echo "CONFLICTO: $file cambió durante el preflight." >&2
    exit 1
  }
done

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/formulario-resultado-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done

rollback() {
  local code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for file in "${CHANGED[@]}"; do
      cp -p "$BACKUP/$file" "$LIVE/$file"
    done
    echo "ERROR: revertido al respaldo $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  [[ "$file" != *.php ]] || php -l "$LIVE/$file" >/dev/null
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
trap - EXIT
echo "OK: formulario Registrar resultado desplazable y Guardar visible."
echo "Respaldo: $BACKUP"
echo "Intactos: llamadas 100→101, Zadarma, Marketing e historial WebRTC."
