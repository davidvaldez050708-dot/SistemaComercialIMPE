#!/usr/bin/env bash
# Ventas: evitar que "Registrar resultado" parpadee durante Marcando/Timbrando.
# --check NO altera el CRM. --apply cambia solo el observador comercial.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
 echo "Uso: --check | --apply" >&2;exit 2;
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
WORK="$HOME/entregas-impe/ventas-sin-parpadeo-resultado-v1"
BACKUPS="$HOME/respaldos-impe-ventas-sin-parpadeo-v1"
JS="public/javascript/telefonia_ventas_shell.js"
MAIN_SHELL="public/javascript/telefonia_shell.js"
RESULTS="public/javascript/telefonia_ventas_resultados.js"
REV="f53f063cdf81febfa5c33076d9d077c165829a62"
BEFORE="024f6c0a9976d4b19f49a1d37e9870ae2b3c4634"
AFTER="f2c7ae29c4770504d500266a98ae729b1e274b39"
SHELL_SHA="67fcac018a3557239329217f507484573e4524ff"
RESULT_SHA="d6b92a3898ef2c01ee2abfd7a28c82c5dc277654"
SRC="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV/$JS"
for cmd in git curl bash cp cmp mv mktemp grep; do
 command -v "$cmd" >/dev/null || { echo "ERROR: falta $cmd. SITIO INTACTO." >&2;exit 1; }
done
for rel in "$JS" "$MAIN_SHELL" "$RESULTS"; do
 [[ -f "$SITE/$rel" && ! -L "$SITE/$rel" ]] || {
  echo "ERROR: archivo ausente o enlace simbólico: $rel. SITIO INTACTO." >&2;exit 1;
 }
done
[[ "$(git hash-object "$SITE/$MAIN_SHELL")" == "$SHELL_SHA" ]] || {
 echo "CONFLICTO: el shell compartido cambió. SITIO INTACTO." >&2;exit 1;
}
[[ "$(git hash-object "$SITE/$RESULTS")" == "$RESULT_SHA" ]] || {
 echo "CONFLICTO: el formulario de resultados cambió. SITIO INTACTO." >&2;exit 1;
}
LIVE="$(git hash-object "$SITE/$JS")"
[[ "$LIVE" == "$BEFORE" || "$LIVE" == "$AFTER" ]] || {
 echo "CONFLICTO: archivo de Ventas personalizado distinto: $LIVE" >&2
 echo "No sobrescribir: necesita conciliación. SITIO INTACTO." >&2;exit 1;
}
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXXXX")"
BACKUP=''
WRITING=0
DONE=0
cleanup(){
 local status=$?
 trap - EXIT
 if [[ "$WRITING" == 1 && "$DONE" != 1 ]]; then
  echo "PUBLICACIÓN INCOMPLETA: restaurando únicamente JS de Ventas..." >&2
  cp -p "$BACKUP/original.js" "$SITE/$JS" ||
   echo "RESTAURAR MANUALMENTE: $BACKUP/original.js" >&2
 fi
 rm -rf "$STAGE"
 exit "$status"
}
trap cleanup EXIT
echo "1. Descargando código de Ventas verificado..."
curl -fLsS --retry 2 "$SRC" -o "$STAGE/ventas.js"
[[ "$(git hash-object "$STAGE/ventas.js")" == "$AFTER" ]] || {
 echo "ERROR: versión nueva no verificada. SITIO INTACTO." >&2;exit 1;
}
echo "2. Comprobando el estado inicial y la barrera visual..."
grep -Fq 'IMPE_VENTAS_NO_REGISTRAR_DURANTE_MARCACION_V1_20261027' "$STAGE/ventas.js"
grep -Fq "const finalState=phoneFinished(state);" "$STAGE/ventas.js"
grep -Fq 'data-impe-sales-result-ready' "$STAGE/ventas.js"
grep -Fq "const outgoingReady=Boolean(task&&metadata" "$STAGE/ventas.js"
grep -Fq 'IMPE_SALES_PROSPECT_CAPTURE' "$STAGE/ventas.js"
grep -Fq 'bindTransferCandidate' "$STAGE/ventas.js"
if command -v node >/dev/null 2>&1; then
 node --check "$STAGE/ventas.js"
elif command -v nodejs >/dev/null 2>&1; then
 nodejs --check "$STAGE/ventas.js"
else
 echo 'JS: SHA verificado; la sintaxis se prueba fuera de Hostinger.'
fi
echo 'COMPATIBLE: un archivo de Ventas, sin cambios en telefonía compartida.'
if [[ "$MODE" == "--check" ]]; then
 echo 'REVISIÓN COMPATIBLE. SITIO INTACTO. Sin SQL.'
 exit 0
fi
if [[ "$LIVE" == "$AFTER" ]]; then
 echo 'YA APLICADO. Sin cambios en el CRM.'
 exit 0
fi
echo '3. Generando respaldo privado y publicando...'
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/lote-$(date +%Y%m%d-%H%M%S)-XXXXXXXX")"
cp -p "$SITE/$JS" "$BACKUP/original.js"
cmp -s "$BACKUP/original.js" "$SITE/$JS" || {
 echo 'CONFLICTO: se modificó el original antes de publicar. SITIO INTACTO.' >&2;exit 1;
}
TEMP="$SITE/$JS.impe-ventas-ux.$$"
cp "$STAGE/ventas.js" "$TEMP"
chmod --reference="$SITE/$JS" "$TEMP"
cmp -s "$BACKUP/original.js" "$SITE/$JS" || {
 rm -f "$TEMP"; echo 'CONFLICTO: modificación simultánea. SITIO INTACTO.' >&2;exit 1;
}
WRITING=1
mv -f "$TEMP" "$SITE/$JS"
[[ "$(git hash-object "$SITE/$JS")" == "$AFTER" ]]
DONE=1
echo "PUBLICACIÓN COMPLETA. RESPALDO: $BACKUP/original.js"
echo 'Solo cambió telefonia_ventas_shell.js. No se alteró SQL, datos, audios ni WebRTC.'
echo 'Actualiza con Ctrl+Shift+R antes de comprobar Marcando y Timbrando.'
