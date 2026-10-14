#!/usr/bin/env bash
# Reversión puntual del último despliegue de historial WebRTC.
# NO ejecuta git reset, NO cambia toda public_html, PBX, webhooks ni MySQL.
# --check solo verifica; --apply respalda primero el estado actual.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2
  exit 2
}

LIVE="${IMPE_RECOVERY_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_RECOVERY_BACKUPS:-$HOME/respaldos-crm}"
SOURCE="${IMPE_RECOVERY_SOURCE:-$HOME/crm-preparacion}"
NEW="app/services/TelefoniaAnalistaWebrtcService.php"
PREFIX="entrantes-webrtc-reconciliado-"
[[ -d "$LIVE" && -d "$BACKUPS" && -d "$SOURCE/.git" ]] || {
  echo "ERROR: falta carpeta de producción, respaldos o repositorio." >&2
  exit 1
}

# Elegir el último respaldo que el despliegue de reconciliación haya creado.
# Hostinger (jaula SSH) puede carecer de /dev/fd: NO usar process substitution.
# Escribir la lista en un archivo temporal y leerla con redirección normal.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
find "$BACKUPS" -mindepth 1 -maxdepth 1 -type d \
  -name "${PREFIX}*" -printf '%T@ %p\n' |
  sort -nr | cut -d' ' -f2- > "$TMP/resguardos.txt"
mapfile -t CANDIDATES < "$TMP/resguardos.txt"
if [[ "${#CANDIDATES[@]}" -eq 0 ]]; then
  echo "ERROR: no existe respaldo reconciliado. No se modificó nada." >&2
  exit 1
fi
OLD="${CANDIDATES[0]}"
[[ -d "$OLD" && ! -L "$OLD" ]] || {
  echo "ERROR: respaldo inválido." >&2; exit 1
}

FILES=(
  "app/controllers/SeguimientoVinculacionController.php"
  "app/services/TelefoniaEntrantesAnalistaService.php"
  "app/views/seguimiento_vinculacion/llamadas_entrantes.php"
  "app/views/telefonia/host.php"
  "app/views/telefonia/shell.php"
  "public/javascript/telefonia_host.js"
  "public/javascript/telefonia_shell.js"
  "public/javascript/seguimiento_entrantes_analista.js"
)

for file in "${FILES[@]}"; do
  if [[ ! -f "$OLD/$file" || -L "$OLD/$file" ||
        ! -f "$LIVE/$file" || -L "$LIVE/$file" ]]; then
    echo "ERROR: respaldo incompleto o archivo modificado fuera de control: $file" >&2
    echo "No se modificó nada." >&2
    exit 1
  fi
  if [[ "$file" == *.php ]]; then
    php -l "$OLD/$file" >/dev/null
  fi
done
if [[ -L "$LIVE/$NEW" ]]; then
  echo "ERROR: el servicio nuevo es un enlace simbólico. No tocar." >&2
  exit 1
fi
# Si el servicio se modificó después de publicar, no borrarlo a ciegas.
if [[ -f "$LIVE/$NEW" ]]; then
  TARGET="d63b667e8e79ed95bf33b64d356f6d72982ca2ce"
  if ! git -C "$SOURCE" show "$TARGET:$NEW" |
      cmp -s - "$LIVE/$NEW"; then
    echo "CONFLICTO: el servicio WebRTC publicado ya fue modificado." >&2
    echo "No se modificó nada; comparte la salida." >&2
    exit 1
  fi
fi

echo "Respaldo anterior: $OLD"
echo "Archivos restaurables: ${#FILES[@]}"
echo "Se mantendrá intacto el observador de timbrado WebRTC, ya existente antes."
if [[ "$MODE" == "--check" ]]; then
  echo "OK: reversión de la última publicación preparada. PRODUCCIÓN INTACTA."
  echo "Para volver a la versión anterior, ejecuta --apply."
  exit 0
fi

# Crear un NUEVO respaldo completo del estado actual antes de revertir.
NOW="$BACKUPS/antes-reversion-historial-webrtc-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$NOW"
for file in "${FILES[@]}"; do
  mkdir -p "$NOW/$(dirname "$file")"
  cp -p "$LIVE/$file" "$NOW/$file"
done
if [[ -f "$LIVE/$NEW" ]]; then
  mkdir -p "$NOW/$(dirname "$NEW")"
  cp -p "$LIVE/$NEW" "$NOW/$NEW"
fi

rollback() {
  local code=$?
  trap - EXIT
  if [[ "$code" -ne 0 ]]; then
    for file in "${FILES[@]}"; do
      cp -p "$NOW/$file" "$LIVE/$file"
    done
    if [[ -f "$NOW/$NEW" ]]; then
      cp -p "$NOW/$NEW" "$LIVE/$NEW"
    fi
    echo "ERROR: se restauró el estado previo a esta reversión: $NOW" >&2
  fi
  exit "$code"
}
trap rollback EXIT

for file in "${FILES[@]}"; do
  cp -p "$OLD/$file" "$LIVE/$file"
  if [[ "$file" == *.js ]]; then
    chmod 0644 "$LIVE/$file"
  else
    php -l "$LIVE/$file" >/dev/null
  fi
  cmp -s "$OLD/$file" "$LIVE/$file"
done
if [[ -f "$LIVE/$NEW" ]]; then
  rm "$LIVE/$NEW"
fi
trap - EXIT
echo "OK: recuperada versión previa del CRM telefónico desde respaldo."
echo "Respaldo del estado actual: $NOW"
echo "Sin cambios en Marketing, Zadarma, webhook, base de datos ni credenciales."
echo "IMPORTANTE: cierra las pestañas de Diego e inicia sesión de nuevo para renovar WebRTC."
