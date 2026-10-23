#!/usr/bin/env bash
# Despliegue compatible QR por aliado: JS, CSS, controlador, servicio y esquema aditivo.
# --check NO escribe en public_html ni modifica SQL; --apply respalda y verifica.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
  echo "Usa: bash publicar_qr_persistente_aliados_v6_hostinger.sh --check|--apply" >&2; exit 2;
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
WORK="$HOME/entregas-impe/qr-persistente-aliado-v6"
BACKUPS="$HOME/respaldos-impe-qr-persistente-aliados-v6"
REV="78a21d964fa08326812cf3f16df48f1b5c383aeb"
BASE="c7f0de765cbb55481af34266668b88344c073717"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE"
CONT="app/controllers/CrearConvocatoriaController.php"
SERV="app/services/ConvocatoriaQrDisenoService.php"
JS="public/javascript/convenios_borradores_v4.js"
CSS="public/css/convenios_borradores_v4.css"
SQL="database/migrations/2026_10_23_aliados_qr_diseno.sql"
MIG="tools/migrar_qr_aliados_v6.php"

expect_hash() {
  case "$1" in
    "$CONT") echo "d4254ac584787ede796922baa78e7491527996cb";;
    "$SERV") echo "8ff4746383bb6e9705aba7364c2a09b5c337059b";;
    "$JS") echo "29fc1bd8ba7e6f9cb81e4ee77a4c11a306113532";;
    "$CSS") echo "5ecfebd91b5fd8bacbaae081a1a3d043bff885ad";;
    "$SQL") echo "2aa53362b5be9cb5b68b02c5a7731555738fe1fd";;
    "$MIG") echo "58be5ed04c323363e165c01cc82acfc4ab4bdff6";;
    *) echo "No se reconoce el activo: $1" >&2; exit 1;;
  esac
}
for cmd in git php curl mktemp cp mv; do command -v "$cmd" >/dev/null || {
  echo "Falta $cmd; sitio intacto." >&2; exit 1;
}; done
[[ -f "$SITE/index.php" && -f "$SITE/$CONT" && -f "$SITE/$JS" && -f "$SITE/$CSS" ]] || {
  echo "No se encontró el editor V4 y su controlador. SITIO INTACTO." >&2; exit 1;
}
for file in "$CONT" "$JS" "$CSS" "$SERV" "$MIG" "$SQL"; do
  [[ ! -L "$SITE/$file" ]] || { echo "Ruta con enlace simbólico: $file. SITIO INTACTO." >&2; exit 1; }
done
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXX")"
FINISHED=0
WRITING=0
BACKUP=""
ALL=("$CONT" "$SERV" "$JS" "$CSS" "$SQL" "$MIG")
restore_if_error() {
  local exit_code=$?
  trap - EXIT
  if [[ "$WRITING" == "1" && "$FINISHED" == "0" ]]; then
    echo "ERROR: revertiendo exclusivamente los archivos de esta entrega..." >&2
    for file in "${ALL[@]}"; do
      if [[ -f "$BACKUP/existing/$file" ]]; then
        cp -p "$BACKUP/existing/$file" "$SITE/$file" ||
          echo "ATENCIÓN: restaura $file desde $BACKUP/existing/$file" >&2
      else
        rm -f "$SITE/$file"
      fi
    done
    echo "La tabla QR aditiva, si llegó a crearse, se conserva para no eliminar datos." >&2
  fi
  rm -rf "$STAGE"
  exit "$exit_code"
}
trap restore_if_error EXIT

echo "1. Descargando entrega V6 verificable..."
for file in "${ALL[@]}"; do
  mkdir -p "$STAGE/$(dirname "$file")"
  curl --retry 2 -fLsS "$RAW/$REV/$file" -o "$STAGE/$file"
  [[ "$(git hash-object "$STAGE/$file")" == "$(expect_hash "$file")" ]] || {
    echo "Huella distinta: $file. SITIO INTACTO." >&2; exit 1;
  }
done
curl --retry 2 -fLsS "$RAW/$BASE/$CONT" -o "$STAGE/controlador-base.php"
[[ "$(git hash-object "$STAGE/controlador-base.php")" == "07f7d0559f777dcc06979b4d89de8156589a74ff" ]] || {
  echo "Base del controlador no verificada. SITIO INTACTO." >&2; exit 1;
}

echo "2. Conciliando controlador PHP personalizado..."
# Nunca reemplaza sin revisión el controlador que Hostinger tenga personalizado.
if git merge-file -p "$SITE/$CONT" "$STAGE/controlador-base.php" "$STAGE/$CONT" > "$STAGE/controller-candidato.php"; then
  :
else
  echo "CONFLICTO: CrearConvocatoriaController.php requiere conciliación manual. SITIO INTACTO." >&2
  exit 1
fi
[[ "$(grep -c 'public function qrDiseno()' "$STAGE/controller-candidato.php")" == "1" ]] || {
  echo "CONFLICTO: endpoint QR no es único. SITIO INTACTO." >&2; exit 1;
}
php -l "$STAGE/controller-candidato.php"
php -l "$STAGE/$SERV"
php -l "$STAGE/$MIG"
if command -v node >/dev/null 2>&1; then
  node --check "$STAGE/$JS"
fi
grep -Fq 'IMPE_CONVENIOS_QR_COMPARTIDO_V5_20261023' "$STAGE/$JS"
grep -Fq 'data-v5qr-save' "$STAGE/$JS"
grep -Fq 'data-v5qr-save' "$STAGE/$CSS"
grep -Fq 'CREATE TABLE IF NOT EXISTS formulario_aliado_qr_diseno' "$STAGE/$SQL"
# El JS/CSS V4 actual debe ser exactamente el publicado o ya ser la nueva versión.
for spec in "$JS:7354879082e6220a0ded60b1963e69fc4294f8c2" \
            "$CSS:c8c04459e5a202ae6917beaa48884496ff911840"; do
  file="${spec%%:*}"; prior="${spec##*:}"
  [[ -f "$SITE/$file" ]] || { echo "Falta $file. SITIO INTACTO." >&2; exit 1; }
  current="$(git hash-object "$SITE/$file")"
  [[ "$current" == "$prior" || "$current" == "$(expect_hash "$file")" ]] || {
    echo "CONFLICTO: cambios posteriores en $file. SITIO INTACTO." >&2; exit 1;
  }
done
for file in "$SERV" "$SQL" "$MIG"; do
  if [[ -f "$SITE/$file" && "$(git hash-object "$SITE/$file")" != "$(expect_hash "$file")" ]]; then
    echo "CONFLICTO: archivo existente $file con otro contenido. SITIO INTACTO." >&2
    exit 1
  fi
done
# Verificar dependencias críticas de la vista que usa el QR, sin publicarlas.
for file in app/views/convocatorias/partials/editor_convenio_v4.php \
  app/views/convocatorias/partials/aliado_programas_modular.php \
  public/javascript/convocatorias_aliado_modular.js \
  public/vendor/qrcode-generator/qrcode.js \
  config/db_connection.php; do
  [[ -f "$SITE/$file" ]] || { echo "Falta dependencia: $file. SITIO INTACTO." >&2; exit 1; }
done
grep -Fq 'data-impe-qr-wrap' "$SITE/app/views/convocatorias/partials/aliado_programas_modular.php"
grep -Fq 'data-impe-crear-qr' "$SITE/app/views/convocatorias/partials/aliado_programas_modular.php"
grep -Fq 'data-borrador-v4' "$SITE/app/views/convocatorias/partials/editor_convenio_v4.php"

echo "3. Validando dependencias de base de datos sin escrituras..."
php -r '
require $argv[1];
$db=(new Database())->connect(false);
foreach (["formulario_aliados","formulario_aliado_registro","usuarios"] as $table) {
  $q=$db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $q->bind_param("s",$table);$q->execute();
  if (!$q->get_result()->num_rows) {fwrite(STDERR,"Falta tabla: $table\n");exit(1);}
}
echo "BASE COMPATIBLE: perfiles de Registro y usuarios.\n";
' "$SITE/config/db_connection.php"

for file in "${ALL[@]}"; do
  if [[ "$file" == "$CONT" ]]; then target="$STAGE/controller-candidato.php"; else target="$STAGE/$file"; fi
  if [[ -f "$SITE/$file" ]] && cmp -s "$SITE/$file" "$target"; then
    echo "SIN CAMBIOS: $file"
  else
    echo "PREPARADO: $file"
  fi
done

if [[ "$MODE" == "--check" ]]; then
  echo "REVISIÓN COMPATIBLE: 6 archivos; SQL nuevo separado, no ejecutado."
  echo "SITIO INTACTO"
  exit 0
fi
echo "4. Creando respaldo privado antes de tocar producción..."
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/qr-aliado-v6-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
for file in "${ALL[@]}"; do
  if [[ -f "$SITE/$file" ]]; then
    mkdir -p "$BACKUP/existing/$(dirname "$file")"
    cp -p "$SITE/$file" "$BACKUP/existing/$file"
    cmp -s "$BACKUP/existing/$file" "$SITE/$file"
  fi
done
# Evitar actualización entre revisión y publicación.
for file in "$CONT" "$JS" "$CSS"; do
  if [[ -f "$BACKUP/existing/$file" ]]; then
    cmp -s "$BACKUP/existing/$file" "$SITE/$file"
  fi
done
WRITING=1
# Crear primero servicio y migrador. El endpoint no se activa aún.
for file in "$SERV" "$SQL" "$MIG"; do
  if [[ -f "$SITE/$file" ]] && cmp -s "$SITE/$file" "$STAGE/$file"; then continue; fi
  mkdir -p "$SITE/$(dirname "$file")"
  tmp="$SITE/$file.v6.$$"
  cp "$STAGE/$file" "$tmp"
  if [[ -e "$SITE/$file" ]]; then chmod --reference="$SITE/$file" "$tmp"; else chmod 0644 "$tmp"; fi
  mv -f "$tmp" "$SITE/$file"
done

echo "5. Instalando exclusivamente el esquema QR aditivo..."
php "$SITE/$MIG" --check
php "$SITE/$MIG" --apply --backup-dir="$BACKUP"

echo "6. Activando controlador y editor visual..."
for file in "$CONT" "$JS" "$CSS"; do
  if [[ "$file" == "$CONT" ]]; then target="$STAGE/controller-candidato.php"; else target="$STAGE/$file"; fi
  if cmp -s "$SITE/$file" "$target"; then continue; fi
  tmp="$SITE/$file.v6.$$"
  cp "$target" "$tmp"
  chmod --reference="$SITE/$file" "$tmp"
  mv -f "$tmp" "$SITE/$file"
  echo "PUBLICADO: $file"
done
for file in "$SERV" "$SQL" "$MIG" "$JS" "$CSS"; do
  [[ "$(git hash-object "$SITE/$file")" == "$(expect_hash "$file")" ]]
done
cmp -s "$SITE/$CONT" "$STAGE/controller-candidato.php"
php -l "$SITE/$CONT" >/dev/null
php -l "$SITE/$SERV" >/dev/null
php "$SITE/$MIG" --check
FINISHED=1
echo "PUBLICACIÓN COMPLETA. Respaldo privado: $BACKUP"
echo "Sin modificaciones a enlaces, tokens, programas, borradores o convocatorias."
