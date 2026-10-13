#!/usr/bin/env bash
# Ajustes telefónicos de Marketing: --check (lectura) / --apply (respaldo).
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || exit 2
SRC="${IMPE_MARKETING_AJUSTES_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_MARKETING_AJUSTES_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_MARKETING_AJUSTES_BACKUP:-$HOME/respaldos-crm}"
BASE="c9e9506479d99347bf5b5225894bbdef987d27c9"
FILES=(
 public/javascript/telefonia_shell.js
 public/javascript/telefonia_recepcion_marketing.js
 public/css/telefonia_recepcion_marketing.css
 app/views/telefonia/recepcion_marketing.php
 app/services/ZadarmaRecordingService.php
 prueba_telefonia/api/grabacion_recepcion_marketing.php
)
[[ -d "$SRC/.git" && -f "$LIVE/index.php" ]] || {
 echo "ERROR: falta el repositorio o la instalación Hostinger." >&2; exit 1;
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Preparando seis archivos sin tocar producción..."
for p in "${FILES[@]}"; do
 [[ -f "$SRC/$p" && -f "$LIVE/$p" && ! -L "$LIVE/$p" ]] || {
   echo "ERROR: archivo no encontrado o enlace simbólico: $p" >&2; exit 1;
 }
 mkdir -p "$TMP/$(dirname "$p")"
 git -C "$SRC" show "$BASE:$p" > "$TMP/base" || {
   echo "ERROR: falta base Git para $p." >&2; exit 1;
 }
 if [[ "$p" == "public/css/telefonia_recepcion_marketing.css" ]]; then
   # Sólo cambió el bloque de Llamar. Una fusión textual del CSS entero
   # entra en conflicto con personalizaciones válidas de Hostinger.
   # Este publicador histórico sólo integra el bloque Llamar.
   # Los estilos posteriores (Tipo/Salientes) tienen su propio publicador.
   # Evitar incluir cambios posteriores fuera del bloque permitido.
   git -C "$SRC" show "2abcfff9e7d789a582713cb4a7f3865e760baa8c:$p" \
     > "$TMP/css-boton-llamar" || {
       echo "ERROR: falta versión CSS histórica de Llamar." >&2; exit 1
     }
   php "$SRC/tools/fusionar_css_recepcion_marketing.php" \
     "$TMP/base" "$TMP/css-boton-llamar" "$LIVE/$p" "$TMP/$p" || {
       echo "CONFLICTO: no se integró el CSS de recepción; nada publicado." >&2
       exit 1
     }
 elif cmp -s "$LIVE/$p" "$SRC/$p" || cmp -s "$LIVE/$p" "$TMP/base"; then
   cp "$SRC/$p" "$TMP/$p"
 elif [[ "$p" == "public/javascript/telefonia_shell.js" &&
         "$(git -C "$SRC" hash-object "$LIVE/$p")" == "45e80cccb3e104917aee74122b21ebf8a68498cf" ]]; then
   # Preservar únicamente las tres líneas del aviso eliminadas en Hostinger.
   sed -e '/El aviso de conexión y "Mostrar llamada" son ambos flotantes:/d' \
       -e '/nunca apilarlos uno encima del otro./d' \
       -e "/status\\.classList\\.toggle('is-raised', !restore\\.hidden);/d" \
       "$SRC/$p" > "$TMP/$p"
   echo "Shell Hostinger reconocido; conservado ajuste del aviso."
 elif git merge-file -p -L HOSTINGER -L BASE -L GITHUB \
        "$LIVE/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p"; then
   echo "Personalización compatible conservada: $p"
 else
   echo "CONFLICTO: $p incompatible. No se publicó ningún archivo." >&2
   exit 1
 fi
done
echo "2/4 Validando integridad y sintaxis..."
for p in app/views/telefonia/recepcion_marketing.php \
         app/services/ZadarmaRecordingService.php \
         prueba_telefonia/api/grabacion_recepcion_marketing.php; do
 php -l "$TMP/$p"
done
if command -v node >/dev/null 2>&1; then
 node --check "$TMP/public/javascript/telefonia_shell.js"
 node --check "$TMP/public/javascript/telefonia_recepcion_marketing.js"
fi
verify() { grep -Fq "$2" "$TMP/$1" || {
 echo "ERROR: marcador obligatorio no encontrado en $1: $2" >&2; exit 1; }; }
verify public/javascript/telefonia_shell.js 'syncIncomingClock(value)'
verify public/javascript/telefonia_shell.js "muteButton.classList.toggle('is-active'"
verify public/javascript/telefonia_recepcion_marketing.js 'puedeLlamarAlRegistro(call)'
verify public/javascript/telefonia_recepcion_marketing.js "url.searchParams.set('verificar', '1')"
verify public/css/telefonia_recepcion_marketing.css '.marketing-reception-call'
verify app/views/telefonia/recepcion_marketing.php '<th>Llamar</th>'
verify app/services/ZadarmaRecordingService.php '^[a-zA-Z0-9_-]{6,100}$'
verify prueba_telefonia/api/grabacion_recepcion_marketing.php "'verificar'"
CHANGED=()
for p in "${FILES[@]}"; do
 cmp -s "$TMP/$p" "$LIVE/$p" || CHANGED+=("$p")
done
if [[ "${#CHANGED[@]}" -eq 0 ]]; then
 echo "OK: ajustes de Marketing ya publicados, sin cambios."; exit 0
fi
printf 'Archivos pendientes: %s\n' "${CHANGED[@]}"
if [[ "$MODE" == "--check" ]]; then
 echo "OK: comprobación previa sin modificar producción."; exit 0
fi
echo "3/4 Respaldando las versiones anteriores..."
mkdir -p "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/marketing-ajustes-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for p in "${CHANGED[@]}"; do
 mkdir -p "$BACKUP/$(dirname "$p")"
 cp -p "$LIVE/$p" "$BACKUP/$p"
done
echo "4/4 Publicando únicamente los seis cambios..."
for p in "${CHANGED[@]}"; do
 cp "$TMP/$p" "$LIVE/$p"
 cmp -s "$TMP/$p" "$LIVE/$p" || {
 echo "ERROR: $p no coincide. Respaldo: $BACKUP" >&2; exit 1; }
done
echo "OK: Marketing actualizado, sin cambios en Zadarma ni MySQL."
echo "Respaldo: $BACKUP"
echo "Cierra todas las pestañas del CRM antes de probar."
