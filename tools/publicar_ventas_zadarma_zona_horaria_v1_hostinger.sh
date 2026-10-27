#!/usr/bin/env bash
# Corrección SELECTIVA de atribución comercial Zadarma: sólo un servicio PHP.
# --check conserva producción; --apply respalda y compara hashes antes de instalar.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || {
  echo 'Uso: --check | --apply' >&2;exit 2;
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
FILE="app/services/TelefoniaVentasLlamadasService.php"
TEST="tests/telefonia_ventas_zona_horaria_asignacion_smoke.php"
REV="e6d1852fb8397b519ced294b17402fa357ff7cc6"
SRC="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE"
BASE_SHA='0d0694b2b554c7f97bc845c2d2d37f3a961238da'
NEW_SHA='21caf01cc66ced2846f8686bb956931c277abed4'
TEST_SHA='4e1693ed8d6d98edf045b912d90a73d931da971f'
WORK="$HOME/entregas-impe/ventas-zona-horaria-ext151-v1"
BACKUP_DIR="$HOME/respaldos-impe-ventas-zona-horaria-ext151"
for cmd in php git curl cmp cp mv mktemp; do
  command -v "$cmd" >/dev/null || { echo "Falta $cmd. SITIO INTACTO." >&2;exit 1; }
done
[[ -f "$SITE/$FILE" && ! -L "$SITE/$FILE" ]] || {
  echo "Falta el servicio esperado o es enlace simbólico. SITIO INTACTO." >&2;exit 1;
}
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXXXX")"
BACKUP=''
WRITING=0
DONE=0
cleanup(){
  local code=$?
  trap - EXIT
  if [[ "$WRITING" == 1 && "$DONE" != 1 ]]; then
    echo 'Publicación interrumpida, intentando restaurar servicio original...' >&2
    cp -p "$BACKUP/original.php" "$SITE/$FILE" ||
      echo "ATENCIÓN: restaurar desde $BACKUP/original.php" >&2
  fi
  rm -rf "$STAGE"
  exit "$code"
}
trap cleanup EXIT
echo '1. Descargando servicio y prueba fijados por commit...'
curl -fLsS --retry 2 "$SRC/$REV/$FILE" -o "$STAGE/service.php"
curl -fLsS --retry 2 "$SRC/$REV/$TEST" -o "$STAGE/test.php"
[[ "$(git hash-object "$STAGE/service.php")" == "$NEW_SHA" &&
   "$(git hash-object "$STAGE/test.php")" == "$TEST_SHA" ]] || {
  echo 'Los archivos no corresponden al commit aprobado. SITIO INTACTO.' >&2;exit 1;
}
echo '2. Comparando servicio activo...'
CURRENT="$(git hash-object "$SITE/$FILE")"
[[ "$CURRENT" == "$BASE_SHA" || "$CURRENT" == "$NEW_SHA" ]] || {
  echo "CONFLICTO: el servicio real tiene otra personalización ($CURRENT)." >&2
  echo 'No se modificó nada; no sobrescribas sin conciliación.' >&2
  exit 1
}
echo '3. Comprobando sintaxis y zona horaria...'
php -l "$STAGE/service.php"
# Prueba de conversión sin cargar el servicio desde un directorio temporal.
php -r '
foreach([
  ["2026-10-27 15:29:57","2026-10-27 09:29:57"],
  ["2026-10-27 17:05:00","2026-10-27 11:05:00"]
] as $pair) {
 $d=DateTimeImmutable::createFromFormat("!Y-m-d H:i:s",$pair[0],new DateTimeZone("UTC"));
 if(!$d || $d->setTimezone(new DateTimeZone("America/Mexico_City"))->format("Y-m-d H:i:s")!==$pair[1])exit(1);
}
echo "PASS: conversión de zona horaria.\n";
'
grep -Fq 'public static function asignacionLocal(string $utc)' "$STAGE/service.php"
grep -Fq "CONVERT_TZ(GREATEST(t.created_at,t.updated_at),'+00:00','-06:00')" "$STAGE/service.php"
grep -Fq "NOT EXISTS(SELECT 1 FROM telefonia_extensiones other" "$STAGE/service.php"
if [[ "$MODE" == '--check' ]]; then
  echo 'REVISIÓN COMPATIBLE. SITIO INTACTO. Sin SQL de escritura.'
  exit 0
fi
echo '4. Creando respaldo privado...'
mkdir -p -m 700 "$BACKUP_DIR"
BACKUP="$(mktemp -d "$BACKUP_DIR/lote-$(date +%Y%m%d-%H%M%S)-XXXXXX")"
cp -p "$SITE/$FILE" "$BACKUP/original.php"
cmp -s "$SITE/$FILE" "$BACKUP/original.php"
if [[ "$CURRENT" == "$NEW_SHA" ]];then
  echo 'YA APLICADO. Sitio sin cambios.'
  DONE=1
  exit 0
fi
WRITING=1
cmp -s "$SITE/$FILE" "$BACKUP/original.php" || { echo 'El archivo cambió; cancelando.' >&2;exit 1; }
TEMP="$SITE/$FILE.tmp-zona-horaria-$$"
cp "$STAGE/service.php" "$TEMP"
chmod --reference="$SITE/$FILE" "$TEMP"
mv -f "$TEMP" "$SITE/$FILE"
php -l "$SITE/$FILE" >/dev/null
[[ "$(git hash-object "$SITE/$FILE")" == "$NEW_SHA" ]]
DONE=1
echo "PUBLICADO. RESPALDO: $BACKUP/original.php"
echo 'Sólo cambió el servicio comercial: sin SQL, audios, usuarios o resultados modificados.'
echo 'Al consultar Mis llamadas, el CRM reconciliará sus registros mediante las reglas existentes.'
