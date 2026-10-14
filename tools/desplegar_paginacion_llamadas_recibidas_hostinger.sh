#!/usr/bin/env bash
# Paginación del historial del Analista: 10 registros por página.
# Despliegue SELECTIVO de vista+CSS, sin MySQL, SDK, PBX, JS o grabaciones.
set -euo pipefail
umask 077
MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi

SOURCE="${IMPE_PAGINACION_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_PAGINACION_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_PAGINACION_BACKUPS:-$HOME/respaldos-crm}"
BASE="e2bf1d9082803fa9423a7935d14d3163075f1205"
TARGET="2ef8110233af82609bcfe8282eb5f04e2b912a2b"
FILES=(
  "app/views/seguimiento_vinculacion/llamadas_entrantes.php"
  "public/css/seguimiento_entrantes_analista.css"
)

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: repositorio o sitio Hostinger no disponibles." >&2
  exit 1
}
for rev in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "${rev}^{commit}" || {
    echo "ERROR: faltan cambios recientes. Ejecuta git pull --ff-only origin main." >&2
    exit 1
  }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CAMBIADOS=()
declare -A HASH=()
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: falta archivo publicado $file" >&2
    exit 1
  }
  HASH["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  patch="$TMP/page_$i.patch"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$patch"
  [[ -s "$patch" ]] || {
    echo "ERROR: parche vacío para $file" >&2
    exit 1
  }
  if (cd "$TMP/stage" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    CAMBIADOS+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file no coincide con diseño actual; producción intacta." >&2
    echo "Verifica primero que esté publicado el rediseño de Llamadas recibidas." >&2
    exit 1
  fi
done

php -l "$TMP/stage/${FILES[0]}" >/dev/null
grep -Fq '$llamadasPorPagina = 10;' "$TMP/stage/${FILES[0]}"
grep -Fq 'array_slice($llamadas, $indiceInicialEntrantes, $llamadasPorPagina)' "$TMP/stage/${FILES[0]}"
grep -Fq 'linkage-incoming-pagination' "$TMP/stage/${FILES[0]}"
grep -Fq 'linkage-incoming-pagination-number.is-current' "$TMP/stage/${FILES[1]}"

if [[ "${#CAMBIADOS[@]}" -eq 0 ]]; then
  echo "OK: paginación de llamadas ya publicada."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: paginación compatible. PRODUCCIÓN INTACTA."
  echo "Se actualizarán únicamente la vista y su CSS."
  exit 0
fi

for file in "${CAMBIADOS[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${HASH[$file]}" ]] || {
    echo "CONFLICTO: cambió $file durante la comprobación." >&2
    exit 1
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/paginacion-llamadas-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CAMBIADOS[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done

rollback() {
  result=$?
  trap - EXIT
  if [[ "$result" -ne 0 ]]; then
    for file in "${CAMBIADOS[@]}"; do
      cp -p "$BACKUP/$file" "$LIVE/$file"
    done
    echo "ERROR: se restauraron los archivos de $BACKUP" >&2
  fi
  exit "$result"
}
trap rollback EXIT
for file in "${CAMBIADOS[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/${FILES[0]}" >/dev/null
trap - EXIT
echo "OK: llamadas recibidas paginadas de 10 en 10 con navegación numerada."
echo "Respaldo: $BACKUP"
echo "Sin cambios en MySQL, Zadarma, WebRTC, grabaciones, modal ni otros módulos."
