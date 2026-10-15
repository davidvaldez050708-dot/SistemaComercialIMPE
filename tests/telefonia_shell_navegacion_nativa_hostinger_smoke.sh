#!/usr/bin/env bash
# Prueba sin producción de publicación selectiva del flujo natural.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
SCRIPT="tools/desplegar_navegacion_nativa_crm_hostinger.sh"
FILES=(
 "public/javascript/telefonia_shell_navegacion.js"
 "app/views/telefonia/shell.php"
 "app/views/layout/dashboard_head.php"
)
BASE=(
 "2b98075df90d7d8f2cc5386a9044e09c936667ee"
 "76b21195687998d5bc42da5f29052ce237265a4b"
 "659781c06a236c81ed84ebc4ac6b61c964ccfb17"
)
TARGET=(
 "76b21195687998d5bc42da5f29052ce237265a4b"
 "659781c06a236c81ed84ebc4ac6b61c964ccfb17"
 "377d018b08366d4f0393637105316c2ad6c8a73e"
)
node tests/telefonia_shell_navegacion_smoke.js
bash -n "$SCRIPT"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
SITE="$TMP/public_html"
mkdir -p "$SITE"
touch "$SITE/index.php"
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  mkdir -p "$SITE/$(dirname "$file")"
  git show "${BASE[$i]}:$file" > "$SITE/$file"
done
export IMPE_NAV_NATIVA_SOURCE="$ROOT"
export IMPE_NAV_NATIVA_PUBLIC="$SITE"
export IMPE_NAV_NATIVA_BACKUPS="$TMP/backups"

bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
for i in "${!FILES[@]}"; do
  git show "${BASE[$i]}:${FILES[$i]}" | cmp -s - "$SITE/${FILES[$i]}"
done
bash "$SCRIPT" --apply | grep -Fq 'navegación natural sin pantalla de carga'
for i in "${!FILES[@]}"; do
  git show "${TARGET[$i]}:${FILES[$i]}" | cmp -s - "$SITE/${FILES[$i]}"
done
[[ -n "$(find "$TMP/backups" -type f)" ]]
bash "$SCRIPT" --check | grep -Fq 'ya publicada'

# Layout con personalización fuera del parche: conservarla en la fusión.
CUSTOM="$TMP/personalizado"
mkdir -p "$CUSTOM"
touch "$CUSTOM/index.php"
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  mkdir -p "$CUSTOM/$(dirname "$file")"
  git show "${BASE[$i]}:$file" > "$CUSTOM/$file"
done
printf '\n<!-- PERSONALIZACION-DEL-LAYOUT -->\n' >> "$CUSTOM/${FILES[2]}"
export IMPE_NAV_NATIVA_PUBLIC="$CUSTOM"
bash "$SCRIPT" --check | grep -Fq 'PRODUCCIÓN INTACTA'
grep -Fq 'PERSONALIZACION-DEL-LAYOUT' "$CUSTOM/${FILES[2]}"
bash "$SCRIPT" --apply | grep -Fq 'navegación natural'
grep -Fq 'PERSONALIZACION-DEL-LAYOUT' "$CUSTOM/${FILES[2]}"
grep -Fq 'impeDashboardNativeNavigation' "$CUSTOM/${FILES[2]}"

# Un script de navegación distinto debe detener todo antes de tocar PHP.
UNKNOWN="$TMP/unknown"
mkdir -p "$UNKNOWN"
touch "$UNKNOWN/index.php"
for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  mkdir -p "$UNKNOWN/$(dirname "$file")"
  git show "${BASE[$i]}:$file" > "$UNKNOWN/$file"
done
printf '\n// CODIGO-DISTINTO-EN-PRODUCCION\n' >> "$UNKNOWN/${FILES[0]}"
cp "$UNKNOWN/${FILES[0]}" "$TMP/nav-before"
export IMPE_NAV_NATIVA_PUBLIC="$UNKNOWN"
if bash "$SCRIPT" --apply > "$TMP/error" 2>&1; then
  echo "ERROR: admitió versión JS desconocida." >&2
  exit 1
fi
grep -Fq 'CONFLICTO:' "$TMP/error"
cmp -s "$UNKNOWN/${FILES[0]}" "$TMP/nav-before"
git show "${BASE[1]}:${FILES[1]}" | cmp -s - "$UNKNOWN/${FILES[1]}"
echo "Navegación natural Hostinger: OK (sin loaders, respaldos, custom layout, idempotencia y conflictos)."
