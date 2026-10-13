#!/usr/bin/env bash
# Despliegue incremental del cierre visual y de la bitácora WebRTC de Analistas.
# No reemplaza todo public_html, no modifica Marketing, Zadarma ni webhooks.
set -euo pipefail
umask 077

MODE="${1:---check}"
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || {
  echo "Uso: bash tools/desplegar_historial_entrantes_webRTC_analista_hostinger.sh --check|--apply" >&2
  exit 2
}

SOURCE="${IMPE_HISTORY_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_HISTORY_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_HISTORY_BACKUPS:-$HOME/respaldos-crm}"
BEFORE="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
AFTER="f2e803029fdefb0bd272db059bed469f134c93a2"

FILES=(
  'app/controllers/SeguimientoVinculacionController.php'
  'app/services/TelefoniaEntrantesAnalistaService.php'
  'app/services/TelefoniaAnalistaWebrtcService.php'
  'app/views/seguimiento_vinculacion/llamadas_entrantes.php'
  'app/views/telefonia/host.php'
  'app/views/telefonia/shell.php'
  'public/javascript/telefonia_host.js'
  'public/javascript/telefonia_shell.js'
  'public/javascript/telefonia_sdk_entrantes_observador.js'
  'public/javascript/seguimiento_entrantes_analista.js'
)
NEW='app/services/TelefoniaAnalistaWebrtcService.php'
JS_FILES=(
  'public/javascript/telefonia_host.js'
  'public/javascript/telefonia_shell.js'
  'public/javascript/telefonia_sdk_entrantes_observador.js'
  'public/javascript/seguimiento_entrantes_analista.js'
)
PHP_FILES=(
  'app/controllers/SeguimientoVinculacionController.php'
  'app/services/TelefoniaEntrantesAnalistaService.php'
  "$NEW"
  'app/views/seguimiento_vinculacion/llamadas_entrantes.php'
  'app/views/telefonia/host.php'
  'app/views/telefonia/shell.php'
)

[[ -d "$SOURCE/.git" && -d "$LIVE" ]] || {
  echo "ERROR: repositorio o carpeta de producción no encontrados." >&2
  exit 1
}
for rev in "$BEFORE" "$AFTER"; do
  git -C "$SOURCE" cat-file -e "${rev}^{commit}" || {
    echo "ERROR: falta el commit $rev; ejecuta git pull --ff-only origin main." >&2
    exit 1
  }
done
for p in "${FILES[@]}"; do
  [[ ! -L "$LIVE/$p" ]] || {
    echo "ERROR: no se sobrescriben enlaces simbólicos: $p" >&2
    exit 1
  }
  if [[ "$p" != "$NEW" && ! -f "$LIVE/$p" ]]; then
    echo "ERROR: falta archivo en producción: $p" >&2
    exit 1
  fi
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
git -C "$SOURCE" diff --binary "$BEFORE" "$AFTER" -- "${FILES[@]}" > "$TMP/update.patch"
[[ -s "$TMP/update.patch" ]] || {
  echo "ERROR: parche vacío." >&2
  exit 1
}
for p in "${PHP_FILES[@]}"; do
  local_file="$TMP/$(basename "$p")"
  git -C "$SOURCE" show "$AFTER:$p" > "$local_file"
  php -l "$local_file" >/dev/null
done

if (cd "$LIVE" && git apply --reverse --check "$TMP/update.patch" >/dev/null 2>&1); then
  echo "OK: la corrección del modal y el historial WebRTC ya está publicada."
  exit 0
fi
if [[ -e "$LIVE/$NEW" ]]; then
  echo "CONFLICTO: el servicio nuevo ya existe con una versión distinta. No se modificó nada." >&2
  exit 1
fi
if ! (cd "$LIVE" && git apply --check "$TMP/update.patch" >/dev/null 2>&1); then
  echo "CONFLICTO: algunos archivos publicados tienen cambios distintos." >&2
  echo "No se modificó nada. Comparte esta salida para reconciliar el parche." >&2
  exit 1
fi
if [[ "$MODE" == '--check' ]]; then
  echo "OK: parche compatible. Sólo se actualizarán 10 archivos de Analista/Telefonía."
  echo "Sin cambios en producción; continúa con --apply."
  exit 0
fi

mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/historial-entrantes-webrtc-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for p in "${FILES[@]}"; do
  [[ "$p" == "$NEW" ]] && continue
  mkdir -p "$BACKUP/$(dirname "$p")"
  cp -p "$LIVE/$p" "$BACKUP/$p"
done

rollback() {
  local code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for p in "${FILES[@]}"; do
      if [[ "$p" == "$NEW" ]]; then
        rm -f "$LIVE/$p"
      else
        cp -p "$BACKUP/$p" "$LIVE/$p"
      fi
    done
    echo "ERROR: restaurados los archivos anteriores desde $BACKUP." >&2
  fi
  rm -rf "$TMP"
  exit "$code"
}
trap rollback EXIT

(cd "$LIVE" && git apply "$TMP/update.patch")
chmod 0644 "$LIVE/$NEW"
for p in "${JS_FILES[@]}"; do
  chmod 0644 "$LIVE/$p"
done
for p in "${PHP_FILES[@]}"; do
  php -l "$LIVE/$p" >/dev/null
done
(cd "$LIVE" && git apply --reverse --check "$TMP/update.patch")
trap - EXIT
echo "OK: modal finalizado e historial WebRTC de Analistas publicados."
echo "Respaldo: $BACKUP"
echo "No se modificaron Marketing, webhooks firmados, Zadarma, credenciales ni la base de datos existente."
echo "El nuevo historial crea su tabla propia al recibir la primera llamada."
