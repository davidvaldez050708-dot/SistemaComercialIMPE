#!/usr/bin/env bash
# Verificación aislada del flujo de llamadas internas y publicación selectiva.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SDK="app/services/TelefoniaAnalistaWebrtcService.php"
ENTRANTES="app/services/TelefoniaEntrantesAnalistaService.php"
CONTROLADOR="app/controllers/SeguimientoVinculacionController.php"
REC="app/services/ZadarmaRecordingService.php"
VISTA="app/views/seguimiento_vinculacion/llamadas_entrantes.php"
JS="public/javascript/telefonia_analista_historial_pasivo.js"
UI_JS="public/javascript/seguimiento_entrantes_analista.js"
CSS="public/css/seguimiento_entrantes_analista.css"
SERVICIO="app/services/TelefoniaInternaGrabacionService.php"
ENDPOINT="prueba_telefonia/api/grabacion_interna_analista.php"
REPARACION="tools/reparar_prueba_interna_100_101.php"

for file in "$SDK" "$ENTRANTES" "$CONTROLADOR" "$REC" "$VISTA" "$SERVICIO" "$ENDPOINT" "$REPARACION"; do
  php -l "$file" >/dev/null
done
node --check "$JS"
node --check "$UI_JS"
bash -n tools/desplegar_llamadas_internas_analista_hostinger.sh

grep -Fq "esNumeroInterno" "$SDK"
grep -Fq "clasificacion = 'INTERNA'" "$SDK"
grep -Fq "esNumeroInterno(" "$ENTRANTES"
grep -Fq "Grabación pendiente de vinculación" "$VISTA"
grep -Fq "grabacion_interna_analista.php" "$VISTA"
grep -Fq "No tienes acceso a las grabaciones" "$ENDPOINT"
grep -Fq "s.clasificacion = 'INTERNA'" "$SERVICIO"
grep -Fq "getPbxRecord(" "$REC"
grep -Fq "fecha_inicio BETWEEN ? AND ?" "$REPARACION"
grep -Fq -- "--confirmar-prueba-interna" "$REPARACION"
grep -Fq "original_json LONGTEXT" "$REPARACION"
grep -Fq "affected_rows !== 1" "$REPARACION"
grep -Fq "sincronizar(" "$SERVICIO"
grep -Fq "sincronizarGrabacionInterna" "$CONTROLADOR"
grep -Fq "data-incoming-sync-audio" "$VISTA"
grep -Fq "data-audio-sync-url" "$VISTA"
grep -Fq "audioSyncUrl" "$UI_JS"
grep -Fq "isInternal" "$JS"
grep -Fq "resultBtn.hidden = Boolean(finished.isInternal)" "$JS"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/site"
mkdir -p "$SITE" "$TMP/backups"

FILES=("$SDK" "$ENTRANTES" "$CONTROLADOR" "$REC" "$VISTA" "$JS" "$CSS" "$UI_JS")
BASES=(
  ee4cc0b3ce23b22e961e7baa82f8ce68315fcef0
  ce7d291e95cc14e13bca40e40d488d98ed2dc6ed
  ee1c2348231498c8a5b7b9e2a5235cf2dfa2f1b1
  9288c29dda42416184a87e7d00c8465004b013a0
  a1c80214ad954540940cba6a3704fc1cfae17d58
  69d55b1920884814e30a0ae34ecd90243ae99581
  4e41519df08c9265b042fdc39b239bb482391ebe
  6faf88e90813775cec3ae024e1e6dc585f2fc233
)
TARGET=053ee9459df1e234683b730e37f5fcf3cc9292f8
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  mkdir -p "$SITE/$(dirname "$file")"
  git show "${BASES[$i]}:$file" > "$SITE/$file"
done
export IMPE_INTERNAS_SOURCE="$ROOT"
export IMPE_INTERNAS_PUBLIC="$SITE"
export IMPE_INTERNAS_BACKUPS="$TMP/backups"
bash tools/desplegar_llamadas_internas_analista_hostinger.sh --check |
  grep -q 'PRODUCCIÓN INTACTA'
[[ ! -f "$SITE/$SERVICIO" && ! -f "$SITE/$ENDPOINT" ]]
git show "${BASES[0]}:$SDK" | cmp - "$SITE/$SDK"
bash tools/desplegar_llamadas_internas_analista_hostinger.sh --apply |
  grep -q 'publicados'
for file in "${FILES[@]}"; do
  git show "$TARGET:$file" | cmp - "$SITE/$file"
done
cmp -s "$SERVICIO" "$SITE/$SERVICIO"
cmp -s "$ENDPOINT" "$SITE/$ENDPOINT"
bash tools/desplegar_llamadas_internas_analista_hostinger.sh --check |
  grep -q 'ya publicados'
[[ -n "$(find "$TMP/backups" -type f)" ]]
echo "Llamadas internas: OK (clasificación, audio protegido, reparación con auditoría y despliegue)."
