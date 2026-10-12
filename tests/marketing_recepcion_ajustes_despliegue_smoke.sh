#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
LIVE="$TMP/public_html"
mkdir -p "$LIVE"
touch "$LIVE/index.php"
FILES=(
 public/javascript/telefonia_shell.js
 public/javascript/telefonia_recepcion_marketing.js
 public/css/telefonia_recepcion_marketing.css
 app/views/telefonia/recepcion_marketing.php
 app/services/ZadarmaRecordingService.php
 prueba_telefonia/api/grabacion_recepcion_marketing.php
)
for p in "${FILES[@]}"; do
 mkdir -p "$LIVE/$(dirname "$p")"
 git -C "$ROOT" show "c9e9506479d99347bf5b5225894bbdef987d27c9:$p" > "$LIVE/$p"
done
# Variación histórica del CSS del servidor: ajuste local al final
# y un cambio dentro del bloque antiguo que choca con git merge-file.
CSS="$LIVE/public/css/telefonia_recepcion_marketing.css"
sed -i 's/min-height: 28px;/min-height: 31px;/' "$CSS"
echo '/* AJUSTE-LOCAL-PRESERVAR */' >> "$CSS"
CSS_BEFORE="$(sha256sum "$CSS")"
run() {
 IMPE_MARKETING_AJUSTES_SOURCE="$ROOT" \
 IMPE_MARKETING_AJUSTES_PUBLIC="$LIVE" \
 IMPE_MARKETING_AJUSTES_BACKUP="$TMP/backups" \
 bash "$ROOT/tools/desplegar_ajustes_recepcion_marketing_hostinger.sh" "$1"
}
BEFORE="$(sha256sum "$LIVE/public/javascript/telefonia_shell.js")"
run --check
[[ "$BEFORE" == "$(sha256sum "$LIVE/public/javascript/telefonia_shell.js")" ]]
[[ "$CSS_BEFORE" == "$(sha256sum "$CSS")" ]]
run --apply
grep -Fq 'AJUSTE-LOCAL-PRESERVAR' "$CSS"
grep -Fq 'min-height: 31px;' "$CSS"
[[ "$(grep -Fc 'Una acción general por registro, compacta y consistente' "$CSS")" -eq 1 ]]
grep -Fq 'syncIncomingClock(value)' "$LIVE/public/javascript/telefonia_shell.js"
grep -Fq 'Consultar audio' "$LIVE/public/javascript/telefonia_recepcion_marketing.js"
test "$(find "$TMP/backups" -type f | wc -l)" -eq 6
run --apply
[[ "$(grep -Fc 'Una acción general por registro, compacta y consistente' "$CSS")" -eq 1 ]]
# Una variante desconocida del nuevo botón es conflicto, NO se sobreescribe.
printf '\n.marketing-reception-call { color: magenta; }\n' >> "$CSS"
CSS_CONFLICT_SHA="$(sha256sum "$CSS")"
if run --apply > "$TMP/conflicto-css.log" 2>&1; then
 echo "ERROR: se sobrescribió una variante local de Llamar." >&2; exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/conflicto-css.log"
[[ "$CSS_CONFLICT_SHA" == "$(sha256sum "$CSS")" ]]
# Restaurar la hoja válida para seguir probando el conflicto JavaScript.
sed -i '/\.marketing-reception-call { color: magenta; }/d' "$CSS"
# Reproducir conflicto real al editar el mismo bloque de la rama remota.
git -C "$ROOT" show \
 "c9e9506479d99347bf5b5225894bbdef987d27c9:public/javascript/telefonia_recepcion_marketing.js" \
 > "$LIVE/public/javascript/telefonia_recepcion_marketing.js"
sed -i "s/'Devolver llamada'/'Marcación personalizada'/g" \
 "$LIVE/public/javascript/telefonia_recepcion_marketing.js"
BEFORE="$(sha256sum "$LIVE/public/javascript/telefonia_recepcion_marketing.js")"
if run --apply > "$TMP/conflicto.log" 2>&1; then
 echo "ERROR: se aceptó el conflicto local." >&2; exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/conflicto.log"
[[ "$BEFORE" == "$(sha256sum "$LIVE/public/javascript/telefonia_recepcion_marketing.js")" ]]
echo "Marketing Hostinger: OK (comprobación, respaldo, repetición y conflicto)"
