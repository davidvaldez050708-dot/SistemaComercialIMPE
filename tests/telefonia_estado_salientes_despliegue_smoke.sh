#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
FILE='prueba_telefonia/api/zadarma_estado_llamada.php'
SITE="$TMP/public_html"
mkdir -p "$SITE/$(dirname "$FILE")"
git -C "$ROOT" show "3ac9f43133852adca9e873e2d5b1794d201db205:$FILE" > "$SITE/$FILE"
run() {
 IMPE_ESTADO_SALIENTES_SOURCE="$ROOT" \
 IMPE_ESTADO_SALIENTES_PUBLIC="$SITE" \
 IMPE_ESTADO_SALIENTES_BACKUP="$TMP/backups" \
 bash "$ROOT/tools/desplegar_estado_salientes_hostinger.sh" "$1"
}
SHA="$(sha256sum "$SITE/$FILE")"
run --check
[[ "$SHA" == "$(sha256sum "$SITE/$FILE")" ]]
run --apply
cmp -s "$SITE/$FILE" "$ROOT/$FILE"
[[ "$(find "$TMP/backups" -name 'zadarma_estado_llamada.php' | wc -l)" -eq 1 ]]
run --apply
# El endpoint personalizado pero compatible mantiene sus ajustes locales.
git -C "$ROOT" show "3ac9f43133852adca9e873e2d5b1794d201db205:$FILE" > "$SITE/$FILE"
echo '// AJUSTE-LOCAL-CONSERVADO' >> "$SITE/$FILE"
run --check
run --apply
grep -Fq 'AJUSTE-LOCAL-CONSERVADO' "$SITE/$FILE"
# Si se modificó el guardia de acceso, jamás forzar el reemplazo.
git -C "$ROOT" show "3ac9f43133852adca9e873e2d5b1794d201db205:$FILE" > "$SITE/$FILE"
sed -i "s/'Asesor de Ventas'/'Rol inventado'/" "$SITE/$FILE"
SHA="$(sha256sum "$SITE/$FILE")"
if run --apply > "$TMP/conflicto" 2>&1; then
 echo "ERROR: se reemplazó un guardia incompatible." >&2; exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/conflicto"
[[ "$SHA" == "$(sha256sum "$SITE/$FILE")" ]]
echo "Estado saliente Hostinger: OK (comprobación, respaldo y conflicto)"
