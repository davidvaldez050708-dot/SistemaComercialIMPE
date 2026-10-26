#!/usr/bin/env bash
# Filtros e imagen de convocatoria. Sin cambios de transporte, SQL ni credenciales.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='filtros-correo-v2-eb35c0459e99d9d5'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/models/CorreoConvocatoriaConsultaModel.php'
DIAGNOSTIC='tools/diagnosticar_hostinger_comercial.php'
FILES=("tools/diagnosticar_filtros_correo.php" "app/models/CorreoConvocatoriaConsultaModel.php" "public/javascript/correo_convocatorias.js")
PATCH_FILES=("app/models/CorreoConvocatoriaConsultaModel.php" "public/javascript/correo_convocatorias.js")
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
FILES=("public/javascript/correo_convocatorias.js" "app/models/CorreoConvocatoriaConsultaModel.php" "tools/diagnosticar_filtros_correo.php")
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
 base64 --decode > "$TMP/stage/tools/diagnosticar_filtros_correo.php" <<'CONVOCATORIA_HELPER_0'
PD9waHAKLy8gRGlhZ27Ds3N0aWNvIENMSSBkZSBzw7NsbyBsZWN0dXJhLiBTaW4gYm9vdHN0cmFw
IHdlYiwgdG9rZW5zLCBTTVRQIG5pIHNlcnZpY2lvcyBkZSBlbnbDrW8uCmlmKFBIUF9TQVBJIT09
J2NsaScpIHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwNCk7ZXhpdDsgfQppbmlfc2V0KCdkaXNwbGF5
X2Vycm9ycycsJzAnKTsKJG9wdGlvbnM9Z2V0b3B0KCcnLFsncm9vdDonXSk7CiRyb290PXJlYWxw
YXRoKChzdHJpbmcpKCRvcHRpb25zWydyb290J10/P2Rpcm5hbWUoX19ESVJfXykpKTsKaWYoISRy
b290IHx8ICFpc19maWxlKCRyb290LicvYXBwL21vZGVscy9Db3JyZW9Db252b2NhdG9yaWFDb25z
dWx0YU1vZGVsLnBocCcpKSB7IGZ3cml0ZShTVERFUlIsIkZJTFRST1NfQ09ESUdPX05PX0lOU1RB
TEFET1xuIik7ZXhpdCgyKTsgfQp0cnkgewogICAgcmVxdWlyZV9vbmNlICRyb290LicvYXBwL21v
ZGVscy9Db3JyZW9Db252b2NhdG9yaWFDb25zdWx0YU1vZGVsLnBocCc7CiAgICAkZGI9KG5ldyBE
YXRhYmFzZSgpKS0+Y29ubmVjdCgpOwogICAgJGNhdGFsb2c9KG5ldyBDb3JyZW9Db252b2NhdG9y
aWFDb25zdWx0YU1vZGVsKCRkYikpLT5jYXRhbG9nbygpOwogICAgJG91dD1bJ3ZlcnNpb25fZGlh
Z25vc3RpY28nPT4nZmlsdHJvcy1jb3JyZW8tdjInLCdmdWVudGUnPT4nYmFzZV9kZV9kYXRvc19k
ZV9sYV9yYWl6X2luZGljYWRhJywKICAgICAgICAnZXN0YWRvcyc9PmNvdW50KCRjYXRhbG9nWydl
c3RhZG9zJ10pLCdjb252b2NhdG9yaWFzX2FjdGl2YXMnPT5jb3VudCgkY2F0YWxvZ1snY29udm9j
YXRvcmlhcyddKSwKICAgICAgICAnY29udmVuaW9zX2NhdGFsb2dvJz0+aXNzZXQoJGNhdGFsb2db
J2NvbnZlbmlvcyddKT9jb3VudCgkY2F0YWxvZ1snY29udmVuaW9zJ10pOm51bGwsJ2F2aXNvcyc9
PiRjYXRhbG9nWydhdmlzb3MnXT8/W10sCiAgICAgICAgJ2NvbnZvY2F0b3JpYXNfc2luX2VzdGFk
b3MnPT5jb3VudChhcnJheV9maWx0ZXIoJGNhdGFsb2dbJ2NvbnZvY2F0b3JpYXMnXSxzdGF0aWMg
Zm4oJHIpPT4hJHJbJ2VzdGFkb3MnXSkpLAogICAgICAgICdjb252b2NhdG9yaWFzX3Npbl9jb252
ZW5pbyc9PmNvdW50KGFycmF5X2ZpbHRlcigkY2F0YWxvZ1snY29udm9jYXRvcmlhcyddLHN0YXRp
YyBmbigkcik9PiEkclsnY29udmVuaW9zJ10pKV07CiAgICAvLyBDb21wYXRpYmxlIHRhbWJpw6lu
IGNvbiB2MSBpbnN0YWxhZGE6IGNvbXByb2JhciBpbmRlcGVuZGllbnRlbWVudGUgZWwgY2F0w6Fs
b2dvIGRlIGFsaWFkb3MuCiAgICB0cnkgewogICAgICAgICRyPSRkYi0+cXVlcnkoJ1NFTEVDVCBD
T1VOVChESVNUSU5DVCBzLmlkKSBBUyB0b3RhbCBGUk9NIGFsaWFkb3NfYXNpZ25hY2lvbmVzIGEg
SU5ORVIgSk9JTiBzZWd1aW1pZW50b3NfdmluY3VsYWNpb24gcyBPTiBzLmlkID0gYS5zZWd1aW1p
ZW50b19pZCBJTk5FUiBKT0lOIGVzdGFkb3MgZSBPTiBlLmlkID0gcy5lc3RhZG9faWQgV0hFUkUg
YS5hY3Rpdm8gPSAxIEFORCBzLmFjdGl2byA9IDEnKTsKICAgICAgICAkb3V0Wydjb252ZW5pb3Nf
YWN0aXZvc19lbl9mdWVudGUnXT0oaW50KSRyLT5mZXRjaF9hc3NvYygpWyd0b3RhbCddOwogICAg
fSBjYXRjaChUaHJvd2FibGUgJGUpIHsgJG91dFsnY29udmVuaW9zX2FjdGl2b3NfZW5fZnVlbnRl
J109bnVsbDskb3V0Wydjb2RpZ29fY29uc3VsdGFfY29udmVuaW9zJ109KGludCkkZS0+Z2V0Q29k
ZSgpOyB9CiAgICBmb3JlYWNoKFsnYXBwL21vZGVscy9Db3JyZW9Db252b2NhdG9yaWFDb25zdWx0
YU1vZGVsLnBocCcsJ3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb19jb252b2NhdG9yaWFzLmpzJywn
cHVibGljL2phdmFzY3JpcHQvc2VsZWN0b3Jlc191bmlmb3JtZXMuanMnXSBhcyAkZmlsZSkgewog
ICAgICAgICRvdXRbJ2NvZGlnb19zaGEyNTYnXVskZmlsZV09aXNfZmlsZSgkcm9vdC4nLycuJGZp
bGUpP2hhc2hfZmlsZSgnc2hhMjU2Jywkcm9vdC4nLycuJGZpbGUpOidBVVNFTlRFJzsKICAgIH0K
ICAgIGVjaG8ganNvbl9lbmNvZGUoJG91dCxKU09OX1BSRVRUWV9QUklOVHxKU09OX1VORVNDQVBF
RF9VTklDT0RFKS5QSFBfRU9MOwogICAgZWNobyAiU09MTyBMRUNUVVJBLiBTaW4gbm9tYnJlcyBk
ZSBjb250YWN0b3MsIGNyZWRlbmNpYWxlcywgZW52w61vcywgY2FtYmlvcyBkZSBkYXRvcyBuaSBy
ZWN1cGVyYWNpw7NuLlxuIjsKfSBjYXRjaChUaHJvd2FibGUgJGVycm9yKSB7CiAgICBmd3JpdGUo
U1RERVJSLCdGSUxUUk9TX0RJQUdOT1NUSUNPX0VSUk9SIGNsYXNlPScuZ2V0X2NsYXNzKCRlcnJv
cikuJyBjb2RpZ289Jy4oaW50KSRlcnJvci0+Z2V0Q29kZSgpLiIuIFNpbiBkYXRvcyBwcml2YWRv
cy5cbiIpO2V4aXQoMik7Cn0K
CONVOCATORIA_HELPER_0
 [[ "$(digest "$TMP/stage/tools/diagnosticar_filtros_correo.php")" == '1a6ec04652cd2907694544e058d1d7a54fe2eae7de3d79e07509fe762b5e9307' ]] || fail 'Dependencia alterada.'
 if [[ "${BEFORE["tools/diagnosticar_filtros_correo.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/tools/diagnosticar_filtros_correo.php")"
  [[ "$existing" == '1a6ec04652cd2907694544e058d1d7a54fe2eae7de3d79e07509fe762b5e9307' ]] || fail 'Dependencia personalizada: tools/diagnosticar_filtros_correo.php. SITIO INTACTO.'
  cp -p -- "$LIVE/tools/diagnosticar_filtros_correo.php" "$TMP/stage/tools/diagnosticar_filtros_correo.php"
 else chmod 0644 "$TMP/stage/tools/diagnosticar_filtros_correo.php";fi
 declare -A EOLS=()
 base64 --decode > "$TMP/feature-0.patch" <<'CONVOCATORIA_PATCH_0'
ZGlmZiAtLWdpdCBhL2FwcC9tb2RlbHMvQ29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbC5w
aHAgYi9hcHAvbW9kZWxzL0NvcnJlb0NvbnZvY2F0b3JpYUNvbnN1bHRhTW9kZWwucGhwCi0tLSBh
L2FwcC9tb2RlbHMvQ29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbC5waHAKKysrIGIvYXBw
L21vZGVscy9Db3JyZW9Db252b2NhdG9yaWFDb25zdWx0YU1vZGVsLnBocApAQCAtNTMsOCArNTMs
MjcgQEAKICAgICAgICAgJHN0YXRlcz0kdGhpcy0+ZmlsYXMoJ1NFTEVDVCBpZCwgbm9tYnJlIEZS
T00gZXN0YWRvcyBXSEVSRSBlc3RhZG8gPSAxIE9SREVSIEJZIG5vbWJyZSwgaWQnKTsKICAgICAg
ICAgJHJvd3M9JHRoaXMtPmNvbXBsZXRhcigkdGhpcy0+ZmlsYXMoJ1NFTEVDVCBpZCwgdGl0dWxv
LCBpbWFnZW4sIGVzdGFkbyBGUk9NIGNvbnZvY2F0b3JpYXMgV0hFUkUgZXN0YWRvID0gMSBPUkRF
UiBCWSB0aXR1bG8sIGlkJykpOwogICAgICAgICBmb3JlYWNoKCRyb3dzIGFzICYkcm93KSB7IHVu
c2V0KCRyb3dbJ2ltYWdlbl9yZWYnXSk7IH11bnNldCgkcm93KTsKLSAgICAgICAgcmV0dXJuIFsn
ZXN0YWRvcyc9PmFycmF5X21hcChzdGF0aWMgZm4oJHIpPT5bJ2lkJz0+KGludCkkclsnaWQnXSwn
bm9tYnJlJz0+KHN0cmluZykkclsnbm9tYnJlJ11dLCRzdGF0ZXMpLCdjb252b2NhdG9yaWFzJz0+
JHJvd3MsCisgICAgICAgICRhZ3JlZW1lbnRzPVtdOyR3YXJuaW5ncz1bXTsKKyAgICAgICAgdHJ5
IHsgJGFncmVlbWVudHM9JHRoaXMtPmxpc3RhckNvbnZlbmlvcygpOyB9CisgICAgICAgIGNhdGNo
KFRocm93YWJsZSAkZXJyb3IpIHsKKyAgICAgICAgICAgIGVycm9yX2xvZygnTUFJTF9DT05WT0NB
VE9SSUFTX0NPTlZFTklPUyBleGNlcHRpb249Jy5nZXRfY2xhc3MoJGVycm9yKS4nIGNvZGU9Jy4o
aW50KSRlcnJvci0+Z2V0Q29kZSgpKTsKKyAgICAgICAgICAgICR3YXJuaW5nc1tdPSdDT05WRU5J
T1NfQ09OU1VMVEFfTk9fRElTUE9OSUJMRSc7CisgICAgICAgIH0KKyAgICAgICAgcmV0dXJuIFsn
ZXN0YWRvcyc9PmFycmF5X21hcChzdGF0aWMgZm4oJHIpPT5bJ2lkJz0+KGludCkkclsnaWQnXSwn
bm9tYnJlJz0+KHN0cmluZykkclsnbm9tYnJlJ11dLCRzdGF0ZXMpLCdjb252b2NhdG9yaWFzJz0+
JHJvd3MsJ2NvbnZlbmlvcyc9PiRhZ3JlZW1lbnRzLCdhdmlzb3MnPT4kd2FybmluZ3MsCiAgICAg
ICAgICAgICAnY29udmVuaW9zX2Rpc3BvbmlibGVzJz0+JHRoaXMtPnRpZW5lUmVsYWNpb25lcygp
XTsKKyAgICB9CisgICAgcHVibGljIGZ1bmN0aW9uIGxpc3RhckNvbnZlbmlvcygpOiBhcnJheQor
ICAgIHsKKyAgICAgICAgLy8gTWlzbWEgZnVlbnRlIHkgcmVnbGFzIGRlIENyZWFyQ29udm9jYXRv
cmlhTW9kZWw6Omxpc3RhckNvbnZlbmlvc0FsaWFkb3MsCisgICAgICAgIC8vIHNpbiBpbnZvY2Fy
IHN1cyBtw6l0b2RvcyBkZSBDUkVBVEUgVEFCTEUgbmkgZXhpZ2lyIHVuYSBjb252b2NhdG9yaWEg
cmVsYWNpb25hZGEuCisgICAgICAgICRyb3dzPSR0aGlzLT5maWxhcygiU0VMRUNUIERJU1RJTkNU
IHMuaWQsIHMubm9tYnJlX2VudGlkYWQgQVMgbm9tYnJlLCBzLmVzdGFkb19pZCwgQ09BTEVTQ0Uo
cC5jb252ZW5pb19yZWZlcmVuY2lhLCAnJykgQVMgcmVmZXJlbmNpYQorICAgICAgICAgICAgRlJP
TSBhbGlhZG9zX2FzaWduYWNpb25lcyBhIElOTkVSIEpPSU4gc2VndWltaWVudG9zX3ZpbmN1bGFj
aW9uIHMgT04gcy5pZCA9IGEuc2VndWltaWVudG9faWQKKyAgICAgICAgICAgIElOTkVSIEpPSU4g
ZXN0YWRvcyBlIE9OIGUuaWQgPSBzLmVzdGFkb19pZAorICAgICAgICAgICAgTEVGVCBKT0lOIHNl
Z3VpbWllbnRvc192aW5jdWxhY2lvbl9wb3N0X2VudmlvIHAgT04gcC5zZWd1aW1pZW50b19pZCA9
IHMuaWQKKyAgICAgICAgICAgIFdIRVJFIGEuYWN0aXZvID0gMSBBTkQgcy5hY3Rpdm8gPSAxIE9S
REVSIEJZIHMubm9tYnJlX2VudGlkYWQsIHMuaWQiKTsKKyAgICAgICAgJHVuaXF1ZT1bXTsKKyAg
ICAgICAgZm9yZWFjaCgkcm93cyBhcyAkcikgeyAkaWQ9KGludCkkclsnaWQnXTskdW5pcXVlWyRp
ZF09WydpZCc9PiRpZCwnbm9tYnJlJz0+KHN0cmluZykkclsnbm9tYnJlJ10sJ2VzdGFkb19pZCc9
PihpbnQpJHJbJ2VzdGFkb19pZCddLCdyZWZlcmVuY2lhJz0+KHN0cmluZykkclsncmVmZXJlbmNp
YSddXTsgfQorICAgICAgICByZXR1cm4gYXJyYXlfdmFsdWVzKCR1bmlxdWUpOwogICAgIH0KICAg
ICBwdWJsaWMgZnVuY3Rpb24gYnVzY2FyKGludCAkaWQpOiA/YXJyYXkKICAgICB7Cg==
CONVOCATORIA_PATCH_0
 [[ "$(digest "$TMP/feature-0.patch")" == '3ba4e6900ffa24040a09d48da6ef6e17e3ba96b918bcaa0c712b705bee8d4da3' ]] || fail 'Parche alterado.'
 f='app/models/CorreoConvocatoriaConsultaModel.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 72c8aea83463af51d24bbc0774b360f6c5349bfd72bc3f21edc7e4bfd01fb6ad) ;;
 4ee715d8d5548482c8037be8b215c98ea37618c4223121d67b162c90348160c0)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-0.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-0.patch")
  case "$(code_digest "$TMP/stage/$f")" in 72c8aea83463af51d24bbc0774b360f6c5349bfd72bc3f21edc7e4bfd01fb6ad) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-1.patch" <<'CONVOCATORIA_PATCH_1'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb19jb252b2NhdG9yaWFzLmpzIGIv
cHVibGljL2phdmFzY3JpcHQvY29ycmVvX2NvbnZvY2F0b3JpYXMuanMKLS0tIGEvcHVibGljL2ph
dmFzY3JpcHQvY29ycmVvX2NvbnZvY2F0b3JpYXMuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQv
Y29ycmVvX2NvbnZvY2F0b3JpYXMuanMKQEAgLTksOSArOSwxMyBAQAogICAgICAgICAoIWVzdGFk
byB8fCByb3cuZXN0YWRvcy5zb21lKGUgPT4gU3RyaW5nKGUuaWQpID09PSBTdHJpbmcoZXN0YWRv
KSkpICYmCiAgICAgICAgICghY29udmVuaW8gfHwgcm93LmNvbnZlbmlvcy5zb21lKGMgPT4gU3Ry
aW5nKGMuaWQpID09PSBTdHJpbmcoY29udmVuaW8pKSk7CiAgICAgY29uc3QgZmlsdHJhciA9IChy
b3dzLCBlc3RhZG8sIGNvbnZlbmlvKSA9PiByb3dzLmZpbHRlcihyb3cgPT4gY29pbmNpZGUocm93
LCBlc3RhZG8sIGNvbnZlbmlvKSk7Ci0gICAgY29uc3QgY29udmVuaW9zID0gKHJvd3MsIGVzdGFk
bykgPT4geworICAgIGNvbnN0IGNvbnZlbmlvcyA9IChyb3dzLCBlc3RhZG8sIGNhdGFsb2dvKSA9
PiB7CiAgICAgICAgIGNvbnN0IHVuaXF1ZSA9IG5ldyBNYXAoKTsKLSAgICAgICAgZmlsdHJhcihy
b3dzLCBlc3RhZG8sICcnKS5mb3JFYWNoKHJvdyA9PiByb3cuY29udmVuaW9zLmZvckVhY2goYyA9
PiB1bmlxdWUuc2V0KFN0cmluZyhjLmlkKSwgYykpKTsKKyAgICAgICAgY29uc3QgcmVsYWNpb25h
ZG9zID0gbmV3IFNldChmaWx0cmFyKHJvd3MsIGVzdGFkbywgJycpLmZsYXRNYXAocm93ID0+IHJv
dy5jb252ZW5pb3MubWFwKGMgPT4gU3RyaW5nKGMuaWQpKSkpOworICAgICAgICBjb25zdCBkaXNw
b25pYmxlcyA9IEFycmF5LmlzQXJyYXkoY2F0YWxvZ28pID8gY2F0YWxvZ28gOiByb3dzLmZsYXRN
YXAocm93ID0+IHJvdy5jb252ZW5pb3MpOworICAgICAgICBkaXNwb25pYmxlcy5mb3JFYWNoKGMg
PT4geworICAgICAgICAgICAgaWYgKCFlc3RhZG8gfHwgU3RyaW5nKGMuZXN0YWRvX2lkKSA9PT0g
U3RyaW5nKGVzdGFkbykgfHwgcmVsYWNpb25hZG9zLmhhcyhTdHJpbmcoYy5pZCkpKSB7IHVuaXF1
ZS5zZXQoU3RyaW5nKGMuaWQpLCBjKTsgfQorICAgICAgICB9KTsKICAgICAgICAgcmV0dXJuIEFy
cmF5LmZyb20odW5pcXVlLnZhbHVlcygpKS5zb3J0KChhLCBiKSA9PiBhLm5vbWJyZS5sb2NhbGVD
b21wYXJlKGIubm9tYnJlLCAnZXMnKSB8fCBhLmlkIC0gYi5pZCk7CiAgICAgfTsKICAgICBjb25z
dCBldGlxdWV0YSA9IHJvdyA9PiBbcm93LnRpdHVsbywgcm93LmNvbnZlbmlvcy5tYXAoYyA9PiBj
Lm5vbWJyZSkuam9pbignLCAnKSwgcm93LmVzdGFkb3MubWFwKGUgPT4gZS5ub21icmUpLmpvaW4o
JywgJyksICcjJyArIHJvdy5pZF0uZmlsdGVyKEJvb2xlYW4pLmpvaW4oJyDCtyAnKTsKQEAgLTI5
LDE0ICszMyw0NSBAQAogICAgICAgICBjb25zdCBpbWFnZSA9IHEoJ1tkYXRhLWNvbnZvY2F0b3Jp
YS1pbWFnZW5dJyk7CiAgICAgICAgIGNvbnN0IGNhcHRpb24gPSBxKCdbZGF0YS1jb252b2NhdG9y
aWEtY2FwdGlvbl0nKTsKICAgICAgICAgY29uc3QgZW5kcG9pbnQgPSAnaW5kZXgucGhwP2NvbnRy
b2xsZXI9Y29ycmVvTWFya2V0aW5nJmFjdGlvbj1jb252b2NhdG9yaWFzJzsKLSAgICAgICAgbGV0
IHJvd3MgPSBbXSwgY2F0YWxvZyA9IG51bGwsIHNlcXVlbmNlID0gMCwgYWJvcnQgPSBudWxsLCBs
b2FkaW5nID0gZmFsc2UsIHJlYWR5ID0gZmFsc2U7CisgICAgICAgIGxldCByb3dzID0gW10sIGFn
cmVlbWVudHMgPSBudWxsLCBjYXRhbG9nID0gbnVsbCwgc2VxdWVuY2UgPSAwLCBhYm9ydCA9IG51
bGwsIGxvYWRpbmcgPSBmYWxzZSwgcmVhZHkgPSBmYWxzZTsKICAgICAgICAgY29uc3Qgb3B0aW9u
ID0gKHZhbHVlLCBsYWJlbCkgPT4gewogICAgICAgICAgICAgY29uc3QgbyA9IGRvYy5jcmVhdGVF
bGVtZW50KCdvcHRpb24nKTsgby52YWx1ZSA9IFN0cmluZyh2YWx1ZSk7IG8udGV4dENvbnRlbnQg
PSBsYWJlbDsgcmV0dXJuIG87CisgICAgICAgIH07CisgICAgICAgIC8vIEVsIGFjYWJhZG8gZ2xv
YmFsIGNsb25hIGxhcyBvcGNpb25lcyB1bmEgc29sYSB2ZXouIEFjdHVhbGl6YXIgw7puaWNhbWVu
dGUKKyAgICAgICAgLy8gbG9zIHRyZXMgbWVuw7pzIGRlIGNvbnZvY2F0b3JpYXMsIGNvbnNlcnZh
bmRvIHN1cyBjbGFzZXMgeSBlbCBzZWxlY3Qgb3JpZ2luYWwuCisgICAgICAgIGNvbnN0IHVuaWZv
cm0gPSBmaWVsZCA9PiB7CisgICAgICAgICAgICBjb25zdCB3cmFwcGVyID0gZmllbGQubmV4dEVs
ZW1lbnRTaWJsaW5nOworICAgICAgICAgICAgaWYgKCF3cmFwcGVyPy5tYXRjaGVzPy4oJy51bmlm
b3JtLXNlbGVjdCcpKSB7IHJldHVybjsgfQorICAgICAgICAgICAgY29uc3QgbWVudSA9IHdyYXBw
ZXIucXVlcnlTZWxlY3RvcignLnVuaWZvcm0tc2VsZWN0LW1lbnUnKTsKKyAgICAgICAgICAgIGNv
bnN0IHRyaWdnZXIgPSB3cmFwcGVyLnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLXVuaWZvcm0tc2VsZWN0
LXRyaWdnZXJdJyk7CisgICAgICAgICAgICBjb25zdCBsYWJlbCA9IHdyYXBwZXIucXVlcnlTZWxl
Y3RvcignW2RhdGEtdW5pZm9ybS1zZWxlY3QtbGFiZWxdJyk7CisgICAgICAgICAgICBpZiAoIW1l
bnUgfHwgIXRyaWdnZXIgfHwgIWxhYmVsKSB7IHJldHVybjsgfQorICAgICAgICAgICAgY29uc3Qg
YnV0dG9ucyA9IEFycmF5LmZyb20oZmllbGQub3B0aW9ucywgKG8sIGluZGV4KSA9PiB7CisgICAg
ICAgICAgICAgICAgY29uc3QgYnV0dG9uID0gZG9jLmNyZWF0ZUVsZW1lbnQoJ2J1dHRvbicpOwor
ICAgICAgICAgICAgICAgIGJ1dHRvbi50eXBlID0gJ2J1dHRvbic7IGJ1dHRvbi5jbGFzc05hbWUg
PSAndW5pZm9ybS1zZWxlY3Qtb3B0aW9uJzsKKyAgICAgICAgICAgICAgICBidXR0b24uc2V0QXR0
cmlidXRlKCdyb2xlJywgJ29wdGlvbicpOyBidXR0b24uc2V0QXR0cmlidXRlKCdkYXRhLXVuaWZv
cm0tc2VsZWN0LXZhbHVlJywgby52YWx1ZSk7CisgICAgICAgICAgICAgICAgYnV0dG9uLmRhdGFz
ZXQudW5pZm9ybVNlbGVjdEluZGV4ID0gU3RyaW5nKGluZGV4KTsgYnV0dG9uLnRleHRDb250ZW50
ID0gby50ZXh0Q29udGVudDsKKyAgICAgICAgICAgICAgICBidXR0b24uZGlzYWJsZWQgPSBCb29s
ZWFuKG8uZGlzYWJsZWQpOyBidXR0b24uaGlkZGVuID0gQm9vbGVhbihvLmhpZGRlbik7CisgICAg
ICAgICAgICAgICAgYnV0dG9uLmNsYXNzTGlzdC50b2dnbGUoJ2lzLXNlbGVjdGVkJywgaW5kZXgg
PT09IGZpZWxkLnNlbGVjdGVkSW5kZXgpOworICAgICAgICAgICAgICAgIGJ1dHRvbi5zZXRBdHRy
aWJ1dGUoJ2FyaWEtc2VsZWN0ZWQnLCBpbmRleCA9PT0gZmllbGQuc2VsZWN0ZWRJbmRleCA/ICd0
cnVlJyA6ICdmYWxzZScpOworICAgICAgICAgICAgICAgIGJ1dHRvbi5hZGRFdmVudExpc3RlbmVy
KCdjbGljaycsICgpID0+IHsKKyAgICAgICAgICAgICAgICAgICAgaWYgKGZpZWxkLmRpc2FibGVk
IHx8IGJ1dHRvbi5kaXNhYmxlZCkgeyByZXR1cm47IH0KKyAgICAgICAgICAgICAgICAgICAgY29u
c3QgY2hhbmdlZCA9IGZpZWxkLnNlbGVjdGVkSW5kZXggIT09IGluZGV4OyBmaWVsZC5zZWxlY3Rl
ZEluZGV4ID0gaW5kZXg7CisgICAgICAgICAgICAgICAgICAgIHVuaWZvcm0oZmllbGQpOworICAg
ICAgICAgICAgICAgICAgICBpZiAoY2hhbmdlZCkgeyBmaWVsZC5kaXNwYXRjaEV2ZW50KG5ldyBF
dmVudCgnY2hhbmdlJywgeyBidWJibGVzOiB0cnVlIH0pKTsgfQorICAgICAgICAgICAgICAgIH0p
OworICAgICAgICAgICAgICAgIHJldHVybiBidXR0b247CisgICAgICAgICAgICB9KTsKKyAgICAg
ICAgICAgIG1lbnUucmVwbGFjZUNoaWxkcmVuKC4uLmJ1dHRvbnMpOworICAgICAgICAgICAgbGFi
ZWwudGV4dENvbnRlbnQgPSBmaWVsZC5vcHRpb25zW2ZpZWxkLnNlbGVjdGVkSW5kZXhdPy50ZXh0
Q29udGVudCB8fCAnJzsKKyAgICAgICAgICAgIHRyaWdnZXIuZGlzYWJsZWQgPSBCb29sZWFuKGZp
ZWxkLmRpc2FibGVkKTsgdHJpZ2dlci5jbGFzc0xpc3QudG9nZ2xlKCdpcy1wbGFjZWhvbGRlcics
ICFmaWVsZC52YWx1ZSk7CisgICAgICAgICAgICB3cmFwcGVyLmNsYXNzTGlzdC5yZW1vdmUoJ2lz
LW9wZW4nKTsgdHJpZ2dlci5zZXRBdHRyaWJ1dGUoJ2FyaWEtZXhwYW5kZWQnLCAnZmFsc2UnKTsK
ICAgICAgICAgfTsKICAgICAgICAgY29uc3Qgb3B0aW9ucyA9IChmaWVsZCwgdmFsdWVzLCBwbGFj
ZWhvbGRlciwgbGFiZWwpID0+IHsKICAgICAgICAgICAgIGNvbnN0IHByaW9yID0gZmllbGQudmFs
dWU7CiAgICAgICAgICAgICBmaWVsZC5yZXBsYWNlQ2hpbGRyZW4ob3B0aW9uKCcnLCBwbGFjZWhv
bGRlciksIC4uLnZhbHVlcy5tYXAociA9PiBvcHRpb24oci5pZCwgbGFiZWwocikpKSk7CiAgICAg
ICAgICAgICBmaWVsZC52YWx1ZSA9IHZhbHVlcy5zb21lKHIgPT4gU3RyaW5nKHIuaWQpID09PSBw
cmlvcikgPyBwcmlvciA6ICcnOworICAgICAgICAgICAgdW5pZm9ybShmaWVsZCk7CiAgICAgICAg
IH07CiAgICAgICAgIGNvbnN0IGNsZWFySW1hZ2UgPSAoKSA9PiB7CiAgICAgICAgICAgICBzZXF1
ZW5jZSsrOyBhYm9ydD8uYWJvcnQoKTsgbG9hZGluZyA9IGZhbHNlOyByZWFkeSA9IGZhbHNlOyBz
aGEudmFsdWUgPSAnJzsKQEAgLTUzLDcgKzg4LDcgQEAKICAgICAgICAgICAgIHN0YXR1cy50ZXh0
Q29udGVudCA9IHJvdz8uaW1hZ2VuX21lbnNhamUgfHwgJyc7CiAgICAgICAgIH07CiAgICAgICAg
IGNvbnN0IGxpc3QgPSAoKSA9PiB7Ci0gICAgICAgICAgICBvcHRpb25zKGFncmVlbWVudCwgY29u
dmVuaW9zKHJvd3MsIHN0YXRlLnZhbHVlKSwgJ1RvZG9zIGxvcyBjb252ZW5pb3MnLCBjID0+IGMu
bm9tYnJlICsgKGMucmVmZXJlbmNpYSA/ICcgwrcgJyArIGMucmVmZXJlbmNpYSA6ICcnKSArICcg
wrcgIycgKyBjLmlkKTsKKyAgICAgICAgICAgIG9wdGlvbnMoYWdyZWVtZW50LCBjb252ZW5pb3Mo
cm93cywgc3RhdGUudmFsdWUsIGFncmVlbWVudHMpLCAnVG9kb3MgbG9zIGNvbnZlbmlvcycsIGMg
PT4gYy5ub21icmUgKyAoYy5yZWZlcmVuY2lhID8gJyDCtyAnICsgYy5yZWZlcmVuY2lhIDogJycp
ICsgJyDCtyAjJyArIGMuaWQpOwogICAgICAgICAgICAgY29uc3QgcHJpb3IgPSBzZWxlY3QudmFs
dWU7CiAgICAgICAgICAgICBjb25zdCBmaWx0ZXJlZCA9IGZpbHRyYXIocm93cywgc3RhdGUudmFs
dWUsIGFncmVlbWVudC52YWx1ZSk7CiAgICAgICAgICAgICBvcHRpb25zKHNlbGVjdCwgZmlsdGVy
ZWQsICdTZWxlY2Npb25hIHVuYSBjb252b2NhdG9yaWEnLCBldGlxdWV0YSk7CkBAIC02MywxOCAr
OTgsMjEgQEAKICAgICAgICAgY29uc3QgbG9hZCA9ICgpID0+IHsKICAgICAgICAgICAgIGlmICgh
Y2F0YWxvZykgewogICAgICAgICAgICAgICAgIHN0YXRlLmRpc2FibGVkID0gYWdyZWVtZW50LmRp
c2FibGVkID0gc2VsZWN0LmRpc2FibGVkID0gdHJ1ZTsKKyAgICAgICAgICAgICAgICBbc3RhdGUs
IGFncmVlbWVudCwgc2VsZWN0XS5mb3JFYWNoKHVuaWZvcm0pOwogICAgICAgICAgICAgICAgIGNh
dGFsb2cgPSByZXF1ZXN0KGVuZHBvaW50LCB7IGhlYWRlcnM6IHsgJ1gtUmVxdWVzdGVkLVdpdGgn
OiAnZmV0Y2gnIH0gfSkudGhlbihhc3luYyByID0+IHsKICAgICAgICAgICAgICAgICAgICAgY29u
c3QgZGF0YSA9IGF3YWl0IHIuanNvbigpOwogICAgICAgICAgICAgICAgICAgICBpZiAoIXIub2sg
fHwgIWRhdGEub2sgfHwgIUFycmF5LmlzQXJyYXkoZGF0YS5jb252b2NhdG9yaWFzKSB8fCAhQXJy
YXkuaXNBcnJheShkYXRhLmVzdGFkb3MpKSB7IHRocm93IG5ldyBFcnJvcignQ2F0w6Fsb2dvIG5v
IGRpc3BvbmlibGUnKTsgfQogICAgICAgICAgICAgICAgICAgICByb3dzID0gZGF0YS5jb252b2Nh
dG9yaWFzOworICAgICAgICAgICAgICAgICAgICBhZ3JlZW1lbnRzID0gQXJyYXkuaXNBcnJheShk
YXRhLmNvbnZlbmlvcykgPyBkYXRhLmNvbnZlbmlvcyA6IG51bGw7CiAgICAgICAgICAgICAgICAg
ICAgIG9wdGlvbnMoc3RhdGUsIGRhdGEuZXN0YWRvcywgJ1RvZG9zIGxvcyBlc3RhZG9zJywgZSA9
PiBlLm5vbWJyZSk7CiAgICAgICAgICAgICAgICAgICAgIGxpc3QoKTsKKyAgICAgICAgICAgICAg
ICAgICAgaWYgKGRhdGEuYXZpc29zPy5pbmNsdWRlcygnQ09OVkVOSU9TX0NPTlNVTFRBX05PX0RJ
U1BPTklCTEUnKSkgeyBzdGF0dXMudGV4dENvbnRlbnQgPSAnTG9zIGVzdGFkb3MgeSBsYXMgY29u
dm9jYXRvcmlhcyBlc3TDoW4gZGlzcG9uaWJsZXM7IGZhbHRhIHJldmlzYXIgbGEgY29uc3VsdGEg
ZGUgY29udmVuaW9zLic7IH0KICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGRhdGE7CiAgICAg
ICAgICAgICAgICAgfSkuY2F0Y2goKCkgPT4gewogICAgICAgICAgICAgICAgICAgICBjYXRhbG9n
ID0gbnVsbDsKICAgICAgICAgICAgICAgICAgICAgc3RhdHVzLnRleHRDb250ZW50ID0gJ05vIGZ1
ZSBwb3NpYmxlIGNhcmdhciBsYXMgY29udm9jYXRvcmlhcy4gUHVlZGVzIHJlZGFjdGFyIHNpbiBz
ZWxlY2Npb25hciB1bmEuJzsKICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG51bGw7Ci0gICAg
ICAgICAgICAgICAgfSkuZmluYWxseSgoKSA9PiB7IHN0YXRlLmRpc2FibGVkID0gYWdyZWVtZW50
LmRpc2FibGVkID0gc2VsZWN0LmRpc2FibGVkID0gZmFsc2U7IH0pOworICAgICAgICAgICAgICAg
IH0pLmZpbmFsbHkoKCkgPT4geyBzdGF0ZS5kaXNhYmxlZCA9IGFncmVlbWVudC5kaXNhYmxlZCA9
IHNlbGVjdC5kaXNhYmxlZCA9IGZhbHNlOyBbc3RhdGUsIGFncmVlbWVudCwgc2VsZWN0XS5mb3JF
YWNoKHVuaWZvcm0pOyB9KTsKICAgICAgICAgICAgIH0KICAgICAgICAgICAgIHJldHVybiBjYXRh
bG9nOwogICAgICAgICB9OwpAQCAtOTQsNyArMTMyLDcgQEAKICAgICAgICAgICAgIH0gZmluYWxs
eSB7IGlmICh2ZXJzaW9uID09PSBzZXF1ZW5jZSkgeyBsb2FkaW5nID0gZmFsc2U7IH0gfQogICAg
ICAgICB9OwogICAgICAgICBjb25zdCByZXNldCA9ICgpID0+IHsKLSAgICAgICAgICAgIGNsZWFy
SW1hZ2UoKTsgc3RhdGUudmFsdWUgPSAnJzsgYWdyZWVtZW50LnZhbHVlID0gJyc7IHNlbGVjdC52
YWx1ZSA9ICcnOyBsaXN0KCk7IHN0YXR1cy50ZXh0Q29udGVudCA9ICcnOworICAgICAgICAgICAg
Y2xlYXJJbWFnZSgpOyBzdGF0ZS52YWx1ZSA9ICcnOyB1bmlmb3JtKHN0YXRlKTsgYWdyZWVtZW50
LnZhbHVlID0gJyc7IHNlbGVjdC52YWx1ZSA9ICcnOyBsaXN0KCk7IHN0YXR1cy50ZXh0Q29udGVu
dCA9ICcnOwogICAgICAgICB9OwogICAgICAgICBtb2RhbC5hZGRFdmVudExpc3RlbmVyKCdzaG93
bi5icy5tb2RhbCcsIGxvYWQpOwogICAgICAgICBtb2RhbC5hZGRFdmVudExpc3RlbmVyKCdjb3Jy
ZW86Y29udm9jYXRvcmlhLWxpbXBpYXInLCByZXNldCk7CkBAIC0xMjQsOCArMTYyLDkgQEAKICAg
ICAgICAgICAgICAgICBjb25zdCBvbGRTdGF0ZSA9IHJvdy5lc3RhZG9zLmZpbmQoZSA9PiBTdHJp
bmcoZS5pZCkgPT09IHN0YXRlSWQpOwogICAgICAgICAgICAgICAgIGlmIChvbGRTdGF0ZSkgeyBz
dGF0ZS5hcHBlbmRDaGlsZChvcHRpb24ob2xkU3RhdGUuaWQsIG9sZFN0YXRlLm5vbWJyZSkpOyB9
CiAgICAgICAgICAgICB9Ci0gICAgICAgICAgICBzdGF0ZS52YWx1ZSA9IHN0YXRlSWQ7IGFncmVl
bWVudC52YWx1ZSA9ICcnOyBsaXN0KCk7CisgICAgICAgICAgICBzdGF0ZS52YWx1ZSA9IHN0YXRl
SWQ7IHVuaWZvcm0oc3RhdGUpOyBhZ3JlZW1lbnQudmFsdWUgPSAnJzsgbGlzdCgpOwogICAgICAg
ICAgICAgYWdyZWVtZW50LnZhbHVlID0gU3RyaW5nKHJvdy5maWx0cm9fY29udmVuaW8gfHwgJycp
OyBsaXN0KCk7IHNlbGVjdC52YWx1ZSA9IFN0cmluZyhyb3cuaWQpOworICAgICAgICAgICAgdW5p
Zm9ybShzZWxlY3QpOwogICAgICAgICAgICAgc2hvd0ltYWdlKHJvdyk7IHJlYWR5ID0gdHJ1ZTsK
ICAgICAgICAgfSk7CiAgICAgICAgIGNvbnN0IGRldGFpbCA9IGRvYy5nZXRFbGVtZW50QnlJZCgn
bW9kYWxDb3JyZW9NYXJrZXRpbmdEZXRhbGxlJyk7Cg==
CONVOCATORIA_PATCH_1
 [[ "$(digest "$TMP/feature-1.patch")" == 'ca3839b772901d35343b458573df5112269d7cce81e61cd6c8056645a6382209' ]] || fail 'Parche alterado.'
 f='public/javascript/correo_convocatorias.js'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 9039c2d1ea8252218cd11344f2d12b39a1bdaf3b167847c69e01e40124dc2e41) ;;
 5054faaed67f167a19fc24f09ad2a2034f901c7413938f96d55510ed03fecc1e)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-1.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-1.patch")
  case "$(code_digest "$TMP/stage/$f")" in 9039c2d1ea8252218cd11344f2d12b39a1bdaf3b167847c69e01e40124dc2e41) ;; *) fail "Resultado inesperado: $f";; esac ;;
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
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/filtros-correo-$(date +%Y%m%d-%H%M%S)-$$";mkdir -m 700 "$BACKUP"
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
