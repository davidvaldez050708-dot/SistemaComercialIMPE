#!/usr/bin/env bash
# Cambia sólo cuatro archivos vinculados al auricular de los Analistas.
# No modifica centralita Zadarma ni datos.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash tools/desplegar_controles_webRTC_analista_hostinger.sh --check|--apply" >&2
  exit 2
}
SRC="${IMPE_WEBRTC_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_WEBRTC_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_WEBRTC_BACKUPS:-$HOME/respaldos-crm}"
BASE="1d130d13b7c3a12d7affe5d4d5d6a2fcc5a7d7de"
PREVIOUS="13a8ed2a48a615620cf2480829fb474898bdd4be"
TARGET="35414e88bf88e2752d5bd821e0242edd9d644e48"
FILES=(
  app/views/telefonia/shell.php
  app/views/layout/topbar.php
  public/javascript/telefonia_shell.js
  public/javascript/telefonia_persistente.js
)
[[ -d "$SRC/.git" && -d "$LIVE" ]] || {
  echo "ERROR: faltan repositorio de preparación o sitio publicado." >&2
  exit 1
}
for commit in "$BASE" "$PREVIOUS" "$TARGET"; do
  git -C "$SRC" cat-file -e "$commit^{commit}" || {
    echo "ERROR: no está el commit $commit. Actualiza crm-preparacion." >&2
    exit 1
  }
done
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
# Soporta tanto producción con la versión anterior del visor como
# instalación inicial. Nunca fuerza un parche sobre cambios desconocidos.
for version in "$PREVIOUS" "$BASE"; do
  git -C "$SRC" diff --binary "$version" "$TARGET" -- "${FILES[@]}" >"$TMP/$(basename "$version").patch"
done
for f in "${FILES[@]}"; do
  [[ -f "$LIVE/$f" && ! -L "$LIVE/$f" ]] || {
    echo "ERROR: falta $f o es un enlace simbólico." >&2
    exit 1
  }
done
for php in app/views/telefonia/shell.php app/views/layout/topbar.php; do
  git -C "$SRC" show "$TARGET:$php" >"$TMP/$(basename "$php")"
  php -l "$TMP/$(basename "$php")" >/dev/null
done
PATCH_NEEDED=1
PATCH=""
for version in "$PREVIOUS" "$BASE"; do
  candidate="$TMP/$(basename "$version").patch"
  if (cd "$LIVE" && git apply --reverse --check "$candidate" >/dev/null 2>&1); then
    PATCH_NEEDED=0
    PATCH="$candidate"
    break
  fi
done
if [[ "$PATCH_NEEDED" == 1 ]]; then
  for version in "$PREVIOUS" "$BASE"; do
    candidate="$TMP/$(basename "$version").patch"
    if (cd "$LIVE" && git apply --check "$candidate" >/dev/null 2>&1); then
      PATCH="$candidate"
      break
    fi
  done
fi
if [[ -z "$PATCH" ]]; then
  echo "CONFLICTO: Hostinger no coincide con las versiones verificadas del visor." >&2
  echo "No se modificó nada. Comparte este mensaje antes de publicar." >&2
  exit 1
fi
if [[ "$PATCH_NEEDED" == 0 ]]; then
  echo "OK: controles WebRTC del Analista ya publicados."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: comprobación de solo lectura. Se puede publicar con --apply."
  exit 0
fi
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/controles-webRTC-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for f in "${FILES[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$f")"
  cp -p "$LIVE/$f" "$BACKUP/$f"
done
rollback() {
  code=$?
  if [[ $code != 0 ]]; then
    for f in "${FILES[@]}"; do cp -p "$BACKUP/$f" "$LIVE/$f"; done
    echo "ERROR: cambios revertidos, respaldo en $BACKUP" >&2
  fi
  exit "$code"
}
trap rollback EXIT
(cd "$LIVE" && git apply "$PATCH")
php -l "$LIVE/app/views/telefonia/shell.php" >/dev/null
php -l "$LIVE/app/views/layout/topbar.php" >/dev/null
(cd "$LIVE" && git apply --reverse --check "$PATCH")
trap - EXIT
echo "OK: indicador y controles WebRTC del Analista publicados."
echo "Respaldo: $BACKUP"
echo "Tania, MySQL, rutas PBX y webhooks: sin modificaciones."
