#!/usr/bin/env bash
# Ventas: no pedir resultado comercial de intentos salientes sin conexión.
# --check conserva íntegro public_html y BD; --apply cambia SOLO 1 PHP de
# servicio, 1 vista PHP conciliada, y 1 observador JS, con respaldo y rollback.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
 echo "Uso: bash publicar_ventas_canceladas_sin_resultado_v2_hostinger.sh --check|--apply" >&2
 exit 2
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
WORK="$HOME/entregas-impe/ventas-canceladas-sin-resultado-v2"
BACKUPS="$HOME/respaldos-impe-ventas-canceladas-v2"
REPO="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE"
REV="3427d3e0c9c641b99228fcfacf745376ebafcf4e"
PATCH="tools/conciliar_vista_ventas_canceladas_v2.php"
PATCH_SHA="afe812537451b66831517080381bd3926f7aefa4"
SERVICE="app/services/TelefoniaVentasLlamadasService.php"
VIEW="app/views/telefonia/ventas_llamadas.php"
JS="public/javascript/telefonia_ventas_shell.js"
SHELL="public/javascript/telefonia_shell.js"
RESULTS="public/javascript/telefonia_ventas_resultados.js"
FILES=("$SERVICE" "$VIEW" "$JS")
sha_new(){
 case "$1" in
  "$SERVICE") echo ff92f36abfe6e1a006cb6ae599f807b5c9839350;;
  "$VIEW") echo 63edf6f35cb1cd2107264ebfeb09f52ad33f28cb;;
  "$JS") echo 024f6c0a9976d4b19f49a1d37e9870ae2b3c4634;;
  *) return 1;;
 esac
}
for util in curl git php cp cmp mv mktemp grep bash; do
 command -v "$util" >/dev/null || { echo "ERROR: falta $util. SITIO INTACTO." >&2;exit 1; }
done
for file in "${FILES[@]}" "$SHELL" "$RESULTS"; do
 [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] || {
   echo "ERROR: falta archivo o enlace simbólico: $file. SITIO INTACTO." >&2;exit 1;
 }
done
[[ "$(git hash-object "$SITE/$SHELL")" == "67fcac018a3557239329217f507484573e4524ff" ]] || {
 echo "CONFLICTO: shell telefónico cambió. NO PUBLICADO." >&2;exit 1;
}
[[ "$(git hash-object "$SITE/$RESULTS")" == "d6b92a3898ef2c01ee2abfd7a28c82c5dc277654" ]] || {
 echo "CONFLICTO: resultados comerciales JS cambiaron. NO PUBLICADO." >&2;exit 1;
}
for config in "$SERVICE" "$JS"; do
 expected=''
 case "$config" in
  "$SERVICE") expected='28ad16380c89afc548e1812cfe4f8855d09b25a6';;
  "$JS") expected='44f22188d133565645adf8e6842f0bdd299beb6f';;
 esac
 live="$(git hash-object "$SITE/$config")"
 [[ "$live" == "$expected" || "$live" == "$(sha_new "$config")" ]] || {
  echo "CONFLICTO: $(basename "$config") tiene cambios no verificados ($live). SITIO INTACTO." >&2
  exit 1
 }
done
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXXXX")"
BACKUP=''
WRITING=0
DONE=0
cleanup(){
 local status=$?
 trap - EXIT
 if [[ "$WRITING" == 1 && "$DONE" != 1 ]];then
  echo "ERROR: revirtiendo los tres archivos originales..." >&2
  for file in "${FILES[@]}";do
   if [[ -f "$BACKUP/original/$file" ]];then
    cp -p "$BACKUP/original/$file" "$SITE/$file" ||
     echo "RESTAURAR MANUALMENTE: $BACKUP/original/$file" >&2
   fi
  done
  echo "RESPALDO: $BACKUP" >&2
 fi
 rm -rf "$STAGE"
 exit "$status"
}
trap cleanup EXIT
echo "1. Descargando código verificado..."
for file in "${FILES[@]}";do
 mkdir -p "$STAGE/target/$(dirname "$file")" "$STAGE/candidate/$(dirname "$file")"
 curl -fLsS --retry 2 "$REPO/$REV/$file" -o "$STAGE/target/$file"
 [[ "$(git hash-object "$STAGE/target/$file")" == "$(sha_new "$file")" ]] || {
  echo "ERROR: fuente no coincide con versión autorizada ($file). SITIO INTACTO." >&2;exit 1;
 }
done
echo "2. Corrigiendo exclusivamente la condición de Registrar resultado en la vista personalizada..."
# No usar merge de toda la vista: Hostinger conserva decenas de estilos y
# personalizaciones que producirían un conflicto de tres vías innecesario.
curl -fLsS --retry 2 "$REPO/$REV/$PATCH" -o "$STAGE/conciliar_vista.php"
[[ "$(git hash-object "$STAGE/conciliar_vista.php")" == "$PATCH_SHA" ]] || {
 echo "ERROR: conciliador no verificado. SITIO INTACTO." >&2;exit 1;
}
php -l "$STAGE/conciliar_vista.php"
php "$STAGE/conciliar_vista.php" "$SITE/$VIEW" "$STAGE/candidate/$VIEW"
for file in "$SERVICE" "$JS";do
 cp "$STAGE/target/$file" "$STAGE/candidate/$file"
done
echo "3. Validando reglas y sintaxis antes de publicar..."
php -l "$STAGE/candidate/$SERVICE"
php -l "$STAGE/candidate/$VIEW"
grep -Fq 'TelefoniaVentasLlamadasService::resultadoCapturable($call)' "$STAGE/candidate/$VIEW"
[[ "$(grep -Fc 'TelefoniaVentasLlamadasService::resultadoCapturable($call)' "$STAGE/candidate/$VIEW")" == 1 ]] || {
 echo "ERROR: condición de resultado no única. SITIO INTACTO." >&2;exit 1;
}
if grep -Fq "if(\$ref!==''&&\$call['finalizada']):" "$STAGE/candidate/$VIEW";then
 echo "ERROR: condición antigua todavía presente. SITIO INTACTO." >&2;exit 1;
fi
grep -Fq 'IMPE_VENTAS_PANEL_PBX_DISCRETO_V1_20261027' "$STAGE/candidate/$JS"
grep -Fq 'Date.now()-waitStarted<8500' "$STAGE/candidate/$JS"
grep -Fq "public static function resultadoCapturable" "$STAGE/candidate/$SERVICE"
grep -Fq "if ((\$call['direccion'] ?? '') === 'Saliente')" "$STAGE/candidate/$SERVICE"
if command -v node >/dev/null 2>&1;then
 node --check "$STAGE/candidate/$JS"
elif command -v nodejs >/dev/null 2>&1;then
 nodejs --check "$STAGE/candidate/$JS"
else
 echo "JS: integridad SHA comprobada y sintaxis validada en CI; Node.js no requerido."
fi
# Pruebas PHP aisladas con archivo real y vista conciliada, sin cargar BD.
mkdir -p "$STAGE/tests" "$STAGE/app/services" "$STAGE/app/views/telefonia"
cp "$STAGE/candidate/$SERVICE" "$STAGE/app/services/TelefoniaVentasLlamadasService.php"
cp "$STAGE/candidate/$VIEW" "$STAGE/app/views/telefonia/ventas_llamadas.php"
curl -fLsS --retry 2 "$REPO/$REV/tests/telefonia_ventas_canceladas_no_capturables_smoke.php" \
 -o "$STAGE/tests/telefonia_ventas_canceladas_no_capturables_smoke.php"
[[ "$(git hash-object "$STAGE/tests/telefonia_ventas_canceladas_no_capturables_smoke.php")" == "af670ee006b9bef01bc6989ff54a2a5d9c1a43dd" ]] || {
 echo "ERROR: prueba PHP inesperada. SITIO INTACTO." >&2; exit 1;
}
php "$STAGE/tests/telefonia_ventas_canceladas_no_capturables_smoke.php"
echo "COMPATIBLE: canceladas fuera de pendientes, panel de Ventas y aviso breve."
for file in "${FILES[@]}";do
 if cmp -s "$SITE/$file" "$STAGE/candidate/$file";then
  echo "SIN CAMBIOS: $file"
 else
  echo "PREPARADO: $file"
 fi
done
if [[ "$MODE" == "--check" ]];then
 echo "REVISIÓN COMPATIBLE. SITIO INTACTO. Sin SQL de escritura."
 exit 0
fi
echo "4. Guardando respaldo y revisando modificaciones concurrentes..."
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/lote-$(date +%Y%m%d-%H%M%S)-XXXXXXXX")"
for file in "${FILES[@]}";do
 mkdir -p "$BACKUP/original/$(dirname "$file")"
 cp -p "$SITE/$file" "$BACKUP/original/$file"
 cmp -s "$SITE/$file" "$BACKUP/original/$file"
done
WRITING=1
for file in "${FILES[@]}";do
 cmp -s "$SITE/$file" "$BACKUP/original/$file" || {
  echo "CONFLICTO: $file cambió tras generar el candidato." >&2;exit 1;
 }
 if cmp -s "$SITE/$file" "$STAGE/candidate/$file";then continue;fi
 tmp="$SITE/$file.impe-ventas-v2.$$"
 cp "$STAGE/candidate/$file" "$tmp"
 chmod --reference="$SITE/$file" "$tmp"
 mv -f "$tmp" "$SITE/$file"
 echo "PUBLICADO: $file"
done
php -l "$SITE/$SERVICE" >/dev/null
php -l "$SITE/$VIEW" >/dev/null
[[ "$(git hash-object "$SITE/$JS")" == "$(sha_new "$JS")" ]]
[[ "$(git hash-object "$SITE/$SERVICE")" == "$(sha_new "$SERVICE")" ]]
DONE=1
echo "PUBLICACIÓN COMPLETA. RESPALDO: $BACKUP"
echo "Sin SQL, borrados, cambios de audio o cambios en telefonía de Marketing/Analistas."
echo "Recarga forzada del CRM antes de comprobar con llamadas de prueba."
