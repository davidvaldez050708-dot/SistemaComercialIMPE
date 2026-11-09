#!/usr/bin/env bash
# Filtros e imagen de convocatoria. Sin cambios de transporte, SQL ni credenciales.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='envio-convocatoria-v1-23480ad03ea0c0b1'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/services/CorreoConvocatoriaComposicion.php'
DIAGNOSTIC='tools/diagnosticar_hostinger_comercial.php'
FILES=("tools/diagnosticar_envio_convocatoria.php" "app/services/CorreoConvocatoriaComposicion.php")
PATCH_FILES=("app/services/CorreoConvocatoriaComposicion.php")
declare -A ALLOWED=()
for f in "${FILES[@]}";do ALLOWED["$f"]=1;done
fail(){ echo "ERROR: $*" >&2; exit 1; }
digest(){ sha256sum "$1" | cut -d' ' -f1; }
no_links(){ local part="$1"; while [[ "$part" != / && "$part" != . ]]; do
 [[ ! -L "$part" ]] || fail "Enlace no permitido: $1"
 case "$part" in */*) part="${part%/*}";[[ -n "$part" ]] || part=/;; *) part=.;; esac
done; }
for tool in php git base64 sha256sum cut mktemp realpath cp mv mkdir rm rmdir dirname cat date chmod; do command -v "$tool" >/dev/null || fail "Falta $tool";done
no_links "$LIVE"
[[ -d "$LIVE/app/services" && -f "$LIVE/$SERVICE" && -f "$LIVE/index.php" ]] || fail 'Raíz del CRM no encontrada.'
LIVE="$(cd "$LIVE" && pwd -P)"
[[ -d "$LIVE/app/controllers" && -d "$LIVE/app/models" ]] || fail 'Falta la estructura de controladores/modelos del CRM.'
no_links "$BACKUPS";BACKUPS="$(realpath -m "$BACKUPS")"
[[ "$BACKUPS" != / && "$BACKUPS/" != "$LIVE/"* && "$LIVE/" != "$BACKUPS/"* ]] || fail 'El respaldo debe estar fuera del sitio y sus antecesores.'
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
[[ "$SCRATCH/" != "$LIVE/"* ]] || fail 'Los temporales deben estar fuera del sitio.'
TMP="$(mktemp -d "$SCRATCH/impe-correos.XXXXXX")";touch "$TMP/.owned"
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE
export GIT_CEILING_DIRECTORIES="$TMP"
for f in "${FILES[@]}";do mkdir -p "$TMP/stage/$(dirname "$f")" "$TMP/original/$(dirname "$f")";done
LOCK="$(dirname "$LIVE")/.impe-correos-deploy.lock";LOCKED=0;WRITING=0;CREATED_TOOLS=0
declare -A BEFORE=() AFTER=() SEEN=()
declare -a CHANGED=() WRITTEN=()
finish(){ local code=$? f i;trap - EXIT INT TERM
 if [[ "$WRITING" == 1 && "$code" != 0 ]];then
  for ((i=${#WRITTEN[@]}-1;i>=0;i--));do f="${WRITTEN[$i]}";
   if [[ "${BEFORE[$f]}" == MISSING ]];then rm -f -- "$LIVE/$f";else cp -p -- "$TMP/original/$f" "$LIVE/$f" || echo "REVISAR RESTAURACIÓN: $f" >&2;fi
  done
  echo 'Publicación interrumpida: se restauraron los archivos alcanzados.' >&2
 fi
 for f in "${FILES[@]}";do rm -f -- "$LIVE/$f.impe-new-$$";done
 if [[ "$code" != 0 && "$CREATED_TOOLS" == 1 ]];then rmdir -- "$LIVE/tools" 2>/dev/null || :;fi
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 if [[ "$TMP" == "$SCRATCH"/impe-correos.* && -f "$TMP/.owned" ]];then rm -rf -- "$TMP";fi
 exit "$code"
}
trap finish EXIT;trap 'exit 130' INT;trap 'exit 143' TERM
guard(){ no_links "$LIVE/$1";[[ ! -e "$LIVE/$1" || -f "$LIVE/$1" ]] || fail "No es archivo: $1"; }
unchanged(){ local f="$1";guard "$f";if [[ "${BEFORE[$f]}" == MISSING ]];then [[ ! -e "$LIVE/$f" ]] || fail "Apareció durante la revisión: $f";else [[ -f "$LIVE/$f" && "$(digest "$LIVE/$f")" == "${BEFORE[$f]}" ]] || fail "Cambió durante la revisión: $f";fi; }
if [[ "$MODE" != --check ]];then no_links "$LOCK";mkdir "$LOCK" 2>/dev/null || fail 'Hay otra publicación en curso.';LOCKED=1;fi
for f in "${FILES[@]}";do
 guard "$f";if [[ -f "$LIVE/$f" ]];then BEFORE["$f"]="$(digest "$LIVE/$f")";cp -p -- "$LIVE/$f" "$TMP/original/$f";else BEFORE["$f"]=MISSING;fi
done
if [[ "$MODE" == --restore ]];then
 no_links "$2";RESTORE="$(realpath -m "$2")"
 [[ "$RESTORE/" == "$BACKUPS/"* && "$RESTORE" != "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
 no_links "$RESTORE/version.txt";no_links "$RESTORE/archivos.tsv";no_links "$RESTORE/tools-directory-created"
 [[ ! -e "$RESTORE/tools-directory-created" || ( -f "$RESTORE/tools-directory-created" && ! -s "$RESTORE/tools-directory-created" ) ]] || fail "Marca de directorio inválida."
 [[ -f "$RESTORE/version.txt" && "$(cat "$RESTORE/version.txt")" == "$VERSION" && -f "$RESTORE/archivos.tsv" ]] || fail 'Respaldo de otra entrega o incompleto.'
 while IFS=$'\t' read -r f old installed;do
  [[ ( -n "${ALLOWED[$f]:-}" ) && -z "${SEEN[$f]:-}" && "$installed" =~ ^[a-f0-9]{64}$ && ( "$old" == MISSING || "$old" =~ ^[a-f0-9]{64}$ ) ]] || fail 'Registro de respaldo inválido.'
  SEEN["$f"]=1;no_links "$RESTORE/$f"
  if [[ "$old" != MISSING ]];then [[ -f "$RESTORE/$f" && "$(digest "$RESTORE/$f")" == "$old" ]] || fail 'Respaldo alterado.';fi
  [[ "${BEFORE[$f]}" != "$old" ]] || continue
  [[ "${BEFORE[$f]}" == "$installed" ]] || fail "Editado después de publicar: $f. No se sobrescribe."
  CHANGED+=("$f");AFTER["$f"]="$old";if [[ "$old" != MISSING ]];then cp -p -- "$RESTORE/$f" "$TMP/stage/$f";fi
 done < "$RESTORE/archivos.tsv"
 [[ "${#SEEN[@]}" -gt 0 ]] || fail 'Respaldo vacío.'
 # Restaurar consumidores antes de retirar sus dependencias.
FILES=("app/services/CorreoConvocatoriaComposicion.php" "tools/diagnosticar_envio_convocatoria.php")
else
 [[ -f "$LIVE/vendor/autoload.php" ]] || fail 'Falta vendor/autoload.php. No se instalan dependencias.'
 [[ -d "$LIVE/tools" ]] || fail 'Falta tools del CRM; revisar la instalación previa.'
 php -r 'exit(PHP_VERSION_ID>=80200?0:1);' || fail 'Se requiere PHP 8.2+.'
 function code_digest(){
 if [[ "$1" == *.php ]];then
  php -r '$out=[];foreach(token_get_all(file_get_contents($argv[1])) as $t){if(is_array($t)){if(in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$out[]=[$t[0],$t[0]===T_OPEN_TAG?trim($t[1]):$t[1]];}else{$out[]=$t;}}echo hash("sha256",json_encode($out));' "$1"
 else digest "$1";fi
 }
 php -r 'exit(function_exists("token_get_all")?0:1);' || fail 'Se requiere el tokenizador de PHP para reconocer versiones sin modificar comentarios.'
 base64 --decode > "$TMP/stage/tools/diagnosticar_envio_convocatoria.php" <<'CONVOCATORIA_HELPER_0'
PD9waHAKLy8gQ0xJIGV4Y2x1c2l2YW1lbnRlLiBTaW4gZW52w61vcywgY29uc3VsdGFzIEFQSSwg
Y2FtYmlvcyBkZSBCRCBuaSBsZWN0dXJhIGRlIHRva2Vucy4KaWYoUEhQX1NBUEkhPT0nY2xpJykg
eyBodHRwX3Jlc3BvbnNlX2NvZGUoNDA0KTtleGl0OyB9CmluaV9zZXQoJ2Rpc3BsYXlfZXJyb3Jz
JywnMCcpOwokb3B0cz1nZXRvcHQoJycsWydjaGVjaycsJ2NvbnZvY2F0b3JpYTonLCdyb290Oidd
KTsKJHJvb3Q9cmVhbHBhdGgoKHN0cmluZykoJG9wdHNbJ3Jvb3QnXT8/ZGlybmFtZShfX0RJUl9f
KSkpOwppZighJHJvb3QgfHwgIWlzX2ZpbGUoJHJvb3QuJy9hcHAvc2VydmljZXMvQ29ycmVvQ29u
dm9jYXRvcmlhQ29tcG9zaWNpb24ucGhwJykpIHsgZndyaXRlKFNUREVSUiwiQ09OVk9DQVRPUklB
X0NPRElHT19OT19JTlNUQUxBRE9cbiIpO2V4aXQoMik7IH0KdHJ5IHsKICAgIHJlcXVpcmVfb25j
ZSAkcm9vdC4nL2FwcC9zZXJ2aWNlcy9Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbi5waHAn
OwogICAgLy8gRWwgZG9ibGUgbm8gaW5pY2lhbGl6YSB0cmFuc3BvcnRlOiBjdWFscXVpZXIgbcOp
dG9kbyBoZXJlZGFkbyBwb3IgZXJyb3IgZGViZSBmYWxsYXIuCiAgICBjbGFzcyBDb252b2NhdG9y
aWFQcmVmbGlnaHRQcm9iZSBleHRlbmRzIENvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNl
IHsKICAgICAgICBwdWJsaWMgJGNhbGxzPVtdOwogICAgICAgIHB1YmxpYyBmdW5jdGlvbiBfX2Nv
bnN0cnVjdCgpIHt9CiAgICAgICAgcHVibGljIGZ1bmN0aW9uIHByZXBhcmFyU2FsaWRhKHN0cmlu
ZyAkZnJvbSxpbnQgJHVzZXIpOiBhcnJheSB7ICR0aGlzLT5jYWxsc1tdPSdwcmVwYXJhclNhbGlk
YSc7cmV0dXJuIFsnb2snPT50cnVlXTsgfQogICAgICAgIHB1YmxpYyBmdW5jdGlvbiB2YWxpZGFy
UmVtaXRlbnRlKHN0cmluZyAkZnJvbSxpbnQgJHVzZXIpOiBhcnJheSB7ICR0aGlzLT5jYWxsc1td
PSd2YWxpZGFyUmVtaXRlbnRlJztyZXR1cm4gWydvayc9PnRydWVdOyB9CiAgICAgICAgcHVibGlj
IGZ1bmN0aW9uIGVudmlhcigkZGF0YSkgeyB0aHJvdyBuZXcgTG9naWNFeGNlcHRpb24oJ0VOVklP
X1BST0hJQklET19FTl9ESUFHTk9TVElDTycpOyB9CiAgICB9CiAgICAkcHJvYmU9bmV3IENvbnZv
Y2F0b3JpYVByZWZsaWdodFByb2JlKCk7JHdyYXBwZXI9bmV3IENvcnJlb0NvbnZvY2F0b3JpYU1l
bnNhamUoW10sbnVsbCwkcHJvYmUpOwogICAgJHBoYXNlPSdwcmVwYXJhclNhbGlkYSc7JHdyYXBw
ZXItPnByZXBhcmFyU2FsaWRhKCdxYUBleGFtcGxlLnRlc3QnLDEpOwogICAgJHBoYXNlPSd2YWxp
ZGFyUmVtaXRlbnRlJzskd3JhcHBlci0+dmFsaWRhclJlbWl0ZW50ZSgncWFAZXhhbXBsZS50ZXN0
JywxKTsKICAgIGlmKCRwcm9iZS0+Y2FsbHMhPT1bJ3ByZXBhcmFyU2FsaWRhJywndmFsaWRhclJl
bWl0ZW50ZSddKSB7IHRocm93IG5ldyBMb2dpY0V4Y2VwdGlvbignREVMRUdBQ0lPTl9OT19DT05G
SVJNQURBJyk7IH0KICAgICRvdXQ9Wyd2ZXJzaW9uJz0+J2NvbnZvY2F0b3JpYS1lbnZpby12MScs
J2F1dG9yaXphY2lvbl9kZWxlZ2FkYSc9PnRydWUsJ3BydWViYSc9Pidkb2JsZSBsb2NhbCBzaW4g
cHJvdmVlZG9yJywnc2hhMjU2X2NvbXBvc2l0b3InPT5oYXNoX2ZpbGUoJ3NoYTI1NicsJHJvb3Qu
Jy9hcHAvc2VydmljZXMvQ29ycmVvQ29udm9jYXRvcmlhQ29tcG9zaWNpb24ucGhwJyldOwogICAg
aWYoaXNzZXQoJG9wdHNbJ2NvbnZvY2F0b3JpYSddKSkgewogICAgICAgICRwaGFzZT0nbGVjdHVy
YV9jb252b2NhdG9yaWEnOyRpZD1Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbjo6aWQoJG9w
dHNbJ2NvbnZvY2F0b3JpYSddKTsKICAgICAgICBpZighJGlkKSB7IHRocm93IG5ldyBJbnZhbGlk
QXJndW1lbnRFeGNlcHRpb24oJ0lEX1JFUVVFUklETycpOyB9CiAgICAgICAgJG1vZGVsPW5ldyBD
b3JyZW9Db252b2NhdG9yaWFDb25zdWx0YU1vZGVsKCk7JHJvdz0kbW9kZWwtPmJ1c2NhcigkaWQp
OwogICAgICAgIGlmKCEkcm93KSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdDT05WT0NB
VE9SSUFfTk9fRVhJU1RFJyk7IH0KICAgICAgICAkaW1hZ2U9KG5ldyBDb3JyZW9Db252b2NhdG9y
aWFDb21wb3NpY2lvbigkbW9kZWwsJHJvb3QpKS0+aW1hZ2VuKCRyb3cpOwogICAgICAgICRvdXRb
J2NvbnZvY2F0b3JpYSddPVsnaWQnPT4kaWQsJ2FjdGl2YSc9PiRyb3dbJ2FjdGl2YSddLCdpbWFn
ZW5fcmVmZXJlbmNpYWRhJz0+IWVtcHR5KCRyb3dbJ2ltYWdlbl9yZWYnXSksJ2ltYWdlbl92YWxp
ZGEnPT4oYm9vbCkkaW1hZ2VdOwogICAgICAgIGlmKCRpbWFnZSkgeyAkb3V0Wydjb252b2NhdG9y
aWEnXSs9WydieXRlcyc9PiRpbWFnZVsndGFtYW5vJ10sJ21pbWUnPT4kaW1hZ2VbJ21pbWUnXSwn
c2hhMjU2Jz0+JGltYWdlWydzaGEnXV07IH0KICAgIH0KICAgIGVjaG8ganNvbl9lbmNvZGUoJG91
dCxKU09OX1BSRVRUWV9QUklOVHxKU09OX1VORVNDQVBFRF9VTklDT0RFKS5QSFBfRU9MOwogICAg
ZWNobyAiU09MTyBMRUNUVVJBLiBObyBzZSBoYSBlbnZpYWRvIG5pbmfDum4gY29ycmVvIG5pIGNv
bXByb2JhZG8gcmVjZXBjacOzbi5cbiI7Cn0gY2F0Y2goVGhyb3dhYmxlICRlcnJvcikgewogICAg
ZndyaXRlKFNUREVSUiwnQ09OVk9DQVRPUklBX0RJQUdOT1NUSUNPX0VSUk9SIGZhc2U9Jy4oJHBo
YXNlPz8nY2FyZ2EnKS4nIGNsYXNlPScuZ2V0X2NsYXNzKCRlcnJvcikuJyBjb2RpZ289Jy4oaW50
KSRlcnJvci0+Z2V0Q29kZSgpLiIuIFNpbiBkYXRvcyBwcml2YWRvcy5cbiIpO2V4aXQoMik7Cn0K
CONVOCATORIA_HELPER_0
 [[ "$(digest "$TMP/stage/tools/diagnosticar_envio_convocatoria.php")" == 'd85af8f03d2f87c68a9a968dd0ee1ff27a0afa4503bec7e63b6aca40ec218503' ]] || fail 'Dependencia alterada.'
 if [[ "${BEFORE["tools/diagnosticar_envio_convocatoria.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/tools/diagnosticar_envio_convocatoria.php")"
  [[ "$existing" == 'd85af8f03d2f87c68a9a968dd0ee1ff27a0afa4503bec7e63b6aca40ec218503' ]] || fail 'Dependencia personalizada: tools/diagnosticar_envio_convocatoria.php. SITIO INTACTO.'
  cp -p -- "$LIVE/tools/diagnosticar_envio_convocatoria.php" "$TMP/stage/tools/diagnosticar_envio_convocatoria.php"
 else chmod 0644 "$TMP/stage/tools/diagnosticar_envio_convocatoria.php";fi
 declare -A EOLS=()
 base64 --decode > "$TMP/feature-0.patch" <<'CONVOCATORIA_PATCH_0'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbi5w
aHAgYi9hcHAvc2VydmljZXMvQ29ycmVvQ29udm9jYXRvcmlhQ29tcG9zaWNpb24ucGhwCi0tLSBh
L2FwcC9zZXJ2aWNlcy9Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbi5waHAKKysrIGIvYXBw
L3NlcnZpY2VzL0NvcnJlb0NvbnZvY2F0b3JpYUNvbXBvc2ljaW9uLnBocApAQCAtOTksNiArOTks
MjQgQEAKICAgICB7CiAgICAgICAgICR0aGlzLT5zZWxlY3Rpb249JHNlbGVjdGlvbjskdGhpcy0+
aW1hZ2U9JGltYWdlOyR0aGlzLT5kZWxlZ2F0ZT0kZGVsZWdhdGU/P25ldyBDb3JyZW9TYWxpZGFJ
bnN0aXR1Y2lvbmFsU2VydmljZSgpOwogICAgIH0KKyAgICAvLyBNYXJrZXRpbmcgdjQgYXV0b3Jp
emEgYW50ZXMgZGUgZW52aWFyLiBVc2FyIGxhIG1pc21hIGluc3RhbmNpYSBjb25maWd1cmFkYSwK
KyAgICAvLyBudW5jYSBsb3MgbcOpdG9kb3MgaGVyZWRhZG9zIHF1ZSBkZXBlbmRlbiBkZSBwcm9w
aWVkYWRlcyBwcml2YWRhcyBkZWwgcGFkcmUuCisgICAgcHVibGljIGZ1bmN0aW9uIHByZXBhcmFy
U2FsaWRhKHN0cmluZyAkZnJvbSxpbnQgJHVzZXIpOiBhcnJheQorICAgIHsKKyAgICAgICAgdHJ5
IHsgcmV0dXJuICR0aGlzLT5kZWxlZ2F0ZS0+cHJlcGFyYXJTYWxpZGEoJGZyb20sJHVzZXIpOyB9
CisgICAgICAgIGNhdGNoKFRocm93YWJsZSAkZXJyb3IpIHsKKyAgICAgICAgICAgIGVycm9yX2xv
ZygnTUFJTF9DT05WT0NBVE9SSUEgcGhhc2U9cHJlcGFyYXJfc2FsaWRhIGV4Y2VwdGlvbj0nLmdl
dF9jbGFzcygkZXJyb3IpLicgY29kZT0nLihpbnQpJGVycm9yLT5nZXRDb2RlKCkpOworICAgICAg
ICAgICAgdGhyb3cgJGVycm9yOworICAgICAgICB9CisgICAgfQorICAgIHB1YmxpYyBmdW5jdGlv
biB2YWxpZGFyUmVtaXRlbnRlKHN0cmluZyAkZnJvbSxpbnQgJHVzZXIpOiBhcnJheQorICAgIHsK
KyAgICAgICAgdHJ5IHsgcmV0dXJuICR0aGlzLT5kZWxlZ2F0ZS0+dmFsaWRhclJlbWl0ZW50ZSgk
ZnJvbSwkdXNlcik7IH0KKyAgICAgICAgY2F0Y2goVGhyb3dhYmxlICRlcnJvcikgeworICAgICAg
ICAgICAgZXJyb3JfbG9nKCdNQUlMX0NPTlZPQ0FUT1JJQSBwaGFzZT12YWxpZGFyX3JlbWl0ZW50
ZSBleGNlcHRpb249Jy5nZXRfY2xhc3MoJGVycm9yKS4nIGNvZGU9Jy4oaW50KSRlcnJvci0+Z2V0
Q29kZSgpKTsKKyAgICAgICAgICAgIHRocm93ICRlcnJvcjsKKyAgICAgICAgfQorICAgIH0KICAg
ICBwdWJsaWMgZnVuY3Rpb24gZW52aWFyKCRkYXRhKQogICAgIHsKICAgICAgICAgaWYoIWVtcHR5
KCR0aGlzLT5zZWxlY3Rpb25bJ2ltYWdlbl9zaGEnXSkgJiYgISR0aGlzLT5pbWFnZSkgeyByZXR1
cm4gWydvayc9PmZhbHNlLCdjb2RpZ29faHR0cCc9PjQyMiwnZXN0YWRvX2VudHJlZ2EnPT4nUkVD
SEFaQURPJywnbWVuc2FqZSc9PidMYSBpbWFnZW4gZGUgbGEgY29udm9jYXRvcmlhIGNhbWJpw7Mu
IFZ1ZWx2ZSBhIHNlbGVjY2lvbmFybGEgYW50ZXMgZGUgZW52aWFyLiddOyB9CkBAIC0xMTMsNiAr
MTMxLDE0IEBACiAgICAgICAgICAgICAkdGl0bGU9aHRtbHNwZWNpYWxjaGFycygkdGhpcy0+c2Vs
ZWN0aW9uWyd0aXR1bG8nXSxFTlRfUVVPVEVTLCdVVEYtOCcpOwogICAgICAgICAgICAgJGRhdGFb
J2h0bWxfYWRpY2lvbmFsJ109KCRkYXRhWydodG1sX2FkaWNpb25hbCddPz8nJykuJzxkaXYgc3R5
bGU9Im1hcmdpbi10b3A6MThweCI+PGltZyBzcmM9ImNpZDonLiRpWydjaWQnXS4nIiBhbHQ9Iicu
JHRpdGxlLiciIHdpZHRoPSI2MDAiIHN0eWxlPSJkaXNwbGF5OmJsb2NrO3dpZHRoOjEwMCU7bWF4
LXdpZHRoOjYwMHB4O2hlaWdodDphdXRvO2JvcmRlcjowIj48L2Rpdj4nOwogICAgICAgICB9Ci0g
ICAgICAgIHJldHVybiAkdGhpcy0+ZGVsZWdhdGUtPmVudmlhcigkZGF0YSk7CisgICAgICAgIHRy
eSB7ICRyZXN1bHQ9JHRoaXMtPmRlbGVnYXRlLT5lbnZpYXIoJGRhdGEpOyB9CisgICAgICAgIGNh
dGNoKFRocm93YWJsZSAkZXJyb3IpIHsKKyAgICAgICAgICAgIGVycm9yX2xvZygnTUFJTF9DT05W
T0NBVE9SSUEgcGhhc2U9dHJhbnNwb3J0ZSBleGNlcHRpb249Jy5nZXRfY2xhc3MoJGVycm9yKS4n
IGNvZGU9Jy4oaW50KSRlcnJvci0+Z2V0Q29kZSgpKTsKKyAgICAgICAgICAgIHRocm93ICRlcnJv
cjsKKyAgICAgICAgfQorICAgICAgICAkc3RhdGU9JHJlc3VsdFsnZXN0YWRvX2VudHJlZ2EnXT8/
KCFlbXB0eSgkcmVzdWx0WydvayddKT8nQUNFUFRBRE8nOidSRUNIQVpBRE8nKTsKKyAgICAgICAg
aWYoIWluX2FycmF5KCRzdGF0ZSxbJ0FDRVBUQURPJywnUkVDSEFaQURPJywnREVTQ09OT0NJRE8n
XSx0cnVlKSkgeyAkc3RhdGU9J0RFU0NPTk9DSURPJzsgfQorICAgICAgICBlcnJvcl9sb2coJ01B
SUxfQ09OVk9DQVRPUklBIHBoYXNlPXJlc3VsdGFkb190cmFuc3BvcnRlIGVzdGFkbz0nLiRzdGF0
ZS4nIHNvbGljaXR1ZD0nLnN1YnN0cihoYXNoKCdzaGEyNTYnLChzdHJpbmcpKCRkYXRhWydpZGVt
cG90ZW5jeV9rZXknXT8/JycpKSwwLDEyKSk7CisgICAgICAgIHJldHVybiAkcmVzdWx0OwogICAg
IH0KIH0K
CONVOCATORIA_PATCH_0
 [[ "$(digest "$TMP/feature-0.patch")" == '61ae589c9893b68c177f1e5b26b604032e0ade134610e586657b35940365d8f4' ]] || fail 'Parche alterado.'
 f='app/services/CorreoConvocatoriaComposicion.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 69e995a2990e1f182e58d6f8c04c0549d38065eeeeecdb85f760e7c36495b421|e498cb337bf58ded87f8f37653ff2409359149034c6cb42c6f79cd5a37dd51cc) ;;
 11faa984b7261b20ac3565afea9b5981ddd9a8bbfb035b96353dea0be4428af3|ee5c16de3c9699d1bfae0765fb428f671bd21706159564dea406be9ec39a64a6)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-0.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-0.patch")
  case "$(code_digest "$TMP/stage/$f")" in 69e995a2990e1f182e58d6f8c04c0549d38065eeeeecdb85f760e7c36495b421|e498cb337bf58ded87f8f37653ff2409359149034c6cb42c6f79cd5a37dd51cc) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 for f in "${PATCH_FILES[@]}";do
  if [[ "${EOLS[$f]}" == CRLF ]];then php -r '$p=$argv[1];file_put_contents($p,str_replace("\n","\r\n",file_get_contents($p)));' "$TMP/stage/$f";fi
  if [[ "${BEFORE[$f]}" == MISSING ]];then chmod 0644 "$TMP/stage/$f";else chmod --reference="$TMP/original/$f" "$TMP/stage/$f";fi
 done
 for f in "${FILES[@]}";do
  if [[ "$f" == *.php ]];then php -l "$TMP/stage/$f" >/dev/null || fail "Sintaxis PHP inválida: $f";fi
  AFTER["$f"]="$(digest "$TMP/stage/$f")"
  if [[ "${BEFORE[$f]}" != "${AFTER[$f]}" ]];then CHANGED+=("$f");fi
 done
fi
[[ "${#CHANGED[@]}" -gt 0 ]] || { echo 'OK: ya aplicado o restaurado. Ningún archivo reemplazado.';exit 0; }
for f in "${FILES[@]}";do unchanged "$f";done
if [[ "$MODE" == --check ]];then echo "OK: revisión compatible. ${#CHANGED[@]} archivo(s). SITIO INTACTO. Sin SQL ni envíos de correo.";exit 0;fi
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/envio-convocatoria-$(date +%Y%m%d-%H%M%S)-$$";mkdir -m 700 "$BACKUP"
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt"
 for f in "${CHANGED[@]}";do
  printf '%s\t%s\t%s\n' "$f" "${BEFORE[$f]}" "${AFTER[$f]}" >> "$BACKUP/archivos.tsv"
  if [[ "${BEFORE[$f]}" != MISSING ]];then mkdir -p "$BACKUP/$(dirname "$f")";cp -p -- "$TMP/original/$f" "$BACKUP/$f";[[ "$(digest "$BACKUP/$f")" == "${BEFORE[$f]}" ]] || fail 'Respaldo inconsistente.';fi
 done
fi
WRITING=1
for f in "${FILES[@]}";do
 [[ -n "${AFTER[$f]:-}" && "${BEFORE[$f]}" != "${AFTER[$f]}" ]] || continue
 unchanged "$f";[[ ! -e "$LIVE/$f.impe-new-$$" && ! -L "$LIVE/$f.impe-new-$$" ]] || fail 'Existe un temporal de instalación.'
 WRITTEN+=("$f")
 if [[ "${AFTER[$f]}" == MISSING ]];then rm -- "$LIVE/$f";
 else cp -p -- "$TMP/stage/$f" "$LIVE/$f.impe-new-$$";mv -f -- "$LIVE/$f.impe-new-$$" "$LIVE/$f";[[ "$(digest "$LIVE/$f")" == "${AFTER[$f]}" ]] || fail 'Archivo instalado distinto.';fi
done
WRITING=0
echo "OK: $MODE terminado. ${#CHANGED[@]} archivo(s). Sin cambios de BD ni credenciales. Verificar envíos reales por separado."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
