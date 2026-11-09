#!/usr/bin/env bash
# Filtros e imagen de convocatoria. Sin cambios de transporte, SQL ni credenciales.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='enlace-convocatoria-v1-478d1c4c50f5a950'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/services/CorreoConvocatoriaComposicion.php'
DIAGNOSTIC='tools/diagnosticar_hostinger_comercial.php'
FILES=("app/services/CorreoConvocatoriaEnlace.php" "tools/diagnosticar_enlace_convocatoria.php" "app/models/CorreoConvocatoriaConsultaModel.php" "app/services/CorreoConvocatoriaComposicion.php" "public/javascript/correo_convocatorias.js" "app/views/correos/index.php")
PATCH_FILES=("app/models/CorreoConvocatoriaConsultaModel.php" "app/services/CorreoConvocatoriaComposicion.php" "public/javascript/correo_convocatorias.js" "app/views/correos/index.php")
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
FILES=("app/views/correos/index.php" "public/javascript/correo_convocatorias.js" "app/services/CorreoConvocatoriaComposicion.php" "app/models/CorreoConvocatoriaConsultaModel.php" "tools/diagnosticar_enlace_convocatoria.php" "app/services/CorreoConvocatoriaEnlace.php")
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
 base64 --decode > "$TMP/stage/app/services/CorreoConvocatoriaEnlace.php" <<'CONVOCATORIA_HELPER_0'
PD9waHAKCi8qKiBTw7NsbyB2YWxpZGEvcHJlc2VudGEgbGEgVVJMIHlhIGd1YXJkYWRhLiBObyBn
ZW5lcmEgZW5sYWNlcyBuaSBjb25zdWx0YSBmb3JtdWxhcmlvcy4gKi8KZmluYWwgY2xhc3MgQ29y
cmVvQ29udm9jYXRvcmlhRW5sYWNlCnsKICAgIHB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gb2J0ZW5l
cihhcnJheSAkcm93LD9zdHJpbmcgJGJhc2U9bnVsbCk6ID9hcnJheQogICAgewogICAgICAgICR1
cmw9JHJvd1snZW5sYWNlX3JlZiddPz8nJzsKICAgICAgICAkYmFzZT8/PWRlZmluZWQoJ0JBU0Vf
VVJMJyk/KHN0cmluZylCQVNFX1VSTDonJzsKICAgICAgICBpZighaXNfc3RyaW5nKCR1cmwpIHx8
ICR1cmw9PT0nJyB8fCBzdHJsZW4oJHVybCk+MjA0OCB8fCBwcmVnX21hdGNoKCcvW1x4MDAtXHgy
MFx4N2ZdfCUwW2FkXXwlMDAvaScsJHVybCkgfHwgIWZpbHRlcl92YXIoJHVybCxGSUxURVJfVkFM
SURBVEVfVVJMKSkgeyByZXR1cm4gbnVsbDsgfQogICAgICAgICRwPXBhcnNlX3VybCgkdXJsKTsK
ICAgICAgICBpZighJHAgfHwgaXNzZXQoJHBbJ3VzZXInXSkgfHwgaXNzZXQoJHBbJ3Bhc3MnXSkg
fHwgaXNzZXQoJHBbJ2ZyYWdtZW50J10pKSB7IHJldHVybiBudWxsOyB9CiAgICAgICAgLy8gUmV1
dGlsaXphciBsYSBsaXN0YSBjZXJyYWRhIGRlIGhvc3RzIHDDumJsaWNvcyBxdWUgeWEgdXRpbGl6
YSBCQVNFX1VSTC4KICAgICAgICAkdHJ1c3RlZD1mdW5jdGlvbl9leGlzdHMoJ2ltcGVSZXNvbHZl
ckJhc2VVcmxIb3N0aW5nZXInKT9pbXBlUmVzb2x2ZXJCYXNlVXJsSG9zdGluZ2VyKCRiYXNlLHN0
cnRvbG93ZXIoJHBbJ2hvc3QnXT8/JycpKTokYmFzZTsKICAgICAgICAkYj1wYXJzZV91cmwoJHRy
dXN0ZWQpOwogICAgICAgIGlmKCEkYiB8fCBlbXB0eSgkYlsnaG9zdCddKSB8fCBzdHJ0b2xvd2Vy
KCRwWydob3N0J10/PycnKSE9PXN0cnRvbG93ZXIoJGJbJ2hvc3QnXSkgfHwgc3RydG9sb3dlcigk
cFsnc2NoZW1lJ10/PycnKSE9PXN0cnRvbG93ZXIoJGJbJ3NjaGVtZSddPz8nJykgfHwKICAgICAg
ICAgICAgKGludCkoJHBbJ3BvcnQnXT8/KHN0cnRvbG93ZXIoJHBbJ3NjaGVtZSddPz8nJyk9PT0n
aHR0cHMnPzQ0Mzo4MCkpIT09KGludCkoJGJbJ3BvcnQnXT8/KHN0cnRvbG93ZXIoJGJbJ3NjaGVt
ZSddPz8nJyk9PT0naHR0cHMnPzQ0Mzo4MCkpIHx8CiAgICAgICAgICAgICgkcFsncGF0aCddPz8n
JykhPT1ydHJpbSgkYlsncGF0aCddPz8nJywnLycpLicvaW5kZXgucGhwJykgeyByZXR1cm4gbnVs
bDsgfQogICAgICAgIGlmKHN0cnRvbG93ZXIoJHBbJ3NjaGVtZSddPz8nJykhPT0naHR0cHMnICYm
ICFpbl9hcnJheShzdHJ0b2xvd2VyKCRiWydob3N0J10pLFsnbG9jYWxob3N0JywnMTI3LjAuMC4x
JywnWzo6MV0nXSx0cnVlKSkgeyByZXR1cm4gbnVsbDsgfQogICAgICAgICRwYXJhbXM9W107CiAg
ICAgICAgZm9yZWFjaChleHBsb2RlKCcmJywkcFsncXVlcnknXT8/JycpIGFzICRwYXJ0KSB7CiAg
ICAgICAgICAgICRwYWlyPWV4cGxvZGUoJz0nLCRwYXJ0LDIpOyRrZXk9dXJsZGVjb2RlKCRwYWly
WzBdKTsKICAgICAgICAgICAgaWYoY291bnQoJHBhaXIpIT09MiB8fCAhaW5fYXJyYXkoJGtleSxb
J2NvbnRyb2xsZXInLCdhY3Rpb24nLCdhbGlhZG8nXSx0cnVlKSB8fCBhcnJheV9rZXlfZXhpc3Rz
KCRrZXksJHBhcmFtcykpIHsgcmV0dXJuIG51bGw7IH0KICAgICAgICAgICAgJHBhcmFtc1ska2V5
XT11cmxkZWNvZGUoJHBhaXJbMV0pOwogICAgICAgIH0KICAgICAgICBpZigoJHBhcmFtc1snY29u
dHJvbGxlciddPz8nJykhPT0nZm9ybXVsYXJpb1B1YmxpY28nIHx8ICFpbl9hcnJheSgkcGFyYW1z
WydhY3Rpb24nXT8/JycsWydyZWdpc3RybycsJ2luc2NyaXBjaW9uJ10sdHJ1ZSkgfHwKICAgICAg
ICAgICAgKGlzc2V0KCRwYXJhbXNbJ2FsaWFkbyddKSAmJiAhcHJlZ19tYXRjaCgnL15bYS1mMC05
XXs2NH0kL0QnLCRwYXJhbXNbJ2FsaWFkbyddKSkpIHsgcmV0dXJuIG51bGw7IH0KICAgICAgICAk
cmVnaXN0cmF0aW9uPSRwYXJhbXNbJ2FjdGlvbiddPT09J3JlZ2lzdHJvJzsKICAgICAgICByZXR1
cm4gWyd1cmwnPT4kdXJsLCd0aXBvJz0+JHBhcmFtc1snYWN0aW9uJ10sJ3RpdHVsbyc9PiRyZWdp
c3RyYXRpb24/J0Zvcm11bGFyaW8gZGUgUmVnaXN0cm8nOidGb3JtdWxhcmlvIGRlIEluc2NyaXBj
acOzbicsCiAgICAgICAgICAgICd0ZXh0byc9PiRyZWdpc3RyYXRpb24/J0NvbnN1bHRhIGxhIGNv
bnZvY2F0b3JpYSB5IGNvbXBsZXRhIHR1IHJlZ2lzdHJvIGVuIGVsIHNpZ3VpZW50ZSBlbmxhY2U6
JzonQ29uc3VsdGEgbGEgY29udm9jYXRvcmlhIHkgY29tcGxldGEgdHUgaW5zY3JpcGNpw7NuIGVu
IGVsIHNpZ3VpZW50ZSBlbmxhY2U6JywKICAgICAgICAgICAgJ2V0aXF1ZXRhJz0+JHJlZ2lzdHJh
dGlvbj8nQWNjZWRlciBhbCBmb3JtdWxhcmlvIGRlIHJlZ2lzdHJvJzonQWNjZWRlciBhbCBmb3Jt
dWxhcmlvIGRlIGluc2NyaXBjacOzbiddOwogICAgfQogICAgcHVibGljIHN0YXRpYyBmdW5jdGlv
biBodG1sKGFycmF5ICRsaW5rKTogc3RyaW5nCiAgICB7CiAgICAgICAgJGVzY2FwZT1zdGF0aWMg
Zm4oJHZhbHVlKT0+aHRtbHNwZWNpYWxjaGFycygkdmFsdWUsRU5UX1FVT1RFU3xFTlRfU1VCU1RJ
VFVURSwnVVRGLTgnKTsKICAgICAgICByZXR1cm4gJzxkaXYgc3R5bGU9Im1hcmdpbi10b3A6MThw
eCI+PHN0cm9uZz4nLiRlc2NhcGUoJGxpbmtbJ3RpdHVsbyddKS4nPC9zdHJvbmc+PHA+Jy4kZXNj
YXBlKCRsaW5rWyd0ZXh0byddKS4nPC9wPjxhIGhyZWY9IicuJGVzY2FwZSgkbGlua1sndXJsJ10p
LiciPicuJGVzY2FwZSgkbGlua1snZXRpcXVldGEnXSkuJzwvYT48L2Rpdj4nOwogICAgfQp9Cg==
CONVOCATORIA_HELPER_0
 [[ "$(digest "$TMP/stage/app/services/CorreoConvocatoriaEnlace.php")" == 'bc22e72d386b99967384fa16eedfa6c3da1253d489d09700a2eb57b7e7424ebb' ]] || fail 'Dependencia alterada.'
 if [[ "${BEFORE["app/services/CorreoConvocatoriaEnlace.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoConvocatoriaEnlace.php")"
  [[ "$existing" == 'bc22e72d386b99967384fa16eedfa6c3da1253d489d09700a2eb57b7e7424ebb' ]] || fail 'Dependencia personalizada: app/services/CorreoConvocatoriaEnlace.php. SITIO INTACTO.'
  cp -p -- "$LIVE/app/services/CorreoConvocatoriaEnlace.php" "$TMP/stage/app/services/CorreoConvocatoriaEnlace.php"
 else chmod 0644 "$TMP/stage/app/services/CorreoConvocatoriaEnlace.php";fi
 base64 --decode > "$TMP/stage/tools/diagnosticar_enlace_convocatoria.php" <<'CONVOCATORIA_HELPER_1'
PD9waHAKLy8gU8OzbG8gU0VMRUNUIHkgdmFsaWRhY2nDs24gbG9jYWwuIE5vIGdlbmVyYSBlbmxh
Y2VzIG5pIGNvbnN1bHRhL2VudsOtYSBhbCBwcm92ZWVkb3IuCmlmKFBIUF9TQVBJIT09J2NsaScp
IHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwNCk7ZXhpdDsgfQppbmlfc2V0KCdkaXNwbGF5X2Vycm9y
cycsJzAnKTsKJG9wdGlvbnM9Z2V0b3B0KCcnLFsnY29udm9jYXRvcmlhOicsJ2Jhc2U6Jywncm9v
dDonXSk7CiRyb290PXJlYWxwYXRoKChzdHJpbmcpKCRvcHRpb25zWydyb290J10/P2Rpcm5hbWUo
X19ESVJfXykpKTsKdHJ5IHsKICAgIGlmKCEkcm9vdCkgeyB0aHJvdyBuZXcgSW52YWxpZEFyZ3Vt
ZW50RXhjZXB0aW9uKCdSQUlaX0lOVkFMSURBJyk7IH0KICAgIHJlcXVpcmVfb25jZSAkcm9vdC4n
L2FwcC9zZXJ2aWNlcy9Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbi5waHAnOwogICAgJGlk
PUNvcnJlb0NvbnZvY2F0b3JpYUNvbXBvc2ljaW9uOjppZCgkb3B0aW9uc1snY29udm9jYXRvcmlh
J10/PzExKTsKICAgICRtb2RlbD1uZXcgQ29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbCgp
OyRyb3c9JG1vZGVsLT5idXNjYXIoJGlkKTsKICAgIGlmKCEkcm93KSB7IHRocm93IG5ldyBSdW50
aW1lRXhjZXB0aW9uKCdDT05WT0NBVE9SSUFfTk9fRVhJU1RFJyk7IH0KICAgIC8vIFJlZmVyZW5j
aWEgZGUgdmFsaWRhY2nDs24gQ0xJOyBubyBjYW1iaWEgbGEgVVJMIGd1YXJkYWRhIG5pIGxhIGNv
bmZpZ3VyYWNpw7NuIHdlYi4KICAgICRiYXNlPShzdHJpbmcpKCRvcHRpb25zWydiYXNlJ10/Pydo
dHRwczovL2Z1bmRhY2lvbnJlZGVkdW1leC5vcmcvJyk7CiAgICAkbGluaz1Db3JyZW9Db252b2Nh
dG9yaWFFbmxhY2U6Om9idGVuZXIoJHJvdywkYmFzZSk7CiAgICAkaW1hZ2U9KG5ldyBDb3JyZW9D
b252b2NhdG9yaWFDb21wb3NpY2lvbigkbW9kZWwsJHJvb3QpKS0+aW1hZ2VuKCRyb3cpOwogICAg
ZWNobyBqc29uX2VuY29kZShbJ3ZlcnNpb24nPT4nY29udm9jYXRvcmlhLWVubGFjZS12MScsJ2lk
Jz0+JGlkLCdhY3RpdmEnPT4kcm93WydhY3RpdmEnXSwnaW1hZ2VuX3ZhbGlkYSc9Pihib29sKSRp
bWFnZSwKICAgICAgICAnZW5sYWNlX2d1YXJkYWRvJz0+IWVtcHR5KCRyb3dbJ2VubGFjZV9yZWYn
XSksJ2VubGFjZV9kaXNwb25pYmxlJz0+KGJvb2wpJGxpbmssJ3RpcG9fZm9ybXVsYXJpbyc9PiRs
aW5rWyd0aXBvJ10/P251bGwsCiAgICAgICAgJ3VybF9jb25zZXJ2YWRhJz0+JGxpbms/aGFzaF9l
cXVhbHMoJHJvd1snZW5sYWNlX3JlZiddLCRsaW5rWyd1cmwnXSk6bnVsbCwKICAgICAgICAnc2hh
MjU2X3VybCc9PiRsaW5rP2hhc2goJ3NoYTI1NicsJGxpbmtbJ3VybCddKTpudWxsXSxKU09OX1BS
RVRUWV9QUklOVHxKU09OX1VORVNDQVBFRF9VTklDT0RFKS5QSFBfRU9MOwogICAgZWNobyAiU09M
TyBMRUNUVVJBLiBTaW4gVVJMcyBwcml2YWRhcywgdG9rZW5zIGRlIGFjY2VzbywgZ2VuZXJhY2nD
s24gZGUgZW5sYWNlcywgZW52w61vcyBuaSBjYW1iaW9zIGRlIEJELlxuIjsKfSBjYXRjaChUaHJv
d2FibGUgJGVycm9yKSB7CiAgICBmd3JpdGUoU1RERVJSLCdFTkxBQ0VfQ09OVk9DQVRPUklBX0RJ
QUdOT1NUSUNPIGNsYXNlPScuZ2V0X2NsYXNzKCRlcnJvcikuJyBjb2RpZ289Jy4oaW50KSRlcnJv
ci0+Z2V0Q29kZSgpLiIuIFNpbiBkYXRvcyBwcml2YWRvcy5cbiIpO2V4aXQoMik7Cn0K
CONVOCATORIA_HELPER_1
 [[ "$(digest "$TMP/stage/tools/diagnosticar_enlace_convocatoria.php")" == '75bfb4b14ba171c1288219ca5a9c0ec8eacebe7b8321f8511f94bd2172e0f3a5' ]] || fail 'Dependencia alterada.'
 if [[ "${BEFORE["tools/diagnosticar_enlace_convocatoria.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/tools/diagnosticar_enlace_convocatoria.php")"
  [[ "$existing" == '75bfb4b14ba171c1288219ca5a9c0ec8eacebe7b8321f8511f94bd2172e0f3a5' ]] || fail 'Dependencia personalizada: tools/diagnosticar_enlace_convocatoria.php. SITIO INTACTO.'
  cp -p -- "$LIVE/tools/diagnosticar_enlace_convocatoria.php" "$TMP/stage/tools/diagnosticar_enlace_convocatoria.php"
 else chmod 0644 "$TMP/stage/tools/diagnosticar_enlace_convocatoria.php";fi
 declare -A EOLS=()
 base64 --decode > "$TMP/feature-0.patch" <<'CONVOCATORIA_PATCH_0'
ZGlmZiAtLWdpdCBhL2FwcC9tb2RlbHMvQ29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbC5w
aHAgYi9hcHAvbW9kZWxzL0NvcnJlb0NvbnZvY2F0b3JpYUNvbnN1bHRhTW9kZWwucGhwCi0tLSBh
L2FwcC9tb2RlbHMvQ29ycmVvQ29udm9jYXRvcmlhQ29uc3VsdGFNb2RlbC5waHAKKysrIGIvYXBw
L21vZGVscy9Db3JyZW9Db252b2NhdG9yaWFDb25zdWx0YU1vZGVsLnBocApAQCAtNDUsNiArNDUs
NyBAQAogICAgICAgICBmb3JlYWNoKCRyb3dzIGFzICRyKSB7CiAgICAgICAgICAgICAkaWQ9KGlu
dCkkclsnaWQnXTskaXRlbXNbJGlkXT1bJ2lkJz0+JGlkLCd0aXR1bG8nPT4oc3RyaW5nKSRyWyd0
aXR1bG8nXSwnYWN0aXZhJz0+KGludCkkclsnZXN0YWRvJ109PT0xLAogICAgICAgICAgICAgICAg
ICdpbWFnZW5fcmVmJz0+KHN0cmluZykoJHJbJ2ltYWdlbiddPz8nJyksJ2VzdGFkb3MnPT5bXSwn
Y29udmVuaW9zJz0+W11dOworICAgICAgICAgICAgaWYoYXJyYXlfa2V5X2V4aXN0cygnZW5sYWNl
X3JlZ2lzdHJvJywkcikpIHsgJGl0ZW1zWyRpZF1bJ2VubGFjZV9yZWYnXT0oc3RyaW5nKSgkclsn
ZW5sYWNlX3JlZ2lzdHJvJ10/PycnKTsgfQogICAgICAgICB9CiAgICAgICAgIC8vIElEcyBwcm9j
ZWRlbiBleGNsdXNpdmFtZW50ZSBkZWwgcmVzdWx0YWRvIFNRTDsgbm8gc2UgaW50ZXJwb2xhIGVu
dHJhZGEgZGVsIHVzdWFyaW8uCiAgICAgICAgICRpZHM9aW1wbG9kZSgnLCcsYXJyYXlfa2V5cygk
aXRlbXMpKTsKQEAgLTEyOCw3ICsxMjksNyBAQAogICAgIHB1YmxpYyBmdW5jdGlvbiBidXNjYXIo
aW50ICRpZCk6ID9hcnJheQogICAgIHsKICAgICAgICAgaWYoJGlkPD0wKSB7IHJldHVybiBudWxs
OyB9Ci0gICAgICAgICRyb3dzPSR0aGlzLT5jb21wbGV0YXIoJHRoaXMtPmZpbGFzKCdTRUxFQ1Qg
aWQsIHRpdHVsbywgaW1hZ2VuLCBlc3RhZG8gRlJPTSBjb252b2NhdG9yaWFzIFdIRVJFIGlkID0g
PyBMSU1JVCAxJyxbJGlkXSkpOworICAgICAgICAkcm93cz0kdGhpcy0+Y29tcGxldGFyKCR0aGlz
LT5maWxhcygnU0VMRUNUIGlkLCB0aXR1bG8sIGltYWdlbiwgZXN0YWRvLCBlbmxhY2VfcmVnaXN0
cm8gRlJPTSBjb252b2NhdG9yaWFzIFdIRVJFIGlkID0gPyBMSU1JVCAxJyxbJGlkXSkpOwogICAg
ICAgICByZXR1cm4gJHJvd3NbMF0/P251bGw7CiAgICAgfQogfQo=
CONVOCATORIA_PATCH_0
 [[ "$(digest "$TMP/feature-0.patch")" == '55e4b10ad0603fa0c5713b8a8e8a3552edef7a704e00d328f61239fc67569288' ]] || fail 'Parche alterado.'
 f='app/models/CorreoConvocatoriaConsultaModel.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 78ced2a7f0b78313794155fb8b1b4287aad6408d015849690297621a038f3ead) ;;
 7d7ee423d942d58988fc4250d02921abf04a42e4a7813efc3db6192946e542ab)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-0.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-0.patch")
  case "$(code_digest "$TMP/stage/$f")" in 78ced2a7f0b78313794155fb8b1b4287aad6408d015849690297621a038f3ead) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-1.patch" <<'CONVOCATORIA_PATCH_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbi5w
aHAgYi9hcHAvc2VydmljZXMvQ29ycmVvQ29udm9jYXRvcmlhQ29tcG9zaWNpb24ucGhwCi0tLSBh
L2FwcC9zZXJ2aWNlcy9Db3JyZW9Db252b2NhdG9yaWFDb21wb3NpY2lvbi5waHAKKysrIGIvYXBw
L3NlcnZpY2VzL0NvcnJlb0NvbnZvY2F0b3JpYUNvbXBvc2ljaW9uLnBocApAQCAtMSw2ICsxLDcg
QEAKIDw/cGhwCiByZXF1aXJlX29uY2UgX19ESVJfXy4nL0NvcnJlb1NhbGlkYUluc3RpdHVjaW9u
YWxTZXJ2aWNlLnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXy4nL0NvcnJlb0VzdGFkb1ByaXZh
ZG8ucGhwJzsKK3JlcXVpcmVfb25jZSBfX0RJUl9fLicvQ29ycmVvQ29udm9jYXRvcmlhRW5sYWNl
LnBocCc7CiByZXF1aXJlX29uY2UgZGlybmFtZShfX0RJUl9fKS4nL21vZGVscy9Db3JyZW9Db252
b2NhdG9yaWFDb25zdWx0YU1vZGVsLnBocCc7CiAKIC8qKiBTZWxlY2Npw7NuIGUgaW1hZ2VuIGRl
bCBtZW5zYWplLiBFbCB0cmFuc3BvcnRlIHkgbGEgcGVyc2lzdGVuY2lhIGNvbWVyY2lhbCBzZSBk
ZWxlZ2FuIGludGFjdG9zLiAqLwpAQCAtNzYsNyArNzcsOCBAQAogICAgIHsKICAgICAgICAgaWYo
ISRyb3cpIHsgcmV0dXJuIG51bGw7IH0KICAgICAgICAgJGltYWdlPSR0aGlzLT5pbWFnZW4oJHJv
dyk7Ci0gICAgICAgICRvdXQ9JHJvdzt1bnNldCgkb3V0WydpbWFnZW5fcmVmJ10sJG91dFsnX2Vu
dmlvX2FjZXB0YWRvJ10pOworICAgICAgICAkb3V0PSRyb3c7dW5zZXQoJG91dFsnaW1hZ2VuX3Jl
ZiddLCRvdXRbJ19lbnZpb19hY2VwdGFkbyddLCRvdXRbJ2VubGFjZV9yZWYnXSk7CisgICAgICAg
ICRvdXRbJ2VubGFjZV9wdWJsaWNvJ109Q29ycmVvQ29udm9jYXRvcmlhRW5sYWNlOjpvYnRlbmVy
KCRyb3cpOwogICAgICAgICAkb3V0WydpbWFnZW5fc2hhJ109JGltYWdlWydzaGEnXT8/Jyc7CiAg
ICAgICAgICRvdXRbJ2ltYWdlbl91cmwnXT0kaW1hZ2U/J2luZGV4LnBocD9jb250cm9sbGVyPWNv
cnJlb01hcmtldGluZyZhY3Rpb249aW1hZ2VuQ29udm9jYXRvcmlhJmlkPScuKGludCkkcm93Wydp
ZCddLicmdj0nLiRpbWFnZVsnc2hhJ10uKCRtYWlsPycmY29ycmVvX2lkPScuJG1haWw6JycpOicn
OwogICAgICAgICAkb3V0WydpbWFnZW5fbWVuc2FqZSddPSRpbWFnZT8nSW1hZ2VuIHF1ZSBzZSBp
bmNsdWlyw6EgZGVudHJvIGRlbCBjb3JyZW8uJzonRXN0YSBjb252b2NhdG9yaWEgbm8gdGllbmUg
dW5hIGltYWdlbiBkaXNwb25pYmxlLiBQdWVkZXMgZW52aWFyIGVsIGNvcnJlbyBzaW4gaW1hZ2Vu
Lic7CkBAIC0xMzEsNiArMTMzLDggQEAKICAgICAgICAgICAgICR0aXRsZT1odG1sc3BlY2lhbGNo
YXJzKCR0aGlzLT5zZWxlY3Rpb25bJ3RpdHVsbyddLEVOVF9RVU9URVMsJ1VURi04Jyk7CiAgICAg
ICAgICAgICAkZGF0YVsnaHRtbF9hZGljaW9uYWwnXT0oJGRhdGFbJ2h0bWxfYWRpY2lvbmFsJ10/
PycnKS4nPGRpdiBzdHlsZT0ibWFyZ2luLXRvcDoxOHB4Ij48aW1nIHNyYz0iY2lkOicuJGlbJ2Np
ZCddLiciIGFsdD0iJy4kdGl0bGUuJyIgd2lkdGg9IjYwMCIgc3R5bGU9ImRpc3BsYXk6YmxvY2s7
d2lkdGg6MTAwJTttYXgtd2lkdGg6NjAwcHg7aGVpZ2h0OmF1dG87Ym9yZGVyOjAiPjwvZGl2Pic7
CiAgICAgICAgIH0KKyAgICAgICAgJGxpbms9Q29ycmVvQ29udm9jYXRvcmlhRW5sYWNlOjpvYnRl
bmVyKCR0aGlzLT5zZWxlY3Rpb24pOworICAgICAgICBpZigkbGluaykgeyAkZGF0YVsnaHRtbF9h
ZGljaW9uYWwnXT0oJGRhdGFbJ2h0bWxfYWRpY2lvbmFsJ10/PycnKS5Db3JyZW9Db252b2NhdG9y
aWFFbmxhY2U6Omh0bWwoJGxpbmspOyB9CiAgICAgICAgIHRyeSB7ICRyZXN1bHQ9JHRoaXMtPmRl
bGVnYXRlLT5lbnZpYXIoJGRhdGEpOyB9CiAgICAgICAgIGNhdGNoKFRocm93YWJsZSAkZXJyb3Ip
IHsKICAgICAgICAgICAgIGVycm9yX2xvZygnTUFJTF9DT05WT0NBVE9SSUEgcGhhc2U9dHJhbnNw
b3J0ZSBleGNlcHRpb249Jy5nZXRfY2xhc3MoJGVycm9yKS4nIGNvZGU9Jy4oaW50KSRlcnJvci0+
Z2V0Q29kZSgpKTsK
CONVOCATORIA_PATCH_1
 [[ "$(digest "$TMP/feature-1.patch")" == 'bb86afc5555d0a228410c27168f3a3088c9024a36e1d1d4e5e07270d89ead239' ]] || fail 'Parche alterado.'
 f='app/services/CorreoConvocatoriaComposicion.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 7001a0d46599223198d3db4993295811f507e2f9c592b80d5dfd9e75f455a466) ;;
 e498cb337bf58ded87f8f37653ff2409359149034c6cb42c6f79cd5a37dd51cc)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-1.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-1.patch")
  case "$(code_digest "$TMP/stage/$f")" in 7001a0d46599223198d3db4993295811f507e2f9c592b80d5dfd9e75f455a466) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-2.patch" <<'CONVOCATORIA_PATCH_2'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb19jb252b2NhdG9yaWFzLmpzIGIv
cHVibGljL2phdmFzY3JpcHQvY29ycmVvX2NvbnZvY2F0b3JpYXMuanMKLS0tIGEvcHVibGljL2ph
dmFzY3JpcHQvY29ycmVvX2NvbnZvY2F0b3JpYXMuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQv
Y29ycmVvX2NvbnZvY2F0b3JpYXMuanMKQEAgLTMyLDYgKzMyLDIyIEBACiAgICAgICAgIGNvbnN0
IHByZXZpZXcgPSBxKCdbZGF0YS1jb252b2NhdG9yaWEtcHJldmlld10nKTsKICAgICAgICAgY29u
c3QgaW1hZ2UgPSBxKCdbZGF0YS1jb252b2NhdG9yaWEtaW1hZ2VuXScpOwogICAgICAgICBjb25z
dCBjYXB0aW9uID0gcSgnW2RhdGEtY29udm9jYXRvcmlhLWNhcHRpb25dJyk7CisgICAgICAgIGNv
bnN0IHNob3dMaW5rID0gKGNvbnRhaW5lciwgcm93KSA9PiB7CisgICAgICAgICAgICBjb25zdCBi
bG9jayA9IGNvbnRhaW5lci5xdWVyeVNlbGVjdG9yKCdbZGF0YS1jb252b2NhdG9yaWEtZW5sYWNl
XScpOworICAgICAgICAgICAgY29uc3QgdGl0bGUgPSBjb250YWluZXIucXVlcnlTZWxlY3Rvcign
W2RhdGEtY29udm9jYXRvcmlhLWVubGFjZS10aXR1bG9dJyk7CisgICAgICAgICAgICBjb25zdCB0
ZXh0ID0gY29udGFpbmVyLnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLWNvbnZvY2F0b3JpYS1lbmxhY2Ut
dGV4dG9dJyk7CisgICAgICAgICAgICBjb25zdCBhbmNob3IgPSBjb250YWluZXIucXVlcnlTZWxl
Y3RvcignW2RhdGEtY29udm9jYXRvcmlhLWVubGFjZS11cmxdJyk7CisgICAgICAgICAgICBjb25z
dCBub3RpY2UgPSBjb250YWluZXIucXVlcnlTZWxlY3RvcignW2RhdGEtY29udm9jYXRvcmlhLWVu
bGFjZS1hdmlzb10nKTsKKyAgICAgICAgICAgIGlmICghYmxvY2sgfHwgIXRpdGxlIHx8ICF0ZXh0
IHx8ICFhbmNob3IgfHwgIW5vdGljZSkgeyByZXR1cm47IH0KKyAgICAgICAgICAgIGNvbnN0IGxp
bmsgPSByb3c/LmVubGFjZV9wdWJsaWNvOworICAgICAgICAgICAgY29uc3QgdmFsaWQgPSBsaW5r
ICYmIFsncmVnaXN0cm8nLCAnaW5zY3JpcGNpb24nXS5pbmNsdWRlcyhsaW5rLnRpcG8pICYmIHR5
cGVvZiBsaW5rLnVybCA9PT0gJ3N0cmluZycgJiYgL15odHRwcz86XC9cLy9pLnRlc3QobGluay51
cmwpOworICAgICAgICAgICAgYmxvY2suY2xhc3NMaXN0LnRvZ2dsZSgnZC1ub25lJywgIXZhbGlk
KTsKKyAgICAgICAgICAgIG5vdGljZS5jbGFzc0xpc3QudG9nZ2xlKCdkLW5vbmUnLCAhcm93IHx8
IEJvb2xlYW4odmFsaWQpKTsKKyAgICAgICAgICAgIG5vdGljZS50ZXh0Q29udGVudCA9IHJvdyAm
JiAhdmFsaWQgPyAnRXN0YSBjb252b2NhdG9yaWEgbm8gdGllbmUgdW4gZW5sYWNlIHDDumJsaWNv
IGRpc3BvbmlibGUuJyA6ICcnOworICAgICAgICAgICAgdGl0bGUudGV4dENvbnRlbnQgPSB2YWxp
ZCA/IGxpbmsudGl0dWxvIDogJyc7IHRleHQudGV4dENvbnRlbnQgPSB2YWxpZCA/IGxpbmsudGV4
dG8gOiAnJzsKKyAgICAgICAgICAgIGFuY2hvci50ZXh0Q29udGVudCA9IHZhbGlkID8gbGluay5l
dGlxdWV0YSA6ICcnOworICAgICAgICAgICAgaWYgKHZhbGlkKSB7IGFuY2hvci5ocmVmID0gbGlu
ay51cmw7IH0gZWxzZSB7IGFuY2hvci5yZW1vdmVBdHRyaWJ1dGUoJ2hyZWYnKTsgfQorICAgICAg
ICB9OwogICAgICAgICBjb25zdCBlbmRwb2ludCA9ICdpbmRleC5waHA/Y29udHJvbGxlcj1jb3Jy
ZW9NYXJrZXRpbmcmYWN0aW9uPWNvbnZvY2F0b3JpYXMnOwogICAgICAgICBsZXQgcm93cyA9IFtd
LCBhZ3JlZW1lbnRzID0gbnVsbCwgY2F0YWxvZyA9IG51bGwsIHNlcXVlbmNlID0gMCwgYWJvcnQg
PSBudWxsLCBsb2FkaW5nID0gZmFsc2UsIHJlYWR5ID0gZmFsc2U7CiAgICAgICAgIGNvbnN0IG9w
dGlvbiA9ICh2YWx1ZSwgbGFiZWwpID0+IHsKQEAgLTc2LDYgKzkyLDcgQEAKICAgICAgICAgY29u
c3QgY2xlYXJJbWFnZSA9ICgpID0+IHsKICAgICAgICAgICAgIHNlcXVlbmNlKys7IGFib3J0Py5h
Ym9ydCgpOyBsb2FkaW5nID0gZmFsc2U7IHJlYWR5ID0gZmFsc2U7IHNoYS52YWx1ZSA9ICcnOwog
ICAgICAgICAgICAgcHJldmlldy5jbGFzc0xpc3QuYWRkKCdkLW5vbmUnKTsgaW1hZ2UucmVtb3Zl
QXR0cmlidXRlKCdzcmMnKTsgY2FwdGlvbi50ZXh0Q29udGVudCA9ICcnOworICAgICAgICAgICAg
c2hvd0xpbmsobW9kYWwsIG51bGwpOwogICAgICAgICB9OwogICAgICAgICBjb25zdCBzaG93SW1h
Z2UgPSByb3cgPT4gewogICAgICAgICAgICAgc2hhLnZhbHVlID0gcm93Py5pbWFnZW5fc2hhIHx8
ICcnOwpAQCAtODYsNiArMTAzLDcgQEAKICAgICAgICAgICAgIGVsc2UgeyBpbWFnZS5yZW1vdmVB
dHRyaWJ1dGUoJ3NyYycpOyB9CiAgICAgICAgICAgICBjYXB0aW9uLnRleHRDb250ZW50ID0gcm93
ID8gZXRpcXVldGEocm93KSA6ICcnOwogICAgICAgICAgICAgc3RhdHVzLnRleHRDb250ZW50ID0g
cm93Py5pbWFnZW5fbWVuc2FqZSB8fCAnJzsKKyAgICAgICAgICAgIHNob3dMaW5rKG1vZGFsLCBy
b3cpOwogICAgICAgICB9OwogICAgICAgICBjb25zdCBsaXN0ID0gKCkgPT4gewogICAgICAgICAg
ICAgb3B0aW9ucyhhZ3JlZW1lbnQsIGNvbnZlbmlvcyhyb3dzLCBzdGF0ZS52YWx1ZSwgYWdyZWVt
ZW50cyksICdUb2RvcyBsb3MgY29udmVuaW9zJywgYyA9PiBjLm5vbWJyZSArIChjLnJlZmVyZW5j
aWEgPyAnIMK3ICcgKyBjLnJlZmVyZW5jaWEgOiAnJykgKyAnIMK3ICMnICsgKGMub3JpZ2VuX2lk
IHx8IGMuaWQpICsgKGMub3JpZ2VuID09PSAnbWFudWFsJyA/ICcgKG1hbnVhbCknIDogJycpKTsK
QEAgLTE4MCw2ICsxOTgsNyBAQAogICAgICAgICAgICAgaW1nLmNsYXNzTGlzdC50b2dnbGUoJ2Qt
bm9uZScsICF2YWxpZCk7CiAgICAgICAgICAgICBpZiAodmFsaWQpIHsgaW1nLnNyYyA9IHVybDsg
aW1nLmFsdCA9IHJvdy50aXR1bG87IH0gZWxzZSB7IGltZy5yZW1vdmVBdHRyaWJ1dGUoJ3NyYycp
OyB9CiAgICAgICAgICAgICBsYWJlbC50ZXh0Q29udGVudCA9IHJvdyA/IGV0aXF1ZXRhKHJvdykg
KyAodmFsaWQgPyAnJyA6ICcgwrcgSW1hZ2VuIG5vIGRpc3BvbmlibGUnKSA6ICcnOworICAgICAg
ICAgICAgc2hvd0xpbmsoZGV0YWlsLCByb3cpOwogICAgICAgICB9KTsKICAgICAgICAgcmV0dXJu
IHsgbG9hZCwgY2hvb3NlLCByZXNldCB9OwogICAgIH0K
CONVOCATORIA_PATCH_2
 [[ "$(digest "$TMP/feature-2.patch")" == '29edfc7fbd814110ede23d64b0b21fda362e91a690253a2222fe2342a11ff46f' ]] || fail 'Parche alterado.'
 f='public/javascript/correo_convocatorias.js'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 4b74d3c88fd5db6717f9a2893956d8a4a217abdfe198d94db40129074ecb5f89) ;;
 84ddccf19cf55a74f7d7a228390c855d39b1792b5a14dfec7b1872811b61afaa)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-2.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-2.patch")
  case "$(code_digest "$TMP/stage/$f")" in 4b74d3c88fd5db6717f9a2893956d8a4a217abdfe198d94db40129074ecb5f89) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-3.patch" <<'CONVOCATORIA_PATCH_3'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9jb3JyZW9zL2luZGV4LnBocCBiL2FwcC92aWV3cy9jb3Jy
ZW9zL2luZGV4LnBocAotLS0gYS9hcHAvdmlld3MvY29ycmVvcy9pbmRleC5waHAKKysrIGIvYXBw
L3ZpZXdzL2NvcnJlb3MvaW5kZXgucGhwCkBAIC00ODAsNiArNDgwLDEzIEBACiAgICAgICAgICAg
ICAgICAgICAgICAgICA8ZmlnY2FwdGlvbiBjbGFzcz0iZm9ybS10ZXh0IiBkYXRhLWNvbnZvY2F0
b3JpYS1jYXB0aW9uPjwvZmlnY2FwdGlvbj4KICAgICAgICAgICAgICAgICAgICAgPC9maWd1cmU+
CiAKKyAgICAgICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0ibWItMyBkLW5vbmUiIGRhdGEtY29u
dm9jYXRvcmlhLWVubGFjZT4KKyAgICAgICAgICAgICAgICAgICAgICAgIDxwIGNsYXNzPSJtYi0x
IGZ3LXNlbWlib2xkIiBkYXRhLWNvbnZvY2F0b3JpYS1lbmxhY2UtdGl0dWxvPjwvcD4KKyAgICAg
ICAgICAgICAgICAgICAgICAgIDxwIGNsYXNzPSJmb3JtLXRleHQgbWItMSIgZGF0YS1jb252b2Nh
dG9yaWEtZW5sYWNlLXRleHRvPjwvcD4KKyAgICAgICAgICAgICAgICAgICAgICAgIDxhIHRhcmdl
dD0iX2JsYW5rIiByZWw9Im5vb3BlbmVyIG5vcmVmZXJyZXIiIGRhdGEtY29udm9jYXRvcmlhLWVu
bGFjZS11cmw+PC9hPgorICAgICAgICAgICAgICAgICAgICA8L2Rpdj4KKyAgICAgICAgICAgICAg
ICAgICAgPHAgY2xhc3M9ImZvcm0tdGV4dCBkLW5vbmUiIHJvbGU9InN0YXR1cyIgZGF0YS1jb252
b2NhdG9yaWEtZW5sYWNlLWF2aXNvPjwvcD4KKwogICAgICAgICAgICAgICAgICAgICA8ZGl2IGNs
YXNzPSJtYi0wIj4KICAgICAgICAgICAgICAgICAgICAgICAgIDxsYWJlbAogICAgICAgICAgICAg
ICAgICAgICAgICAgICAgIGNsYXNzPSJmb3JtLWxhYmVsIgpAQCAtNjM4LDYgKzY0NSwxMiBAQAog
ICAgICAgICAgICAgICAgICAgICAgICAgICAgIDxpbWcgY2xhc3M9ImltZy1mbHVpZCIgYWx0PSJJ
bWFnZW4gZGUgbGEgY29udm9jYXRvcmlhIGRlbCBjb3JyZW8iIGRhdGEtY29udm9jYXRvcmlhLWRl
dGFsbGUtaW1hZ2VuPgogICAgICAgICAgICAgICAgICAgICAgICAgICAgIDxmaWdjYXB0aW9uIGNs
YXNzPSJmb3JtLXRleHQiIGRhdGEtY29udm9jYXRvcmlhLWRldGFsbGUtY2FwdGlvbj48L2ZpZ2Nh
cHRpb24+CiAgICAgICAgICAgICAgICAgICAgICAgICA8L2ZpZ3VyZT4KKyAgICAgICAgICAgICAg
ICAgICAgICAgIDxkaXYgY2xhc3M9Im10LTMgZC1ub25lIiBkYXRhLWNvbnZvY2F0b3JpYS1lbmxh
Y2U+CisgICAgICAgICAgICAgICAgICAgICAgICAgICAgPHAgY2xhc3M9Im1iLTEgZnctc2VtaWJv
bGQiIGRhdGEtY29udm9jYXRvcmlhLWVubGFjZS10aXR1bG8+PC9wPgorICAgICAgICAgICAgICAg
ICAgICAgICAgICAgIDxwIGNsYXNzPSJmb3JtLXRleHQgbWItMSIgZGF0YS1jb252b2NhdG9yaWEt
ZW5sYWNlLXRleHRvPjwvcD4KKyAgICAgICAgICAgICAgICAgICAgICAgICAgICA8YSB0YXJnZXQ9
Il9ibGFuayIgcmVsPSJub29wZW5lciBub3JlZmVycmVyIiBkYXRhLWNvbnZvY2F0b3JpYS1lbmxh
Y2UtdXJsPjwvYT4KKyAgICAgICAgICAgICAgICAgICAgICAgIDwvZGl2PgorICAgICAgICAgICAg
ICAgICAgICAgICAgPHAgY2xhc3M9ImZvcm0tdGV4dCBkLW5vbmUiIHJvbGU9InN0YXR1cyIgZGF0
YS1jb252b2NhdG9yaWEtZW5sYWNlLWF2aXNvPjwvcD4KICAgICAgICAgICAgICAgICAgICAgPC9k
aXY+CiAKICAgICAgICAgICAgICAgICAgICAgPGRpdgo=
CONVOCATORIA_PATCH_3
 [[ "$(digest "$TMP/feature-3.patch")" == '0e6e5435b01025d0a933adc07eda4c7758c62edbf72092ec190c7385803aa516' ]] || fail 'Parche alterado.'
 f='app/views/correos/index.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 04440cfa767ca025c10a43aee1280c5b2fe36019d6a126d44c0161ccbfef2871|8ac66bf8bf6dd91c0f3e4e38597af5faadd6fd50b155b9af636f15aa219a26f7) ;;
 50e1096016caf07899892b024bd4295e3fc6bedc0ec472838f28ae21a2eda5cb|be3e578b2cd52874895781bc8bd2d2ef6f740b8674f037c5168eeaa8c46bf8a5)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-3.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-3.patch")
  case "$(code_digest "$TMP/stage/$f")" in 04440cfa767ca025c10a43aee1280c5b2fe36019d6a126d44c0161ccbfef2871|8ac66bf8bf6dd91c0f3e4e38597af5faadd6fd50b155b9af636f15aa219a26f7) ;; *) fail "Resultado inesperado: $f";; esac ;;
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
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/enlace-convocatoria-$(date +%Y%m%d-%H%M%S)-$$";mkdir -m 700 "$BACKUP"
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
