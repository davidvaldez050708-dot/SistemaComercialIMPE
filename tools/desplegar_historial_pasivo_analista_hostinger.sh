#!/usr/bin/env bash
# Añade historial/modal final PASIVO. Nunca actualiza motor SIP ni shell.js.
# Modo --check es estrictamente de lectura para producción.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2
}
SOURCE="${IMPE_PASSIVE_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_PASSIVE_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_PASSIVE_BACKUPS:-$HOME/respaldos-crm}"
BASE="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
TARGET="bcbf62e02974a2d317bb297d660821441e751c05"
SIDE="public/javascript/telefonia_analista_historial_pasivo.js"
SERVICE="app/services/TelefoniaAnalistaWebrtcService.php"
SHELL="app/views/telefonia/shell.php"
PATCH_FILES=(
  app/controllers/SeguimientoVinculacionController.php
  app/services/TelefoniaEntrantesAnalistaService.php
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  public/javascript/seguimiento_entrantes_analista.js
)
ALL=( "${PATCH_FILES[@]}" "$SERVICE" "$SIDE" "$SHELL" )
[[ -d "$SOURCE/.git" && -d "$LIVE" ]] || {
  echo "ERROR: falta crm-preparacion o carpeta de producción." >&2; exit 1
}
for v in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "$v^{commit}" || {
    echo "ERROR: actualiza tu repositorio con git pull --ff-only origin main" >&2; exit 1
  }
done
for p in "${ALL[@]}"; do
  [[ ! -L "$LIVE/$p" ]] || { echo "CONFLICTO: enlace simbólico $p" >&2; exit 1; }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -A ORIGINAL=()

for file in "${PATCH_FILES[@]}"; do
  [[ -f "$LIVE/$file" ]] || { echo "ERROR: falta $file" >&2; exit 1; }
  ORIGINAL["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  mkdir -p "$TMP/stage/$(dirname "$file")"
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$TMP/file.patch"
  if [[ ! -s "$TMP/file.patch" ]]; then
    echo "SIN CAMBIOS NECESARIOS: $file"
  elif (cd "$TMP/stage" && git apply --reverse --check "$TMP/file.patch" >/dev/null 2>&1); then
    echo "YA PUBLICADO: $file"
  elif (cd "$TMP/stage" && git apply --check "$TMP/file.patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$TMP/file.patch")
    CHANGED+=("$file")
    echo "PARCHE COMPATIBLE: $file"
  else
    echo "CONFLICTO: $file. No se modificó producción." >&2
    exit 1
  fi
done

for file in "$SERVICE" "$SIDE"; do
  mkdir -p "$TMP/stage/$(dirname "$file")"
  git -C "$SOURCE" show "$TARGET:$file" > "$TMP/stage/$file"
  if [[ -e "$LIVE/$file" ]]; then
    if ! cmp -s "$LIVE/$file" "$TMP/stage/$file"; then
      echo "CONFLICTO: existe una versión distinta de $file." >&2
      exit 1
    fi
    echo "YA PUBLICADO: $file"
  else
    CHANGED+=("$file")
    echo "AGREGAR: $file"
  fi
done
[[ -f "$LIVE/$SHELL" ]] || { echo "ERROR: no existe el shell telefónico." >&2; exit 1; }
ORIGINAL["$SHELL"]="$(sha256sum "$LIVE/$SHELL" | cut -d' ' -f1)"
mkdir -p "$TMP/stage/$(dirname "$SHELL")"
php "$SOURCE/tools/injectar_historial_pasivo_analista.php" \
  "$LIVE/$SHELL" "$TMP/stage/$SHELL"
if ! cmp -s "$LIVE/$SHELL" "$TMP/stage/$SHELL"; then
  CHANGED+=("$SHELL")
  echo "EXTENSIÓN PASIVA: $SHELL (solo analista)"
else
  echo "YA PUBLICADO: $SHELL"
fi

for file in "${CHANGED[@]}"; do
  case "$file" in
    *.php) php -l "$TMP/stage/$file" >/dev/null;;
    *.js) if command -v node >/dev/null 2>&1; then
      node --check "$TMP/stage/$file"
    fi;;
  esac
done
# Los archivos que controlan SIP y el timbrado NUNCA están en este despliegue.
for forbidden in public/javascript/telefonia_host.js \
  public/javascript/telefonia_shell.js \
  public/javascript/telefonia_sdk_entrantes_observador.js \
  app/views/telefonia/host.php; do
  for changed in "${CHANGED[@]}"; do
    [[ "$forbidden" != "$changed" ]] || {
      echo "ERROR: intento de modificar archivo protegido: $forbidden" >&2
      exit 1
    }
  done
done

if [[ "${#CHANGED[@]}" -eq 0 ]]; then
  echo "OK: extensión de historial y cierre ya publicada."
  exit 0
fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: historial pasivo compatible (${#CHANGED[@]} archivos); PRODUCCIÓN INTACTA."
  echo "SIN cambios en host.js, telefonia_shell.js, host.php o detector del timbrado."
  echo "Cuando confirmes la comprobación, ejecuta --apply."
  exit 0
fi

# Preflight por hash: cancelar si cambiaron archivos entre lectura y escritura.
for file in "${CHANGED[@]}"; do
  if [[ "$file" == "$SIDE" || "$file" == "$SERVICE" ]]; then
    [[ ! -e "$LIVE/$file" ]] || { echo "CONFLICTO: el archivo nuevo ya existe."; exit 1; }
  else
    now="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
    [[ "$now" == "${ORIGINAL[$file]}" ]] || {
      echo "CONFLICTO: $file cambió durante el preflight."; exit 1
    }
  fi
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/historial-pasivo-analista-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  [[ "$file" == "$SIDE" || "$file" == "$SERVICE" ]] && continue
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done

rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" -ne 0 ]]; then
    for file in "${CHANGED[@]}"; do
      if [[ "$file" == "$SIDE" || "$file" == "$SERVICE" ]]; then
        rm -f "$LIVE/$file"
      else
        cp -p "$BACKUP/$file" "$LIVE/$file"
      fi
    done
    echo "ERROR: revertido a $BACKUP" >&2
  fi
  exit "$status"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  if [[ "$file" == *.php ]]; then php -l "$LIVE/$file" >/dev/null; fi
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
trap - EXIT
echo "OK: modal finalizado e historial WebRTC pasivo publicados."
echo "Respaldo de archivos anteriores: $BACKUP"
echo "Recepción 100→101, motor Zadarma, shell.js, Marketing y webhooks: intactos."
