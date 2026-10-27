#!/usr/bin/env bash
# Corrección de atribución de llamadas de Ventas sobre el EXACTO servicio de producción.
# --check no altera public_html. --apply afecta únicamente 1 archivo PHP con respaldo.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || { echo "Usa --check o --apply" >&2; exit 2; }
SITE="$HOME/domains/fundacionrededumex.org/public_html"
FILE="app/services/TelefoniaVentasLlamadasService.php"
DIR="$HOME/entregas-impe/ventas-ext151-conciliacion-v2"
BACKUPS="$HOME/respaldos-impe-ventas-ext151-conciliacion-v2"
REV="3a176946a0b3ba55f3729619bcb191100f76c854"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE"
BEFORE="4ef800bf9f7044dfeb1dbe200ff4666bb2212a10"
AFTER="28ad16380c89afc548e1812cfe4f8855d09b25a6"
for tool in bash git php curl mktemp cp cmp mv grep; do
  command -v "$tool" >/dev/null || { echo "ERROR: falta $tool. SITIO INTACTO." >&2; exit 1; }
done
[[ -f "$SITE/$FILE" && ! -L "$SITE/$FILE" ]] || {
  echo "ERROR: servicio de Ventas no encontrado o es un enlace simbólico. SITIO INTACTO." >&2; exit 1;
}
for rel in "TelefoniaLimpiezaGuard.php" "TelefoniaMarcacionesVentasService.php" "TelefoniaPbxSegmentosService.php"; do
  [[ -f "$SITE/app/services/$rel" ]] || {
    echo "ERROR: falta dependencia conservada $rel. SITIO INTACTO." >&2; exit 1;
  }
done
mkdir -p -m 700 "$DIR"
STAGE="$(mktemp -d "$DIR/stage-XXXXXXXX")"
BACKUP=''
WRITING=0
DONE=0
cleanup() {
  local status=$?
  trap - EXIT
  if [[ "$WRITING" == 1 && "$DONE" != 1 ]]; then
    echo "PUBLICACIÓN FALLIDA: intentando restaurar servicio original..." >&2
    cp -p "$BACKUP/service-original.php" "$SITE/$FILE" ||
      echo "ATENCIÓN: restaura manualmente $BACKUP/service-original.php" >&2
  fi
  rm -rf "$STAGE"
  exit "$status"
}
trap cleanup EXIT
echo "1. Descargando versión conciliada con personalizaciones de Hostinger..."
curl -fLsS --retry 2 "$RAW/$REV/$FILE" -o "$STAGE/new.php"
[[ "$(git hash-object "$STAGE/new.php")" == "$AFTER" ]] || {
  echo "ERROR: archivo descargado no coincide con versión verificable. SITIO INTACTO." >&2; exit 1;
}
echo "2. Verificando servicio real y diferencias..."
CURRENT="$(git hash-object "$SITE/$FILE")"
if [[ "$CURRENT" != "$BEFORE" && "$CURRENT" != "$AFTER" ]]; then
  echo "CONFLICTO: se detectaron más cambios en el servicio ($CURRENT)." >&2
  echo "No se publicará ni forzará ningún archivo. SITIO INTACTO." >&2
  exit 1
fi
php -l "$STAGE/new.php"
grep -Fq "require_once __DIR__.'/TelefoniaLimpiezaGuard.php';" "$STAGE/new.php"
grep -Fq "TelefoniaLimpiezaGuard::bloqueada" "$STAGE/new.php"
grep -Fq "TelefoniaLimpiezaGuard::condicion" "$STAGE/new.php"
grep -Fq "public static function evidenciaAudio" "$STAGE/new.php"
grep -Fq "public static function resultadoCapturable" "$STAGE/new.php"
grep -Fq "public static function asignacionLocal" "$STAGE/new.php"
[[ "$(grep -Fc "CONVERT_TZ(GREATEST(t.created_at,t.updated_at),'+00:00','-06:00')" "$STAGE/new.php")" == 2 ]] || {
  echo "ERROR: ambas rutas de conciliación no están corregidas. SITIO INTACTO." >&2;exit 1;
}
php -r '
$d=DateTimeImmutable::createFromFormat("!Y-m-d H:i:s","2026-10-27 15:29:57",new DateTimeZone("UTC"));
if (!$d || $d->setTimezone(new DateTimeZone("America/Mexico_City"))->format("Y-m-d H:i:s")!=="2026-10-27 09:29:57") exit(1);
echo "PASS: conversión UTC a México para la extensión 151.\n";
'
echo "COMPATIBLE: historial, protección de limpieza, transferencias y grabaciones conservadas."
if [[ "$MODE" == "--check" ]]; then
  echo "REVISIÓN COMPATIBLE. SITIO INTACTO. Sin SQL de escritura."
  exit 0
fi
if [[ "$CURRENT" == "$AFTER" ]]; then
  echo "YA APLICADO. Sitio sin cambios."
  exit 0
fi
echo "3. Respaldando antes de aplicar..."
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/backup-$(date +%Y%m%d-%H%M%S)-XXXXXXXX")"
cp -p "$SITE/$FILE" "$BACKUP/service-original.php"
cmp -s "$SITE/$FILE" "$BACKUP/service-original.php" || {
  echo "CONFLICTO: cambió el servicio antes de publicar. SITIO INTACTO." >&2; exit 1;
}
WRITING=1
TEMP="$SITE/$FILE.ext151-v2.$$"
cp "$STAGE/new.php" "$TEMP"
chmod --reference="$SITE/$FILE" "$TEMP"
cmp -s "$SITE/$FILE" "$BACKUP/service-original.php" || {
  rm -f "$TEMP"
  echo "CONFLICTO: cambio concurrente detectado. SITIO INTACTO." >&2; exit 1;
}
mv -f "$TEMP" "$SITE/$FILE"
php -l "$SITE/$FILE" >/dev/null
[[ "$(git hash-object "$SITE/$FILE")" == "$AFTER" ]]
DONE=1
echo "PUBLICACIÓN TERMINADA. RESPALDO: $BACKUP/service-original.php"
echo "Solo se actualizó el servicio de atribución comercial; sin SQL directo ni cambios en JS, audios o resultados."
echo "IMPORTANTE: prueba Mis llamadas con la extensión 151 y vuelve a verificar los pendientes."
