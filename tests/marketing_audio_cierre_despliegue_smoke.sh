#!/usr/bin/env bash
# Instalación temporal: --check no altera, --apply respalda y es repetible.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE"
touch "$SITE/index.php"
FILES=(
 app/services/TelefoniaRecepcionMarketingService.php
 prueba_telefonia/api/grabacion_recepcion_marketing.php
 public/javascript/telefonia_recepcion_marketing.js
 public/javascript/telefonia_shell.js
 public/javascript/telefonia_host.js
 public/javascript/telefonia_persistente.js
 app/views/telefonia/shell.php
)
BASE='157667d3f1d4eccc524d21b23eadfa3aebb96e2a'
for p in "${FILES[@]}"; do
 mkdir -p "$SITE/$(dirname "$p")"
 git -C "$ROOT" show "$BASE:$p" > "$SITE/$p"
done
# Personalizaciones compatibles de Hostinger deben conservarse.
echo '// MARKETING-HOSTINGER-AJUSTE-PRESERVAR' >> "$SITE/public/javascript/telefonia_shell.js"
run() {
 IMPE_MARKETING_FIX_SOURCE="$ROOT" \
 IMPE_MARKETING_FIX_PUBLIC="$SITE" \
 IMPE_MARKETING_FIX_BACKUP="$TMP/backups" \
 bash "$ROOT/tools/desplegar_cierre_y_audios_marketing_hostinger.sh" "$1"
}
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_shell.js")"
run --check
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_shell.js")" ]]
[[ ! -e "$SITE/app/services/TelefoniaGrabacionSalienteMarketingService.php" ]]
run --apply
grep -Fq 'MARKETING-HOSTINGER-AJUSTE-PRESERVAR' "$SITE/public/javascript/telefonia_shell.js"
grep -Fq 'recentFinishedOutgoing' "$SITE/public/javascript/telefonia_shell.js"
grep -Fq 'manualCall(state)' "$SITE/public/javascript/telefonia_host.js"
grep -Fq 'TelefoniaGrabacionSalienteMarketingService::autorizada' \
 "$SITE/prueba_telefonia/api/grabacion_recepcion_marketing.php"
grep -Fq 'data-incoming-close-finished' "$SITE/app/views/telefonia/shell.php"
[[ "$(find "$TMP/backups" -type f | wc -l)" -eq 7 ]]
run --apply
# Una personalización incompatible del ciclo de vida NO se sobreescribe.
git -C "$ROOT" show "$BASE:public/javascript/telefonia_host.js" \
 > "$SITE/public/javascript/telefonia_host.js"
python3 - "$SITE/public/javascript/telefonia_host.js" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
s=p.read_text()
needle = "        publish({\n            phase: 'finished',\n            active: false,"
assert s.count(needle)==1
p.write_text(s.replace(needle,
    "        publish({\n            phase: 'legacy-final',\n            active: false,"))
PY
SHA="$(sha256sum "$SITE/public/javascript/telefonia_host.js")"
if run --apply > "$TMP/conflict.txt" 2>&1; then
 echo "ERROR: se aceptó conflicto de Hostinger." >&2; exit 1
fi
grep -Eq 'CONFLICTO|ERROR:' "$TMP/conflict.txt"
[[ "$SHA" == "$(sha256sum "$SITE/public/javascript/telefonia_host.js")" ]]
echo "Marketing audio y cierre: OK (preflight, permisos, respaldo, repetición y conflicto)"
