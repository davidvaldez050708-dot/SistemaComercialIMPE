#!/usr/bin/env bash
# Emulación local de Hostinger, sin MySQL ni telefonía real.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SCRIPT="tools/desplegar_transferencias_seguras_hostinger.sh"
BASE="74c7f5e81432f743b7890c2134f70aa3c3e5fb91"
TARGET="$(sed -n 's/^TARGET="\([^"]*\)"/\1/p' "$SCRIPT")"
OLD=(
  app/controllers/TelefoniaController.php
  app/controllers/TerritorioController.php
  app/models/TerritorioModel.php
  app/services/TelefoniaEntrantesAnalistaService.php
  app/views/telefonia/host.php
  app/views/telefonia/shell.php
  app/views/layout/dashboard_head.php
  app/views/territorios/equipo.php
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  public/javascript/telefonia_host.js
  public/javascript/telefonia_shell.js
  public/javascript/telefonia_persistente.js
  public/javascript/seguimiento_entrantes_analista.js
)
NEW=(
  app/services/TelefoniaEnrutamientoService.php
  public/javascript/telefonia_enrutamiento.js
  public/css/telefonia_enrutamiento.css
)
php tests/telefonia_transferencias_seguras_smoke.php
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/site"
mkdir -p "$SITE"
touch "$SITE/index.php"
for f in "${OLD[@]}"; do
  mkdir -p "$SITE/$(dirname "$f")"
  git show "$BASE:$f" > "$SITE/$f"
done
export IMPE_TRANSFER_SOURCE="$ROOT"
export IMPE_TRANSFER_PUBLIC="$SITE"
export IMPE_TRANSFER_BACKUPS="$TMP/backups"

bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
for f in "${OLD[@]}"; do
  git show "$BASE:$f" | cmp -s - "$SITE/$f"
done
for f in "${NEW[@]}"; do [[ ! -e "$SITE/$f" ]]; done
bash "$SCRIPT" --apply | grep -Fq 'transferencia contextual y captura publicadas'
for f in "${OLD[@]}" "${NEW[@]}"; do
  git show "$TARGET:$f" | cmp -s - "$SITE/$f"
done
[[ -n "$(find "$TMP/backups" -type f)" ]]
bash "$SCRIPT" --check | grep -Fq 'ya publicados'
bash "$SCRIPT" --apply | grep -Fq 'ya publicados'

# Archivos desconocidos no se sobrescriben. Un conflicto detiene TODO.
CONFLICT="$TMP/conflict"
mkdir -p "$CONFLICT"
touch "$CONFLICT/index.php"
for f in "${OLD[@]}"; do
  mkdir -p "$CONFLICT/$(dirname "$f")"
  git show "$BASE:$f" > "$CONFLICT/$f"
done
python3 - "$CONFLICT/app/controllers/TelefoniaController.php" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
anchor='public function destinosTransferencia()'
assert anchor in s
p.write_text(s.replace(anchor,'public function destinosDistintos()'))
PY
cp "$CONFLICT/app/controllers/TelefoniaController.php" "$TMP/old-controller"
export IMPE_TRANSFER_PUBLIC="$CONFLICT"
if bash "$SCRIPT" --apply > "$TMP/conflict.log" 2>&1; then
  echo "ERROR: aceptó controlador desconocido." >&2
  exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.log"
cmp -s "$TMP/old-controller" "$CONFLICT/app/controllers/TelefoniaController.php"
git show "$BASE:app/views/telefonia/shell.php" |
  cmp -s - "$CONFLICT/app/views/telefonia/shell.php"
for f in "${NEW[@]}"; do [[ ! -e "$CONFLICT/$f" ]]; done
echo "Transferencias Hostinger: OK (preflight, copia exacta, rollback preparado, idempotencia y conflictos)."
