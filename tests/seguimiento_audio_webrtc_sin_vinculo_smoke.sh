#!/usr/bin/env bash
# Verifica que no se oculte la auditoría de BD ni se inventen grabaciones.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
API="prueba_telefonia/api/llamadas_seguimiento.php"
JS="public/javascript/seguimiento_llamadas_expediente.js"

php -l "$API" >/dev/null
node --check "$JS"
grep -Fq '$marcadorWebrtcSinPbx,' "$API"
grep -Fq "'grabacion_sin_vincular' =>" "$API"
grep -Fq 'strpos($notas, $marcadorWebrtcSinPbx)' "$API"
grep -Fq "'ZADARMA_WEBRTC'" "$API"
grep -Fq "Grabación no vinculada" "$JS"
grep -Fq "Contacto reportado" "$JS"
grep -Fq "convertirBoton(tarjeta, llamada)" public/javascript/seguimiento_llamadas_desplegable.js
grep -Fq "'grabacion_url' => \$puedeTenerGrabacion" "$API"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/site"
mkdir -p "$SITE/$(dirname "$API")" "$SITE/$(dirname "$JS")" "$TMP/backups"
git show "c8166bf7c5136622b3ec7ca34a415c2bfb97237d:$API" > "$SITE/$API"
git show "348d7d616d5e63b0ff6a2c5407cdcbc06dd03831:$JS" > "$SITE/$JS"
export IMPE_AUDIO_UI_SOURCE="$ROOT"
export IMPE_AUDIO_UI_PUBLIC="$SITE"
export IMPE_AUDIO_UI_BACKUPS="$TMP/backups"

bash tools/desplegar_leyenda_webrtc_y_audio_hostinger.sh --check | grep -q 'PRODUCCIÓN INTACTA'
git show "c8166bf7c5136622b3ec7ca34a415c2bfb97237d:$API" | cmp - "$SITE/$API"
bash tools/desplegar_leyenda_webrtc_y_audio_hostinger.sh --apply | grep -q 'grabaciones corregida'
# Verificar sólo la versión final del parche de audio WebRTC;
# el endpoint puede contener cambios posteriores de zona horaria.
git show "33732f671453c63b1104d4aaba7a427fcab140c2:$API" | cmp - "$SITE/$API"
git show "42acda3358033cc16abd7b8816d7879a467b72f5:$JS" | cmp - "$SITE/$JS"
bash tools/desplegar_leyenda_webrtc_y_audio_hostinger.sh --check | grep -q 'ya corregida'
[[ -n "$(find "$TMP/backups" -type f)" ]]
echo "Audio WebRTC en expediente: OK (leyenda privada, audio pendiente, despliegue y respaldo)"
