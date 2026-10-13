#!/usr/bin/env bash
# Recuperación de Marketing y Analistas: revierte sólo los cuatro archivos
# añadidos para el visor WebRTC de Analistas. No toca la configuración de PBX,
# la base de datos ni las vistas/historial de Seguimiento.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash tools/restaurar_recepcion_webRTC_hostinger.sh --check|--apply" >&2
  exit 2
}
SRC="${IMPE_RESTORE_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_RESTORE_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_RESTORE_BACKUPS:-$HOME/respaldos-crm}"
BASE="1d130d13b7c3a12d7affe5d4d5d6a2fcc5a7d7de"
VERSIONS=(
  "35414e88bf88e2752d5bd821e0242edd9d644e48"
  "13a8ed2a48a615620cf2480829fb474898bdd4be"
)
FILES=(
  app/views/telefonia/shell.php
  app/views/layout/topbar.php
  public/javascript/telefonia_shell.js
  public/javascript/telefonia_persistente.js
)
[[ -d "$SRC/.git" && -d "$LIVE" ]] || {
  echo "ERROR: no se encuentran el repositorio o el sitio publicado." >&2
  exit 1
}
git -C "$SRC" cat-file -e "$BASE^{commit}" || {
  echo "ERROR: falta el commit de la versión estable." >&2
  exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
for file in "${FILES[@]}"; do
  [[ -f "$LIVE/$file" && ! -L "$LIVE/$file" ]] || {
    echo "ERROR: no existe $file o apunta a un enlace simbólico." >&2
    exit 1
  }
done

# Detectar qué versión exacta está publicada sin inferirla por el git pull.
SELECTED=""
for ver in "${VERSIONS[@]}"; do
  git -C "$SRC" cat-file -e "$ver^{commit}" || {
    echo "ERROR: falta el commit $ver; actualiza crm-preparacion." >&2
    exit 1
  }
  git -C "$SRC" diff --binary "$BASE" "$ver" -- "${FILES[@]}" > "$TMP/$ver.patch"
  if [[ ! -s "$TMP/$ver.patch" ]]; then
    echo "ERROR: parche de reversión vacío." >&2
    exit 1
  fi
  if (cd "$LIVE" && git apply --reverse --check "$TMP/$ver.patch" >/dev/null 2>&1); then
    SELECTED="$ver"
    break
  fi
done
if [[ -z "$SELECTED" ]]; then
  # Puede que producción ya coincida con la base en estos cuatro archivos.
  STABLE=1
  for file in "${FILES[@]}"; do
    git -C "$SRC" show "$BASE:$file" > "$TMP/$(echo "$file" | tr '/' '_')"
    if ! cmp -s "$TMP/$(echo "$file" | tr '/' '_')" "$LIVE/$file"; then
      STABLE=0
    fi
  done
  if [[ "$STABLE" == 1 ]]; then
    echo "OK: los cuatro archivos ya coinciden con la versión estable."
    exit 0
  fi
  echo "CONFLICTO: Hostinger tiene una variante diferente de los cuatro archivos." >&2
  echo "No se modificó ningún archivo. Comparte este resultado antes de continuar." >&2
  exit 1
fi
echo "Versión del visor detectada: ${SELECTED:0:12}"
if [[ "$MODE" == "--check" ]]; then
  echo "OK: reversión selectiva posible, sin escribir en producción."
  echo "Si deseas restaurar la recepción anterior, ejecuta el mismo script con --apply."
  exit 0
fi

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/restauracion-recepcion-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${FILES[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  code=$?
  if [[ "$code" -ne 0 ]]; then
    for file in "${FILES[@]}"; do
      cp -p "$BACKUP/$file" "$LIVE/$file"
    done
    echo "ERROR: restauración interrumpida; se recuperó el respaldo $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
(cd "$LIVE" && git apply --reverse "$TMP/$SELECTED.patch")
for file in app/views/telefonia/shell.php app/views/layout/topbar.php; do
  php -l "$LIVE/$file" >/dev/null
done
(cd "$LIVE" && git apply --check "$TMP/$SELECTED.patch")
trap - EXIT
echo "OK: cambios recientes del visor WebRTC revertidos de forma selectiva."
echo "Respaldo anterior: $BACKUP"
echo "MySQL, Zadarma, webhook y vistas de Seguimiento: intactos."
