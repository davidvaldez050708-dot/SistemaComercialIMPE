#!/usr/bin/env bash
# Reconciliación conservadora: no reemplaza toda producción ni el webhook.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Uso: $0 --check|--apply" >&2; exit 2; }
SOURCE="${IMPE_HISTORY_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_HISTORY_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_HISTORY_BACKUPS:-$HOME/respaldos-crm}"
BASE="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
TARGET="d63b667e8e79ed95bf33b64d356f6d72982ca2ce"
NEW="app/services/TelefoniaAnalistaWebrtcService.php"
SHELL="public/javascript/telefonia_shell.js"
FILES=(
  app/controllers/SeguimientoVinculacionController.php
  app/services/TelefoniaEntrantesAnalistaService.php
  "$NEW"
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  app/views/telefonia/host.php
  app/views/telefonia/shell.php
  public/javascript/telefonia_host.js
  "$SHELL"
  public/javascript/telefonia_sdk_entrantes_observador.js
  public/javascript/seguimiento_entrantes_analista.js
)
[[ -d "$SOURCE/.git" && -d "$LIVE" ]] || { echo "ERROR: no existe repositorio o producción" >&2; exit 1; }
for v in "$BASE" "$TARGET"; do
  git -C "$SOURCE" cat-file -e "$v^{commit}" || {
    echo "ERROR: falta revisión $v. Ejecuta git pull --ff-only origin main." >&2; exit 1;
  }
done
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage" "$TMP/base" "$TMP/target"
declare -a CHANGED=()
declare -A ORIGINAL_SHA=()
for file in "${FILES[@]}"; do
  [[ ! -L "$LIVE/$file" ]] || { echo "CONFLICTO: enlace simbólico $file" >&2; exit 1; }
  for d in stage base target; do mkdir -p "$TMP/$d/$(dirname "$file")"; done
  git -C "$SOURCE" show "$TARGET:$file" >"$TMP/target/$file"
  if [[ "$file" == "$NEW" ]]; then
    if [[ -e "$LIVE/$file" ]]; then
      if cmp -s "$LIVE/$file" "$TMP/target/$file"; then
        echo "YA ACTUALIZADO: $file"; continue
      fi
      echo "CONFLICTO: el servicio WebRTC ya existe con contenido distinto" >&2; exit 1
    fi
    cp "$TMP/target/$file" "$TMP/stage/$file"
    CHANGED+=("$file")
    echo "AGREGAR: $file"
    continue
  fi
  [[ -f "$LIVE/$file" ]] || { echo "CONFLICTO: archivo ausente $file" >&2; exit 1; }
  ORIGINAL_SHA["$file"]="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
  if cmp -s "$LIVE/$file" "$TMP/target/$file"; then
    echo "YA ACTUALIZADO: $file"; continue
  fi
  if [[ "$file" == "$SHELL" ]] &&
    php "$SOURCE/tools/resolver_conflicto_shell_analista.php" \
      --is-reconciled "$LIVE/$file" "$TMP/target/$file"; then
    echo "YA RECONCILIADO: $file (sin reponer filtro de visibilidad)"
    continue
  fi
  cp -p "$LIVE/$file" "$TMP/stage/$file"
  git -C "$SOURCE" show "$BASE:$file" >"$TMP/base/$file"
  git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" >"$TMP/one.patch"
  if [[ ! -s "$TMP/one.patch" ]]; then
    echo "SIN CAMBIOS NECESARIOS: $file"; continue
  fi
  if (cd "$TMP/stage" && git apply --check "$TMP/one.patch" >/dev/null 2>&1); then
    (cd "$TMP/stage" && git apply "$TMP/one.patch")
    echo "PARCHE COMPATIBLE: $file"
  elif [[ "$file" == "$SHELL" ]]; then
    # Nombres estables de marca: el reconciliador rechaza cualquier diferencia
    # que no sea exactamente el bloque diagnosticado en Hostinger.
    if git merge-file --diff3 -p \
      -L PRODUCCION -L BASE -L OBJETIVO \
      "$LIVE/$file" "$TMP/base/$file" "$TMP/target/$file" \
      >"$TMP/merge_shell.txt"; then
      cp "$TMP/merge_shell.txt" "$TMP/stage/$file"
      echo "FUSIÓN CONSERVADORA: $file (mantiene cambios de producción)"
    elif php "$SOURCE/tools/resolver_conflicto_shell_analista.php" \
      "$TMP/merge_shell.txt" "$TMP/stage/$file"; then
      echo "CONFLICTO CONOCIDO RESUELTO: $file (se conserva el modal visible)"
    else
      echo "CONFLICTO REAL: $file. Requiere revisión manual de las diferencias." >&2
      echo "Producción no se modificó." >&2
      exit 1
    fi
  else
    echo "CONFLICTO REAL: $file. Producción no se modificó." >&2; exit 1
  fi
  CHANGED+=("$file")
done
for file in "${CHANGED[@]}"; do
  case "$file" in
    *.php) php -l "$TMP/stage/$file" >/dev/null;;
    *.js) if command -v node >/dev/null 2>&1; then node --check "$TMP/stage/$file"; fi;;
  esac
done
SHELL_STAGE="$TMP/stage/$SHELL"
[[ -f "$SHELL_STAGE" ]] || SHELL_STAGE="$LIVE/$SHELL"
for marker in \
  'cfg.analystIncomingResults' \
  'providerBridge()?.answer()' \
  'providerBridge()?.hangup()' \
  'const finishedSdk = Boolean(' \
  'const customVisible = (incoming || finishedSdk || shownOutgoing)' \
  "journalStatus !== 'saved'"; do
  grep -Fq "$marker" "$SHELL_STAGE" || {
    echo "CONFLICTO: falta soporte necesario en shell.js: $marker" >&2; exit 1;
  }
done
if [[ "${#CHANGED[@]}" == 0 ]]; then echo "OK: todo ya está publicado."; exit 0; fi
if [[ "$MODE" == "--check" ]]; then
  echo "OK: reconciliación compatible (${#CHANGED[@]} archivos), SIN modificar producción."
  echo "Si aparece FUSIÓN CONSERVADORA, se preservaron los cambios locales."
  echo "Puedes ejecutar --apply."
  exit 0
fi
for file in "${CHANGED[@]}"; do
  if [[ "$file" == "$NEW" ]]; then
    [[ ! -e "$LIVE/$file" ]] || { echo "CONFLICTO: nuevo archivo apareció" >&2; exit 1; }
  else
    hash="$(sha256sum "$LIVE/$file" | cut -d' ' -f1)"
    [[ "$hash" == "${ORIGINAL_SHA[$file]}" ]] || {
      echo "CONFLICTO: $file cambió durante el análisis" >&2; exit 1;
    }
  fi
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/entrantes-webrtc-reconciliado-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for file in "${CHANGED[@]}"; do
  [[ "$file" == "$NEW" ]] && continue
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$LIVE/$file" "$BACKUP/$file"
done
rollback() {
  status=$?
  trap - EXIT
  if [[ "$status" != 0 ]]; then
    for file in "${CHANGED[@]}"; do
      if [[ "$file" == "$NEW" ]]; then rm -f "$LIVE/$file"
      else cp -p "$BACKUP/$file" "$LIVE/$file"; fi
    done
    echo "ERROR: reversión desde respaldo $BACKUP" >&2
  fi
  exit "$status"
}
trap rollback EXIT
for file in "${CHANGED[@]}"; do
  cp "$TMP/stage/$file" "$LIVE/$file"
  chmod 0644 "$LIVE/$file"
  [[ "$file" != *.php ]] || php -l "$LIVE/$file" >/dev/null
  cmp -s "$TMP/stage/$file" "$LIVE/$file"
done
trap - EXIT
echo "OK: modal e historial WebRTC actualizados. Respaldo: $BACKUP"
echo "Sin cambios en Marketing, Zadarma, webhook, datos o credenciales."
