#!/usr/bin/env bash
# Contexto confirmado, duración observada y formulario del Analista.
# --check NUNCA escribe en producción. No instala el shell ni Zadarma.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash $0 --check|--apply" >&2; exit 2;
}
SOURCE="${IMPE_FLOW_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_FLOW_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_FLOW_BACKUPS:-$HOME/respaldos-crm}"
BASE="53f94403d5aae63d8b02de4203c496c87cfb47b3"
TARGET="4575ca9159664625c36f78f34eb089a74a3b9853"
FILES=(
 "app/controllers/TelefoniaController.php"
 "app/services/TelefoniaTransferenciaContextoService.php"
 "app/services/TelefoniaAnalistaWebrtcService.php"
 "public/javascript/telefonia_analista_historial_pasivo.js"
 "public/javascript/seguimiento_entrantes_analista.js"
 "app/views/seguimiento_vinculacion/llamadas_entrantes.php"
 "public/css/seguimiento_entrantes_analista.css"
)
[[ -d "$SOURCE/.git" && -f "$LIVE/index.php" && ! -L "$LIVE" ]] || {
 echo "ERROR: rutas de código o producción no compatibles. PRODUCCIÓN INTACTA." >&2; exit 1;
}
git -C "$SOURCE" cat-file -e "$BASE^{commit}"
git -C "$SOURCE" cat-file -e "$TARGET^{commit}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/stage"
declare -a CHANGED=()
declare -a CONFLICTS=()
declare -A HASHES=()
# Un cambio opcional del controlador sólo agrega una línea de diagnóstico.
# En Hostinger el controlador es personalizado: si el flujo de contexto ya
# está íntegro, conservarlo sin forzar un hunk de PHP ajeno a la llamada.
controller_contexto_compatible() {
 local p="$1"
 local required
 for required in \
   'public function contextoTransferencia()' \
   '->obtenerParaDestinatario(' \
   'public function autorizarTransferencia()' \
   "'seguimiento_id'=>\$seguimientoId" \
   "'pbx_call_id'=>\$pbxCallId" \
   'public function validarOrdenTransferencia()' \
   '(new TelefoniaTransferenciaContextoService())->registrar(' \
   "'destino'=>"; do
   grep -Fq -- "$required" "$p" || return 1
 done
 return 0
}
for f in "${FILES[@]}"; do
 [[ -f "$LIVE/$f" && ! -L "$LIVE/$f" ]] || {
   echo "CONFLICTO: $f no es archivo regular. PRODUCCIÓN INTACTA." >&2; exit 1;
 }
 mkdir -p "$TMP/stage/$(dirname "$f")"
 cp -p "$LIVE/$f" "$TMP/stage/$f"
 HASHES["$f"]="$(sha256sum "$LIVE/$f" | cut -d' ' -f1)"
 p="$TMP/$(basename "$f").patch"
 git -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$f" > "$p"
 [[ -s "$p" ]] || {
   echo "ERROR: no hay parche verificable para $f. PRODUCCIÓN INTACTA." >&2; exit 1;
 }
 if (cd "$TMP/stage" && git apply --reverse --check "$p" >/dev/null 2>&1); then
   echo "YA ACTUALIZADO: $f"
 elif (cd "$TMP/stage" && git apply --check "$p" >/dev/null 2>&1); then
   (cd "$TMP/stage" && git apply "$p")
   echo "PARCHE COMPATIBLE: $f"
 elif [[ "$f" == "app/controllers/TelefoniaController.php" ]] &&
      controller_contexto_compatible "$TMP/stage/$f"; then
   # El PR sólo cambia un log de fallo opcional; no reemplazar el PHP
   # personalizado si ya conserva consulta, autorización y traspaso.
   echo "CONSERVADO: $f (contexto funcional; sólo se omite log opcional)"
 else
   CONFLICTS+=("$f")
   echo "CONFLICTO: $f requiere adaptación; no se aplicarán cambios." >&2
 fi
 if ! cmp -s "$LIVE/$f" "$TMP/stage/$f"; then CHANGED+=("$f"); fi
done
if (( ${#CONFLICTS[@]} )); then
 echo "CONFLICTO: ${#CONFLICTS[@]} archivos incompatibles. PRODUCCIÓN INTACTA." >&2
 echo "Si faltan los métodos de contexto en TelefoniaController.php, necesitan fusionarse antes de publicar." >&2
 exit 1
fi
# No continuar si el controlador desplegado omitía el traspaso. Pasar el
# parche de logging o conservar una variante realmente funcional.
if ! controller_contexto_compatible "$TMP/stage/app/controllers/TelefoniaController.php"; then
 echo "CONFLICTO: faltan funciones de contexto institucional en TelefoniaController.php. PRODUCCIÓN INTACTA." >&2
 exit 1
fi
for f in "${FILES[@]}"; do
 case "$f" in
  *.php) php -l "$TMP/stage/$f" >/dev/null ;;
  *.js)
   if command -v node >/dev/null 2>&1; then
     node --check "$TMP/stage/$f"
   fi ;;
 esac
done
grep -Fq 'duracion_observada_segundos' "$TMP/stage/app/services/TelefoniaAnalistaWebrtcService.php"
grep -Fq 'impe:crm:call-summary:' "$TMP/stage/public/javascript/telefonia_analista_historial_pasivo.js"
grep -Fq 'data-incoming-confirmed-name' "$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
grep -Fq 'data-current-user' "$TMP/stage/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
if [[ "${#CHANGED[@]}" == 0 ]]; then
 echo "OK: contexto, duración y formulario ya publicados."
 exit 0
fi
if [[ "$MODE" == "--check" ]]; then
 echo "OK: flujo de recepción y resultado compatible. PRODUCCIÓN INTACTA."
 echo "Se modificarán ${#CHANGED[@]} archivos; motor SIP, shell y DTMF intactos."
 echo "Al finalizar próximas llamadas WebRTC, se añadirá de forma compatible"
 echo "la columna auxiliar de duración observada; no acredita llamada efectiva."
 exit 0
fi
for f in "${FILES[@]}"; do
 [[ "$(sha256sum "$LIVE/$f" | cut -d' ' -f1)" == "${HASHES[$f]}" ]] || {
   echo "CONFLICTO: $f cambió antes de publicar. PRODUCCIÓN INTACTA." >&2; exit 1;
 }
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/contexto-duracion-formulario-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$BACKUP"
for f in "${CHANGED[@]}"; do
 mkdir -p "$BACKUP/$(dirname "$f")"
 cp -p "$LIVE/$f" "$BACKUP/$f"
done
rollback() {
 code=$?
 trap - EXIT
 if [[ "$code" -ne 0 ]]; then
  for f in "${CHANGED[@]}"; do
   cp -p "$BACKUP/$f" "$LIVE/$f"
  done
  echo "ERROR: se restauró $BACKUP" >&2
 fi
 exit "$code"
}
trap rollback EXIT
for f in "${CHANGED[@]}"; do
 cp -p "$TMP/stage/$f" "$LIVE/$f"
 chmod 0644 "$LIVE/$f"
 cmp -s "$TMP/stage/$f" "$LIVE/$f"
done
for f in "${CHANGED[@]}"; do
 case "$f" in
  *.php) php -l "$LIVE/$f" >/dev/null ;;
  *.js)
   if command -v node >/dev/null 2>&1; then node --check "$LIVE/$f"; fi ;;
 esac
done
trap - EXIT
echo "OK: contexto, duración y formulario publicados. Respaldo: $BACKUP"
echo "Confirmar con llamada real 100 -> 101, sin alterar el transporte WebRTC."
