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
TARGET="13a8ed2a48a615620cf2480829fb474898bdd4be"
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
for commit in "$BASE" "$TARGET"; do
  git -C "$SRC" cat-file -e "$commit^{commit}" || {
    echo "ERROR: no está el commit $commit. Actualiza crm-preparacion." >&2
    exit 1
  }
done
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$SRC" diff --binary "$BASE" "$TARGET" -- "${FILES[@]}" >"$TMP/web-analista.patch"
[[ -s "$TMP/web-analista.patch" ]] || {
  echo "ERROR: el parche de la prueba no está disponible." >&2
  exit 1
}
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
if (cd "$LIVE" && git apply --reverse --check "$TMP/web-analista.patch" >/dev/null 2>&1); then
  PATCH_NEEDED=0
elif ! (cd "$LIVE" && git apply --check "$TMP/web-analista.patch" >/dev/null 2>&1); then
  echo "CONFLICTO: alguna vista o JS tiene modificaciones propias en Hostinger." >&2
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
(cd "$LIVE" && git apply "$TMP/web-analista.patch")
php -l "$LIVE/app/views/telefonia/shell.php" >/dev/null
php -l "$LIVE/app/views/layout/topbar.php" >/dev/null
(cd "$LIVE" && git apply --reverse --check "$TMP/web-analista.patch")
trap - EXIT
echo "OK: indicador y controles WebRTC del Analista publicados."
echo "Respaldo: $BACKUP"
echo "Tania, MySQL, rutas PBX y webhooks: sin modificaciones."
