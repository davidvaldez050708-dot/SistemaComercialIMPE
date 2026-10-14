#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
DEPLOY="tools/desplegar_modal_resultado_analista_hostinger.sh"
BASE="488628f758e60b982ee09386504f2cd025b520be"
TARGET="b3a817802db581da3f8f8c49e365c7e7f44f9b54"
FILES=(
  app/views/seguimiento_vinculacion/llamadas_entrantes.php
  public/css/seguimiento_entrantes_analista.css
)
PROTECTED=(
  public/javascript/telefonia_host.js
  public/javascript/telefonia_shell.js
  public/javascript/telefonia_sdk_entrantes_observador.js
  public/javascript/telefonia_analista_historial_pasivo.js
  public/javascript/seguimiento_entrantes_analista.js
  app/services/TelefoniaEntrantesAnalistaService.php
  app/services/TelefoniaAnalistaWebrtcService.php
  app/views/telefonia/host.php
  app/views/telefonia/shell.php
)
php -l app/views/seguimiento_vinculacion/llamadas_entrantes.php >/dev/null
php tests/telefonia_resultado_analista_modal_smoke.php
bash -n "$DEPLOY"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
export IMPE_RESULT_SOURCE="$PWD" IMPE_RESULT_BACKUPS="$TMP/backups"
export IMPE_RESULT_PUBLIC="$TMP/public_html"
mkdir -p "$IMPE_RESULT_PUBLIC" "$TMP/protected"
git archive "$BASE" | tar -xf - -C "$IMPE_RESULT_PUBLIC"
for file in "${PROTECTED[@]}"; do
  mkdir -p "$TMP/protected/$(dirname "$file")"
  cp -p "$IMPE_RESULT_PUBLIC/$file" "$TMP/protected/$file"
done
check="$(bash "$DEPLOY" --check)"
grep -Fq "formulario y estilos compatibles (2 archivos). PRODUCCIÓN INTACTA" <<< "$check"
for file in "${FILES[@]}"; do
  git show "$BASE:$file" | cmp -s - "$IMPE_RESULT_PUBLIC/$file"
done
bash "$DEPLOY" --apply
for file in "${FILES[@]}"; do
  git show "$TARGET:$file" | cmp -s - "$IMPE_RESULT_PUBLIC/$file"
  [[ "$(stat -c %a "$IMPE_RESULT_PUBLIC/$file")" == 644 ]]
done
for file in "${PROTECTED[@]}"; do
  cmp -s "$TMP/protected/$file" "$IMPE_RESULT_PUBLIC/$file"
done
[[ -d "$IMPE_RESULT_BACKUPS" ]]
again="$(bash "$DEPLOY" --check)"
grep -Fq 'ya actualizado' <<< "$again"

# Un cambio en el formulario que no sea compatible bloquea TODO el despliegue.
export IMPE_RESULT_PUBLIC="$TMP/conflict"
mkdir -p "$IMPE_RESULT_PUBLIC"
git archive "$BASE" | tar -xf - -C "$IMPE_RESULT_PUBLIC"
php -r '
$file=$argv[1];
$text=file_get_contents($file);
$a="<form data-incoming-form>";
$b="<form data-incoming-form data-extra-warning>";
if (substr_count($text,$a)!==1) exit(1);
file_put_contents($file,str_replace($a,$b,$text));
' "$IMPE_RESULT_PUBLIC/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
cp -p "$IMPE_RESULT_PUBLIC/app/views/seguimiento_vinculacion/llamadas_entrantes.php" "$TMP/conflict-original.php"
if bash "$DEPLOY" --apply >"$TMP/failure.log" 2>&1; then
  echo "ERROR: se aplicó un parche incompatible" >&2
  exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/failure.log"
cmp -s "$TMP/conflict-original.php" "$IMPE_RESULT_PUBLIC/app/views/seguimiento_vinculacion/llamadas_entrantes.php"
git show "$BASE:public/css/seguimiento_entrantes_analista.css" |
  cmp -s - "$IMPE_RESULT_PUBLIC/public/css/seguimiento_entrantes_analista.css"
echo "Formulario Analista Hostinger: OK (scroll, pie fijo, respaldo, aislamiento y rechazo seguro)"
