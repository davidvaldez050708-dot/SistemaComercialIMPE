#!/usr/bin/env bash
# Diseño del MISMO modal al colgar y presentación de Llamadas recibidas.
# Prohibido tocar host SDK, shell JS, PBX o servicios de historial ya activos.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
}
SOURCE="${IMPE_UNIFIED_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_UNIFIED_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_UNIFIED_BACKUPS:-$HOME/respaldos-crm}"
BEFORE="9767043f693f4c1e292d28c7d88064ccb40d8dcc"
AFTER="96d10f054a7fe580a16fb3a3a4f004ce10e6a23a"
FILES=(
  public/javascript/telefonia_analista_historial_pasivo.js
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  public/css/seguimiento_entrantes_analista.css
)
[[ -d "$SOURCE/.git" && -d "$LIVE" ]] || {
  echo "ERROR: faltan crm-preparacion o carpeta public_html." >&2
  exit 1
}
for rev in "$BEFORE" "$AFTER"; do
  git -C "$SOURCE" cat-file -e "$rev^{commit}" || {
    echo "ERROR: falta $rev. Ejecuta git pull --ff-only origin main." >&2
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
    echo "ERROR: falta el archivo publicado o es un enlace simbólico: $file" >&2
    exit 1
  }
  ORIGINAL_SHA["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  git -C "$SOURCE" diff --binary "$BEFORE" "$AFTER" -- "$file" > "$TMP/one.patch"
  [[ -s "$TMP/one.patch" ]] || {
    echo "ERROR: falta el parche esperado para $file" >&2
    exit 1
  }
  if (cd "$TMP/stage" && git apply --reverse --check "$TMP/one.patch" >/dev/null 2>&1); then
    echo "YA PUBLICADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$TMP/one.patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$TMP/one.patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file contiene diferencias posteriores." >&2
    echo "No se modificó nada. Comparte esta salida para preparar un parche seguro." >&2
    exit 1
  fi
done

for file in "${CHANGED[@]}"; do
  if [[ "$file" == *.php ]]; then
    php -l "$TMP/stage/$file" >/dev/null
  elif [[ "$file" == *.js ]] && command -v node >/dev/null 2>&1; then
    node --check "$TMP/stage/$file"
  fi
done
# Comprobaciones de seguridad: el JS actualizado sólo reutiliza
# data-incoming-panel y no puede crear otro modal ni controlar SIP.
JS="$TMP/stage/public/javascript/telefonia_analista_historial_pasivo.js"
[[ -f "$JS" ]] || JS="$LIVE/public/javascript/telefonia_analista_historial_pasivo.js"
grep -Fq "querySelector('[data-incoming-panel]')" "$JS"
grep -Fq "const keepFinishedVisible = function ()" "$JS"
if grep -Fq "createElement('section')" "$JS" ||
   grep -Fq 'zdrmWebPhone' "$JS"; then
  # El nombre en comentarios puede incluir "zdrmWebPhone" en un archivo
  # distinto, pero este módulo no debe invocar la API.
  echo "ERROR: el módulo pasivo contiene una acción potencialmente prohibida." >&2
  exit 1
fi

if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: el mismo modal final y el estilo de historial ya están publicados."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: diseño unificado compatible (${#CHANGED[@]} archivos). PRODUCCIÓN INTACTA."
  echo "Intactos: telefonia_host.js, telefonia_shell.js, host.php, SDK, PBX y Marketing."
  echo "Continúa con --apply sólo si reconoces estos tres archivos."
  exit 0
fi
for file in "${CHANGED[@]}"; do
  now="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  [[ "$now" == "${ORIGINAL_SHA[$file]}" ]] || {
    echo "CONFLICTO: $file cambió durante el preflight" >&2
    exit 1
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/modal-unificado-analista-$(date +%Y%m%d-%H%M%S)-$$"
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
    echo "ERROR: revertido automáticamente desde $BACKUP" >&2
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
echo "OK: modal único al finalizar llamada y lista de recibidas actualizada."
echo "Respaldo: $BACKUP"
echo "Sin modificar recepción, SDK, botones de contestar, PBX ni Marketing."
