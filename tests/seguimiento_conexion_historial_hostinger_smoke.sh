#!/usr/bin/env bash
# Simula Hostinger con versiones anteriores; no altera la BD ni SIP.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
LIVE="$TMP/site"
mkdir -p "$LIVE" "$TMP/backups"
mkdir -p "$LIVE/$(dirname "app/services/ZadarmaWebhookEventStoreService.php")"
git show "270e1fdc53916ba56dead7e187924c9e11b5dd32:app/services/ZadarmaWebhookEventStoreService.php" > "$LIVE/app/services/ZadarmaWebhookEventStoreService.php"
mkdir -p "$LIVE/$(dirname "prueba_telefonia/api/llamadas_seguimiento.php")"
git show "1f40e561faa62c3ea57d703e96eda319abaabf5e:prueba_telefonia/api/llamadas_seguimiento.php" > "$LIVE/prueba_telefonia/api/llamadas_seguimiento.php"
mkdir -p "$LIVE/$(dirname "public/javascript/seguimiento_llamadas_expediente.js")"
git show "a4e6545306dfed9c931e1f73bd048ac9fd17c9b2:public/javascript/seguimiento_llamadas_expediente.js" > "$LIVE/public/javascript/seguimiento_llamadas_expediente.js"
mkdir -p "$LIVE/$(dirname "public/javascript/seguimiento_llamadas_desplegable.js")"
git show "ef18da8d0c4db428f957bb318f29b934a5a415e6:public/javascript/seguimiento_llamadas_desplegable.js" > "$LIVE/public/javascript/seguimiento_llamadas_desplegable.js"
export IMPE_CONEXION_SOURCE="$ROOT"
export IMPE_CONEXION_PUBLIC="$LIVE"
export IMPE_CONEXION_BACKUPS="$TMP/backups"
bash tools/desplegar_conexion_historial_llamadas_hostinger.sh --check | grep -q "PRODUCCIÓN INTACTA"
git show "270e1fdc53916ba56dead7e187924c9e11b5dd32:app/services/ZadarmaWebhookEventStoreService.php" | cmp - "$LIVE/app/services/ZadarmaWebhookEventStoreService.php"
git show "1f40e561faa62c3ea57d703e96eda319abaabf5e:prueba_telefonia/api/llamadas_seguimiento.php" | cmp - "$LIVE/prueba_telefonia/api/llamadas_seguimiento.php"
git show "a4e6545306dfed9c931e1f73bd048ac9fd17c9b2:public/javascript/seguimiento_llamadas_expediente.js" | cmp - "$LIVE/public/javascript/seguimiento_llamadas_expediente.js"
git show "ef18da8d0c4db428f957bb318f29b934a5a415e6:public/javascript/seguimiento_llamadas_desplegable.js" | cmp - "$LIVE/public/javascript/seguimiento_llamadas_desplegable.js"
bash tools/desplegar_conexion_historial_llamadas_hostinger.sh --apply | grep -q 'historial optimizada'
git show "1f40e561faa62c3ea57d703e96eda319abaabf5e:app/services/ZadarmaWebhookEventStoreService.php" | cmp - "$LIVE/app/services/ZadarmaWebhookEventStoreService.php"
git show "a4e6545306dfed9c931e1f73bd048ac9fd17c9b2:prueba_telefonia/api/llamadas_seguimiento.php" | cmp - "$LIVE/prueba_telefonia/api/llamadas_seguimiento.php"
git show "ef18da8d0c4db428f957bb318f29b934a5a415e6:public/javascript/seguimiento_llamadas_expediente.js" | cmp - "$LIVE/public/javascript/seguimiento_llamadas_expediente.js"
git show "dc3a02f8cdaeedc9aad006d08c0c81a9de592d9b:public/javascript/seguimiento_llamadas_desplegable.js" | cmp - "$LIVE/public/javascript/seguimiento_llamadas_desplegable.js"
bash tools/desplegar_conexion_historial_llamadas_hostinger.sh --check | grep -q 'ya actualizado'
[[ -n "$(find "$TMP/backups" -type f)" ]]
echo "Historial SQL de Hostinger: OK (sintaxis, check, apply, idempotencia, respaldo)"
