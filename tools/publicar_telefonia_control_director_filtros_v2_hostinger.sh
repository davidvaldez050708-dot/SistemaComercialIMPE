#!/usr/bin/env bash
# Centro de Control de llamadas V2. Revisión no destructiva y publicación con respaldo.
# --check: SIN escritura en public_html, SQL o datos. --apply: solo 6 archivos web.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Uso: bash publicar_telefonia_control_director_filtros_v2_hostinger.sh --check|--apply" >&2
  exit 2
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
WORK="$HOME/entregas-impe/telefonia-control-director-v2"
BACKUPS="$HOME/respaldos-impe-telefonia-control-director-v2"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE"
REV="e54e67de39f06ceae3cb3aae184abb6154fd1f49"
BASE="a90b6aded4a7f606eccc7a27749658e3fed9b9ed"

CONT="app/controllers/TelefoniaController.php"
ROLE="app/models/RolModel.php"
VIEW="app/views/telefonia/index.php"
SIDE="app/views/layout/sidebar.php"
JS="public/javascript/telefonia_control.js"
CSS="public/css/telefonia_control_filtros_v2.css"
OLD=("$CONT" "$ROLE" "$VIEW" "$SIDE" "$JS")
ALL=("$CONT" "$ROLE" "$VIEW" "$SIDE" "$JS" "$CSS")
expected_new() {
  case "$1" in
    "$CONT") echo 05e76413f1851167d8232f630260a5f6c949f5ae;;
    "$ROLE") echo 57ad0ecb0232a6d0fee35897a27a78cf1772855d;;
    "$VIEW") echo dac667303d0cdb6c78971d7ecae9aaca4179af22;;
    "$SIDE") echo 46ab90757847f40d83d4b64cb6f2e61cfe8d4306;;
    "$JS") echo a9e5aedf21b74a8dca61ae9688f37f3fbb566bc6;;
    "$CSS") echo 69dc0b47cf8e3c477dc73061a4fd3eb049db9bda;;
    *) return 1;;
  esac
}
expected_base() {
  case "$1" in
    "$CONT") echo fbe30cfbd69b2d881c8e330fe5988e6674ff36b2;;
    "$ROLE") echo a9f3b09d7e25bb265e319de626f4a762c46a053d;;
    "$VIEW") echo 6cf7ef079d647a3c0879b8b262e1c3d31274e55d;;
    "$SIDE") echo d24f9016b054393898e57db651cd04e2ab86a0e4;;
    "$JS") echo 890e75ff3e3a555df2eee3120e056299fc8375e6;;
    *) return 1;;
  esac
}

for tool in php git curl cmp cp mv mktemp grep; do
  command -v "$tool" >/dev/null || { echo "ERROR: falta $tool. SITIO INTACTO." >&2; exit 1; }
done
for file in "${OLD[@]}"; do
  [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] || {
    echo "ERROR: dependencia ausente o simbólica: $file. SITIO INTACTO." >&2;exit 1;
  }
done
[[ ! -L "$SITE/$CSS" ]] || { echo "CSS simbólico. SITIO INTACTO." >&2; exit 1; }
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXXXX")"
BACKUP=""
WRITING=0
SUCCESS=0
exit_cleanup(){
  local code=$?
  trap - EXIT
  if [[ "$WRITING" == 1 && "$SUCCESS" != 1 ]]; then
    echo "FALLO DE PUBLICACIÓN: restaurando seis archivos del respaldo..." >&2
    for file in "${ALL[@]}"; do
      if [[ -f "$BACKUP/original/$file" ]];then
        cp -p "$BACKUP/original/$file" "$SITE/$file" || echo "RESTAURAR: $file desde $BACKUP/original/$file" >&2
      else
        rm -f "$SITE/$file"
      fi
    done
    echo "RESPALDO CONSERVADO: $BACKUP" >&2
  fi
  rm -rf "$STAGE"
  exit "$code"
}
trap exit_cleanup EXIT

echo "1. Descargando revisión firmada por huellas Git..."
for file in "${ALL[@]}"; do
  mkdir -p "$STAGE/new/$(dirname "$file")"
  curl -fLsS --retry 2 "$RAW/$REV/$file" -o "$STAGE/new/$file"
  [[ "$(git hash-object "$STAGE/new/$file")" == "$(expected_new "$file")" ]] || {
    echo "ERROR: fuente V2 distinta: $file. SITIO INTACTO." >&2;exit 1;
  }
done
for file in "${OLD[@]}"; do
  mkdir -p "$STAGE/base/$(dirname "$file")" "$STAGE/candidate/$(dirname "$file")"
  curl -fLsS --retry 2 "$RAW/$BASE/$file" -o "$STAGE/base/$file"
  [[ "$(git hash-object "$STAGE/base/$file")" == "$(expected_base "$file")" ]] || {
    echo "ERROR: base distinta: $file. SITIO INTACTO." >&2;exit 1;
  }
done

echo "2. Conciliando archivos existentes sin pisar cambios personalizados..."
for file in "${OLD[@]}"; do
  if cmp -s "$SITE/$file" "$STAGE/new/$file"; then
    cp "$STAGE/new/$file" "$STAGE/candidate/$file"
  elif ! git merge-file -p "$SITE/$file" "$STAGE/base/$file" "$STAGE/new/$file" > "$STAGE/candidate/$file"; then
    echo "CONFLICTO: $file. Sitio intacto; no forzar." >&2
    exit 1
  fi
done
if [[ -f "$SITE/$CSS" && "$(git hash-object "$SITE/$CSS")" != "$(expected_new "$CSS")" ]]; then
  echo "CONFLICTO: CSS V2 ya personalizado. SITIO INTACTO." >&2
  exit 1
fi
cp "$STAGE/new/$CSS" "$STAGE/candidate/$CSS"

echo "3. Verificando sintaxis, alcance y permisos..."
for file in "$CONT" "$ROLE" "$VIEW" "$SIDE"; do php -l "$STAGE/candidate/$file" || exit 1; done
grep -Fq "telefonia.control_ver" "$STAGE/candidate/$ROLE"
grep -Fq "Director de Operaciones" "$STAGE/candidate/$ROLE"
grep -Fq "validarAccesoControlLlamadas()" "$STAGE/candidate/$CONT"
grep -Fq 'validarAdministrador()' "$STAGE/candidate/$CONT"
grep -Fq 'data-control-auto-filters' "$STAGE/candidate/$VIEW"
grep -Fq 'Limpiar filtros' "$STAGE/candidate/$VIEW"
grep -Fq 'telefonia_control_filtros_v2.css' "$STAGE/candidate/$VIEW"
grep -Fq 'mostrarTelefoniaControl' "$STAGE/candidate/$SIDE"
grep -Fq 'window.location.assign' "$STAGE/candidate/$JS"
if command -v node >/dev/null 2>&1; then
  node --check "$STAGE/candidate/$JS"
elif command -v nodejs >/dev/null 2>&1; then
  nodejs --check "$STAGE/candidate/$JS"
else
  echo 'JS: sintaxis comprobada en la entrega. Hostinger sin Node.js; se conserva conciliación, sin instalación.'
fi
[[ "$(grep -Fc 'function validarAccesoControlLlamadas()' "$STAGE/candidate/$CONT")" == 1 ]]
[[ "$(grep -Fc "'codigo' => 'telefonia.control_ver'" "$STAGE/candidate/$ROLE")" == 1 ]]
[[ "$(grep -Fc 'data-control-auto-filters' "$STAGE/candidate/$VIEW")" == 1 ]]
echo 'COMPATIBLE: permiso separado, filtros automáticos y estilos institucionales.'
for file in "${ALL[@]}"; do
  if [[ -f "$SITE/$file" ]] && cmp -s "$SITE/$file" "$STAGE/candidate/$file"; then
    echo "SIN CAMBIOS: $file"
  else
    echo "PREPARADO: $file"
  fi
done
if [[ "$MODE" == "--check" ]]; then
  echo "REVISIÓN COMPATIBLE. SITIO INTACTO. No se ejecutó SQL."
  exit 0
fi

echo "4. Respaldando seis archivos antes de publicar..."
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/revision-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${ALL[@]}"; do
  if [[ -f "$SITE/$file" ]]; then
    mkdir -p "$BACKUP/original/$(dirname "$file")"
    cp -p "$SITE/$file" "$BACKUP/original/$file"
    cmp -s "$SITE/$file" "$BACKUP/original/$file"
  fi
done
for file in "${OLD[@]}"; do
  cmp -s "$SITE/$file" "$BACKUP/original/$file" || {
    echo "ARCHIVO CAMBIADO: $file. SITIO INTACTO." >&2;exit 1;
  }
done
WRITING=1
for file in "${ALL[@]}"; do
  target="$STAGE/candidate/$file"
  if [[ -f "$SITE/$file" ]] && cmp -s "$SITE/$file" "$target"; then continue; fi
  mkdir -p "$SITE/$(dirname "$file")"
  temp="$SITE/$file.control-v2.$$"
  cp "$target" "$temp"
  if [[ -f "$SITE/$file" ]];then chmod --reference="$SITE/$file" "$temp";else chmod 0644 "$temp";fi
  mv -f "$temp" "$SITE/$file"
  echo "PUBLICADO: $file"
done
for file in "$CONT" "$ROLE" "$VIEW" "$SIDE"; do php -l "$SITE/$file" >/dev/null; done
cmp -s "$SITE/$CSS" "$STAGE/candidate/$CSS"
SUCCESS=1
echo "PUBLICACIÓN COMPLETA. RESPALDO: $BACKUP"
echo "Sin modificaciones a SQL, grabaciones, llamadas, extensiones, usuarios ni resultados."
echo "El permiso nuevo se registrará por el inicializador del CRM al ingresar en Roles o Telefonía."
