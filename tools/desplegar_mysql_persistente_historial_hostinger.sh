#!/usr/bin/env bash
# Corrige el límite de creación de conexiones SOLO para el historial del expediente.
# No altera credenciales, estructura/filas MySQL, SDK, Zadarma ni WebRTC.
set -euo pipefail
umask 077

MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
}
SOURCE="${IMPE_PERSIST_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_PERSIST_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_PERSIST_BACKUPS:-$HOME/respaldos-crm}"
FILES=("config/db_connection.php" "prueba_telefonia/api/llamadas_seguimiento.php")
BASES=("1f41511a08d17162d9e2257b5949af4efd814482" "bb9b350ec836e5a8b0abf4cffd1bcd50ade83f6f")
TARGETS=("bb9b350ec836e5a8b0abf4cffd1bcd50ade83f6f" "f0229bc2b1ffe8db1bce6c9c9ba8aa6210b9a98b")

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: falta el repositorio de preparación o la carpeta publicada." >&2
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
    echo "ERROR: archivo publicado faltante o enlace simbólico: $file" >&2
    exit 1
  }
  ORIGINAL_SHA["$file"]="$(sha256sum "$LIVE/$file" | cut -d ' ' -f 1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  patch="$TMP/$i.patch"
  git -C "$SOURCE" diff --binary "${BASES[$i]}" "${TARGETS[$i]}" -- "$file" > "$patch"
  [[ -s "$patch" ]] || { echo "ERROR: parche vacío: $file" >&2; exit 1; }
  if (cd "$TMP/stage" && git apply --reverse --check "$patch" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO en $file: publicación intacta." >&2
    exit 1
  fi
done

for file in "${FILES[@]}"; do php -l "$TMP/stage/$file" >/dev/null; done
grep -Fq 'public function connect(bool $persistente = false)' "$TMP/stage/config/db_connection.php"
grep -Fq "'p:' . $host" "$TMP/stage/config/db_connection.php"
grep -Fq '(new Database())->connect(true)' "$TMP/stage/prueba_telefonia/api/llamadas_seguimiento.php"
grep -Fq 'conexion=%s' "$TMP/stage/prueba_telefonia/api/llamadas_seguimiento.php"

if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: conexión persistente ya instalada en historial."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: parches compatibles. PRODUCCIÓN INTACTA."
  echo "Solo se modifica la conexión del historial; resto del CRM en modo anterior."
  echo "Para publicar: --apply"
  exit 0
fi

for file in "${CHANGED[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d ' ' -f 1)" == "${ORIGINAL_SHA[$file]}" ]] || {
    echo "CONFLICTO: $file cambió entre comprobación y publicación." >&2
    exit 1
  }
done

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/mysql-historial-$(date +%Y%m%d-%H%M%S)-$$"
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
    echo "ERROR: restaurado desde $BACKUP" >&2
  fi
  exit "$codigo"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
  php -l "$LIVE/$file" >/dev/null
done
trap - EXIT
echo "OK: parche MySQL publicado. Respaldo: $BACKUP"
echo "Motor de telefonía, datos y credenciales sin modificar."
