#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
SCRIPT="tools/recuperar_recepcion_analista_ultimo_historial_hostinger.sh"
BASE="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
TARGET="d63b667e8e79ed95bf33b64d356f6d72982ca2ce"
NEW="app/services/TelefoniaAnalistaWebrtcService.php"
FILES=(
  app/controllers/SeguimientoVinculacionController.php
  app/services/TelefoniaEntrantesAnalistaService.php
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  app/views/telefonia/host.php
  app/views/telefonia/shell.php
  public/javascript/telefonia_host.js
  public/javascript/telefonia_shell.js
  public/javascript/seguimiento_entrantes_analista.js
)
bash -n "$SCRIPT"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
export IMPE_RECOVERY_SOURCE="$PWD"
export IMPE_RECOVERY_BACKUPS="$tmp/backups"
export IMPE_RECOVERY_PUBLIC="$tmp/public_html"
mkdir -p "$IMPE_RECOVERY_BACKUPS" "$IMPE_RECOVERY_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_RECOVERY_PUBLIC"

# Simular respaldo original de producción, previo al historial.
BACKUP="$IMPE_RECOVERY_BACKUPS/entrantes-webrtc-reconciliado-20261014-150000-123"
mkdir -p "$BACKUP"
for file in "${FILES[@]}"; do
  mkdir -p "$BACKUP/$(dirname "$file")"
  cp -p "$IMPE_RECOVERY_PUBLIC/$file" "$BACKUP/$file"
  git show "$TARGET:$file" >"$IMPE_RECOVERY_PUBLIC/$file"
done
git show "$TARGET:$NEW" > "$IMPE_RECOVERY_PUBLIC/$NEW"
# Comprobar que la revisión de seguridad no exige borrar el observador.
git show "$BASE:public/javascript/telefonia_sdk_entrantes_observador.js" > "$tmp/observer"
beforecheck="$(bash "$SCRIPT" --check)"
grep -Fq 'PRODUCCIÓN INTACTA' <<< "$beforecheck"
[[ -f "$IMPE_RECOVERY_PUBLIC/$NEW" ]]
git show "$TARGET:public/javascript/telefonia_shell.js" |
  cmp -s - "$IMPE_RECOVERY_PUBLIC/public/javascript/telefonia_shell.js"
bash "$SCRIPT" --apply
for f in "${FILES[@]}"; do
  cmp -s "$BACKUP/$f" "$IMPE_RECOVERY_PUBLIC/$f"
done
[[ ! -e "$IMPE_RECOVERY_PUBLIC/$NEW" ]]
cmp -s "$tmp/observer" "$IMPE_RECOVERY_PUBLIC/public/javascript/telefonia_sdk_entrantes_observador.js"
ls -d "$IMPE_RECOVERY_BACKUPS"/antes-reversion-historial-webrtc-* >/dev/null

# Seguridad: si el PHP nuevo recibió cambios posteriores, no borrarlo.
export IMPE_RECOVERY_PUBLIC="$tmp/conflict"
mkdir -p "$IMPE_RECOVERY_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_RECOVERY_PUBLIC"
git show "$TARGET:$NEW" > "$IMPE_RECOVERY_PUBLIC/$NEW"
echo '// edición posterior' >> "$IMPE_RECOVERY_PUBLIC/$NEW"
if bash "$SCRIPT" --apply >"$tmp/refused" 2>&1; then
  echo 'ERROR: restauración ignoró un cambio posterior' >&2
  exit 1
fi
grep -Fq 'CONFLICTO' "$tmp/refused"
[[ -f "$IMPE_RECOVERY_PUBLIC/$NEW" ]]
echo 'Rollback telefonía Analista: OK (respaldo íntegro, sólo lectura, reversión y rechazo seguro)'
