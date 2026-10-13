#!/usr/bin/env bash
# Simulación en directorio temporal sin acceso a Hostinger.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE"
touch "$SITE/index.php"
FILES=(
 public/javascript/telefonia_shell.js
 public/javascript/telefonia_persistente.js
 public/javascript/telefonia_teclado.js
 public/css/telefonia_teclado.css
)
for p in "${FILES[@]}"; do
 mkdir -p "$SITE/$(dirname "$p")"
 git -C "$ROOT" show "8fc7f1dad44cefe6c070da89d48beb10ed5bed9f:$p" > "$SITE/$p"
done
# Las reglas de color/espaciado del servidor no se deben borrar.
printf '\n/* AJUSTE-LOCAL-TELEFONO-PRESERVAR */\n' >> "$SITE/public/css/telefonia_teclado.css"
# La personalización de la sesión embebida tampoco debe desaparecer.
printf '\n// AJUSTE-LOCAL-SHELL-PRESERVAR\n' >> "$SITE/public/javascript/telefonia_shell.js"
run() {
 IMPE_SALIENTES_SOURCE="$ROOT" IMPE_SALIENTES_PUBLIC="$SITE" \
 IMPE_SALIENTES_BACKUP="$TMP/backups" \
 bash "$ROOT/tools/desplegar_salientes_modal_hostinger.sh" "$1"
}
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_shell.js")"
run --check
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_shell.js")" ]]
run --apply
grep -Fq 'AJUSTE-LOCAL-SHELL-PRESERVAR' "$SITE/public/javascript/telefonia_shell.js"
grep -Fq 'AJUSTE-LOCAL-TELEFONO-PRESERVAR' "$SITE/public/css/telefonia_teclado.css"
grep -Fq 'singleCallPanel: true' "$SITE/public/javascript/telefonia_shell.js"
grep -Fq 'await prepare({ openHost: false });' "$SITE/public/javascript/telefonia_persistente.js"
grep -Fq 'handoffToCall' "$SITE/public/javascript/telefonia_teclado.js"
grep -Fq '.impe-dialer-state[hidden] {display:none !important;}' "$SITE/public/css/telefonia_teclado.css"
[[ "$(find "$TMP/backups" -type f | wc -l)" -eq 4 ]]
run --apply
# Colisión REAL: personalizaciones en las DOS zonas que cambió GitHub.
# Fusionar el archivo completo produce conflictos, pero el cambio selectivo
# debe preservar estas líneas distintas y seguir verificando la recepción.
git -C "$ROOT" show "8fc7f1dad44cefe6c070da89d48beb10ed5bed9f:public/javascript/telefonia_persistente.js" \
  > "$SITE/public/javascript/telefonia_persistente.js"
python3 - "$SITE/public/javascript/telefonia_persistente.js" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1]); s=p.read_text()
s=s.replace(
'             * prepare() abre la ventana dentro del clic real del usuario.',
'             * HOSTINGER: mantener funciones de recepción existentes.')
s=s.replace(
'        panel.hidden = !show || managedByShell || incomingInShell;',
'        // PERSONALIZACION-HOSTINGER-PANEL\n'
'        panel.hidden = !show || managedByShell || incomingInShell;')
p.write_text(s)
PY
PREV="$(sha256sum "$SITE/public/javascript/telefonia_persistente.js")"
run --check
[[ "$PREV" == "$(sha256sum "$SITE/public/javascript/telefonia_persistente.js")" ]]
run --apply
grep -Fq 'HOSTINGER: mantener funciones de recepción existentes.' \
 "$SITE/public/javascript/telefonia_persistente.js"
grep -Fq 'PERSONALIZACION-HOSTINGER-PANEL' \
 "$SITE/public/javascript/telefonia_persistente.js"
grep -Fq 'shell.ensureHost();' "$SITE/public/javascript/telefonia_persistente.js"
grep -Fq 'manualOutgoingInShell' "$SITE/public/javascript/telefonia_persistente.js"
run --apply
# Cambios locales incompatibles desconocidos detienen el despliegue completo.
git -C "$ROOT" show "8fc7f1dad44cefe6c070da89d48beb10ed5bed9f:public/javascript/telefonia_persistente.js" \
  > "$SITE/public/javascript/telefonia_persistente.js"
sed -i "s/await prepare({ openHost: true });/await prepare({ openHost: false });/" \
  "$SITE/public/javascript/telefonia_persistente.js"
SHA="$(sha256sum "$SITE/public/javascript/telefonia_persistente.js")"
if run --apply > "$TMP/conflict.txt" 2>&1; then
 echo "ERROR: se aceptó cambio local incompatible." >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflict.txt"
[[ "$SHA" == "$(sha256sum "$SITE/public/javascript/telefonia_persistente.js")" ]]
echo "Panel saliente Hostinger: OK (fusión selectiva, respaldo, repetición, conflicto)"
