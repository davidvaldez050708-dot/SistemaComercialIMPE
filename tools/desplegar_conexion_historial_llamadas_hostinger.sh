#!/usr/bin/env bash
# Corrige SOLO consultas de historial de expediente:
# conexión MySQL compartida, eliminación de fetch duplicado y diagnóstico SQL.
# No modifica motor Zadarma/SIP/WebRTC, grabaciones, BD ni configuraciones privadas.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_CONEXION_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_CONEXION_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CONEXION_BACKUPS:-$HOME/respaldos-crm}"
[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: falta el repositorio o el sitio publicado." >&2; exit 1;
}

FILES=(
  "app/services/ZadarmaWebhookEventStoreService.php"
  "prueba_telefonia/api/llamadas_seguimiento.php"
  "public/javascript/seguimiento_llamadas_expediente.js"
  "public/javascript/seguimiento_llamadas_desplegable.js"
)
BASES=(
  "270e1fdc53916ba56dead7e187924c9e11b5dd32"
  "1f40e561faa62c3ea57d703e96eda319abaabf5e"
  "a4e6545306dfed9c931e1f73bd048ac9fd17c9b2"
  "ef18da8d0c4db428f957bb318f29b934a5a415e6"
)
TARGETS=(
  "1f40e561faa62c3ea57d703e96eda319abaabf5e"
  "a4e6545306dfed9c931e1f73bd048ac9fd17c9b2"
  "ef18da8d0c4db428f957bb318f29b934a5a415e6"
  "dc3a02f8cdaeedc9aad006d08c0c81a9de592d9b"
)

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A ORIGINAL_SHA=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  for revision in "${BASES[$i]}" "${TARGETS[$i]}"; do
    git -C "$SOURCE" cat-file -e "${revision}^{commit}" || {
      echo "ERROR: faltan commits; ejecuta git pull --ff-only origin main." >&2; exit 1;
    }
  done
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: archivo no encontrado o enlace simbólico: $file" >&2; exit 1;
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
    echo "CONFLICTO: $file cambió en producción. No se modificó nada." >&2; exit 1;
  fi
done

for file in "${FILES[@]}"; do
  case "$file" in
    *.php) php -l "$TMP/stage/$file" >/dev/null ;;
    *.js) if command -v node >/dev/null; then node --check "$TMP/stage/$file" >/dev/null; fi ;;
  esac
done
grep -Fq 'new ZadarmaWebhookEventStoreService($conexionHistorial)' \
  "$TMP/stage/prueba_telefonia/api/llamadas_seguimiento.php"
grep -Fq 'sql_code=%d sqlstate=%s' \
  "$TMP/stage/prueba_telefonia/api/llamadas_seguimiento.php"
grep -Fq 'IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE' \
  "$TMP/stage/public/javascript/seguimiento_llamadas_desplegable.js"
grep -Fq 'IMPE_HISTORIAL_LLAMADAS_EXPEDIENTE' \
  "$TMP/stage/public/javascript/seguimiento_llamadas_expediente.js"

if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: historial de llamadas ya actualizado."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: todos los parches compatibles. PRODUCCIÓN INTACTA."
  echo "No afecta extensiones, recepción, marketing, SDK, SIP ni base de datos."
  echo "Para publicar los archivos utiliza --apply."
  exit 0
fi

for file in "${CHANGED[@]}"; do
  sha="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  [[ "$sha" == "${ORIGINAL_SHA[$file]}" ]] || {
    echo "CONFLICTO: $file cambió durante la revisión." >&2; exit 1;
  }
done

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/conexion-historial-$(date +%Y%m%d-%H%M%S)-$$"
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
    echo "ERROR: restaurado respaldo: $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/prueba_telefonia/api/llamadas_seguimiento.php" >/dev/null
php -l "$LIVE/app/services/ZadarmaWebhookEventStoreService.php" >/dev/null
trap - EXIT
echo "OK: consulta del historial optimizada; respaldo: $BACKUP"
echo "Intactos: motor telefónico, datos de llamadas, SDK y base de datos."
