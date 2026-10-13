#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE"
touch "$SITE/index.php"
FILES=(
 app/services/TelefoniaRecepcionMarketingService.php
 public/javascript/telefonia_recepcion_marketing.js
 app/views/telefonia/recepcion_marketing.php
 public/css/telefonia_recepcion_marketing.css
)
for p in "${FILES[@]}"; do
 mkdir -p "$SITE/$(dirname "$p")"
 git -C "$ROOT" show "2abcfff9e7d789a582713cb4a7f3865e760baa8c:$p" > "$SITE/$p"
done
# El CSS de producción contiene personalizaciones: no eliminarlas.
echo '/* ESTILO-HOSTINGER-PRESERVAR */' >> "$SITE/public/css/telefonia_recepcion_marketing.css"
run() {
 IMPE_HISTORIAL_MARKETING_SOURCE="$ROOT" \
 IMPE_HISTORIAL_MARKETING_PUBLIC="$SITE" \
 IMPE_HISTORIAL_MARKETING_BACKUP="$TMP/backups" \
 bash "$ROOT/tools/desplegar_historial_unificado_marketing_hostinger.sh" "$1"
}
BEFORE="$(sha256sum "$SITE/public/javascript/telefonia_recepcion_marketing.js")"
run --check
[[ "$BEFORE" == "$(sha256sum "$SITE/public/javascript/telefonia_recepcion_marketing.js")" ]]
run --apply
grep -Fq 'ESTILO-HOSTINGER-PRESERVAR' "$SITE/public/css/telefonia_recepcion_marketing.css"
grep -Fq '<th>Tipo</th>' "$SITE/app/views/telefonia/recepcion_marketing.php"
grep -Fq 'agruparEventos(' "$SITE/app/services/TelefoniaRecepcionMarketingService.php"
[[ "$(grep -Fc '/* Historial único Marketing: entrantes y salientes */' \
 "$SITE/public/css/telefonia_recepcion_marketing.css")" == 1 ]]
[[ "$(find "$TMP/backups" -type f | wc -l)" -eq 4 ]]
run --apply
# Un conflicto real de vista debe impedir cambios en cualquiera de los archivos.
git -C "$ROOT" show \
 '2abcfff9e7d789a582713cb4a7f3865e760baa8c:app/views/telefonia/recepcion_marketing.php' \
 > "$SITE/app/views/telefonia/recepcion_marketing.php"
sed -i 's/<th>Número entrante<\\/th>/<th>Número personal<\\/th>/' \
 "$SITE/app/views/telefonia/recepcion_marketing.php"
BEFORE="$(sha256sum "$SITE/app/views/telefonia/recepcion_marketing.php")"
if run --apply > "$TMP/conflict.log" 2>&1; then
 echo "ERROR: conflicto de Hostinger no detectado." >&2; exit 1
fi
grep -Fq 'CONFLICTO' "$TMP/conflict.log"
[[ "$BEFORE" == "$(sha256sum "$SITE/app/views/telefonia/recepcion_marketing.php")" ]]
echo 'Historial unificado Marketing: OK (simulación, respaldo, idempotencia y conflicto)'
