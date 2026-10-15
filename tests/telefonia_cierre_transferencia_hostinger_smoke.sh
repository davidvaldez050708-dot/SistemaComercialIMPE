#!/usr/bin/env bash
# Prueba despliegue aislado: producción simulada, sin PBX ni BD.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SCRIPT="tools/desplegar_cierre_transferencia_hostinger.sh"
FILE="public/javascript/telefonia_shell.js"
BASE="adb523f075479b980b6f7d5be3188b8c47a3ff83"
TARGET="4075b6066b7ace57a2a4d436e88bf21af911c6e6"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_CLOSE_SOURCE="$ROOT"
export IMPE_CLOSE_BACKUPS="$TMP/backups"
make_site() {
  mkdir -p "$1/public/javascript"
  touch "$1/index.php"
  git show "$BASE:$FILE" > "$1/$FILE"
}
export IMPE_CLOSE_PUBLIC="$TMP/site"
make_site "$IMPE_CLOSE_PUBLIC"
bash -n "$SCRIPT"
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
git show "$BASE:$FILE" | cmp -s - "$IMPE_CLOSE_PUBLIC/$FILE"
bash "$SCRIPT" --apply | grep -Fq 'publicados'
git show "$TARGET:$FILE" | cmp -s - "$IMPE_CLOSE_PUBLIC/$FILE"
[[ -n "$(find "$TMP/backups" -type f)" ]]
bash "$SCRIPT" --check | grep -Fq 'ya publicado'
bash "$SCRIPT" --apply | grep -Fq 'ya publicado'

# Un ajuste propio del alojamiento fuera del parche no debe desaparecer.
export IMPE_CLOSE_PUBLIC="$TMP/custom"
make_site "$IMPE_CLOSE_PUBLIC"
printf '\n/* HOSTINGER-LOCAL: PRESERVAR */\n' >> "$IMPE_CLOSE_PUBLIC/$FILE"
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
bash "$SCRIPT" --apply | grep -Fq 'publicados'
grep -Fq 'HOSTINGER-LOCAL: PRESERVAR' "$IMPE_CLOSE_PUBLIC/$FILE"
grep -Fq 'recentFinishedIncoming' "$IMPE_CLOSE_PUBLIC/$FILE"

# Una variante realmente personalizada, dentro del contexto git apply pero
# FUERA de los fragmentos modificados, debe usar fusión reversible y preservar
# intacta la personalización de Hostinger.
export IMPE_CLOSE_PUBLIC="$TMP/hostinger-personalizado"
make_site "$IMPE_CLOSE_PUBLIC"
python3 - "$IMPE_CLOSE_PUBLIC/$FILE" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
a='    let pendingOutgoingHangup = null;\n'
b='        const pending = pendingFor(state);\n'
assert s.count(a)==1 and s.count(b)==1
s=s.replace(a,a+'    /* HOSTINGER-WEBRTC-EXTRA: PRESERVAR */\n',1)
s=s.replace(b,'        /* HOSTINGER-REGISTRO-EXTRA: PRESERVAR */\n'+b,1)
p.write_text(s)
PY
cp "$IMPE_CLOSE_PUBLIC/$FILE" "$TMP/hostinger-original.js"
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
cmp -s "$IMPE_CLOSE_PUBLIC/$FILE" "$TMP/hostinger-original.js"
bash "$SCRIPT" --apply | grep -Fq 'publicados'
grep -Fq 'HOSTINGER-WEBRTC-EXTRA: PRESERVAR' "$IMPE_CLOSE_PUBLIC/$FILE"
grep -Fq 'HOSTINGER-REGISTRO-EXTRA: PRESERVAR' "$IMPE_CLOSE_PUBLIC/$FILE"
grep -Fq 'recentFinishedIncoming' "$IMPE_CLOSE_PUBLIC/$FILE"
bash "$SCRIPT" --check | grep -Fq 'ya publicado'
bash "$SCRIPT" --apply | grep -Fq 'ya publicado'
grep -Fq 'HOSTINGER-WEBRTC-EXTRA: PRESERVAR' "$IMPE_CLOSE_PUBLIC/$FILE"

# Si cambia código de cierre que el parche necesita, rechazar sin escrituras.
export IMPE_CLOSE_PUBLIC="$TMP/conflict"
make_site "$IMPE_CLOSE_PUBLIC"
python3 - "$IMPE_CLOSE_PUBLIC/$FILE" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
needle="    let recentFinishedOutgoing = null;"
assert needle in s
p.write_text(s.replace(needle,"    let recentFinishedOutgoing = false;",1))
PY
cp "$IMPE_CLOSE_PUBLIC/$FILE" "$TMP/unchanged.js"
if bash "$SCRIPT" --apply > "$TMP/error" 2>&1; then
  echo "ERROR: parche incompatible aceptado." >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/error"
cmp -s "$IMPE_CLOSE_PUBLIC/$FILE" "$TMP/unchanged.js"
echo "Cierre de llamada: OK (estado final, parche aditivo, idempotencia, rollback y conflicto)."
