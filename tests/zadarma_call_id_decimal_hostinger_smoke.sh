#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
HELPER="app/helpers/ZadarmaPbxIdentifierHelper.php"
SVC="app/services/TelefoniaInternaGrabacionService.php"
RECORD="app/services/ZadarmaRecordingService.php"
REPAIR="tools/reparar_prueba_interna_100_101.php"
DEPLOY="tools/desplegar_call_id_decimal_zadarma_hostinger.sh"
BASE="10f689e1a086ba24d40d623b945db47606f8933a"
TARGET="0fe78050363d9f27712816695975f1aa08538fde"

php -l "$HELPER" >/dev/null
php -l "$SVC" >/dev/null
php -l "$RECORD" >/dev/null
php -l "$REPAIR" >/dev/null
php tests/zadarma_call_id_decimal_smoke.php
bash -n "$DEPLOY"
grep -Fq 'ZadarmaPbxIdentifierHelper::decodificarEstadisticas' "$REPAIR"
grep -Fq 'ZadarmaPbxIdentifierHelper::callIdDeFila' "$REPAIR"
grep -Fq 'ZadarmaPbxIdentifierHelper::callIdValido' "$RECORD"
grep -Fq 'ZadarmaPbxIdentifierHelper::callIdDeFila' "$SVC"
grep -Fq 'ZadarmaPbxIdentifierHelper::callIdValido' "$SVC"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE/$(dirname "$SVC")" "$TMP/backups"
git show "$BASE:$SVC" > "$SITE/$SVC"
git show "$BASE:$RECORD" > "$SITE/$RECORD"

export IMPE_DECIMAL_SOURCE="$ROOT"
export IMPE_DECIMAL_PUBLIC="$SITE"
export IMPE_DECIMAL_BACKUPS="$TMP/backups"
bash "$DEPLOY" --check | grep -q 'PRODUCCIÓN INTACTA'
[[ ! -f "$SITE/$HELPER" ]]
git show "$BASE:$SVC" | cmp - "$SITE/$SVC"
bash "$DEPLOY" --apply | grep -q 'decimales Zadarma habilitados'
cmp -s "$ROOT/$HELPER" "$SITE/$HELPER"
git show "$TARGET:$SVC" | cmp - "$SITE/$SVC"
git show "$TARGET:$RECORD" | cmp - "$SITE/$RECORD"
bash "$DEPLOY" --check | grep -q 'ya publicada'
[[ -n "$(find "$TMP/backups" -type f)" ]]

# No reemplazar cambios desconocidos en producción.
echo '/* personalizacion no contemplada */' >> "$SITE/$RECORD"
cp "$SITE/$RECORD" "$TMP/personalizado"
if bash "$DEPLOY" --apply >"$TMP/conflicto.txt" 2>&1; then
  echo "ERROR: se aceptó un archivo modificado." >&2
  exit 1
fi
cmp -s "$SITE/$RECORD" "$TMP/personalizado"
grep -Fq 'CONFLICTO' "$TMP/conflicto.txt"
echo "Deploy decimal Zadarma: OK (check, apply, idempotencia, respaldo, conflicto)."
