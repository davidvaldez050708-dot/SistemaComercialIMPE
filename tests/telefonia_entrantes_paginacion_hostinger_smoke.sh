#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
DEPLOY="tools/desplegar_paginacion_llamadas_recibidas_hostinger.sh"
BASE="e2bf1d9082803fa9423a7935d14d3163075f1205"
TARGET="2ef8110233af82609bcfe8282eb5f04e2b912a2b"
VIEW="app/views/seguimiento_vinculacion/llamadas_entrantes.php"
CSS="public/css/seguimiento_entrantes_analista.css"
php tests/telefonia_entrantes_paginacion_smoke.php
php tests/telefonia_entrantes_visual_smoke.php
php -l "$VIEW" >/dev/null
bash -n "$DEPLOY"

TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
LIVE="$TEMP/site"
for f in "$VIEW" "$CSS"; do
  mkdir -p "$LIVE/$(dirname "$f")"
  git show "$BASE:$f" > "$LIVE/$f"
done
export IMPE_PAGINACION_SOURCE="$ROOT"
export IMPE_PAGINACION_PUBLIC="$LIVE"
export IMPE_PAGINACION_BACKUPS="$TEMP/backups"

bash "$DEPLOY" --check | grep -Fq 'PRODUCCIÓN INTACTA'
for f in "$VIEW" "$CSS"; do
  git show "$BASE:$f" | cmp -s - "$LIVE/$f"
done
bash "$DEPLOY" --apply | grep -Fq 'paginadas de 10 en 10'
for f in "$VIEW" "$CSS"; do
  git show "$TARGET:$f" | cmp -s - "$LIVE/$f"
done
[[ -n "$(find "$TEMP/backups" -type f)" ]]
bash "$DEPLOY" --check | grep -Fq 'ya publicada'

# Verificar detección de conflicto real antes de alterar archivos.
CONFLICT="$TEMP/conflict"
for f in "$VIEW" "$CSS"; do
  mkdir -p "$CONFLICT/$(dirname "$f")"
  git show "$BASE:$f" > "$CONFLICT/$f"
done
python3 - "$CONFLICT/$VIEW" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
source=p.read_text()
assert '$llamadas = is_array(' in source
p.write_text(source.replace('$llamadas = is_array(', '$llamadas = /* custom */ is_array(', 1))
PY
cp "$CONFLICT/$VIEW" "$TEMP/antes"
export IMPE_PAGINACION_PUBLIC="$CONFLICT"
if bash "$DEPLOY" --apply > "$TEMP/conflict_log" 2>&1; then
  echo "ERROR: el despliegue aceptó una versión inesperada." >&2
  exit 1
fi
grep -Fq 'CONFLICTO:' "$TEMP/conflict_log"
cmp -s "$TEMP/antes" "$CONFLICT/$VIEW"
git show "$BASE:$CSS" | cmp -s - "$CONFLICT/$CSS"
echo "Paginación Hostinger: OK (10 por página, navegación, backup, idempotencia y conflictos)."
