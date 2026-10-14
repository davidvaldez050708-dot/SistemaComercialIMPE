#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
DEPLOY="tools/desplegar_historial_webrtc_reconciliado_hostinger.sh"
BASE="9c6af2f292f2bde1a01b0aeb0cb88106327518c0"
TARGET="d63b667e8e79ed95bf33b64d356f6d72982ca2ce"
SHELL="public/javascript/telefonia_shell.js"
SERVICE="app/services/TelefoniaAnalistaWebrtcService.php"

bash -n "$DEPLOY"
php -l tools/resolver_conflicto_shell_analista.php >/dev/null
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
export IMPE_HISTORY_SOURCE="$PWD" IMPE_HISTORY_BACKUPS="$tmp/backups"
export IMPE_HISTORY_PUBLIC="$tmp/live"
mkdir -p "$IMPE_HISTORY_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_HISTORY_PUBLIC"

# Simula un ajuste de producción AJENO al parche GitHub.
python3 - "$IMPE_HISTORY_PUBLIC/$SHELL" <<'PY'
import sys
p=sys.argv[1]
with open(p) as f: s=f.read()
needle="/* Shell persistente para PHP + Zadarma; no utiliza ventanas emergentes.\n"
assert s.count(needle)==1
s=s.replace(needle,needle+" * COMENTARIO DE PRODUCCION QUE DEBE PERMANECER.\n")
with open(p,'w') as f:f.write(s)
PY
cp -p "$IMPE_HISTORY_PUBLIC/$SHELL" "$tmp/shell-original"

if ! check="$(bash "$DEPLOY" --check 2>&1)"; then
  printf 'Preflight fallido:\n%s\n' "$check" >&2
  exit 1
fi
grep -Eq "(FUSIÓN CONSERVADORA|PARCHE COMPATIBLE): $SHELL" <<< "$check"
grep -Fq "SIN modificar producción" <<< "$check"
cmp -s "$tmp/shell-original" "$IMPE_HISTORY_PUBLIC/$SHELL"
[[ ! -e "$IMPE_HISTORY_PUBLIC/$SERVICE" ]]
# Publicar, conservar comentario local y comprobar los archivos.
bash "$DEPLOY" --apply
grep -Fq 'COMENTARIO DE PRODUCCION QUE DEBE PERMANECER' "$IMPE_HISTORY_PUBLIC/$SHELL"
grep -Fq 'const finishedSdk = Boolean(' "$IMPE_HISTORY_PUBLIC/$SHELL"
grep -Fq "journalStatus !== 'saved'" "$IMPE_HISTORY_PUBLIC/$SHELL"
git show "$TARGET:$SERVICE" | cmp -s - "$IMPE_HISTORY_PUBLIC/$SERVICE"
git show "$BASE:public/javascript/telefonia_persistente.js" |
  cmp -s - "$IMPE_HISTORY_PUBLIC/public/javascript/telefonia_persistente.js"
[[ "$(stat -c %a "$IMPE_HISTORY_PUBLIC/$SHELL")" == "644" ]]
[[ "$(stat -c %a "$IMPE_HISTORY_PUBLIC/$SERVICE")" == "644" ]]
repeated="$(bash "$DEPLOY" --check)"
grep -Fq "todo ya está publicado" <<< "$repeated" || {
  # La personalización local no debe impedir un segundo preflight.
  grep -Fq "SIN modificar producción" <<< "$repeated"
}

# Caso REAL: producción quitó el filtro nativeVisible para que el modal
# no se ocultara. Conservar esa decisión y agregar sólo finishedSdk.
export IMPE_HISTORY_PUBLIC="$tmp/live-known-conflict"
mkdir -p "$IMPE_HISTORY_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_HISTORY_PUBLIC"
python3 - "$IMPE_HISTORY_PUBLIC/$SHELL" <<'PY'
import sys
p = sys.argv[1]
with open(p) as f: code = f.read()
needle = ("        const customVisible = (incoming || shownOutgoing) &&\n"
          "            !(cfg.analystIncomingResults && nativeVisible) &&\n")
assert code.count(needle) == 1
code = code.replace(needle,
                    "        const customVisible = (incoming || shownOutgoing) &&\n")
with open(p, 'w') as f: f.write(code)
PY
cp -p "$IMPE_HISTORY_PUBLIC/$SHELL" "$tmp/known-original.js"
knowncheck="$(bash "$DEPLOY" --check)"
grep -Fq "CONFLICTO CONOCIDO RESUELTO: $SHELL" <<< "$knowncheck"
grep -Fq "SIN modificar producción" <<< "$knowncheck"
cmp -s "$tmp/known-original.js" "$IMPE_HISTORY_PUBLIC/$SHELL"
[[ ! -e "$IMPE_HISTORY_PUBLIC/$SERVICE" ]]
bash "$DEPLOY" --apply
grep -Fq 'const customVisible = (incoming || finishedSdk || shownOutgoing) &&' "$IMPE_HISTORY_PUBLIC/$SHELL"
# La línea ELIMINADA EN PRODUCCIÓN NO DEBE VOLVER.
if grep -Fq '!(cfg.analystIncomingResults && nativeVisible) &&' "$IMPE_HISTORY_PUBLIC/$SHELL"; then
  echo 'FALLO: el merge reintrodujo el filtro que ocultaba el modal' >&2
  exit 1
fi
grep -Fq 'const finishedSdk = Boolean(' "$IMPE_HISTORY_PUBLIC/$SHELL"
git show "$TARGET:$SERVICE" | cmp -s - "$IMPE_HISTORY_PUBLIC/$SERVICE"
[[ "$(stat -c %a "$IMPE_HISTORY_PUBLIC/$SHELL")" == "644" ]]
knownagain="$(bash "$DEPLOY" --check)"
grep -Fq "SIN modificar producción" <<< "$knownagain" ||
  grep -Fq 'todo ya está publicado' <<< "$knownagain"

# Cambios incompatibles EN LA MISMA línea: rechazar sin tocar ningún archivo.
export IMPE_HISTORY_PUBLIC="$tmp/live-conflict"
mkdir -p "$IMPE_HISTORY_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_HISTORY_PUBLIC"
python3 - "$IMPE_HISTORY_PUBLIC/$SHELL" <<'PY'
import sys
p=sys.argv[1]
with open(p) as f:s=f.read()
needle="const customVisible = (incoming || shownOutgoing)"
assert s.count(needle)==1
s=s.replace(needle,"const customVisible = (false && shownOutgoing)")
with open(p,"w") as f:f.write(s)
PY
cp -p "$IMPE_HISTORY_PUBLIC/$SHELL" "$tmp/conflict-original"
if bash "$DEPLOY" --apply >"$tmp/error.log" 2>&1; then
  echo 'FALLO: no rechazó conflicto real' >&2; exit 1
fi
grep -Fq 'CONFLICTO REAL' "$tmp/error.log"
cmp -s "$IMPE_HISTORY_PUBLIC/$SHELL" "$tmp/conflict-original"
[[ ! -e "$IMPE_HISTORY_PUBLIC/$SERVICE" ]]
echo "Fusión conservadora WebRTC: OK (preflight, cambios locales, idempotencia y conflicto real)"
