#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
DEPLOY="tools/desplegar_diseno_llamadas_recibidas_expediente_hostinger.sh"
BASE="abd0616a9d2e043dc244d1140d9fb51a40846cdd"
TARGET="636fbfa7cd876d37609664337f8f539a45456354"
FILES=(
  "app/views/layout/dashboard_head.php"
  "app/views/seguimiento_vinculacion/llamadas_entrantes.php"
  "public/css/seguimiento_entrantes_analista.css"
  "public/javascript/seguimiento_entrantes_analista.js"
)
DEPS=(
  "public/css/seguimiento_llamadas_expediente.css"
  "public/css/seguimiento_llamadas_player.css"
)
php tests/telefonia_entrantes_visual_smoke.php
php -l "${FILES[0]}" >/dev/null
php -l "${FILES[1]}" >/dev/null
node --check "${FILES[3]}"
bash -n "$DEPLOY"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/site"
mkdir -p "$SITE" "$TMP/backups"
for f in "${FILES[@]}"; do
  mkdir -p "$SITE/$(dirname "$f")"
  git show "$BASE:$f" > "$SITE/$f"
done
for f in "${DEPS[@]}"; do
  mkdir -p "$SITE/$(dirname "$f")"
  git show "$BASE:$f" > "$SITE/$f"
done
export IMPE_DISENO_LLAMADAS_SOURCE="$ROOT"
export IMPE_DISENO_LLAMADAS_PUBLIC="$SITE"
export IMPE_DISENO_LLAMADAS_BACKUPS="$TMP/backups"

bash "$DEPLOY" --check | grep -Fq 'PRODUCCIÓN INTACTA'
for f in "${FILES[@]}"; do
  git show "$BASE:$f" | cmp -s - "$SITE/$f"
done
bash "$DEPLOY" --apply | grep -Fq 'reproductor del expediente'
for f in "${FILES[@]}"; do
  git show "$TARGET:$f" | cmp -s - "$SITE/$f"
done
for f in "${DEPS[@]}"; do
  git show "$BASE:$f" | cmp -s - "$SITE/$f"
done
[[ -n "$(find "$TMP/backups" -type f)" ]]
bash "$DEPLOY" --check | grep -Fq 'ya publicados'

# Un conflicto en la vista debe detener el lote ANTES de tocar cualquier archivo.
CONFLICT="$TMP/conflict"
mkdir -p "$CONFLICT"
for f in "${FILES[@]}" "${DEPS[@]}"; do
  mkdir -p "$CONFLICT/$(dirname "$f")"
  git show "$BASE:$f" > "$CONFLICT/$f"
done
python3 - "$CONFLICT/${FILES[1]}" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
old='<div class="linkage-incoming-table-wrap">'
assert old in s
p.write_text(s.replace(old,'<div class="custom-incoming-table-wrap">',1))
PY
cp "$CONFLICT/${FILES[1]}" "$TMP/before_conflict"
export IMPE_DISENO_LLAMADAS_PUBLIC="$CONFLICT"
if bash "$DEPLOY" --apply >"$TMP/conflict_log" 2>&1; then
  echo "ERROR: el despliegue permitió un conflicto." >&2
  exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict_log"
cmp -s "$TMP/before_conflict" "$CONFLICT/${FILES[1]}"
git show "$BASE:${FILES[0]}" | cmp -s - "$CONFLICT/${FILES[0]}"
echo "Diseño Llamadas recibidas: OK (tarjetas, reproductor, responsive, publicación, respaldo e incompatibilidades)."
