#!/usr/bin/env bash
# IMPORTACIÓN DE INSTITUCIONES · Despliegue selectivo, sin SQL ni borrado de expedientes.
# --check jamás modifica public_html. --apply requiere compatibilidad y guarda respaldo.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo 'Uso: bash publicar_seguimiento_importador_excel_hostinger_v1.sh --check|--apply' >&2; exit 2;
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
WORK="$HOME/entregas-impe/seguimiento-excel-importacion-v1"
BACKUPS="$HOME/respaldos-impe-seguimiento-excel-importacion"
REV="b7cff39b2bc36e983dc544e7a0624a98a0d55a42"
BASE="a90b6aded4a7f606eccc7a27749658e3fed9b9ed"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE"
CONT="app/controllers/SeguimientoVinculacionController.php"
VIEW="app/views/seguimiento_vinculacion/index.php"
HIST="app/services/SeguimientoHistorialImportadoService.php"
IMPORT="app/services/SeguimientoExcelImportService.php"
TEMPLATE="app/services/SeguimientoExcelPlantillaService.php"
JS="public/javascript/seguimiento_importacion_excel.js"
CSS="public/css/seguimiento_importacion_excel.css"
ALL=("$CONT" "$VIEW" "$HIST" "$IMPORT" "$TEMPLATE" "$JS" "$CSS")
OLD=("$CONT" "$VIEW" "$HIST")

sha_target(){
  case "$1" in
    "$CONT") echo "e56a423ccaf5cf551e64ff4e155de5a47a73b1d3";;
    "$VIEW") echo "0e998b67291f4440f5872d348750a9879c334c8f";;
    "$HIST") echo "04b1cacf361b142edef95c96b48a244b2705a77e";;
    "$IMPORT") echo "6dcb4af841899ac4c62b553bd05107d45cdec2d4";;
    "$TEMPLATE") echo "6d46e73b8f81fae1e900bc2b15329051cba9eb5e";;
    "$JS") echo "f3c94b3449f90da4b1e4e4d867c88f792cd124ca";;
    "$CSS") echo "4df464faa9e69b2fd9b5191a068d71fb54e1dd6f";;
    *) echo "Archivo fuera de lista: $1" >&2; exit 1;;
  esac
}
sha_base(){
  case "$1" in
    "$CONT") echo "4b3836b7f84371e7f30d38daf678056947611366";;
    "$VIEW") echo "e00fbb150c3021fa1224126be3b308646899b9b1";;
    "$HIST") echo "d509ad67a7de3b69c809bd538479610286e14006";;
    *) echo "No hay versión base para $1" >&2; exit 1;;
  esac
}

for utility in git curl php node mktemp cmp cp mv grep; do
  command -v "$utility" >/dev/null || {
    echo "ERROR: falta $utility. SITIO INTACTO." >&2;exit 1;
  }
done
[[ -f "$SITE/index.php" && -f "$SITE/$CONT" && -f "$SITE/$VIEW" &&
    -f "$SITE/$HIST" && -f "$SITE/app/models/SeguimientoVinculacionModel.php" &&
    -f "$SITE/config/db_connection.php" ]] || {
  echo "ERROR: faltan archivos de Seguimiento en Hostinger. SITIO INTACTO." >&2;exit 1;
}
php -r '
foreach (["ZipArchive","DOMDocument","mysqli","DateTimeImmutable"] as $class){
 if(!class_exists($class)){fwrite(STDERR,"Falta extensión: ".$class.PHP_EOL);exit(1);}
}
if(!function_exists("mb_strtolower"))exit(1);
echo "DEPENDENCIAS PHP: ZIP, DOM, MySQLi y mbstring OK\n";
'

for file in "${ALL[@]}"; do
  [[ ! -L "$SITE/$file" ]] || { echo "Ruta simbólica no permitida: $file"; exit 1; }
done
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXX")"
WRITING=0
SUCCESS=0
BACKUP=''
cleanup(){
  local code=$?
  trap - EXIT
  if [[ "$WRITING" == '1' && "$SUCCESS" != '1' ]]; then
    echo 'ERROR: intentando restaurar archivos del respaldo privado...' >&2
    for file in "${ALL[@]}"; do
      if [[ -f "$BACKUP/existing/$file" ]]; then
        cp -p "$BACKUP/existing/$file" "$SITE/$file" ||
          echo "RESTAURA MANUALMENTE: $BACKUP/existing/$file" >&2
      else
        rm -f "$SITE/$file"
      fi
    done
    echo "RESPALDO: $BACKUP" >&2
  fi
  rm -rf "$STAGE"
  exit "$code"
}
trap cleanup EXIT

echo "1. Descargando fuente fijada por commit..."
for file in "${ALL[@]}"; do
  mkdir -p "$STAGE/target/$(dirname "$file")"
  curl -fLsS --retry 2 "$RAW/$REV/$file" -o "$STAGE/target/$file"
  [[ "$(git hash-object "$STAGE/target/$file")" == "$(sha_target "$file")" ]] || {
    echo "ERROR: archivo descargado distinto: $file. SITIO INTACTO." >&2;exit 1;
  }
done
for file in "${OLD[@]}"; do
  mkdir -p "$STAGE/base/$(dirname "$file")"
  curl -fLsS --retry 2 "$RAW/$BASE/$file" -o "$STAGE/base/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$(sha_base "$file")" ]] || {
    echo "ERROR: base de conciliación distinta: $file. SITIO INTACTO." >&2;exit 1;
  }
done

echo "2. Conciliando personalizaciones de Hostinger..."
for file in "${OLD[@]}"; do
  mkdir -p "$STAGE/publish/$(dirname "$file")"
  if ! git merge-file -p "$SITE/$file" "$STAGE/base/$file" "$STAGE/target/$file" \
      > "$STAGE/publish/$file"; then
    echo "CONFLICTO: $file. NO se publicó nada." >&2
    exit 1
  fi
done
for file in "$IMPORT" "$TEMPLATE" "$JS" "$CSS"; do
  if [[ -f "$SITE/$file" && "$(git hash-object "$SITE/$file")" != "$(sha_target "$file")" ]]; then
    echo "CONFLICTO: $file ya contiene otras personalizaciones. SITIO INTACTO." >&2
    exit 1
  fi
done

echo "3. Revisando rutas, dependencias y sintaxis..."
for file in "$CONT" "$VIEW" "$HIST"; do
  php -l "$STAGE/publish/$file"
done
for file in "$IMPORT" "$TEMPLATE"; do
  php -l "$STAGE/target/$file"
done
node --check "$STAGE/target/$JS"
grep -Fq "public function importarInstitucionesExcel()" "$STAGE/publish/$CONT"
grep -Fq "public function descargarPlantillaSeguimientoExcel()" "$STAGE/publish/$CONT"
grep -Fq "public function analistasImportacionExcel()" "$STAGE/publish/$CONT"
grep -Fq "modalImportacionSeguimientoExcel" "$STAGE/publish/$VIEW"
grep -Fq "IMPE_ETAPA_REPORTADA_EXCEL_V1" "$STAGE/publish/$HIST"
grep -Fq 'public const HEADERS=' "$STAGE/target/$IMPORT"
grep -Fq 'SeguimientoExcelPlantillaService' "$STAGE/target/$TEMPLATE"
grep -Fq 'data-impe-excel-confirm' "$STAGE/target/$JS"
echo "COMPROBACIÓN OK: siete archivos, sin SQL ni cambios de datos."
for file in "${ALL[@]}"; do
  if [[ "$file" == "$CONT" || "$file" == "$VIEW" || "$file" == "$HIST" ]];then
    candidate="$STAGE/publish/$file"
  else
    candidate="$STAGE/target/$file"
  fi
  if [[ -f "$SITE/$file" ]] && cmp -s "$SITE/$file" "$candidate";then
    echo "SIN CAMBIOS: $file"
  else
    echo "COMPATIBLE: $file"
  fi
done

if [[ "$MODE" == '--check' ]];then
  echo 'REVISIÓN COMPATIBLE. SITIO INTACTO. No se ejecutó SQL.'
  exit 0
fi

echo "4. Respaldando antes de publicar..."
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/lote-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${ALL[@]}"; do
  if [[ -f "$SITE/$file" ]];then
    mkdir -p "$BACKUP/existing/$(dirname "$file")"
    cp -p "$SITE/$file" "$BACKUP/existing/$file"
    cmp -s "$BACKUP/existing/$file" "$SITE/$file"
  fi
done
for file in "${OLD[@]}";do
  cmp -s "$BACKUP/existing/$file" "$SITE/$file" || {
    echo "CONFLICTO: $file cambió durante la revisión. SITIO INTACTO." >&2;exit 1;
  }
done
WRITING=1
for file in "${ALL[@]}";do
  if [[ "$file" == "$CONT" || "$file" == "$VIEW" || "$file" == "$HIST" ]];then
    candidate="$STAGE/publish/$file"
  else
    candidate="$STAGE/target/$file"
  fi
  if [[ -f "$SITE/$file" ]] && cmp -s "$SITE/$file" "$candidate";then continue;fi
  mkdir -p "$SITE/$(dirname "$file")"
  tmp="$SITE/$file.impe_xlsx_v1.$$"
  cp "$candidate" "$tmp"
  if [[ -f "$SITE/$file" ]];then
    chmod --reference="$SITE/$file" "$tmp"
  else
    chmod 0644 "$tmp"
  fi
  mv -f "$tmp" "$SITE/$file"
  echo "PUBLICADO: $file"
done
for file in "$CONT" "$HIST" "$IMPORT" "$TEMPLATE" "$VIEW";do
  php -l "$SITE/$file" >/dev/null
done
node --check "$SITE/$JS"
SUCCESS=1
echo "PUBLICACIÓN COMPLETA · respaldo: $BACKUP"
echo 'No se modificaron SQL, teléfonos, grabaciones, expedientes ni datos.'
echo 'VALIDACIÓN PENDIENTE: probar en navegador con un XLSX y reimportarlo.'
