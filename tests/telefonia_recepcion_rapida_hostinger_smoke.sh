#!/usr/bin/env bash
# Simula el sitio publicado, sin conectar a PBX, BD ni reemplazar personalizaciones.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SCRIPT="tools/desplegar_recepcion_rapida_hostinger.sh"
BASE="aa37af6778127dd0d33047d2d9282a853febb1f9"
TARGET="aa14190eeba2ac65bd170060f6ed4a512868f580"
FILES=(
  app/services/TelefoniaEnrutamientoService.php
  app/controllers/TelefoniaController.php
  app/views/telefonia/shell.php
  public/javascript/telefonia_enrutamiento.js
  public/css/telefonia_enrutamiento.css
)
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/site"
mkdir -p "$SITE"
touch "$SITE/index.php"
for file in "${FILES[@]}"; do
  mkdir -p "$SITE/$(dirname "$file")"
  git show "$BASE:$file" > "$SITE/$file"
done
export IMPE_RECEPTION_SOURCE="$ROOT"
export IMPE_RECEPTION_PUBLIC="$SITE"
export IMPE_RECEPTION_BACKUPS="$TMP/backups"
php tests/telefonia_recepcion_rapida_smoke.php
bash -n "$SCRIPT"
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
for file in "${FILES[@]}"; do
  git show "$BASE:$file" | cmp -s - "$SITE/$file"
done
bash "$SCRIPT" --apply | grep -Fq 'recepción rápida publicada'
for file in "${FILES[@]}"; do
  git show "$TARGET:$file" | cmp -s - "$SITE/$file"
done
[[ -n "$(find "$TMP/backups" -type f)" ]]
bash "$SCRIPT" --check | grep -Fq 'ya publicada'
bash "$SCRIPT" --apply | grep -Fq 'ya publicada'

# Preservar comentarios locales que no coinciden con las zonas modificadas.
CUSTOM="$TMP/local"
mkdir -p "$CUSTOM"
touch "$CUSTOM/index.php"
for file in "${FILES[@]}"; do
  mkdir -p "$CUSTOM/$(dirname "$file")"
  git show "$BASE:$file" > "$CUSTOM/$file"
done
python3 - "$CUSTOM/app/views/telefonia/shell.php" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
needle="    <section class=\\"persistent-phone-panel"
assert needle in s
s=s.replace(needle, "    <!-- AJUSTE-HISTORICO-HOSTINGER -->\\n"+needle,1)
p.write_text(s)
PY
export IMPE_RECEPTION_PUBLIC="$CUSTOM"
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
bash "$SCRIPT" --apply | grep -Fq 'recepción rápida publicada'
grep -Fq 'AJUSTE-HISTORICO-HOSTINGER' "$CUSTOM/app/views/telefonia/shell.php"
grep -Fq 'impe-routing-sidecar' "$CUSTOM/app/views/telefonia/shell.php"

# Código conflictivo en la zona del parche se rechaza sin modificar producción.
CONFLICT="$TMP/conflict"
mkdir -p "$CONFLICT"
touch "$CONFLICT/index.php"
for file in "${FILES[@]}"; do
  mkdir -p "$CONFLICT/$(dirname "$file")"
  git show "$BASE:$file" > "$CONFLICT/$file"
done
python3 - "$CONFLICT/app/views/telefonia/shell.php" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
old='<div class="impe-routing-panel" id="impeTransferPanel"'
assert old in s
p.write_text(s.replace(old,'<div class="otro-panel" id="impeTransferPanel"',1))
PY
cp "$CONFLICT/app/views/telefonia/shell.php" "$TMP/unchanged"
export IMPE_RECEPTION_PUBLIC="$CONFLICT"
if bash "$SCRIPT" --apply > "$TMP/conflict.log" 2>&1; then
  echo "ERROR: se aceptó un panel incompatible." >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.log"
cmp -s "$CONFLICT/app/views/telefonia/shell.php" "$TMP/unchanged"
git show "$BASE:public/javascript/telefonia_enrutamiento.js" |
  cmp -s - "$CONFLICT/public/javascript/telefonia_enrutamiento.js"
echo "Recepción rápida Hostinger: OK (fusión selectiva, respaldo, idempotencia y rechazo de conflictos)."
