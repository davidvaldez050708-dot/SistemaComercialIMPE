#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SCRIPT="tools/desplegar_transferencia_flujo_recepcion_hostinger.sh"
BASE="470fe8d91afbf4c11a9c170a1691847d05b61341"
TARGET="3d9890350e9650ec54bdbfcb900efea37f9d9bf6"
FILES=(
 app/controllers/TelefoniaController.php
 app/services/TelefoniaEnrutamientoService.php
 public/javascript/telefonia_enrutamiento.js
)
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE"
touch "$SITE/index.php"
for file in "${FILES[@]}"; do
  mkdir -p "$SITE/$(dirname "$file")"
  git show "$BASE:$file" > "$SITE/$file"
done
export IMPE_RECEPTION_FIX_SOURCE="$ROOT"
export IMPE_RECEPTION_FIX_PUBLIC="$SITE"
export IMPE_RECEPTION_FIX_BACKUPS="$TMP/backups"

bash -n "$SCRIPT"
bash "$SCRIPT" --check > "$TMP/check"
grep -Fq 'PRODUCCIÓN INTACTA' "$TMP/check"
for file in "${FILES[@]}"; do
  git show "$BASE:$file" | cmp -s - "$SITE/$file"
done
bash "$SCRIPT" --apply | grep -Fq 'token de transferencia corregidos'
for file in "${FILES[@]}"; do
  git show "$TARGET:$file" | cmp -s - "$SITE/$file"
done
[[ -n "$(find "$TMP/backups" -type f)" ]]
bash "$SCRIPT" --check | grep -Fq 'ya corregidos'
bash "$SCRIPT" --apply | grep -Fq 'ya corregidos'
# Preserve a production-only comment outside the patch context.
CUSTOM="$TMP/custom"
mkdir -p "$CUSTOM"
touch "$CUSTOM/index.php"
for file in "${FILES[@]}"; do
  mkdir -p "$CUSTOM/$(dirname "$file")"
  git show "$BASE:$file" > "$CUSTOM/$file"
done
printf '\n/* NOTA-WEBRTC-HISTORICA */\n' >> "$CUSTOM/public/javascript/telefonia_enrutamiento.js"
export IMPE_RECEPTION_FIX_PUBLIC="$CUSTOM"
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
bash "$SCRIPT" --apply | grep -Fq 'token de transferencia corregidos'
grep -Fq 'NOTA-WEBRTC-HISTORICA' "$CUSTOM/public/javascript/telefonia_enrutamiento.js"
grep -Fq 'confirmedRecord' "$CUSTOM/public/javascript/telefonia_enrutamiento.js"

# A conflict in the CSRF validation code blocks all writes.
CONFLICT="$TMP/conflict"
mkdir -p "$CONFLICT"
touch "$CONFLICT/index.php"
for file in "${FILES[@]}"; do
  mkdir -p "$CONFLICT/$(dirname "$file")"
  git show "$BASE:$file" > "$CONFLICT/$file"
done
python3 - "$CONFLICT/app/controllers/TelefoniaController.php" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
needle="if (!TelefoniaContactosService::validarToken("
assert s.count(needle)>=2
i=s.index("private function validarTransferenciaPost(): void")
pos=s.index(needle,i)
s=s[:pos]+s[pos:].replace(needle,"if (!validacionIncompatible(",1)
p.write_text(s)
PY
cp "$CONFLICT/app/controllers/TelefoniaController.php" "$TMP/unchanged.php"
export IMPE_RECEPTION_FIX_PUBLIC="$CONFLICT"
if bash "$SCRIPT" --apply > "$TMP/error" 2>&1; then
  echo 'ERROR: controlador incompatible aceptado.' >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/error"
cmp -s "$CONFLICT/app/controllers/TelefoniaController.php" "$TMP/unchanged.php"
git show "$BASE:public/javascript/telefonia_enrutamiento.js" |
 cmp -s - "$CONFLICT/public/javascript/telefonia_enrutamiento.js"
echo 'Transferencias recepción: OK (CSRF de sesión, búsqueda, idempotencia, backup y conflictos).'
