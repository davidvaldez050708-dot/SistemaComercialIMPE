#!/usr/bin/env bash
# Ajuste exclusivo del modal de importación de instituciones (vista, CSS, JS).
# --check NO cambia producción. --apply: respaldo privado, publicación atómica y rollback.
set -Eeuo pipefail
umask 077

MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo 'Usa --check o --apply' >&2; exit 2;
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
WORK="$HOME/entregas-impe/seguimiento-excel-modal-ux-v2"
BACKUPS="$HOME/respaldos-impe-seguimiento-excel-modal-ux-v2"
REV="7757bbaaa92b3062c64ef62b474a423e84dd353f"
BASE="8e13e9e4667be56590cc144efa0da43a458b96ad"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE"
VIEW="app/views/seguimiento_vinculacion/index.php"
CSS="public/css/seguimiento_importacion_excel.css"
JS="public/javascript/seguimiento_importacion_excel.js"
FILES=("$VIEW" "$CSS" "$JS")

sha_old() {
  case "$1" in
    "$VIEW") echo '0e998b67291f4440f5872d348750a9879c334c8f';;
    "$CSS") echo '4df464faa9e69b2fd9b5191a068d71fb54e1dd6f';;
    "$JS") echo 'f3c94b3449f90da4b1e4e4d867c88f792cd124ca';;
    *) return 1;;
  esac
}
sha_new() {
  case "$1" in
    "$VIEW") echo 'e2b6f707c7f1c42071a0ca41c78e1c46a73e114f';;
    "$CSS") echo '1e4f96b4ce0d15b579298e305d71e87401824aa0';;
    "$JS") echo '18cad69c3153a04272085fd9cde516e288eb1601';;
    *) return 1;;
  esac
}

for cmd in git curl php mktemp cp cmp mv grep; do
  command -v "$cmd" >/dev/null || {
    echo "ERROR: falta $cmd. SITIO INTACTO." >&2; exit 1;
  }
done
[[ -f "$SITE/index.php" && -f "$SITE/$VIEW" && -f "$SITE/$CSS" && -f "$SITE/$JS" ]] || {
  echo 'ERROR: no se encuentra el modal de Seguimiento V1. SITIO INTACTO.' >&2; exit 1;
}
for file in "${FILES[@]}"; do
  [[ ! -L "$SITE/$file" ]] || { echo "Ruta simbólica: $file. SITIO INTACTO." >&2; exit 1; }
done

mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXX")"
BACKUP=''
CHANGING=0
COMPLETE=0
cleanup() {
  local code=$?
  trap - EXIT
  if [[ "$CHANGING" == '1' && "$COMPLETE" != '1' ]]; then
    echo "ERROR: restaurando únicamente los tres archivos del modal..." >&2
    for file in "${FILES[@]}"; do
      if [[ -f "$BACKUP/antes/$file" ]]; then
        cp -p "$BACKUP/antes/$file" "$SITE/$file" ||
          echo "ATENCIÓN: restaura $file desde $BACKUP/antes/$file" >&2
      fi
    done
    echo "Respaldo: $BACKUP" >&2
  fi
  rm -rf "$STAGE"
  exit "$code"
}
trap cleanup EXIT

echo "1. Descargando versión nueva y base verificadas..."
for file in "${FILES[@]}"; do
  mkdir -p "$STAGE/base/$(dirname "$file")" "$STAGE/nuevo/$(dirname "$file")"
  curl -fLsS --retry 2 "$RAW/$BASE/$file" -o "$STAGE/base/$file"
  curl -fLsS --retry 2 "$RAW/$REV/$file" -o "$STAGE/nuevo/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$(sha_old "$file")" ]] || {
    echo "ERROR: base incorrecta: $file. SITIO INTACTO." >&2;exit 1;
  }
  [[ "$(git hash-object "$STAGE/nuevo/$file")" == "$(sha_new "$file")" ]] || {
    echo "ERROR: versión nueva incorrecta: $file. SITIO INTACTO." >&2;exit 1;
  }
done

echo "2. Revisando personalizaciones de producción..."
# Solo la vista se concilia por tres vías, porque puede contener HTML personalizado.
mkdir -p "$STAGE/candidato/$(dirname "$VIEW")"
if ! git merge-file -p "$SITE/$VIEW" "$STAGE/base/$VIEW" "$STAGE/nuevo/$VIEW" \
  > "$STAGE/candidato/$VIEW"; then
  echo "CONFLICTO en vista: personalización no conciliable. SITIO INTACTO." >&2
  exit 1
fi
# JS y CSS no se fuerzan si tuvieron personalizaciones posteriores.
for file in "$CSS" "$JS"; do
  current="$(git hash-object "$SITE/$file")"
  if [[ "$current" != "$(sha_old "$file")" && "$current" != "$(sha_new "$file")" ]]; then
    echo "CONFLICTO: $file cambió desde la entrega anterior. SITIO INTACTO." >&2
    exit 1
  fi
done

echo "3. Comprobando sintaxis y elementos sin modificar producción..."
php -l "$STAGE/candidato/$VIEW"
grep -Fq "seguimiento_importacion_excel.css?v=20261026-ux2" "$STAGE/candidato/$VIEW"
grep -Fq "seguimiento_importacion_excel.js?v=20261026-ux2" "$STAGE/candidato/$VIEW"
[[ "$(grep -Fc 'id="impe_excel_file"' "$STAGE/candidato/$VIEW")" == '1' ]] || {
  echo 'CONFLICTO: selector de Excel no único. SITIO INTACTO.' >&2; exit 1;
}
grep -Fq 'data-impe-excel-file-name' "$STAGE/candidato/$VIEW"
grep -Fq 'IMPE_SEGUIMIENTO_EXCEL_MODAL_UX_V2_20261026' "$STAGE/nuevo/$CSS"
grep -Fq 'syncFileName()' "$STAGE/nuevo/$JS"
if command -v node >/dev/null 2>&1; then
  node --check "$STAGE/nuevo/$JS"
elif command -v nodejs >/dev/null 2>&1; then
  nodejs --check "$STAGE/nuevo/$JS"
else
  echo 'JavaScript: integridad SHA verificada; Node.js no requerido en Hostinger.'
fi
echo 'COMPATIBLE: estilos del modal, selector de archivo y encabezados.'
echo 'SIN CAMBIOS en SQL, creación de seguimientos, datos, telefonía y otros módulos.'

for file in "${FILES[@]}"; do
  target="$STAGE/nuevo/$file"
  [[ "$file" != "$VIEW" ]] || target="$STAGE/candidato/$VIEW"
  if cmp -s "$SITE/$file" "$target"; then
    echo "SIN CAMBIOS: $file"
  else
    echo "PREPARADO: $file"
  fi
done

if [[ "$MODE" == '--check' ]]; then
  echo 'REVISIÓN COMPATIBLE. SITIO INTACTO.'
  exit 0
fi

echo "4. Creando respaldo privado y comprobando ausencia de cambios simultáneos..."
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/lote-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${FILES[@]}"; do
  mkdir -p "$BACKUP/antes/$(dirname "$file")"
  cp -p "$SITE/$file" "$BACKUP/antes/$file"
  cmp -s "$BACKUP/antes/$file" "$SITE/$file"
done
CHANGING=1
for file in "${FILES[@]}"; do
  # No reemplazar si se cambió un archivo desde que se preparó el candidato.
  cmp -s "$BACKUP/antes/$file" "$SITE/$file" || {
    echo "CONFLICTO: $file cambió antes de publicarse." >&2;exit 1;
  }
  target="$STAGE/nuevo/$file"
  [[ "$file" != "$VIEW" ]] || target="$STAGE/candidato/$VIEW"
  if cmp -s "$SITE/$file" "$target"; then continue; fi
  temp="$SITE/$file.impe-ux-v2.$$"
  cp "$target" "$temp"
  chmod --reference="$SITE/$file" "$temp"
  mv -f "$temp" "$SITE/$file"
  echo "PUBLICADO: $file"
done
php -l "$SITE/$VIEW" >/dev/null
[[ "$(git hash-object "$SITE/$CSS")" == "$(sha_new "$CSS")" ]]
[[ "$(git hash-object "$SITE/$JS")" == "$(sha_new "$JS")" ]]
COMPLETE=1
echo "PUBLICACIÓN COMPLETA · RESPALDO: $BACKUP"
echo 'Solo ajustes visuales del modal y sincronización del nombre del archivo.'
