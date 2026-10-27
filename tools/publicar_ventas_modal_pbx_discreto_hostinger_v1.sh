#!/usr/bin/env bash
# Ventas: oculta el primer modal provisional y conserva el panel confirmado.
# Publicación de SOLO un JS exacto de Hostinger, sin SQL, PHP ni telefonía SDK.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash publicar_ventas_modal_pbx_discreto_hostinger_v1.sh --check|--apply" >&2
  exit 2
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
WORK="$HOME/entregas-impe/ventas-modal-pbx-discreto-v1"
BACKUPS="$HOME/respaldos-impe-ventas-modal-pbx-discreto-v1"
JS="public/javascript/telefonia_ventas_shell.js"
SHELL="public/javascript/telefonia_shell.js"
RESULTS="public/javascript/telefonia_ventas_resultados.js"
REV="22cd33427eea335c95bec23088b86ec900974f39"
URL="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV/$JS"
BEFORE="60f76f9d720dd3f37a4554510008ca32ef448b0a"
AFTER="44f22188d133565645adf8e6842f0bdd299beb6f"
SHELL_SHA="67fcac018a3557239329217f507484573e4524ff"
RESULTS_SHA="d6b92a3898ef2c01ee2abfd7a28c82c5dc277654"
for tool in git curl bash cmp cp mv mktemp grep; do
  command -v "$tool" >/dev/null || {
    echo "ERROR: falta $tool. SITIO INTACTO." >&2;exit 1;
  }
done
for file in "$JS" "$SHELL" "$RESULTS"; do
  [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] || {
    echo "ERROR: archivo ausente o enlace simbólico $file. SITIO INTACTO." >&2;exit 1;
  }
done
[[ "$(git hash-object "$SITE/$SHELL")" == "$SHELL_SHA" ]] || {
  echo "CONFLICTO: telefonia_shell.js cambió. No se toca ningún archivo." >&2;exit 1;
}
[[ "$(git hash-object "$SITE/$RESULTS")" == "$RESULTS_SHA" ]] || {
  echo "CONFLICTO: telefonia_ventas_resultados.js cambió. No se toca ningún archivo." >&2;exit 1;
}
CURRENT="$(git hash-object "$SITE/$JS")"
[[ "$CURRENT" == "$BEFORE" || "$CURRENT" == "$AFTER" ]] || {
  echo "CONFLICTO: el observador comercial cambió ($CURRENT). SITIO INTACTO." >&2;exit 1;
}
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXXXX")"
BACKUP=""
WRITING=0
DONE=0
cleanup(){
  local code=$?
  trap - EXIT
  if [[ "$WRITING" == "1" && "$DONE" != "1" ]];then
    echo "ERROR: restaurando copia privada del observador comercial..." >&2
    cp -p "$BACKUP/original.js" "$SITE/$JS" ||
      echo "RECUPERAR MANUALMENTE: $BACKUP/original.js" >&2
  fi
  rm -rf "$STAGE"
  exit "$code"
}
trap cleanup EXIT
echo "1. Descargando JavaScript conciliado con Hostinger..."
curl -fLsS --retry 2 "$URL" -o "$STAGE/ventas_shell.js"
[[ "$(git hash-object "$STAGE/ventas_shell.js")" == "$AFTER" ]] || {
  echo "ERROR: JavaScript no coincide con revisión verificada. SITIO INTACTO." >&2;exit 1;
}
echo "2. Revisando compatibilidad, diseño y alcance..."
grep -Fq 'IMPE_VENTAS_PANEL_PBX_DISCRETO_V1_20261027' "$STAGE/ventas_shell.js"
grep -Fq 'confirmFinishedCall' "$STAGE/ventas_shell.js"
grep -Fq 'impe-sales-pbx-pending-v1' "$STAGE/ventas_shell.js"
grep -Fq 'Verificando cierre de llamada con Zadarma' "$STAGE/ventas_shell.js"
grep -Fq 'state.direction===' "$STAGE/ventas_shell.js"
grep -Fq 'IMPE_SALES_PROSPECT_CAPTURE' "$STAGE/ventas_shell.js"
grep -Fq 'bindTransferCandidate' "$STAGE/ventas_shell.js"
if command -v node >/dev/null 2>&1;then
  node --check "$STAGE/ventas_shell.js"
elif command -v nodejs >/dev/null 2>&1;then
  nodejs --check "$STAGE/ventas_shell.js"
else
  echo 'SINTAXIS JS: comprobada previamente, huella SHA verificada; Node.js no requerido.'
fi
echo "COMPATIBLE: Ventas, resumen provisional y guardas PBX. Otros módulos intactos."
if [[ "$MODE" == "--check" ]];then
  echo "REVISIÓN COMPATIBLE. SITIO INTACTO. Sin SQL de escritura."
  exit 0
fi
if [[ "$CURRENT" == "$AFTER" ]];then
  echo "YA APLICADO. No hay archivos por sustituir."
  exit 0
fi
echo "3. Respaldando el archivo exacto antes de instalar..."
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/lote-$(date +%Y%m%d-%H%M%S)-XXXXXXXX")"
cp -p "$SITE/$JS" "$BACKUP/original.js"
cmp -s "$SITE/$JS" "$BACKUP/original.js" || {
  echo "CONFLICTO: cambio durante revisión. SITIO INTACTO." >&2;exit 1;
}
TMP="$SITE/$JS.impe-pbx-v1.$$"
cp "$STAGE/ventas_shell.js" "$TMP"
chmod --reference="$SITE/$JS" "$TMP"
cmp -s "$SITE/$JS" "$BACKUP/original.js" || {
  rm -f "$TMP";echo "CONFLICTO: se modificó el JS antes de publicar." >&2;exit 1;
}
WRITING=1
mv -f "$TMP" "$SITE/$JS"
[[ "$(git hash-object "$SITE/$JS")" == "$AFTER" ]]
DONE=1
echo "PUBLICACIÓN COMPLETA. RESPALDO: $BACKUP/original.js"
echo "Solo cambió telefonia_ventas_shell.js; sin SQL, grabaciones, llamadas o telefonía de Analistas/Marketing."
echo "En el navegador realiza recarga forzada (Ctrl+Shift+R) antes de probar."
