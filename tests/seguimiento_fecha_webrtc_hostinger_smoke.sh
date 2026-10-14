#!/usr/bin/env bash
# Pruebas deterministas de fechas y despliegue sobre directorio temporal.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
HELPER="app/helpers/TelefoniaFechaHoraHelper.php"
SDK="app/services/TelefoniaAnalistaWebrtcService.php"
API="prueba_telefonia/api/llamadas_seguimiento.php"
CTRL="app/controllers/SeguimientoVinculacionController.php"
for p in "$HELPER" "$SDK" "$API" "$CTRL" tools/diagnosticar_grabacion_webrtc_analista.php; do
  php -l "$p" >/dev/null
done

php -r '
require $argv[1];
$ej = ["canal" => "LLAMADA_IP", "proveedor_externo" => "ZADARMA_WEBRTC",
    "fecha_inicio" => "2026-10-14 16:49:00", "fecha_fin" => "2026-10-14 16:49:11"];
$convertida = TelefoniaFechaHoraHelper::normalizarInteraccion($ej);
if ($convertida["fecha_inicio"] !== "2026-10-14 10:49:00" ||
    $convertida["fecha_fin"] !== "2026-10-14 10:49:11") exit(1);
$otra = $ej;
$otra["proveedor_externo"] = "ZADARMA";
if (TelefoniaFechaHoraHelper::normalizarInteraccion($otra) !== $otra) exit(2);
$manual = $ej;
$manual["proveedor_externo"] = "";
if (TelefoniaFechaHoraHelper::normalizarInteraccion($manual) !== $manual) exit(3);
if (TelefoniaFechaHoraHelper::aMexico("no es fecha") !== "no es fecha") exit(4);
echo "Fechas WEBRTC: UTC 16:49 → CDMX 10:49; otros orígenes intactos.\n";
' "$HELPER"
grep -Fq 'UTC_TIMESTAMP()' "$SDK"
grep -Fq 'TelefoniaFechaHoraHelper::normalizarInteraccion' "$API"
grep -Fq "'normalizarInteraccion'" "$CTRL"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/site"
mkdir -p "$SITE/app/services" "$SITE/app/controllers" "$SITE/prueba_telefonia/api" "$TMP/backups"
git show "089bea14b834206c14758c49e93b32a62004d443:$SDK" > "$SITE/$SDK"
git show "9a02aa20e7e96c3360a7feeeee3cc22579481986:$API" > "$SITE/$API"
git show "95e5ec098b73efcc83df367e95e2fcdfc0e53ce4:$CTRL" > "$SITE/$CTRL"
export IMPE_HORA_SOURCE="$ROOT"
export IMPE_HORA_PUBLIC="$SITE"
export IMPE_HORA_BACKUPS="$TMP/backups"
bash tools/desplegar_hora_mexico_webrtc_hostinger.sh --check | grep -q 'PRODUCCIÓN INTACTA'
[[ ! -f "$SITE/$HELPER" ]]
bash tools/desplegar_hora_mexico_webrtc_hostinger.sh --apply | grep -q 'hora WebRTC'
cmp -s "$SITE/$SDK" "$SDK"
cmp -s "$SITE/$API" "$API"
cmp -s "$SITE/$CTRL" "$CTRL"
cmp -s "$SITE/$HELPER" "$HELPER"
bash tools/desplegar_hora_mexico_webrtc_hostinger.sh --check | grep -q 'ya corregida'
[[ -n "$(find "$TMP/backups" -type f)" ]]
echo "Deploy hora WebRTC: OK (preflight, apply, respaldo, idempotencia)"
