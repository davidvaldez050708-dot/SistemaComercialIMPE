#!/usr/bin/env bash
# Filtros e imagen de convocatoria. Sin cambios de transporte, SQL ni credenciales.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='convenios-manuales-v1-e6fea241ab396da3'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/models/CorreoConvocatoriaConsultaModel.php'
DIAGNOSTIC='tools/diagnosticar_hostinger_comercial.php'
FILES=("tools/diagnosticar_convenios_manuales_correo.php" "app/models/CorreoConvocatoriaConsultaModel.php" "app/services/CorreoConvocatoriaComposicion.php" "public/javascript/correo_convocatorias.js")
PATCH_FILES=("app/models/CorreoConvocatoriaConsultaModel.php" "app/services/CorreoConvocatoriaComposicion.php" "public/javascript/correo_convocatorias.js")
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
FILES=("public/javascript/correo_convocatorias.js" "app/services/CorreoConvocatoriaComposicion.php" "app/models/CorreoConvocatoriaConsultaModel.php" "tools/diagnosticar_convenios_manuales_correo.php")
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
 base64 --decode > "$TMP/stage/tools/diagnosticar_convenios_manuales_correo.php" <<'CONVOCATORIA_HELPER_0'
PD9waHAKLy8gU8OzbG8gbGVjdHVyYSwgw7puaWNhbWVudGUgSURzIHkgbm9tYnJlcyBpbnN0aXR1
Y2lvbmFsZXM7IG51bmNhIGNvbnRhY3RvcyBuaSBzZWNyZXRvcy4KaWYoUEhQX1NBUEkhPT0nY2xp
JykgeyBodHRwX3Jlc3BvbnNlX2NvZGUoNDA0KTtleGl0OyB9CmluaV9zZXQoJ2Rpc3BsYXlfZXJy
b3JzJywnMCcpOwokb3B0aW9ucz1nZXRvcHQoJycsWydlc3RhZG86Jywncm9vdDonXSk7CiRyb290
PXJlYWxwYXRoKChzdHJpbmcpKCRvcHRpb25zWydyb290J10/P2Rpcm5hbWUoX19ESVJfXykpKTsK
JGVzdGFkbz1maWx0ZXJfdmFyKCRvcHRpb25zWydlc3RhZG8nXT8/MTEsRklMVEVSX1ZBTElEQVRF
X0lOVCxbJ29wdGlvbnMnPT5bJ21pbl9yYW5nZSc9PjEsJ21heF9yYW5nZSc9PjIxNDc0ODM2NDdd
XSk7CmlmKCEkcm9vdCB8fCAhJGVzdGFkbykgeyBmd3JpdGUoU1RERVJSLCJDT05WRU5JT1NfQVJH
VU1FTlRPU19JTlZBTElET1NcbiIpO2V4aXQoMik7IH0KdHJ5IHsKICAgIHJlcXVpcmVfb25jZSAk
cm9vdC4nL2FwcC9tb2RlbHMvQ29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbC5waHAnOwog
ICAgJGRiPShuZXcgRGF0YWJhc2UoKSktPmNvbm5lY3QoKTskY2F0YWxvZz0obmV3IENvcnJlb0Nv
bnZvY2F0b3JpYUNvbnN1bHRhTW9kZWwoJGRiKSktPmNhdGFsb2dvKCk7CiAgICAkb3V0PVsndmVy
c2lvbic9Pidjb252ZW5pb3MtbWFudWFsZXMtdjEnLCdlc3RhZG9faWQnPT4kZXN0YWRvLCdhdmlz
b3MnPT4kY2F0YWxvZ1snYXZpc29zJ10sJ2NvbnZlbmlvcyc9PltdXTsKICAgIGZvcmVhY2goJGNh
dGFsb2dbJ2NvbnZlbmlvcyddIGFzICRyb3cpIHsKICAgICAgICBpZigkcm93Wydlc3RhZG9faWQn
XSE9PSRlc3RhZG8pIHsgY29udGludWU7IH0KICAgICAgICAkaWRzPVtdOwogICAgICAgIGZvcmVh
Y2goJGNhdGFsb2dbJ2NvbnZvY2F0b3JpYXMnXSBhcyAkY2FsbCkgewogICAgICAgICAgICBpZihp
bl9hcnJheSgkcm93WydpZCddLGFycmF5X2NvbHVtbigkY2FsbFsnY29udmVuaW9zJ10sJ2lkJyks
dHJ1ZSkgJiYgaW5fYXJyYXkoJGVzdGFkbyxhcnJheV9jb2x1bW4oJGNhbGxbJ2VzdGFkb3MnXSwn
aWQnKSx0cnVlKSkgeyAkaWRzW109JGNhbGxbJ2lkJ107IH0KICAgICAgICB9CiAgICAgICAgJG91
dFsnY29udmVuaW9zJ11bXT1bJ2NsYXZlJz0+JHJvd1snaWQnXSwnaWRfcmVhbCc9PiRyb3dbJ29y
aWdlbl9pZCddPz8kcm93WydpZCddLCdvcmlnZW4nPT4kcm93WydvcmlnZW4nXT8/J2luc3RpdHVj
aW9uYWwnLCdub21icmUnPT4kcm93Wydub21icmUnXSwnY29udm9jYXRvcmlhc19hY3RpdmFzX2Rl
bF9lc3RhZG8nPT4kaWRzXTsKICAgIH0KICAgICRyPSRkYi0+cHJlcGFyZSgnU0VMRUNUIGlkIEZS
T00gYWxpYWRvc19tYW51YWxlcyBXSEVSRSBhY3Rpdm89MSBBTkQgZXN0YWRvX2lkPT8gT1JERVIg
QlkgaWQnKTsKICAgICRyLT5iaW5kX3BhcmFtKCdpJywkZXN0YWRvKTskci0+ZXhlY3V0ZSgpOyRy
ZXN1bHQ9JHItPmdldF9yZXN1bHQoKTskbWlzc2luZz1bXTskcmVhbD1bXTsKICAgICRsaXN0ZWQ9
YXJyYXlfY29sdW1uKCRvdXRbJ2NvbnZlbmlvcyddLCdjbGF2ZScpOwogICAgd2hpbGUoJHJvdz0k
cmVzdWx0LT5mZXRjaF9hc3NvYygpKSB7CiAgICAgICAgJGlkPShpbnQpJHJvd1snaWQnXTskcmVh
bFtdPSRpZDtpZighaW5fYXJyYXkoJ21hbnVhbDonLiRpZCwkbGlzdGVkLHRydWUpKSB7ICRtaXNz
aW5nW109JGlkOyB9CiAgICB9CiAgICAkb3V0WydpZHNfbWFudWFsZXNfZW5fZnVlbnRlJ109JHJl
YWw7JG91dFsnaWRzX21hbnVhbGVzX29taXRpZG9zJ109JG1pc3Npbmc7CiAgICAkb3V0WydkdXBs
aWNhZG9zX2NhdGFsb2dvJ109Y291bnQoJGxpc3RlZCktY291bnQoYXJyYXlfdW5pcXVlKCRsaXN0
ZWQpKTsKICAgIGVjaG8ganNvbl9lbmNvZGUoJG91dCxKU09OX1BSRVRUWV9QUklOVHxKU09OX1VO
RVNDQVBFRF9VTklDT0RFKS5QSFBfRU9MOwogICAgZWNobyAiU09MTyBMRUNUVVJBLiBTaW4gZW52
w61vcywgdG9rZW5zLCBjYW1iaW9zIGRlIGRhdG9zIG5pIHJlY3VwZXJhY2nDs24uXG4iOwogICAg
ZXhpdCgkbWlzc2luZyB8fCAkb3V0WydkdXBsaWNhZG9zX2NhdGFsb2dvJ10gfHwgJGNhdGFsb2db
J2F2aXNvcyddPzE6MCk7Cn0gY2F0Y2goVGhyb3dhYmxlICRlcnJvcikgewogICAgZndyaXRlKFNU
REVSUiwnQ09OVkVOSU9TX0RJQUdOT1NUSUNPX0VSUk9SIGNsYXNlPScuZ2V0X2NsYXNzKCRlcnJv
cikuJyBjb2RpZ289Jy4oaW50KSRlcnJvci0+Z2V0Q29kZSgpLiIuIFNpbiBkYXRvcyBwcml2YWRv
cy5cbiIpO2V4aXQoMik7Cn0K
CONVOCATORIA_HELPER_0
 [[ "$(digest "$TMP/stage/tools/diagnosticar_convenios_manuales_correo.php")" == 'c1eb4d8389dc3d3b6033caf7428bf97684ec5e9d905b6338bcb9ca8f5e585f0f' ]] || fail 'Dependencia alterada.'
 if [[ "${BEFORE["tools/diagnosticar_convenios_manuales_correo.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/tools/diagnosticar_convenios_manuales_correo.php")"
  [[ "$existing" == 'c1eb4d8389dc3d3b6033caf7428bf97684ec5e9d905b6338bcb9ca8f5e585f0f' ]] || fail 'Dependencia personalizada: tools/diagnosticar_convenios_manuales_correo.php. SITIO INTACTO.'
  cp -p -- "$LIVE/tools/diagnosticar_convenios_manuales_correo.php" "$TMP/stage/tools/diagnosticar_convenios_manuales_correo.php"
 else chmod 0644 "$TMP/stage/tools/diagnosticar_convenios_manuales_correo.php";fi
 declare -A EOLS=()
 base64 --decode > "$TMP/feature-0.patch" <<'CONVOCATORIA_PATCH_0'
ZGlmZiAtLWdpdCBhL2FwcC9tb2RlbHMvQ29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbC5w
aHAgYi9hcHAvbW9kZWxzL0NvcnJlb0NvbnZvY2F0b3JpYUNvbnN1bHRhTW9kZWwucGhwCi0tLSBh
L2FwcC9tb2RlbHMvQ29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbC5waHAKKysrIGIvYXBw
L21vZGVscy9Db3JyZW9Db252b2NhdG9yaWFDb25zdWx0YU1vZGVsLnBocApAQCAtNiw2ICs2LDcg
QEAKIHsKICAgICBwcml2YXRlICRkYjsKICAgICBwcml2YXRlICRyZWxhY2lvbmVzRGlzcG9uaWJs
ZXM7CisgICAgcHJpdmF0ZSAkdGFibGFzPVtdOwogICAgIHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0
cnVjdCgkZGI9bnVsbCkgeyAkdGhpcy0+ZGI9JGRiOyB9CiAgICAgcHJpdmF0ZSBmdW5jdGlvbiBm
aWxhcyhzdHJpbmcgJHNxbCxhcnJheSAkcGFyYW1zPVtdKTogYXJyYXkKICAgICB7CkBAIC0yNCw2
ICsyNSwxOCBAQAogICAgICAgICAgICAgJHRoaXMtPnJlbGFjaW9uZXNEaXNwb25pYmxlcz0oaW50
KSgkcm93c1swXVsndG90YWwnXT8/MCk+MDsKICAgICAgICAgfQogICAgICAgICByZXR1cm4gJHRo
aXMtPnJlbGFjaW9uZXNEaXNwb25pYmxlczsKKyAgICB9CisgICAgcHJpdmF0ZSBmdW5jdGlvbiB0
YWJsYShzdHJpbmcgJG5vbWJyZSk6IGJvb2wKKyAgICB7CisgICAgICAgIC8vIE5vbWJyZXMgaW50
ZXJub3MgY29uc3RhbnRlcywgbnVuY2EgcHJvY2VkZW50ZXMgZGUgbGEgcGV0aWNpw7NuLgorICAg
ICAgICByZXR1cm4gJHRoaXMtPnRhYmxhc1skbm9tYnJlXT8/PSAoaW50KSgkdGhpcy0+ZmlsYXMo
IlNFTEVDVCBDT1VOVCgqKSBBUyB0b3RhbCBGUk9NIGluZm9ybWF0aW9uX3NjaGVtYS50YWJsZXMg
V0hFUkUgdGFibGVfc2NoZW1hID0gREFUQUJBU0UoKSBBTkQgdGFibGVfbmFtZSA9ICckbm9tYnJl
JyIpWzBdWyd0b3RhbCddPz8wKT4wOworICAgIH0KKyAgICBwdWJsaWMgc3RhdGljIGZ1bmN0aW9u
IGNvbnZlbmlvSWQoJHZhbHVlKQorICAgIHsKKyAgICAgICAgaWYoaXNfc3RyaW5nKCR2YWx1ZSkg
JiYgcHJlZ19tYXRjaCgnL15tYW51YWw6KFsxLTldWzAtOV17MCw5fSkkL0QnLCR2YWx1ZSwkbSkg
JiYgKGZsb2F0KSRtWzFdPD0yMTQ3NDgzNjQ3KSB7IHJldHVybiAkdmFsdWU7IH0KKyAgICAgICAg
aWYoJHZhbHVlPT09bnVsbCB8fCAkdmFsdWU9PT0nJyB8fCAkdmFsdWU9PT0wIHx8ICR2YWx1ZT09
PScwJykgeyByZXR1cm4gMDsgfQorICAgICAgICBpZigoIWlzX3N0cmluZygkdmFsdWUpICYmICFp
c19pbnQoJHZhbHVlKSkgfHwgIXByZWdfbWF0Y2goJy9eWzEtOV1bMC05XXswLDl9JC9EJywoc3Ry
aW5nKSR2YWx1ZSkgfHwgKGZsb2F0KSR2YWx1ZT4yMTQ3NDgzNjQ3KSB7IHRocm93IG5ldyBSdW50
aW1lRXhjZXB0aW9uKCdDT05WT0NBVE9SSUFfSUQnKTsgfQorICAgICAgICByZXR1cm4gKGludCkk
dmFsdWU7CiAgICAgfQogICAgIHByaXZhdGUgZnVuY3Rpb24gY29tcGxldGFyKGFycmF5ICRyb3dz
KTogYXJyYXkKICAgICB7CkBAIC00Niw2ICs1OSwzMyBAQAogICAgICAgICAgICAgICAgICRpdGVt
c1soaW50KSRyWydjb252b2NhdG9yaWFfaWQnXV1bJ2NvbnZlbmlvcyddW109WydpZCc9PihpbnQp
JHJbJ2lkJ10sJ25vbWJyZSc9PihzdHJpbmcpJHJbJ25vbWJyZSddLCdyZWZlcmVuY2lhJz0+KHN0
cmluZykkclsncmVmZXJlbmNpYSddXTsKICAgICAgICAgICAgIH0KICAgICAgICAgfQorICAgICAg
ICAvLyBSZWxhY2lvbmVzIGRlbCBtw7NkdWxvIENvbnZlbmlvczogSUQgcmVhbCArIG9yaWdlbjsg
amFtw6FzIHBvciBub21icmUuCisgICAgICAgICRzb3VyY2VzPVtdOworICAgICAgICBpZigkdGhp
cy0+dGFibGEoJ21hcmtldGluZ19hbGlhZG9fY29udm9jYXRvcmlhc19vcmlnZW4nKSkgeworICAg
ICAgICAgICAgJHNvdXJjZXNbXT0iU0VMRUNUIERJU1RJTkNUIG1jLmNvbnZvY2F0b3JpYV9pZCwg
cy5pZCwgcy5ub21icmVfZW50aWRhZCBBUyBub21icmUsIENPQUxFU0NFKHAuY29udmVuaW9fcmVm
ZXJlbmNpYSwgJycpIEFTIHJlZmVyZW5jaWEKKyAgICAgICAgICAgICAgICBGUk9NIG1hcmtldGlu
Z19hbGlhZG9fY29udm9jYXRvcmlhc19vcmlnZW4gbWMgSU5ORVIgSk9JTiBzZWd1aW1pZW50b3Nf
dmluY3VsYWNpb24gcyBPTiBzLmlkPW1jLm9yaWdlbl9pZCBBTkQgbWMub3JpZ2VuX3RpcG89J2lu
c3RpdHVjaW9uYWwnCisgICAgICAgICAgICAgICAgSU5ORVIgSk9JTiBhbGlhZG9zX2FzaWduYWNp
b25lcyBhIE9OIGEuc2VndWltaWVudG9faWQ9cy5pZCBBTkQgYS5hY3Rpdm89MQorICAgICAgICAg
ICAgICAgIExFRlQgSk9JTiBzZWd1aW1pZW50b3NfdmluY3VsYWNpb25fcG9zdF9lbnZpbyBwIE9O
IHAuc2VndWltaWVudG9faWQ9cy5pZAorICAgICAgICAgICAgICAgIFdIRVJFIHMuYWN0aXZvPTEg
QU5EIG1jLmNvbnZvY2F0b3JpYV9pZCBJTiAoJGlkcykiOworICAgICAgICB9CisgICAgICAgIGlm
KCR0aGlzLT50YWJsYSgnYWxpYWRvc19tYW51YWxlcycpKSB7CisgICAgICAgICAgICBmb3JlYWNo
KFsnbWFya2V0aW5nX2FsaWFkb19tYW51YWxfY29udm9jYXRvcmlhcyc9PidtYy5tYW51YWxfaWQn
LCdtYXJrZXRpbmdfYWxpYWRvX2NvbnZvY2F0b3JpYXNfb3JpZ2VuJz0+J21jLm9yaWdlbl9pZCdd
IGFzICR0YWJsZT0+JGtleSkgeworICAgICAgICAgICAgICAgIGlmKCEkdGhpcy0+dGFibGEoJHRh
YmxlKSkgeyBjb250aW51ZTsgfQorICAgICAgICAgICAgICAgICR0eXBlPSR0YWJsZT09PSdtYXJr
ZXRpbmdfYWxpYWRvX2NvbnZvY2F0b3JpYXNfb3JpZ2VuJz8iIEFORCBtYy5vcmlnZW5fdGlwbz0n
bWFudWFsJyI6Jyc7CisgICAgICAgICAgICAgICAgJHNvdXJjZXNbXT0iU0VMRUNUIERJU1RJTkNU
IG1jLmNvbnZvY2F0b3JpYV9pZCwgQ09OQ0FUKCdtYW51YWw6JywgYS5pZCkgQVMgaWQsIGEubm9t
YnJlX2VudGlkYWQgQVMgbm9tYnJlLCBDT0FMRVNDRShhLnJlZmVyZW5jaWEsICcnKSBBUyByZWZl
cmVuY2lhCisgICAgICAgICAgICAgICAgICAgIEZST00gJHRhYmxlIG1jIElOTkVSIEpPSU4gYWxp
YWRvc19tYW51YWxlcyBhIE9OIGEuaWQ9JGtleQorICAgICAgICAgICAgICAgICAgICBXSEVSRSBh
LmFjdGl2bz0xJHR5cGUgQU5EIG1jLmNvbnZvY2F0b3JpYV9pZCBJTiAoJGlkcykiOworICAgICAg
ICAgICAgfQorICAgICAgICB9CisgICAgICAgIGZvcmVhY2goJHNvdXJjZXMgYXMgJHNxbCkgewor
ICAgICAgICAgICAgZm9yZWFjaCgkdGhpcy0+ZmlsYXMoJHNxbCkgYXMgJHIpIHsKKyAgICAgICAg
ICAgICAgICAkaXRlbXNbKGludCkkclsnY29udm9jYXRvcmlhX2lkJ11dWydjb252ZW5pb3MnXVtd
PVsnaWQnPT5zZWxmOjpjb252ZW5pb0lkKCRyWydpZCddKSwnbm9tYnJlJz0+KHN0cmluZykkclsn
bm9tYnJlJ10sJ3JlZmVyZW5jaWEnPT4oc3RyaW5nKSRyWydyZWZlcmVuY2lhJ11dOworICAgICAg
ICAgICAgfQorICAgICAgICB9CisgICAgICAgIGZvcmVhY2goJGl0ZW1zIGFzICYkaXRlbSkgewor
ICAgICAgICAgICAgJHVuaXF1ZT1bXTtmb3JlYWNoKCRpdGVtWydjb252ZW5pb3MnXSBhcyAkYykg
eyAkdW5pcXVlWyhzdHJpbmcpJGNbJ2lkJ11dPSRjOyB9CisgICAgICAgICAgICAkaXRlbVsnY29u
dmVuaW9zJ109YXJyYXlfdmFsdWVzKCR1bmlxdWUpOworICAgICAgICB9dW5zZXQoJGl0ZW0pOwog
ICAgICAgICByZXR1cm4gYXJyYXlfdmFsdWVzKCRpdGVtcyk7CiAgICAgfQogICAgIHB1YmxpYyBm
dW5jdGlvbiBjYXRhbG9nbygpOiBhcnJheQpAQCAtNjAsNyArMTAwLDcgQEAKICAgICAgICAgICAg
ICR3YXJuaW5nc1tdPSdDT05WRU5JT1NfQ09OU1VMVEFfTk9fRElTUE9OSUJMRSc7CiAgICAgICAg
IH0KICAgICAgICAgcmV0dXJuIFsnZXN0YWRvcyc9PmFycmF5X21hcChzdGF0aWMgZm4oJHIpPT5b
J2lkJz0+KGludCkkclsnaWQnXSwnbm9tYnJlJz0+KHN0cmluZykkclsnbm9tYnJlJ11dLCRzdGF0
ZXMpLCdjb252b2NhdG9yaWFzJz0+JHJvd3MsJ2NvbnZlbmlvcyc9PiRhZ3JlZW1lbnRzLCdhdmlz
b3MnPT4kd2FybmluZ3MsCi0gICAgICAgICAgICAnY29udmVuaW9zX2Rpc3BvbmlibGVzJz0+JHRo
aXMtPnRpZW5lUmVsYWNpb25lcygpXTsKKyAgICAgICAgICAgICdjb252ZW5pb3NfZGlzcG9uaWJs
ZXMnPT4kdGhpcy0+dGllbmVSZWxhY2lvbmVzKCkgfHwgJHRoaXMtPnRhYmxhKCdtYXJrZXRpbmdf
YWxpYWRvX21hbnVhbF9jb252b2NhdG9yaWFzJykgfHwgJHRoaXMtPnRhYmxhKCdtYXJrZXRpbmdf
YWxpYWRvX2NvbnZvY2F0b3JpYXNfb3JpZ2VuJyldOwogICAgIH0KICAgICBwdWJsaWMgZnVuY3Rp
b24gbGlzdGFyQ29udmVuaW9zKCk6IGFycmF5CiAgICAgewpAQCAtNzMsNiArMTEzLDE2IEBACiAg
ICAgICAgICAgICBXSEVSRSBhLmFjdGl2byA9IDEgQU5EIHMuYWN0aXZvID0gMSBPUkRFUiBCWSBz
Lm5vbWJyZV9lbnRpZGFkLCBzLmlkIik7CiAgICAgICAgICR1bmlxdWU9W107CiAgICAgICAgIGZv
cmVhY2goJHJvd3MgYXMgJHIpIHsgJGlkPShpbnQpJHJbJ2lkJ107JHVuaXF1ZVskaWRdPVsnaWQn
PT4kaWQsJ25vbWJyZSc9PihzdHJpbmcpJHJbJ25vbWJyZSddLCdlc3RhZG9faWQnPT4oaW50KSRy
Wydlc3RhZG9faWQnXSwncmVmZXJlbmNpYSc9PihzdHJpbmcpJHJbJ3JlZmVyZW5jaWEnXV07IH0K
KyAgICAgICAgLy8gTWlzbWFzIHJlZ2xhcyBTRUxFQ1QgZGUgQWxpYWRvUmVnaXN0cm9TZXJ2aWNl
OjptYW51YWxlcy9vcmlnZW4sCisgICAgICAgIC8vIHNpbiBpbXBvcnRhciBzdXMgb3BlcmFjaW9u
ZXMgZGUgZXNjcml0dXJhIG5pIGRlcGVuZGVyIGRlIHBlcmZpbGVzIGRlIGZvcm11bGFyaW8uCisg
ICAgICAgIGlmKCR0aGlzLT50YWJsYSgnYWxpYWRvc19tYW51YWxlcycpKSB7CisgICAgICAgICAg
ICBmb3JlYWNoKCR0aGlzLT5maWxhcygiU0VMRUNUIGEuaWQsIGEubm9tYnJlX2VudGlkYWQgQVMg
bm9tYnJlLCBhLmVzdGFkb19pZCwgQ09BTEVTQ0UoYS5yZWZlcmVuY2lhLCAnJykgQVMgcmVmZXJl
bmNpYQorICAgICAgICAgICAgICAgIEZST00gYWxpYWRvc19tYW51YWxlcyBhIElOTkVSIEpPSU4g
ZXN0YWRvcyBlIE9OIGUuaWQ9YS5lc3RhZG9faWQKKyAgICAgICAgICAgICAgICBXSEVSRSBhLmFj
dGl2bz0xIE9SREVSIEJZIGEubm9tYnJlX2VudGlkYWQsIGEuaWQiKSBhcyAkcikgeworICAgICAg
ICAgICAgICAgICRpZD0nbWFudWFsOicuKGludCkkclsnaWQnXTsKKyAgICAgICAgICAgICAgICAk
dW5pcXVlWyRpZF09WydpZCc9PiRpZCwnb3JpZ2VuJz0+J21hbnVhbCcsJ29yaWdlbl9pZCc9Pihp
bnQpJHJbJ2lkJ10sJ25vbWJyZSc9PihzdHJpbmcpJHJbJ25vbWJyZSddLCdlc3RhZG9faWQnPT4o
aW50KSRyWydlc3RhZG9faWQnXSwncmVmZXJlbmNpYSc9PihzdHJpbmcpJHJbJ3JlZmVyZW5jaWEn
XV07CisgICAgICAgICAgICB9CisgICAgICAgIH0KICAgICAgICAgcmV0dXJuIGFycmF5X3ZhbHVl
cygkdW5pcXVlKTsKICAgICB9CiAgICAgcHVibGljIGZ1bmN0aW9uIGJ1c2NhcihpbnQgJGlkKTog
P2FycmF5Cg==
CONVOCATORIA_PATCH_0
 [[ "$(digest "$TMP/feature-0.patch")" == '4afca458f28af7c15d3b513260881e6b455c8e8b523a9f5c583a31ea2e5c89f4' ]] || fail 'Parche alterado.'
 f='app/models/CorreoConvocatoriaConsultaModel.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 7d7ee423d942d58988fc4250d02921abf04a42e4a7813efc3db6192946e542ab) ;;
 72c8aea83463af51d24bbc0774b360f6c5349bfd72bc3f21edc7e4bfd01fb6ad)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-0.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-0.patch")
  case "$(code_digest "$TMP/stage/$f")" in 7d7ee423d942d58988fc4250d02921abf04a42e4a7813efc3db6192946e542ab) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-1.patch" <<'CONVOCATORIA_PATCH_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbi5w
aHAgYi9hcHAvc2VydmljZXMvQ29ycmVvQ29udm9jYXRvcmlhQ29tcG9zaWNpb24ucGhwCi0tLSBh
L2FwcC9zZXJ2aWNlcy9Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbi5waHAKKysrIGIvYXBw
L3NlcnZpY2VzL0NvcnJlb0NvbnZvY2F0b3JpYUNvbXBvc2ljaW9uLnBocApAQCAtNTYsNyArNTYs
NyBAQAogICAgICAgICBpZighJGlkKSB7IHJldHVybiBbXTsgfQogICAgICAgICAkcm93PSR0aGlz
LT5tb2RlbC0+YnVzY2FyKCRpZCk7CiAgICAgICAgIGlmKCEkcm93IHx8ICghJHJvd1snYWN0aXZh
J10gJiYgKGludCkoJHByZXZpb3VzWydpZCddPz8wKSE9PSRpZCkpIHsgdGhyb3cgbmV3IFJ1bnRp
bWVFeGNlcHRpb24oJ0NPTlZPQ0FUT1JJQV9OT19ESVNQT05JQkxFJyk7IH0KLSAgICAgICAgJHN0
YXRlPXNlbGY6OmlkKCRpbnB1dFsnY29udm9jYXRvcmlhX2VzdGFkb19pZCddPz8nJyk7JGFncmVl
bWVudD1zZWxmOjppZCgkaW5wdXRbJ2NvbnZvY2F0b3JpYV9jb252ZW5pb19pZCddPz8nJyk7Cisg
ICAgICAgICRzdGF0ZT1zZWxmOjppZCgkaW5wdXRbJ2NvbnZvY2F0b3JpYV9lc3RhZG9faWQnXT8/
JycpOyRhZ3JlZW1lbnQ9Q29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbDo6Y29udmVuaW9J
ZCgkaW5wdXRbJ2NvbnZvY2F0b3JpYV9jb252ZW5pb19pZCddPz8nJyk7CiAgICAgICAgIGlmKCgk
c3RhdGUgJiYgIWluX2FycmF5KCRzdGF0ZSxhcnJheV9jb2x1bW4oJHJvd1snZXN0YWRvcyddLCdp
ZCcpLHRydWUpKSB8fCAoJGFncmVlbWVudCAmJiAhaW5fYXJyYXkoJGFncmVlbWVudCxhcnJheV9j
b2x1bW4oJHJvd1snY29udmVuaW9zJ10sJ2lkJyksdHJ1ZSkpKSB7IHRocm93IG5ldyBSdW50aW1l
RXhjZXB0aW9uKCdDT05WT0NBVE9SSUFfRklMVFJPUycpOyB9CiAgICAgICAgICRpbWFnZT0kdGhp
cy0+aW1hZ2VuKCRyb3cpOyRleHBlY3RlZD0kaW5wdXRbJ2NvbnZvY2F0b3JpYV9pbWFnZW5fc2hh
J10/PycnOwogICAgICAgICBpZighaXNfc3RyaW5nKCRleHBlY3RlZCkgfHwgKCRleHBlY3RlZCE9
PScnICYmICFwcmVnX21hdGNoKCcvXlthLWYwLTldezY0fSQvRCcsJGV4cGVjdGVkKSkpIHsgdGhy
b3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0NPTlZPQ0FUT1JJQV9JTUFHRU5fVkVSU0lPTicpOyB9
Cg==
CONVOCATORIA_PATCH_1
 [[ "$(digest "$TMP/feature-1.patch")" == 'ea55f6f657e7de45e3f280c98c2418d6ed505d18af493b94c00d209b8498a8fe' ]] || fail 'Parche alterado.'
 f='app/services/CorreoConvocatoriaComposicion.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 11faa984b7261b20ac3565afea9b5981ddd9a8bbfb035b96353dea0be4428af3) ;;
 ee5c16de3c9699d1bfae0765fb428f671bd21706159564dea406be9ec39a64a6)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-1.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-1.patch")
  case "$(code_digest "$TMP/stage/$f")" in 11faa984b7261b20ac3565afea9b5981ddd9a8bbfb035b96353dea0be4428af3) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-2.patch" <<'CONVOCATORIA_PATCH_2'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb19jb252b2NhdG9yaWFzLmpzIGIv
cHVibGljL2phdmFzY3JpcHQvY29ycmVvX2NvbnZvY2F0b3JpYXMuanMKLS0tIGEvcHVibGljL2ph
dmFzY3JpcHQvY29ycmVvX2NvbnZvY2F0b3JpYXMuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQv
Y29ycmVvX2NvbnZvY2F0b3JpYXMuanMKQEAgLTE0LDkgKzE0LDkgQEAKICAgICAgICAgY29uc3Qg
cmVsYWNpb25hZG9zID0gbmV3IFNldChmaWx0cmFyKHJvd3MsIGVzdGFkbywgJycpLmZsYXRNYXAo
cm93ID0+IHJvdy5jb252ZW5pb3MubWFwKGMgPT4gU3RyaW5nKGMuaWQpKSkpOwogICAgICAgICBj
b25zdCBkaXNwb25pYmxlcyA9IEFycmF5LmlzQXJyYXkoY2F0YWxvZ28pID8gY2F0YWxvZ28gOiBy
b3dzLmZsYXRNYXAocm93ID0+IHJvdy5jb252ZW5pb3MpOwogICAgICAgICBkaXNwb25pYmxlcy5m
b3JFYWNoKGMgPT4gewotICAgICAgICAgICAgaWYgKCFlc3RhZG8gfHwgU3RyaW5nKGMuZXN0YWRv
X2lkKSA9PT0gU3RyaW5nKGVzdGFkbykgfHwgcmVsYWNpb25hZG9zLmhhcyhTdHJpbmcoYy5pZCkp
KSB7IHVuaXF1ZS5zZXQoU3RyaW5nKGMuaWQpLCBjKTsgfQorICAgICAgICAgICAgaWYgKCFlc3Rh
ZG8gfHwgKGMuZXN0YWRvX2lkID8gU3RyaW5nKGMuZXN0YWRvX2lkKSA9PT0gU3RyaW5nKGVzdGFk
bykgOiByZWxhY2lvbmFkb3MuaGFzKFN0cmluZyhjLmlkKSkpKSB7IHVuaXF1ZS5zZXQoU3RyaW5n
KGMuaWQpLCBjKTsgfQogICAgICAgICB9KTsKLSAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odW5p
cXVlLnZhbHVlcygpKS5zb3J0KChhLCBiKSA9PiBhLm5vbWJyZS5sb2NhbGVDb21wYXJlKGIubm9t
YnJlLCAnZXMnKSB8fCBhLmlkIC0gYi5pZCk7CisgICAgICAgIHJldHVybiBBcnJheS5mcm9tKHVu
aXF1ZS52YWx1ZXMoKSkuc29ydCgoYSwgYikgPT4gYS5ub21icmUubG9jYWxlQ29tcGFyZShiLm5v
bWJyZSwgJ2VzJykgfHwgU3RyaW5nKGEuaWQpLmxvY2FsZUNvbXBhcmUoU3RyaW5nKGIuaWQpLCAn
ZXMnLCB7IG51bWVyaWM6IHRydWUgfSkpOwogICAgIH07CiAgICAgY29uc3QgZXRpcXVldGEgPSBy
b3cgPT4gW3Jvdy50aXR1bG8sIHJvdy5jb252ZW5pb3MubWFwKGMgPT4gYy5ub21icmUpLmpvaW4o
JywgJyksIHJvdy5lc3RhZG9zLm1hcChlID0+IGUubm9tYnJlKS5qb2luKCcsICcpLCAnIycgKyBy
b3cuaWRdLmZpbHRlcihCb29sZWFuKS5qb2luKCcgwrcgJyk7CiAKQEAgLTg4LDcgKzg4LDcgQEAK
ICAgICAgICAgICAgIHN0YXR1cy50ZXh0Q29udGVudCA9IHJvdz8uaW1hZ2VuX21lbnNhamUgfHwg
Jyc7CiAgICAgICAgIH07CiAgICAgICAgIGNvbnN0IGxpc3QgPSAoKSA9PiB7Ci0gICAgICAgICAg
ICBvcHRpb25zKGFncmVlbWVudCwgY29udmVuaW9zKHJvd3MsIHN0YXRlLnZhbHVlLCBhZ3JlZW1l
bnRzKSwgJ1RvZG9zIGxvcyBjb252ZW5pb3MnLCBjID0+IGMubm9tYnJlICsgKGMucmVmZXJlbmNp
YSA/ICcgwrcgJyArIGMucmVmZXJlbmNpYSA6ICcnKSArICcgwrcgIycgKyBjLmlkKTsKKyAgICAg
ICAgICAgIG9wdGlvbnMoYWdyZWVtZW50LCBjb252ZW5pb3Mocm93cywgc3RhdGUudmFsdWUsIGFn
cmVlbWVudHMpLCAnVG9kb3MgbG9zIGNvbnZlbmlvcycsIGMgPT4gYy5ub21icmUgKyAoYy5yZWZl
cmVuY2lhID8gJyDCtyAnICsgYy5yZWZlcmVuY2lhIDogJycpICsgJyDCtyAjJyArIChjLm9yaWdl
bl9pZCB8fCBjLmlkKSArIChjLm9yaWdlbiA9PT0gJ21hbnVhbCcgPyAnIChtYW51YWwpJyA6ICcn
KSk7CiAgICAgICAgICAgICBjb25zdCBwcmlvciA9IHNlbGVjdC52YWx1ZTsKICAgICAgICAgICAg
IGNvbnN0IGZpbHRlcmVkID0gZmlsdHJhcihyb3dzLCBzdGF0ZS52YWx1ZSwgYWdyZWVtZW50LnZh
bHVlKTsKICAgICAgICAgICAgIG9wdGlvbnMoc2VsZWN0LCBmaWx0ZXJlZCwgJ1NlbGVjY2lvbmEg
dW5hIGNvbnZvY2F0b3JpYScsIGV0aXF1ZXRhKTsK
CONVOCATORIA_PATCH_2
 [[ "$(digest "$TMP/feature-2.patch")" == '405e8b8e73863941a87a990a87c5db4de75babd5c6cbb412bdec98b9da05ceca' ]] || fail 'Parche alterado.'
 f='public/javascript/correo_convocatorias.js'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 84ddccf19cf55a74f7d7a228390c855d39b1792b5a14dfec7b1872811b61afaa) ;;
 9039c2d1ea8252218cd11344f2d12b39a1bdaf3b167847c69e01e40124dc2e41)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-2.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-2.patch")
  case "$(code_digest "$TMP/stage/$f")" in 84ddccf19cf55a74f7d7a228390c855d39b1792b5a14dfec7b1872811b61afaa) ;; *) fail "Resultado inesperado: $f";; esac ;;
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
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/convenios-manuales-$(date +%Y%m%d-%H%M%S)-$$";mkdir -m 700 "$BACKUP"
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
