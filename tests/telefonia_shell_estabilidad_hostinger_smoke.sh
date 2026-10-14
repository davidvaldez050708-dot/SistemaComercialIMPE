#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SCRIPT="tools/desplegar_estabilidad_shell_analista_hostinger.sh"
NEW="public/javascript/telefonia_shell_navegacion.js"
FILES=(
  "app/views/telefonia/shell.php"
  "public/javascript/telefonia_shell.js"
  "app/controllers/TelefoniaController.php"
  "public/css/telefonia_shell.css"
)
BASES=(
  310fa2f2e4917583e8177456fed5ed909913dd6f
  6dc7b7fad51e5685bd63e1f13ac4f9a5cee7bb8f
  59f35c3ae14ebd20456d6b19f88baba6712a355e
  639992293946becae46c390daf0cb3184d392bfc
)
TARGETS=(
  6dc7b7fad51e5685bd63e1f13ac4f9a5cee7bb8f
  59f35c3ae14ebd20456d6b19f88baba6712a355e
  639992293946becae46c390daf0cb3184d392bfc
  e7dc664e3410b781965f37bc8704d7532f6170ec
)
node --check "$NEW"
node tests/telefonia_shell_navegacion_smoke.js
node tests/telefonia_shell_runtime_smoke.js
php -l "${FILES[0]}" >/dev/null
php -l "${FILES[2]}" >/dev/null
bash -n "$SCRIPT"

TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
LIVE="$TEMP/site"
for i in "${!FILES[@]}"; do
  f="${FILES[$i]}"
  mkdir -p "$LIVE/$(dirname "$f")"
  git show "${BASES[$i]}:$f" > "$LIVE/$f"
done
export IMPE_SHELL_ESTABLE_SOURCE="$ROOT"
export IMPE_SHELL_ESTABLE_PUBLIC="$LIVE"
export IMPE_SHELL_ESTABLE_BACKUPS="$TEMP/backups"

bash "$SCRIPT" --check | grep -Fq "PRODUCCIÓN INTACTA"
[[ ! -e "$LIVE/$NEW" ]]
for i in "${!FILES[@]}"; do
  git show "${BASES[$i]}:${FILES[$i]}" | cmp -s - "$LIVE/${FILES[$i]}"
done
bash "$SCRIPT" --apply | grep -Fq "shell telefónico estable publicado"
for i in "${!FILES[@]}"; do
  git show "${TARGETS[$i]}:${FILES[$i]}" | cmp -s - "$LIVE/${FILES[$i]}"
done
cmp -s "$NEW" "$LIVE/$NEW"
[[ -n "$(find "$TEMP/backups" -type f)" ]]
bash "$SCRIPT" --check | grep -Fq "ya publicada"

# Debe preservar personalizaciones compatibles fuera del contexto del parche.
CONFLICT="$TEMP/conflict"
for i in "${!FILES[@]}"; do
  f="${FILES[$i]}"
  mkdir -p "$CONFLICT/$(dirname "$f")"
  git show "${BASES[$i]}:$f" > "$CONFLICT/$f"
done
python3 - "$CONFLICT/${FILES[0]}" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
t=p.read_text()
old='id="impeCrmFrame"'
assert old in t
p.write_text(t.replace(old,'id="impeCrmFrameModificado"',1))
PY
cp "$CONFLICT/${FILES[0]}" "$TEMP/before"
export IMPE_SHELL_ESTABLE_PUBLIC="$CONFLICT"
if bash "$SCRIPT" --apply >"$TEMP/conflict_log" 2>&1; then
  echo "ERROR: se aceptó una versión incompatible del shell." >&2
  exit 1
fi
grep -Fq "CONFLICTO:" "$TEMP/conflict_log"
cmp -s "$TEMP/before" "$CONFLICT/${FILES[0]}"
for i in 1 2 3; do
  git show "${BASES[$i]}:${FILES[$i]}" | cmp -s - "$CONFLICT/${FILES[$i]}"
done

echo "Shell Analista estable: OK (autoconexión, reload ruta, errores, preflight y respaldo)."
