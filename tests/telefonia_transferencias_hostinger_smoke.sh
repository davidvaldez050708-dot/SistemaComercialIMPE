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

# La variante publicada del Analista puede tener lógica WebRTC histórica
# distinta. Se debe fusionar únicamente el código de transferencia.
PERSONAL="$TMP/hostinger-analista"
mkdir -p "$PERSONAL"
touch "$PERSONAL/index.php"
for f in "${OLD[@]}"; do
  mkdir -p "$PERSONAL/$(dirname "$f")"
  git show "$BASE:$f" > "$PERSONAL/$f"
done
python3 - "$PERSONAL/public/javascript/telefonia_shell.js" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
for fragment in (
    "    const registerFinished = document.querySelector('[data-incoming-register-finished]');\n",
    "    const retrySdkSave = document.querySelector('[data-incoming-retry-save]');\n",
):
    assert s.count(fragment)==1
    s=s.replace(fragment, '', 1)
needle="    const analystResultNotice = document.querySelector('[data-analyst-call-result]');"
assert s.count(needle)==1
s=s.replace(needle, "    // PERSONALIZACION-HOSTINGER-WEBRTC-CONVERSACION\n"+needle,1)
p.write_text(s)
PY
cp "$PERSONAL/public/javascript/telefonia_shell.js" "$TMP/js-pre-transferencia"
export IMPE_TRANSFER_PUBLIC="$PERSONAL"
bash "$SCRIPT" --check > "$TMP/check-personal"
grep -Fq 'FUSIÓN COMPATIBLE: telefonia_shell.js' "$TMP/check-personal"
grep -Fq 'PRODUCCIÓN INTACTA' "$TMP/check-personal"
cmp -s "$TMP/js-pre-transferencia" "$PERSONAL/public/javascript/telefonia_shell.js"
bash "$SCRIPT" --apply | grep -Fq 'transferencia contextual y captura publicadas'
node --check "$PERSONAL/public/javascript/telefonia_shell.js"
grep -Fq 'PERSONALIZACION-HOSTINGER-WEBRTC-CONVERSACION' "$PERSONAL/public/javascript/telefonia_shell.js"
grep -Fq 'getActiveCall: function ()' "$PERSONAL/public/javascript/telefonia_shell.js"
grep -Fq 'requestAuthorizedTransfer: function (token)' "$PERSONAL/public/javascript/telefonia_shell.js"
grep -Fq 'const workingCall = Boolean(ongoing' "$PERSONAL/public/javascript/telefonia_shell.js"
! grep -Fq 'const registerFinished = document.querySelector' "$PERSONAL/public/javascript/telefonia_shell.js"
! grep -Fq 'const retrySdkSave = document.querySelector' "$PERSONAL/public/javascript/telefonia_shell.js"
cp "$PERSONAL/public/javascript/telefonia_shell.js" "$TMP/js-fusionado"
bash "$SCRIPT" --check | grep -Fq 'ya publicados'
bash "$SCRIPT" --apply | grep -Fq 'ya publicados'
cmp -s "$PERSONAL/public/javascript/telefonia_shell.js" "$TMP/js-fusionado"

# Un estado intermedio de transferencia no debe pasar el preflight.
PARTIAL="$TMP/hostinger-incompleto"
mkdir -p "$PARTIAL"
touch "$PARTIAL/index.php"
for f in "${OLD[@]}"; do
  mkdir -p "$PARTIAL/$(dirname "$f")"
  git show "$BASE:$f" > "$PARTIAL/$f"
done
python3 - "$PARTIAL/public/javascript/telefonia_shell.js" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
anchor="    const analystResultNotice = document.querySelector('[data-analyst-call-result]');"
assert s.count(anchor)==1
s=s.replace(anchor,
  "    const transferToggle = document.querySelector('[data-shell-transfer-toggle]');\n"+anchor,1)
p.write_text(s)
PY
cp "$PARTIAL/public/javascript/telefonia_shell.js" "$TMP/js-partial-before"
export IMPE_TRANSFER_PUBLIC="$PARTIAL"
if bash "$SCRIPT" --apply > "$TMP/partial-error" 2>&1; then
  echo "ERROR: autorizó una implementación parcial de transferencia." >&2
  exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/partial-error"
cmp -s "$TMP/js-partial-before" "$PARTIAL/public/javascript/telefonia_shell.js"
for f in "${NEW[@]}"; do [[ ! -e "$PARTIAL/$f" ]]; done

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
