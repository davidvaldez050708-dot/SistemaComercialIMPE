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
grep -Fq 'AND d.referencia = ?' app/services/TelefoniaTransferenciaContextoService.php
grep -Fq 'data-incoming-confirmed' app/views/telefonia/resultado_analista_form.php
bash -n tools/desplegar_contexto_duracion_formulario_hostinger.sh
bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --check | grep -Fq 'PRODUCCIÓN INTACTA'
for f in "${FILES[@]}"; do git show "$BASE:$f" | cmp -s - "$IMPE_FLOW_PUBLIC/$f"; done
bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --apply | grep -Fq 'publicados. Respaldo:'
# Este instalador histórico publica el snapshot TARGET fijo; la versión
# actual del observador puede contener mejoras posteriores (p. ej. quitar
# el toast). Comparar con TARGET, no contra HEAD del repositorio.
TARGET="4575ca9159664625c36f78f34eb089a74a3b9853"
for f in "${FILES[@]}"; do
 git show "$TARGET:$f" | cmp -s - "$IMPE_FLOW_PUBLIC/$f"
done
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/telefonia_host.js" | cut -d' ' -f1)" == "$sip" ]]
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/telefonia_shell.js" | cut -d' ' -f1)" == "$shell" ]]
test -n "$(find "$TMP/backups" -type f)"
bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --check | grep -Fq 'ya publicados'
# Hostinger: PHP con comentario propio en el mismo hunk donde GitHub
# sólo agrega un log opcional. Se CONSERVA sin sobrescribir la función.
export IMPE_FLOW_PUBLIC="$TMP/controller-custom"
mkdir -p "$IMPE_FLOW_PUBLIC"
touch "$IMPE_FLOW_PUBLIC/index.php"
for f in "${FILES[@]}"; do
 mkdir -p "$IMPE_FLOW_PUBLIC/$(dirname "$f")"
 git show "$BASE:$f" > "$IMPE_FLOW_PUBLIC/$f"
done
python3 - "$IMPE_FLOW_PUBLIC/app/controllers/TelefoniaController.php" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
old='                    (new TelefoniaTransferenciaContextoService())->registrar('
assert s.count(old)==1
s=s.replace(old,'                    // HOSTINGER_AUTORIZACION_PERSONALIZADA\n'+old)
p.write_text(s)
PY
controllerhash="$(sha256sum "$IMPE_FLOW_PUBLIC/app/controllers/TelefoniaController.php" | cut -d' ' -f1)"
customCheck="$(bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --check)"
grep -Fq 'CONSERVADO: app/controllers/TelefoniaController.php' <<< "$customCheck"
grep -Fq 'OK: flujo de recepción y resultado compatible. PRODUCCIÓN INTACTA.' <<< "$customCheck"
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/app/controllers/TelefoniaController.php" | cut -d' ' -f1)" == "$controllerhash" ]]
bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --apply |
 grep -Fq 'OK: contexto, duración y formulario publicados.'
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/app/controllers/TelefoniaController.php" | cut -d' ' -f1)" == "$controllerhash" ]]
grep -Fq 'HOSTINGER_AUTORIZACION_PERSONALIZADA' "$IMPE_FLOW_PUBLIC/app/controllers/TelefoniaController.php"
grep -Fq 'data-incoming-confirmed-name' "$IMPE_FLOW_PUBLIC/app/views/seguimiento_vinculacion/llamadas_entrantes.php"

# Si el controlador no implementa el traspaso, no fingir compatibilidad.
export IMPE_FLOW_PUBLIC="$TMP/controller-incompleto"
mkdir -p "$IMPE_FLOW_PUBLIC"
touch "$IMPE_FLOW_PUBLIC/index.php"
for f in "${FILES[@]}"; do
 mkdir -p "$IMPE_FLOW_PUBLIC/$(dirname "$f")"
 git show "$BASE:$f" > "$IMPE_FLOW_PUBLIC/$f"
done
sed -i 's/public function contextoTransferencia()/public function contextoTransferenciaIncompleta()/' "$IMPE_FLOW_PUBLIC/app/controllers/TelefoniaController.php"
incompleteHash="$(sha256sum "$IMPE_FLOW_PUBLIC/app/controllers/TelefoniaController.php" | cut -d' ' -f1)"
if bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --apply > "$TMP/incomplete.log" 2>&1; then
 echo 'ERROR: controlador sin consulta de Recepción fue aceptado' >&2; exit 1
fi
grep -Fq 'faltan funciones de contexto institucional' "$TMP/incomplete.log"
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/app/controllers/TelefoniaController.php" | cut -d' ' -f1)" == "$incompleteHash" ]]

export IMPE_FLOW_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_FLOW_PUBLIC"
touch "$IMPE_FLOW_PUBLIC/index.php"
for f in "${FILES[@]}"; do
 mkdir -p "$IMPE_FLOW_PUBLIC/$(dirname "$f")"
 git show "$BASE:$f" > "$IMPE_FLOW_PUBLIC/$f"
done
# Alterar una línea que el parche reemplaza, no una función ajena.
# Un cambio en el contexto de usuario sí debe rechazar el despliegue.
sed -i 's/const userId = Number(window.IMPE_CURRENT_USER_ID || 0);/const userId = Number(window.IMPE_CURRENT_USER_ID || 777);/' "$IMPE_FLOW_PUBLIC/public/javascript/seguimiento_entrantes_analista.js"
grep -Fq 'IMPE_CURRENT_USER_ID || 777' "$IMPE_FLOW_PUBLIC/public/javascript/seguimiento_entrantes_analista.js"
checksum="$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/seguimiento_entrantes_analista.js" | cut -d' ' -f1)"
if bash tools/desplegar_contexto_duracion_formulario_hostinger.sh --apply > "$TMP/error.log" 2>&1; then
 echo 'ERROR: parche alteró una personalización incompatible' >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/error.log"
[[ "$(sha256sum "$IMPE_FLOW_PUBLIC/public/javascript/seguimiento_entrantes_analista.js" | cut -d' ' -f1)" == "$checksum" ]]
echo 'Contexto y resultado: OK (duración WebRTC, seguridad, formulario y rollback).'
