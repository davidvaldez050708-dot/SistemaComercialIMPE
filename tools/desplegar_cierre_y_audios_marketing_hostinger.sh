#!/usr/bin/env bash
# Marketing: audio de salientes autorizadas + cierre de llamada sin fantasmas.
# --check es de solo lectura; --apply respalda antes de publicar.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == --check || "$MODE" == --apply ]] || {
 echo "Uso: bash tools/desplegar_cierre_y_audios_marketing_hostinger.sh --check|--apply" >&2
 exit 2
}
SRC="${IMPE_MARKETING_FIX_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_MARKETING_FIX_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_MARKETING_FIX_BACKUP:-$HOME/respaldos-crm}"
BASE="157667d3f1d4eccc524d21b23eadfa3aebb96e2a"
NEW_FILE="app/services/TelefoniaGrabacionSalienteMarketingService.php"
FILES=(
 "$NEW_FILE"
 "app/services/TelefoniaRecepcionMarketingService.php"
 "prueba_telefonia/api/grabacion_recepcion_marketing.php"
 "public/javascript/telefonia_recepcion_marketing.js"
 "public/javascript/telefonia_shell.js"
 "public/javascript/telefonia_host.js"
 "public/javascript/telefonia_persistente.js"
 "app/views/telefonia/shell.php"
)
[[ -d "$SRC/.git" && -f "$LIVE/index.php" ]] || {
 echo "ERROR: no se encontró el CRM o el repositorio." >&2; exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Revisando ocho archivos sin modificar producción..."
for p in "${FILES[@]}"; do
 [[ -f "$SRC/$p" && ! -L "$SRC/$p" ]] || {
  echo "ERROR: falta archivo GitHub: $p" >&2; exit 1
 }
 mkdir -p "$TMP/$(dirname "$p")"
 if [[ "$p" == "$NEW_FILE" ]]; then
  if [[ -L "$LIVE/$p" ]] || { [[ -e "$LIVE/$p" ]] &&
       ! cmp -s "$LIVE/$p" "$SRC/$p"; }; then
   echo "CONFLICTO: verificador de grabaciones ya existe con otros cambios." >&2
   exit 1
  fi
  cp "$SRC/$p" "$TMP/$p"
  continue
 fi
 [[ -f "$LIVE/$p" && ! -L "$LIVE/$p" ]] || {
  echo "ERROR: falta archivo seguro de producción: $p" >&2; exit 1
 }
 git -C "$SRC" show "$BASE:$p" > "$TMP/base" || {
  echo "ERROR: falta base histórica para $p." >&2; exit 1
 }
 if cmp -s "$LIVE/$p" "$SRC/$p" || cmp -s "$LIVE/$p" "$TMP/base"; then
  cp "$SRC/$p" "$TMP/$p"
 elif git merge-file -p -L HOSTINGER -L BASE -L GITHUB \
    "$LIVE/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p"; then
  echo "Conservada personalización de Hostinger: $p"
 else
  echo "CONFLICTO: $p cambió en una zona incompatible. No se publicó nada." >&2
  exit 1
 fi
done
echo "2/4 Comprobando sintaxis, autorización y ciclo de vida..."
for p in "$NEW_FILE" "app/services/TelefoniaRecepcionMarketingService.php" \
   "prueba_telefonia/api/grabacion_recepcion_marketing.php" \
   "app/views/telefonia/shell.php"; do
 php -l "$TMP/$p"
done
if command -v node >/dev/null 2>&1; then
 for p in public/javascript/telefonia_recepcion_marketing.js \
    public/javascript/telefonia_shell.js public/javascript/telefonia_host.js \
    public/javascript/telefonia_persistente.js; do
  node --check "$TMP/$p"
 done
fi
requires() { grep -Fq "$2" "$TMP/$1" || {
 echo "ERROR: $1 no contiene la validación esperada: $2" >&2; exit 1
}; }
requires "$NEW_FILE" 'GREATEST(te.created_at, te.updated_at)'
requires "$NEW_FILE" "inicio.evento = 'NOTIFY_OUT_START'"
requires "$NEW_FILE" 'eventosAutorizados($eventos, $extension)'
requires "$NEW_FILE" 'return $iniciada && $finalizada'
requires "app/services/TelefoniaRecepcionMarketingService.php" '$salienteReproducible'
requires "prueba_telefonia/api/grabacion_recepcion_marketing.php" "tienePermiso('telefonia.salientes')"
requires "prueba_telefonia/api/grabacion_recepcion_marketing.php" 'TelefoniaGrabacionSalienteMarketingService::autorizada'
requires "public/javascript/telefonia_recepcion_marketing.js" "call.tipo === 'Saliente'"
requires "public/javascript/telefonia_host.js" "manualCall(state)"
requires "public/javascript/telefonia_persistente.js" "manual && !parsed.active && parsed.phase === 'finished'"
requires "public/javascript/telefonia_shell.js" 'recentFinishedOutgoing'
requires "public/javascript/telefonia_shell.js" "'CLEAR_FINISHED'"
requires "app/views/telefonia/shell.php" 'data-incoming-close-finished'
for p in "${FILES[@]}"; do
 if grep -Eq '^(<<<<<<<|=======|>>>>>>>)' "$TMP/$p"; then
  echo "CONFLICTO: fusión incompleta en $p." >&2; exit 1
 fi
done
CHANGED=()
for p in "${FILES[@]}"; do
 if [[ ! -f "$LIVE/$p" ]] || ! cmp -s "$TMP/$p" "$LIVE/$p"; then
  CHANGED+=("$p")
 fi
done
if [[ "${#CHANGED[@]}" -eq 0 ]]; then
 echo "OK: correcciones ya publicadas; no hay cambios."
 exit 0
fi
printf 'Pendiente: %s\n' "${CHANGED[@]}"
if [[ "$MODE" == --check ]]; then
 echo "OK: comprobación previa sin modificar producción."
 exit 0
fi
echo "3/4 Guardando respaldos de los archivos anteriores..."
mkdir -p "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/marketing-audio-cierre-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for p in "${CHANGED[@]}"; do
 if [[ -f "$LIVE/$p" ]]; then
  mkdir -p "$BACKUP/$(dirname "$p")"
  cp -p "$LIVE/$p" "$BACKUP/$p"
 fi
done
echo "4/4 Publicando los cambios aprobados..."
for p in "${CHANGED[@]}"; do
 mkdir -p "$LIVE/$(dirname "$p")"
 cp "$TMP/$p" "$LIVE/$p"
 if ! cmp -s "$TMP/$p" "$LIVE/$p"; then
  echo "ERROR: publicación incompleta ($p). Respaldo: $BACKUP" >&2
  exit 1
 fi
done
echo "OK: grabaciones salientes y cierre limpio de Marketing publicados."
echo "Sin cambios en MySQL, configuración Zadarma ni extensiones."
echo "Respaldo: $BACKUP"
