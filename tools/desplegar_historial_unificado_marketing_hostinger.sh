#!/usr/bin/env bash
# Historial PBX de Marketing: entrantes + salientes de la extensión propia.
# --check no cambia producción. --apply respalda antes de publicar.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
 echo "Uso: bash tools/desplegar_historial_unificado_marketing_hostinger.sh --check|--apply" >&2
 exit 2
}
SRC="${IMPE_HISTORIAL_MARKETING_SOURCE:-$HOME/crm-preparacion}"
LIVE="${IMPE_HISTORIAL_MARKETING_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_HISTORIAL_MARKETING_BACKUP:-$HOME/respaldos-crm}"
BASE="2abcfff9e7d789a582713cb4a7f3865e760baa8c"
FILES=(
 app/services/TelefoniaRecepcionMarketingService.php
 public/javascript/telefonia_recepcion_marketing.js
 app/views/telefonia/recepcion_marketing.php
 public/css/telefonia_recepcion_marketing.css
)
[[ -d "$SRC/.git" && -f "$LIVE/index.php" ]] || {
 echo "ERROR: no se encontró el repositorio o el CRM publicado." >&2; exit 1
}
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "1/4 Preparando el historial sin tocar Hostinger..."
for p in "${FILES[@]}"; do
 [[ -f "$LIVE/$p" && ! -L "$LIVE/$p" && -f "$SRC/$p" ]] || {
  echo "ERROR: archivo ausente o enlace simbólico: $p" >&2; exit 1
 }
 mkdir -p "$TMP/$(dirname "$p")"
 if [[ "$p" == "public/css/telefonia_recepcion_marketing.css" ]]; then
   # Los estilos locales del CRM no se sobrescriben. Sólo añadir
   # nuevas clases + grilla de 5 tarjetas en escritorio.
   cp "$LIVE/$p" "$TMP/$p"
   MARKER='/* Historial único Marketing: entrantes y salientes */'
   if ! grep -Fq "$MARKER" "$TMP/$p"; then
     if grep -Eq '\.marketing-reception-direction\.(is-incoming|is-outgoing)' "$TMP/$p"; then
       echo "CONFLICTO: estilos del tipo de llamada ya personalizados; sin publicar." >&2
       exit 1
     fi
     cat >> "$TMP/$p" <<'CSS'

/* Historial único Marketing: entrantes y salientes */
.marketing-reception-direction {
 display: inline-flex; align-items: center; gap: 6px;
 padding: 5px 8px; border-radius: 8px;
 font-size: 10px; font-weight: 750; white-space: nowrap;
}
.marketing-reception-direction.is-incoming {
 color: #246282; background: #edf5fb;
}
.marketing-reception-direction.is-outgoing {
 color: #374b97; background: #edf0ff;
}
.marketing-reception-metric-icon.is-outgoing {
 color: #374b97; background: #edf0ff;
}
@media (min-width: 1051px) {
 .marketing-reception-metrics {
   grid-template-columns: repeat(5, minmax(0, 1fr));
 }
}
CSS
     echo "Estilos Tipo de llamada añadidos, manteniendo CSS personalizado."
   fi
   continue
 fi
 git -C "$SRC" show "$BASE:$p" > "$TMP/base" || {
  echo "ERROR: falta base del archivo $p." >&2; exit 1
 }
 if cmp -s "$LIVE/$p" "$SRC/$p" || cmp -s "$LIVE/$p" "$TMP/base"; then
   cp "$SRC/$p" "$TMP/$p"
 elif git merge-file -p -L HOSTINGER -L BASE -L GITHUB \
   "$LIVE/$p" "$TMP/base" "$SRC/$p" > "$TMP/$p"; then
   echo "Cambios compatibles de Hostinger conservados: $p"
 else
   echo "CONFLICTO: $p tiene cambios incompatibles; no se publicó nada." >&2
   exit 1
 fi
done
echo "2/4 Validando seguridad, estructura y sintaxis..."
php -l "$TMP/app/services/TelefoniaRecepcionMarketingService.php"
php -l "$TMP/app/views/telefonia/recepcion_marketing.php"
if command -v node >/dev/null 2>&1; then
 node --check "$TMP/public/javascript/telefonia_recepcion_marketing.js"
fi
check() { grep -Fq "$2" "$TMP/$1" || {
 echo "ERROR: falta comprobación requerida en $1: $2" >&2; exit 1;
}; }
check app/services/TelefoniaRecepcionMarketingService.php 'public static function agruparEventos('
check app/services/TelefoniaRecepcionMarketingService.php "NOTIFY_OUT_START"
check app/services/TelefoniaRecepcionMarketingService.php "NOTIFY_OUT_END"
check app/services/TelefoniaRecepcionMarketingService.php "tienePermiso('telefonia.salientes')"
check app/services/TelefoniaRecepcionMarketingService.php 'inicial.internal = ?'
check app/services/TelefoniaRecepcionMarketingService.php 'inicial.received_at >= ?'
check app/services/TelefoniaRecepcionMarketingService.php "'tiene_grabacion'"
check public/javascript/telefonia_recepcion_marketing.js 'data-marketing-reception-type'
check public/javascript/telefonia_recepcion_marketing.js "tipo === 'Saliente'"
check public/javascript/telefonia_recepcion_marketing.js 'content.colSpan = 8'
check app/views/telefonia/recepcion_marketing.php '<th>Tipo</th>'
check app/views/telefonia/recepcion_marketing.php '<th>Número</th>'
check app/views/telefonia/recepcion_marketing.php 'data-marketing-reception-outgoing'
check public/css/telefonia_recepcion_marketing.css '/* Historial único Marketing: entrantes y salientes */'
if grep -Eq '^(<<<<<<<|=======|>>>>>>>)' \
 "$TMP/app/services/TelefoniaRecepcionMarketingService.php" \
 "$TMP/public/javascript/telefonia_recepcion_marketing.js" \
 "$TMP/app/views/telefonia/recepcion_marketing.php"; then
 echo "CONFLICTO: fusión incompleta; sin publicar." >&2; exit 1
fi
CHANGED=()
for p in "${FILES[@]}"; do
 cmp -s "$TMP/$p" "$LIVE/$p" || CHANGED+=("$p")
done
if [[ "${#CHANGED[@]}" -eq 0 ]]; then
 echo "OK: historial unificado ya publicado; sin cambios."; exit 0
fi
printf 'Archivos pendientes: %s\n' "${CHANGED[@]}"
if [[ "$MODE" == "--check" ]]; then
 echo "OK: comprobación previa sin modificar producción."; exit 0
fi
echo "3/4 Respaldando todos los archivos por publicar..."
mkdir -p "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/historial-marketing-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for p in "${CHANGED[@]}"; do
 mkdir -p "$BACKUP/$(dirname "$p")"
 cp -p "$LIVE/$p" "$BACKUP/$p"
done
echo "4/4 Publicando únicamente el historial Marketing..."
for p in "${CHANGED[@]}"; do
 cp "$TMP/$p" "$LIVE/$p"
 cmp -s "$TMP/$p" "$LIVE/$p" || {
   echo "ERROR: archivo incompleto: $p. Respaldo: $BACKUP" >&2; exit 1
 }
done
echo "OK: historial unificado publicado. Sin cambios en MySQL ni Zadarma."
echo "Respaldo: $BACKUP"
