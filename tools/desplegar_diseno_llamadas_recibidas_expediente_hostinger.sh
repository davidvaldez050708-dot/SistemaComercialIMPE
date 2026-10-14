#!/usr/bin/env bash
# Alinea la bandeja del Analista con el diseño/reproductor del expediente.
# Preflight y despliegue SELECTIVO (4 archivos), con respaldo/rollback.
# No cambia base de datos, API, PBX, Zend, sesiones ni endpoints de audio.
set -euo pipefail
umask 077
MODE="${1:---check}"
if [[ "$MODE" != "--check" && "$MODE" != "--apply" ]]; then
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
fi
SOURCE="${IMPE_DISENO_LLAMADAS_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_DISENO_LLAMADAS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_DISENO_LLAMADAS_BACKUPS:-$HOME/respaldos-crm}"
BASE="abd0616a9d2e043dc244d1140d9fb51a40846cdd"
TARGET="636fbfa7cd876d37609664337f8f539a45456354"
FILES=(
  "app/views/layout/dashboard_head.php"
  "app/views/seguimiento_vinculacion/llamadas_entrantes.php"
  "public/css/seguimiento_entrantes_analista.css"
  "public/javascript/seguimiento_entrantes_analista.js"
)
DEPENDENCIAS=(
  "public/css/seguimiento_llamadas_expediente.css"
  "public/css/seguimiento_llamadas_player.css"
)

[[ -d "$SOURCE/.git" && -d "$LIVE" && ! -L "$LIVE" ]] || {
  echo "ERROR: clon Git o publicación no disponibles." >&2
  exit 1
}
for rev in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "${rev}^{commit}" || {
    echo "ERROR: falta un commit. Ejecuta git pull --ff-only origin main." >&2
    exit 1
  }
done
for dep in "${DEPENDENCIAS[@]}"; do
  [[ -f "$LIVE/$dep" && ! -L "$LIVE/$dep" ]] || {
    echo "ERROR: falta hoja del expediente: $dep" >&2
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
    echo "ERROR: falta archivo de producción: $file" >&2
    exit 1
  }
  HASH["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  parche="$TMP/change_$i.patch"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$parche"
  [[ -s "$parche" ]] || {
    echo "ERROR: parche vacío para $file" >&2
    exit 1
  }
  if (cd "$TMP/stage" && git apply --reverse --check "$parche" >/dev/null 2>&1); then
    echo "YA ACTUALIZADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$parche" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$parche")
    CAMBIADOS+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file difiere de la versión compatible. PRODUCCIÓN INTACTA." >&2
    exit 1
  fi
done
php -l "$TMP/stage/app/views/layout/dashboard_head.php" >/dev/null
php -l "$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php" >/dev/null
if command -v node >/dev/null 2>&1; then
  node --check "$TMP/stage/public/javascript/seguimiento_entrantes_analista.js"
fi
grep -Fq "'seguimiento_llamadas_expediente.css'" "$TMP/stage/app/views/layout/dashboard_head.php"
grep -Fq "'seguimiento_llamadas_player.css'" "$TMP/stage/app/views/layout/dashboard_head.php"
grep -Fq "linkage-call-history-card" "$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
grep -Fq "linkage-call-custom-player" "$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
grep -Fq "data-incoming-sync-audio" "$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
grep -Fq "data-call-id" "$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
grep -Fq "data-incoming-audio-toggle" "$TMP/stage/public/javascript/seguimiento_entrantes_analista.js"
grep -Fq "repeat(4, minmax(0, 1fr))" "$TMP/stage/public/css/seguimiento_entrantes_analista.css"

if [[ "${#CAMBIADOS[@]}" -eq 0 ]]; then
  echo "OK: diseño y reproductor del expediente ya publicados."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: diseño compatible. PRODUCCIÓN INTACTA."
  echo "Solo se publicarán: estilos, vista, JS de la bandeja y selector de CSS."
  exit 0
fi

for file in "${CAMBIADOS[@]}"; do
  [[ "$(sha256sum "$LIVE/$file" | cut -d' ' -f1)" == "${HASH[$file]}" ]] || {
    echo "CONFLICTO: $file fue modificado durante el preflight." >&2
    exit 1
  }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/diseno-llamadas-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CAMBIADOS[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    for file in "${CAMBIADOS[@]}"; do
      cp -p "$BACKUP/$file" "$LIVE/$file"
    done
    echo "ERROR: restaurados archivos anteriores de $BACKUP" >&2
  fi
  exit "$status"
}
trap rollback EXIT
for file in "${CAMBIADOS[@]}"; do
  cp -p "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
php -l "$LIVE/app/views/layout/dashboard_head.php" >/dev/null
php -l "$LIVE/app/views/seguimiento_vinculacion/llamadas_entrantes.php" >/dev/null
trap - EXIT
echo "OK: Llamadas recibidas usa tarjetas y reproductor del expediente."
echo "Respaldo: $BACKUP"
echo "Telefonía, grabaciones, PBX, MySQL y otros módulos SIN MODIFICAR."
