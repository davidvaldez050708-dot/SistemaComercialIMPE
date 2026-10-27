#!/usr/bin/env bash
# Unifica Supervisión del equipo (tarjetas) y Control de llamadas (tabla).
# Sólo cuatro archivos. Verifica la versión EXACTA de Hostinger antes de escribir.
# --check = lectura de producción; --apply = respaldo + publicación comprobada.
set -Eeuo pipefail
umask 077
MODE="${1:---check}"
[[ "$MODE" == "--check" || "$MODE" == "--apply" ]] || {
 echo 'Uso: bash publicar_supervision_estados_unificados_v1_hostinger.sh --check|--apply' >&2; exit 2;
}
SITE="$HOME/domains/fundacionrededumex.org/public_html"
WORK="$HOME/entregas-impe/supervision-estados-unificados-v1"
BACKUPS="$HOME/respaldos-impe-supervision-estados-unificados-v1"
RAW="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE"
REV="20e13443426d74e9ad063549479d1c408e6e7bde"
CONTROL="app/services/TelefoniaControlService.php"
REPO="app/services/SupervisionLlamadasRepository.php"
CARD="app/views/telefonia/supervision_llamadas.php"
INDEX="app/views/telefonia/index.php"
HELPER="tools/conciliar_telefonia_supervision_vista_v1.php"
FILES=("$CONTROL" "$REPO" "$CARD" "$INDEX")
old_hash(){
 case "$1" in
  "$CONTROL") echo 79709862b7ab1912f1c474312ac76fcd83552a2c;;
  "$REPO") echo b256c011564ff44e07a91ce7172aeec0356b75c5;;
  "$CARD") echo 259136d437be870febb95d26a76e15e8b82925bb;;
  "$INDEX") echo 713fed51faddadf27a8f8409936dbeca93769b93;;
  *)return 1;;
 esac
}
new_hash(){
 case "$1" in
  "$CONTROL") echo 0e1ff9b921547d315456c7a9bb427f75f10d5e18;;
  "$REPO") echo cb4518947d093ad55581405f46a9ab9b377ec172;;
  "$CARD") echo d36c7dad091a439faf0906454817993d74da5b5a;;
  *)return 1;;
 esac
}
DEPENDENCIES=(
 "app/controllers/SupervisionLlamadasController.php:4a7b46ab8ef82ff0e8c322a7d1a2223a450e72f0"
 "app/controllers/TelefoniaController.php:5b1a3af8c55dd57bed1df41441c1ba99774bfc31"
 "app/services/TelefoniaVentasLlamadasService.php:ff92f36abfe6e1a006cb6ae599f807b5c9839350"
 "public/javascript/supervision_llamadas.js:7b76d9cfc19e5137b80618c1764b7c72dc21d019"
)
for app in php git curl bash cmp cp mv mktemp grep;do
 command -v "$app" >/dev/null || { echo "ERROR: falta $app. SITIO INTACTO." >&2;exit 1; }
done
for file in "${FILES[@]}";do
 [[ -f "$SITE/$file" && ! -L "$SITE/$file" ]] || {
  echo "ERROR: archivo ausente o enlace simbólico: $file. SITIO INTACTO." >&2;exit 1;
 }
done
for dep in "${DEPENDENCIES[@]}";do
 file="${dep%:*}"
 sha="${dep##*:}"
 [[ -f "$SITE/$file" && "$(git hash-object "$SITE/$file")" == "$sha" ]] || {
  echo "CONFLICTO: dependencia personalizada cambió: $file. SITIO INTACTO." >&2;exit 1;
 }
done
for file in "$CONTROL" "$REPO" "$CARD";do
 live="$(git hash-object "$SITE/$file")"
 [[ "$live" == "$(old_hash "$file")" || "$live" == "$(new_hash "$file")" ]] || {
  echo "CONFLICTO: $file ($live). Sin sobrescritura. SITIO INTACTO." >&2;exit 1;
 }
done
index_live="$(git hash-object "$SITE/$INDEX")"
INDEX_PATCHED=0
if [[ "$index_live" != "$(old_hash "$INDEX")" ]];then
 if [[ "$(grep -Fc "\$call['estado_pbx']" "$SITE/$INDEX")" == 1 &&
       "$(grep -Fc "\$call['resultado_estado']" "$SITE/$INDEX")" == 1 ]];then
  INDEX_PATCHED=1
 else
  echo "CONFLICTO: vista general personalizada no reconocida ($index_live). SITIO INTACTO." >&2
  exit 1
 fi
fi
mkdir -p -m 700 "$WORK"
STAGE="$(mktemp -d "$WORK/stage-XXXXXXXX")"
BACKUP=""
WRITING=0
COMPLETE=0
cleanup() {
 local status=$?
 trap - EXIT
 if [[ "$WRITING" == 1 && "$COMPLETE" != 1 ]];then
  echo "ERROR de publicación: restaurando los archivos respaldados..." >&2
  for file in "${FILES[@]}";do
   if [[ -f "$BACKUP/original/$file" ]];then
    cp -p "$BACKUP/original/$file" "$SITE/$file" ||
       echo "RESTAURACIÓN MANUAL: $BACKUP/original/$file" >&2
   fi
  done
  echo "RESPALDO: $BACKUP" >&2
 fi
 rm -rf "$STAGE"
 exit "$status"
}
trap cleanup EXIT
echo '1. Obteniendo la entrega verificada por huellas...'
for file in "$CONTROL" "$REPO" "$CARD";do
 mkdir -p "$STAGE/candidate/$(dirname "$file")"
 curl -fLsS --retry 2 "$RAW/$REV/$file" -o "$STAGE/candidate/$file"
 [[ "$(git hash-object "$STAGE/candidate/$file")" == "$(new_hash "$file")" ]] || {
  echo "ERROR: entrega no verificable para $file. SITIO INTACTO." >&2;exit 1;
 }
done
mkdir -p "$STAGE/tools" "$STAGE/candidate/$(dirname "$INDEX")" "$STAGE/tests"
curl -fLsS --retry 2 "$RAW/$REV/$HELPER" -o "$STAGE/$HELPER"
[[ "$(git hash-object "$STAGE/$HELPER")" == '951f94e54d680884be5b704a2f1e31058526dfc2' ]] || {
 echo 'ERROR: conciliador de vista general no verificado. SITIO INTACTO.' >&2;exit 1;
}
echo '2. Adaptando solamente las etiquetas de la vista personalizada...'
php -l "$STAGE/$HELPER"
if [[ "$INDEX_PATCHED" == 1 ]];then
 cp "$SITE/$INDEX" "$STAGE/candidate/$INDEX"
else
 php "$STAGE/$HELPER" "$SITE/$INDEX" "$STAGE/candidate/$INDEX"
fi
echo '3. Verificando los cuatro candidatos y sus reglas...'
for file in "${FILES[@]}";do
 php -l "$STAGE/candidate/$file"
done
grep -Fq "public static function resultadoAuditable" "$STAGE/candidate/$REPO"
grep -Fq "TelefoniaVentasLlamadasService::hechos" "$STAGE/candidate/$REPO"
grep -Fq "TelefoniaLimpiezaGuard::condicion" "$STAGE/candidate/$CONTROL"
grep -Fq 'IMPE_SUPERVISION_TARJETAS_PBX_ESTADO_V1' "$STAGE/candidate/$CARD"
grep -Fq 'if($resultadoGuardado)' "$STAGE/candidate/$CARD"
grep -Fq "\$call['estado_pbx']" "$STAGE/candidate/$INDEX"
grep -Fq "\$call['resultado_estado']" "$STAGE/candidate/$INDEX"
grep -Fq 'data-supervision-audio' "$STAGE/candidate/$CARD"
grep -Fq 'data-telephony-control-history-rows' "$STAGE/candidate/$INDEX"
# Las regresiones aisladas usan exactamente el PHP candidato en staging.
for test in telefonia_control_supervision_estado_pbx_smoke.php telefonia_supervision_repositorio_resultados_smoke.php;do
 curl -fLsS --retry 2 "$RAW/$REV/tests/$test" -o "$STAGE/tests/$test"
done
[[ "$(git hash-object "$STAGE/tests/telefonia_control_supervision_estado_pbx_smoke.php")" == "df916059c32a6296200e6de8731a72f892eb8a80" ]]
[[ "$(git hash-object "$STAGE/tests/telefonia_supervision_repositorio_resultados_smoke.php")" == "9128be6689c63522fba73a01a1f19273ab56efa6" ]]
php "$STAGE/tests/telefonia_control_supervision_estado_pbx_smoke.php"
php "$STAGE/tests/telefonia_supervision_repositorio_resultados_smoke.php"
echo 'COMPATIBLE: Supervisión en tabla y tarjetas; identidades, permisos, audio y diseño conservados.'
for file in "${FILES[@]}";do
 if cmp -s "$SITE/$file" "$STAGE/candidate/$file";then
  echo "SIN CAMBIOS: $file"
 else
  echo "PREPARADO: $file"
 fi
done
if [[ "$MODE" == "--check" ]];then
 echo 'REVISIÓN COMPATIBLE. SITIO INTACTO. No se ejecutó SQL de escritura.'
 exit 0
fi
echo '4. Respaldando los cuatro archivos antes de publicar...'
mkdir -p -m 700 "$BACKUPS"
BACKUP="$(mktemp -d "$BACKUPS/lote-$(date +%Y%m%d-%H%M%S)-XXXXXXXX")"
for file in "${FILES[@]}";do
 mkdir -p "$BACKUP/original/$(dirname "$file")"
 cp -p "$SITE/$file" "$BACKUP/original/$file"
 cmp -s "$SITE/$file" "$BACKUP/original/$file"
done
# Detectar cambios simultáneos ANTES de reemplazar cualquiera.
for file in "${FILES[@]}";do
 cmp -s "$SITE/$file" "$BACKUP/original/$file" || {
  echo "CONFLICTO: cambió $file mientras se preparaba la entrega. SITIO INTACTO." >&2;exit 1;
 }
done
WRITING=1
for file in "${FILES[@]}";do
 if cmp -s "$SITE/$file" "$STAGE/candidate/$file";then continue;fi
 tmp="$SITE/$file.impe-supervision-v1.$$"
 cp "$STAGE/candidate/$file" "$tmp"
 chmod --reference="$SITE/$file" "$tmp"
 mv -f "$tmp" "$SITE/$file"
 echo "PUBLICADO: $file"
done
for file in "${FILES[@]}";do
 php -l "$SITE/$file" >/dev/null
 cmp -s "$SITE/$file" "$STAGE/candidate/$file"
done
COMPLETE=1
echo "PUBLICACIÓN COMPLETA. RESPALDO: $BACKUP"
echo 'No se modificó SQL, llamadas, resultados, permisos, WebRTC ni grabaciones.'
echo 'Actualizar el navegador con Ctrl+Shift+R para comprobar ambas vistas.'
