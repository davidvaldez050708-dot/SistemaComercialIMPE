#!/usr/bin/env bash
# La sección de Grabaciones jamás debe dibujarse antes de desaparecer.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
MAIN="public/javascript/seguimiento_llamadas_expediente.js"
PLAYER="public/javascript/seguimiento_llamadas_desplegable.js"
! grep -Fq 'linkage-call-recordings-section' "$MAIN"
! grep -Fq 'linkage-call-recordings-section' "$PLAYER"
! grep -Fq 'data-call-audio-list' "$MAIN"
grep -Fq 'data-call-summary-recordings' "$MAIN"
grep -Fq 'data-call-history-list' "$MAIN"
grep -Fq 'convertirBoton(tarjeta, llamada)' "$PLAYER"
node --check "$MAIN"
node --check "$PLAYER"

TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
SITE="$TEMP/site"
mkdir -p "$SITE/public/javascript" "$TEMP/backups"
git show "4a4f9857d5bcec2e73e34f93e50aa97ba96afdc1:$MAIN" > "$SITE/$MAIN"
git show "ad2703830405c3fe32ecbe0b104a7539c3d92c5c:$PLAYER" > "$SITE/$PLAYER"
export IMPE_GRABACIONES_SOURCE="$ROOT"
export IMPE_GRABACIONES_PUBLIC="$SITE"
export IMPE_GRABACIONES_BACKUPS="$TEMP/backups"
bash tools/desplegar_grabaciones_sin_parpadeo_hostinger.sh --check | grep -q 'PRODUCCIÓN INTACTA'
git show "4a4f9857d5bcec2e73e34f93e50aa97ba96afdc1:$MAIN" | cmp - "$SITE/$MAIN"
bash tools/desplegar_grabaciones_sin_parpadeo_hostinger.sh --apply | grep -q 'grabaciones sin parpadeo'
cmp -s "$MAIN" "$SITE/$MAIN"
cmp -s "$PLAYER" "$SITE/$PLAYER"
bash tools/desplegar_grabaciones_sin_parpadeo_hostinger.sh --check | grep -q 'ya fue eliminado'
[[ -n "$(find "$TEMP/backups" -type f)" ]]
echo 'Grabaciones sin parpadeo: OK (sin doble bloque, reproductor, validación, respaldo)'
