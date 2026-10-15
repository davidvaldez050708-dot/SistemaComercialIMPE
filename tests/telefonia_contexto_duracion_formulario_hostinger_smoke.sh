#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
BASE="53f94403d5aae63d8b02de4203c496c87cfb47b3"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_FLOW_SOURCE="$PWD" IMPE_FLOW_BACKUPS="$TMP/backups" IMPE_FLOW_PUBLIC="$TMP/live"
FILES=(
 "app/controllers/TelefoniaController.php"
 "app/services/TelefoniaTransferenciaContextoService.php"
 "app/services/TelefoniaAnalistaWebrtcService.php"
 "public/javascript/telefonia_analista_historial_pasivo.js"
 "public/javascript/seguimiento_entrantes_analista.js"
 "app/views/seguimiento_vinculacion/llamadas_entrantes.php"
 "public/css/seguimiento_entrantes_analista.css"
)
mkdir -p "$IMPE_FLOW_PUBLIC"
touch "$IMPE_FLOW_PUBLIC/index.php"
for f in "${FILES[@]}"; do
 mkdir -p "$IMPE_FLOW_PUBLIC/$(dirname "$f")"
 git show "$BASE:$f" > "$IMPE_FLOW_PUBLIC/$f"
done
echo '/* motor SIP intocable */' > "$IMPE_FLOW_PUBLIC/public/javascript/telefonia_host.js"
echo '/* shell personalizado intocable */' > "$IMPE_FLOW_PUBLIC/public/javascript/telefonia_shell.js"
sip="$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)"
shell="$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)"
node tests/telefonia_analista_resultado_transferido_smoke.js
php tests/telefonia_resultado_analista_modal_smoke.php
for f in app/services/TelefoniaAnalistaWebrtcService.php app/services/TelefoniaTransferenciaContextoService.php app/controllers/TelefoniaController.php app/views/seguimiento_vinculacion/llamadas_entrantes.php; do php -l "$f" >/dev/null; done
node --check public/javascript/seguimiento_entrantes_analista.js
node --check public/javascript/telefonia_analista_historial_pasivo.js
grep -Fq 'TIMESTAMPDIFF(SECOND, fecha_inicio, fecha_fin) + 3' app/services/TelefoniaAnalistaWebrtcService.php
grep -Fq 'INTERVAL 5 MINUTE' app/services/TelefoniaTransferenciaContextoService.php
grep -Fq 'data-incoming-confirmed' app/views/seguimiento_vinculacion/llamadas_entrantes.php
bash -n tools/desplegar_contexto_duracion_formulario_hostinger.sh
bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --check | grep -Fq 'PRODUCCIÓN INTACTA'
for f in "${FILES[@]}"; do git show "$BASE:$f" | cmp -s - "$IMPE_FLOW_PUBLIC/$f"; done
bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --apply | grep -Fq 'publicados. Respaldo:'
for f in "${FILES[@]}"; do cmp -s "$f" "$IMPE_FLOW_PUBLIC/$f"; done
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)" == "$sip" ]]
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)" == "$shell" ]]
test -n "$(find "$TMP/backups" -type f)"
bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --check | grep -Fq 'ya publicados'
export IMPE_FLOW_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_FLOW_PUBLIC"
touch "$IMPE_FLOW_PUBLIC/index.php"
for f in "${FILES[@]}"; do
 mkdir -p "$IMPE_FLOW_PUBLIC/$(dirname "$f")"
 git show "$BASE:$f" > "$IMPE_FLOW_PUBLIC/$f"
done
sed -i 's/const openRegistration = button => {/const openRegistration = async button => {/' "$IMPE_FLOW_PUBLIC/public/javascript/seguimiento_entrantes_analista.js"
checksum="$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/seguimiento_entrantes_analista.js" | cut -d' ' -f1)"
if bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --apply > "$TMP/error.log" 2>&1; then
 echo 'ERROR: parche alteró una personalización incompatible' >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/error.log"
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/seguimiento_entrantes_analista.js" | cut -d' ' -f1)" == "$checksum" ]]
echo 'Contexto y resultado: OK (duración WebRTC, seguridad, formulario y rollback).'
