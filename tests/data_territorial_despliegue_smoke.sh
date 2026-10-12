#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/domains/fundacionrededumex.org/public_html"
mkdir -p "$SITE/app/models" "$SITE/app/controllers" "$SITE/app/views/data_territorial"
ln -s "$ROOT" "$TMP/crm-preparacion"
touch "$SITE/index.php"
git -C "$ROOT" cat-file blob ae83c00ae79dbd09a920e5671b1cfb353ef3dfad > "$SITE/app/models/DataTerritorialModel.php"
git -C "$ROOT" cat-file blob eec104a817a2d46640010ff4f43189d177e959f3 > "$SITE/app/controllers/DataTerritorialController.php"
git -C "$ROOT" cat-file blob 6c28390eb42fc73b0a6abc799a410f8c107e111d > "$SITE/app/views/data_territorial/index.php"
echo '<!-- AJUSTE-LOCAL-CONSERVAR -->' >> "$SITE/app/views/data_territorial/index.php"
deploy() { HOME="$TMP" bash "$ROOT/tools/desplegar_acceso_territorial_hostinger.sh" "$1"; }
before="$(sha256sum "$SITE/app/models/DataTerritorialModel.php")"
deploy --check
[[ "$before" == "$(sha256sum "$SITE/app/models/DataTerritorialModel.php")" ]]
[[ ! -f "$SITE/app/helpers/DataTerritorialAccessPolicy.php" ]]
deploy --apply
grep -Fq 'AJUSTE-LOCAL-CONSERVAR' "$SITE/app/views/data_territorial/index.php"
grep -Fq 'Consulta la información de los Estados registrados.' "$SITE/app/controllers/DataTerritorialController.php"
grep -Fq 'DataTerritorialAccessPolicy::resolver' "$SITE/app/models/DataTerritorialModel.php"
[[ -f "$SITE/app/helpers/DataTerritorialAccessPolicy.php" ]]
deploy --apply
[[ "$(find "$TMP/respaldos-crm" -type f | wc -l)" == "3" ]]
# Modificar justamente la línea histórica que el parche cambiaría => conflicto.
git -C "$ROOT" cat-file blob ae83c00ae79dbd09a920e5671b1cfb353ef3dfad > "$SITE/app/models/DataTerritorialModel.php"
python3 - "$SITE/app/models/DataTerritorialModel.php" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
old='if ((int)$rolId === 1) {'
assert old in s
p.write_text(s.replace(old, 'if ($rolId === 999) {', 1))
PY
before="$(sha256sum "$SITE/app/models/DataTerritorialModel.php")"
if deploy --apply > "$TMP/conflict.log" 2>&1; then
  echo "ERROR: el despliegue aceptó un conflicto de tres vías." >&2
  exit 1
fi
grep -Fq CONFLICTO "$TMP/conflict.log"
[[ "$before" == "$(sha256sum "$SITE/app/models/DataTerritorialModel.php")" ]]
echo 'Publicación territorial: OK (previsualizar, publicar, repetir y rechazar conflicto)'
