#!/usr/bin/env bash
# Verifica el arreglo de selector sin editar WebRTC/telefonía.
set -euo pipefail
cd "$(dirname "$0")/.."
SOURCE="$PWD"
SCRIPT="tools/desplegar_estilos_select_transferencia_hostinger.sh"
CSS="public/css/telefonia_enrutamiento.css"
BASE="393a1115c65ff81b99cbdec0f48de64df714bf06"
TARGET="975782be5b65d7d657ac4b58d8cea8478f9911a0"
TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
SITE="$TEMP/site"
mkdir -p "$SITE/public/css"
touch "$SITE/index.php"
git show "$BASE:$CSS" > "$SITE/$CSS"
export IMPE_SELECT_SOURCE="$SOURCE"
export IMPE_SELECT_PUBLIC="$SITE"
export IMPE_SELECT_BACKUPS="$TEMP/backups"
bash -n "$SCRIPT"
# El nuevo CSS contiene un único estilo de flecha, enfoque y hover.
python3 - <<'PY'
from pathlib import Path
css=Path('public/css/telefonia_enrutamiento.css').read_text()
assert css.count('.impe-routing-panel .impe-routing-field select {') == 1
assert css.count('.impe-routing-panel .impe-routing-field select:hover:not(:disabled)') == 1
assert css.count('.impe-routing-panel .impe-routing-field select:focus {') == 1
assert css.count('appearance: none;') == 3
assert 'background-image: url("data:image/svg+xml,' in css
assert "background-position: right 13px center;" in css
assert "padding: 8px 40px 8px 11px;" in css
assert ".impe-routing-panel.impe-routing-sidecar" in css
assert ":has(.impe-routing-panel" not in css
shell=Path('app/views/telefonia/shell.php').read_text()
for attr in ("data-routing-type","data-routing-state","data-routing-destination"):
    assert attr in shell, attr
print('Selector visual: OK (3 controles, hover, foco y flecha única).')
PY
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
git show "$BASE:$CSS" | cmp -s - "$SITE/$CSS"
bash "$SCRIPT" --apply | grep -Fq 'estilo de selectores corregido'
git show "$TARGET:$CSS" | cmp -s - "$SITE/$CSS"
[[ -n "$(find "$TEMP/backups" -type f)" ]]
bash "$SCRIPT" --check | grep -Fq 'YA ACTUALIZADO'
bash "$SCRIPT" --apply | grep -Fq 'YA ACTUALIZADO'

# Agregados CSS independientes en Hostinger deben mantenerse intactos.
CUSTOM="$TEMP/custom"
mkdir -p "$CUSTOM/public/css"
touch "$CUSTOM/index.php"
git show "$BASE:$CSS" > "$CUSTOM/$CSS"
printf '\n/* AJUSTE-HOSTINGER-EXISTENTE */\n' >> "$CUSTOM/$CSS"
export IMPE_SELECT_PUBLIC="$CUSTOM"
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
bash "$SCRIPT" --apply | grep -Fq 'estilo de selectores corregido'
grep -Fq 'AJUSTE-HOSTINGER-EXISTENTE' "$CUSTOM/$CSS"
grep -Fq 'appearance: none' "$CUSTOM/$CSS"

# Una personalización conflictiva del selector debe detener el instalador.
CONFLICT="$TEMP/conflict"
mkdir -p "$CONFLICT/public/css"
touch "$CONFLICT/index.php"
git show "$BASE:$CSS" > "$CONFLICT/$CSS"
python3 - "$CONFLICT/$CSS" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
css=p.read_text()
old=".impe-routing-field textarea {"
assert old in css
p.write_text(css.replace(old,".impe-routing-field textarea, select {",1))
PY
cp "$CONFLICT/$CSS" "$TEMP/unchanged.css"
export IMPE_SELECT_PUBLIC="$CONFLICT"
if bash "$SCRIPT" --apply > "$TEMP/rejected" 2>&1; then
  echo 'ERROR: instalador aceptó hoja CSS conflictiva.' >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TEMP/rejected"
cmp -s "$TEMP/unchanged.css" "$CONFLICT/$CSS"
echo 'Hostinger CSS: OK (selectores corregidos, respaldo, idempotencia y rechazo de conflictos).'
