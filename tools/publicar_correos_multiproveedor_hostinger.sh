#!/usr/bin/env bash
# Correos autenticados. Publicación selectiva; sin SQL, vendor ni credenciales.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='correo-central-v1-6b0b012e524015cf'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/services/MailService.php'
DIAGNOSTIC='tools/diagnosticar_correo_hostinger.php'
FILES=("app/services/CorreoEstadoPrivado.php" "app/services/CorreoOAuthTokenProvider.php" "app/services/CorreoSmtpTransport.php" "app/services/CorreoEnvioControl.php" "app/services/CorreoLimiteService.php" "app/services/CorreoRegistroEstadoService.php" "app/services/CorreoGmailApiTransport.php" "app/services/CorreoConexionOAuthService.php" "app/services/CorreoRecuperacionService.php" "app/controllers/CorreoConexionController.php" "app/views/correos/conexion.php" "config/mail_config.php" "app/services/MailService.php" "app/models/UsuarioModel.php" "app/services/CorreoSalidaInstitucionalService.php" "app/services/CorreoCuentaConectadaService.php" "app/services/CorreoMarketingService.php" "app/services/SeguimientoCorreoService.php" "app/services/OficioCorreoService.php" "app/controllers/CorreoMarketingController.php" "app/views/correos/index.php" "public/javascript/correos_marketing.js" "tools/diagnosticar_correo_hostinger.php" "index.php" "app/controllers/LoginController.php")
PATCH_FILES=("config/mail_config.php" "app/services/MailService.php" "app/services/CorreoSalidaInstitucionalService.php" "app/services/CorreoMarketingService.php" "app/services/SeguimientoCorreoService.php" "app/services/OficioCorreoService.php" "app/controllers/CorreoMarketingController.php" "app/controllers/LoginController.php" "app/models/UsuarioModel.php" "app/views/correos/index.php" "public/javascript/correos_marketing.js" "tools/diagnosticar_correo_hostinger.php" "index.php")
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
FILES=("app/controllers/LoginController.php" "index.php" "tools/diagnosticar_correo_hostinger.php" "public/javascript/correos_marketing.js" "app/views/correos/index.php" "app/controllers/CorreoMarketingController.php" "app/services/OficioCorreoService.php" "app/services/SeguimientoCorreoService.php" "app/services/CorreoMarketingService.php" "app/services/CorreoCuentaConectadaService.php" "app/services/CorreoSalidaInstitucionalService.php" "app/models/UsuarioModel.php" "app/services/MailService.php" "config/mail_config.php" "app/views/correos/conexion.php" "app/controllers/CorreoConexionController.php" "app/services/CorreoRecuperacionService.php" "app/services/CorreoConexionOAuthService.php" "app/services/CorreoGmailApiTransport.php" "app/services/CorreoRegistroEstadoService.php" "app/services/CorreoLimiteService.php" "app/services/CorreoEnvioControl.php" "app/services/CorreoSmtpTransport.php" "app/services/CorreoOAuthTokenProvider.php" "app/services/CorreoEstadoPrivado.php")
else
 [[ -f "$LIVE/vendor/autoload.php" ]] || fail 'Falta vendor/autoload.php. No se instalan dependencias.'
 php -r 'if(PHP_VERSION_ID<80200)exit(1);require $argv[1]."/vendor/autoload.php";foreach(["curl","openssl","mbstring","mysqli"] as $e)if(!extension_loaded($e))exit(1);if(!class_exists("PHPMailer\\PHPMailer\\PHPMailer")||!interface_exists("PHPMailer\\PHPMailer\\OAuthTokenProvider"))exit(1);' "$LIVE" || fail 'Se requiere PHP 8.2+, PHPMailer existente, cURL, OpenSSL, MBString y mysqli.'
 php -r '$root=realpath($argv[1]);$dir=getenv("IMPE_MAIL_STATE_DIR")?:dirname($root)."/impe-private/mail-state";$p=$dir;while(!file_exists($p)&&dirname($p)!==$p)$p=dirname($p);$real=realpath($p);if(!$real||str_starts_with($real."/",$root."/")||!is_writable($real))exit(1);' "$LIVE" || fail 'Se requiere una carpeta privada escribible fuera del sitio para OAuth e idempotencia.'
 function code_digest(){
 if [[ "$1" == *.php ]];then
  php -r '$out=[];foreach(token_get_all(file_get_contents($argv[1])) as $t){if(is_array($t)){if(in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$out[]=[$t[0],$t[0]===T_OPEN_TAG?trim($t[1]):$t[1]];}else{$out[]=$t;}}echo hash("sha256",json_encode($out));' "$1"
 else digest "$1";fi
 }
 php -r 'exit(function_exists("token_get_all")?0:1);' || fail 'Se requiere el tokenizador de PHP para reconocer versiones sin modificar comentarios.'
 base64 --decode > "$TMP/stage/app/services/CorreoEstadoPrivado.php" <<'CENTRAL_HELPER_0'
PD9waHAKCi8qKiBFc3RhZG8gdMOpY25pY28gcHJpdmFkbyBwYXJhIE9BdXRoIHkgZXhjbHVzacOz
biBkZSBlbnbDrW9zIGR1cGxpY2Fkb3MuIFNpbiBCRC4gKi8KZmluYWwgY2xhc3MgQ29ycmVvRXN0
YWRvUHJpdmFkbwp7CiAgICBwcml2YXRlIHN0YXRpYyBmdW5jdGlvbiBkaXJlY3RvcmlvKGJvb2wg
JGNyZWFyKTogP3N0cmluZwogICAgewogICAgICAgICRyb290ID0gcmVhbHBhdGgoZGlybmFtZShf
X0RJUl9fLCAyKSk7CiAgICAgICAgJGRpciA9IHJ0cmltKChzdHJpbmcpKGdldGVudignSU1QRV9N
QUlMX1NUQVRFX0RJUicpID86IGRpcm5hbWUoJHJvb3QpIC4gJy9pbXBlLXByaXZhdGUvbWFpbC1z
dGF0ZScpLCAnL1xcJyk7CiAgICAgICAgaWYgKCFpc19kaXIoJGRpcikgJiYgISRjcmVhcikgeyBy
ZXR1cm4gbnVsbDsgfQogICAgICAgIGlmICghaXNfZGlyKCRkaXIpICYmICFAbWtkaXIoJGRpciwg
MDcwMCwgdHJ1ZSkgJiYgIWlzX2RpcigkZGlyKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlv
bignTUFJTF9TVEFURV9ESVJFQ1RPUlknKTsgfQogICAgICAgICRyZWFsID0gcmVhbHBhdGgoJGRp
cik7CiAgICAgICAgJHdlYlJvb3QgPSByZWFscGF0aCgoc3RyaW5nKSgkX1NFUlZFUlsnRE9DVU1F
TlRfUk9PVCddID8/ICcnKSk7CiAgICAgICAgaWYgKCEkcmVhbCB8fCBpc19saW5rKCRkaXIpIHx8
IHN0cl9zdGFydHNfd2l0aChzdHJ0b2xvd2VyKCRyZWFsIC4gRElSRUNUT1JZX1NFUEFSQVRPUiks
IHN0cnRvbG93ZXIoJHJvb3QgLiBESVJFQ1RPUllfU0VQQVJBVE9SKSkgfHwKICAgICAgICAgICAg
KCR3ZWJSb290ICYmIHN0cl9zdGFydHNfd2l0aChzdHJ0b2xvd2VyKCRyZWFsIC4gRElSRUNUT1JZ
X1NFUEFSQVRPUiksIHN0cnRvbG93ZXIoJHdlYlJvb3QgLiBESVJFQ1RPUllfU0VQQVJBVE9SKSkp
KSB7CiAgICAgICAgICAgIHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1NUQVRFX05P
VF9QUklWQVRFJyk7CiAgICAgICAgfQogICAgICAgIHJldHVybiAkcmVhbDsKICAgIH0KCiAgICBw
dWJsaWMgc3RhdGljIGZ1bmN0aW9uIGxlZXIoc3RyaW5nICRncnVwbywgc3RyaW5nICRjbGF2ZSk6
IGFycmF5CiAgICB7CiAgICAgICAgJGRpciA9IHNlbGY6OmRpcmVjdG9yaW8oZmFsc2UpOwogICAg
ICAgIGlmICgkZGlyID09PSBudWxsIHx8ICFpc19maWxlKCRkaXIgLiBESVJFQ1RPUllfU0VQQVJB
VE9SIC4gaGFzaCgnc2hhMjU2JywgJGdydXBvIC4gIlwwIiAuICRjbGF2ZSkgLiAnLmpzb24nKSkg
eyByZXR1cm4gW107IH0KICAgICAgICByZXR1cm4gc2VsZjo6ZWplY3V0YXIoJGdydXBvLCAkY2xh
dmUsIHN0YXRpYyBmdW5jdGlvbiAoJHN0YXRlKSB7IHJldHVybiAkc3RhdGU7IH0pOwogICAgfQoK
ICAgIHB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gZWplY3V0YXIoc3RyaW5nICRncnVwbywgc3RyaW5n
ICRjbGF2ZSwgY2FsbGFibGUgJG9wZXJhY2lvbikKICAgIHsKICAgICAgICAkcmVhbCA9IHNlbGY6
OmRpcmVjdG9yaW8odHJ1ZSk7CiAgICAgICAgJG5hbWUgPSBoYXNoKCdzaGEyNTYnLCAkZ3J1cG8g
LiAiXDAiIC4gJGNsYXZlKTsKICAgICAgICAkcGF0aCA9ICRyZWFsIC4gRElSRUNUT1JZX1NFUEFS
QVRPUiAuICRuYW1lIC4gJy5qc29uJzsKICAgICAgICAkbG9ja1BhdGggPSAkcmVhbCAuIERJUkVD
VE9SWV9TRVBBUkFUT1IgLiAkbmFtZSAuICcubG9jayc7CiAgICAgICAgaWYgKGlzX2xpbmsoJHBh
dGgpIHx8IGlzX2xpbmsoJGxvY2tQYXRoKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbign
TUFJTF9TVEFURV9MSU5LJyk7IH0KICAgICAgICAkbmV3ID0gIWZpbGVfZXhpc3RzKCRsb2NrUGF0
aCk7CiAgICAgICAgJGxvY2sgPSBAZm9wZW4oJGxvY2tQYXRoLCAnYytiJyk7CiAgICAgICAgaWYg
KCEkbG9jaykgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9TVEFURV9PUEVOJyk7
IH0KICAgICAgICBpZiAoJG5ldykgeyBAY2htb2QoJGxvY2tQYXRoLCAwNjAwKTsgfQogICAgICAg
IHRyeSB7CiAgICAgICAgICAgIGlmICghZmxvY2soJGxvY2ssIExPQ0tfRVggfCBMT0NLX05CKSkg
eyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9TVEFURV9CVVNZJyk7IH0KICAgICAg
ICAgICAgJHN0YXRlID0gW107CiAgICAgICAgICAgIGlmIChpc19maWxlKCRwYXRoKSkgewogICAg
ICAgICAgICAgICAgJHN0YXRlID0ganNvbl9kZWNvZGUoKHN0cmluZylmaWxlX2dldF9jb250ZW50
cygkcGF0aCksIHRydWUpOwogICAgICAgICAgICAgICAgaWYgKCFpc19hcnJheSgkc3RhdGUpKSB7
IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1NUQVRFX0NPUlJVUFQnKTsgfQogICAg
ICAgICAgICB9CiAgICAgICAgICAgICRzYXZlID0gc3RhdGljIGZ1bmN0aW9uIChhcnJheSAkbmV4
dCkgdXNlICgkcGF0aCkgewogICAgICAgICAgICAgICAgJGJ5dGVzID0ganNvbl9lbmNvZGUoJG5l
eHQsIEpTT05fVEhST1dfT05fRVJST1IpOwogICAgICAgICAgICAgICAgJHRlbXBvcmFyeSA9ICRw
YXRoIC4gJy4nIC4gYmluMmhleChyYW5kb21fYnl0ZXMoOCkpOwogICAgICAgICAgICAgICAgdHJ5
IHsKICAgICAgICAgICAgICAgICAgICAkaCA9IEBmb3BlbigkdGVtcG9yYXJ5LCAneGInKTsKICAg
ICAgICAgICAgICAgICAgICBpZiAoISRoKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdN
QUlMX1NUQVRFX1dSSVRFJyk7IH0KICAgICAgICAgICAgICAgICAgICBAY2htb2QoJHRlbXBvcmFy
eSwgMDYwMCk7CiAgICAgICAgICAgICAgICAgICAgdHJ5IHsKICAgICAgICAgICAgICAgICAgICAg
ICAgaWYgKGZ3cml0ZSgkaCwgJGJ5dGVzKSAhPT0gc3RybGVuKCRieXRlcykgfHwgIWZmbHVzaCgk
aCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01BSUxfU1RBVEVfV1JJVEUnKTsgfQog
ICAgICAgICAgICAgICAgICAgICAgICBpZiAoZnVuY3Rpb25fZXhpc3RzKCdmc3luYycpKSB7IGZz
eW5jKCRoKTsgfQogICAgICAgICAgICAgICAgICAgIH0gZmluYWxseSB7IGZjbG9zZSgkaCk7IH0K
ICAgICAgICAgICAgICAgICAgICBpZiAoIUByZW5hbWUoJHRlbXBvcmFyeSwgJHBhdGgpKSB7IHRo
cm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1NUQVRFX1dSSVRFJyk7IH0KICAgICAgICAg
ICAgICAgIH0gZmluYWxseSB7IGlmIChpc19maWxlKCR0ZW1wb3JhcnkpKSB7IEB1bmxpbmsoJHRl
bXBvcmFyeSk7IH0gfQogICAgICAgICAgICB9OwogICAgICAgICAgICByZXR1cm4gJG9wZXJhY2lv
bigkc3RhdGUsICRzYXZlKTsKICAgICAgICB9IGZpbmFsbHkgeyBmbG9jaygkbG9jaywgTE9DS19V
Tik7IGZjbG9zZSgkbG9jayk7IH0KICAgIH0KfQo=
CENTRAL_HELPER_0
 [[ "$(digest "$TMP/stage/app/services/CorreoEstadoPrivado.php")" == '47ee28050c149b526b5bb096ec5df207c3b215396255e33ff25227677ee9bc33' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoEstadoPrivado.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoEstadoPrivado.php")"
  case "$existing" in 47ee28050c149b526b5bb096ec5df207c3b215396255e33ff25227677ee9bc33|9166f6cc9d9d6ff86b944fab7963d586856e1b3a1ea92bbcb9004d28f5f6e57e) ;; *) fail 'Dependencia personalizada: app/services/CorreoEstadoPrivado.php. No se sobrescribe.';; esac
  if [[ "$existing" == '47ee28050c149b526b5bb096ec5df207c3b215396255e33ff25227677ee9bc33' ]];then cp -p -- "$LIVE/app/services/CorreoEstadoPrivado.php" "$TMP/stage/app/services/CorreoEstadoPrivado.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoEstadoPrivado.php" "$TMP/stage/app/services/CorreoEstadoPrivado.php";chmod --reference="$LIVE/app/services/CorreoEstadoPrivado.php" "$TMP/stage/app/services/CorreoEstadoPrivado.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoEstadoPrivado.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoOAuthTokenProvider.php" <<'CENTRAL_HELPER_1'
PD9waHAKCnJlcXVpcmVfb25jZSBkaXJuYW1lKF9fRElSX18sIDIpIC4gJy92ZW5kb3IvYXV0b2xv
YWQucGhwJzsKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0VzdGFkb1ByaXZhZG8ucGhw
JzsKCi8qKiBYT0FVVEgyIGRlIFBIUE1haWxlciwgdXNhbmRvIHVuYSBhdXRvcml6YWNpw7NuIHBy
ZXZpYSBhZG1pbmlzdHJhZGEgcHJpdmFkYW1lbnRlLiAqLwpmaW5hbCBjbGFzcyBDb3JyZW9PQXV0
aFRva2VuUHJvdmlkZXIgaW1wbGVtZW50cyBcUEhQTWFpbGVyXFBIUE1haWxlclxPQXV0aFRva2Vu
UHJvdmlkZXIKewogICAgcHJpdmF0ZSAkYWNjb3VudDsKICAgIHByaXZhdGUgJHJlcXVlc3Q7CiAg
ICBwdWJsaWMgZnVuY3Rpb24gX19jb25zdHJ1Y3QoYXJyYXkgJGFjY291bnQsID9jYWxsYWJsZSAk
cmVxdWVzdCA9IG51bGwpIHsgJHRoaXMtPmFjY291bnQgPSAkYWNjb3VudDsgJHRoaXMtPnJlcXVl
c3QgPSAkcmVxdWVzdDsgfQoKICAgIHB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gY2xhdmVDdWVudGEo
YXJyYXkgJGEpOiBzdHJpbmcKICAgIHsKICAgICAgICAkcGFydHMgPSBbJGFbJ29hdXRoX3Byb3Zp
ZGVyJ10sIChzdHJpbmcpKCRhWyd0ZW5hbnQnXSA/PyAnY29tbW9uJyksIHRyaW0oKHN0cmluZykk
YVsnc210cF91c2VybmFtZSddKSwgJGFbJ2NsaWVudF9pZCddLCAkYVsncmVmcmVzaF90b2tlbidd
XTsKICAgICAgICBpZiAoIWVtcHR5KCRhWydvYXV0aF9zY29wZSddKSkgeyAkcGFydHNbXSA9ICRh
WydvYXV0aF9zY29wZSddOyB9CiAgICAgICAgcmV0dXJuIGhhc2goJ3NoYTI1NicsIGpzb25fZW5j
b2RlKCRwYXJ0cykpOwogICAgfQoKICAgIHB1YmxpYyBmdW5jdGlvbiBnZXRPYXV0aDY0KCkKICAg
IHsKICAgICAgICByZXR1cm4gYmFzZTY0X2VuY29kZSgndXNlcj0nIC4gdHJpbSgoc3RyaW5nKSgk
dGhpcy0+YWNjb3VudFsnc210cF91c2VybmFtZSddID8/ICcnKSkgLiAiXDAwMWF1dGg9QmVhcmVy
ICIgLiAkdGhpcy0+b2J0ZW5lckFjY2Vzc1Rva2VuKCkgLiAiXDAwMVwwMDEiKTsKICAgIH0KCiAg
ICBwdWJsaWMgZnVuY3Rpb24gb2J0ZW5lckFjY2Vzc1Rva2VuKCk6IHN0cmluZwogICAgewogICAg
ICAgICRhID0gJHRoaXMtPmFjY291bnQ7CiAgICAgICAgJHByb3ZpZGVyID0gJGFbJ29hdXRoX3By
b3ZpZGVyJ10gPz8gJyc7CiAgICAgICAgJHVzZXIgPSB0cmltKChzdHJpbmcpKCRhWydzbXRwX3Vz
ZXJuYW1lJ10gPz8gJycpKTsKICAgICAgICBpZiAoIWZpbHRlcl92YXIoJHVzZXIsIEZJTFRFUl9W
QUxJREFURV9FTUFJTCkgfHwgIWluX2FycmF5KCRwcm92aWRlciwgWydnb29nbGUnLCAnbWljcm9z
b2Z0J10sIHRydWUpIHx8IGVtcHR5KCRhWydjbGllbnRfaWQnXSkgfHwgZW1wdHkoJGFbJ3JlZnJl
c2hfdG9rZW4nXSkpIHsKICAgICAgICAgICAgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01B
SUxfT0FVVEhfQ09ORklHJyk7CiAgICAgICAgfQogICAgICAgICR0ZW5hbnQgPSAoc3RyaW5nKSgk
YVsndGVuYW50J10gPz8gJ2NvbW1vbicpOwogICAgICAgIGlmICghcHJlZ19tYXRjaCgnL15bYS16
QS1aMC05Li1dezEsMjUzfSQvRCcsICR0ZW5hbnQpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0
aW9uKCdNQUlMX09BVVRIX1RFTkFOVCcpOyB9CiAgICAgICAgJHVybCA9ICRwcm92aWRlciA9PT0g
J2dvb2dsZScgPyAnaHR0cHM6Ly9vYXV0aDIuZ29vZ2xlYXBpcy5jb20vdG9rZW4nCiAgICAgICAg
ICAgIDogJ2h0dHBzOi8vbG9naW4ubWljcm9zb2Z0b25saW5lLmNvbS8nIC4gJHRlbmFudCAuICcv
b2F1dGgyL3YyLjAvdG9rZW4nOwogICAgICAgICRrZXkgPSBzZWxmOjpjbGF2ZUN1ZW50YSgkYSk7
CiAgICAgICAgJHRva2VuID0gQ29ycmVvRXN0YWRvUHJpdmFkbzo6ZWplY3V0YXIoJ29hdXRoJywg
JGtleSwgZnVuY3Rpb24gKCRzdGF0ZSwgJHNhdmUpIHVzZSAoJGEsICRwcm92aWRlciwgJHVybCkg
ewogICAgICAgICAgICBpZiAoIWVtcHR5KCRzdGF0ZVsnYWNjZXNzX3Rva2VuJ10pICYmICgkc3Rh
dGVbJ2V4cGlyZXNfYXQnXSA/PyAwKSA+IHRpbWUoKSArIDYwKSB7IHJldHVybiAkc3RhdGVbJ2Fj
Y2Vzc190b2tlbiddOyB9CiAgICAgICAgICAgICRmaWVsZHMgPSBbJ2dyYW50X3R5cGUnID0+ICdy
ZWZyZXNoX3Rva2VuJywgJ2NsaWVudF9pZCcgPT4gJGFbJ2NsaWVudF9pZCddLAogICAgICAgICAg
ICAgICAgJ3JlZnJlc2hfdG9rZW4nID0+ICRzdGF0ZVsncmVmcmVzaF90b2tlbiddID8/ICRhWydy
ZWZyZXNoX3Rva2VuJ11dOwogICAgICAgICAgICBpZiAoIWVtcHR5KCRhWydjbGllbnRfc2VjcmV0
J10pKSB7ICRmaWVsZHNbJ2NsaWVudF9zZWNyZXQnXSA9ICRhWydjbGllbnRfc2VjcmV0J107IH0K
ICAgICAgICAgICAgaWYgKCRwcm92aWRlciA9PT0gJ21pY3Jvc29mdCcpIHsgJGZpZWxkc1snc2Nv
cGUnXSA9ICdodHRwczovL291dGxvb2sub2ZmaWNlLmNvbS9TTVRQLlNlbmQgb2ZmbGluZV9hY2Nl
c3MnOyB9CiAgICAgICAgICAgICRyZXNwb25zZSA9ICR0aGlzLT5yZXF1ZXN0ID8gKCR0aGlzLT5y
ZXF1ZXN0KSgkdXJsLCAkZmllbGRzKSA6ICR0aGlzLT5zb2xpY2l0YXIoJHVybCwgJGZpZWxkcyk7
CiAgICAgICAgICAgIGlmICghaXNfYXJyYXkoJHJlc3BvbnNlKSB8fCBlbXB0eSgkcmVzcG9uc2Vb
J2FjY2Vzc190b2tlbiddKSB8fCAoaW50KSgkcmVzcG9uc2VbJ2V4cGlyZXNfaW4nXSA/PyAwKSA8
IDYwKSB7CiAgICAgICAgICAgICAgICB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9P
QVVUSF9BVVRIT1JJWkFUSU9OJyk7CiAgICAgICAgICAgIH0KICAgICAgICAgICAgJHRva2VuID0g
KHN0cmluZykkcmVzcG9uc2VbJ2FjY2Vzc190b2tlbiddOwogICAgICAgICAgICBpZiAoc3RybGVu
KCR0b2tlbikgPiAxNjM4NCB8fCBwcmVnX21hdGNoKCcvW1x4MDAtXHgyMFx4N2ZdLycsICR0b2tl
bikpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01BSUxfT0FVVEhfVE9LRU4nKTsgfQog
ICAgICAgICAgICAkc2F2ZShbJ2FjY2Vzc190b2tlbicgPT4gJHRva2VuLCAnZXhwaXJlc19hdCcg
PT4gdGltZSgpICsgbWluKChpbnQpJHJlc3BvbnNlWydleHBpcmVzX2luJ10sIDg2NDAwKSwKICAg
ICAgICAgICAgICAgICdyZWZyZXNoX3Rva2VuJyA9PiAkcmVzcG9uc2VbJ3JlZnJlc2hfdG9rZW4n
XSA/PyAkZmllbGRzWydyZWZyZXNoX3Rva2VuJ11dKTsKICAgICAgICAgICAgcmV0dXJuICR0b2tl
bjsKICAgICAgICB9KTsKICAgICAgICByZXR1cm4gJHRva2VuOwogICAgfQoKICAgIHByaXZhdGUg
ZnVuY3Rpb24gc29saWNpdGFyKHN0cmluZyAkdXJsLCBhcnJheSAkZmllbGRzKTogYXJyYXkKICAg
IHsKICAgICAgICBpZiAoIWZ1bmN0aW9uX2V4aXN0cygnY3VybF9pbml0JykpIHsgdGhyb3cgbmV3
IFJ1bnRpbWVFeGNlcHRpb24oJ01BSUxfT0FVVEhfQ1VSTCcpOyB9CiAgICAgICAgJGN1cmwgPSBj
dXJsX2luaXQoJHVybCk7CiAgICAgICAgJGJvZHkgPSAnJzsKICAgICAgICBjdXJsX3NldG9wdF9h
cnJheSgkY3VybCwgW0NVUkxPUFRfUE9TVCA9PiB0cnVlLCBDVVJMT1BUX1BPU1RGSUVMRFMgPT4g
aHR0cF9idWlsZF9xdWVyeSgkZmllbGRzKSwKICAgICAgICAgICAgQ1VSTE9QVF9IVFRQSEVBREVS
ID0+IFsnQ29udGVudC1UeXBlOiBhcHBsaWNhdGlvbi94LXd3dy1mb3JtLXVybGVuY29kZWQnLCAn
QWNjZXB0OiBhcHBsaWNhdGlvbi9qc29uJ10sCiAgICAgICAgICAgIENVUkxPUFRfRk9MTE9XTE9D
QVRJT04gPT4gZmFsc2UsIENVUkxPUFRfQ09OTkVDVFRJTUVPVVQgPT4gMTAsIENVUkxPUFRfVElN
RU9VVCA9PiAyNSwKICAgICAgICAgICAgQ1VSTE9QVF9TU0xfVkVSSUZZUEVFUiA9PiB0cnVlLCBD
VVJMT1BUX1NTTF9WRVJJRllIT1NUID0+IDIsCiAgICAgICAgICAgIENVUkxPUFRfV1JJVEVGVU5D
VElPTiA9PiBzdGF0aWMgZnVuY3Rpb24gKCRjLCAkYnl0ZXMpIHVzZSAoJiRib2R5KSB7CiAgICAg
ICAgICAgICAgICBpZiAoc3RybGVuKCRib2R5KSArIHN0cmxlbigkYnl0ZXMpID4gNjU1MzYpIHsg
cmV0dXJuIDA7IH0KICAgICAgICAgICAgICAgICRib2R5IC49ICRieXRlczsgcmV0dXJuIHN0cmxl
bigkYnl0ZXMpOwogICAgICAgICAgICB9XSk7CiAgICAgICAgdHJ5IHsKICAgICAgICAgICAgJG9r
ID0gY3VybF9leGVjKCRjdXJsKTsKICAgICAgICAgICAgJHN0YXR1cyA9IChpbnQpY3VybF9nZXRp
bmZvKCRjdXJsLCBDVVJMSU5GT19IVFRQX0NPREUpOwogICAgICAgICAgICBpZiAoJG9rID09PSBm
YWxzZSB8fCAkc3RhdHVzICE9PSAyMDApIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01B
SUxfT0FVVEhfQVVUSE9SSVpBVElPTicpOyB9CiAgICAgICAgICAgICRyZXN1bHQgPSBqc29uX2Rl
Y29kZSgkYm9keSwgdHJ1ZSk7CiAgICAgICAgICAgIHJldHVybiBpc19hcnJheSgkcmVzdWx0KSA/
ICRyZXN1bHQgOiBbXTsKICAgICAgICB9IGZpbmFsbHkgeyBjdXJsX2Nsb3NlKCRjdXJsKTsgfQog
ICAgfQp9Cg==
CENTRAL_HELPER_1
 [[ "$(digest "$TMP/stage/app/services/CorreoOAuthTokenProvider.php")" == '9cc9b6266b9ea465a6ce30e33013f3520c4db7d8ef5340e7ae4e4dfbe9a833b7' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoOAuthTokenProvider.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoOAuthTokenProvider.php")"
  case "$existing" in 12410743a04b0862df82f45a3f64e8b48aebba66b1b1f1f110ae33778abe8cae|9cc9b6266b9ea465a6ce30e33013f3520c4db7d8ef5340e7ae4e4dfbe9a833b7) ;; *) fail 'Dependencia personalizada: app/services/CorreoOAuthTokenProvider.php. No se sobrescribe.';; esac
  if [[ "$existing" == '9cc9b6266b9ea465a6ce30e33013f3520c4db7d8ef5340e7ae4e4dfbe9a833b7' ]];then cp -p -- "$LIVE/app/services/CorreoOAuthTokenProvider.php" "$TMP/stage/app/services/CorreoOAuthTokenProvider.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoOAuthTokenProvider.php" "$TMP/stage/app/services/CorreoOAuthTokenProvider.php";chmod --reference="$LIVE/app/services/CorreoOAuthTokenProvider.php" "$TMP/stage/app/services/CorreoOAuthTokenProvider.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoOAuthTokenProvider.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoSmtpTransport.php" <<'CENTRAL_HELPER_2'
PD9waHAKCnJlcXVpcmVfb25jZSBkaXJuYW1lKF9fRElSX18sIDIpIC4gJy92ZW5kb3IvYXV0b2xv
YWQucGhwJzsKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb09BdXRoVG9rZW5Qcm92aWRl
ci5waHAnOwoKY2xhc3MgQ29ycmVvU210cFNlc3Npb24gZXh0ZW5kcyBcUEhQTWFpbGVyXFBIUE1h
aWxlclxTTVRQCnsKICAgIHB1YmxpYyAkZGF0YVN0YXJ0ZWQgPSBmYWxzZTsKICAgIHB1YmxpYyBm
dW5jdGlvbiBkYXRhKCRtc2dfZGF0YSkgeyAkdGhpcy0+ZGF0YVN0YXJ0ZWQgPSB0cnVlOyByZXR1
cm4gcGFyZW50OjpkYXRhKCRtc2dfZGF0YSk7IH0KfQoKZmluYWwgY2xhc3MgQ29ycmVvU210cFRy
YW5zcG9ydAp7CiAgICBwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIGNvbmZpZ3VyYXIoXFBIUE1haWxl
clxQSFBNYWlsZXJcUEhQTWFpbGVyICRtYWlsLCBhcnJheSAkYSk6IHZvaWQKICAgIHsKICAgICAg
ICAkaG9zdCA9IHRyaW0oKHN0cmluZykoJGFbJ3NtdHBfaG9zdCddID8/ICcnKSk7CiAgICAgICAg
JHBvcnQgPSAoaW50KSgkYVsnc210cF9wb3J0J10gPz8gNTg3KTsKICAgICAgICAkdGxzID0gc3Ry
dG9sb3dlcigoc3RyaW5nKSgkYVsnc210cF9lbmNyeXB0aW9uJ10gPz8gJ3RscycpKTsKICAgICAg
ICAkYXV0aCA9IHN0cnRvbG93ZXIoKHN0cmluZykoJGFbJ2F1dGhfbWV0aG9kJ10gPz8gJ3Bhc3N3
b3JkJykpOwogICAgICAgIGlmICghcHJlZ19tYXRjaCgnL15bYS16QS1aMC05Li1dKyQvRCcsICRo
b3N0KSB8fCAkcG9ydCA8IDEgfHwgJHBvcnQgPiA2NTUzNSB8fAogICAgICAgICAgICAhaW5fYXJy
YXkoJHRscywgWydzc2wnLCAndGxzJ10sIHRydWUpIHx8IGVtcHR5KCRhWydzbXRwX3VzZXJuYW1l
J10pIHx8ICFpbl9hcnJheSgkYXV0aCwgWydwYXNzd29yZCcsICdvYXV0aDInXSwgdHJ1ZSkgfHwK
ICAgICAgICAgICAgKCRhdXRoID09PSAncGFzc3dvcmQnICYmIGVtcHR5KCRhWydzbXRwX3Bhc3N3
b3JkJ10pKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9TTVRQX0NPTkZJRycp
OyB9CiAgICAgICAgJG1haWwtPmlzU01UUCgpOyAkbWFpbC0+SG9zdCA9ICRob3N0OyAkbWFpbC0+
UG9ydCA9ICRwb3J0OyAkbWFpbC0+U01UUEF1dGggPSB0cnVlOwogICAgICAgICRtYWlsLT5Vc2Vy
bmFtZSA9IChzdHJpbmcpJGFbJ3NtdHBfdXNlcm5hbWUnXTsgJG1haWwtPkNoYXJTZXQgPSAnVVRG
LTgnOwogICAgICAgICRtYWlsLT5TTVRQU2VjdXJlID0gJHRscyA9PT0gJ3NzbCcgPyAnc3NsJyA6
ICd0bHMnOyAkbWFpbC0+U01UUEF1dG9UTFMgPSB0cnVlOwogICAgICAgICRtYWlsLT5UaW1lb3V0
ID0gMTU7ICRtYWlsLT5TTVRQRGVidWcgPSAwOwogICAgICAgICRzc2wgPSBbJ3ZlcmlmeV9wZWVy
JyA9PiB0cnVlLCAndmVyaWZ5X3BlZXJfbmFtZScgPT4gdHJ1ZSwgJ2FsbG93X3NlbGZfc2lnbmVk
JyA9PiBmYWxzZV07CiAgICAgICAgaWYgKCFlbXB0eSgkYVsnc210cF9jYV9maWxlJ10pKSB7CiAg
ICAgICAgICAgIGlmICghaXNfZmlsZSgkYVsnc210cF9jYV9maWxlJ10pKSB7IHRocm93IG5ldyBS
dW50aW1lRXhjZXB0aW9uKCdNQUlMX1NNVFBfQ0EnKTsgfQogICAgICAgICAgICAkc3NsWydjYWZp
bGUnXSA9ICRhWydzbXRwX2NhX2ZpbGUnXTsKICAgICAgICB9CiAgICAgICAgJG1haWwtPlNNVFBP
cHRpb25zID0gWydzc2wnID0+ICRzc2xdOwogICAgICAgIGlmICghKCRtYWlsLT5nZXRTTVRQSW5z
dGFuY2UoKSBpbnN0YW5jZW9mIENvcnJlb1NtdHBTZXNzaW9uKSkgeyAkbWFpbC0+c2V0U01UUElu
c3RhbmNlKG5ldyBDb3JyZW9TbXRwU2Vzc2lvbigpKTsgfQogICAgICAgICRtYWlsLT5nZXRTTVRQ
SW5zdGFuY2UoKS0+VGltZWxpbWl0ID0gMzA7CiAgICAgICAgaWYgKCRhdXRoID09PSAnb2F1dGgy
JykgewogICAgICAgICAgICAkbWFpbC0+QXV0aFR5cGUgPSAnWE9BVVRIMic7ICRtYWlsLT5zZXRP
QXV0aChuZXcgQ29ycmVvT0F1dGhUb2tlblByb3ZpZGVyKCRhKSk7CiAgICAgICAgfSBlbHNlIHsg
JG1haWwtPlBhc3N3b3JkID0gKHN0cmluZykkYVsnc210cF9wYXNzd29yZCddOyB9CiAgICB9Cgog
ICAgcHVibGljIHN0YXRpYyBmdW5jdGlvbiBlbnZpYXIoXFBIUE1haWxlclxQSFBNYWlsZXJcUEhQ
TWFpbGVyICRtYWlsLCBzdHJpbmcgJHB1cnBvc2UpOiBhcnJheQogICAgewogICAgICAgIHRyeSB7
CiAgICAgICAgICAgIGlmICghJG1haWwtPnNlbmQoKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2Vw
dGlvbignTUFJTF9TTVRQX1NFTkQnKTsgfQogICAgICAgICAgICBlcnJvcl9sb2coJ01BSUxfVFJB
TlNQT1JUICcgLiBqc29uX2VuY29kZShbJ3B1cnBvc2UnID0+ICRwdXJwb3NlLCAndHJhbnNwb3J0
JyA9PiAnU01UUCcsICdzdGF0dXMnID0+ICdBQ0VQVEFETyddKSk7CiAgICAgICAgICAgIHJldHVy
biBbJ29rJyA9PiB0cnVlLCAncHJvdmVlZG9yJyA9PiAnU01UUCcsICdlc3RhZG9fZW50cmVnYScg
PT4gJ0FDRVBUQURPJywKICAgICAgICAgICAgICAgICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+IGZh
bHNlLCAnbWVzc2FnZV9pZCcgPT4gJG1haWwtPmdldExhc3RNZXNzYWdlSUQoKV07CiAgICAgICAg
fSBjYXRjaCAoVGhyb3dhYmxlICRlcnJvcikgewogICAgICAgICAgICAkc210cCA9ICRtYWlsLT5n
ZXRTTVRQSW5zdGFuY2UoKTsgJGRldGFpbCA9ICRzbXRwLT5nZXRFcnJvcigpOwogICAgICAgICAg
ICAkY29kZSA9IChpbnQpKCRkZXRhaWxbJ3NtdHBfY29kZSddID8/IDApOwogICAgICAgICAgICAk
dW5rbm93biA9ICRzbXRwIGluc3RhbmNlb2YgQ29ycmVvU210cFNlc3Npb24gJiYgJHNtdHAtPmRh
dGFTdGFydGVkICYmICgkY29kZSA8IDQwMCB8fCAkY29kZSA+IDU5OSk7CiAgICAgICAgICAgICRy
ZWFzb24gPSBwcmVnX21hdGNoKCcvXk1BSUxfW0EtWl9dKyQvRCcsICRlcnJvci0+Z2V0TWVzc2Fn
ZSgpKSA/ICRlcnJvci0+Z2V0TWVzc2FnZSgpIDogJ01BSUxfU01UUF9TRU5EJzsKICAgICAgICAg
ICAgZXJyb3JfbG9nKCdNQUlMX1RSQU5TUE9SVCAnIC4ganNvbl9lbmNvZGUoWydwdXJwb3NlJyA9
PiAkcHVycG9zZSwgJ3RyYW5zcG9ydCcgPT4gJ1NNVFAnLCAnc3RhdHVzJyA9PiAkdW5rbm93biA/
ICdERVNDT05PQ0lETycgOiAnUkVDSEFaQURPJywKICAgICAgICAgICAgICAgICdzbXRwX2NvZGUn
ID0+ICRjb2RlLCAncmVhc29uJyA9PiAkcmVhc29uLCAnZXhjZXB0aW9uJyA9PiBnZXRfY2xhc3Mo
JGVycm9yKV0pKTsKICAgICAgICAgICAgcmV0dXJuIFsnb2snID0+IGZhbHNlLCAnY29kaWdvX2h0
dHAnID0+IDUwMiwgJ2VzdGFkb19lbnRyZWdhJyA9PiAkdW5rbm93biA/ICdERVNDT05PQ0lETycg
OiAnUkVDSEFaQURPJywKICAgICAgICAgICAgICAgICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+IGZh
bHNlLCAnbWVuc2FqZScgPT4gJHVua25vd24KICAgICAgICAgICAgICAgICAgICA/ICdFbCByZXN1
bHRhZG8gZGVsIGVudsOtbyBlcyBkZXNjb25vY2lkby4gQ29tcHJ1ZWJhIGVsIHByb3ZlZWRvciBh
bnRlcyBkZSB2b2x2ZXIgYSBlbnZpYXIgcGFyYSBldml0YXIgZHVwbGljYWRvcy4nCiAgICAgICAg
ICAgICAgICAgICAgOiAnRWwgcHJvdmVlZG9yIG5vIGFjZXB0w7MgZWwgY29ycmVvLiBSZXZpc2Eg
bGEgY29uZXhpw7NuLCBsYSBhdXRlbnRpY2FjacOzbiB5IGxvcyBwZXJtaXNvcyBkZSBlbnbDrW8g
ZGUgbGEgY3VlbnRhLicsCiAgICAgICAgICAgICAgICAnbWVuc2FqZV90ZWNuaWNvJyA9PiAkcmVh
c29uXTsKICAgICAgICB9CiAgICB9Cn0K
CENTRAL_HELPER_2
 [[ "$(digest "$TMP/stage/app/services/CorreoSmtpTransport.php")" == '82907f0ae9ad636e6efddb9dd8e06cd94a280dcc64ac8240b936adb385c6e920' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoSmtpTransport.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoSmtpTransport.php")"
  case "$existing" in 82907f0ae9ad636e6efddb9dd8e06cd94a280dcc64ac8240b936adb385c6e920|9ed5e442354f71eeb0bc61e73b21aa44c42d93f410fe1437f99eb5cf840ecc46) ;; *) fail 'Dependencia personalizada: app/services/CorreoSmtpTransport.php. No se sobrescribe.';; esac
  if [[ "$existing" == '82907f0ae9ad636e6efddb9dd8e06cd94a280dcc64ac8240b936adb385c6e920' ]];then cp -p -- "$LIVE/app/services/CorreoSmtpTransport.php" "$TMP/stage/app/services/CorreoSmtpTransport.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoSmtpTransport.php" "$TMP/stage/app/services/CorreoSmtpTransport.php";chmod --reference="$LIVE/app/services/CorreoSmtpTransport.php" "$TMP/stage/app/services/CorreoSmtpTransport.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoSmtpTransport.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoEnvioControl.php" <<'CENTRAL_HELPER_3'
PD9waHAKCnJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9Fc3RhZG9Qcml2YWRvLnBocCc7
CgovKiogTm8gcmVpbnRlbnRhIGF1dG9tw6F0aWNhbWVudGUgb3BlcmFjaW9uZXMgYWNlcHRhZGFz
IG8gY29uIHJlc3VsdGFkbyBpbmNpZXJ0by4gKi8KZmluYWwgY2xhc3MgQ29ycmVvRW52aW9Db250
cm9sCnsKICAgIC8qKiBJbmNsdXllIGhpc3RvcmlhbDogcmVwZXRpciBsYSBwZXRpY2nDs24gZGV2
dWVsdmUgZWwgbWlzbW8gcmVnaXN0cm8uICovCiAgICBwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIHJl
Z2lzdHJhcihzdHJpbmcgJGtleSwgc3RyaW5nICRmaW5nZXJwcmludCwgY2FsbGFibGUgJG9wZXJh
dGlvbik6IGFycmF5CiAgICB7CiAgICAgICAgcmV0dXJuIENvcnJlb0VzdGFkb1ByaXZhZG86OmVq
ZWN1dGFyKCdtYXJrZXRpbmcnLCAka2V5LCBzdGF0aWMgZnVuY3Rpb24gKCRzdGF0ZSwgJHNhdmUp
IHVzZSAoJGZpbmdlcnByaW50LCAkb3BlcmF0aW9uKSB7CiAgICAgICAgICAgICRzdGF0dXMgPSAk
c3RhdGVbJ3N0YXR1cyddID8/ICcnOwogICAgICAgICAgICBpZiAoJHN0YXR1cyAhPT0gJ1JFQ0hB
WkFETycgJiYgJHN0YXRlICYmICFoYXNoX2VxdWFscygoc3RyaW5nKSgkc3RhdGVbJ2ZpbmdlcnBy
aW50J10gPz8gJycpLCAkZmluZ2VycHJpbnQpKSB7CiAgICAgICAgICAgICAgICByZXR1cm4gWydv
aycgPT4gZmFsc2UsICdjb2RpZ29faHR0cCcgPT4gNDA5LCAnbWVuc2FqZScgPT4gJ0VzdGEgcmVk
YWNjacOzbiB5YSB0aWVuZSB1biBlbnbDrW8gaW5pY2lhZG8uIENvbnN1bHRhIGVsIGhpc3Rvcmlh
bCBhbnRlcyBkZSBpbmljaWFyIG90cm8uJ107CiAgICAgICAgICAgIH0KICAgICAgICAgICAgaWYg
KCRzdGF0dXMgPT09ICdBQ0VQVEFETycpIHsgcmV0dXJuICRzdGF0ZVsncmVzdWx0J107IH0KICAg
ICAgICAgICAgaWYgKGluX2FycmF5KCRzdGF0dXMsIFsnc2VuZGluZycsICdERVNDT05PQ0lETydd
LCB0cnVlKSkgewogICAgICAgICAgICAgICAgcmV0dXJuIFsnb2snID0+IGZhbHNlLCAnY29kaWdv
X2h0dHAnID0+IDQwOSwgJ2VzdGFkb19lbnRyZWdhJyA9PiAnREVTQ09OT0NJRE8nLAogICAgICAg
ICAgICAgICAgICAgICdtZW5zYWplJyA9PiAnRWwgZW52w61vIGFudGVyaW9yIGVzdMOhIGVuIGN1
cnNvIG8gc2luIGNvbmZpcm1hci4gUmV2aXNhIGVsIHByb3ZlZWRvciB5IGVsIGhpc3RvcmlhbCBh
bnRlcyBkZSByZXBldGlybG8uJ107CiAgICAgICAgICAgIH0KICAgICAgICAgICAgJHNhdmUoWydm
aW5nZXJwcmludCcgPT4gJGZpbmdlcnByaW50LCAnc3RhdHVzJyA9PiAnc2VuZGluZycsICd0aW1l
JyA9PiB0aW1lKCldKTsKICAgICAgICAgICAgJHJlc3VsdCA9ICRvcGVyYXRpb24oKTsKICAgICAg
ICAgICAgJHN0YXR1cyA9ICRyZXN1bHRbJ2VzdGFkb19lbnRyZWdhJ10gPz8gKCFlbXB0eSgkcmVz
dWx0WydvayddKSA/ICdBQ0VQVEFETycgOiAnUkVDSEFaQURPJyk7CiAgICAgICAgICAgICRzYXZl
KFsnZmluZ2VycHJpbnQnID0+ICRmaW5nZXJwcmludCwgJ3N0YXR1cycgPT4gJHN0YXR1cywgJ3Rp
bWUnID0+IHRpbWUoKSwgJ3Jlc3VsdCcgPT4gJHJlc3VsdF0pOwogICAgICAgICAgICByZXR1cm4g
JHJlc3VsdDsKICAgICAgICB9KTsKICAgIH0KCiAgICBwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIGVq
ZWN1dGFyKHN0cmluZyAka2V5LCBzdHJpbmcgJGZpbmdlcnByaW50LCBjYWxsYWJsZSAkc2VuZCwg
Ym9vbCAkZXhwbGljaXQgPSB0cnVlKTogYXJyYXkKICAgIHsKICAgICAgICByZXR1cm4gQ29ycmVv
RXN0YWRvUHJpdmFkbzo6ZWplY3V0YXIoJ3NlbmQnLCAka2V5LCBzdGF0aWMgZnVuY3Rpb24gKCRz
dGF0ZSwgJHNhdmUpIHVzZSAoJGZpbmdlcnByaW50LCAkc2VuZCwgJGV4cGxpY2l0KSB7CiAgICAg
ICAgICAgIGlmICgoJHN0YXRlWydzdGF0dXMnXSA/PyAnJykgIT09ICdSRUNIQVpBRE8nICYmICRz
dGF0ZSAmJiAhaGFzaF9lcXVhbHMoKHN0cmluZykoJHN0YXRlWydmaW5nZXJwcmludCddID8/ICcn
KSwgJGZpbmdlcnByaW50KSkgewogICAgICAgICAgICAgICAgcmV0dXJuIFsnb2snID0+IGZhbHNl
LCAnY29kaWdvX2h0dHAnID0+IDQwOSwgJ21lbnNhamUnID0+ICdMYSBzb2xpY2l0dWQgZGUgZW52
w61vIGNhbWJpw7MuIEFicmUgdW5hIG51ZXZhIHJlZGFjY2nDs24gYW50ZXMgZGUgZW52aWFyLidd
OwogICAgICAgICAgICB9CiAgICAgICAgICAgIGlmIChpbl9hcnJheSgkc3RhdGVbJ3N0YXR1cydd
ID8/ICcnLCBbJ3NlbmRpbmcnLCAnREVTQ09OT0NJRE8nXSwgdHJ1ZSkpIHsKICAgICAgICAgICAg
ICAgIHJldHVybiBbJ29rJyA9PiBmYWxzZSwgJ2NvZGlnb19odHRwJyA9PiA0MDksICdlc3RhZG9f
ZW50cmVnYScgPT4gJ0RFU0NPTk9DSURPJywKICAgICAgICAgICAgICAgICAgICAnbWVuc2FqZScg
PT4gJ0hheSB1biBlbnbDrW8gZW4gY3Vyc28gbyBjb24gcmVzdWx0YWRvIGRlc2Nvbm9jaWRvLiBD
b21wcnVlYmEgZWwgcHJvdmVlZG9yIHkgZWwgaGlzdG9yaWFsIGFudGVzIGRlIHJlcGV0aXJsby4n
XTsKICAgICAgICAgICAgfQogICAgICAgICAgICBpZiAoKCRzdGF0ZVsnc3RhdHVzJ10gPz8gJycp
ID09PSAnQUNFUFRBRE8nICYmICgkZXhwbGljaXQgfHwgdGltZSgpIC0gKGludCkoJHN0YXRlWyd0
aW1lJ10gPz8gMCkgPCA2MCkpIHsKICAgICAgICAgICAgICAgIHJldHVybiBbJ29rJyA9PiBmYWxz
ZSwgJ2NvZGlnb19odHRwJyA9PiA0MDksICdlc3RhZG9fZW50cmVnYScgPT4gJ0FDRVBUQURPJywK
ICAgICAgICAgICAgICAgICAgICAnbWVuc2FqZScgPT4gJ0VsIHByb3ZlZWRvciB5YSBhY2VwdMOz
IGVzdGEgc29saWNpdHVkLiBObyBzZSBlbnZpYXLDoSBvdHJhIGNvcGlhOyBjb25zdWx0YSBlbCBo
aXN0b3JpYWwuJ107CiAgICAgICAgICAgIH0KICAgICAgICAgICAgJHNhdmUoWydmaW5nZXJwcmlu
dCcgPT4gJGZpbmdlcnByaW50LCAnc3RhdHVzJyA9PiAnc2VuZGluZycsICd0aW1lJyA9PiB0aW1l
KCldKTsKICAgICAgICAgICAgLy8gU2kgZWwgcHJvY2VzbyB0ZXJtaW5hIG8gZmFsbGEgZWwgZ3Vh
cmRhZG8sIHBlcnNpc3RlIHNlbmRpbmc6IG5vIHJlZW52aWFyIGEgY2llZ2FzLgogICAgICAgICAg
ICAkcmVzdWx0ID0gJHNlbmQoKTsKICAgICAgICAgICAgJHN0YXR1cyA9ICRyZXN1bHRbJ2VzdGFk
b19lbnRyZWdhJ10gPz8gKCFlbXB0eSgkcmVzdWx0WydvayddKSA/ICdBQ0VQVEFETycgOiAnUkVD
SEFaQURPJyk7CiAgICAgICAgICAgICRzYXZlKFsnZmluZ2VycHJpbnQnID0+ICRmaW5nZXJwcmlu
dCwgJ3N0YXR1cycgPT4gJHN0YXR1cywgJ3RpbWUnID0+IHRpbWUoKSwgJ21lc3NhZ2VfaWQnID0+
ICRyZXN1bHRbJ21lc3NhZ2VfaWQnXSA/PyAnJ10pOwogICAgICAgICAgICByZXR1cm4gJHJlc3Vs
dDsKICAgICAgICB9KTsKICAgIH0KfQo=
CENTRAL_HELPER_3
 [[ "$(digest "$TMP/stage/app/services/CorreoEnvioControl.php")" == 'cdaf50a97248fe0ea1a7af2e59050ecdb2f20a0c443ca2346b421b7f1cc39e68' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoEnvioControl.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoEnvioControl.php")"
  case "$existing" in cdaf50a97248fe0ea1a7af2e59050ecdb2f20a0c443ca2346b421b7f1cc39e68) ;; *) fail 'Dependencia personalizada: app/services/CorreoEnvioControl.php. No se sobrescribe.';; esac
  if [[ "$existing" == 'cdaf50a97248fe0ea1a7af2e59050ecdb2f20a0c443ca2346b421b7f1cc39e68' ]];then cp -p -- "$LIVE/app/services/CorreoEnvioControl.php" "$TMP/stage/app/services/CorreoEnvioControl.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoEnvioControl.php" "$TMP/stage/app/services/CorreoEnvioControl.php";chmod --reference="$LIVE/app/services/CorreoEnvioControl.php" "$TMP/stage/app/services/CorreoEnvioControl.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoEnvioControl.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoLimiteService.php" <<'CENTRAL_HELPER_4'
PD9waHAKCnJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9Fc3RhZG9Qcml2YWRvLnBocCc7
CgpmaW5hbCBjbGFzcyBDb3JyZW9MaW1pdGVTZXJ2aWNlCnsKICAgIHB1YmxpYyBzdGF0aWMgZnVu
Y3Rpb24gdG9tYXIoc3RyaW5nICRwdXJwb3NlLCBpbnQgJHVzZXIsIGludCAkbWF4LCBpbnQgJHdp
bmRvdyk6IGJvb2wKICAgIHsKICAgICAgICBpZiAoJHVzZXIgPD0gMCB8fCAkbWF4IDwgMSB8fCAk
d2luZG93IDwgMSkgeyByZXR1cm4gZmFsc2U7IH0KICAgICAgICByZXR1cm4gc2VsZjo6dG9tYXJD
bGF2ZSgkcHVycG9zZSwgKHN0cmluZykkdXNlciwgJG1heCwgJHdpbmRvdyk7CiAgICB9CiAgICBw
dWJsaWMgc3RhdGljIGZ1bmN0aW9uIHRvbWFyQ2xhdmUoc3RyaW5nICRwdXJwb3NlLCBzdHJpbmcg
JGtleSwgaW50ICRtYXgsIGludCAkd2luZG93KTogYm9vbAogICAgewogICAgICAgIGlmICgka2V5
ID09PSAnJyB8fCAkbWF4IDwgMSB8fCAkd2luZG93IDwgMSkgeyByZXR1cm4gZmFsc2U7IH0KICAg
ICAgICByZXR1cm4gQ29ycmVvRXN0YWRvUHJpdmFkbzo6ZWplY3V0YXIoJ21haWwtcmF0ZScsICRw
dXJwb3NlIC4gJzonIC4gaGFzaCgnc2hhMjU2JywgJGtleSksIHN0YXRpYyBmdW5jdGlvbiAoJHN0
YXRlLCAkc2F2ZSkgdXNlICgkbWF4LCAkd2luZG93KSB7CiAgICAgICAgICAgICRub3cgPSB0aW1l
KCk7CiAgICAgICAgICAgICR0aW1lcyA9IGFycmF5X3ZhbHVlcyhhcnJheV9maWx0ZXIoJHN0YXRl
Wyd0aW1lcyddID8/IFtdLCBzdGF0aWMgZnVuY3Rpb24gKCRhdCkgdXNlICgkbm93LCAkd2luZG93
KSB7IHJldHVybiBpc19pbnQoJGF0KSAmJiAkYXQgPiAkbm93IC0gJHdpbmRvdzsgfSkpOwogICAg
ICAgICAgICBpZiAoY291bnQoJHRpbWVzKSA+PSAkbWF4KSB7IHJldHVybiBmYWxzZTsgfQogICAg
ICAgICAgICAkdGltZXNbXSA9ICRub3c7ICRzYXZlKFsndGltZXMnID0+ICR0aW1lc10pOwogICAg
ICAgICAgICByZXR1cm4gdHJ1ZTsKICAgICAgICB9KTsKICAgIH0KfQo=
CENTRAL_HELPER_4
 [[ "$(digest "$TMP/stage/app/services/CorreoLimiteService.php")" == 'ad77c109c4a49abc19e33a69951895de962a495d51ab484c15ff0d43472b0362' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoLimiteService.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoLimiteService.php")"
  case "$existing" in ad77c109c4a49abc19e33a69951895de962a495d51ab484c15ff0d43472b0362|c5ce21caca2ce84fce997c028be3bad9538613feac0d98f9234dcaf8676a30ad) ;; *) fail 'Dependencia personalizada: app/services/CorreoLimiteService.php. No se sobrescribe.';; esac
  if [[ "$existing" == 'ad77c109c4a49abc19e33a69951895de962a495d51ab484c15ff0d43472b0362' ]];then cp -p -- "$LIVE/app/services/CorreoLimiteService.php" "$TMP/stage/app/services/CorreoLimiteService.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoLimiteService.php" "$TMP/stage/app/services/CorreoLimiteService.php";chmod --reference="$LIVE/app/services/CorreoLimiteService.php" "$TMP/stage/app/services/CorreoLimiteService.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoLimiteService.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoRegistroEstadoService.php" <<'CENTRAL_HELPER_5'
PD9waHAKCnJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9Fc3RhZG9Qcml2YWRvLnBocCc7
CgovKiogRXZpZGVuY2lhIGRlbCB0cmFuc3BvcnRlIHBvciByZWdpc3RybywgZnVlcmEgZGUgbGEg
QkQgeSBzaW4gYWx0ZXJhciBoaXN0w7NyaWNvcy4gKi8KZmluYWwgY2xhc3MgQ29ycmVvUmVnaXN0
cm9Fc3RhZG9TZXJ2aWNlCnsKICAgIHByaXZhdGUgc3RhdGljICRyZXBvcnRlZCA9IGZhbHNlOwog
ICAgcHVibGljIHN0YXRpYyBmdW5jdGlvbiBndWFyZGFyKGludCAkdXNlciwgaW50ICRpZCwgc3Ry
aW5nICRzdGF0dXMsIGFycmF5ICRyZXN1bHQgPSBbXSk6IHZvaWQKICAgIHsKICAgICAgICBpZiAo
JHVzZXIgPD0gMCB8fCAkaWQgPD0gMCB8fCAhaW5fYXJyYXkoJHN0YXR1cywgWydQRU5ESUVOVEUn
LCAnRU5fUFJPQ0VTTycsICdBQ0VQVEFETycsICdGQUxMSURPJywgJ0RFU0NPTk9DSURPJ10sIHRy
dWUpKSB7CiAgICAgICAgICAgIHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1JFQ09S
RF9TVEFURScpOwogICAgICAgIH0KICAgICAgICBDb3JyZW9Fc3RhZG9Qcml2YWRvOjplamVjdXRh
cignbWFpbC1yZWNvcmQnLCAkdXNlciAuICc6JyAuICRpZCwgc3RhdGljIGZ1bmN0aW9uICgkb2xk
LCAkc2F2ZSkgdXNlICgkc3RhdHVzLCAkcmVzdWx0KSB7CiAgICAgICAgICAgICRzYXZlKFsnZXN0
YWRvJyA9PiAkc3RhdHVzLCAnYXQnID0+IHRpbWUoKSwgJ3Byb3ZlZWRvcicgPT4gKHN0cmluZyko
JHJlc3VsdFsncHJvdmVlZG9yJ10gPz8gJycpLAogICAgICAgICAgICAgICAgJ21lc3NhZ2VfaWQn
ID0+IChzdHJpbmcpKCRyZXN1bHRbJ21lc3NhZ2VfaWQnXSA/PyAnJyksICdlbnRyZWdhX2NvbmZp
cm1hZGEnID0+IGZhbHNlLAogICAgICAgICAgICAgICAgJ3JlbWl0ZW50ZV92aXNpYmxlJyA9PiAo
c3RyaW5nKSgkcmVzdWx0WydyZW1pdGVudGVfdmlzaWJsZSddID8/ICcnKSwgJ3JlcGx5X3RvJyA9
PiAoc3RyaW5nKSgkcmVzdWx0WydyZXBseV90byddID8/ICcnKV0pOwogICAgICAgIH0pOwogICAg
fQoKICAgIHB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gY29uc3VsdGFyKGludCAkdXNlciwgaW50ICRp
ZCwgc3RyaW5nICRkYlN0YXR1cyk6IGFycmF5CiAgICB7CiAgICAgICAgdHJ5IHsgJHN0YXRlID0g
Q29ycmVvRXN0YWRvUHJpdmFkbzo6bGVlcignbWFpbC1yZWNvcmQnLCAkdXNlciAuICc6JyAuICRp
ZCk7IH0KICAgICAgICBjYXRjaCAoVGhyb3dhYmxlICRlKSB7CiAgICAgICAgICAgIGlmICghc2Vs
Zjo6JHJlcG9ydGVkKSB7IGVycm9yX2xvZygnTUFJTF9SRUNPUkRfUkVBRF9GQUlMRUQgZXhjZXB0
aW9uPScgLiBnZXRfY2xhc3MoJGUpKTsgc2VsZjo6JHJlcG9ydGVkID0gdHJ1ZTsgfQogICAgICAg
ICAgICAkc3RhdGUgPSBbJ2VzdGFkbycgPT4gJ0RFU0NPTk9DSURPJ107CiAgICAgICAgfQogICAg
ICAgICRzdGF0dXMgPSAkc3RhdGVbJ2VzdGFkbyddID8/IChzdHJ0b3VwcGVyKCRkYlN0YXR1cykg
PT09ICdFTlZJQURPJyA/ICdBQ0VQVEFETycgOiAnUEVORElFTlRFJyk7CiAgICAgICAgJGxhYmVs
cyA9IFsnUEVORElFTlRFJyA9PiAnUGVuZGllbnRlJywgJ0VOX1BST0NFU08nID0+ICdFbiBwcm9j
ZXNvJywgJ0FDRVBUQURPJyA9PiAnQWNlcHRhZG8gcG9yIHByb3ZlZWRvcicsCiAgICAgICAgICAg
ICdGQUxMSURPJyA9PiAnRmFsbGlkbycsICdERVNDT05PQ0lETycgPT4gJ1Jlc3VsdGFkbyBzaW4g
Y29uZmlybWFyJ107CiAgICAgICAgaWYgKCFpc3NldCgkbGFiZWxzWyRzdGF0dXNdKSkgeyAkc3Rh
dHVzID0gJ0RFU0NPTk9DSURPJzsgfQogICAgICAgICRsYWJlbCA9IGlzc2V0KCRzdGF0ZVsnZXN0
YWRvJ10pID8gJGxhYmVsc1skc3RhdHVzXSA6IG51bGw7CiAgICAgICAgaWYgKCRzdGF0dXMgPT09
ICdBQ0VQVEFETycgJiYgc3RydG91cHBlcigkZGJTdGF0dXMpICE9PSAnRU5WSUFETycpIHsgJGxh
YmVsID0gJ0FjZXB0YWRvOyByZWdpc3RybyBpbmNvbXBsZXRvJzsgfQogICAgICAgIHJldHVybiBb
J2VzdGFkb19lbnZpbycgPT4gJHN0YXR1cywgJ2V0aXF1ZXRhX2VudmlvJyA9PiAkbGFiZWwsICdl
bnRyZWdhX2NvbmZpcm1hZGEnID0+IGZhbHNlLAogICAgICAgICAgICAncmVtaXRlbnRlX3Zpc2li
bGUnID0+ICRzdGF0ZVsncmVtaXRlbnRlX3Zpc2libGUnXSA/PyAnJywgJ3JlcGx5X3RvJyA9PiAk
c3RhdGVbJ3JlcGx5X3RvJ10gPz8gJycsCiAgICAgICAgICAgICdyZWdpc3Ryb19jb25maXJtYWRv
JyA9PiBzdHJ0b3VwcGVyKCRkYlN0YXR1cykgPT09ICdFTlZJQURPJ107CiAgICB9Cn0K
CENTRAL_HELPER_5
 [[ "$(digest "$TMP/stage/app/services/CorreoRegistroEstadoService.php")" == 'a01a131b3789607154c817a41f8b5d81807cc3120be3cf8b63a4f0ad6a6e10ee' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoRegistroEstadoService.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoRegistroEstadoService.php")"
  case "$existing" in 6b85b12d8302e3f6972c37a4ccf8787d3b28c87d5db59a1f8e8f10cfe2fcd55a|a01a131b3789607154c817a41f8b5d81807cc3120be3cf8b63a4f0ad6a6e10ee) ;; *) fail 'Dependencia personalizada: app/services/CorreoRegistroEstadoService.php. No se sobrescribe.';; esac
  if [[ "$existing" == 'a01a131b3789607154c817a41f8b5d81807cc3120be3cf8b63a4f0ad6a6e10ee' ]];then cp -p -- "$LIVE/app/services/CorreoRegistroEstadoService.php" "$TMP/stage/app/services/CorreoRegistroEstadoService.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoRegistroEstadoService.php" "$TMP/stage/app/services/CorreoRegistroEstadoService.php";chmod --reference="$LIVE/app/services/CorreoRegistroEstadoService.php" "$TMP/stage/app/services/CorreoRegistroEstadoService.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoRegistroEstadoService.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoGmailApiTransport.php" <<'CENTRAL_HELPER_6'
PD9waHAKCnJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9PQXV0aFRva2VuUHJvdmlkZXIu
cGhwJzsKCi8qKiBFbnbDrW8gR21haWwgbWVkaWFudGUgTUlNRS9IVFRQUywgc2luIHVuYSBjb25l
eGnDs24gU01UUCBuaSBzdXN0aXR1Y2nDs24gZGUgcmVtaXRlbnRlLiAqLwpmaW5hbCBjbGFzcyBD
b3JyZW9HbWFpbEFwaVRyYW5zcG9ydAp7CiAgICBwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIGlkZW50
aWRhZChzdHJpbmcgJGVtYWlsLCBzdHJpbmcgJHRva2VuLCA/Y2FsbGFibGUgJHJlcXVlc3QgPSBu
dWxsKTogYm9vbAogICAgewogICAgICAgICRyID0gc2VsZjo6c29saWNpdGFyKCdHRVQnLCAnaHR0
cHM6Ly9vcGVuaWRjb25uZWN0Lmdvb2dsZWFwaXMuY29tL3YxL3VzZXJpbmZvJywgJHRva2VuLCBu
dWxsLCAkcmVxdWVzdCk7CiAgICAgICAgcmV0dXJuICFlbXB0eSgkclsnb2snXSkgJiYgKCRyWydq
c29uJ11bJ2VtYWlsX3ZlcmlmaWVkJ10gPz8gZmFsc2UpID09PSB0cnVlICYmCiAgICAgICAgICAg
IHN0cmNhc2VjbXAoKHN0cmluZykoJHJbJ2pzb24nXVsnZW1haWwnXSA/PyAnJyksICRlbWFpbCkg
PT09IDA7CiAgICB9CgogICAgcHVibGljIHN0YXRpYyBmdW5jdGlvbiBlbnZpYXIoXFBIUE1haWxl
clxQSFBNYWlsZXJcUEhQTWFpbGVyICRtYWlsLCBhcnJheSAkYWNjb3VudCwgP2NhbGxhYmxlICRy
ZXF1ZXN0ID0gbnVsbCk6IGFycmF5CiAgICB7CiAgICAgICAgJHBvc3RpbmcgPSBmYWxzZTsKICAg
ICAgICB0cnkgewogICAgICAgICAgICBpZiAoKCRhY2NvdW50WydvYXV0aF9wcm92aWRlciddID8/
ICcnKSAhPT0gJ2dvb2dsZScgfHwgc3RyY2FzZWNtcCgkbWFpbC0+RnJvbSwgKHN0cmluZykoJGFj
Y291bnRbJ2NvcnJlbyddID8/ICcnKSkgIT09IDApIHsKICAgICAgICAgICAgICAgIHRocm93IG5l
dyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX0dNQUlMX0lERU5USVRZJyk7CiAgICAgICAgICAgIH0K
ICAgICAgICAgICAgJHRva2VuID0gKG5ldyBDb3JyZW9PQXV0aFRva2VuUHJvdmlkZXIoJGFjY291
bnQpKS0+b2J0ZW5lckFjY2Vzc1Rva2VuKCk7CiAgICAgICAgICAgIGlmICghc2VsZjo6aWRlbnRp
ZGFkKCRtYWlsLT5Gcm9tLCAkdG9rZW4sICRyZXF1ZXN0KSkgeyB0aHJvdyBuZXcgUnVudGltZUV4
Y2VwdGlvbignTUFJTF9HTUFJTF9JREVOVElUWScpOyB9CiAgICAgICAgICAgIC8vIHByZVNlbmQg
Z2VuZXJhIE1JTUUgY29uIFRvL0NjOyBudW5jYSBzZSBsbGFtYSBhIHNlbmQoKS9wb3N0U2VuZCgp
LgogICAgICAgICAgICAkbWFpbC0+aXNTTVRQKCk7CiAgICAgICAgICAgIGlmICghJG1haWwtPnBy
ZVNlbmQoKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9HTUFJTF9NSU1FJyk7
IH0KICAgICAgICAgICAgJHJhdyA9IHJ0cmltKHN0cnRyKGJhc2U2NF9lbmNvZGUoJG1haWwtPmdl
dFNlbnRNSU1FTWVzc2FnZSgpKSwgJysvJywgJy1fJyksICc9Jyk7CiAgICAgICAgICAgICRwb3N0
aW5nID0gdHJ1ZTsKICAgICAgICAgICAgJHIgPSBzZWxmOjpzb2xpY2l0YXIoJ1BPU1QnLCAnaHR0
cHM6Ly9nbWFpbC5nb29nbGVhcGlzLmNvbS9nbWFpbC92MS91c2Vycy9tZS9tZXNzYWdlcy9zZW5k
JywgJHRva2VuLCBbJ3JhdycgPT4gJHJhd10sICRyZXF1ZXN0KTsKICAgICAgICAgICAgJGNvZGUg
PSAoaW50KSgkclsnY29kaWdvX2h0dHAnXSA/PyAwKTsgJGlkID0gJHJbJ2pzb24nXVsnaWQnXSA/
PyBudWxsOwogICAgICAgICAgICBpZiAoIWVtcHR5KCRyWydvayddKSAmJiBlbXB0eSgkclsnaW5j
aWVydG8nXSkgJiYgJGNvZGUgPT09IDIwMCAmJiBpc19zdHJpbmcoJGlkKSAmJiBwcmVnX21hdGNo
KCcvXlthLXpBLVowLTlfLV17MSwyNTZ9JC9EJywgJGlkKSkgewogICAgICAgICAgICAgICAgZXJy
b3JfbG9nKCdNQUlMX1RSQU5TUE9SVCB0cmFuc3BvcnQ9R01BSUxfQVBJIHN0YXR1cz1BQ0VQVEFE
TycpOwogICAgICAgICAgICAgICAgcmV0dXJuIFsnb2snID0+IHRydWUsICdwcm92ZWVkb3InID0+
ICdHTUFJTF9BUEknLCAnZXN0YWRvX2VudHJlZ2EnID0+ICdBQ0VQVEFETycsICdlbnRyZWdhX2Nv
bmZpcm1hZGEnID0+IGZhbHNlLCAnbWVzc2FnZV9pZCcgPT4gJGlkXTsKICAgICAgICAgICAgfQog
ICAgICAgICAgICAkdW5rbm93biA9ICFlbXB0eSgkclsnaW5jaWVydG8nXSkgfHwgJGNvZGUgPCA0
MDAgfHwgJGNvZGUgPj0gNTAwIHx8ICRjb2RlID09PSA0MDg7CiAgICAgICAgICAgIHJldHVybiBz
ZWxmOjplcnJvcigkdW5rbm93biwgJGNvZGUpOwogICAgICAgIH0gY2F0Y2ggKFRocm93YWJsZSAk
ZSkgewogICAgICAgICAgICBlcnJvcl9sb2coJ01BSUxfVFJBTlNQT1JUIHRyYW5zcG9ydD1HTUFJ
TF9BUEkgcGhhc2U9JyAuICgkcG9zdGluZyA/ICdwb3N0JyA6ICdwcmVwYXJhdGlvbicpIC4gJyBl
eGNlcHRpb249JyAuIGdldF9jbGFzcygkZSkpOwogICAgICAgICAgICByZXR1cm4gc2VsZjo6ZXJy
b3IoJHBvc3RpbmcsIDApOwogICAgICAgIH0KICAgIH0KCiAgICBwcml2YXRlIHN0YXRpYyBmdW5j
dGlvbiBlcnJvcihib29sICR1bmtub3duLCBpbnQgJGNvZGUpOiBhcnJheQogICAgewogICAgICAg
IGVycm9yX2xvZygnTUFJTF9UUkFOU1BPUlQgdHJhbnNwb3J0PUdNQUlMX0FQSSBzdGF0dXM9JyAu
ICgkdW5rbm93biA/ICdERVNDT05PQ0lETycgOiAnUkVDSEFaQURPJykgLiAnIGh0dHA9JyAuICRj
b2RlKTsKICAgICAgICByZXR1cm4gWydvaycgPT4gZmFsc2UsICdwcm92ZWVkb3InID0+ICdHTUFJ
TF9BUEknLCAnZXN0YWRvX2VudHJlZ2EnID0+ICR1bmtub3duID8gJ0RFU0NPTk9DSURPJyA6ICdS
RUNIQVpBRE8nLAogICAgICAgICAgICAnZW50cmVnYV9jb25maXJtYWRhJyA9PiBmYWxzZSwgJ2Nv
ZGlnb19odHRwJyA9PiA1MDIsICdtZW5zYWplJyA9PiAkdW5rbm93bgogICAgICAgICAgICAgICAg
PyAnR29vZ2xlIG5vIGNvbmZpcm3DsyBlbCByZXN1bHRhZG8uIFJldmlzYSBlbCBidXrDs24geSBl
bCBoaXN0b3JpYWwgYW50ZXMgZGUgcmVwZXRpciBlbCBlbnbDrW8uJwogICAgICAgICAgICAgICAg
OiAnR29vZ2xlIG5vIGFjZXB0w7MgZWwgY29ycmVvIGNvbiBlc3RhIGlkZW50aWRhZC4gUmV2aXNh
IGxhIGF1dG9yaXphY2nDs24geSBsb3MgcGVybWlzb3MgZGUgZW52w61vIGRlIGxhIGN1ZW50YS4n
XTsKICAgIH0KCiAgICBwcml2YXRlIHN0YXRpYyBmdW5jdGlvbiBzb2xpY2l0YXIoc3RyaW5nICRt
ZXRob2QsIHN0cmluZyAkdXJsLCBzdHJpbmcgJHRva2VuLCA/YXJyYXkgJHBheWxvYWQsID9jYWxs
YWJsZSAkcmVxdWVzdCk6IGFycmF5CiAgICB7CiAgICAgICAgaWYgKCRyZXF1ZXN0KSB7IHJldHVy
biAkcmVxdWVzdCgkbWV0aG9kLCAkdXJsLCAkdG9rZW4sICRwYXlsb2FkKTsgfQogICAgICAgICRj
ID0gY3VybF9pbml0KCR1cmwpOyAkYm9keSA9ICcnOwogICAgICAgICRoZWFkZXJzID0gWydBdXRo
b3JpemF0aW9uOiBCZWFyZXIgJyAuICR0b2tlbiwgJ0FjY2VwdDogYXBwbGljYXRpb24vanNvbidd
OwogICAgICAgIGN1cmxfc2V0b3B0X2FycmF5KCRjLCBbQ1VSTE9QVF9DVVNUT01SRVFVRVNUID0+
ICRtZXRob2QsIENVUkxPUFRfRk9MTE9XTE9DQVRJT04gPT4gZmFsc2UsCiAgICAgICAgICAgIENV
UkxPUFRfQ09OTkVDVFRJTUVPVVQgPT4gMTAsIENVUkxPUFRfVElNRU9VVCA9PiAzMCwgQ1VSTE9Q
VF9TU0xfVkVSSUZZUEVFUiA9PiB0cnVlLCBDVVJMT1BUX1NTTF9WRVJJRllIT1NUID0+IDIsCiAg
ICAgICAgICAgIENVUkxPUFRfV1JJVEVGVU5DVElPTiA9PiBzdGF0aWMgZnVuY3Rpb24gKCRjLCAk
Ynl0ZXMpIHVzZSAoJiRib2R5KSB7IGlmIChzdHJsZW4oJGJvZHkpICsgc3RybGVuKCRieXRlcykg
PiA2NTUzNikgeyByZXR1cm4gMDsgfSAkYm9keSAuPSAkYnl0ZXM7IHJldHVybiBzdHJsZW4oJGJ5
dGVzKTsgfV0pOwogICAgICAgIGlmICgkcGF5bG9hZCAhPT0gbnVsbCkgewogICAgICAgICAgICAk
aGVhZGVyc1tdID0gJ0NvbnRlbnQtVHlwZTogYXBwbGljYXRpb24vanNvbic7CiAgICAgICAgICAg
IGN1cmxfc2V0b3B0KCRjLCBDVVJMT1BUX1BPU1RGSUVMRFMsIGpzb25fZW5jb2RlKCRwYXlsb2Fk
LCBKU09OX1RIUk9XX09OX0VSUk9SKSk7CiAgICAgICAgfQogICAgICAgIGN1cmxfc2V0b3B0KCRj
LCBDVVJMT1BUX0hUVFBIRUFERVIsICRoZWFkZXJzKTsKICAgICAgICB0cnkgewogICAgICAgICAg
ICAkb2sgPSBjdXJsX2V4ZWMoJGMpOyAkc3RhdHVzID0gKGludCljdXJsX2dldGluZm8oJGMsIENV
UkxJTkZPX0hUVFBfQ09ERSk7CiAgICAgICAgICAgIHJldHVybiBbJ29rJyA9PiAkb2sgIT09IGZh
bHNlICYmICRzdGF0dXMgPj0gMjAwICYmICRzdGF0dXMgPCAzMDAsICdjb2RpZ29faHR0cCcgPT4g
JHN0YXR1cywKICAgICAgICAgICAgICAgICdpbmNpZXJ0bycgPT4gJG9rID09PSBmYWxzZSwgJ2pz
b24nID0+IGpzb25fZGVjb2RlKCRib2R5LCB0cnVlKSA/OiBbXV07CiAgICAgICAgfSBmaW5hbGx5
IHsgY3VybF9jbG9zZSgkYyk7IH0KICAgIH0KfQo=
CENTRAL_HELPER_6
 [[ "$(digest "$TMP/stage/app/services/CorreoGmailApiTransport.php")" == 'c5e42ed0166599f3d12050c2245b636ab0a9dd1999ec94d0a3319444d562ebc6' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoGmailApiTransport.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoGmailApiTransport.php")"
  case "$existing" in 8ef94d420c2a0bcce3c1e6a6e440b5372acaf7c120c04d57222fb20887d92c2b|c5e42ed0166599f3d12050c2245b636ab0a9dd1999ec94d0a3319444d562ebc6) ;; *) fail 'Dependencia personalizada: app/services/CorreoGmailApiTransport.php. No se sobrescribe.';; esac
  if [[ "$existing" == 'c5e42ed0166599f3d12050c2245b636ab0a9dd1999ec94d0a3319444d562ebc6' ]];then cp -p -- "$LIVE/app/services/CorreoGmailApiTransport.php" "$TMP/stage/app/services/CorreoGmailApiTransport.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoGmailApiTransport.php" "$TMP/stage/app/services/CorreoGmailApiTransport.php";chmod --reference="$LIVE/app/services/CorreoGmailApiTransport.php" "$TMP/stage/app/services/CorreoGmailApiTransport.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoGmailApiTransport.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoConexionOAuthService.php" <<'CENTRAL_HELPER_7'
PD9waHAKCnJlcXVpcmVfb25jZSBkaXJuYW1lKF9fRElSX18sIDIpIC4gJy9jb25maWcvcHJpdmF0
ZV9jb25maWcucGhwJzsKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb1NtdHBUcmFuc3Bv
cnQucGhwJzsKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0xpbWl0ZVNlcnZpY2UucGhw
JzsKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0dtYWlsQXBpVHJhbnNwb3J0LnBocCc7
CgovKiogQXV0b3JpemEgYnV6b25lcywgbm8gaW5pY2lhIHNlc2nDs24gZW4gZWwgQ1JNLiBMb3Mg
cGVybWlzb3MgZGVsIENSTSBubyBjYW1iaWFuLiAqLwpmaW5hbCBjbGFzcyBDb3JyZW9Db25leGlv
bk9BdXRoU2VydmljZQp7CiAgICBwcml2YXRlICRyZXF1ZXN0OwogICAgcHJpdmF0ZSAkcHJvYmU7
CiAgICBwcml2YXRlICRpZGVudGl0eUxvYWRlcjsKCiAgICBwdWJsaWMgZnVuY3Rpb24gX19jb25z
dHJ1Y3QoP2NhbGxhYmxlICRyZXF1ZXN0ID0gbnVsbCwgP2NhbGxhYmxlICRwcm9iZSA9IG51bGws
ID9jYWxsYWJsZSAkaWRlbnRpdHlMb2FkZXIgPSBudWxsKQogICAgewogICAgICAgICR0aGlzLT5y
ZXF1ZXN0ID0gJHJlcXVlc3Q7ICR0aGlzLT5wcm9iZSA9ICRwcm9iZTsgJHRoaXMtPmlkZW50aXR5
TG9hZGVyID0gJGlkZW50aXR5TG9hZGVyOwogICAgfQoKICAgIHB1YmxpYyBmdW5jdGlvbiBkaXNw
b25pYmxlcygpOiBhcnJheQogICAgewogICAgICAgICRvdXQgPSBbXTsKICAgICAgICBmb3JlYWNo
IChbJ2dvb2dsZSddIGFzICRwcm92aWRlcikgewogICAgICAgICAgICB0cnkgeyAkdGhpcy0+Y29u
ZmlnKCRwcm92aWRlcik7ICRvdXRbJHByb3ZpZGVyXSA9IHRydWU7IH0KICAgICAgICAgICAgY2F0
Y2ggKFRocm93YWJsZSAkZSkgeyAkb3V0WyRwcm92aWRlcl0gPSBmYWxzZTsgfQogICAgICAgIH0K
ICAgICAgICByZXR1cm4gJG91dDsKICAgIH0KCiAgICBwdWJsaWMgZnVuY3Rpb24gaW5pY2lhcihp
bnQgJHVzZXIsIHN0cmluZyAkZW1haWwsIHN0cmluZyAkcHJvdmlkZXIsIGFycmF5ICYkc2Vzc2lv
bik6IHN0cmluZwogICAgewogICAgICAgICR0aGlzLT5pZGVudGlkYWQoJHVzZXIsICRlbWFpbCwg
JHNlc3Npb24pOwogICAgICAgICR0aGlzLT5wb2xpdGljYSgkdXNlciwgJGVtYWlsKTsKICAgICAg
ICAkYSA9ICR0aGlzLT5jb25maWcoJHByb3ZpZGVyKTsKICAgICAgICBpZiAoIUNvcnJlb0xpbWl0
ZVNlcnZpY2U6OnRvbWFyKCdvYXV0aC1zdGFydCcsICR1c2VyLCAxMCwgNjAwKSkgeyB0aHJvdyBu
ZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9PQVVUSF9SQVRFJyk7IH0KICAgICAgICAkc3RhdGUg
PSBiaW4yaGV4KHJhbmRvbV9ieXRlcygzMikpOwogICAgICAgICR2ZXJpZmllciA9IHNlbGY6OmI2
NChyYW5kb21fYnl0ZXMoNDgpKTsKICAgICAgICAkYXR0ZW1wdHMgPSBhcnJheV9maWx0ZXIoJHNl
c3Npb25bJ21haWxfb2F1dGhfYXR0ZW1wdHMnXSA/PyBbXSwgc3RhdGljIGZ1bmN0aW9uICgkdikg
eyByZXR1cm4gKCR2WydleHBpcmVzJ10gPz8gMCkgPiB0aW1lKCk7IH0pOwogICAgICAgIGlmIChj
b3VudCgkYXR0ZW1wdHMpID49IDUpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01BSUxf
T0FVVEhfUkFURScpOyB9CiAgICAgICAgJGF0dGVtcHRzWyRzdGF0ZV0gPSBbJ3VzZXInID0+ICR1
c2VyLCAnZW1haWwnID0+ICRlbWFpbCwgJ3Byb3ZpZGVyJyA9PiAkcHJvdmlkZXIsCiAgICAgICAg
ICAgICd2ZXJpZmllcicgPT4gJHZlcmlmaWVyLCAnZXhwaXJlcycgPT4gdGltZSgpICsgNjAwLCAn
YXBwJyA9PiAkdGhpcy0+YXBwSWQoJGEpXTsKICAgICAgICAkc2Vzc2lvblsnbWFpbF9vYXV0aF9h
dHRlbXB0cyddID0gJGF0dGVtcHRzOwogICAgICAgICRwYXJhbXMgPSBbJ2NsaWVudF9pZCcgPT4g
JGFbJ2NsaWVudF9pZCddLCAncmVkaXJlY3RfdXJpJyA9PiAkYVsncmVkaXJlY3RfdXJpJ10sICdy
ZXNwb25zZV90eXBlJyA9PiAnY29kZScsCiAgICAgICAgICAgICdzY29wZScgPT4gJHRoaXMtPnNj
b3BlKCRwcm92aWRlciksICdzdGF0ZScgPT4gJHN0YXRlLCAnbG9naW5faGludCcgPT4gJGVtYWls
LAogICAgICAgICAgICAnY29kZV9jaGFsbGVuZ2UnID0+IHNlbGY6OmI2NChoYXNoKCdzaGEyNTYn
LCAkdmVyaWZpZXIsIHRydWUpKSwgJ2NvZGVfY2hhbGxlbmdlX21ldGhvZCcgPT4gJ1MyNTYnXTsK
ICAgICAgICBpZiAoJHByb3ZpZGVyID09PSAnZ29vZ2xlJykgeyAkcGFyYW1zICs9IFsnYWNjZXNz
X3R5cGUnID0+ICdvZmZsaW5lJywgJ3Byb21wdCcgPT4gJ2NvbnNlbnQnXTsgfQogICAgICAgIGVs
c2UgeyAkcGFyYW1zICs9IFsncmVzcG9uc2VfbW9kZScgPT4gJ3F1ZXJ5JywgJ3Byb21wdCcgPT4g
J3NlbGVjdF9hY2NvdW50J107IH0KICAgICAgICByZXR1cm4gJGFbJ2F1dGhvcml6ZV91cmwnXSAu
ICc/JyAuIGh0dHBfYnVpbGRfcXVlcnkoJHBhcmFtcywgJycsICcmJywgUEhQX1FVRVJZX1JGQzM5
ODYpOwogICAgfQoKICAgIHB1YmxpYyBmdW5jdGlvbiBjb21wbGV0YXIoaW50ICR1c2VyLCBhcnJh
eSAkcXVlcnksIGFycmF5ICYkc2Vzc2lvbik6IHN0cmluZwogICAgewogICAgICAgICRzdGF0ZSA9
IChzdHJpbmcpKCRxdWVyeVsnc3RhdGUnXSA/PyAnJyk7CiAgICAgICAgJGF0dGVtcHQgPSAkc2Vz
c2lvblsnbWFpbF9vYXV0aF9hdHRlbXB0cyddWyRzdGF0ZV0gPz8gbnVsbDsKICAgICAgICAvLyBD
b25zdW1pciBhbnRlcyBkZSBjb250YWN0YXIgY29uIGVsIHByb3ZlZWRvciwgdGFtYmnDqW4gZW4g
Y2FuY2VsYWNpb25lcyBvIGVycm9yZXMuCiAgICAgICAgdW5zZXQoJHNlc3Npb25bJ21haWxfb2F1
dGhfYXR0ZW1wdHMnXVskc3RhdGVdKTsKICAgICAgICBpZiAoIXByZWdfbWF0Y2goJy9eW2EtZjAt
OV17NjR9JC9EJywgJHN0YXRlKSB8fCAhaXNfYXJyYXkoJGF0dGVtcHQpIHx8CiAgICAgICAgICAg
IChpbnQpJGF0dGVtcHRbJ3VzZXInXSAhPT0gJHVzZXIgfHwgKGludCkkYXR0ZW1wdFsnZXhwaXJl
cyddIDwgdGltZSgpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX09BVVRIX1NU
QVRFJyk7IH0KICAgICAgICAkZW1haWwgPSAoc3RyaW5nKSRhdHRlbXB0WydlbWFpbCddOyAkcHJv
dmlkZXIgPSAoc3RyaW5nKSRhdHRlbXB0Wydwcm92aWRlciddOwogICAgICAgICR0aGlzLT5pZGVu
dGlkYWQoJHVzZXIsICRlbWFpbCwgJHNlc3Npb24pOyAkdGhpcy0+cG9saXRpY2EoJHVzZXIsICRl
bWFpbCk7CiAgICAgICAgJGEgPSAkdGhpcy0+Y29uZmlnKCRwcm92aWRlcik7CiAgICAgICAgaWYg
KCFoYXNoX2VxdWFscygkYXR0ZW1wdFsnYXBwJ10sICR0aGlzLT5hcHBJZCgkYSkpKSB7IHRocm93
IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX09BVVRIX0NPTkZJR19DSEFOR0VEJyk7IH0KICAg
ICAgICBpZiAoaXNzZXQoJHF1ZXJ5WydlcnJvciddKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2Vw
dGlvbignTUFJTF9PQVVUSF9ERU5JRUQnKTsgfQogICAgICAgICRjb2RlID0gKHN0cmluZykoJHF1
ZXJ5Wydjb2RlJ10gPz8gJycpOwogICAgICAgIGlmICgkY29kZSA9PT0gJycgfHwgc3RybGVuKCRj
b2RlKSA+IDgxOTIgfHwgcHJlZ19tYXRjaCgnL1tceDAwLVx4MjBceDdmXS8nLCAkY29kZSkpIHsg
dGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01BSUxfT0FVVEhfQ09ERScpOyB9CiAgICAgICAg
JGZpZWxkcyA9IFsnZ3JhbnRfdHlwZScgPT4gJ2F1dGhvcml6YXRpb25fY29kZScsICdjbGllbnRf
aWQnID0+ICRhWydjbGllbnRfaWQnXSwgJ3JlZGlyZWN0X3VyaScgPT4gJGFbJ3JlZGlyZWN0X3Vy
aSddLAogICAgICAgICAgICAnY29kZScgPT4gJGNvZGUsICdjb2RlX3ZlcmlmaWVyJyA9PiAkYXR0
ZW1wdFsndmVyaWZpZXInXV07CiAgICAgICAgaWYgKCFlbXB0eSgkYVsnY2xpZW50X3NlY3JldCdd
KSkgeyAkZmllbGRzWydjbGllbnRfc2VjcmV0J10gPSAkYVsnY2xpZW50X3NlY3JldCddOyB9CiAg
ICAgICAgaWYgKCRwcm92aWRlciA9PT0gJ21pY3Jvc29mdCcpIHsgJGZpZWxkc1snc2NvcGUnXSA9
ICR0aGlzLT5zY29wZSgkcHJvdmlkZXIpOyB9CiAgICAgICAgJHIgPSAkdGhpcy0+cmVxdWVzdCA/
ICgkdGhpcy0+cmVxdWVzdCkoJGFbJ3Rva2VuX3VybCddLCAkZmllbGRzKSA6ICR0aGlzLT50b2tl
bigkYVsndG9rZW5fdXJsJ10sICRmaWVsZHMpOwogICAgICAgICRhY2Nlc3MgPSAkdGhpcy0+dmFs
aWRhclRva2VuKCRyWydhY2Nlc3NfdG9rZW4nXSA/PyBudWxsKTsKICAgICAgICAkcmVmcmVzaCA9
ICR0aGlzLT52YWxpZGFyVG9rZW4oJHJbJ3JlZnJlc2hfdG9rZW4nXSA/PyBudWxsKTsKICAgICAg
ICAkZXhwaXJlcyA9IChpbnQpKCRyWydleHBpcmVzX2luJ10gPz8gMCk7CiAgICAgICAgaWYgKCRl
eHBpcmVzIDwgNjApIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01BSUxfT0FVVEhfVE9L
RU4nKTsgfQogICAgICAgIGlmIChpc3NldCgkclsnc2NvcGUnXSkpIHsKICAgICAgICAgICAgJHNj
b3BlcyA9IGlzX3N0cmluZygkclsnc2NvcGUnXSkgPyBwcmVnX3NwbGl0KCcvXHMrLycsIHRyaW0o
JHJbJ3Njb3BlJ10pKSA6IFtdOwogICAgICAgICAgICAkcmVxdWlyZWQgPSAkcHJvdmlkZXIgPT09
ICdnb29nbGUnID8gWydodHRwczovL3d3dy5nb29nbGVhcGlzLmNvbS9hdXRoL2dtYWlsLnNlbmQn
XSA6IFsnaHR0cHM6Ly9vdXRsb29rLm9mZmljZS5jb20vU01UUC5TZW5kJywgJ1NNVFAuU2VuZCdd
OwogICAgICAgICAgICBpZiAoIWFycmF5X2ludGVyc2VjdCgkcmVxdWlyZWQsICRzY29wZXMpKSB7
IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX09BVVRIX1NDT1BFJyk7IH0KICAgICAg
ICB9CiAgICAgICAgJGFjY291bnQgPSAkdGhpcy0+Y3VlbnRhKCRhLCAkZW1haWwsICRyZWZyZXNo
KTsKICAgICAgICAvLyBMYSBpZGVudGlkYWQgc2UgdmVyaWZpY2EgY29uIEdvb2dsZSBwb3IgSFRU
UFMgbyBjb24gU01UUCBBVVRIIGRlIE1pY3Jvc29mdC4KICAgICAgICAvLyBObyBzZSBjb25mw61h
IGVuIGxvZ2luX2hpbnQgbmkgZW4gdW4gSldUIHNpbiB2ZXJpZmljYXIuCiAgICAgICAgJHZhbGlk
ID0gJHRoaXMtPnByb2JlID8gKCR0aGlzLT5wcm9iZSkoJGFjY291bnQsICRhY2Nlc3MpIDogKCRw
cm92aWRlciA9PT0gJ2dvb2dsZScKICAgICAgICAgICAgPyBDb3JyZW9HbWFpbEFwaVRyYW5zcG9y
dDo6aWRlbnRpZGFkKCRlbWFpbCwgJGFjY2VzcykgOiAkdGhpcy0+dmVyaWZpY2FyU210cCgkYWNj
b3VudCwgJGFjY2VzcykpOwogICAgICAgIGlmICghJHZhbGlkKSB7IHRocm93IG5ldyBSdW50aW1l
RXhjZXB0aW9uKCdNQUlMX09BVVRIX01BSUxCT1gnKTsgfQogICAgICAgIENvcnJlb0VzdGFkb1By
aXZhZG86OmVqZWN1dGFyKCdvYXV0aCcsIENvcnJlb09BdXRoVG9rZW5Qcm92aWRlcjo6Y2xhdmVD
dWVudGEoJGFjY291bnQpLCBzdGF0aWMgZnVuY3Rpb24gKCRvbGQsICRzYXZlKSB1c2UgKCRhY2Nl
c3MsICRyZWZyZXNoLCAkZXhwaXJlcykgewogICAgICAgICAgICAkc2F2ZShbJ2FjY2Vzc190b2tl
bicgPT4gJGFjY2VzcywgJ3JlZnJlc2hfdG9rZW4nID0+ICRyZWZyZXNoLCAnZXhwaXJlc19hdCcg
PT4gdGltZSgpICsgbWluKCRleHBpcmVzLCA4NjQwMCldKTsKICAgICAgICB9KTsKICAgICAgICBD
b3JyZW9Fc3RhZG9Qcml2YWRvOjplamVjdXRhcignbWFpbC1jb25uZWN0aW9uJywgJHVzZXIgLiAn
OicgLiAkZW1haWwsIHN0YXRpYyBmdW5jdGlvbiAoJG9sZCwgJHNhdmUpIHVzZSAoJHByb3ZpZGVy
LCAkcmVmcmVzaCwgJGEpIHsKICAgICAgICAgICAgJHNhdmUoWydjb25uZWN0ZWQnID0+IHRydWUs
ICdwcm92aWRlcicgPT4gJHByb3ZpZGVyLCAncmVmcmVzaF90b2tlbicgPT4gJHJlZnJlc2gsICdh
cHAnID0+ICRhWydjbGllbnRfaWQnXSwgJ3RlbmFudCcgPT4gJGFbJ3RlbmFudCddLCAnYXQnID0+
IHRpbWUoKV0pOwogICAgICAgIH0pOwogICAgICAgIHJldHVybiAkZW1haWw7CiAgICB9CgogICAg
cHVibGljIGZ1bmN0aW9uIG9idGVuZXIoaW50ICR1c2VyLCBzdHJpbmcgJGVtYWlsKTogP2FycmF5
CiAgICB7CiAgICAgICAgJHIgPSBDb3JyZW9Fc3RhZG9Qcml2YWRvOjpsZWVyKCdtYWlsLWNvbm5l
Y3Rpb24nLCAkdXNlciAuICc6JyAuICRlbWFpbCk7CiAgICAgICAgaWYgKGVtcHR5KCRyWydjb25u
ZWN0ZWQnXSkpIHsgcmV0dXJuIG51bGw7IH0KICAgICAgICAkdGhpcy0+cG9saXRpY2EoJHVzZXIs
ICRlbWFpbCk7CiAgICAgICAgJGEgPSAkdGhpcy0+Y29uZmlnKChzdHJpbmcpJHJbJ3Byb3ZpZGVy
J10pOwogICAgICAgIGlmICghaGFzaF9lcXVhbHMoKHN0cmluZykkclsnYXBwJ10sIChzdHJpbmcp
JGFbJ2NsaWVudF9pZCddKSB8fCAkclsndGVuYW50J10gIT09ICRhWyd0ZW5hbnQnXSkgeyB0aHJv
dyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9PQVVUSF9DT05GSUdfQ0hBTkdFRCcpOyB9CiAg
ICAgICAgcmV0dXJuICR0aGlzLT5jdWVudGEoJGEsICRlbWFpbCwgKHN0cmluZykkclsncmVmcmVz
aF90b2tlbiddKTsKICAgIH0KCiAgICBwdWJsaWMgZnVuY3Rpb24gZGVzY29uZWN0YXIoaW50ICR1
c2VyLCBzdHJpbmcgJGVtYWlsLCBhcnJheSAkc2Vzc2lvbik6IHZvaWQKICAgIHsKICAgICAgICAk
dGhpcy0+aWRlbnRpZGFkKCR1c2VyLCAkZW1haWwsICRzZXNzaW9uKTsKICAgICAgICAkZ29vZ2xl
U2NvcGUgPSAkdGhpcy0+c2NvcGUoJ2dvb2dsZScpOwogICAgICAgIENvcnJlb0VzdGFkb1ByaXZh
ZG86OmVqZWN1dGFyKCdtYWlsLWNvbm5lY3Rpb24nLCAkdXNlciAuICc6JyAuICRlbWFpbCwgc3Rh
dGljIGZ1bmN0aW9uICgkciwgJHNhdmUpIHVzZSAoJGVtYWlsLCAkZ29vZ2xlU2NvcGUpIHsKICAg
ICAgICAgICAgaWYgKCFlbXB0eSgkclsnY29ubmVjdGVkJ10pKSB7CiAgICAgICAgICAgICAgICAk
YSA9IFsnb2F1dGhfcHJvdmlkZXInID0+ICRyWydwcm92aWRlciddLCAndGVuYW50JyA9PiAkclsn
dGVuYW50J10sCiAgICAgICAgICAgICAgICAgICAgJ3NtdHBfdXNlcm5hbWUnID0+ICRlbWFpbCwg
J2NsaWVudF9pZCcgPT4gJHJbJ2FwcCddLCAncmVmcmVzaF90b2tlbicgPT4gJHJbJ3JlZnJlc2hf
dG9rZW4nXV07CiAgICAgICAgICAgICAgICBpZiAoJHJbJ3Byb3ZpZGVyJ10gPT09ICdnb29nbGUn
KSB7ICRhWydvYXV0aF9zY29wZSddID0gJGdvb2dsZVNjb3BlOyB9CiAgICAgICAgICAgICAgICAk
a2V5ID0gQ29ycmVvT0F1dGhUb2tlblByb3ZpZGVyOjpjbGF2ZUN1ZW50YSgkYSk7CiAgICAgICAg
ICAgICAgICBDb3JyZW9Fc3RhZG9Qcml2YWRvOjplamVjdXRhcignb2F1dGgnLCAka2V5LCBzdGF0
aWMgZnVuY3Rpb24gKCRzLCAkY2xlYXIpIHsgJGNsZWFyKFtdKTsgfSk7CiAgICAgICAgICAgIH0K
ICAgICAgICAgICAgJHNhdmUoWydjb25uZWN0ZWQnID0+IGZhbHNlXSk7CiAgICAgICAgfSk7CiAg
ICB9CgogICAgcHJpdmF0ZSBmdW5jdGlvbiBpZGVudGlkYWQoaW50ICR1c2VyLCBzdHJpbmcgJGVt
YWlsLCBhcnJheSAkc2Vzc2lvbik6IHZvaWQKICAgIHsKICAgICAgICBpZiAoJHVzZXIgPD0gMCB8
fCAoaW50KSgkc2Vzc2lvblsndXN1YXJpb19pZCddID8/IDApICE9PSAkdXNlciB8fCAkZW1haWwg
IT09IHN0cnRvbG93ZXIodHJpbSgkZW1haWwpKSB8fCAhZmlsdGVyX3ZhcigkZW1haWwsIEZJTFRF
Ul9WQUxJREFURV9FTUFJTCkpIHsKICAgICAgICAgICAgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRp
b24oJ01BSUxfT0FVVEhfSURFTlRJVFknKTsKICAgICAgICB9CiAgICB9CgogICAgcHJpdmF0ZSBm
dW5jdGlvbiBwb2xpdGljYShpbnQgJHVzZXIsIHN0cmluZyAkZW1haWwpOiB2b2lkCiAgICB7CiAg
ICAgICAgaWYgKCFwcmVnX21hdGNoKCcvQChnbWFpbHxnb29nbGVtYWlsKVwuY29tJC9EJywgJGVt
YWlsKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9PQVVUSF9GT1JCSURERU4n
KTsgfQogICAgICAgIGlmICgkdGhpcy0+aWRlbnRpdHlMb2FkZXIpIHsgJGlkZW50aXR5ID0gKCR0
aGlzLT5pZGVudGl0eUxvYWRlcikoJHVzZXIpOyB9CiAgICAgICAgZWxzZSB7CiAgICAgICAgICAg
IHJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy8uLi9tb2RlbHMvVXN1YXJpb01vZGVsLnBocCc7CiAg
ICAgICAgICAgICRpZGVudGl0eSA9IChuZXcgVXN1YXJpb01vZGVsKCkpLT5idXNjYXJQb3JJZCgk
dXNlcik7CiAgICAgICAgfQogICAgICAgIGlmICghJGlkZW50aXR5IHx8IChpbnQpKCRpZGVudGl0
eVsnZXN0YWRvJ10gPz8gMCkgIT09IDEgfHwgc3RyY2FzZWNtcCgoc3RyaW5nKSgkaWRlbnRpdHlb
J2NvcnJlbyddID8/ICcnKSwgJGVtYWlsKSAhPT0gMCkgewogICAgICAgICAgICB0aHJvdyBuZXcg
UnVudGltZUV4Y2VwdGlvbignTUFJTF9PQVVUSF9GT1JCSURERU4nKTsKICAgICAgICB9CiAgICAg
ICAgZm9yZWFjaCAoKGltcGVMZWVyQ29uZmlnUHJpdmFkYSgnbWFpbF9hY2NvdW50cy5sb2NhbC5w
aHAnKVsnYWNjb3VudHMnXSA/PyBbXSkgYXMgJGtleSA9PiAkYSkgewogICAgICAgICAgICBpZiAo
IWlzX2FycmF5KCRhKSB8fCBzdHJ0b2xvd2VyKChzdHJpbmcpKCRhWydjb3JyZW8nXSA/PyAka2V5
KSkgIT09ICRlbWFpbCkgeyBjb250aW51ZTsgfQogICAgICAgICAgICBpZiAoZW1wdHkoJGFbJ2Fj
dGl2byddKSB8fCAhaW5fYXJyYXkoJHVzZXIsIGFycmF5X21hcCgnaW50dmFsJywgJGFbJ3VzdWFy
aW9zJ10gPz8gW10pLCB0cnVlKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9P
QVVUSF9GT1JCSURERU4nKTsgfQogICAgICAgICAgICBpZiAoKCRhWyd0cmFuc3BvcnQnXSA/PyAn
c210cCcpICE9PSAnc210cCcgfHwgKCRhWydhdXRoX21ldGhvZCddID8/ICcnKSAhPT0gJ29hdXRo
MicgfHwgIWVtcHR5KCRhWydyZWZyZXNoX3Rva2VuJ10pKSB7CiAgICAgICAgICAgICAgICB0aHJv
dyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9PQVVUSF9NQU5BR0VEJyk7CiAgICAgICAgICAg
IH0KICAgICAgICB9CiAgICB9CgogICAgcHJpdmF0ZSBmdW5jdGlvbiBjb25maWcoc3RyaW5nICRw
cm92aWRlcik6IGFycmF5CiAgICB7CiAgICAgICAgaWYgKCRwcm92aWRlciAhPT0gJ2dvb2dsZScp
IHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01BSUxfT0FVVEhfUFJPVklERVInKTsgfQog
ICAgICAgICRhID0gaW1wZUxlZXJDb25maWdQcml2YWRhKCdtYWlsX29hdXRoLmxvY2FsLnBocCcp
Wydwcm92aWRlcnMnXVskcHJvdmlkZXJdID8/IFtdOwogICAgICAgICR1cmkgPSAoc3RyaW5nKSgk
YVsncmVkaXJlY3RfdXJpJ10gPz8gJycpOyAkcGFydHMgPSBwYXJzZV91cmwoJHVyaSk7ICRxID0g
W107CiAgICAgICAgcGFyc2Vfc3RyKChzdHJpbmcpKCRwYXJ0c1sncXVlcnknXSA/PyAnJyksICRx
KTsKICAgICAgICBpZiAoZW1wdHkoJGFbJ2VuYWJsZWQnXSkgfHwgZW1wdHkoJGFbJ2NsaWVudF9p
ZCddKSB8fCBlbXB0eSgkYVsnY2xpZW50X3NlY3JldCddKSB8fCAhJHBhcnRzIHx8ICgkcGFydHNb
J3NjaGVtZSddID8/ICcnKSAhPT0gJ2h0dHBzJyB8fCBlbXB0eSgkcGFydHNbJ2hvc3QnXSkgfHwK
ICAgICAgICAgICAgaXNzZXQoJHBhcnRzWyd1c2VyJ10pIHx8IGlzc2V0KCRwYXJ0c1sncGFzcydd
KSB8fCBpc3NldCgkcGFydHNbJ2ZyYWdtZW50J10pIHx8ICFzdHJfZW5kc193aXRoKCRwYXJ0c1sn
cGF0aCddID8/ICcnLCAnL2luZGV4LnBocCcpIHx8CiAgICAgICAgICAgIGNvdW50KCRxKSAhPT0g
MiB8fCAoJHFbJ2NvbnRyb2xsZXInXSA/PyAnJykgIT09ICdjb3JyZW9Db25leGlvbicgfHwgKCRx
WydhY3Rpb24nXSA/PyAnJykgIT09ICdjYWxsYmFjaycpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNl
cHRpb24oJ01BSUxfT0FVVEhfQ09ORklHJyk7IH0KICAgICAgICAkdGVuYW50ID0gKHN0cmluZyko
JGFbJ3RlbmFudCddID8/ICdjb21tb24nKTsKICAgICAgICBpZiAoIXByZWdfbWF0Y2goJy9eW2Et
ekEtWjAtOS4tXXsxLDI1M30kL0QnLCAkdGVuYW50KSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2Vw
dGlvbignTUFJTF9PQVVUSF9DT05GSUcnKTsgfQogICAgICAgIHJldHVybiBhcnJheV9tZXJnZSgk
YSwgWydvYXV0aF9wcm92aWRlcicgPT4gJHByb3ZpZGVyLCAndGVuYW50JyA9PiAkdGVuYW50LAog
ICAgICAgICAgICAnYXV0aG9yaXplX3VybCcgPT4gJHByb3ZpZGVyID09PSAnZ29vZ2xlJyA/ICdo
dHRwczovL2FjY291bnRzLmdvb2dsZS5jb20vby9vYXV0aDIvdjIvYXV0aCcgOiAnaHR0cHM6Ly9s
b2dpbi5taWNyb3NvZnRvbmxpbmUuY29tLycgLiAkdGVuYW50IC4gJy9vYXV0aDIvdjIuMC9hdXRo
b3JpemUnLAogICAgICAgICAgICAndG9rZW5fdXJsJyA9PiAkcHJvdmlkZXIgPT09ICdnb29nbGUn
ID8gJ2h0dHBzOi8vb2F1dGgyLmdvb2dsZWFwaXMuY29tL3Rva2VuJyA6ICdodHRwczovL2xvZ2lu
Lm1pY3Jvc29mdG9ubGluZS5jb20vJyAuICR0ZW5hbnQgLiAnL29hdXRoMi92Mi4wL3Rva2VuJ10p
OwogICAgfQoKICAgIHByaXZhdGUgZnVuY3Rpb24gY3VlbnRhKGFycmF5ICRhLCBzdHJpbmcgJGVt
YWlsLCBzdHJpbmcgJHJlZnJlc2gpOiBhcnJheQogICAgewogICAgICAgICRnb29nbGUgPSAkYVsn
b2F1dGhfcHJvdmlkZXInXSA9PT0gJ2dvb2dsZSc7CiAgICAgICAgJGNvbnN1bWVyID0gcHJlZ19t
YXRjaCgnL0Aob3V0bG9va3xob3RtYWlsfGxpdmV8bXNuKVwuL2knLCAkZW1haWwpOwogICAgICAg
ICRob3N0ID0gJGdvb2dsZSA/ICdzbXRwLmdtYWlsLmNvbScgOiAoJGNvbnN1bWVyID8gJ3NtdHAt
bWFpbC5vdXRsb29rLmNvbScgOiAnc210cC5vZmZpY2UzNjUuY29tJyk7CiAgICAgICAgJGFjY291
bnQgPSBbJ2NvcnJlbycgPT4gJGVtYWlsLCAndHJhbnNwb3J0JyA9PiAkZ29vZ2xlID8gJ2dtYWls
X2FwaScgOiAnc210cCcsICdzbXRwX3VzZXJuYW1lJyA9PiAkZW1haWwsICdzbXRwX2hvc3QnID0+
ICRob3N0LCAnc210cF9wb3J0JyA9PiA1ODcsCiAgICAgICAgICAgICdzbXRwX2VuY3J5cHRpb24n
ID0+ICd0bHMnLCAnYXV0aF9tZXRob2QnID0+ICdvYXV0aDInLCAnb2F1dGhfcHJvdmlkZXInID0+
ICRhWydvYXV0aF9wcm92aWRlciddLAogICAgICAgICAgICAnY2xpZW50X2lkJyA9PiAkYVsnY2xp
ZW50X2lkJ10sICdjbGllbnRfc2VjcmV0JyA9PiAkYVsnY2xpZW50X3NlY3JldCddID8/ICcnLCAn
dGVuYW50JyA9PiAkYVsndGVuYW50J10sICdyZWZyZXNoX3Rva2VuJyA9PiAkcmVmcmVzaF07CiAg
ICAgICAgaWYgKCRnb29nbGUpIHsgJGFjY291bnRbJ29hdXRoX3Njb3BlJ10gPSAkdGhpcy0+c2Nv
cGUoJ2dvb2dsZScpOyB9CiAgICAgICAgcmV0dXJuICRhY2NvdW50OwogICAgfQoKICAgIHByaXZh
dGUgZnVuY3Rpb24gdmVyaWZpY2FyU210cChhcnJheSAkYWNjb3VudCwgc3RyaW5nICR0b2tlbik6
IGJvb2wKICAgIHsKICAgICAgICAkbWFpbCA9IG5ldyBcUEhQTWFpbGVyXFBIUE1haWxlclxQSFBN
YWlsZXIodHJ1ZSk7CiAgICAgICAgQ29ycmVvU210cFRyYW5zcG9ydDo6Y29uZmlndXJhcigkbWFp
bCwgJGFjY291bnQpOwogICAgICAgICRtYWlsLT5zZXRPQXV0aChuZXcgY2xhc3MoJGFjY291bnRb
J2NvcnJlbyddLCAkdG9rZW4pIGltcGxlbWVudHMgXFBIUE1haWxlclxQSFBNYWlsZXJcT0F1dGhU
b2tlblByb3ZpZGVyIHsKICAgICAgICAgICAgcHJpdmF0ZSAkdXNlciwgJHRva2VuOwogICAgICAg
ICAgICBwdWJsaWMgZnVuY3Rpb24gX19jb25zdHJ1Y3QoJHVzZXIsICR0b2tlbikgeyAkdGhpcy0+
dXNlciA9ICR1c2VyOyAkdGhpcy0+dG9rZW4gPSAkdG9rZW47IH0KICAgICAgICAgICAgcHVibGlj
IGZ1bmN0aW9uIGdldE9hdXRoNjQoKSB7IHJldHVybiBiYXNlNjRfZW5jb2RlKCd1c2VyPScgLiAk
dGhpcy0+dXNlciAuICJcMDAxYXV0aD1CZWFyZXIgIiAuICR0aGlzLT50b2tlbiAuICJcMDAxXDAw
MSIpOyB9CiAgICAgICAgfSk7CiAgICAgICAgdHJ5IHsgcmV0dXJuICRtYWlsLT5zbXRwQ29ubmVj
dCgpOyB9CiAgICAgICAgZmluYWxseSB7ICRtYWlsLT5zbXRwQ2xvc2UoKTsgfQogICAgfQoKICAg
IHByaXZhdGUgZnVuY3Rpb24gdG9rZW4oc3RyaW5nICR1cmwsIGFycmF5ICRmaWVsZHMpOiBhcnJh
eQogICAgewogICAgICAgICRjID0gY3VybF9pbml0KCR1cmwpOyAkYm9keSA9ICcnOwogICAgICAg
IGN1cmxfc2V0b3B0X2FycmF5KCRjLCBbQ1VSTE9QVF9QT1NUID0+IHRydWUsIENVUkxPUFRfUE9T
VEZJRUxEUyA9PiBodHRwX2J1aWxkX3F1ZXJ5KCRmaWVsZHMpLAogICAgICAgICAgICBDVVJMT1BU
X0hUVFBIRUFERVIgPT4gWydDb250ZW50LVR5cGU6IGFwcGxpY2F0aW9uL3gtd3d3LWZvcm0tdXJs
ZW5jb2RlZCcsICdBY2NlcHQ6IGFwcGxpY2F0aW9uL2pzb24nXSwKICAgICAgICAgICAgQ1VSTE9Q
VF9GT0xMT1dMT0NBVElPTiA9PiBmYWxzZSwgQ1VSTE9QVF9DT05ORUNUVElNRU9VVCA9PiAxMCwg
Q1VSTE9QVF9USU1FT1VUID0+IDI1LAogICAgICAgICAgICBDVVJMT1BUX1NTTF9WRVJJRllQRUVS
ID0+IHRydWUsIENVUkxPUFRfU1NMX1ZFUklGWUhPU1QgPT4gMiwKICAgICAgICAgICAgQ1VSTE9Q
VF9XUklURUZVTkNUSU9OID0+IHN0YXRpYyBmdW5jdGlvbiAoJGMsICRieXRlcykgdXNlICgmJGJv
ZHkpIHsgaWYgKHN0cmxlbigkYm9keSkgKyBzdHJsZW4oJGJ5dGVzKSA+IDY1NTM2KSB7IHJldHVy
biAwOyB9ICRib2R5IC49ICRieXRlczsgcmV0dXJuIHN0cmxlbigkYnl0ZXMpOyB9XSk7CiAgICAg
ICAgdHJ5IHsKICAgICAgICAgICAgaWYgKGN1cmxfZXhlYygkYykgPT09IGZhbHNlIHx8IChpbnQp
Y3VybF9nZXRpbmZvKCRjLCBDVVJMSU5GT19IVFRQX0NPREUpICE9PSAyMDApIHsgdGhyb3cgbmV3
IFJ1bnRpbWVFeGNlcHRpb24oJ01BSUxfT0FVVEhfQVVUSE9SSVpBVElPTicpOyB9CiAgICAgICAg
ICAgICRyID0ganNvbl9kZWNvZGUoJGJvZHksIHRydWUpOwogICAgICAgICAgICBpZiAoIWlzX2Fy
cmF5KCRyKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9PQVVUSF9UT0tFTicp
OyB9CiAgICAgICAgICAgIHJldHVybiAkcjsKICAgICAgICB9IGZpbmFsbHkgeyBjdXJsX2Nsb3Nl
KCRjKTsgfQogICAgfQoKICAgIHByaXZhdGUgZnVuY3Rpb24gdmFsaWRhclRva2VuKCR0b2tlbik6
IHN0cmluZwogICAgewogICAgICAgIGlmICghaXNfc3RyaW5nKCR0b2tlbikgfHwgJHRva2VuID09
PSAnJyB8fCBzdHJsZW4oJHRva2VuKSA+IDE2Mzg0IHx8IHByZWdfbWF0Y2goJy9bXHgwMC1ceDIw
XHg3Zl0vJywgJHRva2VuKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9PQVVU
SF9UT0tFTicpOyB9CiAgICAgICAgcmV0dXJuICR0b2tlbjsKICAgIH0KICAgIHByaXZhdGUgZnVu
Y3Rpb24gYXBwSWQoYXJyYXkgJGEpOiBzdHJpbmcgeyByZXR1cm4gaGFzaCgnc2hhMjU2JywganNv
bl9lbmNvZGUoWyRhWydjbGllbnRfaWQnXSwgJGFbJ3JlZGlyZWN0X3VyaSddLCAkYVsndGVuYW50
J11dKSk7IH0KICAgIHByaXZhdGUgZnVuY3Rpb24gc2NvcGUoc3RyaW5nICRwKTogc3RyaW5nIHsg
cmV0dXJuICRwID09PSAnZ29vZ2xlJyA/ICdvcGVuaWQgZW1haWwgaHR0cHM6Ly93d3cuZ29vZ2xl
YXBpcy5jb20vYXV0aC9nbWFpbC5zZW5kJyA6ICdodHRwczovL291dGxvb2sub2ZmaWNlLmNvbS9T
TVRQLlNlbmQgb2ZmbGluZV9hY2Nlc3MnOyB9CiAgICBwcml2YXRlIHN0YXRpYyBmdW5jdGlvbiBi
NjQoc3RyaW5nICRzKTogc3RyaW5nIHsgcmV0dXJuIHJ0cmltKHN0cnRyKGJhc2U2NF9lbmNvZGUo
JHMpLCAnKy8nLCAnLV8nKSwgJz0nKTsgfQp9Cg==
CENTRAL_HELPER_7
 [[ "$(digest "$TMP/stage/app/services/CorreoConexionOAuthService.php")" == 'c99c783cfa4f9dc66bfa8cc721e63dabc5e61db451d3f49f107fb9ad02dfbba8' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoConexionOAuthService.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoConexionOAuthService.php")"
  case "$existing" in c99c783cfa4f9dc66bfa8cc721e63dabc5e61db451d3f49f107fb9ad02dfbba8|d19de6f7d4904a60a74570028b0529b018ca9619251e49650b2ec7522ce8ca0c) ;; *) fail 'Dependencia personalizada: app/services/CorreoConexionOAuthService.php. No se sobrescribe.';; esac
  if [[ "$existing" == 'c99c783cfa4f9dc66bfa8cc721e63dabc5e61db451d3f49f107fb9ad02dfbba8' ]];then cp -p -- "$LIVE/app/services/CorreoConexionOAuthService.php" "$TMP/stage/app/services/CorreoConexionOAuthService.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoConexionOAuthService.php" "$TMP/stage/app/services/CorreoConexionOAuthService.php";chmod --reference="$LIVE/app/services/CorreoConexionOAuthService.php" "$TMP/stage/app/services/CorreoConexionOAuthService.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoConexionOAuthService.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoCuentaConectadaService.php" <<'CENTRAL_HELPER_8'
PD9waHAKCnJlcXVpcmVfb25jZSBkaXJuYW1lKF9fRElSX18sIDIpIC4gJy9jb25maWcvcHJpdmF0
ZV9jb25maWcucGhwJzsKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb1NtdHBUcmFuc3Bv
cnQucGhwJzsKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0NvbmV4aW9uT0F1dGhTZXJ2
aWNlLnBocCc7CgovKiogVHJhbnNwb3J0ZSBpbnN0aXR1Y2lvbmFsIGFkbWluaXN0cmFkbyB1bmEg
dmV6OyBubyBjb25zdWx0YSBjb25leGlvbmVzIHBlcnNvbmFsZXMuICovCmZpbmFsIGNsYXNzIENv
cnJlb0N1ZW50YUNvbmVjdGFkYVNlcnZpY2UKewogICAgcHJpdmF0ZSAkaWRlbnRpdHlMb2FkZXI7
CiAgICBwcml2YXRlICRhcGlSZXF1ZXN0OwogICAgcHVibGljIGZ1bmN0aW9uIF9fY29uc3RydWN0
KD9jYWxsYWJsZSAkaWRlbnRpdHlMb2FkZXIgPSBudWxsKSB7ICR0aGlzLT5pZGVudGl0eUxvYWRl
ciA9ICRpZGVudGl0eUxvYWRlcjsgfQogICAgcHVibGljIGZ1bmN0aW9uIGNvbmZpZ3VyYXJDb25z
dWx0YUFwaShjYWxsYWJsZSAkcmVxdWVzdCk6IHZvaWQgeyAkdGhpcy0+YXBpUmVxdWVzdCA9ICRy
ZXF1ZXN0OyB9CgogICAgcHVibGljIGZ1bmN0aW9uIGNlbnRyYWwoKTogYXJyYXkKICAgIHsKICAg
ICAgICAkcGF0aCA9IGltcGVSdXRhQ29uZmlnUHJpdmFkYSgnbWFpbF9jZW50cmFsLmxvY2FsLnBo
cCcpOwogICAgICAgIGlmICgkcGF0aCAhPT0gbnVsbCkgewogICAgICAgICAgICAkYSA9IGltcGVM
ZWVyQ29uZmlnUHJpdmFkYSgnbWFpbF9jZW50cmFsLmxvY2FsLnBocCcpOwogICAgICAgICAgICBp
ZiAoISRhIHx8ICgkYVsnYWN0aXZvJ10gPz8gdHJ1ZSkgIT09IHRydWUpIHsgdGhyb3cgbmV3IFJ1
bnRpbWVFeGNlcHRpb24oJ01BSUxfQ0VOVFJBTF9DT05GSUcnKTsgfQogICAgICAgIH0gZWxzZSB7
CiAgICAgICAgICAgICRsZWdhY3kgPSBpbXBlTGVlckNvbmZpZ1ByaXZhZGEoJ21hcmtldGluZ19t
YWlsLmxvY2FsLnBocCcpOwogICAgICAgICAgICAkcGF0aCA9IGltcGVSdXRhQ29uZmlnUHJpdmFk
YSgnaG9zdGluZ2VyX21haWxfY29uZmlnLnBocCcpOwogICAgICAgICAgICBpZiAoJHBhdGgpIHsg
cmVxdWlyZV9vbmNlICRwYXRoOyB9CiAgICAgICAgICAgICRmcm9tID0gJGxlZ2FjeVsnaG9zdGlu
Z2VyX21haWxfZnJvbV9hZGRyZXNzJ10gPz8gKGRlZmluZWQoJ0hPU1RJTkdFUl9NQUlMX0ZST01f
QUREUkVTUycpID8gSE9TVElOR0VSX01BSUxfRlJPTV9BRERSRVNTIDogZ2V0ZW52KCdIT1NUSU5H
RVJfTUFJTF9GUk9NX0FERFJFU1MnKSk7CiAgICAgICAgICAgICR0b2tlbiA9ICRsZWdhY3lbJ2hv
c3Rpbmdlcl9tYWlsX2FwaV90b2tlbiddID8/IChkZWZpbmVkKCdIT1NUSU5HRVJfTUFJTF9BUElf
VE9LRU4nKSA/IEhPU1RJTkdFUl9NQUlMX0FQSV9UT0tFTiA6IGdldGVudignSE9TVElOR0VSX01B
SUxfQVBJX1RPS0VOJykpOwogICAgICAgICAgICBpZiAoIWVtcHR5KCR0b2tlbikpIHsKICAgICAg
ICAgICAgICAgICRhID0gWyd0cmFuc3BvcnQnID0+ICdob3N0aW5nZXJfYXBpJywgJ2NvcnJlbycg
PT4gJGZyb20sICdhcGlfdG9rZW4nID0+ICR0b2tlbl07CiAgICAgICAgICAgIH0gZWxzZSB7CiAg
ICAgICAgICAgICAgICAkYSA9ICRsZWdhY3kgKyBbJ3RyYW5zcG9ydCcgPT4gJ3NtdHAnLCAnY29y
cmVvJyA9PiAkbGVnYWN5WydzbXRwX2Zyb21fYWRkcmVzcyddID8/ICRsZWdhY3lbJ3NtdHBfdXNl
cm5hbWUnXSA/PyAnJ107CiAgICAgICAgICAgIH0KICAgICAgICB9CiAgICAgICAgJGFbJ3RyYW5z
cG9ydCddID0gJGFbJ3RyYW5zcG9ydCddID8/ICdzbXRwJzsKICAgICAgICAkYVsnY29ycmVvJ10g
PSBzdHJ0b2xvd2VyKHRyaW0oKHN0cmluZykoJGFbJ2NvcnJlbyddID8/ICRhWydzbXRwX2Zyb21f
YWRkcmVzcyddID8/ICRhWydzbXRwX3VzZXJuYW1lJ10gPz8gJycpKSk7CiAgICAgICAgaWYgKCFp
bl9hcnJheSgkYVsndHJhbnNwb3J0J10sIFsnc210cCcsICdob3N0aW5nZXJfYXBpJ10sIHRydWUp
IHx8CiAgICAgICAgICAgICgkYVsnY29ycmVvJ10gIT09ICcnICYmICFmaWx0ZXJfdmFyKCRhWydj
b3JyZW8nXSwgRklMVEVSX1ZBTElEQVRFX0VNQUlMKSkgfHwKICAgICAgICAgICAgKCRhWyd0cmFu
c3BvcnQnXSA9PT0gJ3NtdHAnICYmICRhWydjb3JyZW8nXSA9PT0gJycpKSB7IHRocm93IG5ldyBS
dW50aW1lRXhjZXB0aW9uKCdNQUlMX0NFTlRSQUxfQ09ORklHJyk7IH0KICAgICAgICBpZiAoJGFb
J3RyYW5zcG9ydCddID09PSAnaG9zdGluZ2VyX2FwaScpIHsKICAgICAgICAgICAgJHBhdGggPSBp
bXBlUnV0YUNvbmZpZ1ByaXZhZGEoJ2hvc3Rpbmdlcl9tYWlsX2NvbmZpZy5waHAnKTsKICAgICAg
ICAgICAgaWYgKCRwYXRoKSB7IHJlcXVpcmVfb25jZSAkcGF0aDsgfQogICAgICAgICAgICAkYVsn
YXBpX3Rva2VuJ10gPSAkYVsnYXBpX3Rva2VuJ10gPz8gKGRlZmluZWQoJ0hPU1RJTkdFUl9NQUlM
X0FQSV9UT0tFTicpID8gSE9TVElOR0VSX01BSUxfQVBJX1RPS0VOIDogZ2V0ZW52KCdIT1NUSU5H
RVJfTUFJTF9BUElfVE9LRU4nKSk7CiAgICAgICAgICAgICRhWydhcGlfYmFzZV91cmwnXSA9ICRh
WydhcGlfYmFzZV91cmwnXSA/PyAnaHR0cHM6Ly9hcGkubWFpbC5ob3N0aW5nZXIuY29tJzsKICAg
ICAgICAgICAgaWYgKGVtcHR5KCRhWydhcGlfdG9rZW4nXSkgfHwgJGFbJ2FwaV9iYXNlX3VybCdd
ICE9PSAnaHR0cHM6Ly9hcGkubWFpbC5ob3N0aW5nZXIuY29tJykgeyB0aHJvdyBuZXcgUnVudGlt
ZUV4Y2VwdGlvbignTUFJTF9DRU5UUkFMX0NPTkZJRycpOyB9CiAgICAgICAgfQogICAgICAgICRh
Wydub21icmUnXSA9IHRyaW0oKHN0cmluZykoJGFbJ25vbWJyZSddID8/ICRhWydzbXRwX2Zyb21f
bmFtZSddID8/ICdGdW5kYWNpw7NuIFJlZCBFZHVjYXRpdmEgTcOpeGljbycpKTsKICAgICAgICBp
ZiAoJGFbJ3RyYW5zcG9ydCddID09PSAnc210cCcpIHsgQ29ycmVvU210cFRyYW5zcG9ydDo6Y29u
ZmlndXJhcihuZXcgXFBIUE1haWxlclxQSFBNYWlsZXJcUEhQTWFpbGVyKHRydWUpLCAkYSk7IH0K
ICAgICAgICByZXR1cm4gJGE7CiAgICB9CgogICAgcHVibGljIGZ1bmN0aW9uIHJlc29sdmVyKHN0
cmluZyAkY29udGFjdCwgaW50ICR1c2VyLCBib29sICRtYXJrZXRpbmcgPSBmYWxzZSk6IGFycmF5
CiAgICB7CiAgICAgICAgaWYgKCR1c2VyIDw9IDAgfHwgKGlzc2V0KCRfU0VTU0lPTlsndXN1YXJp
b19pZCddKSAmJiAoaW50KSRfU0VTU0lPTlsndXN1YXJpb19pZCddICE9PSAkdXNlcikpIHsgcmV0
dXJuICR0aGlzLT5lcnJvcignTGEgc2VzacOzbiBubyBlc3TDoSBhdXRvcml6YWRhIHBhcmEgZW52
aWFyLicsIDQwMyk7IH0KICAgICAgICAkaWRlbnRpdHkgPSAkdGhpcy0+aWRlbnRpdHlMb2FkZXIg
PyAoJHRoaXMtPmlkZW50aXR5TG9hZGVyKSgkdXNlcikgOiAkdGhpcy0+dXN1YXJpbygkdXNlcik7
CiAgICAgICAgaWYgKCEkaWRlbnRpdHkgfHwgKGludCkoJGlkZW50aXR5Wydlc3RhZG8nXSA/PyAw
KSAhPT0gMSkgeyByZXR1cm4gJHRoaXMtPmVycm9yKCdObyBmdWUgcG9zaWJsZSBpZGVudGlmaWNh
ciB1bmEgY3VlbnRhIGFjdGl2YSBwYXJhIGVudmlhci4nLCA0MDMpOyB9CiAgICAgICAgJHJlZ2lz
dGVyZWQgPSBzdHJ0b2xvd2VyKHRyaW0oKHN0cmluZykoJGlkZW50aXR5Wydjb3JyZW8nXSA/PyAn
JykpKTsKICAgICAgICAkY29udGFjdCA9IHN0cnRvbG93ZXIodHJpbSgkY29udGFjdCkpOwogICAg
ICAgIGlmICgkY29udGFjdCA9PT0gJHJlZ2lzdGVyZWQgJiYgcHJlZ19tYXRjaCgnL0AoZ21haWx8
Z29vZ2xlbWFpbClcLmNvbSQvRCcsICRyZWdpc3RlcmVkKSkgewogICAgICAgICAgICAkYWNjb3Vu
dCA9IChuZXcgQ29ycmVvQ29uZXhpb25PQXV0aFNlcnZpY2UobnVsbCwgbnVsbCwgJHRoaXMtPmlk
ZW50aXR5TG9hZGVyKSktPm9idGVuZXIoJHVzZXIsICRyZWdpc3RlcmVkKTsKICAgICAgICAgICAg
aWYgKCEkYWNjb3VudCkgewogICAgICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lcnJvcignQXV0
b3JpemEgdHUgR21haWwgY29uIEdvb2dsZSBwYXJhIGVudmlhciBkZXNkZSBlc2EgZGlyZWNjacOz
bi4nLCA0MjIpICsgWwogICAgICAgICAgICAgICAgICAgICdjb25leGlvbl9yZXF1ZXJpZGEnID0+
IHRydWUsCiAgICAgICAgICAgICAgICAgICAgJ2NvbmV4aW9uX3VybCcgPT4gJ2luZGV4LnBocD9j
b250cm9sbGVyPWNvcnJlb0NvbmV4aW9uJmFjdGlvbj1pbmRleCZjb3JyZW89JyAuIHJhd3VybGVu
Y29kZSgkcmVnaXN0ZXJlZCkKICAgICAgICAgICAgICAgIF07CiAgICAgICAgICAgIH0KICAgICAg
ICAgICAgJG5hbWUgPSB0cmltKChzdHJpbmcpKCRpZGVudGl0eVsnbm9tYnJlJ10gPz8gJycpKSA/
OiAkcmVnaXN0ZXJlZDsKICAgICAgICAgICAgcmV0dXJuIFsnb2snID0+IHRydWUsICdhY2NvdW50
JyA9PiAkYWNjb3VudCwgJ2lkZW50aWRhZCcgPT4gWwogICAgICAgICAgICAgICAgJ3JlbWl0ZW50
ZV92aXNpYmxlJyA9PiAkcmVnaXN0ZXJlZCwgJ25vbWJyZV92aXNpYmxlJyA9PiAkbmFtZSwgJ3Jl
cGx5X3RvJyA9PiAkcmVnaXN0ZXJlZCwKICAgICAgICAgICAgICAgICdjb250YWN0bycgPT4gJHJl
Z2lzdGVyZWQsICdyZXBseV90b19hcGxpY2FkbycgPT4gdHJ1ZSwgJ2NvbnRhY3RvX2Zpcm1hJyA9
PiAkcmVnaXN0ZXJlZCwKICAgICAgICAgICAgICAgICd1c3VhcmlvX2lkJyA9PiAkdXNlciwgJ3Ry
YW5zcG9ydGUnID0+ICdnbWFpbF9hcGknLCAnYXZpc28nID0+ICcnCiAgICAgICAgICAgIF1dOwog
ICAgICAgIH0KICAgICAgICAkYSA9ICR0aGlzLT5jZW50cmFsKCk7CiAgICAgICAgJGV4dHJhID0g
JGFbJ2F1dGhvcml6ZWRfY29udGFjdHMnXVskdXNlcl0gPz8gW107CiAgICAgICAgJHRydXN0ZWQg
PSAkY29udGFjdCA9PT0gJHJlZ2lzdGVyZWQgfHwgaW5fYXJyYXkoJGNvbnRhY3QsIGFycmF5X21h
cCgnc3RydG9sb3dlcicsIChhcnJheSkkZXh0cmEpLCB0cnVlKTsKICAgICAgICAkcmVwbHkgPSAk
dHJ1c3RlZCA/ICRjb250YWN0IDogJHJlZ2lzdGVyZWQ7CiAgICAgICAgaWYgKCFmaWx0ZXJfdmFy
KCRyZXBseSwgRklMVEVSX1ZBTElEQVRFX0VNQUlMKSkgeyAkcmVwbHkgPSAkYVsnY29ycmVvJ107
IH0KICAgICAgICAkYWxpYXNlcyA9IGFycmF5X21hcCgnc3RydG9sb3dlcicsIChhcnJheSkoJGFb
J2F1dGhvcml6ZWRfZnJvbSddID8/IFtdKSk7CiAgICAgICAgJGZyb20gPSAkY29udGFjdCA9PT0g
JGFbJ2NvcnJlbyddIHx8ICgkdHJ1c3RlZCAmJiBpbl9hcnJheSgkY29udGFjdCwgJGFsaWFzZXMs
IHRydWUpKSA/ICRjb250YWN0IDogJGFbJ2NvcnJlbyddOwogICAgICAgIGlmICgkYVsndHJhbnNw
b3J0J10gPT09ICdob3N0aW5nZXJfYXBpJykgewogICAgICAgICAgICAkYm94ZXMgPSAkdGhpcy0+
YnV6b25lcygkYSk7CiAgICAgICAgICAgIGlmICgkdHJ1c3RlZCAmJiBpbl9hcnJheSgkY29udGFj
dCwgJGJveGVzLCB0cnVlKSkgeyAkZnJvbSA9ICRjb250YWN0OyB9CiAgICAgICAgICAgIGVsc2Vp
ZiAoaW5fYXJyYXkoJHJlZ2lzdGVyZWQsICRib3hlcywgdHJ1ZSkpIHsgJGZyb20gPSAkcmVnaXN0
ZXJlZDsgfQogICAgICAgICAgICBlbHNlaWYgKCRhWydjb3JyZW8nXSAhPT0gJycgJiYgaW5fYXJy
YXkoJGFbJ2NvcnJlbyddLCAkYm94ZXMsIHRydWUpKSB7ICRmcm9tID0gJGFbJ2NvcnJlbyddOyB9
CiAgICAgICAgICAgIGVsc2VpZiAoY291bnQoJGJveGVzKSA9PT0gMSkgeyAkZnJvbSA9ICRib3hl
c1swXTsgfQogICAgICAgICAgICBlbHNlIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ01B
SUxfQ0VOVFJBTF9GUk9NJyk7IH0KICAgICAgICAgICAgaWYgKCFmaWx0ZXJfdmFyKCRyZXBseSwg
RklMVEVSX1ZBTElEQVRFX0VNQUlMKSkgeyAkcmVwbHkgPSAkZnJvbTsgfQogICAgICAgIH0KICAg
ICAgICAvLyBMYSBBUEkgcHVibGljYWRhIG5vIGFkbWl0ZSBSZXBseS1Uby4gTm8gaW52ZW50YXIg
dW4gY2FtcG8gbmkgc2ltdWxhcmxvLgogICAgICAgIC8vIFNpIHNlIGNvbmZpZ3Vyw7MgU01UUCBj
ZW50cmFsLCBzZWxlY2Npb25hcmxvIGFudGVzIGRlIGN1YWxxdWllciBlbnbDrW8gcG9yIEFQSS4K
ICAgICAgICBpZiAoJGFbJ3RyYW5zcG9ydCddID09PSAnaG9zdGluZ2VyX2FwaScgJiYgJHJlcGx5
ICE9PSAkZnJvbSAmJiAhZW1wdHkoJGFbJ3JlcGx5X3NtdHAnXSkgJiYKICAgICAgICAgICAgKHN0
cmNhc2VjbXAoKHN0cmluZykoJGFbJ3JlcGx5X3NtdHAnXVsnc210cF91c2VybmFtZSddID8/ICcn
KSwgJGZyb20pID09PSAwIHx8IGluX2FycmF5KCRmcm9tLCAoYXJyYXkpKCRhWydyZXBseV9zbXRw
J11bJ2F1dGhvcml6ZWRfZnJvbSddID8/IFtdKSwgdHJ1ZSkpKSB7CiAgICAgICAgICAgICRhID0g
YXJyYXlfcmVwbGFjZSgkYSwgJGFbJ3JlcGx5X3NtdHAnXSwgWyd0cmFuc3BvcnQnID0+ICdzbXRw
J10pOwogICAgICAgICAgICBDb3JyZW9TbXRwVHJhbnNwb3J0Ojpjb25maWd1cmFyKG5ldyBcUEhQ
TWFpbGVyXFBIUE1haWxlclxQSFBNYWlsZXIodHJ1ZSksICRhKTsKICAgICAgICB9CiAgICAgICAg
JGFwcGxpZWQgPSAkYVsndHJhbnNwb3J0J10gPT09ICdzbXRwJzsKICAgICAgICAkYVsnY29ycmVv
J10gPSAkZnJvbTsKICAgICAgICByZXR1cm4gWydvaycgPT4gdHJ1ZSwgJ2FjY291bnQnID0+ICRh
LCAnaWRlbnRpZGFkJyA9PiBbCiAgICAgICAgICAgICdyZW1pdGVudGVfdmlzaWJsZScgPT4gJGZy
b20sICdub21icmVfdmlzaWJsZScgPT4gJGFbJ25vbWJyZSddLAogICAgICAgICAgICAncmVwbHlf
dG8nID0+ICRhcHBsaWVkID8gJHJlcGx5IDogJGZyb20sICdjb250YWN0bycgPT4gJGNvbnRhY3Qs
ICdyZXBseV90b19hcGxpY2FkbycgPT4gJGFwcGxpZWQsCiAgICAgICAgICAgICdjb250YWN0b19m
aXJtYScgPT4gJHRydXN0ZWQgPyAkY29udGFjdCA6ICRyZWdpc3RlcmVkLAogICAgICAgICAgICAn
dXN1YXJpb19pZCcgPT4gJHVzZXIsICd0cmFuc3BvcnRlJyA9PiAkYVsndHJhbnNwb3J0J10sCiAg
ICAgICAgICAgICdhdmlzbycgPT4gISRhcHBsaWVkICYmICRyZXBseSAhPT0gJGZyb20gPyAnTGFz
IHJlc3B1ZXN0YXMgc2UgcmVjaWJpcsOhbiDDum5pY2FtZW50ZSBlbiBsYSBjdWVudGEgaW5zdGl0
dWNpb25hbC4nIDogJycKICAgICAgICBdXTsKICAgIH0KCiAgICBwcml2YXRlIGZ1bmN0aW9uIGJ1
em9uZXMoYXJyYXkgJGEpOiBhcnJheQogICAgewogICAgICAgIHJldHVybiBDb3JyZW9Fc3RhZG9Q
cml2YWRvOjplamVjdXRhcignY2VudHJhbC1tYWlsYm94ZXMnLCBoYXNoKCdzaGEyNTYnLCAkYVsn
YXBpX3Rva2VuJ10pLCBmdW5jdGlvbiAoJHN0YXRlLCAkc2F2ZSkgdXNlICgkYSkgewogICAgICAg
ICAgICBpZiAoKCRzdGF0ZVsnZXhwaXJlcyddID8/IDApID4gdGltZSgpICYmIGlzc2V0KCRzdGF0
ZVsnYWRkcmVzc2VzJ10pKSB7IHJldHVybiAkc3RhdGVbJ2FkZHJlc3NlcyddOyB9CiAgICAgICAg
ICAgICR1cmwgPSAnaHR0cHM6Ly9hcGkubWFpbC5ob3N0aW5nZXIuY29tL2FwaS92MS9tZSc7CiAg
ICAgICAgICAgIGlmICgkdGhpcy0+YXBpUmVxdWVzdCkgeyAkciA9ICgkdGhpcy0+YXBpUmVxdWVz
dCkoJ0dFVCcsICR1cmwsICRhWydhcGlfdG9rZW4nXSwgbnVsbCk7IH0KICAgICAgICAgICAgZWxz
ZSB7CiAgICAgICAgICAgICAgICAkYyA9IGN1cmxfaW5pdCgkdXJsKTsgJGJvZHkgPSAnJzsKICAg
ICAgICAgICAgICAgIGN1cmxfc2V0b3B0X2FycmF5KCRjLCBbQ1VSTE9QVF9IVFRQSEVBREVSPT5b
J0F1dGhvcml6YXRpb246IEJlYXJlciAnLiRhWydhcGlfdG9rZW4nXSwgJ0FjY2VwdDogYXBwbGlj
YXRpb24vanNvbiddLAogICAgICAgICAgICAgICAgICAgIENVUkxPUFRfQ09OTkVDVFRJTUVPVVQ9
PjEwLCBDVVJMT1BUX1RJTUVPVVQ9PjI1LCBDVVJMT1BUX0ZPTExPV0xPQ0FUSU9OPT5mYWxzZSwK
ICAgICAgICAgICAgICAgICAgICBDVVJMT1BUX1NTTF9WRVJJRllQRUVSPT50cnVlLCBDVVJMT1BU
X1NTTF9WRVJJRllIT1NUPT4yLAogICAgICAgICAgICAgICAgICAgIENVUkxPUFRfV1JJVEVGVU5D
VElPTj0+c3RhdGljIGZ1bmN0aW9uKCRjLCRwYXJ0KXVzZSgmJGJvZHkpe2lmKHN0cmxlbigkYm9k
eSkrc3RybGVuKCRwYXJ0KT4xMDQ4NTc2KXJldHVybiAwOyRib2R5Lj0kcGFydDtyZXR1cm4gc3Ry
bGVuKCRwYXJ0KTt9XSk7CiAgICAgICAgICAgICAgICB0cnkgeyAkb2s9Y3VybF9leGVjKCRjKTsg
JGNvZGU9KGludCljdXJsX2dldGluZm8oJGMsQ1VSTElORk9fSFRUUF9DT0RFKTsgJHI9Wydvayc9
PiRvayE9PWZhbHNlJiYkY29kZT09PTIwMCwnanNvbic9Pmpzb25fZGVjb2RlKCRib2R5LHRydWUp
XTsgfQogICAgICAgICAgICAgICAgZmluYWxseSB7IGN1cmxfY2xvc2UoJGMpOyB9CiAgICAgICAg
ICAgIH0KICAgICAgICAgICAgaWYgKGVtcHR5KCRyWydvayddKSkgeyB0aHJvdyBuZXcgUnVudGlt
ZUV4Y2VwdGlvbignTUFJTF9DRU5UUkFMX0FQSScpOyB9CiAgICAgICAgICAgICRvdXQ9W107CiAg
ICAgICAgICAgIGZvcmVhY2ggKCgkclsnanNvbiddWydkYXRhJ11bJ21haWxib3hlcyddID8/ICRy
Wydqc29uJ11bJ21haWxib3hlcyddID8/IFtdKSBhcyAkYm94KSB7CiAgICAgICAgICAgICAgICAk
ZW1haWw9c3RydG9sb3dlcih0cmltKChzdHJpbmcpKCRib3hbJ2FkZHJlc3MnXT8/JycpKSk7CiAg
ICAgICAgICAgICAgICBpZiAoZmlsdGVyX3ZhcigkZW1haWwsRklMVEVSX1ZBTElEQVRFX0VNQUlM
KSAmJiAhZW1wdHkoJGJveFsncmVzb3VyY2VJZCddPz8kYm94WydyZXNvdXJjZV9pZCddPz8nJykp
IHsgJG91dFtdPSRlbWFpbDsgfQogICAgICAgICAgICB9CiAgICAgICAgICAgIGlmICghJG91dCkg
eyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9DRU5UUkFMX0FQSScpOyB9CiAgICAg
ICAgICAgICRvdXQ9YXJyYXlfdmFsdWVzKGFycmF5X3VuaXF1ZSgkb3V0KSk7ICRzYXZlKFsnYWRk
cmVzc2VzJz0+JG91dCwnZXhwaXJlcyc9PnRpbWUoKSs2MF0pOyByZXR1cm4gJG91dDsKICAgICAg
ICB9KTsKICAgIH0KCiAgICBwcml2YXRlIGZ1bmN0aW9uIHVzdWFyaW8oaW50ICRpZCkKICAgIHsK
ICAgICAgICByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvLi4vbW9kZWxzL1VzdWFyaW9Nb2RlbC5w
aHAnOwogICAgICAgIHJldHVybiAobmV3IFVzdWFyaW9Nb2RlbCgpKS0+YnVzY2FyUG9ySWQoJGlk
KTsKICAgIH0KICAgIHByaXZhdGUgZnVuY3Rpb24gZXJyb3Ioc3RyaW5nICRtZXNzYWdlLCBpbnQg
JGNvZGUpOiBhcnJheSB7IHJldHVybiBbJ29rJyA9PiBmYWxzZSwgJ21lbnNhamUnID0+ICRtZXNz
YWdlLCAnY29kaWdvX2h0dHAnID0+ICRjb2RlXTsgfQp9Cg==
CENTRAL_HELPER_8
 [[ "$(digest "$TMP/stage/app/services/CorreoCuentaConectadaService.php")" == '292ac1c52185fff0fecf35e0a1ae3e54946edf04b7a32f854afdddc06a6ecdf3' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoCuentaConectadaService.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoCuentaConectadaService.php")"
  case "$existing" in 292ac1c52185fff0fecf35e0a1ae3e54946edf04b7a32f854afdddc06a6ecdf3|384047b54bd375ba66fc025b38a1e03c4b7346f2e559246f39d5c52fd7321ba9|945bf7557c345e2182011d631d176679c35d8e2baa9dbb7a4cbefed5cdc1b68e) ;; *) fail 'Dependencia personalizada: app/services/CorreoCuentaConectadaService.php. No se sobrescribe.';; esac
  if [[ "$existing" == '292ac1c52185fff0fecf35e0a1ae3e54946edf04b7a32f854afdddc06a6ecdf3' ]];then cp -p -- "$LIVE/app/services/CorreoCuentaConectadaService.php" "$TMP/stage/app/services/CorreoCuentaConectadaService.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoCuentaConectadaService.php" "$TMP/stage/app/services/CorreoCuentaConectadaService.php";chmod --reference="$LIVE/app/services/CorreoCuentaConectadaService.php" "$TMP/stage/app/services/CorreoCuentaConectadaService.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoCuentaConectadaService.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoRecuperacionService.php" <<'CENTRAL_HELPER_9'
PD9waHAKCnJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9NYWlsU2VydmljZS5waHAnOwpyZXF1aXJl
X29uY2UgX19ESVJfXyAuICcvQ29ycmVvTGltaXRlU2VydmljZS5waHAnOwoKLyoqIENvbnRyYXNl
w7FhIHRlbXBvcmFsIGFsIGNvcnJlbyByZWdpc3RyYWRvLiBOdW5jYSBkZXZ1ZWx2ZSBkYXRvcyBk
ZSBsYSBjdWVudGEgYWwgdmlzaXRhbnRlLiAqLwpmaW5hbCBjbGFzcyBDb3JyZW9SZWN1cGVyYWNp
b25TZXJ2aWNlCnsKICAgIHByaXZhdGUgJHVzdWFyaW9zLCAkbWFpbDsKICAgIHB1YmxpYyBmdW5j
dGlvbiBfX2NvbnN0cnVjdCgkdXN1YXJpb3MsID9NYWlsU2VydmljZSAkbWFpbCA9IG51bGwpIHsg
JHRoaXMtPnVzdWFyaW9zID0gJHVzdWFyaW9zOyAkdGhpcy0+bWFpbCA9ICRtYWlsID8/IG5ldyBN
YWlsU2VydmljZSgpOyB9CgogICAgcHVibGljIGZ1bmN0aW9uIHNvbGljaXRhcihzdHJpbmcgJGRh
dG8sIHN0cmluZyAkaXApOiB2b2lkCiAgICB7CiAgICAgICAgdHJ5IHsKICAgICAgICAgICAgaWYg
KCRkYXRvID09PSAnJyB8fCBtYl9zdHJsZW4oJGRhdG8pID4gMjU1IHx8ICFDb3JyZW9MaW1pdGVT
ZXJ2aWNlOjp0b21hckNsYXZlKCdyZWNvdmVyeS1pcCcsICRpcCA/OiAndW5rbm93bicsIDEwLCA5
MDApIHx8CiAgICAgICAgICAgICAgICAhQ29ycmVvTGltaXRlU2VydmljZTo6dG9tYXJDbGF2ZSgn
cmVjb3ZlcnktaW5wdXQnLCBzdHJ0b2xvd2VyKCRkYXRvKSwgMywgMzYwMCkpIHsgcmV0dXJuOyB9
CiAgICAgICAgICAgICR1ID0gJHRoaXMtPnVzdWFyaW9zLT5idXNjYXJQb3JVc3VhcmlvT0NvcnJl
bygkZGF0byk7CiAgICAgICAgICAgIGlmICghJHUgfHwgKGludCkkdVsnZXN0YWRvJ10gIT09IDEg
fHwgKGludCkoJHVbJ3JvbF9lc3RhZG8nXSA/PyAwKSAhPT0gMSB8fCAhZmlsdGVyX3ZhcigkdVsn
Y29ycmVvJ10sIEZJTFRFUl9WQUxJREFURV9FTUFJTCkpIHsgcmV0dXJuOyB9CiAgICAgICAgICAg
IC8vIFVzdWFyaW8geSBjb3JyZW8gcHVlZGVuIHJlZmVyaXJzZSBhIGxhIG1pc21hIGN1ZW50YTog
Y29tcGFydGlyIGzDrW1pdGUgeSBleGNsdXNpw7NuLgogICAgICAgICAgICBpZiAoIUNvcnJlb0xp
bWl0ZVNlcnZpY2U6OnRvbWFyKCdyZWNvdmVyeS11c2VyJywgKGludCkkdVsnaWQnXSwgMywgMzYw
MCkpIHsgcmV0dXJuOyB9CiAgICAgICAgICAgIENvcnJlb0VzdGFkb1ByaXZhZG86OmVqZWN1dGFy
KCdyZWNvdmVyeScsIChzdHJpbmcpJHVbJ2lkJ10sIGZ1bmN0aW9uICgkc3RhdGUsICRzYXZlKSB1
c2UgKCR1KSB7CiAgICAgICAgICAgICAgICBpZiAoaW5fYXJyYXkoJHN0YXRlWydzdGF0dXMnXSA/
PyAnJywgWydzZW5kaW5nJywgJ0RFU0NPTk9DSURPJ10sIHRydWUpICYmIChpbnQpKCRzdGF0ZVsn
YXQnXSA/PyAwKSA+IHRpbWUoKSAtIDE4MDApIHsgcmV0dXJuOyB9CiAgICAgICAgICAgICAgICAk
cGFzc3dvcmQgPSAkdGhpcy0+Z2VuZXJhcigpOwogICAgICAgICAgICAgICAgJGhhc2ggPSBwYXNz
d29yZF9oYXNoKCRwYXNzd29yZCwgUEFTU1dPUkRfREVGQVVMVCk7CiAgICAgICAgICAgICAgICAk
c2F2ZShbJ3N0YXR1cycgPT4gJ3NlbmRpbmcnLCAnYXQnID0+IHRpbWUoKV0pOwogICAgICAgICAg
ICAgICAgaWYgKCEkdGhpcy0+bWFpbC0+ZW52aWFyUGFzc3dvcmRUZW1wb3JhbCgkdVsnY29ycmVv
J10sIHRyaW0oJHVbJ25vbWJyZSddIC4gJyAnIC4gKCR1WydhcGVsbGlkb3MnXSA/PyAnJykpLCAk
cGFzc3dvcmQpKSB7CiAgICAgICAgICAgICAgICAgICAgJHVua25vd24gPSAkdGhpcy0+bWFpbC0+
dWx0aW1vUmVzdWx0YWRvKClbJ2VzdGFkb19lbnRyZWdhJ10gPz8gJ1JFQ0hBWkFETyc7CiAgICAg
ICAgICAgICAgICAgICAgJHNhdmUoWydzdGF0dXMnID0+ICR1bmtub3duID09PSAnREVTQ09OT0NJ
RE8nID8gJ0RFU0NPTk9DSURPJyA6ICdSRUNIQVpBRE8nLCAnYXQnID0+IHRpbWUoKV0pOwogICAg
ICAgICAgICAgICAgICAgIGVycm9yX2xvZygnTUFJTF9SRUNPVkVSWSBzdGF0dXM9bm90X2FjY2Vw
dGVkJyk7IHJldHVybjsKICAgICAgICAgICAgICAgIH0KICAgICAgICAgICAgICAgIC8vIFPDs2xv
IGhhc2ggZW4gQkQsIGRlc3B1w6lzIGRlIGFjZXB0YWNpw7NuLiBObyBoYXkgdHJhbnNhY2Npw7Nu
IGRpc3RyaWJ1aWRhIGNvbiBTTVRQLgogICAgICAgICAgICAgICAgJG9rID0gJHRoaXMtPnVzdWFy
aW9zLT5lc3RhYmxlY2VyUGFzc3dvcmRUZW1wb3JhbFNpVmlnZW50ZSgoaW50KSR1WydpZCddLCAk
dVsncGFzc3dvcmQnXSwgJGhhc2gsIGRhdGUoJ1ktbS1kIEg6aTpzJywgdGltZSgpICsgMTgwMCkp
OwogICAgICAgICAgICAgICAgJHNhdmUoWydzdGF0dXMnID0+ICRvayA/ICdBQ0VQVEFETycgOiAn
UkVHSVNUUk9fRkFMTElETycsICdhdCcgPT4gdGltZSgpXSk7CiAgICAgICAgICAgICAgICBlcnJv
cl9sb2coJ01BSUxfUkVDT1ZFUlkgc3RhdHVzPScgLiAoJG9rID8gJ2FjY2VwdGVkX2FuZF9zdG9y
ZWQnIDogJ2FjY2VwdGVkX25vdF9zdG9yZWQnKSk7CiAgICAgICAgICAgIH0pOwogICAgICAgIH0g
Y2F0Y2ggKFRocm93YWJsZSAkZSkgeyBlcnJvcl9sb2coJ01BSUxfUkVDT1ZFUlkgc3RhdHVzPXVu
Y29uZmlybWVkIGV4Y2VwdGlvbj0nIC4gZ2V0X2NsYXNzKCRlKSk7IH0KICAgIH0KCiAgICBwcml2
YXRlIGZ1bmN0aW9uIGdlbmVyYXIoKTogc3RyaW5nCiAgICB7CiAgICAgICAgJHNldHMgPSBbJ0FC
Q0RFRkdISktMTU5QUVJTVFVWV1hZWicsICdhYmNkZWZnaGlqa21ub3BxcnN0dXZ3eHl6JywgJzIz
NDU2Nzg5J107ICRjaGFycyA9IFtdOwogICAgICAgIGZvcmVhY2ggKCRzZXRzIGFzICRzZXQpIHsg
JGNoYXJzW10gPSAkc2V0W3JhbmRvbV9pbnQoMCwgc3RybGVuKCRzZXQpIC0gMSldOyB9CiAgICAg
ICAgJGFsbCA9IGltcGxvZGUoJycsICRzZXRzKTsKICAgICAgICB3aGlsZSAoY291bnQoJGNoYXJz
KSA8IDEyKSB7ICRjaGFyc1tdID0gJGFsbFtyYW5kb21faW50KDAsIHN0cmxlbigkYWxsKSAtIDEp
XTsgfQogICAgICAgIGZvciAoJGkgPSBjb3VudCgkY2hhcnMpIC0gMTsgJGkgPiAwOyAkaS0tKSB7
ICRqID0gcmFuZG9tX2ludCgwLCAkaSk7IFskY2hhcnNbJGldLCAkY2hhcnNbJGpdXSA9IFskY2hh
cnNbJGpdLCAkY2hhcnNbJGldXTsgfQogICAgICAgIHJldHVybiBpbXBsb2RlKCcnLCAkY2hhcnMp
OwogICAgfQp9Cg==
CENTRAL_HELPER_9
 [[ "$(digest "$TMP/stage/app/services/CorreoRecuperacionService.php")" == '47060b1910363c5f4fd61a8f096f96e6bd84af3529a7dc06b738b3b413edf71e' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoRecuperacionService.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoRecuperacionService.php")"
  case "$existing" in 47060b1910363c5f4fd61a8f096f96e6bd84af3529a7dc06b738b3b413edf71e) ;; *) fail 'Dependencia personalizada: app/services/CorreoRecuperacionService.php. No se sobrescribe.';; esac
  if [[ "$existing" == '47060b1910363c5f4fd61a8f096f96e6bd84af3529a7dc06b738b3b413edf71e' ]];then cp -p -- "$LIVE/app/services/CorreoRecuperacionService.php" "$TMP/stage/app/services/CorreoRecuperacionService.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoRecuperacionService.php" "$TMP/stage/app/services/CorreoRecuperacionService.php";chmod --reference="$LIVE/app/services/CorreoRecuperacionService.php" "$TMP/stage/app/services/CorreoRecuperacionService.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoRecuperacionService.php";fi
 base64 --decode > "$TMP/stage/app/controllers/CorreoConexionController.php" <<'CENTRAL_HELPER_10'
PD9waHAKCnJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy8uLi9oZWxwZXJzL1Blcm1pc3Npb25IZWxw
ZXIucGhwJzsKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnLy4uL3NlcnZpY2VzL0NvcnJlb0NvbmV4
aW9uT0F1dGhTZXJ2aWNlLnBocCc7CgpmaW5hbCBjbGFzcyBDb3JyZW9Db25leGlvbkNvbnRyb2xs
ZXIKewogICAgcHVibGljIGZ1bmN0aW9uIF9fY29uc3RydWN0KCkKICAgIHsKICAgICAgICBoZWFk
ZXIoJ0NhY2hlLUNvbnRyb2w6IG5vLXN0b3JlJyk7IGhlYWRlcignUmVmZXJyZXItUG9saWN5OiBu
by1yZWZlcnJlcicpOwogICAgICAgIGlmICghaW5fYXJyYXkoJF9HRVRbJ2FjdGlvbiddID8/ICdp
bmRleCcsIFsnaW5kZXgnLCAnaW5pY2lhcicsICdjYWxsYmFjaycsICdkZXNjb25lY3RhciddLCB0
cnVlKSkgeyBodHRwX3Jlc3BvbnNlX2NvZGUoNDA0KTsgZXhpdDsgfQogICAgICAgIGlmICghc2Vs
Zjo6cHVlZGVBY2NlZGVyKCkpIHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwMyk7IGV4aXQoJ05vIHRp
ZW5lcyBwZXJtaXNvIHBhcmEgYXV0b3JpemFyIGVsIGVudsOtbyBkZSBjb3JyZW8uJyk7IH0KICAg
IH0KCiAgICBwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIHB1ZWRlQWNjZWRlcigpOiBib29sCiAgICB7
CiAgICAgICAgcmV0dXJuIChpbnQpKCRfU0VTU0lPTlsndXN1YXJpb19pZCddID8/IDApID4gMCAm
JiAoaW50KSgkX1NFU1NJT05bJ3JvbF9pZCddID8/IDApICE9PSAxICYmCiAgICAgICAgICAgIChz
dHJjYXNlY21wKChzdHJpbmcpKCRfU0VTU0lPTlsncm9sJ10gPz8gJycpLCAnTWFya2V0aW5nJykg
PT09IDAgfHwgdGllbmVBbGd1blBlcm1pc28oWwogICAgICAgICAgICAgICAgJ3NlZ3VpbWllbnRv
c192aW5jdWxhY2lvbi5vcGVyYXJfcHJvcGlvcycsICdvZmljaW9zLmVudmlhcicsICdyZXVuaW9u
ZXMuc29saWNpdGFyJywgJ3JldW5pb25lcy5nZXN0aW9uYXInLAogICAgICAgICAgICAgICAgJ2Fs
aWFkb3MuY29tcGFydGlyX2NvcnJlbycsICdkaWZ1c2lvbi5lbnZpYXInXSkpOwogICAgfQoKICAg
IHB1YmxpYyBmdW5jdGlvbiBpbmRleCgpOiB2b2lkCiAgICB7CiAgICAgICAgJGVtYWlsID0gc3Ry
dG9sb3dlcih0cmltKChzdHJpbmcpKCRfU0VTU0lPTlsnY29ycmVvJ10gPz8gJF9HRVRbJ2NvcnJl
byddID8/ICcnKSkpOwogICAgICAgICRzZXJ2aWNlID0gbmV3IENvcnJlb0NvbmV4aW9uT0F1dGhT
ZXJ2aWNlKCk7CiAgICAgICAgJHByb3ZpZGVycyA9ICRzZXJ2aWNlLT5kaXNwb25pYmxlcygpOyAk
Y29ubmVjdGVkID0gZmFsc2U7CiAgICAgICAgJG5vdGljZSA9IChzdHJpbmcpKCRfU0VTU0lPTlsn
bWFpbF9jb25uZWN0aW9uX2ZsYXNoJ10gPz8gJycpOyB1bnNldCgkX1NFU1NJT05bJ21haWxfY29u
bmVjdGlvbl9mbGFzaCddKTsKICAgICAgICB0cnkgeyBpZiAoZmlsdGVyX3ZhcigkZW1haWwsIEZJ
TFRFUl9WQUxJREFURV9FTUFJTCkpIHsgJGNvbm5lY3RlZCA9ICRzZXJ2aWNlLT5vYnRlbmVyKChp
bnQpJF9TRVNTSU9OWyd1c3VhcmlvX2lkJ10sICRlbWFpbCkgIT09IG51bGw7IH0gfQogICAgICAg
IGNhdGNoIChUaHJvd2FibGUgJGUpIHsgJG5vdGljZSA9ICdObyBzZSBwdWRvIGNvbnN1bHRhciBl
c3RhIGNvbmV4acOzbi4gUmV2aXNhIGxhIGNvbmZpZ3VyYWNpw7NuIHByaXZhZGEgY29uIGVsIGFk
bWluaXN0cmFkb3IuJzsgfQogICAgICAgICR0aXR1bG9QYWdpbmEgPSAnQXV0b3JpemFyIEdtYWls
JzsgJHN1YnRpdHVsb1BhZ2luYSA9ICdFbnbDrW8gZGVzZGUgdHUgY3VlbnRhIEdtYWlsJzsgJG9w
Y2lvbkFjdGl2YSA9ICcnOwogICAgICAgIHJlcXVpcmUgX19ESVJfXyAuICcvLi4vdmlld3MvbGF5
b3V0L2Rhc2hib2FyZF9oZWFkLnBocCc7CiAgICAgICAgcmVxdWlyZSBfX0RJUl9fIC4gJy8uLi92
aWV3cy9sYXlvdXQvc2lkZWJhci5waHAnOwogICAgICAgIHJlcXVpcmUgX19ESVJfXyAuICcvLi4v
dmlld3MvbGF5b3V0L3RvcGJhci5waHAnOwogICAgICAgIHJlcXVpcmUgX19ESVJfXyAuICcvLi4v
dmlld3MvY29ycmVvcy9jb25leGlvbi5waHAnOwogICAgICAgIHJlcXVpcmUgX19ESVJfXyAuICcv
Li4vdmlld3MvbGF5b3V0L2Rhc2hib2FyZF9mb290ZXIucGhwJzsKICAgIH0KCiAgICBwdWJsaWMg
ZnVuY3Rpb24gaW5pY2lhcigpOiB2b2lkCiAgICB7CiAgICAgICAgJHRoaXMtPnBvc3QoKTsKICAg
ICAgICAkZW1haWwgPSBzdHJ0b2xvd2VyKHRyaW0oKHN0cmluZykoJF9QT1NUWydjb3JyZW8nXSA/
PyAnJykpKTsKICAgICAgICB0cnkgewogICAgICAgICAgICAkdXJsID0gKG5ldyBDb3JyZW9Db25l
eGlvbk9BdXRoU2VydmljZSgpKS0+aW5pY2lhcigoaW50KSRfU0VTU0lPTlsndXN1YXJpb19pZCdd
LCAkZW1haWwsIChzdHJpbmcpKCRfUE9TVFsncHJvdmVlZG9yJ10gPz8gJycpLCAkX1NFU1NJT04p
OwogICAgICAgICAgICBoZWFkZXIoJ0xvY2F0aW9uOiAnIC4gJHVybCwgdHJ1ZSwgMzAzKTsgZXhp
dDsKICAgICAgICB9IGNhdGNoIChUaHJvd2FibGUgJGUpIHsgJHRoaXMtPmZhbGxvKCRlKTsgJHRo
aXMtPnZvbHZlcigkZW1haWwpOyB9CiAgICB9CgogICAgcHVibGljIGZ1bmN0aW9uIGNhbGxiYWNr
KCk6IHZvaWQKICAgIHsKICAgICAgICBpZiAoKCRfU0VSVkVSWydSRVFVRVNUX01FVEhPRCddID8/
ICcnKSAhPT0gJ0dFVCcpIHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwNSk7IGV4aXQoJ03DqXRvZG8g
bm8gcGVybWl0aWRvLicpOyB9CiAgICAgICAgJGVtYWlsID0gJyc7CiAgICAgICAgdHJ5IHsKICAg
ICAgICAgICAgJGVtYWlsID0gKG5ldyBDb3JyZW9Db25leGlvbk9BdXRoU2VydmljZSgpKS0+Y29t
cGxldGFyKChpbnQpJF9TRVNTSU9OWyd1c3VhcmlvX2lkJ10sICRfR0VULCAkX1NFU1NJT04pOwog
ICAgICAgICAgICAkX1NFU1NJT05bJ21haWxfY29ubmVjdGlvbl9mbGFzaCddID0gJ0N1ZW50YSBh
dXRvcml6YWRhIHkgYXV0ZW50aWNhZGEgY29uIGVsIHByb3ZlZWRvci4gVnVlbHZlIGEgbGEgcmVk
YWNjacOzbiBwYXJhIGVudmlhcjsgdG9kYXbDrWEgbm8gc2UgaGEgdHJhbnNtaXRpZG8gbmluZ8O6
biBtZW5zYWplLic7CiAgICAgICAgfSBjYXRjaCAoVGhyb3dhYmxlICRlKSB7ICR0aGlzLT5mYWxs
bygkZSk7IH0KICAgICAgICAkdGhpcy0+dm9sdmVyKCRlbWFpbCk7CiAgICB9CgogICAgcHVibGlj
IGZ1bmN0aW9uIGRlc2NvbmVjdGFyKCk6IHZvaWQKICAgIHsKICAgICAgICAkdGhpcy0+cG9zdCgp
OyAkZW1haWwgPSBzdHJ0b2xvd2VyKHRyaW0oKHN0cmluZykoJF9QT1NUWydjb3JyZW8nXSA/PyAn
JykpKTsKICAgICAgICB0cnkgewogICAgICAgICAgICAobmV3IENvcnJlb0NvbmV4aW9uT0F1dGhT
ZXJ2aWNlKCkpLT5kZXNjb25lY3RhcigoaW50KSRfU0VTU0lPTlsndXN1YXJpb19pZCddLCAkZW1h
aWwsICRfU0VTU0lPTik7CiAgICAgICAgICAgICRfU0VTU0lPTlsnbWFpbF9jb25uZWN0aW9uX2Zs
YXNoJ10gPSAnQ29uZXhpw7NuIE9BdXRoIHJldGlyYWRhIGRlIHR1IHVzdWFyaW8gZW4gZWwgQ1JN
LiBQdWVkZXMgcmV2b2NhciB0YW1iacOpbiBlbCBwZXJtaXNvIGRlIGxhIGFwbGljYWNpw7NuIGRl
c2RlIHR1IGN1ZW50YSBkZWwgcHJvdmVlZG9yLiBMYXMgY3VlbnRhcyBjb25maWd1cmFkYXMgcG9y
IGVsIGFkbWluaXN0cmFkb3Igc2UgZ2VzdGlvbmFuIHBvciBzZXBhcmFkby4nOwogICAgICAgIH0g
Y2F0Y2ggKFRocm93YWJsZSAkZSkgeyAkdGhpcy0+ZmFsbG8oJGUpOyB9CiAgICAgICAgJHRoaXMt
PnZvbHZlcigkZW1haWwpOwogICAgfQoKICAgIHByaXZhdGUgZnVuY3Rpb24gcG9zdCgpOiB2b2lk
CiAgICB7CiAgICAgICAgaWYgKCgkX1NFUlZFUlsnUkVRVUVTVF9NRVRIT0QnXSA/PyAnJykgIT09
ICdQT1NUJykgeyBodHRwX3Jlc3BvbnNlX2NvZGUoNDA1KTsgZXhpdCgnTcOpdG9kbyBubyBwZXJt
aXRpZG8uJyk7IH0KICAgICAgICAkYSA9IChzdHJpbmcpKCRfU0VTU0lPTlsnY3NyZl90b2tlbidd
ID8/ICcnKTsgJGIgPSAoc3RyaW5nKSgkX1BPU1RbJ2NzcmZfdG9rZW4nXSA/PyAnJyk7CiAgICAg
ICAgaWYgKCRhID09PSAnJyB8fCAkYiA9PT0gJycgfHwgIWhhc2hfZXF1YWxzKCRhLCAkYikpIHsg
aHR0cF9yZXNwb25zZV9jb2RlKDQxOSk7IGV4aXQoJ0xhIHNlc2nDs24gZGUgc2VndXJpZGFkIGNh
bWJpw7MuIFJlY2FyZ2EgbGEgcMOhZ2luYS4nKTsgfQogICAgfQoKICAgIHByaXZhdGUgZnVuY3Rp
b24gZmFsbG8oVGhyb3dhYmxlICRlKTogdm9pZAogICAgewogICAgICAgICRjb2RlID0gcHJlZ19t
YXRjaCgnL15NQUlMX09BVVRIX1tBLVpfXSskL0QnLCAkZS0+Z2V0TWVzc2FnZSgpKSA/ICRlLT5n
ZXRNZXNzYWdlKCkgOiAnTUFJTF9PQVVUSF9GQUlMVVJFJzsKICAgICAgICBlcnJvcl9sb2coJ01B
SUxfT0FVVEggY29kZT0nIC4gJGNvZGUgLiAnIGV4Y2VwdGlvbj0nIC4gZ2V0X2NsYXNzKCRlKSk7
CiAgICAgICAgJG1lc3NhZ2VzID0gWwogICAgICAgICAgICAnTUFJTF9PQVVUSF9DT05GSUcnID0+
ICdFbCBhZG1pbmlzdHJhZG9yIGRlYmUgY29uZmlndXJhciBsYSBhcGxpY2FjacOzbiBPQXV0aCB5
IHN1IFVSTCBkZSByZXRvcm5vIGFudGVzIGRlIGNvbmVjdGFyIGVzdGEgY3VlbnRhLicsCiAgICAg
ICAgICAgICdNQUlMX09BVVRIX0NPTkZJR19DSEFOR0VEJyA9PiAnTGEgY29uZmlndXJhY2nDs24g
ZGUgbGEgYXBsaWNhY2nDs24gY2FtYmnDsy4gSW5pY2lhIGRlIG51ZXZvIGxhIGF1dG9yaXphY2nD
s24uJywKICAgICAgICAgICAgJ01BSUxfT0FVVEhfU1RBVEUnID0+ICdMYSBhdXRvcml6YWNpw7Nu
IHZlbmNpw7MgbyBubyBwZXJ0ZW5lY2UgYSB0dSBzZXNpw7NuLiBJbsOtY2lhbGEgZGUgbnVldm8g
ZGVzZGUgZWwgQ1JNLicsCiAgICAgICAgICAgICdNQUlMX09BVVRIX0ZPUkJJRERFTicgPT4gJ0Vz
dGEgY3VlbnRhIHRpZW5lIHVuYSByZXN0cmljY2nDs24gYWRtaW5pc3RyYXRpdmEuIFNvbGljaXRh
IHN1IGF1dG9yaXphY2nDs24gcGFyYSB0dSB1c3VhcmlvLicsCiAgICAgICAgICAgICdNQUlMX09B
VVRIX01BTkFHRUQnID0+ICdFc3RhIGN1ZW50YSB5YSB0aWVuZSB1biB0cmFuc3BvcnRlIGFkbWlu
aXN0cmFkby4gU3UgQVBJLCBTTVRQIG8gY3JlZGVuY2lhbGVzIHNlIGFjdHVhbGl6YW4gY29uIGVs
IGFkbWluaXN0cmFkb3IuJywKICAgICAgICAgICAgJ01BSUxfT0FVVEhfREVOSUVEJyA9PiAnTm8g
c2UgY29uY2VkacOzIGVsIHBlcm1pc28gZGUgZW52w61vLiBMYSBjdWVudGEgbm8gc2UgaGEgY29u
ZWN0YWRvLicsCiAgICAgICAgICAgICdNQUlMX09BVVRIX1NDT1BFJyA9PiAnRmFsdGEgY29uY2Vk
ZXIgZWwgcGVybWlzbyBkZSBlbnbDrW8gZW4gZWwgcHJvdmVlZG9yLiBMYSBjdWVudGEgbm8gc2Ug
aGEgY29uZWN0YWRvLicsCiAgICAgICAgICAgICdNQUlMX09BVVRIX1JBVEUnID0+ICdTZSBhbGNh
bnrDsyBlbCBsw61taXRlIGRlIGludGVudG9zIGRlIGNvbmV4acOzbi4gRXNwZXJhIHVub3MgbWlu
dXRvcyBhbnRlcyBkZSBpbnRlbnRhcmxvIG90cmEgdmV6LicKICAgICAgICBdOwogICAgICAgICRf
U0VTU0lPTlsnbWFpbF9jb25uZWN0aW9uX2ZsYXNoJ10gPSAkbWVzc2FnZXNbJGNvZGVdID8/ICdO
byBzZSBwdWRvIGF1dGVudGljYXIgZWwgYnV6w7NuIHNvbGljaXRhZG8uIFJldmlzYSBsb3MgcGVy
bWlzb3MgZGVsIHByb3ZlZWRvciwgbGEgY29uZXhpw7NuIFNNVFAgeSBUTFMgZGUgSG9zdGluZ2Vy
IGNvbiBlbCBhZG1pbmlzdHJhZG9yLiBMYSBjdWVudGEgbm8gc2UgaGEgY29uZWN0YWRvLic7CiAg
ICB9CgogICAgcHJpdmF0ZSBmdW5jdGlvbiB2b2x2ZXIoc3RyaW5nICRlbWFpbCk6IHZvaWQKICAg
IHsKICAgICAgICAkcSA9IGZpbHRlcl92YXIoJGVtYWlsLCBGSUxURVJfVkFMSURBVEVfRU1BSUwp
ID8gJyZjb3JyZW89JyAuIHJhd3VybGVuY29kZSgkZW1haWwpIDogJyc7CiAgICAgICAgaGVhZGVy
KCdMb2NhdGlvbjogaW5kZXgucGhwP2NvbnRyb2xsZXI9Y29ycmVvQ29uZXhpb24mYWN0aW9uPWlu
ZGV4JyAuICRxLCB0cnVlLCAzMDMpOyBleGl0OwogICAgfQp9Cg==
CENTRAL_HELPER_10
 [[ "$(digest "$TMP/stage/app/controllers/CorreoConexionController.php")" == '6f0548eecce84ac569ac08d030518e5eda3ef14d79038bbd2b13efb775b6ddf0' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/controllers/CorreoConexionController.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/controllers/CorreoConexionController.php")"
  case "$existing" in 6f0548eecce84ac569ac08d030518e5eda3ef14d79038bbd2b13efb775b6ddf0|7cd1d720e67d8784363896bc975bbe39d6c0e0843ae1613b6ee3aca36cdb69ad) ;; *) fail 'Dependencia personalizada: app/controllers/CorreoConexionController.php. No se sobrescribe.';; esac
  if [[ "$existing" == '6f0548eecce84ac569ac08d030518e5eda3ef14d79038bbd2b13efb775b6ddf0' ]];then cp -p -- "$LIVE/app/controllers/CorreoConexionController.php" "$TMP/stage/app/controllers/CorreoConexionController.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/controllers/CorreoConexionController.php" "$TMP/stage/app/controllers/CorreoConexionController.php";chmod --reference="$LIVE/app/controllers/CorreoConexionController.php" "$TMP/stage/app/controllers/CorreoConexionController.php";fi
 else chmod 0644 "$TMP/stage/app/controllers/CorreoConexionController.php";fi
 base64 --decode > "$TMP/stage/app/views/correos/conexion.php" <<'CENTRAL_HELPER_11'
PD9waHAgJGVzY01haWwgPSBzdGF0aWMgZnVuY3Rpb24gKCRzKSB7IHJldHVybiBodG1sc3BlY2lh
bGNoYXJzKChzdHJpbmcpJHMsIEVOVF9RVU9URVMgfCBFTlRfU1VCU1RJVFVURSwgJ1VURi04Jyk7
IH07ID8+CjxzZWN0aW9uIGNsYXNzPSJkYXNoYm9hcmQtcGFuZWwiPgogICAgPGgyIGNsYXNzPSJw
YW5lbC10aXRsZSI+QXV0b3JpemFyIG1pIEdtYWlsPC9oMj4KICAgIDxwPkF1dG9yaXphIMO6bmlj
YW1lbnRlIHR1IEdtYWlsIHJlZ2lzdHJhZG8gcGFyYSBlbnZpYXIgY29uIGVzYSBkaXJlY2Npw7Nu
IGNvbW8gcmVtaXRlbnRlLiBUdSBzZXNpw7NuIHkgdHVzIHBlcm1pc29zIGRlbCBDUk0gbm8gY2Ft
Ymlhbi48L3A+CiAgICA8P3BocCBpZiAoJG5vdGljZSAhPT0gJycpOiA/PjxkaXYgY2xhc3M9ImFs
ZXJ0IGFsZXJ0LWluZm8iIHJvbGU9InN0YXR1cyI+PD89ICRlc2NNYWlsKCRub3RpY2UpID8+PC9k
aXY+PD9waHAgZW5kaWY7ID8+CiAgICA8Zm9ybSBtZXRob2Q9ImdldCIgYWN0aW9uPSJpbmRleC5w
aHAiIGNsYXNzPSJtYi0zIj4KICAgICAgICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBuYW1lPSJjb250
cm9sbGVyIiB2YWx1ZT0iY29ycmVvQ29uZXhpb24iPgogICAgICAgIDxpbnB1dCB0eXBlPSJoaWRk
ZW4iIG5hbWU9ImFjdGlvbiIgdmFsdWU9ImluZGV4Ij4KICAgICAgICA8bGFiZWwgY2xhc3M9ImZv
cm0tbGFiZWwiIGZvcj0ibWFpbC1jb25uZWN0aW9uLWFkZHJlc3MiPkNvcnJlbyBkZSBvcmlnZW48
L2xhYmVsPgogICAgICAgIDxpbnB1dCBjbGFzcz0iZm9ybS1jb250cm9sIiB0eXBlPSJlbWFpbCIg
bmFtZT0iY29ycmVvIiBpZD0ibWFpbC1jb25uZWN0aW9uLWFkZHJlc3MiIHZhbHVlPSI8Pz0gJGVz
Y01haWwoJGVtYWlsKSA/PiIgYXV0b2NvbXBsZXRlPSJlbWFpbCIgbWF4bGVuZ3RoPSIyNTQiIHJl
cXVpcmVkPgogICAgICAgIDxidXR0b24gY2xhc3M9ImJ0biBidG4tc3lzdGVtLWxpZ2h0IG10LTIi
IHR5cGU9InN1Ym1pdCI+Q29uc3VsdGFyIGNvbmV4acOzbjwvYnV0dG9uPgogICAgPC9mb3JtPgog
ICAgPD9waHAgaWYgKGZpbHRlcl92YXIoJGVtYWlsLCBGSUxURVJfVkFMSURBVEVfRU1BSUwpKTog
Pz4KICAgICAgICA8P3BocCBpZiAoJGNvbm5lY3RlZCk6ID8+PHA+RXN0YSBjdWVudGEgdGllbmUg
dW5hIGNvbmV4acOzbiBPQXV0aCBwYXJhIHR1IHVzdWFyaW8uPC9wPjw/cGhwIGVuZGlmOyA/Pgog
ICAgICAgIDw/cGhwIGZvcmVhY2ggKFsnZ29vZ2xlJyA9PiAnR29vZ2xlIC8gR21haWwnXSBhcyAk
cHJvdmlkZXIgPT4gJG5hbWUpOiA/PgogICAgICAgICAgICA8Zm9ybSBtZXRob2Q9InBvc3QiIGFj
dGlvbj0iaW5kZXgucGhwP2NvbnRyb2xsZXI9Y29ycmVvQ29uZXhpb24mYW1wO2FjdGlvbj1pbmlj
aWFyIiBjbGFzcz0ibWItMiI+CiAgICAgICAgICAgICAgICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBu
YW1lPSJjc3JmX3Rva2VuIiB2YWx1ZT0iPD89ICRlc2NNYWlsKCRfU0VTU0lPTlsnY3NyZl90b2tl
biddID8/ICcnKSA/PiI+CiAgICAgICAgICAgICAgICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBuYW1l
PSJjb3JyZW8iIHZhbHVlPSI8Pz0gJGVzY01haWwoJGVtYWlsKSA/PiI+CiAgICAgICAgICAgICAg
ICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBuYW1lPSJwcm92ZWVkb3IiIHZhbHVlPSI8Pz0gJGVzY01h
aWwoJHByb3ZpZGVyKSA/PiI+CiAgICAgICAgICAgICAgICA8YnV0dG9uIGNsYXNzPSJidG4gYnRu
LXN5c3RlbS1zYXZlIiB0eXBlPSJzdWJtaXQiIDw/PSBlbXB0eSgkcHJvdmlkZXJzWyRwcm92aWRl
cl0pID8gJ2Rpc2FibGVkJyA6ICcnID8+PkF1dG9yaXphciBjb24gPD89ICRlc2NNYWlsKCRuYW1l
KSA/PjwvYnV0dG9uPgogICAgICAgICAgICAgICAgPD9waHAgaWYgKGVtcHR5KCRwcm92aWRlcnNb
JHByb3ZpZGVyXSkpOiA/PjxzcGFuPkZhbHRhIGNvbmZpZ3VyYXIgbGEgYXBsaWNhY2nDs24gZGUg
ZXN0ZSBwcm92ZWVkb3IuPC9zcGFuPjw/cGhwIGVuZGlmOyA/PgogICAgICAgICAgICA8L2Zvcm0+
CiAgICAgICAgPD9waHAgZW5kZm9yZWFjaDsgPz4KICAgICAgICA8P3BocCBpZiAoJGNvbm5lY3Rl
ZCk6ID8+CiAgICAgICAgICAgIDxmb3JtIG1ldGhvZD0icG9zdCIgYWN0aW9uPSJpbmRleC5waHA/
Y29udHJvbGxlcj1jb3JyZW9Db25leGlvbiZhbXA7YWN0aW9uPWRlc2NvbmVjdGFyIiBjbGFzcz0i
bWItMyI+CiAgICAgICAgICAgICAgICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBuYW1lPSJjc3JmX3Rv
a2VuIiB2YWx1ZT0iPD89ICRlc2NNYWlsKCRfU0VTU0lPTlsnY3NyZl90b2tlbiddID8/ICcnKSA/
PiI+CiAgICAgICAgICAgICAgICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBuYW1lPSJjb3JyZW8iIHZh
bHVlPSI8Pz0gJGVzY01haWwoJGVtYWlsKSA/PiI+CiAgICAgICAgICAgICAgICA8YnV0dG9uIGNs
YXNzPSJidG4gYnRuLXN5c3RlbS1jYW5jZWwiIHR5cGU9InN1Ym1pdCI+RGVzY29uZWN0YXIgbWkg
YXV0b3JpemFjacOzbiBPQXV0aDwvYnV0dG9uPgogICAgICAgICAgICA8L2Zvcm0+CiAgICAgICAg
PD9waHAgZW5kaWY7ID8+CiAgICA8P3BocCBlbmRpZjsgPz4KICAgIDxwPkxhIGF1dG9yaXphY2nD
s24gc2UgcmVhbGl6YSBlbiBHb29nbGUuIE5vIGVzY3JpYmFzIGNvbnRyYXNlw7FhcyBuaSB0b2tl
bnMgZW4gbGEgcmVkYWNjacOzbiBkZWwgY29ycmVvLjwvcD4KICAgIDxwPkxhIG51ZXZhIGNvbmV4
acOzbiBHb29nbGUgc29saWNpdGEgcGVybWlzbyBwYXJhIGVudmlhciB5IHZlcmlmaWNhciBsYSBk
aXJlY2Npw7NuIGRlIHR1IGN1ZW50YTsgbm8gc29saWNpdGEgbGVjdHVyYSBuaSBlbGltaW5hY2nD
s24gZGUgbWVuc2FqZXMuPC9wPgogICAgPHA+TG9zIGVtcGxlYWRvcyBjb24gY29ycmVvIEhvc3Rp
bmdlciBlbnbDrWFuIGF1dG9tw6F0aWNhbWVudGUgbWVkaWFudGUgbGEgQVBJIGV4aXN0ZW50ZSB5
IG5vIG5lY2VzaXRhbiBlc3RhIGF1dG9yaXphY2nDs24uIExhIHJlY3VwZXJhY2nDs24gZGUgY29u
dHJhc2XDsWFzIHVzYSBzdSBwcm9waWEgY3VlbnRhIGluZGVwZW5kaWVudGUuPC9wPgogICAgPHA+
QXV0b3JpemFyIGxhIGN1ZW50YSBjb21wcnVlYmEgZWwgYWNjZXNvIGFsIGJ1esOzbiBzaW4gZW52
aWFyIG1lbnNhamVzLiBMYSByZWNlcGNpw7NuIGRlIGNvcnJlb3MgeSBhZGp1bnRvcyBzZSB2ZXJp
ZmljYSBkZXNwdcOpcyBjb24gdW4gZGVzdGluYXRhcmlvIGRlIHBydWViYSBhdXRvcml6YWRvLjwv
cD4KPC9zZWN0aW9uPgo=
CENTRAL_HELPER_11
 [[ "$(digest "$TMP/stage/app/views/correos/conexion.php")" == '65841045e2ede703fa302862292d459c10d2892a07b63eb1d25ed24c5447e7c5' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/views/correos/conexion.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/views/correos/conexion.php")"
  case "$existing" in 49581727ad6c2d1221910f86213aac3daae28036053683bd03608bc5e3904e75|65841045e2ede703fa302862292d459c10d2892a07b63eb1d25ed24c5447e7c5) ;; *) fail 'Dependencia personalizada: app/views/correos/conexion.php. No se sobrescribe.';; esac
  if [[ "$existing" == '65841045e2ede703fa302862292d459c10d2892a07b63eb1d25ed24c5447e7c5' ]];then cp -p -- "$LIVE/app/views/correos/conexion.php" "$TMP/stage/app/views/correos/conexion.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/views/correos/conexion.php" "$TMP/stage/app/views/correos/conexion.php";chmod --reference="$LIVE/app/views/correos/conexion.php" "$TMP/stage/app/views/correos/conexion.php";fi
 else chmod 0644 "$TMP/stage/app/views/correos/conexion.php";fi
 base64 --decode > "$TMP/diagnostic.php" <<'CENTRAL_DIAGNOSTIC'
PD9waHAKLy8gU8OzbG8gU1NIL0NMSS4gTm8gbXVlc3RyYSBjcmVkZW5jaWFsZXMgbmkgcGVybWl0
ZSBlbnZpYXIgZGVzZGUgdW5hIHBldGljacOzbiB3ZWIuCmlmIChQSFBfU0FQSSAhPT0gJ2NsaScp
IHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwNCk7IGV4aXQ7IH0KcmVxdWlyZSBkaXJuYW1lKF9fRElS
X18pIC4gJy9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhw
JzsKcmVxdWlyZSBkaXJuYW1lKF9fRElSX18pIC4gJy9hcHAvc2VydmljZXMvTWFpbFNlcnZpY2Uu
cGhwJzsKJG9wdGlvbnMgPSBnZXRvcHQoJycsIFsncmVjb3ZlcnknLCAnY2VudHJhbCcsICdhY2Nv
dW50OicsICd1c2VyOicsICdwcm9iZScsICdzZW5kLXRlc3QnLCAndG86JywgJ2RvbWFpbjonLCAn
ZGtpbTonXSk7CmZ1bmN0aW9uIGZsYWdNYWlsKCRsYWJlbCwgJG9rKSB7IGVjaG8gJGxhYmVsIC4g
JzogJyAuICgkb2sgPyAnT0snIDogJ0ZBTFRBL1JFVklTQVInKSAuIFBIUF9FT0w7IH0KdHJ5IHsK
ICAgIGVjaG8gJ1BIUCAnIC4gUEhQX1ZFUlNJT04gLiAnIFNBUEkgJyAuIFBIUF9TQVBJIC4gUEhQ
X0VPTDsKICAgIGZvcmVhY2ggKFsnb3BlbnNzbCcsICdjdXJsJywgJ21ic3RyaW5nJywgJ215c3Fs
aSddIGFzICRleHQpIHsgZmxhZ01haWwoJ2V4dGVuc2lvbiAnIC4gJGV4dCwgZXh0ZW5zaW9uX2xv
YWRlZCgkZXh0KSk7IH0KICAgIGZsYWdNYWlsKCdQSFBNYWlsZXInLCBjbGFzc19leGlzdHMoJ1BI
UE1haWxlclxcUEhQTWFpbGVyXFxQSFBNYWlsZXInKSk7CiAgICBpZiAoaXNzZXQoJG9wdGlvbnNb
J2NlbnRyYWwnXSkpIHsKICAgICAgICAkYSA9IChuZXcgQ29ycmVvQ3VlbnRhQ29uZWN0YWRhU2Vy
dmljZSgpKS0+Y2VudHJhbCgpOyAkZnJvbSA9ICRhWydjb3JyZW8nXTsgJHVzZXIgPSAoaW50KSgk
b3B0aW9uc1sndXNlciddID8/IDApOwogICAgICAgIGVjaG8gIkN1ZW50YSBpbnN0aXR1Y2lvbmFs
IGNlbnRyYWwuIE5vIHVzYSBjb25leGlvbmVzIHBlcnNvbmFsZXMuXG4iOwogICAgfSBlbHNlaWYg
KGlzc2V0KCRvcHRpb25zWydhY2NvdW50J10pKSB7CiAgICAgICAgJGZyb20gPSBzdHJ0b2xvd2Vy
KHRyaW0oJG9wdGlvbnNbJ2FjY291bnQnXSkpOyAkdXNlciA9IChpbnQpKCRvcHRpb25zWyd1c2Vy
J10gPz8gMCk7CiAgICAgICAgJHJlc3VsdCA9IChuZXcgQ29ycmVvQ3VlbnRhQ29uZWN0YWRhU2Vy
dmljZSgpKS0+cmVzb2x2ZXIoJGZyb20sICR1c2VyLCB0cnVlKTsKICAgICAgICBpZiAoZW1wdHko
JHJlc3VsdFsnb2snXSkpIHsgZWNobyAnQ3VlbnRhOiAnIC4gJHJlc3VsdFsnbWVuc2FqZSddIC4g
UEhQX0VPTDsgZXhpdCgyKTsgfQogICAgICAgICRhID0gJHJlc3VsdFsnYWNjb3VudCddOwogICAg
fSBlbHNlIHsKICAgICAgICBlY2hvICdDb25maWd1cmFjacOzbiBkZSByZWN1cGVyYWNpw7NuOiAn
IC4gKGltcGVSdXRhQ29uZmlnUHJpdmFkYSgncGFzc3dvcmRfcmVzZXRfbWFpbC5sb2NhbC5waHAn
KSAhPT0gbnVsbCA/ICdhcmNoaXZvIGRlZGljYWRvJyA6ICdNQUlMXyogZGUgYXJjaGl2byBwcml2
YWRvL2VudG9ybm8nKSAuIFBIUF9FT0w7CiAgICAgICAgJGEgPSBNYWlsU2VydmljZTo6Y3VlbnRh
UmVjdXBlcmFjaW9uKCk7CiAgICAgICAgJGZyb20gPSAoc3RyaW5nKSgkYVsnY29ycmVvJ10gPz8g
JGFbJ3NtdHBfdXNlcm5hbWUnXSA/PyAnJyk7CiAgICAgICAgJHVzZXIgPSAwOwogICAgICAgIGVj
aG8gIkN1ZW50YSBkZWRpY2FkYSBkZSByZWN1cGVyYWNpw7NuIChubyBtb2RpZmljYSB1c3Vhcmlv
cyBuaSBjb250cmFzZcOxYXMpLlxuIjsKICAgIH0KICAgICR0cmFuc3BvcnQgPSAkYVsndHJhbnNw
b3J0J10gPz8gJ3NtdHAnOwogICAgZWNobyAnVHJhbnNwb3J0ZTogJyAuICR0cmFuc3BvcnQgLiBQ
SFBfRU9MOwogICAgaWYgKCR0cmFuc3BvcnQgPT09ICdob3N0aW5nZXJfYXBpJyAmJiAkZnJvbSA9
PT0gJycpIHsgZWNobyAiSWRlbnRpZGFkOiBzZWxlY2Npw7NuIGF1dG9tw6F0aWNhIGRlbCBidXrD
s24gZGVsIHVzdWFyaW8gcmVnaXN0cmFkby5cbiI7IH0KICAgIGVsc2UgeyBmbGFnTWFpbCgnSWRl
bnRpZGFkIGNvbmZpZ3VyYWRhJywgZmlsdGVyX3ZhcigkZnJvbSwgRklMVEVSX1ZBTElEQVRFX0VN
QUlMKSAhPT0gZmFsc2UpOyB9CiAgICBpZiAoJHRyYW5zcG9ydCA9PT0gJ3NtdHAnKSB7CiAgICAg
ICAgJG1haWwgPSBuZXcgXFBIUE1haWxlclxQSFBNYWlsZXJcUEhQTWFpbGVyKHRydWUpOwogICAg
ICAgIENvcnJlb1NtdHBUcmFuc3BvcnQ6OmNvbmZpZ3VyYXIoJG1haWwsICRhKTsKICAgICAgICBl
Y2hvICdTTVRQICcgLiAkbWFpbC0+SG9zdCAuICc6JyAuICRtYWlsLT5Qb3J0IC4gJyAnIC4gJG1h
aWwtPlNNVFBTZWN1cmUgLiBQSFBfRU9MOwogICAgICAgIGZsYWdNYWlsKCdUTFMgeSBjZXJ0aWZp
Y2Fkb3MgdmVyaWZpY2Fkb3MnLCAkbWFpbC0+U01UUE9wdGlvbnNbJ3NzbCddWyd2ZXJpZnlfcGVl
ciddICYmICRtYWlsLT5TTVRQT3B0aW9uc1snc3NsJ11bJ3ZlcmlmeV9wZWVyX25hbWUnXSk7CiAg
ICAgICAgZmxhZ01haWwoJ0ROUyBTTVRQJywgZmlsdGVyX3ZhcigkbWFpbC0+SG9zdCwgRklMVEVS
X1ZBTElEQVRFX0lQKSB8fCBnZXRob3N0YnluYW1lKCRtYWlsLT5Ib3N0KSAhPT0gJG1haWwtPkhv
c3QpOwogICAgICAgIGlmIChpc3NldCgkb3B0aW9uc1sncHJvYmUnXSkpIHsKICAgICAgICAgICAg
Ly8gRUhMTy9UTFMvQVVUSCwgc2luIE1BSUwvUkNQVC9EQVRBLiBPQXV0aCBwdWVkZSByZW5vdmFy
IHN1IHRva2VuIHByaXZhZG8uCiAgICAgICAgICAgICRjb25uZWN0ZWQgPSAkbWFpbC0+c210cENv
bm5lY3QoKTsgZmxhZ01haWwoJ0NvbmV4acOzbiBUTFMgeSBhdXRlbnRpY2FjacOzbicsICRjb25u
ZWN0ZWQpOyAkbWFpbC0+c210cENsb3NlKCk7CiAgICAgICAgICAgIGlmICghJGNvbm5lY3RlZCkg
eyBleGl0KDIpOyB9CiAgICAgICAgfQogICAgfSBlbHNlaWYgKCR0cmFuc3BvcnQgPT09ICdob3N0
aW5nZXJfYXBpJykgewogICAgICAgIGZsYWdNYWlsKCdUb2tlbiBBUEkgY29uZmlndXJhZG8nLCAh
ZW1wdHkoJGFbJ2FwaV90b2tlbiddKSk7CiAgICAgICAgaWYgKGlzc2V0KCRvcHRpb25zWydwcm9i
ZSddKSkgewogICAgICAgICAgICBpZiAoKCRhWydhcGlfYmFzZV91cmwnXSA/PyAnJykgIT09ICdo
dHRwczovL2FwaS5tYWlsLmhvc3Rpbmdlci5jb20nKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0
aW9uKCdNQUlMX0FQSV9CQVNFJyk7IH0KICAgICAgICAgICAgJGMgPSBjdXJsX2luaXQoJ2h0dHBz
Oi8vYXBpLm1haWwuaG9zdGluZ2VyLmNvbS9hcGkvdjEvbWUnKTsKICAgICAgICAgICAgY3VybF9z
ZXRvcHRfYXJyYXkoJGMsIFtDVVJMT1BUX1JFVFVSTlRSQU5TRkVSID0+IHRydWUsIENVUkxPUFRf
SFRUUEhFQURFUiA9PiBbJ0F1dGhvcml6YXRpb246IEJlYXJlciAnIC4gJGFbJ2FwaV90b2tlbidd
XSwKICAgICAgICAgICAgICAgIENVUkxPUFRfU1NMX1ZFUklGWVBFRVIgPT4gdHJ1ZSwgQ1VSTE9Q
VF9TU0xfVkVSSUZZSE9TVCA9PiAyLCBDVVJMT1BUX0ZPTExPV0xPQ0FUSU9OID0+IGZhbHNlLCBD
VVJMT1BUX0NPTk5FQ1RUSU1FT1VUID0+IDEwLCBDVVJMT1BUX1RJTUVPVVQgPT4gMjVdKTsKICAg
ICAgICAgICAgJGJvZHkgPSBjdXJsX2V4ZWMoJGMpOyAkY29kZSA9IChpbnQpY3VybF9nZXRpbmZv
KCRjLCBDVVJMSU5GT19IVFRQX0NPREUpOyBjdXJsX2Nsb3NlKCRjKTsKICAgICAgICAgICAgJGpz
b24gPSBqc29uX2RlY29kZSgoc3RyaW5nKSRib2R5LCB0cnVlKTsgJGFsbG93ZWQgPSAkZnJvbSA9
PT0gJycgJiYgIWVtcHR5KCRqc29uWydkYXRhJ11bJ21haWxib3hlcyddKTsKICAgICAgICAgICAg
Zm9yZWFjaCAoKCRqc29uWydkYXRhJ11bJ21haWxib3hlcyddID8/IFtdKSBhcyAkYm94KSB7IGlm
IChzdHJjYXNlY21wKChzdHJpbmcpKCRib3hbJ2FkZHJlc3MnXSA/PyAnJyksICRmcm9tKSA9PT0g
MCkgeyAkYWxsb3dlZCA9IHRydWU7IH0gfQogICAgICAgICAgICBlY2hvICdIVFRQIGNvbnN1bHRh
IEFQSTogJyAuICRjb2RlIC4gUEhQX0VPTDsgZmxhZ01haWwoJ0J1esOzbiBhdXRvcml6YWRvJywg
JGNvZGUgPT09IDIwMCAmJiAkYWxsb3dlZCk7CiAgICAgICAgICAgIGVjaG8gJ0J1em9uZXMgZGlz
cG9uaWJsZXM6ICcgLiBjb3VudCgkanNvblsnZGF0YSddWydtYWlsYm94ZXMnXSA/PyBbXSkgLiBQ
SFBfRU9MOwogICAgICAgICAgICBpZiAoJGNvZGUgIT09IDIwMCB8fCAhJGFsbG93ZWQpIHsgZXhp
dCgyKTsgfQogICAgICAgIH0KICAgIH0KICAgIGlmIChpc3NldCgkb3B0aW9uc1snZG9tYWluJ10p
KSB7CiAgICAgICAgJGRvbWFpbiA9ICRvcHRpb25zWydkb21haW4nXTsgJHNlbGVjdG9yID0gJG9w
dGlvbnNbJ2RraW0nXSA/PyAnJzsKICAgICAgICBpZiAoIXByZWdfbWF0Y2goJy9eW2EtekEtWjAt
OS4tXSskL0QnLCAkZG9tYWluIC4gJHNlbGVjdG9yKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2Vw
dGlvbignTUFJTF9ETlNfSU5QVVQnKTsgfQogICAgICAgICRzcGYgPSBmYWxzZTsKICAgICAgICBm
b3JlYWNoIChkbnNfZ2V0X3JlY29yZCgkZG9tYWluLCBETlNfVFhUKSA/OiBbXSBhcyAkcmVjb3Jk
KSB7IGlmIChzdHJfc3RhcnRzX3dpdGgoJHJlY29yZFsndHh0J10gPz8gJycsICd2PXNwZjEnKSkg
eyAkc3BmID0gdHJ1ZTsgfSB9CiAgICAgICAgZmxhZ01haWwoJ1JlZ2lzdHJvIFNQRiBwcmVzZW50
ZSAobm8gY2VydGlmaWNhIGFsaW5lYWNpw7NuKScsICRzcGYpOwogICAgICAgIGZsYWdNYWlsKCdS
ZWdpc3RybyBETUFSQyBwcmVzZW50ZScsIChib29sKWRuc19nZXRfcmVjb3JkKCdfZG1hcmMuJyAu
ICRkb21haW4sIEROU19UWFQpKTsKICAgICAgICBpZiAoJHNlbGVjdG9yICE9PSAnJykgeyBmbGFn
TWFpbCgnUmVnaXN0cm8gREtJTSBwcmVzZW50ZScsIChib29sKWRuc19nZXRfcmVjb3JkKCRzZWxl
Y3RvciAuICcuX2RvbWFpbmtleS4nIC4gJGRvbWFpbiwgRE5TX1RYVCB8IEROU19DTkFNRSkpOyB9
CiAgICB9CiAgICBpZiAoaXNzZXQoJG9wdGlvbnNbJ3NlbmQtdGVzdCddKSkgewogICAgICAgIGlm
IChpc3NldCgkb3B0aW9uc1snY2VudHJhbCddKSAmJiAkdXNlciA8PSAwKSB7IHRocm93IG5ldyBS
dW50aW1lRXhjZXB0aW9uKCdNQUlMX1RFU1RfVVNFUicpOyB9CiAgICAgICAgaWYgKGlzc2V0KCRv
cHRpb25zWydjZW50cmFsJ10pICYmICRmcm9tID09PSAnJyAmJiAkdXNlciA+IDApIHsKICAgICAg
ICAgICAgcmVxdWlyZV9vbmNlIGRpcm5hbWUoX19ESVJfXykgLiAnL2FwcC9tb2RlbHMvVXN1YXJp
b01vZGVsLnBocCc7CiAgICAgICAgICAgICRmcm9tID0gKHN0cmluZykoKG5ldyBVc3VhcmlvTW9k
ZWwoKSktPmJ1c2NhclBvcklkKCR1c2VyKVsnY29ycmVvJ10gPz8gJycpOwogICAgICAgIH0KICAg
ICAgICAkdG8gPSB0cmltKChzdHJpbmcpKCRvcHRpb25zWyd0byddID8/ICcnKSk7CiAgICAgICAg
aWYgKCFmaWx0ZXJfdmFyKCR0bywgRklMVEVSX1ZBTElEQVRFX0VNQUlMKSkgeyB0aHJvdyBuZXcg
UnVudGltZUV4Y2VwdGlvbignTUFJTF9URVNUX1JFQ0lQSUVOVCcpOyB9CiAgICAgICAgJHN1Ympl
Y3QgPSAnSU1QRSBwcnVlYmEgYXV0b3JpemFkYSAnIC4gZ21kYXRlKCdZLW0tZCBIOmk6cycpOwog
ICAgICAgICRib2R5ID0gJ01lbnNhamUgZmljdGljaW8gZGUgZGlhZ27Ds3N0aWNvLiBObyBjb250
aWVuZSB1bmEgY29udHJhc2XDsWEgbmkgY2FtYmlhIG5pbmd1bmEgY3VlbnRhLiBDb25maXJtYXIg
cmVjZXBjacOzbiBtYW51YWxtZW50ZS4nOwogICAgICAgIGlmICgkdXNlciA+IDApIHsKICAgICAg
ICAgICAgJHIgPSAobmV3IENvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlKCkpLT5lbnZp
YXIoWyd1c3VhcmlvX2lkJyA9PiAkdXNlciwgJ3BlcmZpbF9tYXJrZXRpbmcnID0+IHRydWUsCiAg
ICAgICAgICAgICAgICAncmVtaXRlbnRlJyA9PiAkZnJvbSwgJ2Rlc3RpbmF0YXJpbycgPT4gJHRv
LCAnYXN1bnRvJyA9PiAkc3ViamVjdCwgJ2N1ZXJwbycgPT4gJGJvZHksCiAgICAgICAgICAgICAg
ICAnaWRlbXBvdGVuY3lfa2V5JyA9PiAncWEtY2xpOicgLiBiaW4yaGV4KHJhbmRvbV9ieXRlcygx
NikpXSk7CiAgICAgICAgfSBlbHNlIHsKICAgICAgICAgICAgJG1haWwtPnNldEZyb20oJGZyb20p
OyAkbWFpbC0+YWRkQWRkcmVzcygkdG8pOyAkbWFpbC0+U3ViamVjdCA9ICRzdWJqZWN0OyAkbWFp
bC0+Qm9keSA9ICRib2R5OwogICAgICAgICAgICAkciA9IENvcnJlb1NtdHBUcmFuc3BvcnQ6OmVu
dmlhcigkbWFpbCwgJ3JlY292ZXJ5X3Rlc3QnKTsKICAgICAgICB9CiAgICAgICAgZWNobyAnUmVz
dWx0YWRvOiAnIC4gKCRyWydlc3RhZG9fZW50cmVnYSddID8/ICdSRUNIQVpBRE8nKSAuICc7IGVu
dHJlZ2EgYWwgZGVzdGluYXRhcmlvIE5PIGNvbmZpcm1hZGEuJyAuIFBIUF9FT0w7CiAgICAgICAg
aWYgKGVtcHR5KCRyWydvayddKSkgeyBleGl0KDIpOyB9CiAgICB9IGVsc2UgeyBlY2hvICJTaW4g
ZW52w61vIGRlIG1lbnNhamVzLiBVc2EgLS1wcm9iZSBwYXJhIGNvbXByb2JhciBUTFMvYXV0ZW50
aWNhY2nDs24gZGVzZGUgU1NILlxuIjsgfQp9IGNhdGNoIChUaHJvd2FibGUgJGUpIHsKICAgICRh
bGxvd2VkID0gWydNQUlMX1JFU0VUX0NPTkZJRycsICdNQUlMX1JFU0VUX1RSQU5TUE9SVCcsICdN
QUlMX1JFU0VUX0lERU5USVRZJywgJ01BSUxfU01UUF9DT05GSUcnLCAnTUFJTF9TTVRQX0NBJywg
J01BSUxfQ0VOVFJBTF9DT05GSUcnLCAnTUFJTF9URVNUX1VTRVInXTsKICAgICRyZWFzb24gPSBp
bl9hcnJheSgkZS0+Z2V0TWVzc2FnZSgpLCAkYWxsb3dlZCwgdHJ1ZSkgPyAkZS0+Z2V0TWVzc2Fn
ZSgpIDogJ01BSUxfRElBR05PU1RJQ19GQUlMRUQnOwogICAgJHNtdHBDb2RlID0gaXNzZXQoJG1h
aWwpID8gKGludCkoJG1haWwtPmdldFNNVFBJbnN0YW5jZSgpLT5nZXRFcnJvcigpWydzbXRwX2Nv
ZGUnXSA/PyAwKSA6IDA7CiAgICBlcnJvcl9sb2coJ01BSUxfRElBR05PU1RJQ19GQUlMRUQgcmVh
c29uPScgLiAkcmVhc29uIC4gJyBzbXRwX2NvZGU9JyAuICRzbXRwQ29kZSAuICcgZXhjZXB0aW9u
PScgLiBnZXRfY2xhc3MoJGUpKTsKICAgIGVjaG8gJ0PDs2RpZ28gc2VndXJvOiAnIC4gJHJlYXNv
biAuICgkc210cENvZGUgPyAnOyBTTVRQICcgLiAkc210cENvZGUgOiAnJykgLiBQSFBfRU9MOwog
ICAgZWNobyAiRGlhZ27Ds3N0aWNvIGluY29tcGxldG8uIFJldmlzYXIgY29uZmlndXJhY2nDs24s
IHBlcm1pc29zIHByaXZhZG9zLCBjZXJ0aWZpY2Fkb3MsIGF1dGVudGljYWNpw7NuIG8gcmVzdHJp
Y2Npb25lcyBzYWxpZW50ZXMuIE5vIHNlIG11ZXN0cmFuIHNlY3JldG9zLlxuIjsKICAgIGV4aXQo
Mik7Cn0K
CENTRAL_DIAGNOSTIC
 [[ "$(digest "$TMP/diagnostic.php")" == '702da432e0c01e42d7cfc8a0fa3e5d6157efd68dc14ec290a7c105dd779a6e17' ]] || fail 'Diagnóstico alterado.'
 [[ ! -e "$LIVE/tools" || -d "$LIVE/tools" ]] || fail 'tools no es directorio.'
 mkdir -p "$TMP/patches/0"
 base64 --decode > "$TMP/patches/0/1.patch" <<'CENTRAL_PATCH_0_1'
ZGlmZiAtLWdpdCBhL2NvbmZpZy9tYWlsX2NvbmZpZy5waHAgYi9jb25maWcvbWFpbF9jb25maWcu
cGhwCi0tLSBhL2NvbmZpZy9tYWlsX2NvbmZpZy5waHAKKysrIGIvY29uZmlnL21haWxfY29uZmln
LnBocApAQCAtOCwxMyArOCwxOCBAQAogICAgIHJlcXVpcmVfb25jZSAkcnV0YUxvY2FsOwogfQog
CisvLyBTaSBzZSBjb25zZXJ2YSBsYSBjdWVudGEgR21haWwgZGVsIGVudG9ybm8gbG9jYWwgcGVy
byBmYWx0YSBNQUlMX0hPU1QsCisvLyBubyBhdXRlbnRpY2FybGEgY29udHJhIEhvc3Rpbmdlci4g
VW4gaG9zdCBleHBsw61jaXRvIHNpZW1wcmUgdGllbmUgcHJpb3JpZGFkLgorJHVzdWFyaW9TbXRw
ID0gdHJpbSgoc3RyaW5nKShkZWZpbmVkKCdNQUlMX1VTRVJOQU1FJykgPyBNQUlMX1VTRVJOQU1F
IDogKGdldGVudignTUFJTF9VU0VSTkFNRScpID86ICcnKSkpOworJGhvc3RQcmVkZXRlcm1pbmFk
byA9IHByZWdfbWF0Y2goJy9AKGdtYWlsfGdvb2dsZW1haWwpXC5jb20kL2knLCAkdXN1YXJpb1Nt
dHApCisgICAgPyAnc210cC5nbWFpbC5jb20nIDogJ3NtdHAuaG9zdGluZ2VyLmNvbSc7CiAkdmFs
b3JlcyA9IFsKLSAgICAnTUFJTF9IT1NUJyA9PiAnc210cC5ob3N0aW5nZXIuY29tJywKKyAgICAn
TUFJTF9IT1NUJyA9PiAkaG9zdFByZWRldGVybWluYWRvLAogICAgICdNQUlMX1BPUlQnID0+IDU4
NywKICAgICAnTUFJTF9VU0VSTkFNRScgPT4gJycsCiAgICAgJ01BSUxfUEFTU1dPUkQnID0+ICcn
LAogICAgICdNQUlMX0VOQ1JZUFRJT04nID0+ICd0bHMnLAotICAgICdNQUlMX0ZST01fQUREUkVT
UycgPT4gdHJpbSgoc3RyaW5nKShkZWZpbmVkKCdNQUlMX1VTRVJOQU1FJykgPyBNQUlMX1VTRVJO
QU1FIDogKGdldGVudignTUFJTF9VU0VSTkFNRScpID86ICcnKSkpLAorICAgICdNQUlMX0ZST01f
QUREUkVTUycgPT4gJHVzdWFyaW9TbXRwLAogICAgICdNQUlMX0ZST01fTkFNRScgPT4gJ0Z1bmRh
Y2nDs24gUmVkIEVkdWNhdGl2YSBNw6l4aWNvJywKIF07CiAK
CENTRAL_PATCH_0_1
 [[ "$(digest "$TMP/patches/0/1.patch")" == '0d72f43d62272b25f2060c363e05f1ff9493e539f8e9d9a5255853525ca369bd' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/0/2.patch" <<'CENTRAL_PATCH_0_2'
ZGlmZiAtLWdpdCBhL2NvbmZpZy9tYWlsX2NvbmZpZy5waHAgYi9jb25maWcvbWFpbF9jb25maWcu
cGhwCi0tLSBhL2NvbmZpZy9tYWlsX2NvbmZpZy5waHAKKysrIGIvY29uZmlnL21haWxfY29uZmln
LnBocApAQCAtOCwxMyArOCwxOCBAQAogICAgIHJlcXVpcmVfb25jZSAkcnV0YUxvY2FsOwogfQog
CisvLyBTaSBzZSBjb25zZXJ2YSBsYSBjdWVudGEgR21haWwgZGVsIGVudG9ybm8gbG9jYWwgcGVy
byBmYWx0YSBNQUlMX0hPU1QsCisvLyBubyBhdXRlbnRpY2FybGEgY29udHJhIEhvc3Rpbmdlci4g
VW4gaG9zdCBleHBsw61jaXRvIHNpZW1wcmUgdGllbmUgcHJpb3JpZGFkLgorJHVzdWFyaW9TbXRw
ID0gdHJpbSgoc3RyaW5nKShkZWZpbmVkKCdNQUlMX1VTRVJOQU1FJykgPyBNQUlMX1VTRVJOQU1F
IDogKGdldGVudignTUFJTF9VU0VSTkFNRScpID86ICcnKSkpOworJGhvc3RQcmVkZXRlcm1pbmFk
byA9IHByZWdfbWF0Y2goJy9AKGdtYWlsfGdvb2dsZW1haWwpXC5jb20kL2knLCAkdXN1YXJpb1Nt
dHApCisgICAgPyAnc210cC5nbWFpbC5jb20nIDogJ3NtdHAuaG9zdGluZ2VyLmNvbSc7CiAkdmFs
b3JlcyA9IFsKLSAgICAnTUFJTF9IT1NUJyA9PiAnc210cC5ob3N0aW5nZXIuY29tJywKKyAgICAn
TUFJTF9IT1NUJyA9PiAkaG9zdFByZWRldGVybWluYWRvLAogICAgICdNQUlMX1BPUlQnID0+IDU4
NywKICAgICAnTUFJTF9VU0VSTkFNRScgPT4gJycsCiAgICAgJ01BSUxfUEFTU1dPUkQnID0+ICcn
LAogICAgICdNQUlMX0VOQ1JZUFRJT04nID0+ICd0bHMnLAotICAgICdNQUlMX0ZST01fQUREUkVT
UycgPT4gdHJpbSgoc3RyaW5nKShnZXRlbnYoJ01BSUxfVVNFUk5BTUUnKSA/OiAnJykpLAorICAg
ICdNQUlMX0ZST01fQUREUkVTUycgPT4gJHVzdWFyaW9TbXRwLAogICAgICdNQUlMX0ZST01fTkFN
RScgPT4gJ0Z1bmRhY2nDs24gUmVkIEVkdWNhdGl2YSBNw6l4aWNvJywKIF07CiAK
CENTRAL_PATCH_0_2
 [[ "$(digest "$TMP/patches/0/2.patch")" == '1746275ba1c8b6d1eeb91dde6012a903274e77736f3dad6137b2137a03dd9d31' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/1"
 base64 --decode > "$TMP/patches/1/1.patch" <<'CENTRAL_PATCH_1_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9NYWlsU2VydmljZS5waHAgYi9hcHAvc2VydmljZXMv
TWFpbFNlcnZpY2UucGhwCi0tLSBhL2FwcC9zZXJ2aWNlcy9NYWlsU2VydmljZS5waHAKKysrIGIv
YXBwL3NlcnZpY2VzL01haWxTZXJ2aWNlLnBocApAQCAtMSw3ICsxLDcgQEAKIDw/cGhwCiAKLXJl
cXVpcmVfb25jZSBST09UX1BBVEggLiAnL3ZlbmRvci9hdXRvbG9hZC5waHAnOwotcmVxdWlyZV9v
bmNlIFJPT1RfUEFUSCAuICcvY29uZmlnL21haWxfY29uZmlnLnBocCc7CityZXF1aXJlX29uY2Ug
ZGlybmFtZShfX0RJUl9fLCAyKSAuICcvdmVuZG9yL2F1dG9sb2FkLnBocCc7CityZXF1aXJlX29u
Y2UgZGlybmFtZShfX0RJUl9fLCAyKSAuICcvY29uZmlnL21haWxfY29uZmlnLnBocCc7CiByZXF1
aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvU210cFRyYW5zcG9ydC5waHAnOwogCiB1c2UgUEhQ
TWFpbGVyXFBIUE1haWxlclxQSFBNYWlsZXI7CkBAIC0xMCwyMCArMTAsMzggQEAKIGNsYXNzIE1h
aWxTZXJ2aWNlCiB7CiAgICAgcHJpdmF0ZSAkbWFpbGVyRmFjdG9yeTsKKyAgICBwcml2YXRlICRy
ZXN1bHRhZG8gPSBbXTsKICAgICBwdWJsaWMgZnVuY3Rpb24gX19jb25zdHJ1Y3QoP2NhbGxhYmxl
ICRtYWlsZXJGYWN0b3J5ID0gbnVsbCkgeyAkdGhpcy0+bWFpbGVyRmFjdG9yeSA9ICRtYWlsZXJG
YWN0b3J5OyB9CisgICAgcHVibGljIGZ1bmN0aW9uIHVsdGltb1Jlc3VsdGFkbygpOiBhcnJheSB7
IHJldHVybiAkdGhpcy0+cmVzdWx0YWRvOyB9CisKKyAgICAvKiogTWlzbWEgY3VlbnRhIHBhcmEg
ZWwgZW52w61vIHdlYiB5IGxhIGNvbXByb2JhY2nDs24gQ0xJOyBzaW4gY3VlbnRhcyBjb21lcmNp
YWxlcy4gKi8KKyAgICBwdWJsaWMgc3RhdGljIGZ1bmN0aW9uIGN1ZW50YVJlY3VwZXJhY2lvbigp
OiBhcnJheQorICAgIHsKKyAgICAgICAgJHJ1dGEgPSBpbXBlUnV0YUNvbmZpZ1ByaXZhZGEoJ3Bh
c3N3b3JkX3Jlc2V0X21haWwubG9jYWwucGhwJyk7CisgICAgICAgIGlmICgkcnV0YSAhPT0gbnVs
bCkgeworICAgICAgICAgICAgJGN1ZW50YSA9IGltcGVMZWVyQ29uZmlnUHJpdmFkYSgncGFzc3dv
cmRfcmVzZXRfbWFpbC5sb2NhbC5waHAnKTsKKyAgICAgICAgICAgIGlmICghJGN1ZW50YSkgeyB0
aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9SRVNFVF9DT05GSUcnKTsgfQorICAgICAg
ICB9IGVsc2UgeworICAgICAgICAgICAgJGN1ZW50YSA9IFsnc210cF9ob3N0JyA9PiBNQUlMX0hP
U1QsICdzbXRwX3BvcnQnID0+IE1BSUxfUE9SVCwKKyAgICAgICAgICAgICAgICAnc210cF91c2Vy
bmFtZScgPT4gTUFJTF9VU0VSTkFNRSwgJ3NtdHBfcGFzc3dvcmQnID0+IE1BSUxfUEFTU1dPUkQs
CisgICAgICAgICAgICAgICAgJ3NtdHBfZW5jcnlwdGlvbicgPT4gTUFJTF9FTkNSWVBUSU9OLCAn
Y29ycmVvJyA9PiBNQUlMX0ZST01fQUREUkVTUywgJ25vbWJyZScgPT4gTUFJTF9GUk9NX05BTUVd
OworICAgICAgICB9CisgICAgICAgIGlmICgoJGN1ZW50YVsndHJhbnNwb3J0J10gPz8gJ3NtdHAn
KSAhPT0gJ3NtdHAnKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1JFU0VUX1RS
QU5TUE9SVCcpOyB9CisgICAgICAgICRjdWVudGFbJ2NvcnJlbyddID0gdHJpbSgoc3RyaW5nKSgk
Y3VlbnRhWydjb3JyZW8nXSA/PyAkY3VlbnRhWydzbXRwX3VzZXJuYW1lJ10gPz8gJycpKTsKKyAg
ICAgICAgaWYgKCFmaWx0ZXJfdmFyKCRjdWVudGFbJ2NvcnJlbyddLCBGSUxURVJfVkFMSURBVEVf
RU1BSUwpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1JFU0VUX0lERU5USVRZ
Jyk7IH0KKyAgICAgICAgcmV0dXJuICRjdWVudGE7CisgICAgfQogICAgIHB1YmxpYyBmdW5jdGlv
biBlbnZpYXJQYXNzd29yZFRlbXBvcmFsKAogICAgICAgICAkY29ycmVvRGVzdGlubywKICAgICAg
ICAgJG5vbWJyZURlc3Rpbm8sCiAgICAgICAgICRwYXNzd29yZFRlbXBvcmFsCiAgICAgKSB7Cisg
ICAgICAgICR0aGlzLT5yZXN1bHRhZG8gPSBbJ29rJyA9PiBmYWxzZSwgJ2VzdGFkb19lbnRyZWdh
JyA9PiAnUkVDSEFaQURPJ107CiAgICAgICAgICRtYWlsID0gJHRoaXMtPm1haWxlckZhY3Rvcnkg
PyAoJHRoaXMtPm1haWxlckZhY3RvcnkpKCkgOiBuZXcgUEhQTWFpbGVyKHRydWUpOwogCiAgICAg
ICAgIHRyeSB7CiAgICAgICAgICAgICAvLyBDb25maWd1cmFjacOzbiBkZWRpY2FkYTsgbnVuY2Eg
dG9tYSBlbCBjYW1wbyBEZSBkZSBsb3MgY29ycmVvcyBjb21lcmNpYWxlcy4KLSAgICAgICAgICAg
ICRkZWRpY2FkYSA9IGltcGVMZWVyQ29uZmlnUHJpdmFkYSgncGFzc3dvcmRfcmVzZXRfbWFpbC5s
b2NhbC5waHAnKTsKLSAgICAgICAgICAgICRjdWVudGEgPSAkZGVkaWNhZGEgPzogWydzbXRwX2hv
c3QnID0+IE1BSUxfSE9TVCwgJ3NtdHBfcG9ydCcgPT4gTUFJTF9QT1JULAotICAgICAgICAgICAg
ICAgICdzbXRwX3VzZXJuYW1lJyA9PiBNQUlMX1VTRVJOQU1FLCAnc210cF9wYXNzd29yZCcgPT4g
TUFJTF9QQVNTV09SRCwKLSAgICAgICAgICAgICAgICAnc210cF9lbmNyeXB0aW9uJyA9PiBNQUlM
X0VOQ1JZUFRJT04sICdjb3JyZW8nID0+IE1BSUxfRlJPTV9BRERSRVNTLCAnbm9tYnJlJyA9PiBN
QUlMX0ZST01fTkFNRV07CisgICAgICAgICAgICAkY3VlbnRhID0gc2VsZjo6Y3VlbnRhUmVjdXBl
cmFjaW9uKCk7CiAgICAgICAgICAgICBDb3JyZW9TbXRwVHJhbnNwb3J0Ojpjb25maWd1cmFyKCRt
YWlsLCAkY3VlbnRhKTsKIAogICAgICAgICAgICAgJG1haWwtPnNldEZyb20oCkBAIC05OCw5ICsx
MTYsMTIgQEAKICAgICAgICAgICAgICAgICAiSW5ncmVzYXIgYWwgc2lzdGVtYTogeyR1cmxMb2dp
bn1cblxuIiAuCiAgICAgICAgICAgICAgICAgIlNpIG5vIHJlYWxpemFzdGUgZXN0YSBzb2xpY2l0
dWQsIGNvbXVuw61jYXRlIGNvbiBlbCBhZG1pbmlzdHJhZG9yIGRlbCBzaXN0ZW1hLiI7CiAKLSAg
ICAgICAgICAgIHJldHVybiAhZW1wdHkoQ29ycmVvU210cFRyYW5zcG9ydDo6ZW52aWFyKCRtYWls
LCAncGFzc3dvcmRfcmVzZXQnKVsnb2snXSk7CisgICAgICAgICAgICAkdGhpcy0+cmVzdWx0YWRv
ID0gQ29ycmVvU210cFRyYW5zcG9ydDo6ZW52aWFyKCRtYWlsLCAncGFzc3dvcmRfcmVzZXQnKTsK
KyAgICAgICAgICAgIHJldHVybiAhZW1wdHkoJHRoaXMtPnJlc3VsdGFkb1snb2snXSk7CiAgICAg
ICAgIH0gY2F0Y2ggKFRocm93YWJsZSAkZSkgewotICAgICAgICAgICAgZXJyb3JfbG9nKCdNQUlM
X1RSQU5TUE9SVCAnIC4ganNvbl9lbmNvZGUoWydwdXJwb3NlJyA9PiAncGFzc3dvcmRfcmVzZXQn
LCAncGhhc2UnID0+ICdjb25maWd1cmF0aW9uJywgJ2V4Y2VwdGlvbicgPT4gZ2V0X2NsYXNzKCRl
KV0pKTsKKyAgICAgICAgICAgICRhbGxvd2VkID0gWydNQUlMX1JFU0VUX0NPTkZJRycsICdNQUlM
X1JFU0VUX1RSQU5TUE9SVCcsICdNQUlMX1JFU0VUX0lERU5USVRZJywgJ01BSUxfU01UUF9DT05G
SUcnLCAnTUFJTF9TTVRQX0NBJ107CisgICAgICAgICAgICAkcmVhc29uID0gaW5fYXJyYXkoJGUt
PmdldE1lc3NhZ2UoKSwgJGFsbG93ZWQsIHRydWUpID8gJGUtPmdldE1lc3NhZ2UoKSA6ICdNQUlM
X1JFU0VUX1BSRVBBUkFUSU9OJzsKKyAgICAgICAgICAgIGVycm9yX2xvZygnTUFJTF9UUkFOU1BP
UlQgJyAuIGpzb25fZW5jb2RlKFsncHVycG9zZScgPT4gJ3Bhc3N3b3JkX3Jlc2V0JywgJ3BoYXNl
JyA9PiAnY29uZmlndXJhdGlvbicsICdyZWFzb24nID0+ICRyZWFzb24sICdleGNlcHRpb24nID0+
IGdldF9jbGFzcygkZSldKSk7CiAgICAgICAgICAgICByZXR1cm4gZmFsc2U7CiAgICAgICAgIH0K
ICAgICB9Cg==
CENTRAL_PATCH_1_1
 [[ "$(digest "$TMP/patches/1/1.patch")" == 'ab615166a9d416b193116cf9f79ce9b903193e6a3bf06e567ee7ed97257b07b8' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/1/2.patch" <<'CENTRAL_PATCH_1_2'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9NYWlsU2VydmljZS5waHAgYi9hcHAvc2VydmljZXMv
TWFpbFNlcnZpY2UucGhwCi0tLSBhL2FwcC9zZXJ2aWNlcy9NYWlsU2VydmljZS5waHAKKysrIGIv
YXBwL3NlcnZpY2VzL01haWxTZXJ2aWNlLnBocApAQCAtMTAsNyArMTAsOSBAQAogY2xhc3MgTWFp
bFNlcnZpY2UKIHsKICAgICBwcml2YXRlICRtYWlsZXJGYWN0b3J5OworICAgIHByaXZhdGUgJHJl
c3VsdGFkbyA9IFtdOwogICAgIHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0cnVjdCg/Y2FsbGFibGUg
JG1haWxlckZhY3RvcnkgPSBudWxsKSB7ICR0aGlzLT5tYWlsZXJGYWN0b3J5ID0gJG1haWxlckZh
Y3Rvcnk7IH0KKyAgICBwdWJsaWMgZnVuY3Rpb24gdWx0aW1vUmVzdWx0YWRvKCk6IGFycmF5IHsg
cmV0dXJuICR0aGlzLT5yZXN1bHRhZG87IH0KIAogICAgIC8qKiBNaXNtYSBjdWVudGEgcGFyYSBl
bCBlbnbDrW8gd2ViIHkgbGEgY29tcHJvYmFjacOzbiBDTEk7IHNpbiBjdWVudGFzIGNvbWVyY2lh
bGVzLiAqLwogICAgIHB1YmxpYyBzdGF0aWMgZnVuY3Rpb24gY3VlbnRhUmVjdXBlcmFjaW9uKCk6
IGFycmF5CkBAIC0zNCw2ICszNiw3IEBACiAgICAgICAgICRub21icmVEZXN0aW5vLAogICAgICAg
ICAkcGFzc3dvcmRUZW1wb3JhbAogICAgICkgeworICAgICAgICAkdGhpcy0+cmVzdWx0YWRvID0g
WydvaycgPT4gZmFsc2UsICdlc3RhZG9fZW50cmVnYScgPT4gJ1JFQ0hBWkFETyddOwogICAgICAg
ICAkbWFpbCA9ICR0aGlzLT5tYWlsZXJGYWN0b3J5ID8gKCR0aGlzLT5tYWlsZXJGYWN0b3J5KSgp
IDogbmV3IFBIUE1haWxlcih0cnVlKTsKIAogICAgICAgICB0cnkgewpAQCAtMTEzLDcgKzExNiw4
IEBACiAgICAgICAgICAgICAgICAgIkluZ3Jlc2FyIGFsIHNpc3RlbWE6IHskdXJsTG9naW59XG5c
biIgLgogICAgICAgICAgICAgICAgICJTaSBubyByZWFsaXphc3RlIGVzdGEgc29saWNpdHVkLCBj
b211bsOtY2F0ZSBjb24gZWwgYWRtaW5pc3RyYWRvciBkZWwgc2lzdGVtYS4iOwogCi0gICAgICAg
ICAgICByZXR1cm4gIWVtcHR5KENvcnJlb1NtdHBUcmFuc3BvcnQ6OmVudmlhcigkbWFpbCwgJ3Bh
c3N3b3JkX3Jlc2V0JylbJ29rJ10pOworICAgICAgICAgICAgJHRoaXMtPnJlc3VsdGFkbyA9IENv
cnJlb1NtdHBUcmFuc3BvcnQ6OmVudmlhcigkbWFpbCwgJ3Bhc3N3b3JkX3Jlc2V0Jyk7CisgICAg
ICAgICAgICByZXR1cm4gIWVtcHR5KCR0aGlzLT5yZXN1bHRhZG9bJ29rJ10pOwogICAgICAgICB9
IGNhdGNoIChUaHJvd2FibGUgJGUpIHsKICAgICAgICAgICAgICRhbGxvd2VkID0gWydNQUlMX1JF
U0VUX0NPTkZJRycsICdNQUlMX1JFU0VUX1RSQU5TUE9SVCcsICdNQUlMX1JFU0VUX0lERU5USVRZ
JywgJ01BSUxfU01UUF9DT05GSUcnLCAnTUFJTF9TTVRQX0NBJ107CiAgICAgICAgICAgICAkcmVh
c29uID0gaW5fYXJyYXkoJGUtPmdldE1lc3NhZ2UoKSwgJGFsbG93ZWQsIHRydWUpID8gJGUtPmdl
dE1lc3NhZ2UoKSA6ICdNQUlMX1JFU0VUX1BSRVBBUkFUSU9OJzsK
CENTRAL_PATCH_1_2
 [[ "$(digest "$TMP/patches/1/2.patch")" == 'e51b853daff2d987b44feeb637dbf04a6cb02e5701c6fa9304b4b2a10b692f59' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/1/3.patch" <<'CENTRAL_PATCH_1_3'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9NYWlsU2VydmljZS5waHAgYi9hcHAvc2VydmljZXMv
TWFpbFNlcnZpY2UucGhwCi0tLSBhL2FwcC9zZXJ2aWNlcy9NYWlsU2VydmljZS5waHAKKysrIGIv
YXBwL3NlcnZpY2VzL01haWxTZXJ2aWNlLnBocApAQCAtMSwzOSArMSw1MiBAQAogPD9waHAKIAot
cmVxdWlyZV9vbmNlIFJPT1RfUEFUSCAuICcvdmVuZG9yL2F1dG9sb2FkLnBocCc7Ci1yZXF1aXJl
X29uY2UgUk9PVF9QQVRIIC4gJy9jb25maWcvbWFpbF9jb25maWcucGhwJzsKK3JlcXVpcmVfb25j
ZSBkaXJuYW1lKF9fRElSX18sIDIpIC4gJy92ZW5kb3IvYXV0b2xvYWQucGhwJzsKK3JlcXVpcmVf
b25jZSBkaXJuYW1lKF9fRElSX18sIDIpIC4gJy9jb25maWcvbWFpbF9jb25maWcucGhwJzsKK3Jl
cXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9TbXRwVHJhbnNwb3J0LnBocCc7CiAKIHVzZSBQ
SFBNYWlsZXJcUEhQTWFpbGVyXFBIUE1haWxlcjsKIHVzZSBQSFBNYWlsZXJcUEhQTWFpbGVyXEV4
Y2VwdGlvbjsKIAogY2xhc3MgTWFpbFNlcnZpY2UKIHsKKyAgICBwcml2YXRlICRtYWlsZXJGYWN0
b3J5OworICAgIHByaXZhdGUgJHJlc3VsdGFkbyA9IFtdOworICAgIHB1YmxpYyBmdW5jdGlvbiBf
X2NvbnN0cnVjdCg/Y2FsbGFibGUgJG1haWxlckZhY3RvcnkgPSBudWxsKSB7ICR0aGlzLT5tYWls
ZXJGYWN0b3J5ID0gJG1haWxlckZhY3Rvcnk7IH0KKyAgICBwdWJsaWMgZnVuY3Rpb24gdWx0aW1v
UmVzdWx0YWRvKCk6IGFycmF5IHsgcmV0dXJuICR0aGlzLT5yZXN1bHRhZG87IH0KKworICAgIC8q
KiBNaXNtYSBjdWVudGEgcGFyYSBlbCBlbnbDrW8gd2ViIHkgbGEgY29tcHJvYmFjacOzbiBDTEk7
IHNpbiBjdWVudGFzIGNvbWVyY2lhbGVzLiAqLworICAgIHB1YmxpYyBzdGF0aWMgZnVuY3Rpb24g
Y3VlbnRhUmVjdXBlcmFjaW9uKCk6IGFycmF5CisgICAgeworICAgICAgICAkcnV0YSA9IGltcGVS
dXRhQ29uZmlnUHJpdmFkYSgncGFzc3dvcmRfcmVzZXRfbWFpbC5sb2NhbC5waHAnKTsKKyAgICAg
ICAgaWYgKCRydXRhICE9PSBudWxsKSB7CisgICAgICAgICAgICAkY3VlbnRhID0gaW1wZUxlZXJD
b25maWdQcml2YWRhKCdwYXNzd29yZF9yZXNldF9tYWlsLmxvY2FsLnBocCcpOworICAgICAgICAg
ICAgaWYgKCEkY3VlbnRhKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1JFU0VU
X0NPTkZJRycpOyB9CisgICAgICAgIH0gZWxzZSB7CisgICAgICAgICAgICAkY3VlbnRhID0gWydz
bXRwX2hvc3QnID0+IE1BSUxfSE9TVCwgJ3NtdHBfcG9ydCcgPT4gTUFJTF9QT1JULAorICAgICAg
ICAgICAgICAgICdzbXRwX3VzZXJuYW1lJyA9PiBNQUlMX1VTRVJOQU1FLCAnc210cF9wYXNzd29y
ZCcgPT4gTUFJTF9QQVNTV09SRCwKKyAgICAgICAgICAgICAgICAnc210cF9lbmNyeXB0aW9uJyA9
PiBNQUlMX0VOQ1JZUFRJT04sICdjb3JyZW8nID0+IE1BSUxfRlJPTV9BRERSRVNTLCAnbm9tYnJl
JyA9PiBNQUlMX0ZST01fTkFNRV07CisgICAgICAgIH0KKyAgICAgICAgaWYgKCgkY3VlbnRhWyd0
cmFuc3BvcnQnXSA/PyAnc210cCcpICE9PSAnc210cCcpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNl
cHRpb24oJ01BSUxfUkVTRVRfVFJBTlNQT1JUJyk7IH0KKyAgICAgICAgJGN1ZW50YVsnY29ycmVv
J10gPSB0cmltKChzdHJpbmcpKCRjdWVudGFbJ2NvcnJlbyddID8/ICRjdWVudGFbJ3NtdHBfdXNl
cm5hbWUnXSA/PyAnJykpOworICAgICAgICBpZiAoIWZpbHRlcl92YXIoJGN1ZW50YVsnY29ycmVv
J10sIEZJTFRFUl9WQUxJREFURV9FTUFJTCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24o
J01BSUxfUkVTRVRfSURFTlRJVFknKTsgfQorICAgICAgICByZXR1cm4gJGN1ZW50YTsKKyAgICB9
CiAgICAgcHVibGljIGZ1bmN0aW9uIGVudmlhclBhc3N3b3JkVGVtcG9yYWwoCiAgICAgICAgICRj
b3JyZW9EZXN0aW5vLAogICAgICAgICAkbm9tYnJlRGVzdGlubywKICAgICAgICAgJHBhc3N3b3Jk
VGVtcG9yYWwKICAgICApIHsKLSAgICAgICAgJG1haWwgPSBuZXcgUEhQTWFpbGVyKHRydWUpOwor
ICAgICAgICAkdGhpcy0+cmVzdWx0YWRvID0gWydvaycgPT4gZmFsc2UsICdlc3RhZG9fZW50cmVn
YScgPT4gJ1JFQ0hBWkFETyddOworICAgICAgICAkbWFpbCA9ICR0aGlzLT5tYWlsZXJGYWN0b3J5
ID8gKCR0aGlzLT5tYWlsZXJGYWN0b3J5KSgpIDogbmV3IFBIUE1haWxlcih0cnVlKTsKIAogICAg
ICAgICB0cnkgewotICAgICAgICAgICAgJG1haWwtPmlzU01UUCgpOwotICAgICAgICAgICAgJG1h
aWwtPkhvc3QgPSBNQUlMX0hPU1Q7Ci0gICAgICAgICAgICAkbWFpbC0+U01UUEF1dGggPSB0cnVl
OwotICAgICAgICAgICAgJG1haWwtPlVzZXJuYW1lID0gTUFJTF9VU0VSTkFNRTsKLSAgICAgICAg
ICAgICRtYWlsLT5QYXNzd29yZCA9IE1BSUxfUEFTU1dPUkQ7Ci0KLSAgICAgICAgICAgIGlmIChN
QUlMX0VOQ1JZUFRJT04gPT09ICdzc2wnKSB7Ci0gICAgICAgICAgICAgICAgJG1haWwtPlNNVFBT
ZWN1cmUgPSBQSFBNYWlsZXI6OkVOQ1JZUFRJT05fU01UUFM7Ci0gICAgICAgICAgICB9IGVsc2Ug
ewotICAgICAgICAgICAgICAgICRtYWlsLT5TTVRQU2VjdXJlID0gUEhQTWFpbGVyOjpFTkNSWVBU
SU9OX1NUQVJUVExTOwotICAgICAgICAgICAgfQotCi0gICAgICAgICAgICAkbWFpbC0+UG9ydCA9
IE1BSUxfUE9SVDsKLSAgICAgICAgICAgICRtYWlsLT5DaGFyU2V0ID0gJ1VURi04JzsKKyAgICAg
ICAgICAgIC8vIENvbmZpZ3VyYWNpw7NuIGRlZGljYWRhOyBudW5jYSB0b21hIGVsIGNhbXBvIERl
IGRlIGxvcyBjb3JyZW9zIGNvbWVyY2lhbGVzLgorICAgICAgICAgICAgJGN1ZW50YSA9IHNlbGY6
OmN1ZW50YVJlY3VwZXJhY2lvbigpOworICAgICAgICAgICAgQ29ycmVvU210cFRyYW5zcG9ydDo6
Y29uZmlndXJhcigkbWFpbCwgJGN1ZW50YSk7CiAKICAgICAgICAgICAgICRtYWlsLT5zZXRGcm9t
KAotICAgICAgICAgICAgICAgIE1BSUxfRlJPTV9BRERSRVNTLAotICAgICAgICAgICAgICAgIE1B
SUxfRlJPTV9OQU1FCisgICAgICAgICAgICAgICAgKHN0cmluZykoJGN1ZW50YVsnY29ycmVvJ10g
Pz8gJGN1ZW50YVsnc210cF91c2VybmFtZSddKSwKKyAgICAgICAgICAgICAgICAoc3RyaW5nKSgk
Y3VlbnRhWydub21icmUnXSA/PyBNQUlMX0ZST01fTkFNRSkKICAgICAgICAgICAgICk7CiAgICAg
ICAgICAgICAkbWFpbC0+YWRkQWRkcmVzcygKICAgICAgICAgICAgICAgICAkY29ycmVvRGVzdGlu
bywKQEAgLTgxLDcgKzk0LDcgQEAKICAgICAgICAgICAgICAgICB9IGNhdGNoIChUaHJvd2FibGUg
JGxvZ29FcnJvcikgewogICAgICAgICAgICAgICAgICAgICBlcnJvcl9sb2coCiAgICAgICAgICAg
ICAgICAgICAgICAgICAnTm8gZnVlIHBvc2libGUgaW5jcnVzdGFyIGVsIGxvZ28gZW4gZWwgY29y
cmVvIGRlIHJlY3VwZXJhY2nDs246ICcgLgotICAgICAgICAgICAgICAgICAgICAgICAgJGxvZ29F
cnJvci0+Z2V0TWVzc2FnZSgpCisgICAgICAgICAgICAgICAgICAgICAgICBnZXRfY2xhc3MoJGxv
Z29FcnJvcikKICAgICAgICAgICAgICAgICAgICAgKTsKICAgICAgICAgICAgICAgICB9CiAgICAg
ICAgICAgICB9CkBAIC0xMDMsMTUgKzExNiwxMiBAQAogICAgICAgICAgICAgICAgICJJbmdyZXNh
ciBhbCBzaXN0ZW1hOiB7JHVybExvZ2lufVxuXG4iIC4KICAgICAgICAgICAgICAgICAiU2kgbm8g
cmVhbGl6YXN0ZSBlc3RhIHNvbGljaXR1ZCwgY29tdW7DrWNhdGUgY29uIGVsIGFkbWluaXN0cmFk
b3IgZGVsIHNpc3RlbWEuIjsKIAotICAgICAgICAgICAgJG1haWwtPnNlbmQoKTsKLQotICAgICAg
ICAgICAgcmV0dXJuIHRydWU7Ci0gICAgICAgIH0gY2F0Y2ggKEV4Y2VwdGlvbiAkZSkgewotICAg
ICAgICAgICAgZXJyb3JfbG9nKAotICAgICAgICAgICAgICAgICdFcnJvciBhbCBlbnZpYXIgY29y
cmVvIGRlIHJlY3VwZXJhY2nDs246ICcgLgotICAgICAgICAgICAgICAgICRtYWlsLT5FcnJvcklu
Zm8KLSAgICAgICAgICAgICk7Ci0KKyAgICAgICAgICAgICR0aGlzLT5yZXN1bHRhZG8gPSBDb3Jy
ZW9TbXRwVHJhbnNwb3J0OjplbnZpYXIoJG1haWwsICdwYXNzd29yZF9yZXNldCcpOworICAgICAg
ICAgICAgcmV0dXJuICFlbXB0eSgkdGhpcy0+cmVzdWx0YWRvWydvayddKTsKKyAgICAgICAgfSBj
YXRjaCAoVGhyb3dhYmxlICRlKSB7CisgICAgICAgICAgICAkYWxsb3dlZCA9IFsnTUFJTF9SRVNF
VF9DT05GSUcnLCAnTUFJTF9SRVNFVF9UUkFOU1BPUlQnLCAnTUFJTF9SRVNFVF9JREVOVElUWScs
ICdNQUlMX1NNVFBfQ09ORklHJywgJ01BSUxfU01UUF9DQSddOworICAgICAgICAgICAgJHJlYXNv
biA9IGluX2FycmF5KCRlLT5nZXRNZXNzYWdlKCksICRhbGxvd2VkLCB0cnVlKSA/ICRlLT5nZXRN
ZXNzYWdlKCkgOiAnTUFJTF9SRVNFVF9QUkVQQVJBVElPTic7CisgICAgICAgICAgICBlcnJvcl9s
b2coJ01BSUxfVFJBTlNQT1JUICcgLiBqc29uX2VuY29kZShbJ3B1cnBvc2UnID0+ICdwYXNzd29y
ZF9yZXNldCcsICdwaGFzZScgPT4gJ2NvbmZpZ3VyYXRpb24nLCAncmVhc29uJyA9PiAkcmVhc29u
LCAnZXhjZXB0aW9uJyA9PiBnZXRfY2xhc3MoJGUpXSkpOwogICAgICAgICAgICAgcmV0dXJuIGZh
bHNlOwogICAgICAgICB9CiAgICAgfQo=
CENTRAL_PATCH_1_3
 [[ "$(digest "$TMP/patches/1/3.patch")" == '13edbf408ee81b2b4f515dc6dcf54428957565c316bff7d38c90ebfdf116fa8b' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/2"
 base64 --decode > "$TMP/patches/2/1.patch" <<'CENTRAL_PATCH_2_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2Vydmlj
ZS5waHAgYi9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhw
Ci0tLSBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5waHAK
KysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocApA
QCAtNCw2ICs0LDcgQEAKIHJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9DdWVudGFDb25l
Y3RhZGFTZXJ2aWNlLnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvU210cFRy
YW5zcG9ydC5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0VudmlvQ29udHJv
bC5waHAnOworcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0xpbWl0ZVNlcnZpY2UucGhw
JzsKIAogY2xhc3MgQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UKIHsKQEAgLTIzLDYg
KzI0LDcgQEAKICAgICAgICAgJHRoaXMtPm1haWxlckZhY3RvcnkgPSAkbWFpbGVyRmFjdG9yeTsK
ICAgICAgICAgJHRoaXMtPmN1ZW50YXMgPSAkY3VlbnRhcyA/PyBuZXcgQ29ycmVvQ3VlbnRhQ29u
ZWN0YWRhU2VydmljZSgpOwogICAgICAgICAkdGhpcy0+YXBpUmVxdWVzdCA9ICRhcGlSZXF1ZXN0
OworICAgICAgICAkdGhpcy0+Y3VlbnRhcy0+Y29uZmlndXJhckNvbnN1bHRhQXBpKGZ1bmN0aW9u
ICgkbWV0aG9kLCR1cmwsJHRva2VuLCRwYXlsb2FkKSB7IHJldHVybiAkdGhpcy0+c29saWNpdGFy
SG9zdGluZ2VyKCRtZXRob2QsJHVybCwkdG9rZW4sJHBheWxvYWQpOyB9KTsKICAgICAgICAgJHRo
aXMtPmZpcm1hU2VydmljZSA9IG5ldyBGaXJtYUNvcnJlb1NlcnZpY2UoKTsKICAgICB9CiAKQEAg
LTM1LDcgKzM3LDcgQEAKICAgICAgICAgLyoKICAgICAgICAgICogQ29tcGF0aWJpbGlkYWQgY29u
IG3Ds2R1bG9zIGV4aXN0ZW50ZXM6IHNpIGVsIGxsYW1hZG9yIHRvZGF2w61hIG5vCiAgICAgICAg
ICAqIG1hbmRhIHVzdWFyaW9faWQsIHJldXRpbGl6YW1vcyBlbCB1c3VhcmlvIGF1dGVudGljYWRv
IGRlIGxhIHNlc2nDs24uCi0gICAgICAgICAqIEFzw60gdG9kb3MgbG9zIGVudsOtb3MgaW50ZXJh
Y3Rpdm9zIHVzYW4gbGEgY3VlbnRhIFNNVFAgcGVyc29uYWwuCisgICAgICAgICAqIFRvZG9zIGxv
cyBlbnbDrW9zIGludGVyYWN0aXZvcyBjb25zZXJ2YW4gbGEgcmVmZXJlbmNpYSBkZSBzdSB1c3Vh
cmlvLgogICAgICAgICAgKi8KICAgICAgICAgaWYgKCR1c3VhcmlvSWQgPD0gMCAmJiBpc3NldCgk
X1NFU1NJT05bJ3VzdWFyaW9faWQnXSkpIHsKICAgICAgICAgICAgICR1c3VhcmlvSWQgPSAoaW50
KSRfU0VTU0lPTlsndXN1YXJpb19pZCddOwpAQCAtODAsOCArODIsNiBAQAogICAgICAgICAgICAg
cmV0dXJuICR0aGlzLT5lcnJvcignRWwgYXN1bnRvIG8gZWwgbWVuc2FqZSBzdXBlcmEgZWwgdGFt
YcOxbyBwZXJtaXRpZG8uJywgNDIyKTsKICAgICAgICAgfQogCi0gICAgICAgICRydXRhRmlybWEg
PSAkdGhpcy0+ZmlybWFTZXJ2aWNlLT5vYnRlbmVyUnV0YSgkcmVtaXRlbnRlKTsKLQogICAgICAg
ICB0cnkgewogICAgICAgICAgICAgJGNvbm5lY3Rpb24gPSAkdGhpcy0+Y3VlbnRhcy0+cmVzb2x2
ZXIoJHJlbWl0ZW50ZSwgJHVzdWFyaW9JZCwgJHBlcmZpbE1hcmtldGluZyk7CiAgICAgICAgIH0g
Y2F0Y2ggKFRocm93YWJsZSAkZXJyb3IpIHsKQEAgLTkwLDIxICs5MCwyOSBAQAogICAgICAgICB9
CiAgICAgICAgIGlmIChlbXB0eSgkY29ubmVjdGlvblsnb2snXSkpIHsgcmV0dXJuICRjb25uZWN0
aW9uOyB9CiAgICAgICAgICRhY2NvdW50ID0gJGNvbm5lY3Rpb25bJ2FjY291bnQnXTsKLSAgICAg
ICAgJHNlbmQgPSBmdW5jdGlvbiAoKSB1c2UgKCRhY2NvdW50LCAkcmVtaXRlbnRlLCAkbm9tYnJl
UmVtaXRlbnRlLCAkZGVzdGluYXRhcmlvLCAkbm9tYnJlRGVzdGluYXRhcmlvLAorICAgICAgICAk
aWRlbnRpZGFkID0gJGNvbm5lY3Rpb25bJ2lkZW50aWRhZCddOworICAgICAgICAkcnV0YUZpcm1h
ID0gJHRoaXMtPmZpcm1hU2VydmljZS0+b2J0ZW5lclJ1dGEoJGlkZW50aWRhZFsnY29udGFjdG9f
ZmlybWEnXSk7CisgICAgICAgIGlmICghZW1wdHkoJGRhdG9zWydjb25maXJtYXJfaWRlbnRpZGFk
J10pICYmICEkdGhpcy0+Y29uZmlybWFjaW9uVmFsaWRhKCRpZGVudGlkYWQsIChzdHJpbmcpKCRk
YXRvc1snaWRlbnRpZGFkX2VudmlvJ10gPz8gJycpKSkgeworICAgICAgICAgICAgcmV0dXJuICR0
aGlzLT5lcnJvcignRWwgcmVtaXRlbnRlIGluc3RpdHVjaW9uYWwgY2FtYmnDsyBvIHZlbmNpw7Mg
c3UgY29tcHJvYmFjacOzbi4gUmV2aXNhIGVsIHJlbWl0ZW50ZSB2aXNpYmxlIHkgdnVlbHZlIGEg
ZW52aWFyLicsIDQyMik7CisgICAgICAgIH0KKyAgICAgICAgJHNlbmQgPSBmdW5jdGlvbiAoKSB1
c2UgKCR1c3VhcmlvSWQsICRpZGVudGlkYWQsICRhY2NvdW50LCAkcmVtaXRlbnRlLCAkbm9tYnJl
UmVtaXRlbnRlLCAkZGVzdGluYXRhcmlvLCAkbm9tYnJlRGVzdGluYXRhcmlvLAogICAgICAgICAg
ICAgJGFzdW50bywgJGN1ZXJwbywgJHJ1dGFGaXJtYSwgJGltYWdlbmVzRW1iZWJpZGFzLCAkYWRq
dW50b3MsICRodG1sQWRpY2lvbmFsKSB7CisgICAgICAgICAgICBpZiAoIUNvcnJlb0xpbWl0ZVNl
cnZpY2U6OnRvbWFyKCdjb21tZXJjaWFsLXNlbmQnLCAkdXN1YXJpb0lkLCA2MCwgMzYwMCkpIHsg
cmV0dXJuICR0aGlzLT5lcnJvcignU2UgYWxjYW56w7MgZWwgbMOtbWl0ZSBkZSBlbnbDrW9zLiBF
c3BlcmEgYW50ZXMgZGUgdm9sdmVyIGEgZW52aWFyLicsIDQyOSk7IH0KICAgICAgICAgICAgIGlm
ICgkYWNjb3VudFsndHJhbnNwb3J0J10gPT09ICdob3N0aW5nZXJfYXBpJykgewogICAgICAgICAg
ICAgICAgICRiYXNlID0gcnRyaW0oKHN0cmluZykoJGFjY291bnRbJ2FwaV9iYXNlX3VybCddID8/
IHNlbGY6OkhPU1RJTkdFUl9CQVNFX0RFRkFVTFQpLCAnLycpOwogICAgICAgICAgICAgICAgIGlm
ICgkYmFzZSAhPT0gc2VsZjo6SE9TVElOR0VSX0JBU0VfREVGQVVMVCB8fCBlbXB0eSgkYWNjb3Vu
dFsnYXBpX3Rva2VuJ10pKSB7CiAgICAgICAgICAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJy
b3IoJ0ZhbHRhIGxhIGNvbmZpZ3VyYWNpw7NuIHByaXZhZGEgZGUgbGEgQVBJIEhvc3RpbmdlciBw
YXJhIGVzdGEgY3VlbnRhLicsIDQyMik7CiAgICAgICAgICAgICAgICAgfQotICAgICAgICAgICAg
ICAgIHJldHVybiAkdGhpcy0+ZW52aWFySG9zdGluZ2VyKCRyZW1pdGVudGUsICRub21icmVSZW1p
dGVudGUsICRkZXN0aW5hdGFyaW8sICRhc3VudG8sICRjdWVycG8sICRydXRhRmlybWEsCi0gICAg
ICAgICAgICAgICAgICAgIFsndG9rZW4nID0+ICRhY2NvdW50WydhcGlfdG9rZW4nXSwgJ2Jhc2Vf
dXJsJyA9PiAkYmFzZV0sICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9zLCAkaHRtbEFkaWNp
b25hbCwgdHJ1ZSwgJHJlbWl0ZW50ZSk7Ci0gICAgICAgICAgICB9Ci0gICAgICAgICAgICByZXR1
cm4gJHRoaXMtPmVudmlhclNtdHAoJHJlbWl0ZW50ZSwgJG5vbWJyZVJlbWl0ZW50ZSwgJGRlc3Rp
bmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJpbywgJGFzdW50bywgJGN1ZXJwbywKLSAgICAgICAg
ICAgICAgICAkcnV0YUZpcm1hLCAkaW1hZ2VuZXNFbWJlYmlkYXMsICRhZGp1bnRvcywgJGh0bWxB
ZGljaW9uYWwsICRhY2NvdW50KTsKKyAgICAgICAgICAgICAgICAkcmVzdWx0ID0gJHRoaXMtPmVu
dmlhckhvc3RpbmdlcigkaWRlbnRpZGFkWydyZW1pdGVudGVfdmlzaWJsZSddLCAkaWRlbnRpZGFk
Wydub21icmVfdmlzaWJsZSddLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRvLCAkY3VlcnBvLCAkcnV0
YUZpcm1hLAorICAgICAgICAgICAgICAgICAgICBbJ3Rva2VuJyA9PiAkYWNjb3VudFsnYXBpX3Rv
a2VuJ10sICdiYXNlX3VybCcgPT4gJGJhc2VdLCAkaW1hZ2VuZXNFbWJlYmlkYXMsICRhZGp1bnRv
cywgJGh0bWxBZGljaW9uYWwsIHRydWUsICRpZGVudGlkYWRbJ3JlbWl0ZW50ZV92aXNpYmxlJ10p
OworICAgICAgICAgICAgfSBlbHNlIHsKKyAgICAgICAgICAgICAgICAkcmVzdWx0ID0gJHRoaXMt
PmVudmlhclNtdHAoJGlkZW50aWRhZFsncmVtaXRlbnRlX3Zpc2libGUnXSwgJGlkZW50aWRhZFsn
bm9tYnJlX3Zpc2libGUnXSwgJGRlc3RpbmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJpbywgJGFz
dW50bywgJGN1ZXJwbywKKyAgICAgICAgICAgICAgICAgICAgJHJ1dGFGaXJtYSwgJGltYWdlbmVz
RW1iZWJpZGFzLCAkYWRqdW50b3MsICRodG1sQWRpY2lvbmFsLCBhcnJheV9yZXBsYWNlKCRhY2Nv
dW50LCBbJ3JlcGx5X3RvJyA9PiAkaWRlbnRpZGFkWydyZXBseV90byddXSkpOworICAgICAgICAg
ICAgfQorICAgICAgICAgICAgcmV0dXJuICRyZXN1bHQgKyAkaWRlbnRpZGFkOwogICAgICAgICB9
OwogICAgICAgICAkYXR0YWNobWVudHMgPSBhcnJheV9tYXAoc3RhdGljIGZ1bmN0aW9uICgkYSkg
eyByZXR1cm4gWyRhWydub21icmUnXSwgaGFzaF9maWxlKCdzaGEyNTYnLCAkYVsncnV0YSddKV07
IH0sIGFycmF5X21lcmdlKCRhZGp1bnRvcywgJGltYWdlbmVzRW1iZWJpZGFzKSk7Ci0gICAgICAg
ICRmaW5nZXJwcmludCA9IGhhc2goJ3NoYTI1NicsIGpzb25fZW5jb2RlKFskdXN1YXJpb0lkLCAk
cmVtaXRlbnRlLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRvLCAkY3VlcnBvLCAkaHRtbEFkaWNpb25h
bCwgJGF0dGFjaG1lbnRzLAorICAgICAgICAkZmluZ2VycHJpbnQgPSBoYXNoKCdzaGEyNTYnLCBq
c29uX2VuY29kZShbJHVzdWFyaW9JZCwgJGlkZW50aWRhZCwgJGRlc3RpbmF0YXJpbywgJGFzdW50
bywgJGN1ZXJwbywgJGh0bWxBZGljaW9uYWwsICRhdHRhY2htZW50cywKICAgICAgICAgICAgIGlz
X2ZpbGUoJHJ1dGFGaXJtYSkgPyBoYXNoX2ZpbGUoJ3NoYTI1NicsICRydXRhRmlybWEpIDogJydd
KSk7CiAgICAgICAgICRleHBsaWNpdCA9IHRyaW0oKHN0cmluZykoJGRhdG9zWydpZGVtcG90ZW5j
eV9rZXknXSA/PyAnJykpOwogICAgICAgICBpZiAoJGV4cGxpY2l0ICE9PSAnJyAmJiAhcHJlZ19t
YXRjaCgnL15bYS16QS1aMC05Ol8uLV17OCwxODB9JC9EJywgJGV4cGxpY2l0KSkgeyByZXR1cm4g
JHRoaXMtPmVycm9yKCdMYSBzb2xpY2l0dWQgZGUgZW52w61vIG5vIGVzIHbDoWxpZGEuJywgNDIy
KTsgfQpAQCAtMTE1LDYgKzEyMywzNyBAQAogICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lcnJv
cignTm8gc2UgcHVkbyBjb25maXJtYXIgdW4gZW52w61vIHNlZ3Vyby4gUmV2aXNhIGVsIHByb3Zl
ZWRvciB5IGVsIGhpc3RvcmlhbCBhbnRlcyBkZSByZXBldGlybG8uJywgNTAzKQogICAgICAgICAg
ICAgICAgICsgWydlc3RhZG9fZW50cmVnYScgPT4gJ0RFU0NPTk9DSURPJywgJ2VudHJlZ2FfY29u
ZmlybWFkYScgPT4gZmFsc2VdOwogICAgICAgICB9CisgICAgfQorCisgICAgcHVibGljIGZ1bmN0
aW9uIGlkZW50aWRhZChzdHJpbmcgJGNvbnRhY3RvLCBpbnQgJHVzZXIpOiBhcnJheQorICAgIHsK
KyAgICAgICAgdHJ5IHsKKyAgICAgICAgICAgIGlmICghZmlsdGVyX3ZhcigkY29udGFjdG8sIEZJ
TFRFUl9WQUxJREFURV9FTUFJTCkpIHsgcmV0dXJuICR0aGlzLT5lcnJvcignSW5ncmVzYSB1biBj
b3JyZW8gZGUgY29udGFjdG8gdsOhbGlkby4nLCA0MjIpOyB9CisgICAgICAgICAgICAkciA9ICR0
aGlzLT5jdWVudGFzLT5yZXNvbHZlcihzdHJ0b2xvd2VyKHRyaW0oJGNvbnRhY3RvKSksICR1c2Vy
KTsKKyAgICAgICAgICAgIGlmIChlbXB0eSgkclsnb2snXSkpIHsgcmV0dXJuICRyOyB9CisgICAg
ICAgICAgICAkaWRlbnRpdHkgPSAkclsnaWRlbnRpZGFkJ107ICRleHBpcmVzID0gdGltZSgpICsg
NjAwOworICAgICAgICAgICAgJF9TRVNTSU9OWydtYWlsX2lkZW50aXR5X2tleSddID0gJF9TRVNT
SU9OWydtYWlsX2lkZW50aXR5X2tleSddID8/IGJpbjJoZXgocmFuZG9tX2J5dGVzKDMyKSk7Cisg
ICAgICAgICAgICAkdG9rZW4gPSAkZXhwaXJlcyAuICcuJyAuIGhhc2hfaG1hYygnc2hhMjU2Jywg
anNvbl9lbmNvZGUoWyRpZGVudGl0eSwgJGV4cGlyZXNdKSwgJF9TRVNTSU9OWydtYWlsX2lkZW50
aXR5X2tleSddKTsKKyAgICAgICAgICAgIHJldHVybiBbJ29rJyA9PiB0cnVlLCAnaWRlbnRpZGFk
JyA9PiAkaWRlbnRpdHksICdpZGVudGlkYWRfZW52aW8nID0+ICR0b2tlbl07CisgICAgICAgIH0g
Y2F0Y2ggKFRocm93YWJsZSAkZSkgeworICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lcnJvcigk
ZS0+Z2V0TWVzc2FnZSgpID09PSAnTUFJTF9DRU5UUkFMX0ZST00nCisgICAgICAgICAgICAgICAg
PyAnVHUgY29ycmVvIG5vIGNvcnJlc3BvbmRlIGEgdW4gYnV6w7NuIEhvc3RpbmdlciBhdXRvcml6
YWRvLiBFbCBhZG1pbmlzdHJhZG9yIGRlYmUgaW5kaWNhciBsYSBjdWVudGEgaW5zdGl0dWNpb25h
bCBkZSBzYWxpZGEgcGFyYSBlc3RvcyBjb250YWN0b3MuJworICAgICAgICAgICAgICAgIDogJ05v
IHNlIHB1ZG8gY29tcHJvYmFyIGVsIGNvcnJlbyBpbnN0aXR1Y2lvbmFsIGRlbCBzaXN0ZW1hLiBT
b2xpY2l0YSByZXZpc2FyIHN1IGNvbmZpZ3VyYWNpw7NuIHByaXZhZGEgeSBhdXRvcml6YWNpw7Nu
LicsIDUwMyk7CisgICAgICAgIH0KKyAgICB9CisKKyAgICBwcml2YXRlIGZ1bmN0aW9uIGNvbmZp
cm1hY2lvblZhbGlkYShhcnJheSAkaWRlbnRpdHksIHN0cmluZyAkdG9rZW4pOiBib29sCisgICAg
eworICAgICAgICBpZiAoIXByZWdfbWF0Y2goJy9eKFswLTldezEwfSlcLihbYS1mMC05XXs2NH0p
JC9EJywgJHRva2VuLCAkbSkgfHwgKGludCkkbVsxXSA8IHRpbWUoKSB8fCAoaW50KSRtWzFdID4g
dGltZSgpICsgNjAwIHx8IGVtcHR5KCRfU0VTU0lPTlsnbWFpbF9pZGVudGl0eV9rZXknXSkpIHsg
cmV0dXJuIGZhbHNlOyB9CisgICAgICAgIHJldHVybiBoYXNoX2VxdWFscyhoYXNoX2htYWMoJ3No
YTI1NicsIGpzb25fZW5jb2RlKFskaWRlbnRpdHksIChpbnQpJG1bMV1dKSwgJF9TRVNTSU9OWydt
YWlsX2lkZW50aXR5X2tleSddKSwgJG1bMl0pOworICAgIH0KKworICAgIHB1YmxpYyBmdW5jdGlv
biBjb21wcm9iYXJJZGVudGlkYWQoc3RyaW5nICRjb250YWN0bywgaW50ICR1c2VyLCBzdHJpbmcg
JHRva2VuKTogYXJyYXkKKyAgICB7CisgICAgICAgICRyID0gJHRoaXMtPmlkZW50aWRhZCgkY29u
dGFjdG8sICR1c2VyKTsKKyAgICAgICAgaWYgKGVtcHR5KCRyWydvayddKSkgeyByZXR1cm4gJHI7
IH0KKyAgICAgICAgcmV0dXJuICR0aGlzLT5jb25maXJtYWNpb25WYWxpZGEoJHJbJ2lkZW50aWRh
ZCddLCAkdG9rZW4pID8gWydvaycgPT4gdHJ1ZSwgJ2lkZW50aWRhZCcgPT4gJHJbJ2lkZW50aWRh
ZCddXQorICAgICAgICAgICAgOiAkdGhpcy0+ZXJyb3IoJ1JldmlzYSBlbCByZW1pdGVudGUgaW5z
dGl0dWNpb25hbCB2aXNpYmxlIGFudGVzIGRlIGVudmlhci4nLCA0MjIpOwogICAgIH0KIAogICAg
IHByaXZhdGUgZnVuY3Rpb24gZW52aWFySG9zdGluZ2VyKApAQCAtMzU1LDggKzM5NCwxMCBAQAog
ICAgICAgICB0cnkgewogICAgICAgICAgICAgJGNvbmZpZ1VzdWFyaW8gPSBpc19hcnJheSgkY29u
ZmlnVXN1YXJpbykgPyAkY29uZmlnVXN1YXJpbyA6IFtdOwogICAgICAgICAgICAgJG1haWwgPSAk
dGhpcy0+bWFpbGVyRmFjdG9yeSA/ICgkdGhpcy0+bWFpbGVyRmFjdG9yeSkoKSA6IG5ldyBcUEhQ
TWFpbGVyXFBIUE1haWxlclxQSFBNYWlsZXIodHJ1ZSk7Ci0gICAgICAgICAgICBDb3JyZW9TbXRw
VHJhbnNwb3J0Ojpjb25maWd1cmFyKCRtYWlsLCAkY29uZmlnVXN1YXJpbyk7CisgICAgICAgICAg
ICBpZiAoKCRjb25maWdVc3VhcmlvWyd0cmFuc3BvcnQnXSA/PyAnJykgPT09ICdnbWFpbF9hcGkn
KSB7ICRtYWlsLT5DaGFyU2V0ID0gJ1VURi04JzsgfQorICAgICAgICAgICAgZWxzZSB7IENvcnJl
b1NtdHBUcmFuc3BvcnQ6OmNvbmZpZ3VyYXIoJG1haWwsICRjb25maWdVc3VhcmlvKTsgfQogICAg
ICAgICAgICAgJG1haWwtPnNldEZyb20oJHJlbWl0ZW50ZVVzdWFyaW8sICRub21icmVSZW1pdGVu
dGUgIT09ICcnID8gJG5vbWJyZVJlbWl0ZW50ZSA6ICRyZW1pdGVudGVVc3VhcmlvKTsKKyAgICAg
ICAgICAgIGlmICghZW1wdHkoJGNvbmZpZ1VzdWFyaW9bJ3JlcGx5X3RvJ10pKSB7ICRtYWlsLT5h
ZGRSZXBseVRvKCRjb25maWdVc3VhcmlvWydyZXBseV90byddKTsgfQogICAgICAgICAgICAgJG1h
aWwtPmFkZEFkZHJlc3MoJGRlc3RpbmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJpbyk7CiAgICAg
ICAgICAgICAkZmlybWFEaXNwb25pYmxlID0gJHJ1dGFGaXJtYSAhPT0gJycgJiYgaXNfZmlsZSgk
cnV0YUZpcm1hKTsKICAgICAgICAgICAgIGlmICgkZmlybWFEaXNwb25pYmxlKSB7CkBAIC0zOTYs
NyArNDM3LDkgQEAKICAgICAgICAgICAgICAgICAkaHRtbEFkaWNpb25hbAogICAgICAgICAgICAg
KTsKICAgICAgICAgICAgICRtYWlsLT5BbHRCb2R5ID0gJGN1ZXJwbzsKLSAgICAgICAgICAgICRv
dXRjb21lID0gQ29ycmVvU210cFRyYW5zcG9ydDo6ZW52aWFyKCRtYWlsLCAnY29tbWVyY2lhbCcp
OworICAgICAgICAgICAgJG91dGNvbWUgPSAoJGNvbmZpZ1VzdWFyaW9bJ3RyYW5zcG9ydCddID8/
ICcnKSA9PT0gJ2dtYWlsX2FwaScKKyAgICAgICAgICAgICAgICA/IENvcnJlb0dtYWlsQXBpVHJh
bnNwb3J0OjplbnZpYXIoJG1haWwsICRjb25maWdVc3VhcmlvLCAkdGhpcy0+YXBpUmVxdWVzdCkK
KyAgICAgICAgICAgICAgICA6IENvcnJlb1NtdHBUcmFuc3BvcnQ6OmVudmlhcigkbWFpbCwgJ2Nv
bW1lcmNpYWwnKTsKICAgICAgICAgICAgIGlmIChlbXB0eSgkb3V0Y29tZVsnb2snXSkpIHsgcmV0
dXJuICRvdXRjb21lOyB9CiAgICAgICAgICAgICByZXR1cm4gJG91dGNvbWUgKyBbCiAgICAgICAg
ICAgICAgICAgJ2Zpcm1hX2luY2x1aWRhJyA9PiAkZmlybWFEaXNwb25pYmxlLAo=
CENTRAL_PATCH_2_1
 [[ "$(digest "$TMP/patches/2/1.patch")" == 'e784cf6c8746f0843e254128ede3ee7c4bb992312fd9a51d028a2dede468dbf8' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/2/2.patch" <<'CENTRAL_PATCH_2_2'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2Vydmlj
ZS5waHAgYi9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhw
Ci0tLSBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5waHAK
KysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocApA
QCAtNCw2ICs0LDcgQEAKIHJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9DdWVudGFDb25l
Y3RhZGFTZXJ2aWNlLnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvU210cFRy
YW5zcG9ydC5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0VudmlvQ29udHJv
bC5waHAnOworcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0xpbWl0ZVNlcnZpY2UucGhw
JzsKIAogY2xhc3MgQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UKIHsKQEAgLTIzLDYg
KzI0LDcgQEAKICAgICAgICAgJHRoaXMtPm1haWxlckZhY3RvcnkgPSAkbWFpbGVyRmFjdG9yeTsK
ICAgICAgICAgJHRoaXMtPmN1ZW50YXMgPSAkY3VlbnRhcyA/PyBuZXcgQ29ycmVvQ3VlbnRhQ29u
ZWN0YWRhU2VydmljZSgpOwogICAgICAgICAkdGhpcy0+YXBpUmVxdWVzdCA9ICRhcGlSZXF1ZXN0
OworICAgICAgICAkdGhpcy0+Y3VlbnRhcy0+Y29uZmlndXJhckNvbnN1bHRhQXBpKGZ1bmN0aW9u
ICgkbWV0aG9kLCR1cmwsJHRva2VuLCRwYXlsb2FkKSB7IHJldHVybiAkdGhpcy0+c29saWNpdGFy
SG9zdGluZ2VyKCRtZXRob2QsJHVybCwkdG9rZW4sJHBheWxvYWQpOyB9KTsKICAgICAgICAgJHRo
aXMtPmZpcm1hU2VydmljZSA9IG5ldyBGaXJtYUNvcnJlb1NlcnZpY2UoKTsKICAgICB9CiAKQEAg
LTM1LDcgKzM3LDcgQEAKICAgICAgICAgLyoKICAgICAgICAgICogQ29tcGF0aWJpbGlkYWQgY29u
IG3Ds2R1bG9zIGV4aXN0ZW50ZXM6IHNpIGVsIGxsYW1hZG9yIHRvZGF2w61hIG5vCiAgICAgICAg
ICAqIG1hbmRhIHVzdWFyaW9faWQsIHJldXRpbGl6YW1vcyBlbCB1c3VhcmlvIGF1dGVudGljYWRv
IGRlIGxhIHNlc2nDs24uCi0gICAgICAgICAqIEFzw60gdG9kb3MgbG9zIGVudsOtb3MgaW50ZXJh
Y3Rpdm9zIHVzYW4gbGEgY3VlbnRhIFNNVFAgcGVyc29uYWwuCisgICAgICAgICAqIFRvZG9zIGxv
cyBlbnbDrW9zIGludGVyYWN0aXZvcyBjb25zZXJ2YW4gbGEgcmVmZXJlbmNpYSBkZSBzdSB1c3Vh
cmlvLgogICAgICAgICAgKi8KICAgICAgICAgaWYgKCR1c3VhcmlvSWQgPD0gMCAmJiBpc3NldCgk
X1NFU1NJT05bJ3VzdWFyaW9faWQnXSkpIHsKICAgICAgICAgICAgICR1c3VhcmlvSWQgPSAoaW50
KSRfU0VTU0lPTlsndXN1YXJpb19pZCddOwpAQCAtODAsOCArODIsNiBAQAogICAgICAgICAgICAg
cmV0dXJuICR0aGlzLT5lcnJvcignRWwgYXN1bnRvIG8gZWwgbWVuc2FqZSBzdXBlcmEgZWwgdGFt
YcOxbyBwZXJtaXRpZG8uJywgNDIyKTsKICAgICAgICAgfQogCi0gICAgICAgICRydXRhRmlybWEg
PSAkdGhpcy0+ZmlybWFTZXJ2aWNlLT5vYnRlbmVyUnV0YSgkcmVtaXRlbnRlKTsKLQogICAgICAg
ICB0cnkgewogICAgICAgICAgICAgJGNvbm5lY3Rpb24gPSAkdGhpcy0+Y3VlbnRhcy0+cmVzb2x2
ZXIoJHJlbWl0ZW50ZSwgJHVzdWFyaW9JZCwgJHBlcmZpbE1hcmtldGluZyk7CiAgICAgICAgIH0g
Y2F0Y2ggKFRocm93YWJsZSAkZXJyb3IpIHsKQEAgLTkwLDI3ICs5MCwyOSBAQAogICAgICAgICB9
CiAgICAgICAgIGlmIChlbXB0eSgkY29ubmVjdGlvblsnb2snXSkpIHsgcmV0dXJuICRjb25uZWN0
aW9uOyB9CiAgICAgICAgICRhY2NvdW50ID0gJGNvbm5lY3Rpb25bJ2FjY291bnQnXTsKLSAgICAg
ICAgJHNlbmQgPSBmdW5jdGlvbiAoKSB1c2UgKCR1c3VhcmlvSWQsICRhY2NvdW50LCAkcmVtaXRl
bnRlLCAkbm9tYnJlUmVtaXRlbnRlLCAkZGVzdGluYXRhcmlvLCAkbm9tYnJlRGVzdGluYXRhcmlv
LAorICAgICAgICAkaWRlbnRpZGFkID0gJGNvbm5lY3Rpb25bJ2lkZW50aWRhZCddOworICAgICAg
ICAkcnV0YUZpcm1hID0gJHRoaXMtPmZpcm1hU2VydmljZS0+b2J0ZW5lclJ1dGEoJGlkZW50aWRh
ZFsnY29udGFjdG9fZmlybWEnXSk7CisgICAgICAgIGlmICghZW1wdHkoJGRhdG9zWydjb25maXJt
YXJfaWRlbnRpZGFkJ10pICYmICEkdGhpcy0+Y29uZmlybWFjaW9uVmFsaWRhKCRpZGVudGlkYWQs
IChzdHJpbmcpKCRkYXRvc1snaWRlbnRpZGFkX2VudmlvJ10gPz8gJycpKSkgeworICAgICAgICAg
ICAgcmV0dXJuICR0aGlzLT5lcnJvcignRWwgcmVtaXRlbnRlIGluc3RpdHVjaW9uYWwgY2FtYmnD
syBvIHZlbmNpw7Mgc3UgY29tcHJvYmFjacOzbi4gUmV2aXNhIGVsIHJlbWl0ZW50ZSB2aXNpYmxl
IHkgdnVlbHZlIGEgZW52aWFyLicsIDQyMik7CisgICAgICAgIH0KKyAgICAgICAgJHNlbmQgPSBm
dW5jdGlvbiAoKSB1c2UgKCR1c3VhcmlvSWQsICRpZGVudGlkYWQsICRhY2NvdW50LCAkcmVtaXRl
bnRlLCAkbm9tYnJlUmVtaXRlbnRlLCAkZGVzdGluYXRhcmlvLCAkbm9tYnJlRGVzdGluYXRhcmlv
LAogICAgICAgICAgICAgJGFzdW50bywgJGN1ZXJwbywgJHJ1dGFGaXJtYSwgJGltYWdlbmVzRW1i
ZWJpZGFzLCAkYWRqdW50b3MsICRodG1sQWRpY2lvbmFsKSB7Ci0gICAgICAgICAgICAkbGltaXRz
ID0gaW1wZUxlZXJDb25maWdQcml2YWRhKCdtYWlsX2FjY291bnRzLmxvY2FsLnBocCcpWydsaW1p
dHMnXSA/PyBbXTsKLSAgICAgICAgICAgICRtYXhpbXVtID0gbWF4KDEsIG1pbig1MDAsIChpbnQp
KCRsaW1pdHNbJ3Blcl91c2VyX2hvdXInXSA/PyA2MCkpKTsKLSAgICAgICAgICAgIGlmICghQ29y
cmVvTGltaXRlU2VydmljZTo6dG9tYXIoJ2NvbW1lcmNpYWwtc2VuZCcsICR1c3VhcmlvSWQsICRt
YXhpbXVtLCAzNjAwKSkgewotICAgICAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJ1Nl
IGFsY2FuesOzIGVsIGzDrW1pdGUgZGUgZW52w61vcyBkZSB0dSB1c3VhcmlvLiBFc3BlcmEgYW50
ZXMgZGUgaW5pY2lhciBvdHJvIGVudsOtby4nLCA0MjkpCi0gICAgICAgICAgICAgICAgICAgICsg
Wydlc3RhZG9fZW50cmVnYScgPT4gJ1JFQ0hBWkFETycsICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+
IGZhbHNlXTsKLSAgICAgICAgICAgIH0KKyAgICAgICAgICAgIGlmICghQ29ycmVvTGltaXRlU2Vy
dmljZTo6dG9tYXIoJ2NvbW1lcmNpYWwtc2VuZCcsICR1c3VhcmlvSWQsIDYwLCAzNjAwKSkgeyBy
ZXR1cm4gJHRoaXMtPmVycm9yKCdTZSBhbGNhbnrDsyBlbCBsw61taXRlIGRlIGVudsOtb3MuIEVz
cGVyYSBhbnRlcyBkZSB2b2x2ZXIgYSBlbnZpYXIuJywgNDI5KTsgfQogICAgICAgICAgICAgaWYg
KCRhY2NvdW50Wyd0cmFuc3BvcnQnXSA9PT0gJ2hvc3Rpbmdlcl9hcGknKSB7CiAgICAgICAgICAg
ICAgICAgJGJhc2UgPSBydHJpbSgoc3RyaW5nKSgkYWNjb3VudFsnYXBpX2Jhc2VfdXJsJ10gPz8g
c2VsZjo6SE9TVElOR0VSX0JBU0VfREVGQVVMVCksICcvJyk7CiAgICAgICAgICAgICAgICAgaWYg
KCRiYXNlICE9PSBzZWxmOjpIT1NUSU5HRVJfQkFTRV9ERUZBVUxUIHx8IGVtcHR5KCRhY2NvdW50
WydhcGlfdG9rZW4nXSkpIHsKICAgICAgICAgICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lcnJv
cignRmFsdGEgbGEgY29uZmlndXJhY2nDs24gcHJpdmFkYSBkZSBsYSBBUEkgSG9zdGluZ2VyIHBh
cmEgZXN0YSBjdWVudGEuJywgNDIyKTsKICAgICAgICAgICAgICAgICB9Ci0gICAgICAgICAgICAg
ICAgcmV0dXJuICR0aGlzLT5lbnZpYXJIb3N0aW5nZXIoJHJlbWl0ZW50ZSwgJG5vbWJyZVJlbWl0
ZW50ZSwgJGRlc3RpbmF0YXJpbywgJGFzdW50bywgJGN1ZXJwbywgJHJ1dGFGaXJtYSwKLSAgICAg
ICAgICAgICAgICAgICAgWyd0b2tlbicgPT4gJGFjY291bnRbJ2FwaV90b2tlbiddLCAnYmFzZV91
cmwnID0+ICRiYXNlXSwgJGltYWdlbmVzRW1iZWJpZGFzLCAkYWRqdW50b3MsICRodG1sQWRpY2lv
bmFsLCB0cnVlLCAkcmVtaXRlbnRlKTsKLSAgICAgICAgICAgIH0KLSAgICAgICAgICAgIHJldHVy
biAkdGhpcy0+ZW52aWFyU210cCgkcmVtaXRlbnRlLCAkbm9tYnJlUmVtaXRlbnRlLCAkZGVzdGlu
YXRhcmlvLCAkbm9tYnJlRGVzdGluYXRhcmlvLCAkYXN1bnRvLCAkY3VlcnBvLAotICAgICAgICAg
ICAgICAgICRydXRhRmlybWEsICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9zLCAkaHRtbEFk
aWNpb25hbCwgJGFjY291bnQpOworICAgICAgICAgICAgICAgICRyZXN1bHQgPSAkdGhpcy0+ZW52
aWFySG9zdGluZ2VyKCRpZGVudGlkYWRbJ3JlbWl0ZW50ZV92aXNpYmxlJ10sICRpZGVudGlkYWRb
J25vbWJyZV92aXNpYmxlJ10sICRkZXN0aW5hdGFyaW8sICRhc3VudG8sICRjdWVycG8sICRydXRh
RmlybWEsCisgICAgICAgICAgICAgICAgICAgIFsndG9rZW4nID0+ICRhY2NvdW50WydhcGlfdG9r
ZW4nXSwgJ2Jhc2VfdXJsJyA9PiAkYmFzZV0sICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9z
LCAkaHRtbEFkaWNpb25hbCwgdHJ1ZSwgJGlkZW50aWRhZFsncmVtaXRlbnRlX3Zpc2libGUnXSk7
CisgICAgICAgICAgICB9IGVsc2UgeworICAgICAgICAgICAgICAgICRyZXN1bHQgPSAkdGhpcy0+
ZW52aWFyU210cCgkaWRlbnRpZGFkWydyZW1pdGVudGVfdmlzaWJsZSddLCAkaWRlbnRpZGFkWydu
b21icmVfdmlzaWJsZSddLCAkZGVzdGluYXRhcmlvLCAkbm9tYnJlRGVzdGluYXRhcmlvLCAkYXN1
bnRvLCAkY3VlcnBvLAorICAgICAgICAgICAgICAgICAgICAkcnV0YUZpcm1hLCAkaW1hZ2VuZXNF
bWJlYmlkYXMsICRhZGp1bnRvcywgJGh0bWxBZGljaW9uYWwsIGFycmF5X3JlcGxhY2UoJGFjY291
bnQsIFsncmVwbHlfdG8nID0+ICRpZGVudGlkYWRbJ3JlcGx5X3RvJ11dKSk7CisgICAgICAgICAg
ICB9CisgICAgICAgICAgICByZXR1cm4gJHJlc3VsdCArICRpZGVudGlkYWQ7CiAgICAgICAgIH07
CiAgICAgICAgICRhdHRhY2htZW50cyA9IGFycmF5X21hcChzdGF0aWMgZnVuY3Rpb24gKCRhKSB7
IHJldHVybiBbJGFbJ25vbWJyZSddLCBoYXNoX2ZpbGUoJ3NoYTI1NicsICRhWydydXRhJ10pXTsg
fSwgYXJyYXlfbWVyZ2UoJGFkanVudG9zLCAkaW1hZ2VuZXNFbWJlYmlkYXMpKTsKLSAgICAgICAg
JGZpbmdlcnByaW50ID0gaGFzaCgnc2hhMjU2JywganNvbl9lbmNvZGUoWyR1c3VhcmlvSWQsICRy
ZW1pdGVudGUsICRkZXN0aW5hdGFyaW8sICRhc3VudG8sICRjdWVycG8sICRodG1sQWRpY2lvbmFs
LCAkYXR0YWNobWVudHMsCisgICAgICAgICRmaW5nZXJwcmludCA9IGhhc2goJ3NoYTI1NicsIGpz
b25fZW5jb2RlKFskdXN1YXJpb0lkLCAkaWRlbnRpZGFkLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRv
LCAkY3VlcnBvLCAkaHRtbEFkaWNpb25hbCwgJGF0dGFjaG1lbnRzLAogICAgICAgICAgICAgaXNf
ZmlsZSgkcnV0YUZpcm1hKSA/IGhhc2hfZmlsZSgnc2hhMjU2JywgJHJ1dGFGaXJtYSkgOiAnJ10p
KTsKICAgICAgICAgJGV4cGxpY2l0ID0gdHJpbSgoc3RyaW5nKSgkZGF0b3NbJ2lkZW1wb3RlbmN5
X2tleSddID8/ICcnKSk7CiAgICAgICAgIGlmICgkZXhwbGljaXQgIT09ICcnICYmICFwcmVnX21h
dGNoKCcvXlthLXpBLVowLTk6Xy4tXXs4LDE4MH0kL0QnLCAkZXhwbGljaXQpKSB7IHJldHVybiAk
dGhpcy0+ZXJyb3IoJ0xhIHNvbGljaXR1ZCBkZSBlbnbDrW8gbm8gZXMgdsOhbGlkYS4nLCA0MjIp
OyB9CkBAIC0xMjEsNiArMTIzLDM3IEBACiAgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9y
KCdObyBzZSBwdWRvIGNvbmZpcm1hciB1biBlbnbDrW8gc2VndXJvLiBSZXZpc2EgZWwgcHJvdmVl
ZG9yIHkgZWwgaGlzdG9yaWFsIGFudGVzIGRlIHJlcGV0aXJsby4nLCA1MDMpCiAgICAgICAgICAg
ICAgICAgKyBbJ2VzdGFkb19lbnRyZWdhJyA9PiAnREVTQ09OT0NJRE8nLCAnZW50cmVnYV9jb25m
aXJtYWRhJyA9PiBmYWxzZV07CiAgICAgICAgIH0KKyAgICB9CisKKyAgICBwdWJsaWMgZnVuY3Rp
b24gaWRlbnRpZGFkKHN0cmluZyAkY29udGFjdG8sIGludCAkdXNlcik6IGFycmF5CisgICAgewor
ICAgICAgICB0cnkgeworICAgICAgICAgICAgaWYgKCFmaWx0ZXJfdmFyKCRjb250YWN0bywgRklM
VEVSX1ZBTElEQVRFX0VNQUlMKSkgeyByZXR1cm4gJHRoaXMtPmVycm9yKCdJbmdyZXNhIHVuIGNv
cnJlbyBkZSBjb250YWN0byB2w6FsaWRvLicsIDQyMik7IH0KKyAgICAgICAgICAgICRyID0gJHRo
aXMtPmN1ZW50YXMtPnJlc29sdmVyKHN0cnRvbG93ZXIodHJpbSgkY29udGFjdG8pKSwgJHVzZXIp
OworICAgICAgICAgICAgaWYgKGVtcHR5KCRyWydvayddKSkgeyByZXR1cm4gJHI7IH0KKyAgICAg
ICAgICAgICRpZGVudGl0eSA9ICRyWydpZGVudGlkYWQnXTsgJGV4cGlyZXMgPSB0aW1lKCkgKyA2
MDA7CisgICAgICAgICAgICAkX1NFU1NJT05bJ21haWxfaWRlbnRpdHlfa2V5J10gPSAkX1NFU1NJ
T05bJ21haWxfaWRlbnRpdHlfa2V5J10gPz8gYmluMmhleChyYW5kb21fYnl0ZXMoMzIpKTsKKyAg
ICAgICAgICAgICR0b2tlbiA9ICRleHBpcmVzIC4gJy4nIC4gaGFzaF9obWFjKCdzaGEyNTYnLCBq
c29uX2VuY29kZShbJGlkZW50aXR5LCAkZXhwaXJlc10pLCAkX1NFU1NJT05bJ21haWxfaWRlbnRp
dHlfa2V5J10pOworICAgICAgICAgICAgcmV0dXJuIFsnb2snID0+IHRydWUsICdpZGVudGlkYWQn
ID0+ICRpZGVudGl0eSwgJ2lkZW50aWRhZF9lbnZpbycgPT4gJHRva2VuXTsKKyAgICAgICAgfSBj
YXRjaCAoVGhyb3dhYmxlICRlKSB7CisgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCRl
LT5nZXRNZXNzYWdlKCkgPT09ICdNQUlMX0NFTlRSQUxfRlJPTScKKyAgICAgICAgICAgICAgICA/
ICdUdSBjb3JyZW8gbm8gY29ycmVzcG9uZGUgYSB1biBidXrDs24gSG9zdGluZ2VyIGF1dG9yaXph
ZG8uIEVsIGFkbWluaXN0cmFkb3IgZGViZSBpbmRpY2FyIGxhIGN1ZW50YSBpbnN0aXR1Y2lvbmFs
IGRlIHNhbGlkYSBwYXJhIGVzdG9zIGNvbnRhY3Rvcy4nCisgICAgICAgICAgICAgICAgOiAnTm8g
c2UgcHVkbyBjb21wcm9iYXIgZWwgY29ycmVvIGluc3RpdHVjaW9uYWwgZGVsIHNpc3RlbWEuIFNv
bGljaXRhIHJldmlzYXIgc3UgY29uZmlndXJhY2nDs24gcHJpdmFkYSB5IGF1dG9yaXphY2nDs24u
JywgNTAzKTsKKyAgICAgICAgfQorICAgIH0KKworICAgIHByaXZhdGUgZnVuY3Rpb24gY29uZmly
bWFjaW9uVmFsaWRhKGFycmF5ICRpZGVudGl0eSwgc3RyaW5nICR0b2tlbik6IGJvb2wKKyAgICB7
CisgICAgICAgIGlmICghcHJlZ19tYXRjaCgnL14oWzAtOV17MTB9KVwuKFthLWYwLTldezY0fSkk
L0QnLCAkdG9rZW4sICRtKSB8fCAoaW50KSRtWzFdIDwgdGltZSgpIHx8IChpbnQpJG1bMV0gPiB0
aW1lKCkgKyA2MDAgfHwgZW1wdHkoJF9TRVNTSU9OWydtYWlsX2lkZW50aXR5X2tleSddKSkgeyBy
ZXR1cm4gZmFsc2U7IH0KKyAgICAgICAgcmV0dXJuIGhhc2hfZXF1YWxzKGhhc2hfaG1hYygnc2hh
MjU2JywganNvbl9lbmNvZGUoWyRpZGVudGl0eSwgKGludCkkbVsxXV0pLCAkX1NFU1NJT05bJ21h
aWxfaWRlbnRpdHlfa2V5J10pLCAkbVsyXSk7CisgICAgfQorCisgICAgcHVibGljIGZ1bmN0aW9u
IGNvbXByb2JhcklkZW50aWRhZChzdHJpbmcgJGNvbnRhY3RvLCBpbnQgJHVzZXIsIHN0cmluZyAk
dG9rZW4pOiBhcnJheQorICAgIHsKKyAgICAgICAgJHIgPSAkdGhpcy0+aWRlbnRpZGFkKCRjb250
YWN0bywgJHVzZXIpOworICAgICAgICBpZiAoZW1wdHkoJHJbJ29rJ10pKSB7IHJldHVybiAkcjsg
fQorICAgICAgICByZXR1cm4gJHRoaXMtPmNvbmZpcm1hY2lvblZhbGlkYSgkclsnaWRlbnRpZGFk
J10sICR0b2tlbikgPyBbJ29rJyA9PiB0cnVlLCAnaWRlbnRpZGFkJyA9PiAkclsnaWRlbnRpZGFk
J11dCisgICAgICAgICAgICA6ICR0aGlzLT5lcnJvcignUmV2aXNhIGVsIHJlbWl0ZW50ZSBpbnN0
aXR1Y2lvbmFsIHZpc2libGUgYW50ZXMgZGUgZW52aWFyLicsIDQyMik7CiAgICAgfQogCiAgICAg
cHJpdmF0ZSBmdW5jdGlvbiBlbnZpYXJIb3N0aW5nZXIoCkBAIC0yMjMsMTAgKzI1Niw2IEBACiAg
ICAgICAgIGlmICgkcmVzb3VyY2VJZCA9PT0gJycpIHsKICAgICAgICAgICAgIHJldHVybiBbCiAg
ICAgICAgICAgICAgICAgJ29rJyA9PiBmYWxzZSwKLSAgICAgICAgICAgICAgICAnY29kaWdvX2h0
dHAnID0+IDQyMiwKLSAgICAgICAgICAgICAgICAnZXN0YWRvX2VudHJlZ2EnID0+ICdSRUNIQVpB
RE8nLAotICAgICAgICAgICAgICAgICdjb25leGlvbl9yZXF1ZXJpZGEnID0+IHRydWUsCi0gICAg
ICAgICAgICAgICAgJ2NvbmV4aW9uX3VybCcgPT4gJ2luZGV4LnBocD9jb250cm9sbGVyPWNvcnJl
b0NvbmV4aW9uJmFjdGlvbj1pbmRleCZjb3JyZW89JyAuIHJhd3VybGVuY29kZSgkcmVtaXRlbnRl
KSwKICAgICAgICAgICAgICAgICAnbWVuc2FqZScgPT4gJHVzYXJSZW1pdGVudGVFeGFjdG8KICAg
ICAgICAgICAgICAgICAgICAgPyAnRWwgY29ycmVvIGRlIG9yaWdlbiBubyBlc3TDoSBhdXRvcml6
YWRvIGNvbW8gY3VlbnRhIGRlIHNhbGlkYS4gVXNhIHVuYSBjdWVudGEgY29uZmlndXJhZGEgZW4g
ZWwgc2lzdGVtYSBvIHVuIGJ1esOzbiBhdXRvcml6YWRvIGRlIEhvc3Rpbmdlci4nCiAgICAgICAg
ICAgICAgICAgICAgIDogJ05vIGhheSBuaW5nw7puIGJ1esOzbiBkZSBzYWxpZGEgYXV0b3JpemFk
byBkaXNwb25pYmxlLicKQEAgLTM2OCw2ICszOTcsNyBAQAogICAgICAgICAgICAgaWYgKCgkY29u
ZmlnVXN1YXJpb1sndHJhbnNwb3J0J10gPz8gJycpID09PSAnZ21haWxfYXBpJykgeyAkbWFpbC0+
Q2hhclNldCA9ICdVVEYtOCc7IH0KICAgICAgICAgICAgIGVsc2UgeyBDb3JyZW9TbXRwVHJhbnNw
b3J0Ojpjb25maWd1cmFyKCRtYWlsLCAkY29uZmlnVXN1YXJpbyk7IH0KICAgICAgICAgICAgICRt
YWlsLT5zZXRGcm9tKCRyZW1pdGVudGVVc3VhcmlvLCAkbm9tYnJlUmVtaXRlbnRlICE9PSAnJyA/
ICRub21icmVSZW1pdGVudGUgOiAkcmVtaXRlbnRlVXN1YXJpbyk7CisgICAgICAgICAgICBpZiAo
IWVtcHR5KCRjb25maWdVc3VhcmlvWydyZXBseV90byddKSkgeyAkbWFpbC0+YWRkUmVwbHlUbygk
Y29uZmlnVXN1YXJpb1sncmVwbHlfdG8nXSk7IH0KICAgICAgICAgICAgICRtYWlsLT5hZGRBZGRy
ZXNzKCRkZXN0aW5hdGFyaW8sICRub21icmVEZXN0aW5hdGFyaW8pOwogICAgICAgICAgICAgJGZp
cm1hRGlzcG9uaWJsZSA9ICRydXRhRmlybWEgIT09ICcnICYmIGlzX2ZpbGUoJHJ1dGFGaXJtYSk7
CiAgICAgICAgICAgICBpZiAoJGZpcm1hRGlzcG9uaWJsZSkgewo=
CENTRAL_PATCH_2_2
 [[ "$(digest "$TMP/patches/2/2.patch")" == '65d2ef98b8cf7f2a3839286502d5122446fbfca8857af46f0cf678474c58d452' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/2/3.patch" <<'CENTRAL_PATCH_2_3'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2Vydmlj
ZS5waHAgYi9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhw
Ci0tLSBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5waHAK
KysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocApA
QCAtMSw2ICsxLDEwIEBACiA8P3BocAogCiByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvRmlybWFD
b3JyZW9TZXJ2aWNlLnBocCc7CityZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvQ3VlbnRh
Q29uZWN0YWRhU2VydmljZS5waHAnOworcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb1Nt
dHBUcmFuc3BvcnQucGhwJzsKK3JlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9FbnZpb0Nv
bnRyb2wucGhwJzsKK3JlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9MaW1pdGVTZXJ2aWNl
LnBocCc7CiAKIGNsYXNzIENvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlCiB7CkBAIC0x
MCw5ICsxNCwxNyBAQAogICAgIHByaXZhdGUgJHJvb3RQYXRoOwogICAgIHByaXZhdGUgJGZpcm1h
U2VydmljZTsKIAotICAgIHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0cnVjdCgpCisgICAgcHJpdmF0
ZSAkbWFpbGVyRmFjdG9yeTsKKyAgICBwcml2YXRlICRjdWVudGFzOworICAgIHByaXZhdGUgJGFw
aVJlcXVlc3Q7CisKKyAgICBwdWJsaWMgZnVuY3Rpb24gX19jb25zdHJ1Y3QoP2NhbGxhYmxlICRt
YWlsZXJGYWN0b3J5ID0gbnVsbCwgP0NvcnJlb0N1ZW50YUNvbmVjdGFkYVNlcnZpY2UgJGN1ZW50
YXMgPSBudWxsLCA/Y2FsbGFibGUgJGFwaVJlcXVlc3QgPSBudWxsKQogICAgIHsKICAgICAgICAg
JHRoaXMtPnJvb3RQYXRoID0gZGlybmFtZShfX0RJUl9fLCAyKTsKKyAgICAgICAgJHRoaXMtPm1h
aWxlckZhY3RvcnkgPSAkbWFpbGVyRmFjdG9yeTsKKyAgICAgICAgJHRoaXMtPmN1ZW50YXMgPSAk
Y3VlbnRhcyA/PyBuZXcgQ29ycmVvQ3VlbnRhQ29uZWN0YWRhU2VydmljZSgpOworICAgICAgICAk
dGhpcy0+YXBpUmVxdWVzdCA9ICRhcGlSZXF1ZXN0OworICAgICAgICAkdGhpcy0+Y3VlbnRhcy0+
Y29uZmlndXJhckNvbnN1bHRhQXBpKGZ1bmN0aW9uICgkbWV0aG9kLCR1cmwsJHRva2VuLCRwYXls
b2FkKSB7IHJldHVybiAkdGhpcy0+c29saWNpdGFySG9zdGluZ2VyKCRtZXRob2QsJHVybCwkdG9r
ZW4sJHBheWxvYWQpOyB9KTsKICAgICAgICAgJHRoaXMtPmZpcm1hU2VydmljZSA9IG5ldyBGaXJt
YUNvcnJlb1NlcnZpY2UoKTsKICAgICB9CiAKQEAgLTIwLDE0ICszMiwxMiBAQAogICAgIHsKICAg
ICAgICAgJHJlbWl0ZW50ZSA9IHN0cnRvbG93ZXIodHJpbSgoc3RyaW5nKSgkZGF0b3NbJ3JlbWl0
ZW50ZSddID8/ICcnKSkpOwogICAgICAgICAkdXN1YXJpb0lkID0gKGludCkoJGRhdG9zWyd1c3Vh
cmlvX2lkJ10gPz8gMCk7Ci0gICAgICAgICRlc0VudmlvU2lzdGVtYSA9ICFlbXB0eSgkZGF0b3Nb
J2VudmlvX3Npc3RlbWEnXSk7CiAgICAgICAgICRwZXJmaWxNYXJrZXRpbmcgPSAhZW1wdHkoJGRh
dG9zWydwZXJmaWxfbWFya2V0aW5nJ10pOwotICAgICAgICAkdXNhclJlbWl0ZW50ZUV4YWN0byA9
ICFlbXB0eSgkZGF0b3NbJ3VzYXJfcmVtaXRlbnRlX2V4YWN0byddKTsKIAogICAgICAgICAvKgog
ICAgICAgICAgKiBDb21wYXRpYmlsaWRhZCBjb24gbcOzZHVsb3MgZXhpc3RlbnRlczogc2kgZWwg
bGxhbWFkb3IgdG9kYXbDrWEgbm8KICAgICAgICAgICogbWFuZGEgdXN1YXJpb19pZCwgcmV1dGls
aXphbW9zIGVsIHVzdWFyaW8gYXV0ZW50aWNhZG8gZGUgbGEgc2VzacOzbi4KLSAgICAgICAgICog
QXPDrSB0b2RvcyBsb3MgZW52w61vcyBpbnRlcmFjdGl2b3MgdXNhbiBsYSBjdWVudGEgU01UUCBw
ZXJzb25hbC4KKyAgICAgICAgICogVG9kb3MgbG9zIGVudsOtb3MgaW50ZXJhY3Rpdm9zIGNvbnNl
cnZhbiBsYSByZWZlcmVuY2lhIGRlIHN1IHVzdWFyaW8uCiAgICAgICAgICAqLwogICAgICAgICBp
ZiAoJHVzdWFyaW9JZCA8PSAwICYmIGlzc2V0KCRfU0VTU0lPTlsndXN1YXJpb19pZCddKSkgewog
ICAgICAgICAgICAgJHVzdWFyaW9JZCA9IChpbnQpJF9TRVNTSU9OWyd1c3VhcmlvX2lkJ107CkBA
IC03MiwxNjIgKzgyLDc4IEBACiAgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCdFbCBh
c3VudG8gbyBlbCBtZW5zYWplIHN1cGVyYSBlbCB0YW1hw7FvIHBlcm1pdGlkby4nLCA0MjIpOwog
ICAgICAgICB9CiAKLSAgICAgICAgJHJ1dGFGaXJtYSA9ICR0aGlzLT5maXJtYVNlcnZpY2UtPm9i
dGVuZXJSdXRhKCRyZW1pdGVudGUpOwotCi0gICAgICAgIC8qCi0gICAgICAgICAqIE1hcmtldGlu
ZyBwdWVkZSBzb2xpY2l0YXIgdW4gcmVtaXRlbnRlIGNvbmNyZXRvLCBwZXJvIGxhcwotICAgICAg
ICAgKiBjcmVkZW5jaWFsZXMgc2lndWVuIHNpZW5kbyBhZG1pbmlzdHJhZGFzIHBvciBlbCBzaXN0
ZW1hLgotICAgICAgICAgKiBTaSBlc2UgcmVtaXRlbnRlIGNvaW5jaWRlIGNvbiBsYSBjdWVudGEg
U01UUCBjZW50cmFsLCB1c2Ftb3MgU01UUC4KLSAgICAgICAgICogU2kgY29ycmVzcG9uZGUgYSB1
biBidXrDs24gYXV0b3JpemFkbyBwb3IgSG9zdGluZ2VyIE1haWwgQVBJLAotICAgICAgICAgKiB1
c2Ftb3MgZXNlIGJ1esOzbi4gTnVuY2Egc3VwbGFudGFtb3MgZGlyZWNjaW9uZXMgbm8gYXV0b3Jp
emFkYXMuCi0gICAgICAgICAqLwotICAgICAgICAkY29uZmlnTWFya2V0aW5nID0gJHBlcmZpbE1h
cmtldGluZwotICAgICAgICAgICAgPyAkdGhpcy0+Y2FyZ2FyQ29uZmlnTWFya2V0aW5nKCkKLSAg
ICAgICAgICAgIDogW107Ci0KLSAgICAgICAgaWYgKCRwZXJmaWxNYXJrZXRpbmcpIHsKLSAgICAg
ICAgICAgICRzbXRwRnJvbUNvbmZpZ3VyYWRvID0gc3RydG9sb3dlcih0cmltKChzdHJpbmcpKAot
ICAgICAgICAgICAgICAgICRjb25maWdNYXJrZXRpbmdbJ3NtdHBfZnJvbV9hZGRyZXNzJ10gPz8g
JycKLSAgICAgICAgICAgICkpKTsKLSAgICAgICAgICAgICRzbXRwVXN1YXJpb0NvbmZpZ3VyYWRv
ID0gdHJpbSgoc3RyaW5nKSgKLSAgICAgICAgICAgICAgICAkY29uZmlnTWFya2V0aW5nWydzbXRw
X3VzZXJuYW1lJ10gPz8gJycKLSAgICAgICAgICAgICkpOwotICAgICAgICAgICAgJHNtdHBQYXNz
d29yZENvbmZpZ3VyYWRvID0gKHN0cmluZykoCi0gICAgICAgICAgICAgICAgJGNvbmZpZ01hcmtl
dGluZ1snc210cF9wYXNzd29yZCddID8/ICcnCi0gICAgICAgICAgICApOwotCi0gICAgICAgICAg
ICAkc210cE1hcmtldGluZyA9IFsKLSAgICAgICAgICAgICAgICAnc210cF9ob3N0JyA9PiB0cmlt
KChzdHJpbmcpKAotICAgICAgICAgICAgICAgICAgICAkY29uZmlnTWFya2V0aW5nWydzbXRwX2hv
c3QnXSA/PyAnJwotICAgICAgICAgICAgICAgICkpLAotICAgICAgICAgICAgICAgICdzbXRwX3Bv
cnQnID0+IChpbnQpKAotICAgICAgICAgICAgICAgICAgICAkY29uZmlnTWFya2V0aW5nWydzbXRw
X3BvcnQnXSA/PyA1ODcKLSAgICAgICAgICAgICAgICApLAotICAgICAgICAgICAgICAgICdzbXRw
X2VuY3J5cHRpb24nID0+IHN0cnRvbG93ZXIodHJpbSgoc3RyaW5nKSgKLSAgICAgICAgICAgICAg
ICAgICAgJGNvbmZpZ01hcmtldGluZ1snc210cF9lbmNyeXB0aW9uJ10gPz8gJ3RscycKLSAgICAg
ICAgICAgICAgICApKSksCi0gICAgICAgICAgICAgICAgJ3NtdHBfdXNlcm5hbWUnID0+ICRzbXRw
VXN1YXJpb0NvbmZpZ3VyYWRvLAotICAgICAgICAgICAgICAgICdzbXRwX3Bhc3N3b3JkJyA9PiAk
c210cFBhc3N3b3JkQ29uZmlndXJhZG8sCi0gICAgICAgICAgICAgICAgJ2NvcnJlbycgPT4gJHNt
dHBGcm9tQ29uZmlndXJhZG8KLSAgICAgICAgICAgIF07Ci0gICAgICAgIH0gZWxzZSB7Ci0gICAg
ICAgICAgICAkYXJjaGl2b0NvbmZpZ1NtdHAgPSAkdGhpcy0+cm9vdFBhdGggLgotICAgICAgICAg
ICAgICAgIERJUkVDVE9SWV9TRVBBUkFUT1IgLiAnY29uZmlnJyAuCi0gICAgICAgICAgICAgICAg
RElSRUNUT1JZX1NFUEFSQVRPUiAuICdtYWlsX2NvbmZpZy5waHAnOwotCi0gICAgICAgICAgICBp
ZiAoaXNfZmlsZSgkYXJjaGl2b0NvbmZpZ1NtdHApKSB7Ci0gICAgICAgICAgICAgICAgcmVxdWly
ZV9vbmNlICRhcmNoaXZvQ29uZmlnU210cDsKLSAgICAgICAgICAgIH0KLQotICAgICAgICAgICAg
JHNtdHBGcm9tQ29uZmlndXJhZG8gPSBzdHJ0b2xvd2VyKAotICAgICAgICAgICAgICAgIHRyaW0o
KHN0cmluZykkdGhpcy0+Y29uZmlnKCdNQUlMX0ZST01fQUREUkVTUycpKQotICAgICAgICAgICAg
KTsKLSAgICAgICAgICAgICRzbXRwVXN1YXJpb0NvbmZpZ3VyYWRvID0gdHJpbSgKLSAgICAgICAg
ICAgICAgICAoc3RyaW5nKSR0aGlzLT5jb25maWcoJ01BSUxfVVNFUk5BTUUnKQotICAgICAgICAg
ICAgKTsKLSAgICAgICAgICAgICRzbXRwUGFzc3dvcmRDb25maWd1cmFkbyA9IChzdHJpbmcpJHRo
aXMtPmNvbmZpZygnTUFJTF9QQVNTV09SRCcpOwotICAgICAgICAgICAgJHNtdHBNYXJrZXRpbmcg
PSBudWxsOwotICAgICAgICB9Ci0KLSAgICAgICAgaWYgKAotICAgICAgICAgICAgJHVzYXJSZW1p
dGVudGVFeGFjdG8gJiYKLSAgICAgICAgICAgICRzbXRwRnJvbUNvbmZpZ3VyYWRvICE9PSAnJyAm
JgotICAgICAgICAgICAgc3RyY2FzZWNtcCgkcmVtaXRlbnRlLCAkc210cEZyb21Db25maWd1cmFk
bykgPT09IDAgJiYKLSAgICAgICAgICAgICRzbXRwVXN1YXJpb0NvbmZpZ3VyYWRvICE9PSAnJyAm
JgotICAgICAgICAgICAgJHNtdHBQYXNzd29yZENvbmZpZ3VyYWRvICE9PSAnJwotICAgICAgICAp
IHsKLSAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZW52aWFyU210cCgKLSAgICAgICAgICAgICAg
ICAkcmVtaXRlbnRlLAotICAgICAgICAgICAgICAgICRub21icmVSZW1pdGVudGUsCi0gICAgICAg
ICAgICAgICAgJGRlc3RpbmF0YXJpbywKLSAgICAgICAgICAgICAgICAkbm9tYnJlRGVzdGluYXRh
cmlvLAotICAgICAgICAgICAgICAgICRhc3VudG8sCi0gICAgICAgICAgICAgICAgJGN1ZXJwbywK
LSAgICAgICAgICAgICAgICAkcnV0YUZpcm1hLAotICAgICAgICAgICAgICAgICRpbWFnZW5lc0Vt
YmViaWRhcywKLSAgICAgICAgICAgICAgICAkYWRqdW50b3MsCi0gICAgICAgICAgICAgICAgJGh0
bWxBZGljaW9uYWwsCi0gICAgICAgICAgICAgICAgJHNtdHBNYXJrZXRpbmcKLSAgICAgICAgICAg
ICk7Ci0gICAgICAgIH0KLQotICAgICAgICAkaG9zdGluZ2VyID0gJHBlcmZpbE1hcmtldGluZwot
ICAgICAgICAgICAgPyBbCi0gICAgICAgICAgICAgICAgJ3Rva2VuJyA9PiB0cmltKChzdHJpbmcp
KAotICAgICAgICAgICAgICAgICAgICAkY29uZmlnTWFya2V0aW5nWydob3N0aW5nZXJfbWFpbF9h
cGlfdG9rZW4nXSA/PyAnJwotICAgICAgICAgICAgICAgICkpLAotICAgICAgICAgICAgICAgICdi
YXNlX3VybCcgPT4gcnRyaW0oCi0gICAgICAgICAgICAgICAgICAgIHRyaW0oKHN0cmluZykoCi0g
ICAgICAgICAgICAgICAgICAgICAgICAkY29uZmlnTWFya2V0aW5nWydob3N0aW5nZXJfbWFpbF9h
cGlfYmFzZV91cmwnXSA/PwotICAgICAgICAgICAgICAgICAgICAgICAgc2VsZjo6SE9TVElOR0VS
X0JBU0VfREVGQVVMVAotICAgICAgICAgICAgICAgICAgICApKSwKLSAgICAgICAgICAgICAgICAg
ICAgJy8nCi0gICAgICAgICAgICAgICAgKSwKLSAgICAgICAgICAgICAgICAnZnJvbV9hZGRyZXNz
JyA9PiBzdHJ0b2xvd2VyKHRyaW0oKHN0cmluZykoCi0gICAgICAgICAgICAgICAgICAgICRjb25m
aWdNYXJrZXRpbmdbJ2hvc3Rpbmdlcl9mcm9tX2FkZHJlc3MnXSA/PyAnJwotICAgICAgICAgICAg
ICAgICkpKQotICAgICAgICAgICAgXQotICAgICAgICAgICAgOiBhcnJheV9tZXJnZSgKLSAgICAg
ICAgICAgICAgICAkdGhpcy0+Y2FyZ2FySG9zdGluZ2VyKCksCi0gICAgICAgICAgICAgICAgWwot
ICAgICAgICAgICAgICAgICAgICAnZnJvbV9hZGRyZXNzJyA9PiBzdHJ0b2xvd2VyKHRyaW0oKHN0
cmluZykKLSAgICAgICAgICAgICAgICAgICAgICAgICR0aGlzLT5jb25maWcoJ0hPU1RJTkdFUl9N
QUlMX0ZST01fQUREUkVTUycpCi0gICAgICAgICAgICAgICAgICAgICkpCi0gICAgICAgICAgICAg
ICAgXQotICAgICAgICAgICAgKTsKLQotCi0gICAgICAgIGlmICgkaG9zdGluZ2VyWyd0b2tlbidd
ICE9PSAnJykgewotICAgICAgICAgICAgJGVudmlvSG9zdGluZ2VyID0gJHRoaXMtPmVudmlhckhv
c3RpbmdlcigKLSAgICAgICAgICAgICAgICAkcmVtaXRlbnRlLAotICAgICAgICAgICAgICAgICRu
b21icmVSZW1pdGVudGUsCi0gICAgICAgICAgICAgICAgJGRlc3RpbmF0YXJpbywKLSAgICAgICAg
ICAgICAgICAkYXN1bnRvLAotICAgICAgICAgICAgICAgICRjdWVycG8sCi0gICAgICAgICAgICAg
ICAgJHJ1dGFGaXJtYSwKLSAgICAgICAgICAgICAgICAkaG9zdGluZ2VyLAotICAgICAgICAgICAg
ICAgICRpbWFnZW5lc0VtYmViaWRhcywKLSAgICAgICAgICAgICAgICAkYWRqdW50b3MsCi0gICAg
ICAgICAgICAgICAgJGh0bWxBZGljaW9uYWwsCi0gICAgICAgICAgICAgICAgJHVzYXJSZW1pdGVu
dGVFeGFjdG8sCi0gICAgICAgICAgICAgICAgKHN0cmluZykoJGhvc3RpbmdlclsnZnJvbV9hZGRy
ZXNzJ10gPz8gJycpCi0gICAgICAgICAgICApOwotCi0gICAgICAgICAgICBpZiAoIWVtcHR5KCRl
bnZpb0hvc3Rpbmdlclsnb2snXSkpIHsKLSAgICAgICAgICAgICAgICByZXR1cm4gJGVudmlvSG9z
dGluZ2VyOwotICAgICAgICAgICAgfQotCi0gICAgICAgICAgICBpZiAoZW1wdHkoJGVudmlvSG9z
dGluZ2VyWydmYWxsYmFja19zbXRwJ10pKSB7Ci0gICAgICAgICAgICAgICAgcmV0dXJuICRlbnZp
b0hvc3RpbmdlcjsKLSAgICAgICAgICAgIH0KLSAgICAgICAgfQotCi0gICAgICAgIGlmICgKLSAg
ICAgICAgICAgICR1c2FyUmVtaXRlbnRlRXhhY3RvICYmCi0gICAgICAgICAgICAoCi0gICAgICAg
ICAgICAgICAgJHNtdHBGcm9tQ29uZmlndXJhZG8gPT09ICcnIHx8Ci0gICAgICAgICAgICAgICAg
c3RyY2FzZWNtcCgkcmVtaXRlbnRlLCAkc210cEZyb21Db25maWd1cmFkbykgIT09IDAKLSAgICAg
ICAgICAgICkKLSAgICAgICAgKSB7Ci0gICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKAot
ICAgICAgICAgICAgICAgICdObyBlcyBwb3NpYmxlIGVudmlhciBtb3N0cmFuZG8gZXNlIGNvcnJl
byBjb21vIG9yaWdlbiBwb3JxdWUgbm8gZXN0w6EgYXV0b3JpemFkbyBlbiBIb3N0aW5nZXIgbmkg
Y29pbmNpZGUgY29uIGxhIGN1ZW50YSBjZW50cmFsIGNvbmZpZ3VyYWRhLicsCi0gICAgICAgICAg
ICAgICAgNDIyCi0gICAgICAgICAgICApOwotICAgICAgICB9Ci0KLSAgICAgICAgcmV0dXJuICR0
aGlzLT5lbnZpYXJTbXRwKAotICAgICAgICAgICAgJHJlbWl0ZW50ZSwKLSAgICAgICAgICAgICRu
b21icmVSZW1pdGVudGUsCi0gICAgICAgICAgICAkZGVzdGluYXRhcmlvLAotICAgICAgICAgICAg
JG5vbWJyZURlc3RpbmF0YXJpbywKLSAgICAgICAgICAgICRhc3VudG8sCi0gICAgICAgICAgICAk
Y3VlcnBvLAotICAgICAgICAgICAgJHJ1dGFGaXJtYSwKLSAgICAgICAgICAgICRpbWFnZW5lc0Vt
YmViaWRhcywKLSAgICAgICAgICAgICRhZGp1bnRvcywKLSAgICAgICAgICAgICRodG1sQWRpY2lv
bmFsLAotICAgICAgICAgICAgJHNtdHBNYXJrZXRpbmcKLSAgICAgICAgKTsKKyAgICAgICAgdHJ5
IHsKKyAgICAgICAgICAgICRjb25uZWN0aW9uID0gJHRoaXMtPmN1ZW50YXMtPnJlc29sdmVyKCRy
ZW1pdGVudGUsICR1c3VhcmlvSWQsICRwZXJmaWxNYXJrZXRpbmcpOworICAgICAgICB9IGNhdGNo
IChUaHJvd2FibGUgJGVycm9yKSB7CisgICAgICAgICAgICBlcnJvcl9sb2coJ01BSUxfVFJBTlNQ
T1JUIGFjY291bnRfY29uZmlndXJhdGlvbl9mYWlsZWQgZXhjZXB0aW9uPScgLiBnZXRfY2xhc3Mo
JGVycm9yKSk7CisgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCdObyBmdWUgcG9zaWJs
ZSBjb25zdWx0YXIgbGFzIGN1ZW50YXMgYXV0b3JpemFkYXMuIFNvbGljaXRhIHJldmlzYXIgbGEg
Y29uZmlndXJhY2nDs24gcHJpdmFkYS4nLCA1MDMpOworICAgICAgICB9CisgICAgICAgIGlmIChl
bXB0eSgkY29ubmVjdGlvblsnb2snXSkpIHsgcmV0dXJuICRjb25uZWN0aW9uOyB9CisgICAgICAg
ICRhY2NvdW50ID0gJGNvbm5lY3Rpb25bJ2FjY291bnQnXTsKKyAgICAgICAgJGlkZW50aWRhZCA9
ICRjb25uZWN0aW9uWydpZGVudGlkYWQnXTsKKyAgICAgICAgJHJ1dGFGaXJtYSA9ICR0aGlzLT5m
aXJtYVNlcnZpY2UtPm9idGVuZXJSdXRhKCRpZGVudGlkYWRbJ2NvbnRhY3RvX2Zpcm1hJ10pOwor
ICAgICAgICBpZiAoIWVtcHR5KCRkYXRvc1snY29uZmlybWFyX2lkZW50aWRhZCddKSAmJiAhJHRo
aXMtPmNvbmZpcm1hY2lvblZhbGlkYSgkaWRlbnRpZGFkLCAoc3RyaW5nKSgkZGF0b3NbJ2lkZW50
aWRhZF9lbnZpbyddID8/ICcnKSkpIHsKKyAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3Io
J0VsIHJlbWl0ZW50ZSBpbnN0aXR1Y2lvbmFsIGNhbWJpw7MgbyB2ZW5jacOzIHN1IGNvbXByb2Jh
Y2nDs24uIFJldmlzYSBlbCByZW1pdGVudGUgdmlzaWJsZSB5IHZ1ZWx2ZSBhIGVudmlhci4nLCA0
MjIpOworICAgICAgICB9CisgICAgICAgICRzZW5kID0gZnVuY3Rpb24gKCkgdXNlICgkdXN1YXJp
b0lkLCAkaWRlbnRpZGFkLCAkYWNjb3VudCwgJHJlbWl0ZW50ZSwgJG5vbWJyZVJlbWl0ZW50ZSwg
JGRlc3RpbmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJpbywKKyAgICAgICAgICAgICRhc3VudG8s
ICRjdWVycG8sICRydXRhRmlybWEsICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9zLCAkaHRt
bEFkaWNpb25hbCkgeworICAgICAgICAgICAgaWYgKCFDb3JyZW9MaW1pdGVTZXJ2aWNlOjp0b21h
cignY29tbWVyY2lhbC1zZW5kJywgJHVzdWFyaW9JZCwgNjAsIDM2MDApKSB7IHJldHVybiAkdGhp
cy0+ZXJyb3IoJ1NlIGFsY2FuesOzIGVsIGzDrW1pdGUgZGUgZW52w61vcy4gRXNwZXJhIGFudGVz
IGRlIHZvbHZlciBhIGVudmlhci4nLCA0MjkpOyB9CisgICAgICAgICAgICBpZiAoJGFjY291bnRb
J3RyYW5zcG9ydCddID09PSAnaG9zdGluZ2VyX2FwaScpIHsKKyAgICAgICAgICAgICAgICAkYmFz
ZSA9IHJ0cmltKChzdHJpbmcpKCRhY2NvdW50WydhcGlfYmFzZV91cmwnXSA/PyBzZWxmOjpIT1NU
SU5HRVJfQkFTRV9ERUZBVUxUKSwgJy8nKTsKKyAgICAgICAgICAgICAgICBpZiAoJGJhc2UgIT09
IHNlbGY6OkhPU1RJTkdFUl9CQVNFX0RFRkFVTFQgfHwgZW1wdHkoJGFjY291bnRbJ2FwaV90b2tl
biddKSkgeworICAgICAgICAgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCdGYWx0YSBs
YSBjb25maWd1cmFjacOzbiBwcml2YWRhIGRlIGxhIEFQSSBIb3N0aW5nZXIgcGFyYSBlc3RhIGN1
ZW50YS4nLCA0MjIpOworICAgICAgICAgICAgICAgIH0KKyAgICAgICAgICAgICAgICAkcmVzdWx0
ID0gJHRoaXMtPmVudmlhckhvc3RpbmdlcigkaWRlbnRpZGFkWydyZW1pdGVudGVfdmlzaWJsZSdd
LCAkaWRlbnRpZGFkWydub21icmVfdmlzaWJsZSddLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRvLCAk
Y3VlcnBvLCAkcnV0YUZpcm1hLAorICAgICAgICAgICAgICAgICAgICBbJ3Rva2VuJyA9PiAkYWNj
b3VudFsnYXBpX3Rva2VuJ10sICdiYXNlX3VybCcgPT4gJGJhc2VdLCAkaW1hZ2VuZXNFbWJlYmlk
YXMsICRhZGp1bnRvcywgJGh0bWxBZGljaW9uYWwsIHRydWUsICRpZGVudGlkYWRbJ3JlbWl0ZW50
ZV92aXNpYmxlJ10pOworICAgICAgICAgICAgfSBlbHNlIHsKKyAgICAgICAgICAgICAgICAkcmVz
dWx0ID0gJHRoaXMtPmVudmlhclNtdHAoJGlkZW50aWRhZFsncmVtaXRlbnRlX3Zpc2libGUnXSwg
JGlkZW50aWRhZFsnbm9tYnJlX3Zpc2libGUnXSwgJGRlc3RpbmF0YXJpbywgJG5vbWJyZURlc3Rp
bmF0YXJpbywgJGFzdW50bywgJGN1ZXJwbywKKyAgICAgICAgICAgICAgICAgICAgJHJ1dGFGaXJt
YSwgJGltYWdlbmVzRW1iZWJpZGFzLCAkYWRqdW50b3MsICRodG1sQWRpY2lvbmFsLCBhcnJheV9y
ZXBsYWNlKCRhY2NvdW50LCBbJ3JlcGx5X3RvJyA9PiAkaWRlbnRpZGFkWydyZXBseV90byddXSkp
OworICAgICAgICAgICAgfQorICAgICAgICAgICAgcmV0dXJuICRyZXN1bHQgKyAkaWRlbnRpZGFk
OworICAgICAgICB9OworICAgICAgICAkYXR0YWNobWVudHMgPSBhcnJheV9tYXAoc3RhdGljIGZ1
bmN0aW9uICgkYSkgeyByZXR1cm4gWyRhWydub21icmUnXSwgaGFzaF9maWxlKCdzaGEyNTYnLCAk
YVsncnV0YSddKV07IH0sIGFycmF5X21lcmdlKCRhZGp1bnRvcywgJGltYWdlbmVzRW1iZWJpZGFz
KSk7CisgICAgICAgICRmaW5nZXJwcmludCA9IGhhc2goJ3NoYTI1NicsIGpzb25fZW5jb2RlKFsk
dXN1YXJpb0lkLCAkaWRlbnRpZGFkLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRvLCAkY3VlcnBvLCAk
aHRtbEFkaWNpb25hbCwgJGF0dGFjaG1lbnRzLAorICAgICAgICAgICAgaXNfZmlsZSgkcnV0YUZp
cm1hKSA/IGhhc2hfZmlsZSgnc2hhMjU2JywgJHJ1dGFGaXJtYSkgOiAnJ10pKTsKKyAgICAgICAg
JGV4cGxpY2l0ID0gdHJpbSgoc3RyaW5nKSgkZGF0b3NbJ2lkZW1wb3RlbmN5X2tleSddID8/ICcn
KSk7CisgICAgICAgIGlmICgkZXhwbGljaXQgIT09ICcnICYmICFwcmVnX21hdGNoKCcvXlthLXpB
LVowLTk6Xy4tXXs4LDE4MH0kL0QnLCAkZXhwbGljaXQpKSB7IHJldHVybiAkdGhpcy0+ZXJyb3Io
J0xhIHNvbGljaXR1ZCBkZSBlbnbDrW8gbm8gZXMgdsOhbGlkYS4nLCA0MjIpOyB9CisgICAgICAg
IHRyeSB7CisgICAgICAgICAgICByZXR1cm4gQ29ycmVvRW52aW9Db250cm9sOjplamVjdXRhcigk
dXN1YXJpb0lkIC4gJzonIC4gKCRleHBsaWNpdCA/OiAkZmluZ2VycHJpbnQpLCAkZmluZ2VycHJp
bnQsICRzZW5kLCAkZXhwbGljaXQgIT09ICcnKTsKKyAgICAgICAgfSBjYXRjaCAoVGhyb3dhYmxl
ICRlcnJvcikgeworICAgICAgICAgICAgZXJyb3JfbG9nKCdNQUlMX1RSQU5TUE9SVCAnIC4ganNv
bl9lbmNvZGUoWydwaGFzZScgPT4gJ2FjY291bnRfb3Jfc3RhdGUnLCAnZXhjZXB0aW9uJyA9PiBn
ZXRfY2xhc3MoJGVycm9yKV0pKTsKKyAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJ05v
IHNlIHB1ZG8gY29uZmlybWFyIHVuIGVudsOtbyBzZWd1cm8uIFJldmlzYSBlbCBwcm92ZWVkb3Ig
eSBlbCBoaXN0b3JpYWwgYW50ZXMgZGUgcmVwZXRpcmxvLicsIDUwMykKKyAgICAgICAgICAgICAg
ICArIFsnZXN0YWRvX2VudHJlZ2EnID0+ICdERVNDT05PQ0lETycsICdlbnRyZWdhX2NvbmZpcm1h
ZGEnID0+IGZhbHNlXTsKKyAgICAgICAgfQorICAgIH0KKworICAgIHB1YmxpYyBmdW5jdGlvbiBp
ZGVudGlkYWQoc3RyaW5nICRjb250YWN0bywgaW50ICR1c2VyKTogYXJyYXkKKyAgICB7CisgICAg
ICAgIHRyeSB7CisgICAgICAgICAgICBpZiAoIWZpbHRlcl92YXIoJGNvbnRhY3RvLCBGSUxURVJf
VkFMSURBVEVfRU1BSUwpKSB7IHJldHVybiAkdGhpcy0+ZXJyb3IoJ0luZ3Jlc2EgdW4gY29ycmVv
IGRlIGNvbnRhY3RvIHbDoWxpZG8uJywgNDIyKTsgfQorICAgICAgICAgICAgJHIgPSAkdGhpcy0+
Y3VlbnRhcy0+cmVzb2x2ZXIoc3RydG9sb3dlcih0cmltKCRjb250YWN0bykpLCAkdXNlcik7Cisg
ICAgICAgICAgICBpZiAoZW1wdHkoJHJbJ29rJ10pKSB7IHJldHVybiAkcjsgfQorICAgICAgICAg
ICAgJGlkZW50aXR5ID0gJHJbJ2lkZW50aWRhZCddOyAkZXhwaXJlcyA9IHRpbWUoKSArIDYwMDsK
KyAgICAgICAgICAgICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0eV9rZXknXSA9ICRfU0VTU0lPTlsn
bWFpbF9pZGVudGl0eV9rZXknXSA/PyBiaW4yaGV4KHJhbmRvbV9ieXRlcygzMikpOworICAgICAg
ICAgICAgJHRva2VuID0gJGV4cGlyZXMgLiAnLicgLiBoYXNoX2htYWMoJ3NoYTI1NicsIGpzb25f
ZW5jb2RlKFskaWRlbnRpdHksICRleHBpcmVzXSksICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0eV9r
ZXknXSk7CisgICAgICAgICAgICByZXR1cm4gWydvaycgPT4gdHJ1ZSwgJ2lkZW50aWRhZCcgPT4g
JGlkZW50aXR5LCAnaWRlbnRpZGFkX2VudmlvJyA9PiAkdG9rZW5dOworICAgICAgICB9IGNhdGNo
IChUaHJvd2FibGUgJGUpIHsKKyAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJGUtPmdl
dE1lc3NhZ2UoKSA9PT0gJ01BSUxfQ0VOVFJBTF9GUk9NJworICAgICAgICAgICAgICAgID8gJ1R1
IGNvcnJlbyBubyBjb3JyZXNwb25kZSBhIHVuIGJ1esOzbiBIb3N0aW5nZXIgYXV0b3JpemFkby4g
RWwgYWRtaW5pc3RyYWRvciBkZWJlIGluZGljYXIgbGEgY3VlbnRhIGluc3RpdHVjaW9uYWwgZGUg
c2FsaWRhIHBhcmEgZXN0b3MgY29udGFjdG9zLicKKyAgICAgICAgICAgICAgICA6ICdObyBzZSBw
dWRvIGNvbXByb2JhciBlbCBjb3JyZW8gaW5zdGl0dWNpb25hbCBkZWwgc2lzdGVtYS4gU29saWNp
dGEgcmV2aXNhciBzdSBjb25maWd1cmFjacOzbiBwcml2YWRhIHkgYXV0b3JpemFjacOzbi4nLCA1
MDMpOworICAgICAgICB9CisgICAgfQorCisgICAgcHJpdmF0ZSBmdW5jdGlvbiBjb25maXJtYWNp
b25WYWxpZGEoYXJyYXkgJGlkZW50aXR5LCBzdHJpbmcgJHRva2VuKTogYm9vbAorICAgIHsKKyAg
ICAgICAgaWYgKCFwcmVnX21hdGNoKCcvXihbMC05XXsxMH0pXC4oW2EtZjAtOV17NjR9KSQvRCcs
ICR0b2tlbiwgJG0pIHx8IChpbnQpJG1bMV0gPCB0aW1lKCkgfHwgKGludCkkbVsxXSA+IHRpbWUo
KSArIDYwMCB8fCBlbXB0eSgkX1NFU1NJT05bJ21haWxfaWRlbnRpdHlfa2V5J10pKSB7IHJldHVy
biBmYWxzZTsgfQorICAgICAgICByZXR1cm4gaGFzaF9lcXVhbHMoaGFzaF9obWFjKCdzaGEyNTYn
LCBqc29uX2VuY29kZShbJGlkZW50aXR5LCAoaW50KSRtWzFdXSksICRfU0VTU0lPTlsnbWFpbF9p
ZGVudGl0eV9rZXknXSksICRtWzJdKTsKKyAgICB9CisKKyAgICBwdWJsaWMgZnVuY3Rpb24gY29t
cHJvYmFySWRlbnRpZGFkKHN0cmluZyAkY29udGFjdG8sIGludCAkdXNlciwgc3RyaW5nICR0b2tl
bik6IGFycmF5CisgICAgeworICAgICAgICAkciA9ICR0aGlzLT5pZGVudGlkYWQoJGNvbnRhY3Rv
LCAkdXNlcik7CisgICAgICAgIGlmIChlbXB0eSgkclsnb2snXSkpIHsgcmV0dXJuICRyOyB9Cisg
ICAgICAgIHJldHVybiAkdGhpcy0+Y29uZmlybWFjaW9uVmFsaWRhKCRyWydpZGVudGlkYWQnXSwg
JHRva2VuKSA/IFsnb2snID0+IHRydWUsICdpZGVudGlkYWQnID0+ICRyWydpZGVudGlkYWQnXV0K
KyAgICAgICAgICAgIDogJHRoaXMtPmVycm9yKCdSZXZpc2EgZWwgcmVtaXRlbnRlIGluc3RpdHVj
aW9uYWwgdmlzaWJsZSBhbnRlcyBkZSBlbnZpYXIuJywgNDIyKTsKICAgICB9CiAKICAgICBwcml2
YXRlIGZ1bmN0aW9uIGVudmlhckhvc3RpbmdlcigKQEAgLTMzMCw3ICsyNTYsNiBAQAogICAgICAg
ICBpZiAoJHJlc291cmNlSWQgPT09ICcnKSB7CiAgICAgICAgICAgICByZXR1cm4gWwogICAgICAg
ICAgICAgICAgICdvaycgPT4gZmFsc2UsCi0gICAgICAgICAgICAgICAgJ2ZhbGxiYWNrX3NtdHAn
ID0+IHRydWUsCiAgICAgICAgICAgICAgICAgJ21lbnNhamUnID0+ICR1c2FyUmVtaXRlbnRlRXhh
Y3RvCiAgICAgICAgICAgICAgICAgICAgID8gJ0VsIGNvcnJlbyBkZSBvcmlnZW4gbm8gZXN0w6Eg
YXV0b3JpemFkbyBjb21vIGN1ZW50YSBkZSBzYWxpZGEuIFVzYSB1bmEgY3VlbnRhIGNvbmZpZ3Vy
YWRhIGVuIGVsIHNpc3RlbWEgbyB1biBidXrDs24gYXV0b3JpemFkbyBkZSBIb3N0aW5nZXIuJwog
ICAgICAgICAgICAgICAgICAgICA6ICdObyBoYXkgbmluZ8O6biBidXrDs24gZGUgc2FsaWRhIGF1
dG9yaXphZG8gZGlzcG9uaWJsZS4nCkBAIC00MjEsMTUgKzM0NiwyMCBAQAogICAgICAgICApOwog
CiAgICAgICAgIGlmICghKCRyZXNwdWVzdGFbJ29rJ10gPz8gZmFsc2UpKSB7Ci0gICAgICAgICAg
ICByZXR1cm4gJHRoaXMtPmVycm9yKAotICAgICAgICAgICAgICAgICdObyBmdWUgcG9zaWJsZSBl
bnZpYXIgZWwgY29ycmVvIGluc3RpdHVjaW9uYWwuJywKLSAgICAgICAgICAgICAgICAoaW50KSgk
cmVzcHVlc3RhWydjb2RpZ29faHR0cCddID8/IDUwMikKLSAgICAgICAgICAgICk7CisgICAgICAg
ICAgICAkY29kZSA9IChpbnQpKCRyZXNwdWVzdGFbJ2NvZGlnb19odHRwJ10gPz8gNTAyKTsKKyAg
ICAgICAgICAgICR1bmtub3duID0gIWVtcHR5KCRyZXNwdWVzdGFbJ2luY2llcnRvJ10pIHx8ICRj
b2RlID49IDUwMCB8fCAkY29kZSA8IDQwMCB8fCAkY29kZSA9PT0gNDA4OworICAgICAgICAgICAg
cmV0dXJuIFsnb2snID0+IGZhbHNlLCAnY29kaWdvX2h0dHAnID0+IDUwMiwgJ3Byb3ZlZWRvcicg
PT4gJ0hPU1RJTkdFUl9NQUlMX0FQSScsCisgICAgICAgICAgICAgICAgJ2VzdGFkb19lbnRyZWdh
JyA9PiAkdW5rbm93biA/ICdERVNDT05PQ0lETycgOiAnUkVDSEFaQURPJywgJ2VudHJlZ2FfY29u
ZmlybWFkYScgPT4gZmFsc2UsCisgICAgICAgICAgICAgICAgJ21lbnNhamUnID0+ICR1bmtub3du
CisgICAgICAgICAgICAgICAgICAgID8gJ0xhIEFQSSBubyBjb25maXJtw7MgZWwgcmVzdWx0YWRv
LiBDb21wcnVlYmEgZWwgYnV6w7NuIHkgZWwgaGlzdG9yaWFsIGFudGVzIGRlIHJlcGV0aXIgZWwg
ZW52w61vLicKKyAgICAgICAgICAgICAgICAgICAgOiAnSG9zdGluZ2VyIHJlY2hhesOzIGVsIGVu
dsOtby4gUmV2aXNhIGxhIGF1dG9yaXphY2nDs24gZGVsIGJ1esOzbiB5IGxhIGNvbmZpZ3VyYWNp
w7NuIGRlIGxhIEFQSS4nXTsKICAgICAgICAgfQogCiAgICAgICAgIHJldHVybiBbCiAgICAgICAg
ICAgICAnb2snID0+IHRydWUsCiAgICAgICAgICAgICAncHJvdmVlZG9yJyA9PiAnSE9TVElOR0VS
X01BSUxfQVBJJywKKyAgICAgICAgICAgICdlc3RhZG9fZW50cmVnYScgPT4gJ0FDRVBUQURPJywK
KyAgICAgICAgICAgICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+IGZhbHNlLAogICAgICAgICAgICAg
J2N1ZW50YV9zYWxpZGEnID0+ICRkaXJlY2Npb25TYWxpZGEsCiAgICAgICAgICAgICAnY29ycmVv
X3VzdWFyaW8nID0+ICRyZW1pdGVudGUsCiAgICAgICAgICAgICAnZmlybWFfaW5jbHVpZGEnID0+
ICRmaXJtYURpc3BvbmlibGUsCkBAIC00NjEsODMgKzM5MSwxNCBAQAogICAgICAgICB9CiAgICAg
ICAgIHJlcXVpcmVfb25jZSAkYXV0b2xvYWQ7CiAKLSAgICAgICAgJGFyY2hpdm9Db25maWcgPSAk
dGhpcy0+cm9vdFBhdGggLiBESVJFQ1RPUllfU0VQQVJBVE9SIC4gJ2NvbmZpZycgLiBESVJFQ1RP
UllfU0VQQVJBVE9SIC4gJ21haWxfY29uZmlnLnBocCc7Ci0gICAgICAgIGlmIChpc19maWxlKCRh
cmNoaXZvQ29uZmlnKSkgewotICAgICAgICAgICAgcmVxdWlyZV9vbmNlICRhcmNoaXZvQ29uZmln
OwotICAgICAgICB9Ci0KLSAgICAgICAgaWYgKGlzX2FycmF5KCRjb25maWdVc3VhcmlvKSkgewot
ICAgICAgICAgICAgJGhvc3QgPSB0cmltKChzdHJpbmcpKCRjb25maWdVc3VhcmlvWydzbXRwX2hv
c3QnXSA/PyAnJykpOwotICAgICAgICAgICAgJHB1ZXJ0byA9IChpbnQpKCRjb25maWdVc3Vhcmlv
WydzbXRwX3BvcnQnXSA/PyA1ODcpOwotICAgICAgICAgICAgJHVzdWFyaW8gPSB0cmltKChzdHJp
bmcpKCRjb25maWdVc3VhcmlvWydzbXRwX3VzZXJuYW1lJ10gPz8gJycpKTsKLSAgICAgICAgICAg
ICRwYXNzd29yZCA9IChzdHJpbmcpKCRjb25maWdVc3VhcmlvWydzbXRwX3Bhc3N3b3JkJ10gPz8g
JycpOwotICAgICAgICAgICAgJGVuY3JpcHRhY2lvbiA9IHN0cnRvbG93ZXIoCi0gICAgICAgICAg
ICAgICAgdHJpbSgoc3RyaW5nKSgkY29uZmlnVXN1YXJpb1snc210cF9lbmNyeXB0aW9uJ10gPz8g
J3RscycpKQotICAgICAgICAgICAgKTsKLSAgICAgICAgICAgICRmcm9tID0gc3RydG9sb3dlcigK
LSAgICAgICAgICAgICAgICB0cmltKChzdHJpbmcpKCRjb25maWdVc3VhcmlvWydjb3JyZW8nXSA/
PyAkdXN1YXJpbykpCi0gICAgICAgICAgICApOwotICAgICAgICAgICAgJGZyb21OYW1lID0gJG5v
bWJyZVJlbWl0ZW50ZSAhPT0gJycKLSAgICAgICAgICAgICAgICA/ICRub21icmVSZW1pdGVudGUK
LSAgICAgICAgICAgICAgICA6ICRmcm9tOwotICAgICAgICB9IGVsc2UgewotICAgICAgICAgICAg
JGhvc3QgPSAkdGhpcy0+Y29uZmlnKCdNQUlMX0hPU1QnKTsKLSAgICAgICAgICAgICRwdWVydG8g
PSAoaW50KSR0aGlzLT5jb25maWcoJ01BSUxfUE9SVCcsICc1ODcnKTsKLSAgICAgICAgICAgICR1
c3VhcmlvID0gJHRoaXMtPmNvbmZpZygnTUFJTF9VU0VSTkFNRScpOwotICAgICAgICAgICAgJHBh
c3N3b3JkID0gJHRoaXMtPmNvbmZpZygnTUFJTF9QQVNTV09SRCcpOwotICAgICAgICAgICAgJGVu
Y3JpcHRhY2lvbiA9IHN0cnRvbG93ZXIoCi0gICAgICAgICAgICAgICAgJHRoaXMtPmNvbmZpZygn
TUFJTF9FTkNSWVBUSU9OJywgJ3RscycpCi0gICAgICAgICAgICApOwotICAgICAgICAgICAgJGZy
b20gPSAkdGhpcy0+Y29uZmlnKCdNQUlMX0ZST01fQUREUkVTUycsICR1c3VhcmlvKTsKLSAgICAg
ICAgICAgICRmcm9tTmFtZSA9ICR0aGlzLT5jb25maWcoCi0gICAgICAgICAgICAgICAgJ01BSUxf
RlJPTV9OQU1FJywKLSAgICAgICAgICAgICAgICAkbm9tYnJlUmVtaXRlbnRlICE9PSAnJwotICAg
ICAgICAgICAgICAgICAgICA/ICRub21icmVSZW1pdGVudGUKLSAgICAgICAgICAgICAgICAgICAg
OiAnRnVuZGFjacOzbiBSZWQgRWR1Y2F0aXZhIE3DqXhpY28nCi0gICAgICAgICAgICApOwotICAg
ICAgICB9Ci0KLSAgICAgICAgaWYgKCRob3N0ID09PSAnJyB8fCAkcHVlcnRvIDw9IDAgfHwgJGZy
b20gPT09ICcnIHx8ICFmaWx0ZXJfdmFyKCRmcm9tLCBGSUxURVJfVkFMSURBVEVfRU1BSUwpKSB7
Ci0gICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKAotICAgICAgICAgICAgICAgICdMYSBj
dWVudGEgZGUgc2FsaWRhIGRlbCBzaXN0ZW1hIG5vIGVzdMOhIGRpc3BvbmlibGUuIENvbnRhY3Rh
IGFsIGFkbWluaXN0cmFkb3IgcGFyYSByZXZpc2FyIGxhIGNvbmZpZ3VyYWNpw7NuIGRlbCBzZXJ2
aWNpbyBkZSBjb3JyZW8uJywKLSAgICAgICAgICAgICAgICA1MDAKLSAgICAgICAgICAgICk7Ci0g
ICAgICAgIH0KLQogICAgICAgICB0cnkgewotICAgICAgICAgICAgJG1haWwgPSBuZXcgXFBIUE1h
aWxlclxQSFBNYWlsZXJcUEhQTWFpbGVyKHRydWUpOwotICAgICAgICAgICAgJG1haWwtPkNoYXJT
ZXQgPSAnVVRGLTgnOwotICAgICAgICAgICAgJG1haWwtPmlzU01UUCgpOwotICAgICAgICAgICAg
JG1haWwtPkhvc3QgPSAkaG9zdDsKLSAgICAgICAgICAgICRtYWlsLT5Qb3J0ID0gJHB1ZXJ0bzsK
LSAgICAgICAgICAgICRtYWlsLT5TTVRQQXV0aCA9ICR1c3VhcmlvICE9PSAnJzsKLSAgICAgICAg
ICAgIGlmICgkdXN1YXJpbyAhPT0gJycpIHsKLSAgICAgICAgICAgICAgICAkbWFpbC0+VXNlcm5h
bWUgPSAkdXN1YXJpbzsKLSAgICAgICAgICAgICAgICAkbWFpbC0+UGFzc3dvcmQgPSAkcGFzc3dv
cmQ7Ci0gICAgICAgICAgICB9Ci0gICAgICAgICAgICBpZiAoJGVuY3JpcHRhY2lvbiA9PT0gJ3Nz
bCcpIHsKLSAgICAgICAgICAgICAgICAkbWFpbC0+U01UUFNlY3VyZSA9IFxQSFBNYWlsZXJcUEhQ
TWFpbGVyXFBIUE1haWxlcjo6RU5DUllQVElPTl9TTVRQUzsKLSAgICAgICAgICAgIH0gZWxzZWlm
ICgkZW5jcmlwdGFjaW9uID09PSAndGxzJykgewotICAgICAgICAgICAgICAgICRtYWlsLT5TTVRQ
U2VjdXJlID0gXFBIUE1haWxlclxQSFBNYWlsZXJcUEhQTWFpbGVyOjpFTkNSWVBUSU9OX1NUQVJU
VExTOwotICAgICAgICAgICAgfSBlbHNlIHsKLSAgICAgICAgICAgICAgICAkbWFpbC0+U01UUEF1
dG9UTFMgPSBmYWxzZTsKLSAgICAgICAgICAgICAgICAkbWFpbC0+U01UUFNlY3VyZSA9IGZhbHNl
OwotICAgICAgICAgICAgfQotCi0gICAgICAgICAgICAkbWFpbC0+c2V0RnJvbSgkZnJvbSwgJGZy
b21OYW1lKTsKKyAgICAgICAgICAgICRjb25maWdVc3VhcmlvID0gaXNfYXJyYXkoJGNvbmZpZ1Vz
dWFyaW8pID8gJGNvbmZpZ1VzdWFyaW8gOiBbXTsKKyAgICAgICAgICAgICRtYWlsID0gJHRoaXMt
Pm1haWxlckZhY3RvcnkgPyAoJHRoaXMtPm1haWxlckZhY3RvcnkpKCkgOiBuZXcgXFBIUE1haWxl
clxQSFBNYWlsZXJcUEhQTWFpbGVyKHRydWUpOworICAgICAgICAgICAgaWYgKCgkY29uZmlnVXN1
YXJpb1sndHJhbnNwb3J0J10gPz8gJycpID09PSAnZ21haWxfYXBpJykgeyAkbWFpbC0+Q2hhclNl
dCA9ICdVVEYtOCc7IH0KKyAgICAgICAgICAgIGVsc2UgeyBDb3JyZW9TbXRwVHJhbnNwb3J0Ojpj
b25maWd1cmFyKCRtYWlsLCAkY29uZmlnVXN1YXJpbyk7IH0KKyAgICAgICAgICAgICRtYWlsLT5z
ZXRGcm9tKCRyZW1pdGVudGVVc3VhcmlvLCAkbm9tYnJlUmVtaXRlbnRlICE9PSAnJyA/ICRub21i
cmVSZW1pdGVudGUgOiAkcmVtaXRlbnRlVXN1YXJpbyk7CisgICAgICAgICAgICBpZiAoIWVtcHR5
KCRjb25maWdVc3VhcmlvWydyZXBseV90byddKSkgeyAkbWFpbC0+YWRkUmVwbHlUbygkY29uZmln
VXN1YXJpb1sncmVwbHlfdG8nXSk7IH0KICAgICAgICAgICAgICRtYWlsLT5hZGRBZGRyZXNzKCRk
ZXN0aW5hdGFyaW8sICRub21icmVEZXN0aW5hdGFyaW8pOwotICAgICAgICAgICAgaWYgKAotICAg
ICAgICAgICAgICAgICRyZW1pdGVudGVVc3VhcmlvICE9PSAnJyAmJgotICAgICAgICAgICAgICAg
IHN0cmNhc2VjbXAoJHJlbWl0ZW50ZVVzdWFyaW8sICRmcm9tKSAhPT0gMAotICAgICAgICAgICAg
KSB7Ci0gICAgICAgICAgICAgICAgJG1haWwtPmFkZFJlcGx5VG8oCi0gICAgICAgICAgICAgICAg
ICAgICRyZW1pdGVudGVVc3VhcmlvLAotICAgICAgICAgICAgICAgICAgICAkbm9tYnJlUmVtaXRl
bnRlICE9PSAnJwotICAgICAgICAgICAgICAgICAgICAgICAgPyAkbm9tYnJlUmVtaXRlbnRlCi0g
ICAgICAgICAgICAgICAgICAgICAgICA6ICRyZW1pdGVudGVVc3VhcmlvCi0gICAgICAgICAgICAg
ICAgKTsKLSAgICAgICAgICAgIH0KLQogICAgICAgICAgICAgJGZpcm1hRGlzcG9uaWJsZSA9ICRy
dXRhRmlybWEgIT09ICcnICYmIGlzX2ZpbGUoJHJ1dGFGaXJtYSk7CiAgICAgICAgICAgICBpZiAo
JGZpcm1hRGlzcG9uaWJsZSkgewogICAgICAgICAgICAgICAgICRtYWlsLT5hZGRFbWJlZGRlZElt
YWdlKApAQCAtNTc2LDE3ICs0MzcsMTcgQEAKICAgICAgICAgICAgICAgICAkaHRtbEFkaWNpb25h
bAogICAgICAgICAgICAgKTsKICAgICAgICAgICAgICRtYWlsLT5BbHRCb2R5ID0gJGN1ZXJwbzsK
LSAgICAgICAgICAgICRtYWlsLT5zZW5kKCk7Ci0KLSAgICAgICAgICAgIHJldHVybiBbCi0gICAg
ICAgICAgICAgICAgJ29rJyA9PiB0cnVlLAotICAgICAgICAgICAgICAgICdwcm92ZWVkb3InID0+
ICdTTVRQJywKKyAgICAgICAgICAgICRvdXRjb21lID0gKCRjb25maWdVc3VhcmlvWyd0cmFuc3Bv
cnQnXSA/PyAnJykgPT09ICdnbWFpbF9hcGknCisgICAgICAgICAgICAgICAgPyBDb3JyZW9HbWFp
bEFwaVRyYW5zcG9ydDo6ZW52aWFyKCRtYWlsLCAkY29uZmlnVXN1YXJpbywgJHRoaXMtPmFwaVJl
cXVlc3QpCisgICAgICAgICAgICAgICAgOiBDb3JyZW9TbXRwVHJhbnNwb3J0OjplbnZpYXIoJG1h
aWwsICdjb21tZXJjaWFsJyk7CisgICAgICAgICAgICBpZiAoZW1wdHkoJG91dGNvbWVbJ29rJ10p
KSB7IHJldHVybiAkb3V0Y29tZTsgfQorICAgICAgICAgICAgcmV0dXJuICRvdXRjb21lICsgWwog
ICAgICAgICAgICAgICAgICdmaXJtYV9pbmNsdWlkYScgPT4gJGZpcm1hRGlzcG9uaWJsZSwKICAg
ICAgICAgICAgICAgICAnaW1hZ2VuZXNfZW1iZWJpZGFzJyA9PiBjb3VudCgkaW1hZ2VuZXNFbWJl
YmlkYXMpLAogICAgICAgICAgICAgICAgICdhZGp1bnRvcycgPT4gY291bnQoJGFkanVudG9zKQog
ICAgICAgICAgICAgXTsKICAgICAgICAgfSBjYXRjaCAoVGhyb3dhYmxlICRlcnJvcikgewotICAg
ICAgICAgICAgZXJyb3JfbG9nKCdDb3JyZW8gaW5zdGl0dWNpb25hbCBTTVRQOiAnIC4gJGVycm9y
LT5nZXRNZXNzYWdlKCkpOworICAgICAgICAgICAgZXJyb3JfbG9nKCdNQUlMX1RSQU5TUE9SVCAn
IC4ganNvbl9lbmNvZGUoWydwaGFzZScgPT4gJ3NtdHBfY29uZmlndXJhdGlvbicsICdleGNlcHRp
b24nID0+IGdldF9jbGFzcygkZXJyb3IpXSkpOwogICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5l
cnJvcignTm8gZnVlIHBvc2libGUgZW52aWFyIGVsIGNvcnJlbyBpbnN0aXR1Y2lvbmFsLicsIDUw
Mik7CiAgICAgICAgIH0KICAgICB9CkBAIC03NzgsNiArNjM5LDcgQEAKIAogICAgIHByaXZhdGUg
ZnVuY3Rpb24gc29saWNpdGFySG9zdGluZ2VyKCRtZXRvZG8sICR1cmwsICR0b2tlbiwgJHBheWxv
YWQpCiAgICAgeworICAgICAgICBpZiAoJHRoaXMtPmFwaVJlcXVlc3QpIHsgcmV0dXJuICgkdGhp
cy0+YXBpUmVxdWVzdCkoJG1ldG9kbywgJHVybCwgJHRva2VuLCAkcGF5bG9hZCk7IH0KICAgICAg
ICAgJGN1cmwgPSBjdXJsX2luaXQoJHVybCk7CiAgICAgICAgICRoZWFkZXJzID0gWwogICAgICAg
ICAgICAgJ0FjY2VwdDogYXBwbGljYXRpb24vanNvbicsCkBAIC03ODgsNiArNjUwLDkgQEAKICAg
ICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfQ1VTVE9NUkVRVUVTVCwgc3RydG91cHBl
cigoc3RyaW5nKSRtZXRvZG8pKTsKICAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRf
Q09OTkVDVFRJTUVPVVQsIDEwKTsKICAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRf
VElNRU9VVCwgMzApOworICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9TU0xfVkVS
SUZZUEVFUiwgdHJ1ZSk7CisgICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX1NTTF9W
RVJJRllIT1NULCAyKTsKKyAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfRk9MTE9X
TE9DQVRJT04sIGZhbHNlKTsKIAogICAgICAgICBpZiAoaXNfYXJyYXkoJHBheWxvYWQpKSB7CiAg
ICAgICAgICAgICAkanNvbiA9IGpzb25fZW5jb2RlKCRwYXlsb2FkLCBKU09OX1VORVNDQVBFRF9V
TklDT0RFIHwgSlNPTl9VTkVTQ0FQRURfU0xBU0hFUyk7CkBAIC04MDksMTUgKzY3NCwxNSBAQAog
ICAgICAgICBjdXJsX2Nsb3NlKCRjdXJsKTsKIAogICAgICAgICBpZiAoJHJlc3B1ZXN0YSA9PT0g
ZmFsc2UgfHwgJGVycm9yICE9PSAnJykgewotICAgICAgICAgICAgZXJyb3JfbG9nKCdIb3N0aW5n
ZXIgTWFpbCBBUEk6ICcgLiAkZXJyb3IpOwotICAgICAgICAgICAgcmV0dXJuIFsnb2snID0+IGZh
bHNlLCAnY29kaWdvX2h0dHAnID0+IDUwMl07CisgICAgICAgICAgICBlcnJvcl9sb2coJ01BSUxf
VFJBTlNQT1JUIGhvc3Rpbmdlcl9jb25uZWN0aW9uX2ZhaWxlZCcpOworICAgICAgICAgICAgcmV0
dXJuIFsnb2snID0+IGZhbHNlLCAnY29kaWdvX2h0dHAnID0+IDUwMiwgJ2luY2llcnRvJyA9PiBz
dHJ0b3VwcGVyKCRtZXRvZG8pID09PSAnUE9TVCddOwogICAgICAgICB9CiAKICAgICAgICAgJGpz
b25SZXNwdWVzdGEgPSBqc29uX2RlY29kZSgoc3RyaW5nKSRyZXNwdWVzdGEsIHRydWUpOwogICAg
ICAgICAkb2sgPSAkY29kaWdvID49IDIwMCAmJiAkY29kaWdvIDwgMzAwOwogCiAgICAgICAgIGlm
ICghJG9rKSB7Ci0gICAgICAgICAgICBlcnJvcl9sb2coJ0hvc3RpbmdlciBNYWlsIEFQSSBIVFRQ
ICcgLiAkY29kaWdvIC4gJzogJyAuIChzdHJpbmcpJHJlc3B1ZXN0YSk7CisgICAgICAgICAgICBl
cnJvcl9sb2coJ01BSUxfVFJBTlNQT1JUIGhvc3Rpbmdlcl9odHRwPScgLiAkY29kaWdvKTsKICAg
ICAgICAgfQogCiAgICAgICAgIHJldHVybiBbCg==
CENTRAL_PATCH_2_3
 [[ "$(digest "$TMP/patches/2/3.patch")" == '532508399444342bd06d2489005f9efde62bab64cdcb525a621958e65461453e' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/3"
 base64 --decode > "$TMP/patches/3/1.patch" <<'CENTRAL_PATCH_3_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocCBiL2Fw
cC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocAotLS0gYS9hcHAvc2VydmljZXMv
Q29ycmVvTWFya2V0aW5nU2VydmljZS5waHAKKysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb01hcmtl
dGluZ1NlcnZpY2UucGhwCkBAIC0zLDYgKzMsNyBAQAogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAn
Ly4uLy4uL2NvbmZpZy9kYl9jb25uZWN0aW9uLnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAu
ICcvLi4vbW9kZWxzL1VzdWFyaW9Nb2RlbC5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAn
L0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocCc7CityZXF1aXJlX29uY2UgX19E
SVJfXyAuICcvQ29ycmVvUmVnaXN0cm9Fc3RhZG9TZXJ2aWNlLnBocCc7CiAKIGNsYXNzIENvcnJl
b01hcmtldGluZ1NlcnZpY2UKIHsKQEAgLTkwLDYgKzkxLDcgQEAKICAgICAgICAgICAgIH0KIAog
ICAgICAgICAgICAgJHRpbWVzdGFtcCA9ICRmZWNoYSAhPT0gJycgPyBzdHJ0b3RpbWUoJGZlY2hh
KSA6IGZhbHNlOworICAgICAgICAgICAgJHRyYW5zcG9ydFN0YXRlID0gQ29ycmVvUmVnaXN0cm9F
c3RhZG9TZXJ2aWNlOjpjb25zdWx0YXIoJHVzdWFyaW9JZCwgKGludCkkZmlsYVsnaWQnXSwgKHN0
cmluZykkZmlsYVsnZXN0YWRvJ10pOwogCiAgICAgICAgICAgICAkc2FsaWRhW10gPSBbCiAgICAg
ICAgICAgICAgICAgJ2lkJyA9PiAoaW50KSgkZmlsYVsnaWQnXSA/PyAwKSwKQEAgLTEwMSw3ICsx
MDMsMTAgQEAKICAgICAgICAgICAgICAgICAndGlwbycgPT4gJHRoaXMtPmV0aXF1ZXRhVGlwbygk
ZmlsYVsndGlwbyddID8/ICcnKSwKICAgICAgICAgICAgICAgICAndGlwb19jb2RpZ28nID0+IHN0
cnRvbG93ZXIoKHN0cmluZykoJGZpbGFbJ3RpcG8nXSA/PyAnZ2VuZXJhbCcpKSwKICAgICAgICAg
ICAgICAgICAnZmVjaGEnID0+ICR0aW1lc3RhbXAgIT09IGZhbHNlID8gZGF0ZSgnZC9tL1kgSDpp
JywgJHRpbWVzdGFtcCkgOiAn4oCUJywKLSAgICAgICAgICAgICAgICAnZXN0YWRvJyA9PiAkdGhp
cy0+ZXRpcXVldGFFc3RhZG8oJGZpbGFbJ2VzdGFkbyddID8/ICcnKSwKKyAgICAgICAgICAgICAg
ICAnZXN0YWRvJyA9PiAkdHJhbnNwb3J0U3RhdGVbJ2V0aXF1ZXRhX2VudmlvJ10gPz8gJHRoaXMt
PmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydlc3RhZG8nXSA/PyAnJyksCisgICAgICAgICAgICAgICAg
J2VzdGFkb19lbnZpbycgPT4gJHRyYW5zcG9ydFN0YXRlWydlc3RhZG9fZW52aW8nXSwKKyAgICAg
ICAgICAgICAgICAncmVtaXRlbnRlX3Zpc2libGUnID0+ICR0cmFuc3BvcnRTdGF0ZVsncmVtaXRl
bnRlX3Zpc2libGUnXSwgJ3JlcGx5X3RvJyA9PiAkdHJhbnNwb3J0U3RhdGVbJ3JlcGx5X3RvJ10s
CisgICAgICAgICAgICAgICAgJ2VudHJlZ2FfY29uZmlybWFkYScgPT4gZmFsc2UsCiAgICAgICAg
ICAgICAgICAgJ2VzdGFkb19jb2RpZ28nID0+IHN0cnRvbG93ZXIoKHN0cmluZykoJGZpbGFbJ2Vz
dGFkbyddID8/ICdwZW5kaWVudGUnKSksCiAgICAgICAgICAgICAgICAgJ2FkanVudG9zX2NvdW50
JyA9PiAoaW50KSgkZmlsYVsnYWRqdW50b3NfY291bnQnXSA/PyAwKQogICAgICAgICAgICAgXTsK
QEAgLTIxMiw2ICsyMTcsNyBAQAogICAgICAgICAgICAgXTsKICAgICAgICAgfQogCisgICAgICAg
ICR0cmFuc3BvcnRTdGF0ZSA9IENvcnJlb1JlZ2lzdHJvRXN0YWRvU2VydmljZTo6Y29uc3VsdGFy
KCR1c3VhcmlvSWQsIChpbnQpJGZpbGFbJ2lkJ10sIChzdHJpbmcpJGZpbGFbJ2VzdGFkbyddKTsK
ICAgICAgICAgcmV0dXJuIFsKICAgICAgICAgICAgICdpZCcgPT4gKGludCkkZmlsYVsnaWQnXSwK
ICAgICAgICAgICAgICdkZXN0aW5hdGFyaW8nID0+IChzdHJpbmcpKCRmaWxhWydkZXN0aW5hdGFy
aW8nXSA/PyAnJyksCkBAIC0yMjAsNyArMjI2LDExIEBACiAgICAgICAgICAgICAnYXN1bnRvJyA9
PiAoc3RyaW5nKSgkZmlsYVsnYXN1bnRvJ10gPz8gJycpLAogICAgICAgICAgICAgJ2N1ZXJwbycg
PT4gKHN0cmluZykoJGZpbGFbJ2N1ZXJwbyddID8/ICcnKSwKICAgICAgICAgICAgICd0aXBvJyA9
PiAkdGhpcy0+ZXRpcXVldGFUaXBvKCRmaWxhWyd0aXBvJ10gPz8gJycpLAotICAgICAgICAgICAg
J2VzdGFkbycgPT4gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydlc3RhZG8nXSA/PyAnJyks
CisgICAgICAgICAgICAnZXN0YWRvJyA9PiAkdHJhbnNwb3J0U3RhdGVbJ2V0aXF1ZXRhX2Vudmlv
J10gPz8gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydlc3RhZG8nXSA/PyAnJyksCisgICAg
ICAgICAgICAnZXN0YWRvX2VudmlvJyA9PiAkdHJhbnNwb3J0U3RhdGVbJ2VzdGFkb19lbnZpbydd
LAorICAgICAgICAgICAgJ2VudHJlZ2FfY29uZmlybWFkYScgPT4gZmFsc2UsCisgICAgICAgICAg
ICAncmVnaXN0cm9fY29uZmlybWFkbycgPT4gJHRyYW5zcG9ydFN0YXRlWydyZWdpc3Ryb19jb25m
aXJtYWRvJ10sCisgICAgICAgICAgICAncmVtaXRlbnRlX3Zpc2libGUnID0+ICR0cmFuc3BvcnRT
dGF0ZVsncmVtaXRlbnRlX3Zpc2libGUnXSwgJ3JlcGx5X3RvJyA9PiAkdHJhbnNwb3J0U3RhdGVb
J3JlcGx5X3RvJ10sCiAgICAgICAgICAgICAnZXN0YWRvX2NvZGlnbycgPT4gc3RydG9sb3dlcigK
ICAgICAgICAgICAgICAgICB0cmltKChzdHJpbmcpKCRmaWxhWydlc3RhZG8nXSA/PyAncGVuZGll
bnRlJykpCiAgICAgICAgICAgICApLApAQCAtNzMwLDYgKzc0MCw5IEBACiAgICAgICAgICAgICAg
ICAgNDIyCiAgICAgICAgICAgICApOwogICAgICAgICB9CisKKyAgICAgICAgJGlkZW50aWRhZCA9
ICR0aGlzLT5zZW5kZXItPmNvbXByb2JhcklkZW50aWRhZCgkcmVtaXRlbnRlLCAkdXN1YXJpb0lk
LCAoc3RyaW5nKSgkZGF0b3NbJ2lkZW50aWRhZF9lbnZpbyddID8/ICcnKSk7CisgICAgICAgIGlm
IChlbXB0eSgkaWRlbnRpZGFkWydvayddKSkgeyByZXR1cm4gJGlkZW50aWRhZDsgfQogCiAgICAg
ICAgICRwcmVwYXJhY2lvbiA9ICR0aGlzLT5wcmVwYXJhckFkanVudG9zKCRhcmNoaXZvcyk7CiAg
ICAgICAgIGlmICghKCRwcmVwYXJhY2lvblsnb2snXSA/PyBmYWxzZSkpIHsKQEAgLTc4Niw5ICs3
OTksMTQgQEAKICAgICAgICAgICAgICAgICB9CiAgICAgICAgICAgICB9CiAKKyAgICAgICAgICAg
IGlmICgkcmVnaXN0cm9JZCA8PSAwKSB7IHJldHVybiAkdGhpcy0+ZXJyb3IoJ05vIHNlIHB1ZG8g
cmVnaXN0cmFyIGxhIHNvbGljaXR1ZCBlbiBsYSBiYW5kZWphLiBFbCBjb3JyZW8gbm8gc2UgaGEg
ZW52aWFkby4nLCA1MDMpOyB9CisgICAgICAgICAgICBDb3JyZW9SZWdpc3Ryb0VzdGFkb1NlcnZp
Y2U6Omd1YXJkYXIoJHVzdWFyaW9JZCwgJHJlZ2lzdHJvSWQsICdQRU5ESUVOVEUnKTsKKyAgICAg
ICAgICAgIENvcnJlb1JlZ2lzdHJvRXN0YWRvU2VydmljZTo6Z3VhcmRhcigkdXN1YXJpb0lkLCAk
cmVnaXN0cm9JZCwgJ0VOX1BST0NFU08nKTsKICAgICAgICAgICAgICRlbnZpbyA9ICR0aGlzLT5z
ZW5kZXItPmVudmlhcihbCiAgICAgICAgICAgICAgICAgJ3VzdWFyaW9faWQnID0+ICR1c3Vhcmlv
SWQsCiAgICAgICAgICAgICAgICAgJ3BlcmZpbF9tYXJrZXRpbmcnID0+IHRydWUsCisgICAgICAg
ICAgICAgICAgJ2NvbmZpcm1hcl9pZGVudGlkYWQnID0+IHRydWUsCisgICAgICAgICAgICAgICAg
J2lkZW50aWRhZF9lbnZpbycgPT4gKHN0cmluZykoJGRhdG9zWydpZGVudGlkYWRfZW52aW8nXSA/
PyAnJyksCiAgICAgICAgICAgICAgICAgJ2lkZW1wb3RlbmN5X2tleScgPT4gJGRhdG9zWydpZGVt
cG90ZW5jeV9rZXknXSwKICAgICAgICAgICAgICAgICAndXNhcl9yZW1pdGVudGVfZXhhY3RvJyA9
PiB0cnVlLAogICAgICAgICAgICAgICAgICdyZW1pdGVudGUnID0+ICRyZW1pdGVudGUsCkBAIC04
MDMsNiArODIxLDExIEBACiAgICAgICAgICAgICAgICAgJ2FkanVudG9zJyA9PiAkYWRqdW50b3NF
bnZpbwogICAgICAgICAgICAgXSk7CiAKKyAgICAgICAgICAgICR0cmFuc3BvcnRTdGF0dXMgPSAh
ZW1wdHkoJGVudmlvWydvayddKSA/ICdBQ0VQVEFETycgOiAoKCRlbnZpb1snZXN0YWRvX2VudHJl
Z2EnXSA/PyAnJykgPT09ICdERVNDT05PQ0lETycgPyAnREVTQ09OT0NJRE8nIDogJ0ZBTExJRE8n
KTsKKyAgICAgICAgICAgIC8vIFVuIGR1cGxpY2FkbyBhY2VwdGFkbyB0aWVuZSBvaz1mYWxzZTog
Y29uc2VydmFyIGVzYSBldmlkZW5jaWEgeSBubyB0cmF0YXJsbyBjb21vIHJlY2hhem8uCisgICAg
ICAgICAgICBpZiAoKCRlbnZpb1snZXN0YWRvX2VudHJlZ2EnXSA/PyAnJykgPT09ICdBQ0VQVEFE
TycpIHsgJHRyYW5zcG9ydFN0YXR1cyA9ICdBQ0VQVEFETyc7IH0KKyAgICAgICAgICAgIENvcnJl
b1JlZ2lzdHJvRXN0YWRvU2VydmljZTo6Z3VhcmRhcigkdXN1YXJpb0lkLCAkcmVnaXN0cm9JZCwg
JHRyYW5zcG9ydFN0YXR1cywgJGVudmlvKTsKKwogICAgICAgICAgICAgaWYgKCEoJGVudmlvWydv
ayddID8/IGZhbHNlKSkgewogICAgICAgICAgICAgICAgIGlmICgkcmVnaXN0cm9JZCA+IDApIHsK
ICAgICAgICAgICAgICAgICAgICAgJHRoaXMtPmFjdHVhbGl6YXJSZWdpc3Ryb0Vycm9yKApAQCAt
ODM3LDEzICs4NjAsMTQgQEAKIAogICAgICAgICAgICAgcmV0dXJuIFsKICAgICAgICAgICAgICAg
ICAnb2snID0+IHRydWUsCi0gICAgICAgICAgICAgICAgJ21lbnNhamUnID0+ICdFbCBwcm92ZWVk
b3IgYWNlcHTDsyBlbCBjb3JyZW8uIExhIGVudHJlZ2EgYWwgZGVzdGluYXRhcmlvIGHDum4gbm8g
ZXN0w6EgY29uZmlybWFkYS4nLAorICAgICAgICAgICAgICAgICdtZW5zYWplJyA9PiAnRWwgcHJv
dmVlZG9yIGFjZXB0w7MgZWwgY29ycmVvIGRlc2RlICcgLiAoJGVudmlvWydyZW1pdGVudGVfdmlz
aWJsZSddID8/ICdsYSBjdWVudGEgaW5zdGl0dWNpb25hbCcpIC4gJy4gTGEgZW50cmVnYSBhw7pu
IG5vIGVzdMOhIGNvbmZpcm1hZGEuJywKICAgICAgICAgICAgICAgICAnZXN0YWRvX2VudHJlZ2En
ID0+ICdBQ0VQVEFETycsCiAgICAgICAgICAgICAgICAgJ2VudHJlZ2FfY29uZmlybWFkYScgPT4g
ZmFsc2UsCiAgICAgICAgICAgICAgICAgJ2NvcnJlb19pZCcgPT4gJHJlZ2lzdHJvSWQsCiAgICAg
ICAgICAgICAgICAgJ3Byb3ZlZWRvcicgPT4gKHN0cmluZykoJGVudmlvWydwcm92ZWVkb3InXSA/
PyAnJyksCiAgICAgICAgICAgICAgICAgJ2Zpcm1hX2luY2x1aWRhJyA9PiAoYm9vbCkoJGVudmlv
WydmaXJtYV9pbmNsdWlkYSddID8/IGZhbHNlKSwKLSAgICAgICAgICAgICAgICAnYWRqdW50b3Mn
ID0+IGNvdW50KCRhZGp1bnRvc0VudmlvKQorICAgICAgICAgICAgICAgICdhZGp1bnRvcycgPT4g
Y291bnQoJGFkanVudG9zRW52aW8pLAorICAgICAgICAgICAgICAgICdyZW1pdGVudGVfdmlzaWJs
ZScgPT4gJGVudmlvWydyZW1pdGVudGVfdmlzaWJsZSddID8/ICcnLCAncmVwbHlfdG8nID0+ICRl
bnZpb1sncmVwbHlfdG8nXSA/PyAnJwogICAgICAgICAgICAgXTsKICAgICAgICAgfSBmaW5hbGx5
IHsKICAgICAgICAgICAgICR0aGlzLT5saW1waWFyVGVtcG9yYWxlcygkcnV0YXNUZW1wb3JhbGVz
KTsK
CENTRAL_PATCH_3_1
 [[ "$(digest "$TMP/patches/3/1.patch")" == '2c2be0c62efbc5033c2cfd5cbf8d0abfc458b9ad5190a227d7036d13f5906332' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/3/2.patch" <<'CENTRAL_PATCH_3_2'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocCBiL2Fw
cC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocAotLS0gYS9hcHAvc2VydmljZXMv
Q29ycmVvTWFya2V0aW5nU2VydmljZS5waHAKKysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb01hcmtl
dGluZ1NlcnZpY2UucGhwCkBAIC0xMDUsNiArMTA1LDcgQEAKICAgICAgICAgICAgICAgICAnZmVj
aGEnID0+ICR0aW1lc3RhbXAgIT09IGZhbHNlID8gZGF0ZSgnZC9tL1kgSDppJywgJHRpbWVzdGFt
cCkgOiAn4oCUJywKICAgICAgICAgICAgICAgICAnZXN0YWRvJyA9PiAkdHJhbnNwb3J0U3RhdGVb
J2V0aXF1ZXRhX2VudmlvJ10gPz8gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydlc3RhZG8n
XSA/PyAnJyksCiAgICAgICAgICAgICAgICAgJ2VzdGFkb19lbnZpbycgPT4gJHRyYW5zcG9ydFN0
YXRlWydlc3RhZG9fZW52aW8nXSwKKyAgICAgICAgICAgICAgICAncmVtaXRlbnRlX3Zpc2libGUn
ID0+ICR0cmFuc3BvcnRTdGF0ZVsncmVtaXRlbnRlX3Zpc2libGUnXSwgJ3JlcGx5X3RvJyA9PiAk
dHJhbnNwb3J0U3RhdGVbJ3JlcGx5X3RvJ10sCiAgICAgICAgICAgICAgICAgJ2VudHJlZ2FfY29u
ZmlybWFkYScgPT4gZmFsc2UsCiAgICAgICAgICAgICAgICAgJ2VzdGFkb19jb2RpZ28nID0+IHN0
cnRvbG93ZXIoKHN0cmluZykoJGZpbGFbJ2VzdGFkbyddID8/ICdwZW5kaWVudGUnKSksCiAgICAg
ICAgICAgICAgICAgJ2FkanVudG9zX2NvdW50JyA9PiAoaW50KSgkZmlsYVsnYWRqdW50b3NfY291
bnQnXSA/PyAwKQpAQCAtMjI5LDYgKzIzMCw3IEBACiAgICAgICAgICAgICAnZXN0YWRvX2Vudmlv
JyA9PiAkdHJhbnNwb3J0U3RhdGVbJ2VzdGFkb19lbnZpbyddLAogICAgICAgICAgICAgJ2VudHJl
Z2FfY29uZmlybWFkYScgPT4gZmFsc2UsCiAgICAgICAgICAgICAncmVnaXN0cm9fY29uZmlybWFk
bycgPT4gJHRyYW5zcG9ydFN0YXRlWydyZWdpc3Ryb19jb25maXJtYWRvJ10sCisgICAgICAgICAg
ICAncmVtaXRlbnRlX3Zpc2libGUnID0+ICR0cmFuc3BvcnRTdGF0ZVsncmVtaXRlbnRlX3Zpc2li
bGUnXSwgJ3JlcGx5X3RvJyA9PiAkdHJhbnNwb3J0U3RhdGVbJ3JlcGx5X3RvJ10sCiAgICAgICAg
ICAgICAnZXN0YWRvX2NvZGlnbycgPT4gc3RydG9sb3dlcigKICAgICAgICAgICAgICAgICB0cmlt
KChzdHJpbmcpKCRmaWxhWydlc3RhZG8nXSA/PyAncGVuZGllbnRlJykpCiAgICAgICAgICAgICAp
LApAQCAtNzM4LDYgKzc0MCw5IEBACiAgICAgICAgICAgICAgICAgNDIyCiAgICAgICAgICAgICAp
OwogICAgICAgICB9CisKKyAgICAgICAgJGlkZW50aWRhZCA9ICR0aGlzLT5zZW5kZXItPmNvbXBy
b2JhcklkZW50aWRhZCgkcmVtaXRlbnRlLCAkdXN1YXJpb0lkLCAoc3RyaW5nKSgkZGF0b3NbJ2lk
ZW50aWRhZF9lbnZpbyddID8/ICcnKSk7CisgICAgICAgIGlmIChlbXB0eSgkaWRlbnRpZGFkWydv
ayddKSkgeyByZXR1cm4gJGlkZW50aWRhZDsgfQogCiAgICAgICAgICRwcmVwYXJhY2lvbiA9ICR0
aGlzLT5wcmVwYXJhckFkanVudG9zKCRhcmNoaXZvcyk7CiAgICAgICAgIGlmICghKCRwcmVwYXJh
Y2lvblsnb2snXSA/PyBmYWxzZSkpIHsKQEAgLTgwMCw2ICs4MDUsOCBAQAogICAgICAgICAgICAg
JGVudmlvID0gJHRoaXMtPnNlbmRlci0+ZW52aWFyKFsKICAgICAgICAgICAgICAgICAndXN1YXJp
b19pZCcgPT4gJHVzdWFyaW9JZCwKICAgICAgICAgICAgICAgICAncGVyZmlsX21hcmtldGluZycg
PT4gdHJ1ZSwKKyAgICAgICAgICAgICAgICAnY29uZmlybWFyX2lkZW50aWRhZCcgPT4gdHJ1ZSwK
KyAgICAgICAgICAgICAgICAnaWRlbnRpZGFkX2VudmlvJyA9PiAoc3RyaW5nKSgkZGF0b3NbJ2lk
ZW50aWRhZF9lbnZpbyddID8/ICcnKSwKICAgICAgICAgICAgICAgICAnaWRlbXBvdGVuY3lfa2V5
JyA9PiAkZGF0b3NbJ2lkZW1wb3RlbmN5X2tleSddLAogICAgICAgICAgICAgICAgICd1c2FyX3Jl
bWl0ZW50ZV9leGFjdG8nID0+IHRydWUsCiAgICAgICAgICAgICAgICAgJ3JlbWl0ZW50ZScgPT4g
JHJlbWl0ZW50ZSwKQEAgLTg1MywxMyArODYwLDE0IEBACiAKICAgICAgICAgICAgIHJldHVybiBb
CiAgICAgICAgICAgICAgICAgJ29rJyA9PiB0cnVlLAotICAgICAgICAgICAgICAgICdtZW5zYWpl
JyA9PiAnRWwgcHJvdmVlZG9yIGFjZXB0w7MgZWwgY29ycmVvLiBMYSBlbnRyZWdhIGFsIGRlc3Rp
bmF0YXJpbyBhw7puIG5vIGVzdMOhIGNvbmZpcm1hZGEuJywKKyAgICAgICAgICAgICAgICAnbWVu
c2FqZScgPT4gJ0VsIHByb3ZlZWRvciBhY2VwdMOzIGVsIGNvcnJlbyBkZXNkZSAnIC4gKCRlbnZp
b1sncmVtaXRlbnRlX3Zpc2libGUnXSA/PyAnbGEgY3VlbnRhIGluc3RpdHVjaW9uYWwnKSAuICcu
IExhIGVudHJlZ2EgYcO6biBubyBlc3TDoSBjb25maXJtYWRhLicsCiAgICAgICAgICAgICAgICAg
J2VzdGFkb19lbnRyZWdhJyA9PiAnQUNFUFRBRE8nLAogICAgICAgICAgICAgICAgICdlbnRyZWdh
X2NvbmZpcm1hZGEnID0+IGZhbHNlLAogICAgICAgICAgICAgICAgICdjb3JyZW9faWQnID0+ICRy
ZWdpc3Ryb0lkLAogICAgICAgICAgICAgICAgICdwcm92ZWVkb3InID0+IChzdHJpbmcpKCRlbnZp
b1sncHJvdmVlZG9yJ10gPz8gJycpLAogICAgICAgICAgICAgICAgICdmaXJtYV9pbmNsdWlkYScg
PT4gKGJvb2wpKCRlbnZpb1snZmlybWFfaW5jbHVpZGEnXSA/PyBmYWxzZSksCi0gICAgICAgICAg
ICAgICAgJ2FkanVudG9zJyA9PiBjb3VudCgkYWRqdW50b3NFbnZpbykKKyAgICAgICAgICAgICAg
ICAnYWRqdW50b3MnID0+IGNvdW50KCRhZGp1bnRvc0VudmlvKSwKKyAgICAgICAgICAgICAgICAn
cmVtaXRlbnRlX3Zpc2libGUnID0+ICRlbnZpb1sncmVtaXRlbnRlX3Zpc2libGUnXSA/PyAnJywg
J3JlcGx5X3RvJyA9PiAkZW52aW9bJ3JlcGx5X3RvJ10gPz8gJycKICAgICAgICAgICAgIF07CiAg
ICAgICAgIH0gZmluYWxseSB7CiAgICAgICAgICAgICAkdGhpcy0+bGltcGlhclRlbXBvcmFsZXMo
JHJ1dGFzVGVtcG9yYWxlcyk7Cg==
CENTRAL_PATCH_3_2
 [[ "$(digest "$TMP/patches/3/2.patch")" == '0971addac8f6bb5dff5635b88098944705a9e1972aa73b4ded253d0264ac5c76' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/3/3.patch" <<'CENTRAL_PATCH_3_3'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocCBiL2Fw
cC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocAotLS0gYS9hcHAvc2VydmljZXMv
Q29ycmVvTWFya2V0aW5nU2VydmljZS5waHAKKysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb01hcmtl
dGluZ1NlcnZpY2UucGhwCkBAIC0zLDE4ICszLDIwIEBACiByZXF1aXJlX29uY2UgX19ESVJfXyAu
ICcvLi4vLi4vY29uZmlnL2RiX2Nvbm5lY3Rpb24ucGhwJzsKIHJlcXVpcmVfb25jZSBfX0RJUl9f
IC4gJy8uLi9tb2RlbHMvVXN1YXJpb01vZGVsLnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAu
ICcvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhwJzsKK3JlcXVpcmVfb25jZSBf
X0RJUl9fIC4gJy9Db3JyZW9SZWdpc3Ryb0VzdGFkb1NlcnZpY2UucGhwJzsKIAogY2xhc3MgQ29y
cmVvTWFya2V0aW5nU2VydmljZQogewogICAgIHByaXZhdGUgJGNvbm5lY3Rpb247CiAgICAgcHJp
dmF0ZSAkc2VuZGVyOwogICAgIHByaXZhdGUgJHJvb3RQYXRoOwotCi0gICAgcHVibGljIGZ1bmN0
aW9uIF9fY29uc3RydWN0KCkKLSAgICB7Ci0gICAgICAgICRkYXRhYmFzZSA9IG5ldyBEYXRhYmFz
ZSgpOwotICAgICAgICAkdGhpcy0+Y29ubmVjdGlvbiA9ICRkYXRhYmFzZS0+Y29ubmVjdCgpOwot
ICAgICAgICAkdGhpcy0+c2VuZGVyID0gbmV3IENvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2
aWNlKCk7CisgICAgcHJpdmF0ZSAkdXN1YXJpb0xvYWRlcjsKKworICAgIHB1YmxpYyBmdW5jdGlv
biBfX2NvbnN0cnVjdCgkY29ubmVjdGlvbiA9IG51bGwsID9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lv
bmFsU2VydmljZSAkc2VuZGVyID0gbnVsbCwgP2NhbGxhYmxlICR1c3VhcmlvTG9hZGVyID0gbnVs
bCkKKyAgICB7CisgICAgICAgICR0aGlzLT5jb25uZWN0aW9uID0gJGNvbm5lY3Rpb24gPz8gKG5l
dyBEYXRhYmFzZSgpKS0+Y29ubmVjdCgpOworICAgICAgICAkdGhpcy0+c2VuZGVyID0gJHNlbmRl
ciA/PyBuZXcgQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UoKTsKKyAgICAgICAgJHRo
aXMtPnVzdWFyaW9Mb2FkZXIgPSAkdXN1YXJpb0xvYWRlcjsKICAgICAgICAgJHRoaXMtPnJvb3RQ
YXRoID0gZGlybmFtZShfX0RJUl9fLCAyKTsKICAgICB9CiAKQEAgLTg5LDYgKzkxLDcgQEAKICAg
ICAgICAgICAgIH0KIAogICAgICAgICAgICAgJHRpbWVzdGFtcCA9ICRmZWNoYSAhPT0gJycgPyBz
dHJ0b3RpbWUoJGZlY2hhKSA6IGZhbHNlOworICAgICAgICAgICAgJHRyYW5zcG9ydFN0YXRlID0g
Q29ycmVvUmVnaXN0cm9Fc3RhZG9TZXJ2aWNlOjpjb25zdWx0YXIoJHVzdWFyaW9JZCwgKGludCkk
ZmlsYVsnaWQnXSwgKHN0cmluZykkZmlsYVsnZXN0YWRvJ10pOwogCiAgICAgICAgICAgICAkc2Fs
aWRhW10gPSBbCiAgICAgICAgICAgICAgICAgJ2lkJyA9PiAoaW50KSgkZmlsYVsnaWQnXSA/PyAw
KSwKQEAgLTEwMCw3ICsxMDMsMTAgQEAKICAgICAgICAgICAgICAgICAndGlwbycgPT4gJHRoaXMt
PmV0aXF1ZXRhVGlwbygkZmlsYVsndGlwbyddID8/ICcnKSwKICAgICAgICAgICAgICAgICAndGlw
b19jb2RpZ28nID0+IHN0cnRvbG93ZXIoKHN0cmluZykoJGZpbGFbJ3RpcG8nXSA/PyAnZ2VuZXJh
bCcpKSwKICAgICAgICAgICAgICAgICAnZmVjaGEnID0+ICR0aW1lc3RhbXAgIT09IGZhbHNlID8g
ZGF0ZSgnZC9tL1kgSDppJywgJHRpbWVzdGFtcCkgOiAn4oCUJywKLSAgICAgICAgICAgICAgICAn
ZXN0YWRvJyA9PiAkdGhpcy0+ZXRpcXVldGFFc3RhZG8oJGZpbGFbJ2VzdGFkbyddID8/ICcnKSwK
KyAgICAgICAgICAgICAgICAnZXN0YWRvJyA9PiAkdHJhbnNwb3J0U3RhdGVbJ2V0aXF1ZXRhX2Vu
dmlvJ10gPz8gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydlc3RhZG8nXSA/PyAnJyksCisg
ICAgICAgICAgICAgICAgJ2VzdGFkb19lbnZpbycgPT4gJHRyYW5zcG9ydFN0YXRlWydlc3RhZG9f
ZW52aW8nXSwKKyAgICAgICAgICAgICAgICAncmVtaXRlbnRlX3Zpc2libGUnID0+ICR0cmFuc3Bv
cnRTdGF0ZVsncmVtaXRlbnRlX3Zpc2libGUnXSwgJ3JlcGx5X3RvJyA9PiAkdHJhbnNwb3J0U3Rh
dGVbJ3JlcGx5X3RvJ10sCisgICAgICAgICAgICAgICAgJ2VudHJlZ2FfY29uZmlybWFkYScgPT4g
ZmFsc2UsCiAgICAgICAgICAgICAgICAgJ2VzdGFkb19jb2RpZ28nID0+IHN0cnRvbG93ZXIoKHN0
cmluZykoJGZpbGFbJ2VzdGFkbyddID8/ICdwZW5kaWVudGUnKSksCiAgICAgICAgICAgICAgICAg
J2FkanVudG9zX2NvdW50JyA9PiAoaW50KSgkZmlsYVsnYWRqdW50b3NfY291bnQnXSA/PyAwKQog
ICAgICAgICAgICAgXTsKQEAgLTIxMSw2ICsyMTcsNyBAQAogICAgICAgICAgICAgXTsKICAgICAg
ICAgfQogCisgICAgICAgICR0cmFuc3BvcnRTdGF0ZSA9IENvcnJlb1JlZ2lzdHJvRXN0YWRvU2Vy
dmljZTo6Y29uc3VsdGFyKCR1c3VhcmlvSWQsIChpbnQpJGZpbGFbJ2lkJ10sIChzdHJpbmcpJGZp
bGFbJ2VzdGFkbyddKTsKICAgICAgICAgcmV0dXJuIFsKICAgICAgICAgICAgICdpZCcgPT4gKGlu
dCkkZmlsYVsnaWQnXSwKICAgICAgICAgICAgICdkZXN0aW5hdGFyaW8nID0+IChzdHJpbmcpKCRm
aWxhWydkZXN0aW5hdGFyaW8nXSA/PyAnJyksCkBAIC0yMTksNyArMjI2LDExIEBACiAgICAgICAg
ICAgICAnYXN1bnRvJyA9PiAoc3RyaW5nKSgkZmlsYVsnYXN1bnRvJ10gPz8gJycpLAogICAgICAg
ICAgICAgJ2N1ZXJwbycgPT4gKHN0cmluZykoJGZpbGFbJ2N1ZXJwbyddID8/ICcnKSwKICAgICAg
ICAgICAgICd0aXBvJyA9PiAkdGhpcy0+ZXRpcXVldGFUaXBvKCRmaWxhWyd0aXBvJ10gPz8gJycp
LAotICAgICAgICAgICAgJ2VzdGFkbycgPT4gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydl
c3RhZG8nXSA/PyAnJyksCisgICAgICAgICAgICAnZXN0YWRvJyA9PiAkdHJhbnNwb3J0U3RhdGVb
J2V0aXF1ZXRhX2VudmlvJ10gPz8gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydlc3RhZG8n
XSA/PyAnJyksCisgICAgICAgICAgICAnZXN0YWRvX2VudmlvJyA9PiAkdHJhbnNwb3J0U3RhdGVb
J2VzdGFkb19lbnZpbyddLAorICAgICAgICAgICAgJ2VudHJlZ2FfY29uZmlybWFkYScgPT4gZmFs
c2UsCisgICAgICAgICAgICAncmVnaXN0cm9fY29uZmlybWFkbycgPT4gJHRyYW5zcG9ydFN0YXRl
WydyZWdpc3Ryb19jb25maXJtYWRvJ10sCisgICAgICAgICAgICAncmVtaXRlbnRlX3Zpc2libGUn
ID0+ICR0cmFuc3BvcnRTdGF0ZVsncmVtaXRlbnRlX3Zpc2libGUnXSwgJ3JlcGx5X3RvJyA9PiAk
dHJhbnNwb3J0U3RhdGVbJ3JlcGx5X3RvJ10sCiAgICAgICAgICAgICAnZXN0YWRvX2NvZGlnbycg
PT4gc3RydG9sb3dlcigKICAgICAgICAgICAgICAgICB0cmltKChzdHJpbmcpKCRmaWxhWydlc3Rh
ZG8nXSA/PyAncGVuZGllbnRlJykpCiAgICAgICAgICAgICApLApAQCAtNjMzLDYgKzY0NCwzNyBA
QAogCiAgICAgcHVibGljIGZ1bmN0aW9uIGVudmlhcigkdXN1YXJpb0lkLCAkZGF0b3MsICRhcmNo
aXZvcyA9IG51bGwpCiAgICAgeworICAgICAgICBpZiAoKGludCkkdXN1YXJpb0lkIDw9IDAgfHwg
KGlzc2V0KCRfU0VTU0lPTlsndXN1YXJpb19pZCddKSAmJiAoaW50KSRfU0VTU0lPTlsndXN1YXJp
b19pZCddICE9PSAoaW50KSR1c3VhcmlvSWQpKSB7CisgICAgICAgICAgICByZXR1cm4gJHRoaXMt
PmVycm9yKCdMYSBzZXNpw7NuIG5vIGVzdMOhIGFjdGl2YS4nLCA0MDEpOworICAgICAgICB9Cisg
ICAgICAgICRrZXkgPSB0cmltKChzdHJpbmcpKCRkYXRvc1snaWRlbXBvdGVuY3lfa2V5J10gPz8g
JycpKTsKKyAgICAgICAgJGRyYWZ0ID0gKGludCkoJGRhdG9zWydjb3JyZW9faWQnXSA/PyAwKTsK
KyAgICAgICAgJGhhc2hlcyA9IFtdOworICAgICAgICBmb3JlYWNoICgoJGFyY2hpdm9zWyd0bXBf
bmFtZSddID8/IFtdKSBhcyAkcGF0aCkgeworICAgICAgICAgICAgJGhhc2hlc1tdID0gaXNfdXBs
b2FkZWRfZmlsZSgkcGF0aCkgPyBoYXNoX2ZpbGUoJ3NoYTI1NicsICRwYXRoKSA6ICcnOworICAg
ICAgICB9CisgICAgICAgICRmaWVsZHMgPSBbXTsKKyAgICAgICAgZm9yZWFjaCAoWydyZW1pdGVu
dGUnLCAnZGVzdGluYXRhcmlvJywgJ2Rlc3RpbmF0YXJpb19ub21icmUnLCAnYXN1bnRvJywgJ2N1
ZXJwbycsICdjb252b2NhdG9yaWFfaWQnLCAnY29ycmVvX2lkJ10gYXMgJGZpZWxkKSB7CisgICAg
ICAgICAgICAkZmllbGRzWyRmaWVsZF0gPSAoc3RyaW5nKSgkZGF0b3NbJGZpZWxkXSA/PyAnJyk7
CisgICAgICAgIH0KKyAgICAgICAgJGZpbmdlcnByaW50ID0gaGFzaCgnc2hhMjU2JywganNvbl9l
bmNvZGUoWyRmaWVsZHMsICRoYXNoZXNdKSk7CisgICAgICAgIGlmICgka2V5ICE9PSAnJyAmJiAh
cHJlZ19tYXRjaCgnL15bYS16QS1aMC05Ol8uLV17OCwxODB9JC9EJywgJGtleSkpIHsKKyAgICAg
ICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJ0xhIHNvbGljaXR1ZCBkZSBlbnbDrW8gbm8gZXMg
dsOhbGlkYS4nLCA0MjIpOworICAgICAgICB9CisgICAgICAgICRrZXkgPSAkZHJhZnQgPiAwID8g
J2RyYWZ0OicgLiAkZHJhZnQgOiAoJGtleSA/OiAnbGVnYWN5OicgLiBpbnRkaXYodGltZSgpLCA2
MCkgLiAnOicgLiAkZmluZ2VycHJpbnQpOworICAgICAgICAkZGF0b3NbJ2lkZW1wb3RlbmN5X2tl
eSddID0gJ21hcmtldGluZzonIC4gaGFzaCgnc2hhMjU2JywgJGtleSk7CisgICAgICAgIHRyeSB7
CisgICAgICAgICAgICByZXR1cm4gQ29ycmVvRW52aW9Db250cm9sOjpyZWdpc3RyYXIoKGludCkk
dXN1YXJpb0lkIC4gJzonIC4gJGtleSwgJGZpbmdlcnByaW50LAorICAgICAgICAgICAgICAgIGZ1
bmN0aW9uICgpIHVzZSAoJHVzdWFyaW9JZCwgJGRhdG9zLCAkYXJjaGl2b3MpIHsgcmV0dXJuICR0
aGlzLT5lbnZpYXJVbmFWZXooJHVzdWFyaW9JZCwgJGRhdG9zLCAkYXJjaGl2b3MpOyB9KTsKKyAg
ICAgICAgfSBjYXRjaCAoVGhyb3dhYmxlICRlcnJvcikgeworICAgICAgICAgICAgZXJyb3JfbG9n
KCdNQUlMX01BUktFVElORyBzdGF0ZV9vcl9oaXN0b3J5X2ZhaWxlZCBleGNlcHRpb249JyAuIGdl
dF9jbGFzcygkZXJyb3IpKTsKKyAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJ05vIGZ1
ZSBwb3NpYmxlIGNvbmZpcm1hciBlbCBlbnbDrW8geSBzdSByZWdpc3Ryby4gUmV2aXNhIGVsIHBy
b3ZlZWRvciB5IGVsIGhpc3RvcmlhbCBhbnRlcyBkZSByZXBldGlybG8uJywgNTAzKQorICAgICAg
ICAgICAgICAgICsgWydlc3RhZG9fZW50cmVnYScgPT4gJ0RFU0NPTk9DSURPJywgJ2VudHJlZ2Ff
Y29uZmlybWFkYScgPT4gZmFsc2VdOworICAgICAgICB9CisgICAgfQorCisgICAgcHJpdmF0ZSBm
dW5jdGlvbiBlbnZpYXJVbmFWZXooJHVzdWFyaW9JZCwgJGRhdG9zLCAkYXJjaGl2b3MpCisgICAg
ewogICAgICAgICAkdXN1YXJpb0lkID0gKGludCkkdXN1YXJpb0lkOwogICAgICAgICAkY29ycmVv
SWQgPSAoaW50KSgkZGF0b3NbJ2NvcnJlb19pZCddID8/IDApOwogICAgICAgICAkcmVtaXRlbnRl
U29saWNpdGFkbyA9IHN0cnRvbG93ZXIoCkBAIC02NzksOCArNzIxLDcgQEAKICAgICAgICAgICAg
IH0KICAgICAgICAgfQogCi0gICAgICAgICRtb2RlbG9Vc3VhcmlvID0gbmV3IFVzdWFyaW9Nb2Rl
bCgpOwotICAgICAgICAkdXN1YXJpbyA9ICRtb2RlbG9Vc3VhcmlvLT5idXNjYXJQb3JJZCgkdXN1
YXJpb0lkKTsKKyAgICAgICAgJHVzdWFyaW8gPSAkdGhpcy0+dXN1YXJpb0xvYWRlciA/ICgkdGhp
cy0+dXN1YXJpb0xvYWRlcikoJHVzdWFyaW9JZCkgOiAobmV3IFVzdWFyaW9Nb2RlbCgpKS0+YnVz
Y2FyUG9ySWQoJHVzdWFyaW9JZCk7CiAKICAgICAgICAgaWYgKCEkdXN1YXJpbykgewogICAgICAg
ICAgICAgcmV0dXJuICR0aGlzLT5lcnJvcignTm8gZnVlIHBvc2libGUgaWRlbnRpZmljYXIgYWwg
cmVtaXRlbnRlLicsIDQwNCk7CkBAIC02OTksNiArNzQwLDkgQEAKICAgICAgICAgICAgICAgICA0
MjIKICAgICAgICAgICAgICk7CiAgICAgICAgIH0KKworICAgICAgICAkaWRlbnRpZGFkID0gJHRo
aXMtPnNlbmRlci0+Y29tcHJvYmFySWRlbnRpZGFkKCRyZW1pdGVudGUsICR1c3VhcmlvSWQsIChz
dHJpbmcpKCRkYXRvc1snaWRlbnRpZGFkX2VudmlvJ10gPz8gJycpKTsKKyAgICAgICAgaWYgKGVt
cHR5KCRpZGVudGlkYWRbJ29rJ10pKSB7IHJldHVybiAkaWRlbnRpZGFkOyB9CiAKICAgICAgICAg
JHByZXBhcmFjaW9uID0gJHRoaXMtPnByZXBhcmFyQWRqdW50b3MoJGFyY2hpdm9zKTsKICAgICAg
ICAgaWYgKCEoJHByZXBhcmFjaW9uWydvayddID8/IGZhbHNlKSkgewpAQCAtNzU1LDkgKzc5OSwx
NSBAQAogICAgICAgICAgICAgICAgIH0KICAgICAgICAgICAgIH0KIAorICAgICAgICAgICAgaWYg
KCRyZWdpc3Ryb0lkIDw9IDApIHsgcmV0dXJuICR0aGlzLT5lcnJvcignTm8gc2UgcHVkbyByZWdp
c3RyYXIgbGEgc29saWNpdHVkIGVuIGxhIGJhbmRlamEuIEVsIGNvcnJlbyBubyBzZSBoYSBlbnZp
YWRvLicsIDUwMyk7IH0KKyAgICAgICAgICAgIENvcnJlb1JlZ2lzdHJvRXN0YWRvU2VydmljZTo6
Z3VhcmRhcigkdXN1YXJpb0lkLCAkcmVnaXN0cm9JZCwgJ1BFTkRJRU5URScpOworICAgICAgICAg
ICAgQ29ycmVvUmVnaXN0cm9Fc3RhZG9TZXJ2aWNlOjpndWFyZGFyKCR1c3VhcmlvSWQsICRyZWdp
c3Ryb0lkLCAnRU5fUFJPQ0VTTycpOwogICAgICAgICAgICAgJGVudmlvID0gJHRoaXMtPnNlbmRl
ci0+ZW52aWFyKFsKICAgICAgICAgICAgICAgICAndXN1YXJpb19pZCcgPT4gJHVzdWFyaW9JZCwK
ICAgICAgICAgICAgICAgICAncGVyZmlsX21hcmtldGluZycgPT4gdHJ1ZSwKKyAgICAgICAgICAg
ICAgICAnY29uZmlybWFyX2lkZW50aWRhZCcgPT4gdHJ1ZSwKKyAgICAgICAgICAgICAgICAnaWRl
bnRpZGFkX2VudmlvJyA9PiAoc3RyaW5nKSgkZGF0b3NbJ2lkZW50aWRhZF9lbnZpbyddID8/ICcn
KSwKKyAgICAgICAgICAgICAgICAnaWRlbXBvdGVuY3lfa2V5JyA9PiAkZGF0b3NbJ2lkZW1wb3Rl
bmN5X2tleSddLAogICAgICAgICAgICAgICAgICd1c2FyX3JlbWl0ZW50ZV9leGFjdG8nID0+IHRy
dWUsCiAgICAgICAgICAgICAgICAgJ3JlbWl0ZW50ZScgPT4gJHJlbWl0ZW50ZSwKICAgICAgICAg
ICAgICAgICAnbm9tYnJlX3JlbWl0ZW50ZScgPT4gdHJpbSgKQEAgLTc3MSwxMiArODIxLDIyIEBA
CiAgICAgICAgICAgICAgICAgJ2FkanVudG9zJyA9PiAkYWRqdW50b3NFbnZpbwogICAgICAgICAg
ICAgXSk7CiAKKyAgICAgICAgICAgICR0cmFuc3BvcnRTdGF0dXMgPSAhZW1wdHkoJGVudmlvWydv
ayddKSA/ICdBQ0VQVEFETycgOiAoKCRlbnZpb1snZXN0YWRvX2VudHJlZ2EnXSA/PyAnJykgPT09
ICdERVNDT05PQ0lETycgPyAnREVTQ09OT0NJRE8nIDogJ0ZBTExJRE8nKTsKKyAgICAgICAgICAg
IC8vIFVuIGR1cGxpY2FkbyBhY2VwdGFkbyB0aWVuZSBvaz1mYWxzZTogY29uc2VydmFyIGVzYSBl
dmlkZW5jaWEgeSBubyB0cmF0YXJsbyBjb21vIHJlY2hhem8uCisgICAgICAgICAgICBpZiAoKCRl
bnZpb1snZXN0YWRvX2VudHJlZ2EnXSA/PyAnJykgPT09ICdBQ0VQVEFETycpIHsgJHRyYW5zcG9y
dFN0YXR1cyA9ICdBQ0VQVEFETyc7IH0KKyAgICAgICAgICAgIENvcnJlb1JlZ2lzdHJvRXN0YWRv
U2VydmljZTo6Z3VhcmRhcigkdXN1YXJpb0lkLCAkcmVnaXN0cm9JZCwgJHRyYW5zcG9ydFN0YXR1
cywgJGVudmlvKTsKKwogICAgICAgICAgICAgaWYgKCEoJGVudmlvWydvayddID8/IGZhbHNlKSkg
ewogICAgICAgICAgICAgICAgIGlmICgkcmVnaXN0cm9JZCA+IDApIHsKICAgICAgICAgICAgICAg
ICAgICAgJHRoaXMtPmFjdHVhbGl6YXJSZWdpc3Ryb0Vycm9yKAogICAgICAgICAgICAgICAgICAg
ICAgICAgJHJlZ2lzdHJvSWQsCiAgICAgICAgICAgICAgICAgICAgICAgICAoc3RyaW5nKSgkZW52
aW9bJ21lbnNhamUnXSA/PyAnTm8gZnVlIHBvc2libGUgcmVhbGl6YXIgZWwgZW52w61vLicpCiAg
ICAgICAgICAgICAgICAgICAgICk7CisgICAgICAgICAgICAgICAgICAgIGlmICgkY29ycmVvSWQg
PiAwICYmICgkZW52aW9bJ2VzdGFkb19lbnRyZWdhJ10gPz8gJ1JFQ0hBWkFETycpID09PSAnUkVD
SEFaQURPJykgeworICAgICAgICAgICAgICAgICAgICAgICAgJHN0bXQgPSAkdGhpcy0+Y29ubmVj
dGlvbi0+cHJlcGFyZSgiVVBEQVRFIGNvcnJlb3NfbWFya2V0aW5nIFNFVCBlc3RhZG8gPSAnQk9S
UkFET1InIFdIRVJFIGlkID0gPyBBTkQgdXN1YXJpb19pZCA9ID8gQU5EIGVzdGFkbyA9ICdQRU5E
SUVOVEUnIik7CisgICAgICAgICAgICAgICAgICAgICAgICAkc3RtdC0+YmluZF9wYXJhbSgnaWkn
LCAkY29ycmVvSWQsICR1c3VhcmlvSWQpOworICAgICAgICAgICAgICAgICAgICAgICAgJHN0bXQt
PmV4ZWN1dGUoKTsKKyAgICAgICAgICAgICAgICAgICAgfQogICAgICAgICAgICAgICAgIH0KIAog
ICAgICAgICAgICAgICAgIHJldHVybiAkZW52aW87CkBAIC04MDAsMTEgKzg2MCwxNCBAQAogCiAg
ICAgICAgICAgICByZXR1cm4gWwogICAgICAgICAgICAgICAgICdvaycgPT4gdHJ1ZSwKLSAgICAg
ICAgICAgICAgICAnbWVuc2FqZScgPT4gJ0NvcnJlbyBlbnZpYWRvIGNvcnJlY3RhbWVudGUuJywK
KyAgICAgICAgICAgICAgICAnbWVuc2FqZScgPT4gJ0VsIHByb3ZlZWRvciBhY2VwdMOzIGVsIGNv
cnJlbyBkZXNkZSAnIC4gKCRlbnZpb1sncmVtaXRlbnRlX3Zpc2libGUnXSA/PyAnbGEgY3VlbnRh
IGluc3RpdHVjaW9uYWwnKSAuICcuIExhIGVudHJlZ2EgYcO6biBubyBlc3TDoSBjb25maXJtYWRh
LicsCisgICAgICAgICAgICAgICAgJ2VzdGFkb19lbnRyZWdhJyA9PiAnQUNFUFRBRE8nLAorICAg
ICAgICAgICAgICAgICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+IGZhbHNlLAogICAgICAgICAgICAg
ICAgICdjb3JyZW9faWQnID0+ICRyZWdpc3Ryb0lkLAogICAgICAgICAgICAgICAgICdwcm92ZWVk
b3InID0+IChzdHJpbmcpKCRlbnZpb1sncHJvdmVlZG9yJ10gPz8gJycpLAogICAgICAgICAgICAg
ICAgICdmaXJtYV9pbmNsdWlkYScgPT4gKGJvb2wpKCRlbnZpb1snZmlybWFfaW5jbHVpZGEnXSA/
PyBmYWxzZSksCi0gICAgICAgICAgICAgICAgJ2FkanVudG9zJyA9PiBjb3VudCgkYWRqdW50b3NF
bnZpbykKKyAgICAgICAgICAgICAgICAnYWRqdW50b3MnID0+IGNvdW50KCRhZGp1bnRvc0Vudmlv
KSwKKyAgICAgICAgICAgICAgICAncmVtaXRlbnRlX3Zpc2libGUnID0+ICRlbnZpb1sncmVtaXRl
bnRlX3Zpc2libGUnXSA/PyAnJywgJ3JlcGx5X3RvJyA9PiAkZW52aW9bJ3JlcGx5X3RvJ10gPz8g
JycKICAgICAgICAgICAgIF07CiAgICAgICAgIH0gZmluYWxseSB7CiAgICAgICAgICAgICAkdGhp
cy0+bGltcGlhclRlbXBvcmFsZXMoJHJ1dGFzVGVtcG9yYWxlcyk7Cg==
CENTRAL_PATCH_3_3
 [[ "$(digest "$TMP/patches/3/3.patch")" == 'dfb4eefb105f6cfb247ae57575d3d0d935f07927bdc4c119d97bd153eef418cf' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/4"
 base64 --decode > "$TMP/patches/4/1.patch" <<'CENTRAL_PATCH_4_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9TZWd1aW1pZW50b0NvcnJlb1NlcnZpY2UucGhwIGIv
YXBwL3NlcnZpY2VzL1NlZ3VpbWllbnRvQ29ycmVvU2VydmljZS5waHAKLS0tIGEvYXBwL3NlcnZp
Y2VzL1NlZ3VpbWllbnRvQ29ycmVvU2VydmljZS5waHAKKysrIGIvYXBwL3NlcnZpY2VzL1NlZ3Vp
bWllbnRvQ29ycmVvU2VydmljZS5waHAKQEAgLTE4OSw3ICsxODksOCBAQAogICAgICAgICAgICAg
JHNlZ3VpbWllbnRvLAogICAgICAgICAgICAgJGFzdW50bywKICAgICAgICAgICAgICRjdWVycG8s
Ci0gICAgICAgICAgICAkYWRqdW50b3MKKyAgICAgICAgICAgICRhZGp1bnRvcywKKyAgICAgICAg
ICAgICR1c3VhcmlvSWQKICAgICAgICAgKTsKICAgICAgICAgaWYgKCEoJHJlc3VsdGFkb0Vudmlv
WydvayddID8/IGZhbHNlKSkgewogICAgICAgICAgICAgJHRoaXMtPmVsaW1pbmFyQXJjaGl2b3Mo
JGFyY2hpdm9zQ3JlYWRvcyk7CkBAIC0xNDQ2LDMyICsxNDQ3LDE4IEBACiAgICAgICAgIH0KICAg
ICB9CiAKLSAgICBwcml2YXRlIGZ1bmN0aW9uIGVudmlhckNvcnJlbygkc2VndWltaWVudG8sICRh
c3VudG8sICRjdWVycG8sICRhZGp1bnRvcyA9IFtdKQotICAgIHsKLSAgICAgICAgcmVxdWlyZV9v
bmNlIF9fRElSX18gLiAnL0hvc3Rpbmdlck1haWxBcGlTZXJ2aWNlLnBocCc7Ci0KLSAgICAgICAg
JGhvc3RpbmdlciA9IG5ldyBIb3N0aW5nZXJNYWlsQXBpU2VydmljZSgpOwotCi0gICAgICAgIGlm
ICgkaG9zdGluZ2VyLT5lc3RhQ29uZmlndXJhZG8oKSkgewotICAgICAgICAgICAgJGFuYWxpc3Rh
Q29ycmVvID0gdHJpbSgoc3RyaW5nKSgkc2VndWltaWVudG9bJ2FuYWxpc3RhX2NvcnJlbyddID8/
ICcnKSk7Ci0gICAgICAgICAgICAkYW5hbGlzdGFOb21icmUgPSB0cmltKAotICAgICAgICAgICAg
ICAgIChzdHJpbmcpKCRzZWd1aW1pZW50b1snYW5hbGlzdGFfbm9tYnJlJ10gPz8gJycpIC4gJyAn
IC4KLSAgICAgICAgICAgICAgICAoc3RyaW5nKSgkc2VndWltaWVudG9bJ2FuYWxpc3RhX2FwZWxs
aWRvcyddID8/ICcnKQotICAgICAgICAgICAgKTsKLSAgICAgICAgICAgICRkZXN0aW5hdGFyaW8g
PSB0cmltKChzdHJpbmcpKCRzZWd1aW1pZW50b1snZGVzdGluYXRhcmlvX2NvcnJlbyddID8/ICcn
KSk7Ci0KLSAgICAgICAgICAgIHJldHVybiAkaG9zdGluZ2VyLT5lbnZpYXJPZmljaW8oWwotICAg
ICAgICAgICAgICAgICdyZW1pdGVudGUnID0+ICRhbmFsaXN0YUNvcnJlbywKLSAgICAgICAgICAg
ICAgICAnbm9tYnJlX3JlbWl0ZW50ZScgPT4gJGFuYWxpc3RhTm9tYnJlLAotICAgICAgICAgICAg
ICAgICdkZXN0aW5hdGFyaW8nID0+ICRkZXN0aW5hdGFyaW8sCi0gICAgICAgICAgICAgICAgJ2Fz
dW50bycgPT4gJGFzdW50bywKLSAgICAgICAgICAgICAgICAnY3VlcnBvJyA9PiAkY3VlcnBvLAot
ICAgICAgICAgICAgICAgICdhZGp1bnRvcycgPT4gJGFkanVudG9zLAotICAgICAgICAgICAgICAg
ICdwZXJtaXRpcl9zaW5fYWRqdW50b3MnID0+IHRydWUKLSAgICAgICAgICAgIF0pOwotICAgICAg
ICB9Ci0KLSAgICAgICAgcmV0dXJuICR0aGlzLT5lbnZpYXJQb3JTbXRwKCRzZWd1aW1pZW50bywg
JGFzdW50bywgJGN1ZXJwbywgJGFkanVudG9zKTsKKyAgICBwcml2YXRlIGZ1bmN0aW9uIGVudmlh
ckNvcnJlbygkc2VndWltaWVudG8sICRhc3VudG8sICRjdWVycG8sICRhZGp1bnRvcyA9IFtdLCAk
dXN1YXJpb0lkID0gMCkKKyAgICB7CisgICAgICAgIHJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9D
b3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5waHAnOworICAgICAgICByZXR1cm4gKG5l
dyBDb3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZSgpKS0+ZW52aWFyKFsKKyAgICAgICAg
ICAgICd1c3VhcmlvX2lkJyA9PiAoaW50KSR1c3VhcmlvSWQsCisgICAgICAgICAgICAncmVtaXRl
bnRlJyA9PiB0cmltKChzdHJpbmcpKCRzZWd1aW1pZW50b1snYW5hbGlzdGFfY29ycmVvJ10gPz8g
JycpKSwKKyAgICAgICAgICAgICdub21icmVfcmVtaXRlbnRlJyA9PiB0cmltKChzdHJpbmcpKCRz
ZWd1aW1pZW50b1snYW5hbGlzdGFfbm9tYnJlJ10gPz8gJycpIC4gJyAnIC4gKHN0cmluZykoJHNl
Z3VpbWllbnRvWydhbmFsaXN0YV9hcGVsbGlkb3MnXSA/PyAnJykpLAorICAgICAgICAgICAgJ2Rl
c3RpbmF0YXJpbycgPT4gdHJpbSgoc3RyaW5nKSgkc2VndWltaWVudG9bJ2Rlc3RpbmF0YXJpb19j
b3JyZW8nXSA/PyAnJykpLAorICAgICAgICAgICAgJ2FzdW50bycgPT4gJGFzdW50bywKKyAgICAg
ICAgICAgICdjdWVycG8nID0+ICRjdWVycG8sCisgICAgICAgICAgICAnYWRqdW50b3MnID0+ICRh
ZGp1bnRvcworICAgICAgICBdKTsKICAgICB9CiAKICAgICBwcml2YXRlIGZ1bmN0aW9uIGVudmlh
clBvckhvc3RpbmdlcigK
CENTRAL_PATCH_4_1
 [[ "$(digest "$TMP/patches/4/1.patch")" == '45bc63ab6fd23aedf23fccce12a29bda9bbe7cb6ba1d25a72587a0ba029b61db' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/5"
 base64 --decode > "$TMP/patches/5/1.patch" <<'CENTRAL_PATCH_5_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9PZmljaW9Db3JyZW9TZXJ2aWNlLnBocCBiL2FwcC9z
ZXJ2aWNlcy9PZmljaW9Db3JyZW9TZXJ2aWNlLnBocAotLS0gYS9hcHAvc2VydmljZXMvT2ZpY2lv
Q29ycmVvU2VydmljZS5waHAKKysrIGIvYXBwL3NlcnZpY2VzL09maWNpb0NvcnJlb1NlcnZpY2Uu
cGhwCkBAIC0yMjAsNyArMjIwLDggQEAKICAgICAgICAgICAgICRzZWd1aW1pZW50bywKICAgICAg
ICAgICAgICRhc3VudG8sCiAgICAgICAgICAgICAkY3VlcnBvLAotICAgICAgICAgICAgJHJ1dGFQ
ZGYKKyAgICAgICAgICAgICRydXRhUGRmLAorICAgICAgICAgICAgJHVzdWFyaW9JZAogICAgICAg
ICApOwogCiAgICAgICAgIGlmICghKCRyZXN1bHRhZG9TbXRwWydvayddID8/IGZhbHNlKSkgewpA
QCAtMzE2LDQwICszMTcsMTkgQEAKICAgICAgICAgXTsKICAgICB9CiAKLSAgICBwcml2YXRlIGZ1
bmN0aW9uIGVudmlhckNvcnJlbygkc2VndWltaWVudG8sICRhc3VudG8sICRjdWVycG8sICRydXRh
UGRmKQotICAgIHsKLSAgICAgICAgcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0hvc3Rpbmdlck1h
aWxBcGlTZXJ2aWNlLnBocCc7Ci0KLSAgICAgICAgJGhvc3RpbmdlciA9IG5ldyBIb3N0aW5nZXJN
YWlsQXBpU2VydmljZSgpOwotCi0gICAgICAgIGlmICgkaG9zdGluZ2VyLT5lc3RhQ29uZmlndXJh
ZG8oKSkgewotICAgICAgICAgICAgJGFuYWxpc3RhQ29ycmVvID0gdHJpbSgoc3RyaW5nKSgkc2Vn
dWltaWVudG9bJ2FuYWxpc3RhX2NvcnJlbyddID8/ICcnKSk7Ci0gICAgICAgICAgICAkYW5hbGlz
dGFOb21icmUgPSB0cmltKAotICAgICAgICAgICAgICAgIChzdHJpbmcpKCRzZWd1aW1pZW50b1sn
YW5hbGlzdGFfbm9tYnJlJ10gPz8gJycpIC4gJyAnIC4KLSAgICAgICAgICAgICAgICAoc3RyaW5n
KSgkc2VndWltaWVudG9bJ2FuYWxpc3RhX2FwZWxsaWRvcyddID8/ICcnKQotICAgICAgICAgICAg
KTsKLSAgICAgICAgICAgICRkZXN0aW5hdGFyaW8gPSB0cmltKChzdHJpbmcpKCRzZWd1aW1pZW50
b1snZGVzdGluYXRhcmlvX2NvcnJlbyddID8/ICcnKSk7Ci0gICAgICAgICAgICAkbm9tYnJlQWRq
dW50byA9IGJhc2VuYW1lKAotICAgICAgICAgICAgICAgIChzdHJpbmcpKCRzZWd1aW1pZW50b1sn
YXJjaGl2b19wZGYnXSA/PyAkcnV0YVBkZikKLSAgICAgICAgICAgICk7Ci0KLSAgICAgICAgICAg
IHJldHVybiAkaG9zdGluZ2VyLT5lbnZpYXJPZmljaW8oWwotICAgICAgICAgICAgICAgICdyZW1p
dGVudGUnID0+ICRhbmFsaXN0YUNvcnJlbywKLSAgICAgICAgICAgICAgICAnbm9tYnJlX3JlbWl0
ZW50ZScgPT4gJGFuYWxpc3RhTm9tYnJlLAotICAgICAgICAgICAgICAgICdkZXN0aW5hdGFyaW8n
ID0+ICRkZXN0aW5hdGFyaW8sCi0gICAgICAgICAgICAgICAgJ2FzdW50bycgPT4gJGFzdW50bywK
LSAgICAgICAgICAgICAgICAnY3VlcnBvJyA9PiAkY3VlcnBvLAotICAgICAgICAgICAgICAgICdy
dXRhX2FkanVudG8nID0+ICRydXRhUGRmLAotICAgICAgICAgICAgICAgICdub21icmVfYWRqdW50
bycgPT4gJG5vbWJyZUFkanVudG8KLSAgICAgICAgICAgIF0pOwotICAgICAgICB9Ci0KLSAgICAg
ICAgcmV0dXJuICR0aGlzLT5lbnZpYXJQb3JTbXRwKAotICAgICAgICAgICAgJHNlZ3VpbWllbnRv
LAotICAgICAgICAgICAgJGFzdW50bywKLSAgICAgICAgICAgICRjdWVycG8sCi0gICAgICAgICAg
ICAkcnV0YVBkZgotICAgICAgICApOworICAgIHByaXZhdGUgZnVuY3Rpb24gZW52aWFyQ29ycmVv
KCRzZWd1aW1pZW50bywgJGFzdW50bywgJGN1ZXJwbywgJHJ1dGFQZGYsICR1c3VhcmlvSWQgPSAw
KQorICAgIHsKKyAgICAgICAgcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb1NhbGlkYUlu
c3RpdHVjaW9uYWxTZXJ2aWNlLnBocCc7CisgICAgICAgIHJldHVybiAobmV3IENvcnJlb1NhbGlk
YUluc3RpdHVjaW9uYWxTZXJ2aWNlKCkpLT5lbnZpYXIoWworICAgICAgICAgICAgJ3VzdWFyaW9f
aWQnID0+IChpbnQpJHVzdWFyaW9JZCwKKyAgICAgICAgICAgICdyZW1pdGVudGUnID0+IHRyaW0o
KHN0cmluZykoJHNlZ3VpbWllbnRvWydhbmFsaXN0YV9jb3JyZW8nXSA/PyAnJykpLAorICAgICAg
ICAgICAgJ25vbWJyZV9yZW1pdGVudGUnID0+IHRyaW0oKHN0cmluZykoJHNlZ3VpbWllbnRvWydh
bmFsaXN0YV9ub21icmUnXSA/PyAnJykgLiAnICcgLiAoc3RyaW5nKSgkc2VndWltaWVudG9bJ2Fu
YWxpc3RhX2FwZWxsaWRvcyddID8/ICcnKSksCisgICAgICAgICAgICAnZGVzdGluYXRhcmlvJyA9
PiB0cmltKChzdHJpbmcpKCRzZWd1aW1pZW50b1snZGVzdGluYXRhcmlvX2NvcnJlbyddID8/ICcn
KSksCisgICAgICAgICAgICAnYXN1bnRvJyA9PiAkYXN1bnRvLAorICAgICAgICAgICAgJ2N1ZXJw
bycgPT4gJGN1ZXJwbywKKyAgICAgICAgICAgICdhZGp1bnRvcycgPT4gW1sncnV0YScgPT4gJHJ1
dGFQZGYsICdub21icmUnID0+IGJhc2VuYW1lKChzdHJpbmcpKCRzZWd1aW1pZW50b1snYXJjaGl2
b19wZGYnXSA/PyAkcnV0YVBkZikpLCAnbWltZScgPT4gJ2FwcGxpY2F0aW9uL3BkZiddXSwKKyAg
ICAgICAgICAgICdpZGVtcG90ZW5jeV9rZXknID0+ICdvZmljaW8tY29ycmVvOicgLiAoaW50KSRz
ZWd1aW1pZW50b1snb2ZpY2lvX2lkJ10KKyAgICAgICAgXSk7CiAgICAgfQogCiAgICAgcHJpdmF0
ZSBmdW5jdGlvbiBlbnZpYXJQb3JTbXRwKCRzZWd1aW1pZW50bywgJGFzdW50bywgJGN1ZXJwbywg
JHJ1dGFQZGYpCg==
CENTRAL_PATCH_5_1
 [[ "$(digest "$TMP/patches/5/1.patch")" == '9f661ff9246abc916ec5922663647c5ba4b41be1d34c4a0297c763c61eea5fad' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/6"
 base64 --decode > "$TMP/patches/6/1.patch" <<'CENTRAL_PATCH_6_1'
ZGlmZiAtLWdpdCBhL2FwcC9jb250cm9sbGVycy9Db3JyZW9NYXJrZXRpbmdDb250cm9sbGVyLnBo
cCBiL2FwcC9jb250cm9sbGVycy9Db3JyZW9NYXJrZXRpbmdDb250cm9sbGVyLnBocAotLS0gYS9h
cHAvY29udHJvbGxlcnMvQ29ycmVvTWFya2V0aW5nQ29udHJvbGxlci5waHAKKysrIGIvYXBwL2Nv
bnRyb2xsZXJzL0NvcnJlb01hcmtldGluZ0NvbnRyb2xsZXIucGhwCkBAIC02LDYgKzYsMTMgQEAK
IAogY2xhc3MgQ29ycmVvTWFya2V0aW5nQ29udHJvbGxlcgogeworICAgIHB1YmxpYyBmdW5jdGlv
biBpZGVudGlkYWQoKQorICAgIHsKKyAgICAgICAgJHRoaXMtPnZhbGlkYXJBY2Nlc29NYXJrZXRp
bmcoKTsKKyAgICAgICAgaWYgKCgkX1NFUlZFUlsnUkVRVUVTVF9NRVRIT0QnXSA/PyAnJykgIT09
ICdQT1NUJykgeyAkdGhpcy0+cmVzcG9uZGVyKFsnb2snID0+IGZhbHNlLCAnbWVuc2FqZScgPT4g
J03DqXRvZG8gbm8gcGVybWl0aWRvLiddLCA0MDUpOyB9CisgICAgICAgICRyID0gKG5ldyBDb3Jy
ZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZSgpKS0+aWRlbnRpZGFkKHN0cnRvbG93ZXIodHJp
bSgoc3RyaW5nKSgkX1BPU1RbJ3JlbWl0ZW50ZSddID8/ICcnKSkpLCAoaW50KSRfU0VTU0lPTlsn
dXN1YXJpb19pZCddKTsKKyAgICAgICAgJGNvZGUgPSAoaW50KSgkclsnY29kaWdvX2h0dHAnXSA/
PyAyMDApOyB1bnNldCgkclsnY29kaWdvX2h0dHAnXSk7ICR0aGlzLT5yZXNwb25kZXIoJHIsICRj
b2RlKTsKKyAgICB9CiAgICAgcHVibGljIGZ1bmN0aW9uIGluZGV4KCkKICAgICB7CiAgICAgICAg
ICR0aGlzLT52YWxpZGFyQWNjZXNvTWFya2V0aW5nKCk7Cg==
CENTRAL_PATCH_6_1
 [[ "$(digest "$TMP/patches/6/1.patch")" == 'f0603f215b35845f5b10401ce5a7d57141c10c123ee6200105a892a43d265c8c' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/7"
 base64 --decode > "$TMP/patches/7/1.patch" <<'CENTRAL_PATCH_7_1'
ZGlmZiAtLWdpdCBhL2FwcC9jb250cm9sbGVycy9Mb2dpbkNvbnRyb2xsZXIucGhwIGIvYXBwL2Nv
bnRyb2xsZXJzL0xvZ2luQ29udHJvbGxlci5waHAKLS0tIGEvYXBwL2NvbnRyb2xsZXJzL0xvZ2lu
Q29udHJvbGxlci5waHAKKysrIGIvYXBwL2NvbnRyb2xsZXJzL0xvZ2luQ29udHJvbGxlci5waHAK
QEAgLTI2NywxNzQgKzI2NywxNyBAQAogCiAgICAgcHVibGljIGZ1bmN0aW9uIHByb2Nlc2FyUmVj
dXBlcmFjaW9uKCkKICAgICB7Ci0gICAgICAgIGlmICgkX1NFUlZFUlsnUkVRVUVTVF9NRVRIT0Qn
XSAhPT0gJ1BPU1QnKSB7Ci0KLSAgICAgICAgICAgIGhlYWRlcigKLSAgICAgICAgICAgICAgICAn
TG9jYXRpb246ICcgLgotICAgICAgICAgICAgICAgIEJBU0VfVVJMIC4KLSAgICAgICAgICAgICAg
ICAnaW5kZXgucGhwP2NvbnRyb2xsZXI9bG9naW4mYWN0aW9uPW1vc3RyYXJSZWN1cGVyYWNpb24n
Ci0gICAgICAgICAgICApOwotCi0gICAgICAgICAgICBleGl0OwotICAgICAgICB9Ci0KLQotICAg
ICAgICAkZGF0byA9IHRyaW0oJF9QT1NUWyd1c3VhcmlvJ10gPz8gJycpOwotCi0KLSAgICAgICAg
aWYgKCRkYXRvID09PSAnJykgewotCi0gICAgICAgICAgICAkX1NFU1NJT05bJ2Vycm9yX3JlY3Vw
ZXJhY2lvbiddID0KLSAgICAgICAgICAgICAgICAnSW5ncmVzZSBzdSB1c3VhcmlvIG8gY29ycmVv
Lic7Ci0KLSAgICAgICAgICAgIGhlYWRlcigKLSAgICAgICAgICAgICAgICAnTG9jYXRpb246ICcg
LgotICAgICAgICAgICAgICAgIEJBU0VfVVJMIC4KLSAgICAgICAgICAgICAgICAnaW5kZXgucGhw
P2NvbnRyb2xsZXI9bG9naW4mYWN0aW9uPW1vc3RyYXJSZWN1cGVyYWNpb24nCi0gICAgICAgICAg
ICApOwotCi0gICAgICAgICAgICBleGl0OwotICAgICAgICB9Ci0KLQotICAgICAgICAkdXN1YXJp
byA9ICR0aGlzLT51c3VhcmlvTW9kZWwKLSAgICAgICAgICAgIC0+YnVzY2FyUG9yVXN1YXJpb09D
b3JyZW8oJGRhdG8pOwotCi0KLSAgICAgICAgLyoKLSAgICAgICAgKiBOdW5jYSBpbmRpY2Ftb3Mg
YWwgdmlzaXRhbnRlIHNpIGxhIGN1ZW50YQotICAgICAgICAqIGV4aXN0ZSBvIG5vLgotICAgICAg
ICAqLwotICAgICAgICBpZiAoCi0gICAgICAgICAgICAkdXN1YXJpbyAmJgotICAgICAgICAgICAg
KGludCkkdXN1YXJpb1snZXN0YWRvJ10gPT09IDEgJiYKLSAgICAgICAgICAgIChpbnQpKCR1c3Vh
cmlvWydyb2xfZXN0YWRvJ10gPz8gMCkgPT09IDEKLSAgICAgICAgKSB7Ci0KLSAgICAgICAgICAg
IC8vIEdlbmVyYXIgY29udHJhc2XDsWEgdGVtcG9yYWwKLSAgICAgICAgICAgICRwYXNzd29yZFRl
bXBvcmFsID0KLSAgICAgICAgICAgICAgICAkdGhpcy0+Z2VuZXJhclBhc3N3b3JkVGVtcG9yYWwo
KTsKLQotCi0gICAgICAgICAgICAvLyBQcmVwYXJhciBoYXNoCi0gICAgICAgICAgICAkcGFzc3dv
cmRIYXNoID0gcGFzc3dvcmRfaGFzaCgKLSAgICAgICAgICAgICAgICAkcGFzc3dvcmRUZW1wb3Jh
bCwKLSAgICAgICAgICAgICAgICBQQVNTV09SRF9ERUZBVUxUCi0gICAgICAgICAgICApOwotCi0K
LSAgICAgICAgICAgIC8vIFZpZ2VuY2lhIGRlIDMwIG1pbnV0b3MKLSAgICAgICAgICAgICRmZWNo
YUV4cGlyYWNpb24gPSBkYXRlKAotICAgICAgICAgICAgICAgICdZLW0tZCBIOmk6cycsCi0gICAg
ICAgICAgICAgICAgdGltZSgpICsgKDMwICogNjApCi0gICAgICAgICAgICApOwotCi0KLSAgICAg
ICAgICAgIC8vIE5vbWJyZSBxdWUgYXBhcmVjZXLDoSBlbiBlbCBjb3JyZW8KLSAgICAgICAgICAg
ICRub21icmVEZXN0aW5vID0gdHJpbSgKLSAgICAgICAgICAgICAgICAkdXN1YXJpb1snbm9tYnJl
J10gLiAnICcgLgotICAgICAgICAgICAgICAgICR1c3VhcmlvWydhcGVsbGlkb3MnXQotICAgICAg
ICAgICAgKTsKLQotCi0gICAgICAgICAgICAvLyBFbnZpYXIgY29ycmVvCi0gICAgICAgICAgICAk
bWFpbFNlcnZpY2UgPSBuZXcgTWFpbFNlcnZpY2UoKTsKLQotICAgICAgICAgICAgJGNvcnJlb0Vu
dmlhZG8gPQotICAgICAgICAgICAgICAgICRtYWlsU2VydmljZS0+ZW52aWFyUGFzc3dvcmRUZW1w
b3JhbCgKLSAgICAgICAgICAgICAgICAgICAgJHVzdWFyaW9bJ2NvcnJlbyddLAotICAgICAgICAg
ICAgICAgICAgICAkbm9tYnJlRGVzdGlubywKLSAgICAgICAgICAgICAgICAgICAgJHBhc3N3b3Jk
VGVtcG9yYWwKLSAgICAgICAgICAgICAgICApOwotCi0KLSAgICAgICAgICAgIC8qCi0gICAgICAg
ICAgICAqIExhIGNvbnRyYXNlw7FhIHNvbG8gc2UgbW9kaWZpY2EKLSAgICAgICAgICAgICogc2kg
ZWwgY29ycmVvIGxvZ3LDsyBlbnZpYXJzZS4KLSAgICAgICAgICAgICovCi0gICAgICAgICAgICBp
ZiAoJGNvcnJlb0VudmlhZG8pIHsKLQotICAgICAgICAgICAgICAgICRhY3R1YWxpemFkbyA9Ci0g
ICAgICAgICAgICAgICAgICAgICR0aGlzLT51c3VhcmlvTW9kZWwKLSAgICAgICAgICAgICAgICAg
ICAgICAgIC0+ZXN0YWJsZWNlclBhc3N3b3JkVGVtcG9yYWwoCi0gICAgICAgICAgICAgICAgICAg
ICAgICAgICAgJHVzdWFyaW9bJ2lkJ10sCi0gICAgICAgICAgICAgICAgICAgICAgICAgICAgJHBh
c3N3b3JkSGFzaCwKLSAgICAgICAgICAgICAgICAgICAgICAgICAgICAkZmVjaGFFeHBpcmFjaW9u
Ci0gICAgICAgICAgICAgICAgICAgICAgICApOwotCi0KLSAgICAgICAgICAgICAgICBpZiAoISRh
Y3R1YWxpemFkbykgewotCi0gICAgICAgICAgICAgICAgICAgIGVycm9yX2xvZygKLSAgICAgICAg
ICAgICAgICAgICAgICAgICdObyBzZSBwdWRvIGd1YXJkYXIgbGEgY29udHJhc2XDsWEgdGVtcG9y
YWwgJyAuCi0gICAgICAgICAgICAgICAgICAgICAgICAnZGVsIHVzdWFyaW8gSUQ6ICcgLgotICAg
ICAgICAgICAgICAgICAgICAgICAgJHVzdWFyaW9bJ2lkJ10KLSAgICAgICAgICAgICAgICAgICAg
KTsKLSAgICAgICAgICAgICAgICB9Ci0KLSAgICAgICAgICAgIH0gZWxzZSB7Ci0KLSAgICAgICAg
ICAgICAgICBlcnJvcl9sb2coCi0gICAgICAgICAgICAgICAgICAgICdObyBzZSBwdWRvIGVudmlh
ciBsYSBjb250cmFzZcOxYSB0ZW1wb3JhbCAnIC4KLSAgICAgICAgICAgICAgICAgICAgJ2FsIHVz
dWFyaW8gSUQ6ICcgLgotICAgICAgICAgICAgICAgICAgICAkdXN1YXJpb1snaWQnXQotICAgICAg
ICAgICAgICAgICk7CisgICAgICAgIGlmICgoJF9TRVJWRVJbJ1JFUVVFU1RfTUVUSE9EJ10gPz8g
JycpID09PSAnUE9TVCcpIHsKKyAgICAgICAgICAgICRkYXRvID0gdHJpbSgoc3RyaW5nKSgkX1BP
U1RbJ3VzdWFyaW8nXSA/PyAnJykpOworICAgICAgICAgICAgaWYgKCRkYXRvID09PSAnJykgeyAk
X1NFU1NJT05bJ2Vycm9yX3JlY3VwZXJhY2lvbiddID0gJ0luZ3Jlc2Ugc3UgdXN1YXJpbyBvIGNv
cnJlby4nOyB9CisgICAgICAgICAgICBlbHNlIHsKKyAgICAgICAgICAgICAgICByZXF1aXJlX29u
Y2UgX19ESVJfXyAuICcvLi4vc2VydmljZXMvQ29ycmVvUmVjdXBlcmFjaW9uU2VydmljZS5waHAn
OworICAgICAgICAgICAgICAgIChuZXcgQ29ycmVvUmVjdXBlcmFjaW9uU2VydmljZSgkdGhpcy0+
dXN1YXJpb01vZGVsKSktPnNvbGljaXRhcigkZGF0bywgKHN0cmluZykoJF9TRVJWRVJbJ1JFTU9U
RV9BRERSJ10gPz8gJycpKTsKKyAgICAgICAgICAgICAgICAkX1NFU1NJT05bJ21vc3RyYXJfbW9k
YWxfcmVjdXBlcmFjaW9uJ10gPSB0cnVlOwogICAgICAgICAgICAgfQogICAgICAgICB9Ci0KLQot
ICAgICAgICAvKgotICAgICAgICAqIEVsIG1vZGFsIGFwYXJlY2UgdGFudG8gc2kgbGEgY3VlbnRh
IGV4aXN0ZQotICAgICAgICAqIGNvbW8gc2kgbm8gZXhpc3RlLgotICAgICAgICAqLwotICAgICAg
ICAkX1NFU1NJT05bJ21vc3RyYXJfbW9kYWxfcmVjdXBlcmFjaW9uJ10gPSB0cnVlOwotCi0KLSAg
ICAgICAgaGVhZGVyKAotICAgICAgICAgICAgJ0xvY2F0aW9uOiAnIC4KLSAgICAgICAgICAgIEJB
U0VfVVJMIC4KLSAgICAgICAgICAgICdpbmRleC5waHA/Y29udHJvbGxlcj1sb2dpbiZhY3Rpb249
bW9zdHJhclJlY3VwZXJhY2lvbicKLSAgICAgICAgKTsKLQorICAgICAgICBoZWFkZXIoJ0xvY2F0
aW9uOiAnIC4gQkFTRV9VUkwgLiAnaW5kZXgucGhwP2NvbnRyb2xsZXI9bG9naW4mYWN0aW9uPW1v
c3RyYXJSZWN1cGVyYWNpb24nKTsKICAgICAgICAgZXhpdDsKLSAgICB9Ci0KLSAgICBwcml2YXRl
IGZ1bmN0aW9uIGdlbmVyYXJQYXNzd29yZFRlbXBvcmFsKCkKLSAgICB7Ci0gICAgICAgICRtYXl1
c2N1bGFzID0gJ0FCQ0RFRkdISktMTU5QUVJTVFVWV1hZWic7Ci0gICAgICAgICRtaW51c2N1bGFz
ID0gJ2FiY2RlZmdoaWprbW5vcHFyc3R1dnd4eXonOwotICAgICAgICAkbnVtZXJvcyA9ICcyMzQ1
Njc4OSc7Ci0KLSAgICAgICAgJHBhc3N3b3JkID0gJyc7Ci0KLSAgICAgICAgLy8gR2FyYW50aXph
bW9zIGFsIG1lbm9zIHVubyBkZSBjYWRhIHRpcG8KLSAgICAgICAgJHBhc3N3b3JkIC49ICRtYXl1
c2N1bGFzW3JhbmRvbV9pbnQoMCwgc3RybGVuKCRtYXl1c2N1bGFzKSAtIDEpXTsKLSAgICAgICAg
JHBhc3N3b3JkIC49ICRtaW51c2N1bGFzW3JhbmRvbV9pbnQoMCwgc3RybGVuKCRtaW51c2N1bGFz
KSAtIDEpXTsKLSAgICAgICAgJHBhc3N3b3JkIC49ICRudW1lcm9zW3JhbmRvbV9pbnQoMCwgc3Ry
bGVuKCRudW1lcm9zKSAtIDEpXTsKLQotICAgICAgICAkdG9kb3MgPSAkbWF5dXNjdWxhcyAuICRt
aW51c2N1bGFzIC4gJG51bWVyb3M7Ci0KLSAgICAgICAgLy8gQ29tcGxldGFyIGhhc3RhIDEwIGNh
cmFjdGVyZXMKLSAgICAgICAgd2hpbGUgKHN0cmxlbigkcGFzc3dvcmQpIDwgMTApIHsKLQotICAg
ICAgICAgICAgJHBhc3N3b3JkIC49ICR0b2Rvc1sKLSAgICAgICAgICAgICAgICByYW5kb21faW50
KDAsIHN0cmxlbigkdG9kb3MpIC0gMSkKLSAgICAgICAgICAgIF07Ci0gICAgICAgIH0KLQotICAg
ICAgICAvLyBNZXpjbGFyIGRlIG1hbmVyYSBzZWd1cmEKLSAgICAgICAgJGNhcmFjdGVyZXMgPSBz
dHJfc3BsaXQoJHBhc3N3b3JkKTsKLQotICAgICAgICBmb3IgKCRpID0gY291bnQoJGNhcmFjdGVy
ZXMpIC0gMTsgJGkgPiAwOyAkaS0tKSB7Ci0KLSAgICAgICAgICAgICRqID0gcmFuZG9tX2ludCgw
LCAkaSk7Ci0KLSAgICAgICAgICAgICR0ZW1wb3JhbCA9ICRjYXJhY3RlcmVzWyRpXTsKLSAgICAg
ICAgICAgICRjYXJhY3RlcmVzWyRpXSA9ICRjYXJhY3RlcmVzWyRqXTsKLSAgICAgICAgICAgICRj
YXJhY3RlcmVzWyRqXSA9ICR0ZW1wb3JhbDsKLSAgICAgICAgfQotCi0gICAgICAgIHJldHVybiBp
bXBsb2RlKCcnLCAkY2FyYWN0ZXJlcyk7CiAgICAgfQogCiAgICAgcHVibGljIGZ1bmN0aW9uIG1v
c3RyYXJDYW1iaW9QYXNzd29yZCgpCg==
CENTRAL_PATCH_7_1
 [[ "$(digest "$TMP/patches/7/1.patch")" == 'bfd65be2e61db7fd03bbbec190ab9cb886574ac0fb2f79b26c3b4605ac534633' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/8"
 base64 --decode > "$TMP/patches/8/1.patch" <<'CENTRAL_PATCH_8_1'
ZGlmZiAtLWdpdCBhL2FwcC9tb2RlbHMvVXN1YXJpb01vZGVsLnBocCBiL2FwcC9tb2RlbHMvVXN1
YXJpb01vZGVsLnBocAotLS0gYS9hcHAvbW9kZWxzL1VzdWFyaW9Nb2RlbC5waHAKKysrIGIvYXBw
L21vZGVscy9Vc3VhcmlvTW9kZWwucGhwCkBAIC03Niw2ICs3NiwxNCBAQAogICAgICAgICApOwog
CiAgICAgICAgIHJldHVybiAkc3RtdC0+ZXhlY3V0ZSgpOworICAgIH0KKworICAgIC8qKiBFdml0
YSBxdWUgdW5hIHJlY3VwZXJhY2nDs24gZW4gdnVlbG8gcmVlbXBsYWNlIHVuYSBjb250cmFzZcOx
YSB5YSBjYW1iaWFkYS4gKi8KKyAgICBwdWJsaWMgZnVuY3Rpb24gZXN0YWJsZWNlclBhc3N3b3Jk
VGVtcG9yYWxTaVZpZ2VudGUoJGlkLCAkaGFzaEFudGVyaW9yLCAkaGFzaE51ZXZvLCAkZXhwaXJh
KTogYm9vbAorICAgIHsKKyAgICAgICAgJHN0bXQgPSAkdGhpcy0+Y29ubmVjdGlvbi0+cHJlcGFy
ZSgiVVBEQVRFIHVzdWFyaW9zIFNFVCBwYXNzd29yZCA9ID8sIHJlcXVpZXJlX2NhbWJpb19wYXNz
d29yZCA9IDEsIHBhc3N3b3JkX3RlbXBvcmFsX2V4cGlyYSA9ID8gV0hFUkUgaWQgPSA/IEFORCBC
SU5BUlkgcGFzc3dvcmQgPSBCSU5BUlkgPyBBTkQgZXN0YWRvID0gMSIpOworICAgICAgICAkc3Rt
dC0+YmluZF9wYXJhbSgnc3NpcycsICRoYXNoTnVldm8sICRleHBpcmEsICRpZCwgJGhhc2hBbnRl
cmlvcik7CisgICAgICAgIHJldHVybiAkc3RtdC0+ZXhlY3V0ZSgpICYmICRzdG10LT5hZmZlY3Rl
ZF9yb3dzID09PSAxOwogICAgIH0KIAogICAgIHB1YmxpYyBmdW5jdGlvbiBidXNjYXJQb3JJZCgk
aWQpCg==
CENTRAL_PATCH_8_1
 [[ "$(digest "$TMP/patches/8/1.patch")" == '23e3a4315b8deb29e7a9586df16f4972cbdd7c05a7de8eadab15122fc37e0245' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/9"
 base64 --decode > "$TMP/patches/9/1.patch" <<'CENTRAL_PATCH_9_1'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9jb3JyZW9zL2luZGV4LnBocCBiL2FwcC92aWV3cy9jb3Jy
ZW9zL2luZGV4LnBocAotLS0gYS9hcHAvdmlld3MvY29ycmVvcy9pbmRleC5waHAKKysrIGIvYXBw
L3ZpZXdzL2NvcnJlb3MvaW5kZXgucGhwCkBAIC0zNzIsMTEgKzM3Miw3IEBACiAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgZGF0YS1tYXJrZXRpbmctbWFpbC1mcm9tCiAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgcmVxdWlyZWQ+CiAKLSAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYg
Y2xhc3M9ImZvcm0tdGV4dCI+Ci0gICAgICAgICAgICAgICAgICAgICAgICAgICAgRXNjcmliZSB1
bmEgY3VlbnRhIGNvbmVjdGFkYSB5IGF1dG9yaXphZGEgcGFyYSB0dSB1c3VhcmlvLgotICAgICAg
ICAgICAgICAgICAgICAgICAgICAgIEVsIHNpc3RlbWEgdXRpbGl6YXLDoSBzdSBBUEkgbyBTTVRQ
IGF1dGVudGljYWRvOyBsYXMgY3VlbnRhcwotICAgICAgICAgICAgICAgICAgICAgICAgICAgIG51
ZXZhcyByZXF1aWVyZW4gY29uZmlndXJhY2nDs24gZGVsIGFkbWluaXN0cmFkb3IuCi0gICAgICAg
ICAgICAgICAgICAgICAgICA8L2Rpdj4KKyAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYgY2xh
c3M9ImZvcm0tdGV4dCIgZGF0YS1tYXJrZXRpbmctbWFpbC1pZGVudGl0eSByb2xlPSJzdGF0dXMi
IGFyaWEtbGl2ZT0icG9saXRlIj48L2Rpdj4KICAgICAgICAgICAgICAgICAgICAgPC9kaXY+CiAK
ICAgICAgICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0iY29ycmVvLW1hcmtldGluZy1yZWNpcGll
bnQtY2FyZCBtYi0zIj4K
CENTRAL_PATCH_9_1
 [[ "$(digest "$TMP/patches/9/1.patch")" == 'f489bef97dc88ac1a7aaf1101b1d0ae8a66bebbc018fe1f8b13314c77911e5fe' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/9/2.patch" <<'CENTRAL_PATCH_9_2'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9jb3JyZW9zL2luZGV4LnBocCBiL2FwcC92aWV3cy9jb3Jy
ZW9zL2luZGV4LnBocAotLS0gYS9hcHAvdmlld3MvY29ycmVvcy9pbmRleC5waHAKKysrIGIvYXBw
L3ZpZXdzL2NvcnJlb3MvaW5kZXgucGhwCkBAIC0zNCw3ICszNCw2IEBACiAgICAgICAgICAgICA8
L2Rpdj4KIAogICAgICAgICAgICAgPGRpdiBjbGFzcz0iY29ycmVvLW1hcmtldGluZy1zZWxlY3Rv
ci1hY3Rpb25zIj4KLSAgICAgICAgICAgICAgICA8YSBjbGFzcz0iYnRuIGJ0bi1zeXN0ZW0tbGln
aHQiIGhyZWY9ImluZGV4LnBocD9jb250cm9sbGVyPWNvcnJlb0NvbmV4aW9uJmFtcDthY3Rpb249
aW5kZXgiIHRhcmdldD0iX2JsYW5rIiByZWw9Im5vb3BlbmVyIG5vcmVmZXJyZXIiPkNvbmVjdGFy
IGN1ZW50YSBkZSBjb3JyZW88L2E+CiAgICAgICAgICAgICAgICAgPGJ1dHRvbgogICAgICAgICAg
ICAgICAgICAgICB0eXBlPSJidXR0b24iCiAgICAgICAgICAgICAgICAgICAgIGNsYXNzPSJidG4g
YnRuLXN5c3RlbS1zYXZlIGNvcnJlby1tYXJrZXRpbmctY29tcG9zZS1idXR0b24iCkBAIC0zNzMs
MTEgKzM3Miw3IEBACiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgZGF0YS1tYXJrZXRpbmct
bWFpbC1mcm9tCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgcmVxdWlyZWQ+CiAKLSAgICAg
ICAgICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9ImZvcm0tdGV4dCI+Ci0gICAgICAgICAgICAg
ICAgICAgICAgICAgICAgRXNjcmliZSB1bmEgY3VlbnRhIGNvbmVjdGFkYSB5IGF1dG9yaXphZGEg
cGFyYSB0dSB1c3VhcmlvLgotICAgICAgICAgICAgICAgICAgICAgICAgICAgIEVsIHNpc3RlbWEg
dXRpbGl6YXLDoSBzdSBBUEkgbyBTTVRQIGF1dGVudGljYWRvOyBsYXMgY3VlbnRhcwotICAgICAg
ICAgICAgICAgICAgICAgICAgICAgIG51ZXZhcyByZXF1aWVyZW4gY29uZmlndXJhY2nDs24gZGVs
IGFkbWluaXN0cmFkb3IuCi0gICAgICAgICAgICAgICAgICAgICAgICA8L2Rpdj4KKyAgICAgICAg
ICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9ImZvcm0tdGV4dCIgZGF0YS1tYXJrZXRpbmctbWFp
bC1pZGVudGl0eSByb2xlPSJzdGF0dXMiIGFyaWEtbGl2ZT0icG9saXRlIj48L2Rpdj4KICAgICAg
ICAgICAgICAgICAgICAgPC9kaXY+CiAKICAgICAgICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0i
Y29ycmVvLW1hcmtldGluZy1yZWNpcGllbnQtY2FyZCBtYi0zIj4K
CENTRAL_PATCH_9_2
 [[ "$(digest "$TMP/patches/9/2.patch")" == '7befa03bf061aeda0331047f613037bdea27b0abd2eb20f65ee04470f8db516e' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/9/3.patch" <<'CENTRAL_PATCH_9_3'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9jb3JyZW9zL2luZGV4LnBocCBiL2FwcC92aWV3cy9jb3Jy
ZW9zL2luZGV4LnBocAotLS0gYS9hcHAvdmlld3MvY29ycmVvcy9pbmRleC5waHAKKysrIGIvYXBw
L3ZpZXdzL2NvcnJlb3MvaW5kZXgucGhwCkBAIC0zNCw3ICszNCw2IEBACiAgICAgICAgICAgICA8
L2Rpdj4KIAogICAgICAgICAgICAgPGRpdiBjbGFzcz0iY29ycmVvLW1hcmtldGluZy1zZWxlY3Rv
ci1hY3Rpb25zIj4KLSAgICAgICAgICAgICAgICA8YSBjbGFzcz0iYnRuIGJ0bi1zeXN0ZW0tbGln
aHQiIGhyZWY9ImluZGV4LnBocD9jb250cm9sbGVyPWNvcnJlb0NvbmV4aW9uJmFtcDthY3Rpb249
aW5kZXgiIHRhcmdldD0iX2JsYW5rIiByZWw9Im5vb3BlbmVyIG5vcmVmZXJyZXIiPkNvbmVjdGFy
IGN1ZW50YSBkZSBjb3JyZW88L2E+CiAgICAgICAgICAgICAgICAgPGJ1dHRvbgogICAgICAgICAg
ICAgICAgICAgICB0eXBlPSJidXR0b24iCiAgICAgICAgICAgICAgICAgICAgIGNsYXNzPSJidG4g
YnRuLXN5c3RlbS1zYXZlIGNvcnJlby1tYXJrZXRpbmctY29tcG9zZS1idXR0b24iCkBAIC0zNzMs
NiArMzcyLDcgQEAKICAgICAgICAgICAgICAgICAgICAgICAgICAgICBkYXRhLW1hcmtldGluZy1t
YWlsLWZyb20KICAgICAgICAgICAgICAgICAgICAgICAgICAgICByZXF1aXJlZD4KIAorICAgICAg
ICAgICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0iZm9ybS10ZXh0IiBkYXRhLW1hcmtldGluZy1t
YWlsLWlkZW50aXR5IHJvbGU9InN0YXR1cyIgYXJpYS1saXZlPSJwb2xpdGUiPjwvZGl2PgogICAg
ICAgICAgICAgICAgICAgICA8L2Rpdj4KIAogICAgICAgICAgICAgICAgICAgICA8ZGl2IGNsYXNz
PSJjb3JyZW8tbWFya2V0aW5nLXJlY2lwaWVudC1jYXJkIG1iLTMiPgo=
CENTRAL_PATCH_9_3
 [[ "$(digest "$TMP/patches/9/3.patch")" == '6ee582927d58a87b030241fe89633d4d3d2b8969d0b4dc74e633687afe6cd74b' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/9/4.patch" <<'CENTRAL_PATCH_9_4'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9jb3JyZW9zL2luZGV4LnBocCBiL2FwcC92aWV3cy9jb3Jy
ZW9zL2luZGV4LnBocAotLS0gYS9hcHAvdmlld3MvY29ycmVvcy9pbmRleC5waHAKKysrIGIvYXBw
L3ZpZXdzL2NvcnJlb3MvaW5kZXgucGhwCkBAIC0zNzIsMTEgKzM3Miw3IEBACiAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgZGF0YS1tYXJrZXRpbmctbWFpbC1mcm9tCiAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgcmVxdWlyZWQ+CiAKLSAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYg
Y2xhc3M9ImZvcm0tdGV4dCI+Ci0gICAgICAgICAgICAgICAgICAgICAgICAgICAgRXNjcmliZSBs
YSBjdWVudGEgZGUgb3JpZ2VuIHF1ZSB1dGlsaXphcsOhIE1hcmtldGluZy4KLSAgICAgICAgICAg
ICAgICAgICAgICAgICAgICBFbCBzaXN0ZW1hIGVsZWdpcsOhIGF1dG9tw6F0aWNhbWVudGUgSG9z
dGluZ2VyIE1haWwgQVBJCi0gICAgICAgICAgICAgICAgICAgICAgICAgICAgbyBsYSBjdWVudGEg
Y2VudHJhbCBjb25maWd1cmFkYSBwYXJhIGVzZSBjb3JyZW8uCi0gICAgICAgICAgICAgICAgICAg
ICAgICA8L2Rpdj4KKyAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9ImZvcm0tdGV4
dCIgZGF0YS1tYXJrZXRpbmctbWFpbC1pZGVudGl0eSByb2xlPSJzdGF0dXMiIGFyaWEtbGl2ZT0i
cG9saXRlIj48L2Rpdj4KICAgICAgICAgICAgICAgICAgICAgPC9kaXY+CiAKICAgICAgICAgICAg
ICAgICAgICAgPGRpdiBjbGFzcz0iY29ycmVvLW1hcmtldGluZy1yZWNpcGllbnQtY2FyZCBtYi0z
Ij4K
CENTRAL_PATCH_9_4
 [[ "$(digest "$TMP/patches/9/4.patch")" == 'e52b116f8802a388a670e4009a82ba3e9c9801f9e803183398d9533b1d285917' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/10"
 base64 --decode > "$TMP/patches/10/1.patch" <<'CENTRAL_PATCH_10_1'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb3NfbWFya2V0aW5nLmpzIGIvcHVi
bGljL2phdmFzY3JpcHQvY29ycmVvc19tYXJrZXRpbmcuanMKLS0tIGEvcHVibGljL2phdmFzY3Jp
cHQvY29ycmVvc19tYXJrZXRpbmcuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQvY29ycmVvc19t
YXJrZXRpbmcuanMKQEAgLTg2LDYgKzg2LDkgQEAKICAgICAgICAgfTsKIAogICAgICAgICBjb25z
dCBtb3N0cmFyVG9hc3QgPSBmdW5jdGlvbiAobWVuc2FqZSwgZXNFcnJvcikgeworICAgICAgICAg
ICAgZG9jdW1lbnQucXVlcnlTZWxlY3RvckFsbCgnW2RhdGEtbWFya2V0aW5nLW1haWwtdG9hc3Rd
JykuZm9yRWFjaChmdW5jdGlvbiAoYW50ZXJpb3IpIHsKKyAgICAgICAgICAgICAgICBib290c3Ry
YXAuVG9hc3QuZ2V0SW5zdGFuY2UoYW50ZXJpb3IpPy5kaXNwb3NlKCk7IGFudGVyaW9yLnJlbW92
ZSgpOworICAgICAgICAgICAgfSk7CiAgICAgICAgICAgICBsZXQgY29udGVuZWRvciA9IGRvY3Vt
ZW50LnF1ZXJ5U2VsZWN0b3IoJy50b2FzdC1jb250YWluZXInKTsKIAogICAgICAgICAgICAgaWYg
KCFjb250ZW5lZG9yKSB7CkBAIC05Nyw2ICsxMDAsNyBAQAogICAgICAgICAgICAgfQogCiAgICAg
ICAgICAgICBjb25zdCB0b2FzdCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpOworICAg
ICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdkYXRhLW1hcmtldGluZy1tYWlsLXRvYXN0Jywg
JycpOwogICAgICAgICAgICAgdG9hc3QuY2xhc3NOYW1lID0gJ3RvYXN0IHN5c3RlbS10b2FzdCcg
KwogICAgICAgICAgICAgICAgIChlc0Vycm9yID8gJyBzeXN0ZW0tdG9hc3QtZXJyb3InIDogJycp
OwogICAgICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdyb2xlJywgZXNFcnJvciA/ICdhbGVy
dCcgOiAnc3RhdHVzJyk7CkBAIC0xMjcsNiArMTMxLDU0IEBACiAgICAgICAgICAgICAgICAgZGVs
YXk6IGVzRXJyb3IgPyA0NTAwIDogMzAwMAogICAgICAgICAgICAgfSkuc2hvdygpOwogICAgICAg
ICB9OworCisgICAgICAgIGNvbnN0IGlkZW50aWRhZEVsZW1lbnRvID0gbW9kYWxFbGVtZW50LnF1
ZXJ5U2VsZWN0b3IoJ1tkYXRhLW1hcmtldGluZy1tYWlsLWlkZW50aXR5XScpOworICAgICAgICBs
ZXQgaWRlbnRpZGFkQWN0dWFsID0gbnVsbDsKKyAgICAgICAgbGV0IGNvbnN1bHRhSWRlbnRpZGFk
ID0gMDsKKyAgICAgICAgbGV0IGVzcGVyYUlkZW50aWRhZDsKKyAgICAgICAgY29uc3QgY29tcHJv
YmFyUmVtaXRlbnRlID0gYXN5bmMgZnVuY3Rpb24gKCkgeworICAgICAgICAgICAgY29uc3QgY29u
dGFjdG8gPSBTdHJpbmcocmVtaXRlbnRlPy52YWx1ZSB8fCAnJykudHJpbSgpLnRvTG93ZXJDYXNl
KCk7CisgICAgICAgICAgICBjb25zdCBzZWN1ZW5jaWEgPSArK2NvbnN1bHRhSWRlbnRpZGFkOwor
ICAgICAgICAgICAgY29uc3QgZGF0b3MgPSBuZXcgRm9ybURhdGEoKTsgZGF0b3Muc2V0KCdyZW1p
dGVudGUnLCBjb250YWN0byk7CisgICAgICAgICAgICBjb25zdCByZXNwb25zZSA9IGF3YWl0IGZl
dGNoKCdpbmRleC5waHA/Y29udHJvbGxlcj1jb3JyZW9NYXJrZXRpbmcmYWN0aW9uPWlkZW50aWRh
ZCcsIHsKKyAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJywgYm9keTogZGF0b3MsIGhlYWRl
cnM6IHsnWC1SZXF1ZXN0ZWQtV2l0aCc6ICdmZXRjaCd9CisgICAgICAgICAgICB9KTsKKyAgICAg
ICAgICAgIGNvbnN0IGpzb24gPSBhd2FpdCByZXNwb25zZS5qc29uKCk7CisgICAgICAgICAgICBp
ZiAoc2VjdWVuY2lhICE9PSBjb25zdWx0YUlkZW50aWRhZCB8fCBjb250YWN0byAhPT0gU3RyaW5n
KHJlbWl0ZW50ZT8udmFsdWUgfHwgJycpLnRyaW0oKS50b0xvd2VyQ2FzZSgpKSB7IHJldHVybiBu
dWxsOyB9CisgICAgICAgICAgICBpZiAoIXJlc3BvbnNlLm9rIHx8ICFqc29uLm9rKSB7CisgICAg
ICAgICAgICAgICAgaWRlbnRpZGFkQWN0dWFsID0gbnVsbDsKKyAgICAgICAgICAgICAgICBpZiAo
aWRlbnRpZGFkRWxlbWVudG8pIHsKKyAgICAgICAgICAgICAgICAgICAgaWRlbnRpZGFkRWxlbWVu
dG8udGV4dENvbnRlbnQgPSBqc29uLm1lbnNhamUgfHwgJ05vIHNlIHB1ZG8gY29tcHJvYmFyIGVs
IHJlbWl0ZW50ZS4nOworICAgICAgICAgICAgICAgICAgICBpZiAoanNvbi5jb25leGlvbl9yZXF1
ZXJpZGEgJiYgdHlwZW9mIGpzb24uY29uZXhpb25fdXJsID09PSAnc3RyaW5nJyAmJiBqc29uLmNv
bmV4aW9uX3VybC5zdGFydHNXaXRoKCdpbmRleC5waHA/Y29udHJvbGxlcj1jb3JyZW9Db25leGlv
biZhY3Rpb249aW5kZXgmY29ycmVvPScpKSB7CisgICAgICAgICAgICAgICAgICAgICAgICBjb25z
dCBlbmxhY2UgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdhJyk7IGVubGFjZS5ocmVmID0ganNv
bi5jb25leGlvbl91cmw7CisgICAgICAgICAgICAgICAgICAgICAgICBlbmxhY2UudGFyZ2V0ID0g
J19ibGFuayc7IGVubGFjZS5yZWwgPSAnbm9vcGVuZXInOyBlbmxhY2UudGV4dENvbnRlbnQgPSAn
IEF1dG9yaXphciBtaSBHbWFpbCc7CisgICAgICAgICAgICAgICAgICAgICAgICBpZGVudGlkYWRF
bGVtZW50by5hcHBlbmRDaGlsZChlbmxhY2UpOworICAgICAgICAgICAgICAgICAgICB9CisgICAg
ICAgICAgICAgICAgfQorICAgICAgICAgICAgICAgIHJldHVybiBudWxsOworICAgICAgICAgICAg
fQorICAgICAgICAgICAgY29uc3QgaWRlbnRpdHkgPSBqc29uLmlkZW50aWRhZDsKKyAgICAgICAg
ICAgIGlkZW50aWRhZEFjdHVhbCA9IHtjb250YWN0bzogY29udGFjdG8sIHRva2VuOiBqc29uLmlk
ZW50aWRhZF9lbnZpbywgZmlybWE6IEpTT04uc3RyaW5naWZ5KGlkZW50aXR5KX07CisgICAgICAg
ICAgICBpZiAoaWRlbnRpZGFkRWxlbWVudG8pIHsKKyAgICAgICAgICAgICAgICBpZGVudGlkYWRF
bGVtZW50by50ZXh0Q29udGVudCA9ICdSZW1pdGVudGUgdmlzaWJsZTogJyArIGlkZW50aXR5LnJl
bWl0ZW50ZV92aXNpYmxlICsgJy4gUmVzcHVlc3RhcyBhOiAnICsgaWRlbnRpdHkucmVwbHlfdG8g
KyAnLiAnICsgKGlkZW50aXR5LmF2aXNvIHx8ICcnKTsKKyAgICAgICAgICAgIH0KKyAgICAgICAg
ICAgIHJldHVybiBpZGVudGlkYWRBY3R1YWw7CisgICAgICAgIH07CisgICAgICAgIGNvbnN0IGFj
dHVhbGl6YXJSZW1pdGVudGUgPSBmdW5jdGlvbiAoKSB7CisgICAgICAgICAgICBpZGVudGlkYWRB
Y3R1YWwgPSBudWxsOworICAgICAgICAgICAgaWYgKGlkZW50aWRhZEVsZW1lbnRvKSB7IGlkZW50
aWRhZEVsZW1lbnRvLnRleHRDb250ZW50ID0gJ0NvbXByb2JhbmRvIHJlbWl0ZW50ZSBpbnN0aXR1
Y2lvbmFs4oCmJzsgfQorICAgICAgICAgICAgY29tcHJvYmFyUmVtaXRlbnRlKCkuY2F0Y2goZnVu
Y3Rpb24gKCkgeworICAgICAgICAgICAgICAgIGlkZW50aWRhZEFjdHVhbCA9IG51bGw7CisgICAg
ICAgICAgICAgICAgaWYgKGlkZW50aWRhZEVsZW1lbnRvKSB7IGlkZW50aWRhZEVsZW1lbnRvLnRl
eHRDb250ZW50ID0gJ05vIHNlIHB1ZG8gY29tcHJvYmFyIGVsIHJlbWl0ZW50ZSBpbnN0aXR1Y2lv
bmFsLiBWdWVsdmUgYSBpbnRlbnRhcmxvLic7IH0KKyAgICAgICAgICAgIH0pOworICAgICAgICB9
OworICAgICAgICBtb2RhbEVsZW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignc2hvd24uYnMubW9kYWwn
LCBhY3R1YWxpemFyUmVtaXRlbnRlKTsKKyAgICAgICAgd2luZG93LmFkZEV2ZW50TGlzdGVuZXIo
J2ZvY3VzJywgZnVuY3Rpb24gKCkgeyBpZiAobW9kYWxFbGVtZW50LmNsYXNzTGlzdC5jb250YWlu
cygnc2hvdycpKSB7IGFjdHVhbGl6YXJSZW1pdGVudGUoKTsgfSB9KTsKKyAgICAgICAgcmVtaXRl
bnRlPy5hZGRFdmVudExpc3RlbmVyKCdpbnB1dCcsIGZ1bmN0aW9uICgpIHsKKyAgICAgICAgICAg
IGlkZW50aWRhZEFjdHVhbCA9IG51bGw7ICsrY29uc3VsdGFJZGVudGlkYWQ7IGNsZWFyVGltZW91
dChlc3BlcmFJZGVudGlkYWQpOworICAgICAgICAgICAgaWYgKGlkZW50aWRhZEVsZW1lbnRvKSB7
IGlkZW50aWRhZEVsZW1lbnRvLnRleHRDb250ZW50ID0gJyc7IH0KKyAgICAgICAgICAgIGVzcGVy
YUlkZW50aWRhZCA9IHdpbmRvdy5zZXRUaW1lb3V0KGFjdHVhbGl6YXJSZW1pdGVudGUsIDI1MCk7
CisgICAgICAgIH0pOwogCiAgICAgICAgIGNvbnN0IG1vc3RyYXJFcnJvciA9IGZ1bmN0aW9uICht
ZW5zYWplKSB7CiAgICAgICAgICAgICBtb3N0cmFyVG9hc3QoCkBAIC0xMzE0LDYgKzEzNjYsMTMg
QEAKICAgICAgICAgICAgIH0KIAogICAgICAgICAgICAgdHJ5IHsKKyAgICAgICAgICAgICAgICBj
b25zdCBhbnRlcmlvciA9IGlkZW50aWRhZEFjdHVhbDsKKyAgICAgICAgICAgICAgICBjb25zdCBh
Y3R1YWwgPSBhd2FpdCBjb21wcm9iYXJSZW1pdGVudGUoKTsKKyAgICAgICAgICAgICAgICBpZiAo
IWFjdHVhbCkgeyBtb3N0cmFyRXJyb3IoJ05vIHNlIHB1ZG8gY29tcHJvYmFyIGVsIHJlbWl0ZW50
ZSBpbnN0aXR1Y2lvbmFsLicpOyByZXR1cm47IH0KKyAgICAgICAgICAgICAgICBpZiAoIWFudGVy
aW9yIHx8IGFudGVyaW9yLmNvbnRhY3RvICE9PSBhY3R1YWwuY29udGFjdG8gfHwgYW50ZXJpb3Iu
ZmlybWEgIT09IGFjdHVhbC5maXJtYSkgeworICAgICAgICAgICAgICAgICAgICBtb3N0cmFyRXJy
b3IoJ1JldmlzYSBlbCByZW1pdGVudGUgdmlzaWJsZSB5IHB1bHNhIEVudmlhciBjb3JyZW8gcGFy
YSBjb25maXJtYXIuJyk7IHJldHVybjsKKyAgICAgICAgICAgICAgICB9CisgICAgICAgICAgICAg
ICAgZGF0b3Muc2V0KCdpZGVudGlkYWRfZW52aW8nLCBhY3R1YWwudG9rZW4pOwogICAgICAgICAg
ICAgICAgIGNvbnN0IHJlc3B1ZXN0YSA9IGF3YWl0IGZldGNoKHVybEVudmlhciwgewogICAgICAg
ICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJywKICAgICAgICAgICAgICAgICAgICAgYm9keTog
ZGF0b3MsCg==
CENTRAL_PATCH_10_1
 [[ "$(digest "$TMP/patches/10/1.patch")" == 'cd650c4c164fcb33e3039bab5c231d8b3b98aff667702ba26f48f4cdff1e64fa' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/10/2.patch" <<'CENTRAL_PATCH_10_2'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb3NfbWFya2V0aW5nLmpzIGIvcHVi
bGljL2phdmFzY3JpcHQvY29ycmVvc19tYXJrZXRpbmcuanMKLS0tIGEvcHVibGljL2phdmFzY3Jp
cHQvY29ycmVvc19tYXJrZXRpbmcuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQvY29ycmVvc19t
YXJrZXRpbmcuanMKQEAgLTg1LDcgKzg1LDEwIEBACiAgICAgICAgICAgICAvLyBMb3MgYXZpc29z
IGRlbCBmb3JtdWxhcmlvIHNlIG11ZXN0cmFuIG1lZGlhbnRlIHRvYXN0LgogICAgICAgICB9Owog
Ci0gICAgICAgIGNvbnN0IG1vc3RyYXJUb2FzdCA9IGZ1bmN0aW9uIChtZW5zYWplLCBlc0Vycm9y
LCBjb25leGlvblVybCkgeworICAgICAgICBjb25zdCBtb3N0cmFyVG9hc3QgPSBmdW5jdGlvbiAo
bWVuc2FqZSwgZXNFcnJvcikgeworICAgICAgICAgICAgZG9jdW1lbnQucXVlcnlTZWxlY3RvckFs
bCgnW2RhdGEtbWFya2V0aW5nLW1haWwtdG9hc3RdJykuZm9yRWFjaChmdW5jdGlvbiAoYW50ZXJp
b3IpIHsKKyAgICAgICAgICAgICAgICBib290c3RyYXAuVG9hc3QuZ2V0SW5zdGFuY2UoYW50ZXJp
b3IpPy5kaXNwb3NlKCk7IGFudGVyaW9yLnJlbW92ZSgpOworICAgICAgICAgICAgfSk7CiAgICAg
ICAgICAgICBsZXQgY29udGVuZWRvciA9IGRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJy50b2FzdC1j
b250YWluZXInKTsKIAogICAgICAgICAgICAgaWYgKCFjb250ZW5lZG9yKSB7CkBAIC05Nyw2ICsx
MDAsNyBAQAogICAgICAgICAgICAgfQogCiAgICAgICAgICAgICBjb25zdCB0b2FzdCA9IGRvY3Vt
ZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpOworICAgICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRl
KCdkYXRhLW1hcmtldGluZy1tYWlsLXRvYXN0JywgJycpOwogICAgICAgICAgICAgdG9hc3QuY2xh
c3NOYW1lID0gJ3RvYXN0IHN5c3RlbS10b2FzdCcgKwogICAgICAgICAgICAgICAgIChlc0Vycm9y
ID8gJyBzeXN0ZW0tdG9hc3QtZXJyb3InIDogJycpOwogICAgICAgICAgICAgdG9hc3Quc2V0QXR0
cmlidXRlKCdyb2xlJywgZXNFcnJvciA/ICdhbGVydCcgOiAnc3RhdHVzJyk7CkBAIC0xMTYsMTUg
KzEyMCw2IEBACiAgICAgICAgICAgICAgICAgICAgID8gJ05vIGZ1ZSBwb3NpYmxlIGVudmlhciBl
bCBjb3JyZW8uJwogICAgICAgICAgICAgICAgICAgICA6ICdDb3JyZW8gZW52aWFkbyBjb3JyZWN0
YW1lbnRlLicpCiAgICAgICAgICAgICApOwotICAgICAgICAgICAgaWYgKGNvbmV4aW9uVXJsKSB7
Ci0gICAgICAgICAgICAgICAgY29uc3QgdXJsID0gbmV3IFVSTChjb25leGlvblVybCwgd2luZG93
LmxvY2F0aW9uLmhyZWYpOwotICAgICAgICAgICAgICAgIGlmICh1cmwub3JpZ2luID09PSB3aW5k
b3cubG9jYXRpb24ub3JpZ2luICYmIHVybC5zZWFyY2hQYXJhbXMuZ2V0KCdjb250cm9sbGVyJykg
PT09ICdjb3JyZW9Db25leGlvbicgJiYgdXJsLnNlYXJjaFBhcmFtcy5nZXQoJ2FjdGlvbicpID09
PSAnaW5kZXgnKSB7Ci0gICAgICAgICAgICAgICAgICAgIGNvbnN0IGxpbmsgPSBkb2N1bWVudC5j
cmVhdGVFbGVtZW50KCdhJyk7Ci0gICAgICAgICAgICAgICAgICAgIGxpbmsuaHJlZiA9IHVybC5o
cmVmOyBsaW5rLnRhcmdldCA9ICdfYmxhbmsnOyBsaW5rLnJlbCA9ICdub29wZW5lciBub3JlZmVy
cmVyJzsKLSAgICAgICAgICAgICAgICAgICAgbGluay50ZXh0Q29udGVudCA9ICcgQ29uZWN0YXIg
Y3VlbnRhIGRlIGNvcnJlbyc7Ci0gICAgICAgICAgICAgICAgICAgIHRvYXN0LnF1ZXJ5U2VsZWN0
b3IoJ3NwYW4nKS5hcHBlbmRDaGlsZChsaW5rKTsKLSAgICAgICAgICAgICAgICB9Ci0gICAgICAg
ICAgICB9CiAKICAgICAgICAgICAgIGNvbnRlbmVkb3IuYXBwZW5kQ2hpbGQodG9hc3QpOwogICAg
ICAgICAgICAgdG9hc3QuYWRkRXZlbnRMaXN0ZW5lcignaGlkZGVuLmJzLnRvYXN0JywgZnVuY3Rp
b24gKCkgewpAQCAtMTMzLDkgKzEyOCw1NyBAQAogCiAgICAgICAgICAgICBib290c3RyYXAuVG9h
c3QuZ2V0T3JDcmVhdGVJbnN0YW5jZSh0b2FzdCwgewogICAgICAgICAgICAgICAgIGF1dG9oaWRl
OiB0cnVlLAotICAgICAgICAgICAgICAgIGRlbGF5OiBjb25leGlvblVybCA/IDMwMDAwIDogKGVz
RXJyb3IgPyA0NTAwIDogMzAwMCkKKyAgICAgICAgICAgICAgICBkZWxheTogZXNFcnJvciA/IDQ1
MDAgOiAzMDAwCiAgICAgICAgICAgICB9KS5zaG93KCk7CiAgICAgICAgIH07CisKKyAgICAgICAg
Y29uc3QgaWRlbnRpZGFkRWxlbWVudG8gPSBtb2RhbEVsZW1lbnQucXVlcnlTZWxlY3RvcignW2Rh
dGEtbWFya2V0aW5nLW1haWwtaWRlbnRpdHldJyk7CisgICAgICAgIGxldCBpZGVudGlkYWRBY3R1
YWwgPSBudWxsOworICAgICAgICBsZXQgY29uc3VsdGFJZGVudGlkYWQgPSAwOworICAgICAgICBs
ZXQgZXNwZXJhSWRlbnRpZGFkOworICAgICAgICBjb25zdCBjb21wcm9iYXJSZW1pdGVudGUgPSBh
c3luYyBmdW5jdGlvbiAoKSB7CisgICAgICAgICAgICBjb25zdCBjb250YWN0byA9IFN0cmluZyhy
ZW1pdGVudGU/LnZhbHVlIHx8ICcnKS50cmltKCkudG9Mb3dlckNhc2UoKTsKKyAgICAgICAgICAg
IGNvbnN0IHNlY3VlbmNpYSA9ICsrY29uc3VsdGFJZGVudGlkYWQ7CisgICAgICAgICAgICBjb25z
dCBkYXRvcyA9IG5ldyBGb3JtRGF0YSgpOyBkYXRvcy5zZXQoJ3JlbWl0ZW50ZScsIGNvbnRhY3Rv
KTsKKyAgICAgICAgICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2goJ2luZGV4LnBocD9j
b250cm9sbGVyPWNvcnJlb01hcmtldGluZyZhY3Rpb249aWRlbnRpZGFkJywgeworICAgICAgICAg
ICAgICAgIG1ldGhvZDogJ1BPU1QnLCBib2R5OiBkYXRvcywgaGVhZGVyczogeydYLVJlcXVlc3Rl
ZC1XaXRoJzogJ2ZldGNoJ30KKyAgICAgICAgICAgIH0pOworICAgICAgICAgICAgY29uc3QganNv
biA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKTsKKyAgICAgICAgICAgIGlmIChzZWN1ZW5jaWEgIT09
IGNvbnN1bHRhSWRlbnRpZGFkIHx8IGNvbnRhY3RvICE9PSBTdHJpbmcocmVtaXRlbnRlPy52YWx1
ZSB8fCAnJykudHJpbSgpLnRvTG93ZXJDYXNlKCkpIHsgcmV0dXJuIG51bGw7IH0KKyAgICAgICAg
ICAgIGlmICghcmVzcG9uc2Uub2sgfHwgIWpzb24ub2spIHsKKyAgICAgICAgICAgICAgICBpZGVu
dGlkYWRBY3R1YWwgPSBudWxsOworICAgICAgICAgICAgICAgIGlmIChpZGVudGlkYWRFbGVtZW50
bykgeworICAgICAgICAgICAgICAgICAgICBpZGVudGlkYWRFbGVtZW50by50ZXh0Q29udGVudCA9
IGpzb24ubWVuc2FqZSB8fCAnTm8gc2UgcHVkbyBjb21wcm9iYXIgZWwgcmVtaXRlbnRlLic7Cisg
ICAgICAgICAgICAgICAgICAgIGlmIChqc29uLmNvbmV4aW9uX3JlcXVlcmlkYSAmJiB0eXBlb2Yg
anNvbi5jb25leGlvbl91cmwgPT09ICdzdHJpbmcnICYmIGpzb24uY29uZXhpb25fdXJsLnN0YXJ0
c1dpdGgoJ2luZGV4LnBocD9jb250cm9sbGVyPWNvcnJlb0NvbmV4aW9uJmFjdGlvbj1pbmRleCZj
b3JyZW89JykpIHsKKyAgICAgICAgICAgICAgICAgICAgICAgIGNvbnN0IGVubGFjZSA9IGRvY3Vt
ZW50LmNyZWF0ZUVsZW1lbnQoJ2EnKTsgZW5sYWNlLmhyZWYgPSBqc29uLmNvbmV4aW9uX3VybDsK
KyAgICAgICAgICAgICAgICAgICAgICAgIGVubGFjZS50YXJnZXQgPSAnX2JsYW5rJzsgZW5sYWNl
LnJlbCA9ICdub29wZW5lcic7IGVubGFjZS50ZXh0Q29udGVudCA9ICcgQXV0b3JpemFyIG1pIEdt
YWlsJzsKKyAgICAgICAgICAgICAgICAgICAgICAgIGlkZW50aWRhZEVsZW1lbnRvLmFwcGVuZENo
aWxkKGVubGFjZSk7CisgICAgICAgICAgICAgICAgICAgIH0KKyAgICAgICAgICAgICAgICB9Cisg
ICAgICAgICAgICAgICAgcmV0dXJuIG51bGw7CisgICAgICAgICAgICB9CisgICAgICAgICAgICBj
b25zdCBpZGVudGl0eSA9IGpzb24uaWRlbnRpZGFkOworICAgICAgICAgICAgaWRlbnRpZGFkQWN0
dWFsID0ge2NvbnRhY3RvOiBjb250YWN0bywgdG9rZW46IGpzb24uaWRlbnRpZGFkX2VudmlvLCBm
aXJtYTogSlNPTi5zdHJpbmdpZnkoaWRlbnRpdHkpfTsKKyAgICAgICAgICAgIGlmIChpZGVudGlk
YWRFbGVtZW50bykgeworICAgICAgICAgICAgICAgIGlkZW50aWRhZEVsZW1lbnRvLnRleHRDb250
ZW50ID0gJ1JlbWl0ZW50ZSB2aXNpYmxlOiAnICsgaWRlbnRpdHkucmVtaXRlbnRlX3Zpc2libGUg
KyAnLiBSZXNwdWVzdGFzIGE6ICcgKyBpZGVudGl0eS5yZXBseV90byArICcuICcgKyAoaWRlbnRp
dHkuYXZpc28gfHwgJycpOworICAgICAgICAgICAgfQorICAgICAgICAgICAgcmV0dXJuIGlkZW50
aWRhZEFjdHVhbDsKKyAgICAgICAgfTsKKyAgICAgICAgY29uc3QgYWN0dWFsaXphclJlbWl0ZW50
ZSA9IGZ1bmN0aW9uICgpIHsKKyAgICAgICAgICAgIGlkZW50aWRhZEFjdHVhbCA9IG51bGw7Cisg
ICAgICAgICAgICBpZiAoaWRlbnRpZGFkRWxlbWVudG8pIHsgaWRlbnRpZGFkRWxlbWVudG8udGV4
dENvbnRlbnQgPSAnQ29tcHJvYmFuZG8gcmVtaXRlbnRlIGluc3RpdHVjaW9uYWzigKYnOyB9Cisg
ICAgICAgICAgICBjb21wcm9iYXJSZW1pdGVudGUoKS5jYXRjaChmdW5jdGlvbiAoKSB7CisgICAg
ICAgICAgICAgICAgaWRlbnRpZGFkQWN0dWFsID0gbnVsbDsKKyAgICAgICAgICAgICAgICBpZiAo
aWRlbnRpZGFkRWxlbWVudG8pIHsgaWRlbnRpZGFkRWxlbWVudG8udGV4dENvbnRlbnQgPSAnTm8g
c2UgcHVkbyBjb21wcm9iYXIgZWwgcmVtaXRlbnRlIGluc3RpdHVjaW9uYWwuIFZ1ZWx2ZSBhIGlu
dGVudGFybG8uJzsgfQorICAgICAgICAgICAgfSk7CisgICAgICAgIH07CisgICAgICAgIG1vZGFs
RWxlbWVudC5hZGRFdmVudExpc3RlbmVyKCdzaG93bi5icy5tb2RhbCcsIGFjdHVhbGl6YXJSZW1p
dGVudGUpOworICAgICAgICB3aW5kb3cuYWRkRXZlbnRMaXN0ZW5lcignZm9jdXMnLCBmdW5jdGlv
biAoKSB7IGlmIChtb2RhbEVsZW1lbnQuY2xhc3NMaXN0LmNvbnRhaW5zKCdzaG93JykpIHsgYWN0
dWFsaXphclJlbWl0ZW50ZSgpOyB9IH0pOworICAgICAgICByZW1pdGVudGU/LmFkZEV2ZW50TGlz
dGVuZXIoJ2lucHV0JywgZnVuY3Rpb24gKCkgeworICAgICAgICAgICAgaWRlbnRpZGFkQWN0dWFs
ID0gbnVsbDsgKytjb25zdWx0YUlkZW50aWRhZDsgY2xlYXJUaW1lb3V0KGVzcGVyYUlkZW50aWRh
ZCk7CisgICAgICAgICAgICBpZiAoaWRlbnRpZGFkRWxlbWVudG8pIHsgaWRlbnRpZGFkRWxlbWVu
dG8udGV4dENvbnRlbnQgPSAnJzsgfQorICAgICAgICAgICAgZXNwZXJhSWRlbnRpZGFkID0gd2lu
ZG93LnNldFRpbWVvdXQoYWN0dWFsaXphclJlbWl0ZW50ZSwgMjUwKTsKKyAgICAgICAgfSk7CiAK
ICAgICAgICAgY29uc3QgbW9zdHJhckVycm9yID0gZnVuY3Rpb24gKG1lbnNhamUpIHsKICAgICAg
ICAgICAgIG1vc3RyYXJUb2FzdCgKQEAgLTEzMjMsNiArMTM2NiwxMyBAQAogICAgICAgICAgICAg
fQogCiAgICAgICAgICAgICB0cnkgeworICAgICAgICAgICAgICAgIGNvbnN0IGFudGVyaW9yID0g
aWRlbnRpZGFkQWN0dWFsOworICAgICAgICAgICAgICAgIGNvbnN0IGFjdHVhbCA9IGF3YWl0IGNv
bXByb2JhclJlbWl0ZW50ZSgpOworICAgICAgICAgICAgICAgIGlmICghYWN0dWFsKSB7IG1vc3Ry
YXJFcnJvcignTm8gc2UgcHVkbyBjb21wcm9iYXIgZWwgcmVtaXRlbnRlIGluc3RpdHVjaW9uYWwu
Jyk7IHJldHVybjsgfQorICAgICAgICAgICAgICAgIGlmICghYW50ZXJpb3IgfHwgYW50ZXJpb3Iu
Y29udGFjdG8gIT09IGFjdHVhbC5jb250YWN0byB8fCBhbnRlcmlvci5maXJtYSAhPT0gYWN0dWFs
LmZpcm1hKSB7CisgICAgICAgICAgICAgICAgICAgIG1vc3RyYXJFcnJvcignUmV2aXNhIGVsIHJl
bWl0ZW50ZSB2aXNpYmxlIHkgcHVsc2EgRW52aWFyIGNvcnJlbyBwYXJhIGNvbmZpcm1hci4nKTsg
cmV0dXJuOworICAgICAgICAgICAgICAgIH0KKyAgICAgICAgICAgICAgICBkYXRvcy5zZXQoJ2lk
ZW50aWRhZF9lbnZpbycsIGFjdHVhbC50b2tlbik7CiAgICAgICAgICAgICAgICAgY29uc3QgcmVz
cHVlc3RhID0gYXdhaXQgZmV0Y2godXJsRW52aWFyLCB7CiAgICAgICAgICAgICAgICAgICAgIG1l
dGhvZDogJ1BPU1QnLAogICAgICAgICAgICAgICAgICAgICBib2R5OiBkYXRvcywKQEAgLTEzNDIs
MTAgKzEzOTIsNiBAQAogICAgICAgICAgICAgICAgIH0KIAogICAgICAgICAgICAgICAgIGlmICgh
cmVzcHVlc3RhLm9rIHx8ICFqc29uLm9rKSB7Ci0gICAgICAgICAgICAgICAgICAgIGlmIChqc29u
LmNvbmV4aW9uX3JlcXVlcmlkYSAmJiBqc29uLmNvbmV4aW9uX3VybCkgewotICAgICAgICAgICAg
ICAgICAgICAgICAgbW9zdHJhclRvYXN0KGpzb24ubWVuc2FqZSwgdHJ1ZSwganNvbi5jb25leGlv
bl91cmwpOwotICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuOwotICAgICAgICAgICAgICAg
ICAgICB9CiAgICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcigKICAgICAgICAgICAg
ICAgICAgICAgICAgIGpzb24ubWVuc2FqZSB8fAogICAgICAgICAgICAgICAgICAgICAgICAgJ05v
IGZ1ZSBwb3NpYmxlIGVudmlhciBlbCBjb3JyZW8uJwo=
CENTRAL_PATCH_10_2
 [[ "$(digest "$TMP/patches/10/2.patch")" == 'c273a0078a9f2d045112baeee05d37024f1d021e74165acac2025f91b67b112f' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/10/3.patch" <<'CENTRAL_PATCH_10_3'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb3NfbWFya2V0aW5nLmpzIGIvcHVi
bGljL2phdmFzY3JpcHQvY29ycmVvc19tYXJrZXRpbmcuanMKLS0tIGEvcHVibGljL2phdmFzY3Jp
cHQvY29ycmVvc19tYXJrZXRpbmcuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQvY29ycmVvc19t
YXJrZXRpbmcuanMKQEAgLTg1LDExICs4NSw5IEBACiAgICAgICAgICAgICAvLyBMb3MgYXZpc29z
IGRlbCBmb3JtdWxhcmlvIHNlIG11ZXN0cmFuIG1lZGlhbnRlIHRvYXN0LgogICAgICAgICB9Owog
Ci0gICAgICAgIGNvbnN0IG1vc3RyYXJUb2FzdCA9IGZ1bmN0aW9uIChtZW5zYWplLCBlc0Vycm9y
LCBjb25leGlvblVybCkgewotICAgICAgICAgICAgLy8gU3VzdGl0dWlyIGVsIGF2aXNvIGFudGVy
aW9yIGRlIGVzdGUgbcOzZHVsbyBlbiBsb3MgcmVpbnRlbnRvcy4KKyAgICAgICAgY29uc3QgbW9z
dHJhclRvYXN0ID0gZnVuY3Rpb24gKG1lbnNhamUsIGVzRXJyb3IpIHsKICAgICAgICAgICAgIGRv
Y3VtZW50LnF1ZXJ5U2VsZWN0b3JBbGwoJ1tkYXRhLW1hcmtldGluZy1tYWlsLXRvYXN0XScpLmZv
ckVhY2goZnVuY3Rpb24gKGFudGVyaW9yKSB7Ci0gICAgICAgICAgICAgICAgYm9vdHN0cmFwLlRv
YXN0LmdldEluc3RhbmNlKGFudGVyaW9yKT8uZGlzcG9zZSgpOwotICAgICAgICAgICAgICAgIGFu
dGVyaW9yLnJlbW92ZSgpOworICAgICAgICAgICAgICAgIGJvb3RzdHJhcC5Ub2FzdC5nZXRJbnN0
YW5jZShhbnRlcmlvcik/LmRpc3Bvc2UoKTsgYW50ZXJpb3IucmVtb3ZlKCk7CiAgICAgICAgICAg
ICB9KTsKICAgICAgICAgICAgIGxldCBjb250ZW5lZG9yID0gZG9jdW1lbnQucXVlcnlTZWxlY3Rv
cignLnRvYXN0LWNvbnRhaW5lcicpOwogCkBAIC0xMjIsMTUgKzEyMCw2IEBACiAgICAgICAgICAg
ICAgICAgICAgID8gJ05vIGZ1ZSBwb3NpYmxlIGVudmlhciBlbCBjb3JyZW8uJwogICAgICAgICAg
ICAgICAgICAgICA6ICdDb3JyZW8gZW52aWFkbyBjb3JyZWN0YW1lbnRlLicpCiAgICAgICAgICAg
ICApOwotICAgICAgICAgICAgaWYgKGNvbmV4aW9uVXJsKSB7Ci0gICAgICAgICAgICAgICAgY29u
c3QgdXJsID0gbmV3IFVSTChjb25leGlvblVybCwgd2luZG93LmxvY2F0aW9uLmhyZWYpOwotICAg
ICAgICAgICAgICAgIGlmICh1cmwub3JpZ2luID09PSB3aW5kb3cubG9jYXRpb24ub3JpZ2luICYm
IHVybC5zZWFyY2hQYXJhbXMuZ2V0KCdjb250cm9sbGVyJykgPT09ICdjb3JyZW9Db25leGlvbicg
JiYgdXJsLnNlYXJjaFBhcmFtcy5nZXQoJ2FjdGlvbicpID09PSAnaW5kZXgnKSB7Ci0gICAgICAg
ICAgICAgICAgICAgIGNvbnN0IGxpbmsgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdhJyk7Ci0g
ICAgICAgICAgICAgICAgICAgIGxpbmsuaHJlZiA9IHVybC5ocmVmOyBsaW5rLnRhcmdldCA9ICdf
YmxhbmsnOyBsaW5rLnJlbCA9ICdub29wZW5lciBub3JlZmVycmVyJzsKLSAgICAgICAgICAgICAg
ICAgICAgbGluay50ZXh0Q29udGVudCA9ICcgQ29uZWN0YXIgY3VlbnRhIGRlIGNvcnJlbyc7Ci0g
ICAgICAgICAgICAgICAgICAgIHRvYXN0LnF1ZXJ5U2VsZWN0b3IoJ3NwYW4nKS5hcHBlbmRDaGls
ZChsaW5rKTsKLSAgICAgICAgICAgICAgICB9Ci0gICAgICAgICAgICB9CiAKICAgICAgICAgICAg
IGNvbnRlbmVkb3IuYXBwZW5kQ2hpbGQodG9hc3QpOwogICAgICAgICAgICAgdG9hc3QuYWRkRXZl
bnRMaXN0ZW5lcignaGlkZGVuLmJzLnRvYXN0JywgZnVuY3Rpb24gKCkgewpAQCAtMTM5LDkgKzEy
OCw1NyBAQAogCiAgICAgICAgICAgICBib290c3RyYXAuVG9hc3QuZ2V0T3JDcmVhdGVJbnN0YW5j
ZSh0b2FzdCwgewogICAgICAgICAgICAgICAgIGF1dG9oaWRlOiB0cnVlLAotICAgICAgICAgICAg
ICAgIGRlbGF5OiBjb25leGlvblVybCA/IDMwMDAwIDogKGVzRXJyb3IgPyA0NTAwIDogMzAwMCkK
KyAgICAgICAgICAgICAgICBkZWxheTogZXNFcnJvciA/IDQ1MDAgOiAzMDAwCiAgICAgICAgICAg
ICB9KS5zaG93KCk7CiAgICAgICAgIH07CisKKyAgICAgICAgY29uc3QgaWRlbnRpZGFkRWxlbWVu
dG8gPSBtb2RhbEVsZW1lbnQucXVlcnlTZWxlY3RvcignW2RhdGEtbWFya2V0aW5nLW1haWwtaWRl
bnRpdHldJyk7CisgICAgICAgIGxldCBpZGVudGlkYWRBY3R1YWwgPSBudWxsOworICAgICAgICBs
ZXQgY29uc3VsdGFJZGVudGlkYWQgPSAwOworICAgICAgICBsZXQgZXNwZXJhSWRlbnRpZGFkOwor
ICAgICAgICBjb25zdCBjb21wcm9iYXJSZW1pdGVudGUgPSBhc3luYyBmdW5jdGlvbiAoKSB7Cisg
ICAgICAgICAgICBjb25zdCBjb250YWN0byA9IFN0cmluZyhyZW1pdGVudGU/LnZhbHVlIHx8ICcn
KS50cmltKCkudG9Mb3dlckNhc2UoKTsKKyAgICAgICAgICAgIGNvbnN0IHNlY3VlbmNpYSA9ICsr
Y29uc3VsdGFJZGVudGlkYWQ7CisgICAgICAgICAgICBjb25zdCBkYXRvcyA9IG5ldyBGb3JtRGF0
YSgpOyBkYXRvcy5zZXQoJ3JlbWl0ZW50ZScsIGNvbnRhY3RvKTsKKyAgICAgICAgICAgIGNvbnN0
IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2goJ2luZGV4LnBocD9jb250cm9sbGVyPWNvcnJlb01hcmtl
dGluZyZhY3Rpb249aWRlbnRpZGFkJywgeworICAgICAgICAgICAgICAgIG1ldGhvZDogJ1BPU1Qn
LCBib2R5OiBkYXRvcywgaGVhZGVyczogeydYLVJlcXVlc3RlZC1XaXRoJzogJ2ZldGNoJ30KKyAg
ICAgICAgICAgIH0pOworICAgICAgICAgICAgY29uc3QganNvbiA9IGF3YWl0IHJlc3BvbnNlLmpz
b24oKTsKKyAgICAgICAgICAgIGlmIChzZWN1ZW5jaWEgIT09IGNvbnN1bHRhSWRlbnRpZGFkIHx8
IGNvbnRhY3RvICE9PSBTdHJpbmcocmVtaXRlbnRlPy52YWx1ZSB8fCAnJykudHJpbSgpLnRvTG93
ZXJDYXNlKCkpIHsgcmV0dXJuIG51bGw7IH0KKyAgICAgICAgICAgIGlmICghcmVzcG9uc2Uub2sg
fHwgIWpzb24ub2spIHsKKyAgICAgICAgICAgICAgICBpZGVudGlkYWRBY3R1YWwgPSBudWxsOwor
ICAgICAgICAgICAgICAgIGlmIChpZGVudGlkYWRFbGVtZW50bykgeworICAgICAgICAgICAgICAg
ICAgICBpZGVudGlkYWRFbGVtZW50by50ZXh0Q29udGVudCA9IGpzb24ubWVuc2FqZSB8fCAnTm8g
c2UgcHVkbyBjb21wcm9iYXIgZWwgcmVtaXRlbnRlLic7CisgICAgICAgICAgICAgICAgICAgIGlm
IChqc29uLmNvbmV4aW9uX3JlcXVlcmlkYSAmJiB0eXBlb2YganNvbi5jb25leGlvbl91cmwgPT09
ICdzdHJpbmcnICYmIGpzb24uY29uZXhpb25fdXJsLnN0YXJ0c1dpdGgoJ2luZGV4LnBocD9jb250
cm9sbGVyPWNvcnJlb0NvbmV4aW9uJmFjdGlvbj1pbmRleCZjb3JyZW89JykpIHsKKyAgICAgICAg
ICAgICAgICAgICAgICAgIGNvbnN0IGVubGFjZSA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2En
KTsgZW5sYWNlLmhyZWYgPSBqc29uLmNvbmV4aW9uX3VybDsKKyAgICAgICAgICAgICAgICAgICAg
ICAgIGVubGFjZS50YXJnZXQgPSAnX2JsYW5rJzsgZW5sYWNlLnJlbCA9ICdub29wZW5lcic7IGVu
bGFjZS50ZXh0Q29udGVudCA9ICcgQXV0b3JpemFyIG1pIEdtYWlsJzsKKyAgICAgICAgICAgICAg
ICAgICAgICAgIGlkZW50aWRhZEVsZW1lbnRvLmFwcGVuZENoaWxkKGVubGFjZSk7CisgICAgICAg
ICAgICAgICAgICAgIH0KKyAgICAgICAgICAgICAgICB9CisgICAgICAgICAgICAgICAgcmV0dXJu
IG51bGw7CisgICAgICAgICAgICB9CisgICAgICAgICAgICBjb25zdCBpZGVudGl0eSA9IGpzb24u
aWRlbnRpZGFkOworICAgICAgICAgICAgaWRlbnRpZGFkQWN0dWFsID0ge2NvbnRhY3RvOiBjb250
YWN0bywgdG9rZW46IGpzb24uaWRlbnRpZGFkX2VudmlvLCBmaXJtYTogSlNPTi5zdHJpbmdpZnko
aWRlbnRpdHkpfTsKKyAgICAgICAgICAgIGlmIChpZGVudGlkYWRFbGVtZW50bykgeworICAgICAg
ICAgICAgICAgIGlkZW50aWRhZEVsZW1lbnRvLnRleHRDb250ZW50ID0gJ1JlbWl0ZW50ZSB2aXNp
YmxlOiAnICsgaWRlbnRpdHkucmVtaXRlbnRlX3Zpc2libGUgKyAnLiBSZXNwdWVzdGFzIGE6ICcg
KyBpZGVudGl0eS5yZXBseV90byArICcuICcgKyAoaWRlbnRpdHkuYXZpc28gfHwgJycpOworICAg
ICAgICAgICAgfQorICAgICAgICAgICAgcmV0dXJuIGlkZW50aWRhZEFjdHVhbDsKKyAgICAgICAg
fTsKKyAgICAgICAgY29uc3QgYWN0dWFsaXphclJlbWl0ZW50ZSA9IGZ1bmN0aW9uICgpIHsKKyAg
ICAgICAgICAgIGlkZW50aWRhZEFjdHVhbCA9IG51bGw7CisgICAgICAgICAgICBpZiAoaWRlbnRp
ZGFkRWxlbWVudG8pIHsgaWRlbnRpZGFkRWxlbWVudG8udGV4dENvbnRlbnQgPSAnQ29tcHJvYmFu
ZG8gcmVtaXRlbnRlIGluc3RpdHVjaW9uYWzigKYnOyB9CisgICAgICAgICAgICBjb21wcm9iYXJS
ZW1pdGVudGUoKS5jYXRjaChmdW5jdGlvbiAoKSB7CisgICAgICAgICAgICAgICAgaWRlbnRpZGFk
QWN0dWFsID0gbnVsbDsKKyAgICAgICAgICAgICAgICBpZiAoaWRlbnRpZGFkRWxlbWVudG8pIHsg
aWRlbnRpZGFkRWxlbWVudG8udGV4dENvbnRlbnQgPSAnTm8gc2UgcHVkbyBjb21wcm9iYXIgZWwg
cmVtaXRlbnRlIGluc3RpdHVjaW9uYWwuIFZ1ZWx2ZSBhIGludGVudGFybG8uJzsgfQorICAgICAg
ICAgICAgfSk7CisgICAgICAgIH07CisgICAgICAgIG1vZGFsRWxlbWVudC5hZGRFdmVudExpc3Rl
bmVyKCdzaG93bi5icy5tb2RhbCcsIGFjdHVhbGl6YXJSZW1pdGVudGUpOworICAgICAgICB3aW5k
b3cuYWRkRXZlbnRMaXN0ZW5lcignZm9jdXMnLCBmdW5jdGlvbiAoKSB7IGlmIChtb2RhbEVsZW1l
bnQuY2xhc3NMaXN0LmNvbnRhaW5zKCdzaG93JykpIHsgYWN0dWFsaXphclJlbWl0ZW50ZSgpOyB9
IH0pOworICAgICAgICByZW1pdGVudGU/LmFkZEV2ZW50TGlzdGVuZXIoJ2lucHV0JywgZnVuY3Rp
b24gKCkgeworICAgICAgICAgICAgaWRlbnRpZGFkQWN0dWFsID0gbnVsbDsgKytjb25zdWx0YUlk
ZW50aWRhZDsgY2xlYXJUaW1lb3V0KGVzcGVyYUlkZW50aWRhZCk7CisgICAgICAgICAgICBpZiAo
aWRlbnRpZGFkRWxlbWVudG8pIHsgaWRlbnRpZGFkRWxlbWVudG8udGV4dENvbnRlbnQgPSAnJzsg
fQorICAgICAgICAgICAgZXNwZXJhSWRlbnRpZGFkID0gd2luZG93LnNldFRpbWVvdXQoYWN0dWFs
aXphclJlbWl0ZW50ZSwgMjUwKTsKKyAgICAgICAgfSk7CiAKICAgICAgICAgY29uc3QgbW9zdHJh
ckVycm9yID0gZnVuY3Rpb24gKG1lbnNhamUpIHsKICAgICAgICAgICAgIG1vc3RyYXJUb2FzdCgK
QEAgLTEzMjksNiArMTM2NiwxMyBAQAogICAgICAgICAgICAgfQogCiAgICAgICAgICAgICB0cnkg
eworICAgICAgICAgICAgICAgIGNvbnN0IGFudGVyaW9yID0gaWRlbnRpZGFkQWN0dWFsOworICAg
ICAgICAgICAgICAgIGNvbnN0IGFjdHVhbCA9IGF3YWl0IGNvbXByb2JhclJlbWl0ZW50ZSgpOwor
ICAgICAgICAgICAgICAgIGlmICghYWN0dWFsKSB7IG1vc3RyYXJFcnJvcignTm8gc2UgcHVkbyBj
b21wcm9iYXIgZWwgcmVtaXRlbnRlIGluc3RpdHVjaW9uYWwuJyk7IHJldHVybjsgfQorICAgICAg
ICAgICAgICAgIGlmICghYW50ZXJpb3IgfHwgYW50ZXJpb3IuY29udGFjdG8gIT09IGFjdHVhbC5j
b250YWN0byB8fCBhbnRlcmlvci5maXJtYSAhPT0gYWN0dWFsLmZpcm1hKSB7CisgICAgICAgICAg
ICAgICAgICAgIG1vc3RyYXJFcnJvcignUmV2aXNhIGVsIHJlbWl0ZW50ZSB2aXNpYmxlIHkgcHVs
c2EgRW52aWFyIGNvcnJlbyBwYXJhIGNvbmZpcm1hci4nKTsgcmV0dXJuOworICAgICAgICAgICAg
ICAgIH0KKyAgICAgICAgICAgICAgICBkYXRvcy5zZXQoJ2lkZW50aWRhZF9lbnZpbycsIGFjdHVh
bC50b2tlbik7CiAgICAgICAgICAgICAgICAgY29uc3QgcmVzcHVlc3RhID0gYXdhaXQgZmV0Y2go
dXJsRW52aWFyLCB7CiAgICAgICAgICAgICAgICAgICAgIG1ldGhvZDogJ1BPU1QnLAogICAgICAg
ICAgICAgICAgICAgICBib2R5OiBkYXRvcywKQEAgLTEzNDgsMTAgKzEzOTIsNiBAQAogICAgICAg
ICAgICAgICAgIH0KIAogICAgICAgICAgICAgICAgIGlmICghcmVzcHVlc3RhLm9rIHx8ICFqc29u
Lm9rKSB7Ci0gICAgICAgICAgICAgICAgICAgIGlmIChqc29uLmNvbmV4aW9uX3JlcXVlcmlkYSAm
JiBqc29uLmNvbmV4aW9uX3VybCkgewotICAgICAgICAgICAgICAgICAgICAgICAgbW9zdHJhclRv
YXN0KGpzb24ubWVuc2FqZSwgdHJ1ZSwganNvbi5jb25leGlvbl91cmwpOwotICAgICAgICAgICAg
ICAgICAgICAgICAgcmV0dXJuOwotICAgICAgICAgICAgICAgICAgICB9CiAgICAgICAgICAgICAg
ICAgICAgIHRocm93IG5ldyBFcnJvcigKICAgICAgICAgICAgICAgICAgICAgICAgIGpzb24ubWVu
c2FqZSB8fAogICAgICAgICAgICAgICAgICAgICAgICAgJ05vIGZ1ZSBwb3NpYmxlIGVudmlhciBl
bCBjb3JyZW8uJwo=
CENTRAL_PATCH_10_3
 [[ "$(digest "$TMP/patches/10/3.patch")" == '67316e125fdc859ffb0934d89def96ae9c8e556e46b8933796889533479146b5' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/10/4.patch" <<'CENTRAL_PATCH_10_4'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb3NfbWFya2V0aW5nLmpzIGIvcHVi
bGljL2phdmFzY3JpcHQvY29ycmVvc19tYXJrZXRpbmcuanMKLS0tIGEvcHVibGljL2phdmFzY3Jp
cHQvY29ycmVvc19tYXJrZXRpbmcuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQvY29ycmVvc19t
YXJrZXRpbmcuanMKQEAgLTg2LDYgKzg2LDkgQEAKICAgICAgICAgfTsKIAogICAgICAgICBjb25z
dCBtb3N0cmFyVG9hc3QgPSBmdW5jdGlvbiAobWVuc2FqZSwgZXNFcnJvcikgeworICAgICAgICAg
ICAgZG9jdW1lbnQucXVlcnlTZWxlY3RvckFsbCgnW2RhdGEtbWFya2V0aW5nLW1haWwtdG9hc3Rd
JykuZm9yRWFjaChmdW5jdGlvbiAoYW50ZXJpb3IpIHsKKyAgICAgICAgICAgICAgICBib290c3Ry
YXAuVG9hc3QuZ2V0SW5zdGFuY2UoYW50ZXJpb3IpPy5kaXNwb3NlKCk7IGFudGVyaW9yLnJlbW92
ZSgpOworICAgICAgICAgICAgfSk7CiAgICAgICAgICAgICBsZXQgY29udGVuZWRvciA9IGRvY3Vt
ZW50LnF1ZXJ5U2VsZWN0b3IoJy50b2FzdC1jb250YWluZXInKTsKIAogICAgICAgICAgICAgaWYg
KCFjb250ZW5lZG9yKSB7CkBAIC05Nyw2ICsxMDAsNyBAQAogICAgICAgICAgICAgfQogCiAgICAg
ICAgICAgICBjb25zdCB0b2FzdCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpOworICAg
ICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdkYXRhLW1hcmtldGluZy1tYWlsLXRvYXN0Jywg
JycpOwogICAgICAgICAgICAgdG9hc3QuY2xhc3NOYW1lID0gJ3RvYXN0IHN5c3RlbS10b2FzdCcg
KwogICAgICAgICAgICAgICAgIChlc0Vycm9yID8gJyBzeXN0ZW0tdG9hc3QtZXJyb3InIDogJycp
OwogICAgICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdyb2xlJywgZXNFcnJvciA/ICdhbGVy
dCcgOiAnc3RhdHVzJyk7CkBAIC0xMjcsNiArMTMxLDU0IEBACiAgICAgICAgICAgICAgICAgZGVs
YXk6IGVzRXJyb3IgPyA0NTAwIDogMzAwMAogICAgICAgICAgICAgfSkuc2hvdygpOwogICAgICAg
ICB9OworCisgICAgICAgIGNvbnN0IGlkZW50aWRhZEVsZW1lbnRvID0gbW9kYWxFbGVtZW50LnF1
ZXJ5U2VsZWN0b3IoJ1tkYXRhLW1hcmtldGluZy1tYWlsLWlkZW50aXR5XScpOworICAgICAgICBs
ZXQgaWRlbnRpZGFkQWN0dWFsID0gbnVsbDsKKyAgICAgICAgbGV0IGNvbnN1bHRhSWRlbnRpZGFk
ID0gMDsKKyAgICAgICAgbGV0IGVzcGVyYUlkZW50aWRhZDsKKyAgICAgICAgY29uc3QgY29tcHJv
YmFyUmVtaXRlbnRlID0gYXN5bmMgZnVuY3Rpb24gKCkgeworICAgICAgICAgICAgY29uc3QgY29u
dGFjdG8gPSBTdHJpbmcocmVtaXRlbnRlPy52YWx1ZSB8fCAnJykudHJpbSgpLnRvTG93ZXJDYXNl
KCk7CisgICAgICAgICAgICBjb25zdCBzZWN1ZW5jaWEgPSArK2NvbnN1bHRhSWRlbnRpZGFkOwor
ICAgICAgICAgICAgY29uc3QgZGF0b3MgPSBuZXcgRm9ybURhdGEoKTsgZGF0b3Muc2V0KCdyZW1p
dGVudGUnLCBjb250YWN0byk7CisgICAgICAgICAgICBjb25zdCByZXNwb25zZSA9IGF3YWl0IGZl
dGNoKCdpbmRleC5waHA/Y29udHJvbGxlcj1jb3JyZW9NYXJrZXRpbmcmYWN0aW9uPWlkZW50aWRh
ZCcsIHsKKyAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJywgYm9keTogZGF0b3MsIGhlYWRl
cnM6IHsnWC1SZXF1ZXN0ZWQtV2l0aCc6ICdmZXRjaCd9CisgICAgICAgICAgICB9KTsKKyAgICAg
ICAgICAgIGNvbnN0IGpzb24gPSBhd2FpdCByZXNwb25zZS5qc29uKCk7CisgICAgICAgICAgICBp
ZiAoc2VjdWVuY2lhICE9PSBjb25zdWx0YUlkZW50aWRhZCB8fCBjb250YWN0byAhPT0gU3RyaW5n
KHJlbWl0ZW50ZT8udmFsdWUgfHwgJycpLnRyaW0oKS50b0xvd2VyQ2FzZSgpKSB7IHJldHVybiBu
dWxsOyB9CisgICAgICAgICAgICBpZiAoIXJlc3BvbnNlLm9rIHx8ICFqc29uLm9rKSB7CisgICAg
ICAgICAgICAgICAgaWRlbnRpZGFkQWN0dWFsID0gbnVsbDsKKyAgICAgICAgICAgICAgICBpZiAo
aWRlbnRpZGFkRWxlbWVudG8pIHsKKyAgICAgICAgICAgICAgICAgICAgaWRlbnRpZGFkRWxlbWVu
dG8udGV4dENvbnRlbnQgPSBqc29uLm1lbnNhamUgfHwgJ05vIHNlIHB1ZG8gY29tcHJvYmFyIGVs
IHJlbWl0ZW50ZS4nOworICAgICAgICAgICAgICAgICAgICBpZiAoanNvbi5jb25leGlvbl9yZXF1
ZXJpZGEgJiYgdHlwZW9mIGpzb24uY29uZXhpb25fdXJsID09PSAnc3RyaW5nJyAmJiBqc29uLmNv
bmV4aW9uX3VybC5zdGFydHNXaXRoKCdpbmRleC5waHA/Y29udHJvbGxlcj1jb3JyZW9Db25leGlv
biZhY3Rpb249aW5kZXgmY29ycmVvPScpKSB7CisgICAgICAgICAgICAgICAgICAgICAgICBjb25z
dCBlbmxhY2UgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdhJyk7IGVubGFjZS5ocmVmID0ganNv
bi5jb25leGlvbl91cmw7CisgICAgICAgICAgICAgICAgICAgICAgICBlbmxhY2UudGFyZ2V0ID0g
J19ibGFuayc7IGVubGFjZS5yZWwgPSAnbm9vcGVuZXInOyBlbmxhY2UudGV4dENvbnRlbnQgPSAn
IEF1dG9yaXphciBtaSBHbWFpbCc7CisgICAgICAgICAgICAgICAgICAgICAgICBpZGVudGlkYWRF
bGVtZW50by5hcHBlbmRDaGlsZChlbmxhY2UpOworICAgICAgICAgICAgICAgICAgICB9CisgICAg
ICAgICAgICAgICAgfQorICAgICAgICAgICAgICAgIHJldHVybiBudWxsOworICAgICAgICAgICAg
fQorICAgICAgICAgICAgY29uc3QgaWRlbnRpdHkgPSBqc29uLmlkZW50aWRhZDsKKyAgICAgICAg
ICAgIGlkZW50aWRhZEFjdHVhbCA9IHtjb250YWN0bzogY29udGFjdG8sIHRva2VuOiBqc29uLmlk
ZW50aWRhZF9lbnZpbywgZmlybWE6IEpTT04uc3RyaW5naWZ5KGlkZW50aXR5KX07CisgICAgICAg
ICAgICBpZiAoaWRlbnRpZGFkRWxlbWVudG8pIHsKKyAgICAgICAgICAgICAgICBpZGVudGlkYWRF
bGVtZW50by50ZXh0Q29udGVudCA9ICdSZW1pdGVudGUgdmlzaWJsZTogJyArIGlkZW50aXR5LnJl
bWl0ZW50ZV92aXNpYmxlICsgJy4gUmVzcHVlc3RhcyBhOiAnICsgaWRlbnRpdHkucmVwbHlfdG8g
KyAnLiAnICsgKGlkZW50aXR5LmF2aXNvIHx8ICcnKTsKKyAgICAgICAgICAgIH0KKyAgICAgICAg
ICAgIHJldHVybiBpZGVudGlkYWRBY3R1YWw7CisgICAgICAgIH07CisgICAgICAgIGNvbnN0IGFj
dHVhbGl6YXJSZW1pdGVudGUgPSBmdW5jdGlvbiAoKSB7CisgICAgICAgICAgICBpZGVudGlkYWRB
Y3R1YWwgPSBudWxsOworICAgICAgICAgICAgaWYgKGlkZW50aWRhZEVsZW1lbnRvKSB7IGlkZW50
aWRhZEVsZW1lbnRvLnRleHRDb250ZW50ID0gJ0NvbXByb2JhbmRvIHJlbWl0ZW50ZSBpbnN0aXR1
Y2lvbmFs4oCmJzsgfQorICAgICAgICAgICAgY29tcHJvYmFyUmVtaXRlbnRlKCkuY2F0Y2goZnVu
Y3Rpb24gKCkgeworICAgICAgICAgICAgICAgIGlkZW50aWRhZEFjdHVhbCA9IG51bGw7CisgICAg
ICAgICAgICAgICAgaWYgKGlkZW50aWRhZEVsZW1lbnRvKSB7IGlkZW50aWRhZEVsZW1lbnRvLnRl
eHRDb250ZW50ID0gJ05vIHNlIHB1ZG8gY29tcHJvYmFyIGVsIHJlbWl0ZW50ZSBpbnN0aXR1Y2lv
bmFsLiBWdWVsdmUgYSBpbnRlbnRhcmxvLic7IH0KKyAgICAgICAgICAgIH0pOworICAgICAgICB9
OworICAgICAgICBtb2RhbEVsZW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignc2hvd24uYnMubW9kYWwn
LCBhY3R1YWxpemFyUmVtaXRlbnRlKTsKKyAgICAgICAgd2luZG93LmFkZEV2ZW50TGlzdGVuZXIo
J2ZvY3VzJywgZnVuY3Rpb24gKCkgeyBpZiAobW9kYWxFbGVtZW50LmNsYXNzTGlzdC5jb250YWlu
cygnc2hvdycpKSB7IGFjdHVhbGl6YXJSZW1pdGVudGUoKTsgfSB9KTsKKyAgICAgICAgcmVtaXRl
bnRlPy5hZGRFdmVudExpc3RlbmVyKCdpbnB1dCcsIGZ1bmN0aW9uICgpIHsKKyAgICAgICAgICAg
IGlkZW50aWRhZEFjdHVhbCA9IG51bGw7ICsrY29uc3VsdGFJZGVudGlkYWQ7IGNsZWFyVGltZW91
dChlc3BlcmFJZGVudGlkYWQpOworICAgICAgICAgICAgaWYgKGlkZW50aWRhZEVsZW1lbnRvKSB7
IGlkZW50aWRhZEVsZW1lbnRvLnRleHRDb250ZW50ID0gJyc7IH0KKyAgICAgICAgICAgIGVzcGVy
YUlkZW50aWRhZCA9IHdpbmRvdy5zZXRUaW1lb3V0KGFjdHVhbGl6YXJSZW1pdGVudGUsIDI1MCk7
CisgICAgICAgIH0pOwogCiAgICAgICAgIGNvbnN0IG1vc3RyYXJFcnJvciA9IGZ1bmN0aW9uICht
ZW5zYWplKSB7CiAgICAgICAgICAgICBtb3N0cmFyVG9hc3QoCkBAIC0yNTIsNyArMzA0LDE0IEBA
CiAgICAgICAgICAgICAgICAgdmFsb3IgfHwgJ0VzY3JpYmUgdW4gY29ycmVvIGVsZWN0csOzbmlj
byc7CiAgICAgICAgIH07CiAKKyAgICAgICAgbGV0IGNsYXZlRW52aW8gPSAnJzsKKyAgICAgICAg
Y29uc3QgbnVldmFDbGF2ZUVudmlvID0gZnVuY3Rpb24gKCkgeworICAgICAgICAgICAgY29uc3Qg
Ynl0ZXMgPSBuZXcgVWludDhBcnJheSgxNik7CisgICAgICAgICAgICB3aW5kb3cuY3J5cHRvLmdl
dFJhbmRvbVZhbHVlcyhieXRlcyk7CisgICAgICAgICAgICByZXR1cm4gQXJyYXkuZnJvbShieXRl
cywgYiA9PiBiLnRvU3RyaW5nKDE2KS5wYWRTdGFydCgyLCAnMCcpKS5qb2luKCcnKTsKKyAgICAg
ICAgfTsKICAgICAgICAgY29uc3QgbGltcGlhckZvcm11bGFyaW8gPSBmdW5jdGlvbiAoKSB7Cisg
ICAgICAgICAgICBjbGF2ZUVudmlvID0gbnVldmFDbGF2ZUVudmlvKCk7CiAgICAgICAgICAgICBm
b3JtPy5yZXNldCgpOwogICAgICAgICAgICAgYWRqdW50b3NCb3JyYWRvckFjdHVhbCA9IFtdOwog
CkBAIC0xMjk1LDYgKzEzNTQsNyBAQAogCiAgICAgICAgICAgICBjb25zdCBkYXRvcyA9IG5ldyBG
b3JtRGF0YShmb3JtKTsKICAgICAgICAgICAgIGNvbnN0IGh0bWxPcmlnaW5hbCA9IGJvdG9uRW52
aWFyPy5pbm5lckhUTUwgfHwgJyc7CisgICAgICAgICAgICBkYXRvcy5zZXQoJ2lkZW1wb3RlbmN5
X2tleScsIGNsYXZlRW52aW8gfHwgKGNsYXZlRW52aW8gPSBudWV2YUNsYXZlRW52aW8oKSkpOwog
CiAgICAgICAgICAgICBlbnZpYW5kbyA9IHRydWU7CiAKQEAgLTEzMDYsNiArMTM2NiwxMyBAQAog
ICAgICAgICAgICAgfQogCiAgICAgICAgICAgICB0cnkgeworICAgICAgICAgICAgICAgIGNvbnN0
IGFudGVyaW9yID0gaWRlbnRpZGFkQWN0dWFsOworICAgICAgICAgICAgICAgIGNvbnN0IGFjdHVh
bCA9IGF3YWl0IGNvbXByb2JhclJlbWl0ZW50ZSgpOworICAgICAgICAgICAgICAgIGlmICghYWN0
dWFsKSB7IG1vc3RyYXJFcnJvcignTm8gc2UgcHVkbyBjb21wcm9iYXIgZWwgcmVtaXRlbnRlIGlu
c3RpdHVjaW9uYWwuJyk7IHJldHVybjsgfQorICAgICAgICAgICAgICAgIGlmICghYW50ZXJpb3Ig
fHwgYW50ZXJpb3IuY29udGFjdG8gIT09IGFjdHVhbC5jb250YWN0byB8fCBhbnRlcmlvci5maXJt
YSAhPT0gYWN0dWFsLmZpcm1hKSB7CisgICAgICAgICAgICAgICAgICAgIG1vc3RyYXJFcnJvcign
UmV2aXNhIGVsIHJlbWl0ZW50ZSB2aXNpYmxlIHkgcHVsc2EgRW52aWFyIGNvcnJlbyBwYXJhIGNv
bmZpcm1hci4nKTsgcmV0dXJuOworICAgICAgICAgICAgICAgIH0KKyAgICAgICAgICAgICAgICBk
YXRvcy5zZXQoJ2lkZW50aWRhZF9lbnZpbycsIGFjdHVhbC50b2tlbik7CiAgICAgICAgICAgICAg
ICAgY29uc3QgcmVzcHVlc3RhID0gYXdhaXQgZmV0Y2godXJsRW52aWFyLCB7CiAgICAgICAgICAg
ICAgICAgICAgIG1ldGhvZDogJ1BPU1QnLAogICAgICAgICAgICAgICAgICAgICBib2R5OiBkYXRv
cywK
CENTRAL_PATCH_10_4
 [[ "$(digest "$TMP/patches/10/4.patch")" == '6a48da893744aef13a62d9e28c68e732c05c28b41c2b411e95665f0bcfae188e' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/11"
 base64 --decode > "$TMP/patches/11/1.patch" <<'CENTRAL_PATCH_11_1'
ZGlmZiAtLWdpdCBhL3Rvb2xzL2RpYWdub3N0aWNhcl9jb3JyZW9faG9zdGluZ2VyLnBocCBiL3Rv
b2xzL2RpYWdub3N0aWNhcl9jb3JyZW9faG9zdGluZ2VyLnBocAotLS0gYS90b29scy9kaWFnbm9z
dGljYXJfY29ycmVvX2hvc3Rpbmdlci5waHAKKysrIGIvdG9vbHMvZGlhZ25vc3RpY2FyX2NvcnJl
b19ob3N0aW5nZXIucGhwCkBAIC0yLDI5ICsyLDMyIEBACiAvLyBTw7NsbyBTU0gvQ0xJLiBObyBt
dWVzdHJhIGNyZWRlbmNpYWxlcyBuaSBwZXJtaXRlIGVudmlhciBkZXNkZSB1bmEgcGV0aWNpw7Nu
IHdlYi4KIGlmIChQSFBfU0FQSSAhPT0gJ2NsaScpIHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwNCk7
IGV4aXQ7IH0KIHJlcXVpcmUgZGlybmFtZShfX0RJUl9fKSAuICcvYXBwL3NlcnZpY2VzL0NvcnJl
b1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocCc7Ci1yZXF1aXJlIGRpcm5hbWUoX19ESVJf
XykgLiAnL2NvbmZpZy9tYWlsX2NvbmZpZy5waHAnOwotJG9wdGlvbnMgPSBnZXRvcHQoJycsIFsn
cmVjb3ZlcnknLCAnYWNjb3VudDonLCAndXNlcjonLCAncHJvYmUnLCAnc2VuZC10ZXN0JywgJ3Rv
OicsICdkb21haW46JywgJ2RraW06J10pOworcmVxdWlyZSBkaXJuYW1lKF9fRElSX18pIC4gJy9h
cHAvc2VydmljZXMvTWFpbFNlcnZpY2UucGhwJzsKKyRvcHRpb25zID0gZ2V0b3B0KCcnLCBbJ3Jl
Y292ZXJ5JywgJ2NlbnRyYWwnLCAnYWNjb3VudDonLCAndXNlcjonLCAncHJvYmUnLCAnc2VuZC10
ZXN0JywgJ3RvOicsICdkb21haW46JywgJ2RraW06J10pOwogZnVuY3Rpb24gZmxhZ01haWwoJGxh
YmVsLCAkb2spIHsgZWNobyAkbGFiZWwgLiAnOiAnIC4gKCRvayA/ICdPSycgOiAnRkFMVEEvUkVW
SVNBUicpIC4gUEhQX0VPTDsgfQogdHJ5IHsKICAgICBlY2hvICdQSFAgJyAuIFBIUF9WRVJTSU9O
IC4gJyBTQVBJICcgLiBQSFBfU0FQSSAuIFBIUF9FT0w7CiAgICAgZm9yZWFjaCAoWydvcGVuc3Ns
JywgJ2N1cmwnLCAnbWJzdHJpbmcnLCAnbXlzcWxpJ10gYXMgJGV4dCkgeyBmbGFnTWFpbCgnZXh0
ZW5zaW9uICcgLiAkZXh0LCBleHRlbnNpb25fbG9hZGVkKCRleHQpKTsgfQogICAgIGZsYWdNYWls
KCdQSFBNYWlsZXInLCBjbGFzc19leGlzdHMoJ1BIUE1haWxlclxcUEhQTWFpbGVyXFxQSFBNYWls
ZXInKSk7Ci0gICAgaWYgKGlzc2V0KCRvcHRpb25zWydhY2NvdW50J10pKSB7CisgICAgaWYgKGlz
c2V0KCRvcHRpb25zWydjZW50cmFsJ10pKSB7CisgICAgICAgICRhID0gKG5ldyBDb3JyZW9DdWVu
dGFDb25lY3RhZGFTZXJ2aWNlKCkpLT5jZW50cmFsKCk7ICRmcm9tID0gJGFbJ2NvcnJlbyddOyAk
dXNlciA9IChpbnQpKCRvcHRpb25zWyd1c2VyJ10gPz8gMCk7CisgICAgICAgIGVjaG8gIkN1ZW50
YSBpbnN0aXR1Y2lvbmFsIGNlbnRyYWwuIE5vIHVzYSBjb25leGlvbmVzIHBlcnNvbmFsZXMuXG4i
OworICAgIH0gZWxzZWlmIChpc3NldCgkb3B0aW9uc1snYWNjb3VudCddKSkgewogICAgICAgICAk
ZnJvbSA9IHN0cnRvbG93ZXIodHJpbSgkb3B0aW9uc1snYWNjb3VudCddKSk7ICR1c2VyID0gKGlu
dCkoJG9wdGlvbnNbJ3VzZXInXSA/PyAwKTsKICAgICAgICAgJHJlc3VsdCA9IChuZXcgQ29ycmVv
Q3VlbnRhQ29uZWN0YWRhU2VydmljZSgpKS0+cmVzb2x2ZXIoJGZyb20sICR1c2VyLCB0cnVlKTsK
ICAgICAgICAgaWYgKGVtcHR5KCRyZXN1bHRbJ29rJ10pKSB7IGVjaG8gJ0N1ZW50YTogJyAuICRy
ZXN1bHRbJ21lbnNhamUnXSAuIFBIUF9FT0w7IGV4aXQoMik7IH0KICAgICAgICAgJGEgPSAkcmVz
dWx0WydhY2NvdW50J107CiAgICAgfSBlbHNlIHsKLSAgICAgICAgJGEgPSBpbXBlTGVlckNvbmZp
Z1ByaXZhZGEoJ3Bhc3N3b3JkX3Jlc2V0X21haWwubG9jYWwucGhwJykgPzogWydjb3JyZW8nID0+
IE1BSUxfRlJPTV9BRERSRVNTLAotICAgICAgICAgICAgJ3NtdHBfaG9zdCcgPT4gTUFJTF9IT1NU
LCAnc210cF9wb3J0JyA9PiBNQUlMX1BPUlQsICdzbXRwX2VuY3J5cHRpb24nID0+IE1BSUxfRU5D
UllQVElPTiwKLSAgICAgICAgICAgICdzbXRwX3VzZXJuYW1lJyA9PiBNQUlMX1VTRVJOQU1FLCAn
c210cF9wYXNzd29yZCcgPT4gTUFJTF9QQVNTV09SRF07CisgICAgICAgIGVjaG8gJ0NvbmZpZ3Vy
YWNpw7NuIGRlIHJlY3VwZXJhY2nDs246ICcgLiAoaW1wZVJ1dGFDb25maWdQcml2YWRhKCdwYXNz
d29yZF9yZXNldF9tYWlsLmxvY2FsLnBocCcpICE9PSBudWxsID8gJ2FyY2hpdm8gZGVkaWNhZG8n
IDogJ01BSUxfKiBkZSBhcmNoaXZvIHByaXZhZG8vZW50b3JubycpIC4gUEhQX0VPTDsKKyAgICAg
ICAgJGEgPSBNYWlsU2VydmljZTo6Y3VlbnRhUmVjdXBlcmFjaW9uKCk7CiAgICAgICAgICRmcm9t
ID0gKHN0cmluZykoJGFbJ2NvcnJlbyddID8/ICRhWydzbXRwX3VzZXJuYW1lJ10gPz8gJycpOwog
ICAgICAgICAkdXNlciA9IDA7CiAgICAgICAgIGVjaG8gIkN1ZW50YSBkZWRpY2FkYSBkZSByZWN1
cGVyYWNpw7NuIChubyBtb2RpZmljYSB1c3VhcmlvcyBuaSBjb250cmFzZcOxYXMpLlxuIjsKICAg
ICB9CiAgICAgJHRyYW5zcG9ydCA9ICRhWyd0cmFuc3BvcnQnXSA/PyAnc210cCc7CiAgICAgZWNo
byAnVHJhbnNwb3J0ZTogJyAuICR0cmFuc3BvcnQgLiBQSFBfRU9MOwotICAgIGZsYWdNYWlsKCdJ
ZGVudGlkYWQgY29uZmlndXJhZGEnLCBmaWx0ZXJfdmFyKCRmcm9tLCBGSUxURVJfVkFMSURBVEVf
RU1BSUwpICE9PSBmYWxzZSk7CisgICAgaWYgKCR0cmFuc3BvcnQgPT09ICdob3N0aW5nZXJfYXBp
JyAmJiAkZnJvbSA9PT0gJycpIHsgZWNobyAiSWRlbnRpZGFkOiBzZWxlY2Npw7NuIGF1dG9tw6F0
aWNhIGRlbCBidXrDs24gZGVsIHVzdWFyaW8gcmVnaXN0cmFkby5cbiI7IH0KKyAgICBlbHNlIHsg
ZmxhZ01haWwoJ0lkZW50aWRhZCBjb25maWd1cmFkYScsIGZpbHRlcl92YXIoJGZyb20sIEZJTFRF
Ul9WQUxJREFURV9FTUFJTCkgIT09IGZhbHNlKTsgfQogICAgIGlmICgkdHJhbnNwb3J0ID09PSAn
c210cCcpIHsKICAgICAgICAgJG1haWwgPSBuZXcgXFBIUE1haWxlclxQSFBNYWlsZXJcUEhQTWFp
bGVyKHRydWUpOwogICAgICAgICBDb3JyZW9TbXRwVHJhbnNwb3J0Ojpjb25maWd1cmFyKCRtYWls
LCAkYSk7CkBAIC00NCw5ICs0NywxMCBAQAogICAgICAgICAgICAgY3VybF9zZXRvcHRfYXJyYXko
JGMsIFtDVVJMT1BUX1JFVFVSTlRSQU5TRkVSID0+IHRydWUsIENVUkxPUFRfSFRUUEhFQURFUiA9
PiBbJ0F1dGhvcml6YXRpb246IEJlYXJlciAnIC4gJGFbJ2FwaV90b2tlbiddXSwKICAgICAgICAg
ICAgICAgICBDVVJMT1BUX1NTTF9WRVJJRllQRUVSID0+IHRydWUsIENVUkxPUFRfU1NMX1ZFUklG
WUhPU1QgPT4gMiwgQ1VSTE9QVF9GT0xMT1dMT0NBVElPTiA9PiBmYWxzZSwgQ1VSTE9QVF9DT05O
RUNUVElNRU9VVCA9PiAxMCwgQ1VSTE9QVF9USU1FT1VUID0+IDI1XSk7CiAgICAgICAgICAgICAk
Ym9keSA9IGN1cmxfZXhlYygkYyk7ICRjb2RlID0gKGludCljdXJsX2dldGluZm8oJGMsIENVUkxJ
TkZPX0hUVFBfQ09ERSk7IGN1cmxfY2xvc2UoJGMpOwotICAgICAgICAgICAgJGpzb24gPSBqc29u
X2RlY29kZSgoc3RyaW5nKSRib2R5LCB0cnVlKTsgJGFsbG93ZWQgPSBmYWxzZTsKKyAgICAgICAg
ICAgICRqc29uID0ganNvbl9kZWNvZGUoKHN0cmluZykkYm9keSwgdHJ1ZSk7ICRhbGxvd2VkID0g
JGZyb20gPT09ICcnICYmICFlbXB0eSgkanNvblsnZGF0YSddWydtYWlsYm94ZXMnXSk7CiAgICAg
ICAgICAgICBmb3JlYWNoICgoJGpzb25bJ2RhdGEnXVsnbWFpbGJveGVzJ10gPz8gW10pIGFzICRi
b3gpIHsgaWYgKHN0cmNhc2VjbXAoKHN0cmluZykoJGJveFsnYWRkcmVzcyddID8/ICcnKSwgJGZy
b20pID09PSAwKSB7ICRhbGxvd2VkID0gdHJ1ZTsgfSB9CiAgICAgICAgICAgICBlY2hvICdIVFRQ
IGNvbnN1bHRhIEFQSTogJyAuICRjb2RlIC4gUEhQX0VPTDsgZmxhZ01haWwoJ0J1esOzbiBhdXRv
cml6YWRvJywgJGNvZGUgPT09IDIwMCAmJiAkYWxsb3dlZCk7CisgICAgICAgICAgICBlY2hvICdC
dXpvbmVzIGRpc3BvbmlibGVzOiAnIC4gY291bnQoJGpzb25bJ2RhdGEnXVsnbWFpbGJveGVzJ10g
Pz8gW10pIC4gUEhQX0VPTDsKICAgICAgICAgICAgIGlmICgkY29kZSAhPT0gMjAwIHx8ICEkYWxs
b3dlZCkgeyBleGl0KDIpOyB9CiAgICAgICAgIH0KICAgICB9CkBAIC02MCw2ICs2NCwxMSBAQAog
ICAgICAgICBpZiAoJHNlbGVjdG9yICE9PSAnJykgeyBmbGFnTWFpbCgnUmVnaXN0cm8gREtJTSBw
cmVzZW50ZScsIChib29sKWRuc19nZXRfcmVjb3JkKCRzZWxlY3RvciAuICcuX2RvbWFpbmtleS4n
IC4gJGRvbWFpbiwgRE5TX1RYVCB8IEROU19DTkFNRSkpOyB9CiAgICAgfQogICAgIGlmIChpc3Nl
dCgkb3B0aW9uc1snc2VuZC10ZXN0J10pKSB7CisgICAgICAgIGlmIChpc3NldCgkb3B0aW9uc1sn
Y2VudHJhbCddKSAmJiAkdXNlciA8PSAwKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdN
QUlMX1RFU1RfVVNFUicpOyB9CisgICAgICAgIGlmIChpc3NldCgkb3B0aW9uc1snY2VudHJhbCdd
KSAmJiAkZnJvbSA9PT0gJycgJiYgJHVzZXIgPiAwKSB7CisgICAgICAgICAgICByZXF1aXJlX29u
Y2UgZGlybmFtZShfX0RJUl9fKSAuICcvYXBwL21vZGVscy9Vc3VhcmlvTW9kZWwucGhwJzsKKyAg
ICAgICAgICAgICRmcm9tID0gKHN0cmluZykoKG5ldyBVc3VhcmlvTW9kZWwoKSktPmJ1c2NhclBv
cklkKCR1c2VyKVsnY29ycmVvJ10gPz8gJycpOworICAgICAgICB9CiAgICAgICAgICR0byA9IHRy
aW0oKHN0cmluZykoJG9wdGlvbnNbJ3RvJ10gPz8gJycpKTsKICAgICAgICAgaWYgKCFmaWx0ZXJf
dmFyKCR0bywgRklMVEVSX1ZBTElEQVRFX0VNQUlMKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2Vw
dGlvbignTUFJTF9URVNUX1JFQ0lQSUVOVCcpOyB9CiAgICAgICAgICRzdWJqZWN0ID0gJ0lNUEUg
cHJ1ZWJhIGF1dG9yaXphZGEgJyAuIGdtZGF0ZSgnWS1tLWQgSDppOnMnKTsKQEAgLTc2LDcgKzg1
LDExIEBACiAgICAgICAgIGlmIChlbXB0eSgkclsnb2snXSkpIHsgZXhpdCgyKTsgfQogICAgIH0g
ZWxzZSB7IGVjaG8gIlNpbiBlbnbDrW8gZGUgbWVuc2FqZXMuIFVzYSAtLXByb2JlIHBhcmEgY29t
cHJvYmFyIFRMUy9hdXRlbnRpY2FjacOzbiBkZXNkZSBTU0guXG4iOyB9CiB9IGNhdGNoIChUaHJv
d2FibGUgJGUpIHsKLSAgICBlcnJvcl9sb2coJ01BSUxfRElBR05PU1RJQ19GQUlMRUQgZXhjZXB0
aW9uPScgLiBnZXRfY2xhc3MoJGUpKTsKKyAgICAkYWxsb3dlZCA9IFsnTUFJTF9SRVNFVF9DT05G
SUcnLCAnTUFJTF9SRVNFVF9UUkFOU1BPUlQnLCAnTUFJTF9SRVNFVF9JREVOVElUWScsICdNQUlM
X1NNVFBfQ09ORklHJywgJ01BSUxfU01UUF9DQScsICdNQUlMX0NFTlRSQUxfQ09ORklHJywgJ01B
SUxfVEVTVF9VU0VSJ107CisgICAgJHJlYXNvbiA9IGluX2FycmF5KCRlLT5nZXRNZXNzYWdlKCks
ICRhbGxvd2VkLCB0cnVlKSA/ICRlLT5nZXRNZXNzYWdlKCkgOiAnTUFJTF9ESUFHTk9TVElDX0ZB
SUxFRCc7CisgICAgJHNtdHBDb2RlID0gaXNzZXQoJG1haWwpID8gKGludCkoJG1haWwtPmdldFNN
VFBJbnN0YW5jZSgpLT5nZXRFcnJvcigpWydzbXRwX2NvZGUnXSA/PyAwKSA6IDA7CisgICAgZXJy
b3JfbG9nKCdNQUlMX0RJQUdOT1NUSUNfRkFJTEVEIHJlYXNvbj0nIC4gJHJlYXNvbiAuICcgc210
cF9jb2RlPScgLiAkc210cENvZGUgLiAnIGV4Y2VwdGlvbj0nIC4gZ2V0X2NsYXNzKCRlKSk7Cisg
ICAgZWNobyAnQ8OzZGlnbyBzZWd1cm86ICcgLiAkcmVhc29uIC4gKCRzbXRwQ29kZSA/ICc7IFNN
VFAgJyAuICRzbXRwQ29kZSA6ICcnKSAuIFBIUF9FT0w7CiAgICAgZWNobyAiRGlhZ27Ds3N0aWNv
IGluY29tcGxldG8uIFJldmlzYXIgY29uZmlndXJhY2nDs24sIHBlcm1pc29zIHByaXZhZG9zLCBj
ZXJ0aWZpY2Fkb3MsIGF1dGVudGljYWNpw7NuIG8gcmVzdHJpY2Npb25lcyBzYWxpZW50ZXMuIE5v
IHNlIG11ZXN0cmFuIHNlY3JldG9zLlxuIjsKICAgICBleGl0KDIpOwogfQo=
CENTRAL_PATCH_11_1
 [[ "$(digest "$TMP/patches/11/1.patch")" == '62e7a87c618366e896c1669213ac3375bb0e5549b329b720ae5b4821a2298477' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/11/2.patch" <<'CENTRAL_PATCH_11_2'
ZGlmZiAtLWdpdCBhL3Rvb2xzL2RpYWdub3N0aWNhcl9jb3JyZW9faG9zdGluZ2VyLnBocCBiL3Rv
b2xzL2RpYWdub3N0aWNhcl9jb3JyZW9faG9zdGluZ2VyLnBocAotLS0gYS90b29scy9kaWFnbm9z
dGljYXJfY29ycmVvX2hvc3Rpbmdlci5waHAKKysrIGIvdG9vbHMvZGlhZ25vc3RpY2FyX2NvcnJl
b19ob3N0aW5nZXIucGhwCkBAIC0yLDMyICsyLDMyIEBACiAvLyBTw7NsbyBTU0gvQ0xJLiBObyBt
dWVzdHJhIGNyZWRlbmNpYWxlcyBuaSBwZXJtaXRlIGVudmlhciBkZXNkZSB1bmEgcGV0aWNpw7Nu
IHdlYi4KIGlmIChQSFBfU0FQSSAhPT0gJ2NsaScpIHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwNCk7
IGV4aXQ7IH0KIHJlcXVpcmUgZGlybmFtZShfX0RJUl9fKSAuICcvYXBwL3NlcnZpY2VzL0NvcnJl
b1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocCc7Ci1yZXF1aXJlIGRpcm5hbWUoX19ESVJf
XykgLiAnL2NvbmZpZy9tYWlsX2NvbmZpZy5waHAnOwotJG9wdGlvbnMgPSBnZXRvcHQoJycsIFsn
cmVjb3ZlcnknLCAnYWNjb3VudDonLCAndXNlcjonLCAncHJvYmUnLCAnc2VuZC10ZXN0JywgJ3Rv
OicsICdkb21haW46JywgJ2RraW06J10pOworcmVxdWlyZSBkaXJuYW1lKF9fRElSX18pIC4gJy9h
cHAvc2VydmljZXMvTWFpbFNlcnZpY2UucGhwJzsKKyRvcHRpb25zID0gZ2V0b3B0KCcnLCBbJ3Jl
Y292ZXJ5JywgJ2NlbnRyYWwnLCAnYWNjb3VudDonLCAndXNlcjonLCAncHJvYmUnLCAnc2VuZC10
ZXN0JywgJ3RvOicsICdkb21haW46JywgJ2RraW06J10pOwogZnVuY3Rpb24gZmxhZ01haWwoJGxh
YmVsLCAkb2spIHsgZWNobyAkbGFiZWwgLiAnOiAnIC4gKCRvayA/ICdPSycgOiAnRkFMVEEvUkVW
SVNBUicpIC4gUEhQX0VPTDsgfQogdHJ5IHsKICAgICBlY2hvICdQSFAgJyAuIFBIUF9WRVJTSU9O
IC4gJyBTQVBJICcgLiBQSFBfU0FQSSAuIFBIUF9FT0w7CiAgICAgZm9yZWFjaCAoWydvcGVuc3Ns
JywgJ2N1cmwnLCAnbWJzdHJpbmcnLCAnbXlzcWxpJ10gYXMgJGV4dCkgeyBmbGFnTWFpbCgnZXh0
ZW5zaW9uICcgLiAkZXh0LCBleHRlbnNpb25fbG9hZGVkKCRleHQpKTsgfQogICAgIGZsYWdNYWls
KCdQSFBNYWlsZXInLCBjbGFzc19leGlzdHMoJ1BIUE1haWxlclxcUEhQTWFpbGVyXFxQSFBNYWls
ZXInKSk7Ci0gICAgZm9yZWFjaCAoKG5ldyBDb3JyZW9Db25leGlvbk9BdXRoU2VydmljZSgpKS0+
ZGlzcG9uaWJsZXMoKSBhcyAkcHJvdmlkZXIgPT4gJHJlYWR5KSB7Ci0gICAgICAgIGVjaG8gJ09B
dXRoICcgLiAkcHJvdmlkZXIgLiAnIChvcGNpb25hbCk6ICcgLiAoJHJlYWR5ID8gJ2FwbGljYWNp
w7NuIGNvbmZpZ3VyYWRhJyA6ICdkZXNhY3RpdmFkbzsgZmFsdGEgYXBsaWNhY2nDs24gYXV0b3Jp
emFkYScpIC4gUEhQX0VPTDsKLSAgICB9Ci0gICAgaWYgKGlzc2V0KCRvcHRpb25zWydhY2NvdW50
J10pKSB7CisgICAgaWYgKGlzc2V0KCRvcHRpb25zWydjZW50cmFsJ10pKSB7CisgICAgICAgICRh
ID0gKG5ldyBDb3JyZW9DdWVudGFDb25lY3RhZGFTZXJ2aWNlKCkpLT5jZW50cmFsKCk7ICRmcm9t
ID0gJGFbJ2NvcnJlbyddOyAkdXNlciA9IChpbnQpKCRvcHRpb25zWyd1c2VyJ10gPz8gMCk7Cisg
ICAgICAgIGVjaG8gIkN1ZW50YSBpbnN0aXR1Y2lvbmFsIGNlbnRyYWwuIE5vIHVzYSBjb25leGlv
bmVzIHBlcnNvbmFsZXMuXG4iOworICAgIH0gZWxzZWlmIChpc3NldCgkb3B0aW9uc1snYWNjb3Vu
dCddKSkgewogICAgICAgICAkZnJvbSA9IHN0cnRvbG93ZXIodHJpbSgkb3B0aW9uc1snYWNjb3Vu
dCddKSk7ICR1c2VyID0gKGludCkoJG9wdGlvbnNbJ3VzZXInXSA/PyAwKTsKICAgICAgICAgJHJl
c3VsdCA9IChuZXcgQ29ycmVvQ3VlbnRhQ29uZWN0YWRhU2VydmljZSgpKS0+cmVzb2x2ZXIoJGZy
b20sICR1c2VyLCB0cnVlKTsKICAgICAgICAgaWYgKGVtcHR5KCRyZXN1bHRbJ29rJ10pKSB7IGVj
aG8gJ0N1ZW50YTogJyAuICRyZXN1bHRbJ21lbnNhamUnXSAuIFBIUF9FT0w7IGV4aXQoMik7IH0K
ICAgICAgICAgJGEgPSAkcmVzdWx0WydhY2NvdW50J107CiAgICAgfSBlbHNlIHsKLSAgICAgICAg
JGEgPSBpbXBlTGVlckNvbmZpZ1ByaXZhZGEoJ3Bhc3N3b3JkX3Jlc2V0X21haWwubG9jYWwucGhw
JykgPzogWydjb3JyZW8nID0+IE1BSUxfRlJPTV9BRERSRVNTLAotICAgICAgICAgICAgJ3NtdHBf
aG9zdCcgPT4gTUFJTF9IT1NULCAnc210cF9wb3J0JyA9PiBNQUlMX1BPUlQsICdzbXRwX2VuY3J5
cHRpb24nID0+IE1BSUxfRU5DUllQVElPTiwKLSAgICAgICAgICAgICdzbXRwX3VzZXJuYW1lJyA9
PiBNQUlMX1VTRVJOQU1FLCAnc210cF9wYXNzd29yZCcgPT4gTUFJTF9QQVNTV09SRF07CisgICAg
ICAgIGVjaG8gJ0NvbmZpZ3VyYWNpw7NuIGRlIHJlY3VwZXJhY2nDs246ICcgLiAoaW1wZVJ1dGFD
b25maWdQcml2YWRhKCdwYXNzd29yZF9yZXNldF9tYWlsLmxvY2FsLnBocCcpICE9PSBudWxsID8g
J2FyY2hpdm8gZGVkaWNhZG8nIDogJ01BSUxfKiBkZSBhcmNoaXZvIHByaXZhZG8vZW50b3Jubycp
IC4gUEhQX0VPTDsKKyAgICAgICAgJGEgPSBNYWlsU2VydmljZTo6Y3VlbnRhUmVjdXBlcmFjaW9u
KCk7CiAgICAgICAgICRmcm9tID0gKHN0cmluZykoJGFbJ2NvcnJlbyddID8/ICRhWydzbXRwX3Vz
ZXJuYW1lJ10gPz8gJycpOwogICAgICAgICAkdXNlciA9IDA7CiAgICAgICAgIGVjaG8gIkN1ZW50
YSBkZWRpY2FkYSBkZSByZWN1cGVyYWNpw7NuIChubyBtb2RpZmljYSB1c3VhcmlvcyBuaSBjb250
cmFzZcOxYXMpLlxuIjsKICAgICB9CiAgICAgJHRyYW5zcG9ydCA9ICRhWyd0cmFuc3BvcnQnXSA/
PyAnc210cCc7CiAgICAgZWNobyAnVHJhbnNwb3J0ZTogJyAuICR0cmFuc3BvcnQgLiBQSFBfRU9M
OwotICAgIGZsYWdNYWlsKCdJZGVudGlkYWQgY29uZmlndXJhZGEnLCBmaWx0ZXJfdmFyKCRmcm9t
LCBGSUxURVJfVkFMSURBVEVfRU1BSUwpICE9PSBmYWxzZSk7CisgICAgaWYgKCR0cmFuc3BvcnQg
PT09ICdob3N0aW5nZXJfYXBpJyAmJiAkZnJvbSA9PT0gJycpIHsgZWNobyAiSWRlbnRpZGFkOiBz
ZWxlY2Npw7NuIGF1dG9tw6F0aWNhIGRlbCBidXrDs24gZGVsIHVzdWFyaW8gcmVnaXN0cmFkby5c
biI7IH0KKyAgICBlbHNlIHsgZmxhZ01haWwoJ0lkZW50aWRhZCBjb25maWd1cmFkYScsIGZpbHRl
cl92YXIoJGZyb20sIEZJTFRFUl9WQUxJREFURV9FTUFJTCkgIT09IGZhbHNlKTsgfQogICAgIGlm
ICgkdHJhbnNwb3J0ID09PSAnc210cCcpIHsKICAgICAgICAgJG1haWwgPSBuZXcgXFBIUE1haWxl
clxQSFBNYWlsZXJcUEhQTWFpbGVyKHRydWUpOwogICAgICAgICBDb3JyZW9TbXRwVHJhbnNwb3J0
Ojpjb25maWd1cmFyKCRtYWlsLCAkYSk7CkBAIC0zOSwxNCArMzksNiBAQAogICAgICAgICAgICAg
JGNvbm5lY3RlZCA9ICRtYWlsLT5zbXRwQ29ubmVjdCgpOyBmbGFnTWFpbCgnQ29uZXhpw7NuIFRM
UyB5IGF1dGVudGljYWNpw7NuJywgJGNvbm5lY3RlZCk7ICRtYWlsLT5zbXRwQ2xvc2UoKTsKICAg
ICAgICAgICAgIGlmICghJGNvbm5lY3RlZCkgeyBleGl0KDIpOyB9CiAgICAgICAgIH0KLSAgICB9
IGVsc2VpZiAoJHRyYW5zcG9ydCA9PT0gJ2dtYWlsX2FwaScpIHsKLSAgICAgICAgZmxhZ01haWwo
J09BdXRoIEdtYWlsIGNvbmZpZ3VyYWRvJywgIWVtcHR5KCRhWydyZWZyZXNoX3Rva2VuJ10pICYm
ICFlbXB0eSgkYVsnY2xpZW50X2lkJ10pKTsKLSAgICAgICAgaWYgKGlzc2V0KCRvcHRpb25zWydw
cm9iZSddKSkgewotICAgICAgICAgICAgJHRva2VuID0gKG5ldyBDb3JyZW9PQXV0aFRva2VuUHJv
dmlkZXIoJGEpKS0+b2J0ZW5lckFjY2Vzc1Rva2VuKCk7Ci0gICAgICAgICAgICAkYWxsb3dlZCA9
IENvcnJlb0dtYWlsQXBpVHJhbnNwb3J0OjppZGVudGlkYWQoJGZyb20sICR0b2tlbik7Ci0gICAg
ICAgICAgICBmbGFnTWFpbCgnSWRlbnRpZGFkIEdvb2dsZSB2ZXJpZmljYWRhIHBvciBIVFRQUycs
ICRhbGxvd2VkKTsKLSAgICAgICAgICAgIGlmICghJGFsbG93ZWQpIHsgZXhpdCgyKTsgfQotICAg
ICAgICB9CiAgICAgfSBlbHNlaWYgKCR0cmFuc3BvcnQgPT09ICdob3N0aW5nZXJfYXBpJykgewog
ICAgICAgICBmbGFnTWFpbCgnVG9rZW4gQVBJIGNvbmZpZ3VyYWRvJywgIWVtcHR5KCRhWydhcGlf
dG9rZW4nXSkpOwogICAgICAgICBpZiAoaXNzZXQoJG9wdGlvbnNbJ3Byb2JlJ10pKSB7CkBAIC01
NSw5ICs0NywxMCBAQAogICAgICAgICAgICAgY3VybF9zZXRvcHRfYXJyYXkoJGMsIFtDVVJMT1BU
X1JFVFVSTlRSQU5TRkVSID0+IHRydWUsIENVUkxPUFRfSFRUUEhFQURFUiA9PiBbJ0F1dGhvcml6
YXRpb246IEJlYXJlciAnIC4gJGFbJ2FwaV90b2tlbiddXSwKICAgICAgICAgICAgICAgICBDVVJM
T1BUX1NTTF9WRVJJRllQRUVSID0+IHRydWUsIENVUkxPUFRfU1NMX1ZFUklGWUhPU1QgPT4gMiwg
Q1VSTE9QVF9GT0xMT1dMT0NBVElPTiA9PiBmYWxzZSwgQ1VSTE9QVF9DT05ORUNUVElNRU9VVCA9
PiAxMCwgQ1VSTE9QVF9USU1FT1VUID0+IDI1XSk7CiAgICAgICAgICAgICAkYm9keSA9IGN1cmxf
ZXhlYygkYyk7ICRjb2RlID0gKGludCljdXJsX2dldGluZm8oJGMsIENVUkxJTkZPX0hUVFBfQ09E
RSk7IGN1cmxfY2xvc2UoJGMpOwotICAgICAgICAgICAgJGpzb24gPSBqc29uX2RlY29kZSgoc3Ry
aW5nKSRib2R5LCB0cnVlKTsgJGFsbG93ZWQgPSBmYWxzZTsKKyAgICAgICAgICAgICRqc29uID0g
anNvbl9kZWNvZGUoKHN0cmluZykkYm9keSwgdHJ1ZSk7ICRhbGxvd2VkID0gJGZyb20gPT09ICcn
ICYmICFlbXB0eSgkanNvblsnZGF0YSddWydtYWlsYm94ZXMnXSk7CiAgICAgICAgICAgICBmb3Jl
YWNoICgoJGpzb25bJ2RhdGEnXVsnbWFpbGJveGVzJ10gPz8gW10pIGFzICRib3gpIHsgaWYgKHN0
cmNhc2VjbXAoKHN0cmluZykoJGJveFsnYWRkcmVzcyddID8/ICcnKSwgJGZyb20pID09PSAwKSB7
ICRhbGxvd2VkID0gdHJ1ZTsgfSB9CiAgICAgICAgICAgICBlY2hvICdIVFRQIGNvbnN1bHRhIEFQ
STogJyAuICRjb2RlIC4gUEhQX0VPTDsgZmxhZ01haWwoJ0J1esOzbiBhdXRvcml6YWRvJywgJGNv
ZGUgPT09IDIwMCAmJiAkYWxsb3dlZCk7CisgICAgICAgICAgICBlY2hvICdCdXpvbmVzIGRpc3Bv
bmlibGVzOiAnIC4gY291bnQoJGpzb25bJ2RhdGEnXVsnbWFpbGJveGVzJ10gPz8gW10pIC4gUEhQ
X0VPTDsKICAgICAgICAgICAgIGlmICgkY29kZSAhPT0gMjAwIHx8ICEkYWxsb3dlZCkgeyBleGl0
KDIpOyB9CiAgICAgICAgIH0KICAgICB9CkBAIC03MSw2ICs2NCwxMSBAQAogICAgICAgICBpZiAo
JHNlbGVjdG9yICE9PSAnJykgeyBmbGFnTWFpbCgnUmVnaXN0cm8gREtJTSBwcmVzZW50ZScsIChi
b29sKWRuc19nZXRfcmVjb3JkKCRzZWxlY3RvciAuICcuX2RvbWFpbmtleS4nIC4gJGRvbWFpbiwg
RE5TX1RYVCB8IEROU19DTkFNRSkpOyB9CiAgICAgfQogICAgIGlmIChpc3NldCgkb3B0aW9uc1sn
c2VuZC10ZXN0J10pKSB7CisgICAgICAgIGlmIChpc3NldCgkb3B0aW9uc1snY2VudHJhbCddKSAm
JiAkdXNlciA8PSAwKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1RFU1RfVVNF
UicpOyB9CisgICAgICAgIGlmIChpc3NldCgkb3B0aW9uc1snY2VudHJhbCddKSAmJiAkZnJvbSA9
PT0gJycgJiYgJHVzZXIgPiAwKSB7CisgICAgICAgICAgICByZXF1aXJlX29uY2UgZGlybmFtZShf
X0RJUl9fKSAuICcvYXBwL21vZGVscy9Vc3VhcmlvTW9kZWwucGhwJzsKKyAgICAgICAgICAgICRm
cm9tID0gKHN0cmluZykoKG5ldyBVc3VhcmlvTW9kZWwoKSktPmJ1c2NhclBvcklkKCR1c2VyKVsn
Y29ycmVvJ10gPz8gJycpOworICAgICAgICB9CiAgICAgICAgICR0byA9IHRyaW0oKHN0cmluZyko
JG9wdGlvbnNbJ3RvJ10gPz8gJycpKTsKICAgICAgICAgaWYgKCFmaWx0ZXJfdmFyKCR0bywgRklM
VEVSX1ZBTElEQVRFX0VNQUlMKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9U
RVNUX1JFQ0lQSUVOVCcpOyB9CiAgICAgICAgICRzdWJqZWN0ID0gJ0lNUEUgcHJ1ZWJhIGF1dG9y
aXphZGEgJyAuIGdtZGF0ZSgnWS1tLWQgSDppOnMnKTsKQEAgLTg3LDcgKzg1LDExIEBACiAgICAg
ICAgIGlmIChlbXB0eSgkclsnb2snXSkpIHsgZXhpdCgyKTsgfQogICAgIH0gZWxzZSB7IGVjaG8g
IlNpbiBlbnbDrW8gZGUgbWVuc2FqZXMuIFVzYSAtLXByb2JlIHBhcmEgY29tcHJvYmFyIFRMUy9h
dXRlbnRpY2FjacOzbiBkZXNkZSBTU0guXG4iOyB9CiB9IGNhdGNoIChUaHJvd2FibGUgJGUpIHsK
LSAgICBlcnJvcl9sb2coJ01BSUxfRElBR05PU1RJQ19GQUlMRUQgZXhjZXB0aW9uPScgLiBnZXRf
Y2xhc3MoJGUpKTsKKyAgICAkYWxsb3dlZCA9IFsnTUFJTF9SRVNFVF9DT05GSUcnLCAnTUFJTF9S
RVNFVF9UUkFOU1BPUlQnLCAnTUFJTF9SRVNFVF9JREVOVElUWScsICdNQUlMX1NNVFBfQ09ORklH
JywgJ01BSUxfU01UUF9DQScsICdNQUlMX0NFTlRSQUxfQ09ORklHJywgJ01BSUxfVEVTVF9VU0VS
J107CisgICAgJHJlYXNvbiA9IGluX2FycmF5KCRlLT5nZXRNZXNzYWdlKCksICRhbGxvd2VkLCB0
cnVlKSA/ICRlLT5nZXRNZXNzYWdlKCkgOiAnTUFJTF9ESUFHTk9TVElDX0ZBSUxFRCc7CisgICAg
JHNtdHBDb2RlID0gaXNzZXQoJG1haWwpID8gKGludCkoJG1haWwtPmdldFNNVFBJbnN0YW5jZSgp
LT5nZXRFcnJvcigpWydzbXRwX2NvZGUnXSA/PyAwKSA6IDA7CisgICAgZXJyb3JfbG9nKCdNQUlM
X0RJQUdOT1NUSUNfRkFJTEVEIHJlYXNvbj0nIC4gJHJlYXNvbiAuICcgc210cF9jb2RlPScgLiAk
c210cENvZGUgLiAnIGV4Y2VwdGlvbj0nIC4gZ2V0X2NsYXNzKCRlKSk7CisgICAgZWNobyAnQ8Oz
ZGlnbyBzZWd1cm86ICcgLiAkcmVhc29uIC4gKCRzbXRwQ29kZSA/ICc7IFNNVFAgJyAuICRzbXRw
Q29kZSA6ICcnKSAuIFBIUF9FT0w7CiAgICAgZWNobyAiRGlhZ27Ds3N0aWNvIGluY29tcGxldG8u
IFJldmlzYXIgY29uZmlndXJhY2nDs24sIHBlcm1pc29zIHByaXZhZG9zLCBjZXJ0aWZpY2Fkb3Ms
IGF1dGVudGljYWNpw7NuIG8gcmVzdHJpY2Npb25lcyBzYWxpZW50ZXMuIE5vIHNlIG11ZXN0cmFu
IHNlY3JldG9zLlxuIjsKICAgICBleGl0KDIpOwogfQo=
CENTRAL_PATCH_11_2
 [[ "$(digest "$TMP/patches/11/2.patch")" == 'ddb93bc42f8595f2038e08a144938501945d264edacc1679ea916ceb21f6cdef' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/11/3.patch" <<'CENTRAL_PATCH_11_3'
ZGlmZiAtLWdpdCBhL3Rvb2xzL2RpYWdub3N0aWNhcl9jb3JyZW9faG9zdGluZ2VyLnBocCBiL3Rv
b2xzL2RpYWdub3N0aWNhcl9jb3JyZW9faG9zdGluZ2VyLnBocAotLS0gYS90b29scy9kaWFnbm9z
dGljYXJfY29ycmVvX2hvc3Rpbmdlci5waHAKKysrIGIvdG9vbHMvZGlhZ25vc3RpY2FyX2NvcnJl
b19ob3N0aW5nZXIucGhwCkBAIC0zLDE2ICszLDE2IEBACiBpZiAoUEhQX1NBUEkgIT09ICdjbGkn
KSB7IGh0dHBfcmVzcG9uc2VfY29kZSg0MDQpOyBleGl0OyB9CiByZXF1aXJlIGRpcm5hbWUoX19E
SVJfXykgLiAnL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5w
aHAnOwogcmVxdWlyZSBkaXJuYW1lKF9fRElSX18pIC4gJy9hcHAvc2VydmljZXMvTWFpbFNlcnZp
Y2UucGhwJzsKLSRvcHRpb25zID0gZ2V0b3B0KCcnLCBbJ3JlY292ZXJ5JywgJ2FjY291bnQ6Jywg
J3VzZXI6JywgJ3Byb2JlJywgJ3NlbmQtdGVzdCcsICd0bzonLCAnZG9tYWluOicsICdka2ltOidd
KTsKKyRvcHRpb25zID0gZ2V0b3B0KCcnLCBbJ3JlY292ZXJ5JywgJ2NlbnRyYWwnLCAnYWNjb3Vu
dDonLCAndXNlcjonLCAncHJvYmUnLCAnc2VuZC10ZXN0JywgJ3RvOicsICdkb21haW46JywgJ2Rr
aW06J10pOwogZnVuY3Rpb24gZmxhZ01haWwoJGxhYmVsLCAkb2spIHsgZWNobyAkbGFiZWwgLiAn
OiAnIC4gKCRvayA/ICdPSycgOiAnRkFMVEEvUkVWSVNBUicpIC4gUEhQX0VPTDsgfQogdHJ5IHsK
ICAgICBlY2hvICdQSFAgJyAuIFBIUF9WRVJTSU9OIC4gJyBTQVBJICcgLiBQSFBfU0FQSSAuIFBI
UF9FT0w7CiAgICAgZm9yZWFjaCAoWydvcGVuc3NsJywgJ2N1cmwnLCAnbWJzdHJpbmcnLCAnbXlz
cWxpJ10gYXMgJGV4dCkgeyBmbGFnTWFpbCgnZXh0ZW5zaW9uICcgLiAkZXh0LCBleHRlbnNpb25f
bG9hZGVkKCRleHQpKTsgfQogICAgIGZsYWdNYWlsKCdQSFBNYWlsZXInLCBjbGFzc19leGlzdHMo
J1BIUE1haWxlclxcUEhQTWFpbGVyXFxQSFBNYWlsZXInKSk7Ci0gICAgZm9yZWFjaCAoKG5ldyBD
b3JyZW9Db25leGlvbk9BdXRoU2VydmljZSgpKS0+ZGlzcG9uaWJsZXMoKSBhcyAkcHJvdmlkZXIg
PT4gJHJlYWR5KSB7Ci0gICAgICAgIGVjaG8gJ09BdXRoICcgLiAkcHJvdmlkZXIgLiAnIChvcGNp
b25hbCk6ICcgLiAoJHJlYWR5ID8gJ2FwbGljYWNpw7NuIGNvbmZpZ3VyYWRhJyA6ICdkZXNhY3Rp
dmFkbzsgZmFsdGEgYXBsaWNhY2nDs24gYXV0b3JpemFkYScpIC4gUEhQX0VPTDsKLSAgICB9Ci0g
ICAgaWYgKGlzc2V0KCRvcHRpb25zWydhY2NvdW50J10pKSB7CisgICAgaWYgKGlzc2V0KCRvcHRp
b25zWydjZW50cmFsJ10pKSB7CisgICAgICAgICRhID0gKG5ldyBDb3JyZW9DdWVudGFDb25lY3Rh
ZGFTZXJ2aWNlKCkpLT5jZW50cmFsKCk7ICRmcm9tID0gJGFbJ2NvcnJlbyddOyAkdXNlciA9IChp
bnQpKCRvcHRpb25zWyd1c2VyJ10gPz8gMCk7CisgICAgICAgIGVjaG8gIkN1ZW50YSBpbnN0aXR1
Y2lvbmFsIGNlbnRyYWwuIE5vIHVzYSBjb25leGlvbmVzIHBlcnNvbmFsZXMuXG4iOworICAgIH0g
ZWxzZWlmIChpc3NldCgkb3B0aW9uc1snYWNjb3VudCddKSkgewogICAgICAgICAkZnJvbSA9IHN0
cnRvbG93ZXIodHJpbSgkb3B0aW9uc1snYWNjb3VudCddKSk7ICR1c2VyID0gKGludCkoJG9wdGlv
bnNbJ3VzZXInXSA/PyAwKTsKICAgICAgICAgJHJlc3VsdCA9IChuZXcgQ29ycmVvQ3VlbnRhQ29u
ZWN0YWRhU2VydmljZSgpKS0+cmVzb2x2ZXIoJGZyb20sICR1c2VyLCB0cnVlKTsKICAgICAgICAg
aWYgKGVtcHR5KCRyZXN1bHRbJ29rJ10pKSB7IGVjaG8gJ0N1ZW50YTogJyAuICRyZXN1bHRbJ21l
bnNhamUnXSAuIFBIUF9FT0w7IGV4aXQoMik7IH0KQEAgLTI2LDcgKzI2LDggQEAKICAgICB9CiAg
ICAgJHRyYW5zcG9ydCA9ICRhWyd0cmFuc3BvcnQnXSA/PyAnc210cCc7CiAgICAgZWNobyAnVHJh
bnNwb3J0ZTogJyAuICR0cmFuc3BvcnQgLiBQSFBfRU9MOwotICAgIGZsYWdNYWlsKCdJZGVudGlk
YWQgY29uZmlndXJhZGEnLCBmaWx0ZXJfdmFyKCRmcm9tLCBGSUxURVJfVkFMSURBVEVfRU1BSUwp
ICE9PSBmYWxzZSk7CisgICAgaWYgKCR0cmFuc3BvcnQgPT09ICdob3N0aW5nZXJfYXBpJyAmJiAk
ZnJvbSA9PT0gJycpIHsgZWNobyAiSWRlbnRpZGFkOiBzZWxlY2Npw7NuIGF1dG9tw6F0aWNhIGRl
bCBidXrDs24gZGVsIHVzdWFyaW8gcmVnaXN0cmFkby5cbiI7IH0KKyAgICBlbHNlIHsgZmxhZ01h
aWwoJ0lkZW50aWRhZCBjb25maWd1cmFkYScsIGZpbHRlcl92YXIoJGZyb20sIEZJTFRFUl9WQUxJ
REFURV9FTUFJTCkgIT09IGZhbHNlKTsgfQogICAgIGlmICgkdHJhbnNwb3J0ID09PSAnc210cCcp
IHsKICAgICAgICAgJG1haWwgPSBuZXcgXFBIUE1haWxlclxQSFBNYWlsZXJcUEhQTWFpbGVyKHRy
dWUpOwogICAgICAgICBDb3JyZW9TbXRwVHJhbnNwb3J0Ojpjb25maWd1cmFyKCRtYWlsLCAkYSk7
CkBAIC0zOCwxNCArMzksNiBAQAogICAgICAgICAgICAgJGNvbm5lY3RlZCA9ICRtYWlsLT5zbXRw
Q29ubmVjdCgpOyBmbGFnTWFpbCgnQ29uZXhpw7NuIFRMUyB5IGF1dGVudGljYWNpw7NuJywgJGNv
bm5lY3RlZCk7ICRtYWlsLT5zbXRwQ2xvc2UoKTsKICAgICAgICAgICAgIGlmICghJGNvbm5lY3Rl
ZCkgeyBleGl0KDIpOyB9CiAgICAgICAgIH0KLSAgICB9IGVsc2VpZiAoJHRyYW5zcG9ydCA9PT0g
J2dtYWlsX2FwaScpIHsKLSAgICAgICAgZmxhZ01haWwoJ09BdXRoIEdtYWlsIGNvbmZpZ3VyYWRv
JywgIWVtcHR5KCRhWydyZWZyZXNoX3Rva2VuJ10pICYmICFlbXB0eSgkYVsnY2xpZW50X2lkJ10p
KTsKLSAgICAgICAgaWYgKGlzc2V0KCRvcHRpb25zWydwcm9iZSddKSkgewotICAgICAgICAgICAg
JHRva2VuID0gKG5ldyBDb3JyZW9PQXV0aFRva2VuUHJvdmlkZXIoJGEpKS0+b2J0ZW5lckFjY2Vz
c1Rva2VuKCk7Ci0gICAgICAgICAgICAkYWxsb3dlZCA9IENvcnJlb0dtYWlsQXBpVHJhbnNwb3J0
OjppZGVudGlkYWQoJGZyb20sICR0b2tlbik7Ci0gICAgICAgICAgICBmbGFnTWFpbCgnSWRlbnRp
ZGFkIEdvb2dsZSB2ZXJpZmljYWRhIHBvciBIVFRQUycsICRhbGxvd2VkKTsKLSAgICAgICAgICAg
IGlmICghJGFsbG93ZWQpIHsgZXhpdCgyKTsgfQotICAgICAgICB9CiAgICAgfSBlbHNlaWYgKCR0
cmFuc3BvcnQgPT09ICdob3N0aW5nZXJfYXBpJykgewogICAgICAgICBmbGFnTWFpbCgnVG9rZW4g
QVBJIGNvbmZpZ3VyYWRvJywgIWVtcHR5KCRhWydhcGlfdG9rZW4nXSkpOwogICAgICAgICBpZiAo
aXNzZXQoJG9wdGlvbnNbJ3Byb2JlJ10pKSB7CkBAIC01NCw5ICs0NywxMCBAQAogICAgICAgICAg
ICAgY3VybF9zZXRvcHRfYXJyYXkoJGMsIFtDVVJMT1BUX1JFVFVSTlRSQU5TRkVSID0+IHRydWUs
IENVUkxPUFRfSFRUUEhFQURFUiA9PiBbJ0F1dGhvcml6YXRpb246IEJlYXJlciAnIC4gJGFbJ2Fw
aV90b2tlbiddXSwKICAgICAgICAgICAgICAgICBDVVJMT1BUX1NTTF9WRVJJRllQRUVSID0+IHRy
dWUsIENVUkxPUFRfU1NMX1ZFUklGWUhPU1QgPT4gMiwgQ1VSTE9QVF9GT0xMT1dMT0NBVElPTiA9
PiBmYWxzZSwgQ1VSTE9QVF9DT05ORUNUVElNRU9VVCA9PiAxMCwgQ1VSTE9QVF9USU1FT1VUID0+
IDI1XSk7CiAgICAgICAgICAgICAkYm9keSA9IGN1cmxfZXhlYygkYyk7ICRjb2RlID0gKGludClj
dXJsX2dldGluZm8oJGMsIENVUkxJTkZPX0hUVFBfQ09ERSk7IGN1cmxfY2xvc2UoJGMpOwotICAg
ICAgICAgICAgJGpzb24gPSBqc29uX2RlY29kZSgoc3RyaW5nKSRib2R5LCB0cnVlKTsgJGFsbG93
ZWQgPSBmYWxzZTsKKyAgICAgICAgICAgICRqc29uID0ganNvbl9kZWNvZGUoKHN0cmluZykkYm9k
eSwgdHJ1ZSk7ICRhbGxvd2VkID0gJGZyb20gPT09ICcnICYmICFlbXB0eSgkanNvblsnZGF0YSdd
WydtYWlsYm94ZXMnXSk7CiAgICAgICAgICAgICBmb3JlYWNoICgoJGpzb25bJ2RhdGEnXVsnbWFp
bGJveGVzJ10gPz8gW10pIGFzICRib3gpIHsgaWYgKHN0cmNhc2VjbXAoKHN0cmluZykoJGJveFsn
YWRkcmVzcyddID8/ICcnKSwgJGZyb20pID09PSAwKSB7ICRhbGxvd2VkID0gdHJ1ZTsgfSB9CiAg
ICAgICAgICAgICBlY2hvICdIVFRQIGNvbnN1bHRhIEFQSTogJyAuICRjb2RlIC4gUEhQX0VPTDsg
ZmxhZ01haWwoJ0J1esOzbiBhdXRvcml6YWRvJywgJGNvZGUgPT09IDIwMCAmJiAkYWxsb3dlZCk7
CisgICAgICAgICAgICBlY2hvICdCdXpvbmVzIGRpc3BvbmlibGVzOiAnIC4gY291bnQoJGpzb25b
J2RhdGEnXVsnbWFpbGJveGVzJ10gPz8gW10pIC4gUEhQX0VPTDsKICAgICAgICAgICAgIGlmICgk
Y29kZSAhPT0gMjAwIHx8ICEkYWxsb3dlZCkgeyBleGl0KDIpOyB9CiAgICAgICAgIH0KICAgICB9
CkBAIC03MCw2ICs2NCwxMSBAQAogICAgICAgICBpZiAoJHNlbGVjdG9yICE9PSAnJykgeyBmbGFn
TWFpbCgnUmVnaXN0cm8gREtJTSBwcmVzZW50ZScsIChib29sKWRuc19nZXRfcmVjb3JkKCRzZWxl
Y3RvciAuICcuX2RvbWFpbmtleS4nIC4gJGRvbWFpbiwgRE5TX1RYVCB8IEROU19DTkFNRSkpOyB9
CiAgICAgfQogICAgIGlmIChpc3NldCgkb3B0aW9uc1snc2VuZC10ZXN0J10pKSB7CisgICAgICAg
IGlmIChpc3NldCgkb3B0aW9uc1snY2VudHJhbCddKSAmJiAkdXNlciA8PSAwKSB7IHRocm93IG5l
dyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX1RFU1RfVVNFUicpOyB9CisgICAgICAgIGlmIChpc3Nl
dCgkb3B0aW9uc1snY2VudHJhbCddKSAmJiAkZnJvbSA9PT0gJycgJiYgJHVzZXIgPiAwKSB7Cisg
ICAgICAgICAgICByZXF1aXJlX29uY2UgZGlybmFtZShfX0RJUl9fKSAuICcvYXBwL21vZGVscy9V
c3VhcmlvTW9kZWwucGhwJzsKKyAgICAgICAgICAgICRmcm9tID0gKHN0cmluZykoKG5ldyBVc3Vh
cmlvTW9kZWwoKSktPmJ1c2NhclBvcklkKCR1c2VyKVsnY29ycmVvJ10gPz8gJycpOworICAgICAg
ICB9CiAgICAgICAgICR0byA9IHRyaW0oKHN0cmluZykoJG9wdGlvbnNbJ3RvJ10gPz8gJycpKTsK
ICAgICAgICAgaWYgKCFmaWx0ZXJfdmFyKCR0bywgRklMVEVSX1ZBTElEQVRFX0VNQUlMKSkgeyB0
aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignTUFJTF9URVNUX1JFQ0lQSUVOVCcpOyB9CiAgICAg
ICAgICRzdWJqZWN0ID0gJ0lNUEUgcHJ1ZWJhIGF1dG9yaXphZGEgJyAuIGdtZGF0ZSgnWS1tLWQg
SDppOnMnKTsKQEAgLTg2LDcgKzg1LDcgQEAKICAgICAgICAgaWYgKGVtcHR5KCRyWydvayddKSkg
eyBleGl0KDIpOyB9CiAgICAgfSBlbHNlIHsgZWNobyAiU2luIGVudsOtbyBkZSBtZW5zYWplcy4g
VXNhIC0tcHJvYmUgcGFyYSBjb21wcm9iYXIgVExTL2F1dGVudGljYWNpw7NuIGRlc2RlIFNTSC5c
biI7IH0KIH0gY2F0Y2ggKFRocm93YWJsZSAkZSkgewotICAgICRhbGxvd2VkID0gWydNQUlMX1JF
U0VUX0NPTkZJRycsICdNQUlMX1JFU0VUX1RSQU5TUE9SVCcsICdNQUlMX1JFU0VUX0lERU5USVRZ
JywgJ01BSUxfU01UUF9DT05GSUcnLCAnTUFJTF9TTVRQX0NBJ107CisgICAgJGFsbG93ZWQgPSBb
J01BSUxfUkVTRVRfQ09ORklHJywgJ01BSUxfUkVTRVRfVFJBTlNQT1JUJywgJ01BSUxfUkVTRVRf
SURFTlRJVFknLCAnTUFJTF9TTVRQX0NPTkZJRycsICdNQUlMX1NNVFBfQ0EnLCAnTUFJTF9DRU5U
UkFMX0NPTkZJRycsICdNQUlMX1RFU1RfVVNFUiddOwogICAgICRyZWFzb24gPSBpbl9hcnJheSgk
ZS0+Z2V0TWVzc2FnZSgpLCAkYWxsb3dlZCwgdHJ1ZSkgPyAkZS0+Z2V0TWVzc2FnZSgpIDogJ01B
SUxfRElBR05PU1RJQ19GQUlMRUQnOwogICAgICRzbXRwQ29kZSA9IGlzc2V0KCRtYWlsKSA/IChp
bnQpKCRtYWlsLT5nZXRTTVRQSW5zdGFuY2UoKS0+Z2V0RXJyb3IoKVsnc210cF9jb2RlJ10gPz8g
MCkgOiAwOwogICAgIGVycm9yX2xvZygnTUFJTF9ESUFHTk9TVElDX0ZBSUxFRCByZWFzb249JyAu
ICRyZWFzb24gLiAnIHNtdHBfY29kZT0nIC4gJHNtdHBDb2RlIC4gJyBleGNlcHRpb249JyAuIGdl
dF9jbGFzcygkZSkpOwo=
CENTRAL_PATCH_11_3
 [[ "$(digest "$TMP/patches/11/3.patch")" == '463b9c4c84ceac82ad1a33321062b4efcb20035662e8d0e18ced66e35ca2061d' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/11/4.patch" <<'CENTRAL_PATCH_11_4'
ZGlmZiAtLWdpdCBhL3Rvb2xzL2RpYWdub3N0aWNhcl9jb3JyZW9faG9zdGluZ2VyLnBocCBiL3Rv
b2xzL2RpYWdub3N0aWNhcl9jb3JyZW9faG9zdGluZ2VyLnBocAotLS0gYS90b29scy9kaWFnbm9z
dGljYXJfY29ycmVvX2hvc3Rpbmdlci5waHAKKysrIGIvdG9vbHMvZGlhZ25vc3RpY2FyX2NvcnJl
b19ob3N0aW5nZXIucGhwCkBAIC0xLDc2ICsxLDk1IEBACiA8P3BocAotCi1kZWNsYXJlKHN0cmlj
dF90eXBlcz0xKTsKLQotJHJvb3QgPSBkaXJuYW1lKF9fRElSX18pOwotJGNvbmZpZyA9ICRyb290
IC4gJy9jb25maWcvbWFpbF9jb25maWcucGhwJzsKLQotZWNobyAiPT09IERpYWduw7NzdGljbyBk
ZSBjb3JyZW8gaW5zdGl0dWNpb25hbCA9PT1cblxuIjsKLQotaWYgKCFpc19maWxlKCRjb25maWcp
KSB7Ci0gICAgZWNobyAiRVJST1I6IG5vIGV4aXN0ZSBjb25maWcvbWFpbF9jb25maWcucGhwXG4i
OwotICAgIGV4aXQoMSk7Ci19Ci0KLXJlcXVpcmVfb25jZSAkY29uZmlnOwotCi0kY2xhdmVzID0g
WwotICAgICdNQUlMX0hPU1QnLAotICAgICdNQUlMX1BPUlQnLAotICAgICdNQUlMX1VTRVJOQU1F
JywKLSAgICAnTUFJTF9QQVNTV09SRCcsCi0gICAgJ01BSUxfRU5DUllQVElPTicsCi0gICAgJ01B
SUxfRlJPTV9BRERSRVNTJywKLSAgICAnTUFJTF9GUk9NX05BTUUnLAotXTsKLQotJGVycm9yZXMg
PSAwOwotCi1mb3JlYWNoICgkY2xhdmVzIGFzICRjbGF2ZSkgewotICAgICR2YWxvciA9IGRlZmlu
ZWQoJGNsYXZlKSA/IGNvbnN0YW50KCRjbGF2ZSkgOiAnJzsKLSAgICAkdGV4dG8gPSB0cmltKChz
dHJpbmcpJHZhbG9yKTsKLQotICAgIGlmICgkY2xhdmUgPT09ICdNQUlMX1BBU1NXT1JEJykgewot
ICAgICAgICAkZXN0YWRvID0gJHRleHRvICE9PSAnJyA/ICdPSyAoY29uZmlndXJhZGEpJyA6ICdG
QUxUQSc7Ci0gICAgfSBlbHNlaWYgKCRjbGF2ZSA9PT0gJ01BSUxfUE9SVCcpIHsKLSAgICAgICAg
JGVzdGFkbyA9IChpbnQpJHZhbG9yID4gMCA/ICdPSyAoJyAuIChpbnQpJHZhbG9yIC4gJyknIDog
J0ZBTFRBJzsKKy8vIFPDs2xvIFNTSC9DTEkuIE5vIG11ZXN0cmEgY3JlZGVuY2lhbGVzIG5pIHBl
cm1pdGUgZW52aWFyIGRlc2RlIHVuYSBwZXRpY2nDs24gd2ViLgoraWYgKFBIUF9TQVBJICE9PSAn
Y2xpJykgeyBodHRwX3Jlc3BvbnNlX2NvZGUoNDA0KTsgZXhpdDsgfQorcmVxdWlyZSBkaXJuYW1l
KF9fRElSX18pIC4gJy9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZp
Y2UucGhwJzsKK3JlcXVpcmUgZGlybmFtZShfX0RJUl9fKSAuICcvYXBwL3NlcnZpY2VzL01haWxT
ZXJ2aWNlLnBocCc7Ciskb3B0aW9ucyA9IGdldG9wdCgnJywgWydyZWNvdmVyeScsICdjZW50cmFs
JywgJ2FjY291bnQ6JywgJ3VzZXI6JywgJ3Byb2JlJywgJ3NlbmQtdGVzdCcsICd0bzonLCAnZG9t
YWluOicsICdka2ltOiddKTsKK2Z1bmN0aW9uIGZsYWdNYWlsKCRsYWJlbCwgJG9rKSB7IGVjaG8g
JGxhYmVsIC4gJzogJyAuICgkb2sgPyAnT0snIDogJ0ZBTFRBL1JFVklTQVInKSAuIFBIUF9FT0w7
IH0KK3RyeSB7CisgICAgZWNobyAnUEhQICcgLiBQSFBfVkVSU0lPTiAuICcgU0FQSSAnIC4gUEhQ
X1NBUEkgLiBQSFBfRU9MOworICAgIGZvcmVhY2ggKFsnb3BlbnNzbCcsICdjdXJsJywgJ21ic3Ry
aW5nJywgJ215c3FsaSddIGFzICRleHQpIHsgZmxhZ01haWwoJ2V4dGVuc2lvbiAnIC4gJGV4dCwg
ZXh0ZW5zaW9uX2xvYWRlZCgkZXh0KSk7IH0KKyAgICBmbGFnTWFpbCgnUEhQTWFpbGVyJywgY2xh
c3NfZXhpc3RzKCdQSFBNYWlsZXJcXFBIUE1haWxlclxcUEhQTWFpbGVyJykpOworICAgIGlmIChp
c3NldCgkb3B0aW9uc1snY2VudHJhbCddKSkgeworICAgICAgICAkYSA9IChuZXcgQ29ycmVvQ3Vl
bnRhQ29uZWN0YWRhU2VydmljZSgpKS0+Y2VudHJhbCgpOyAkZnJvbSA9ICRhWydjb3JyZW8nXTsg
JHVzZXIgPSAoaW50KSgkb3B0aW9uc1sndXNlciddID8/IDApOworICAgICAgICBlY2hvICJDdWVu
dGEgaW5zdGl0dWNpb25hbCBjZW50cmFsLiBObyB1c2EgY29uZXhpb25lcyBwZXJzb25hbGVzLlxu
IjsKKyAgICB9IGVsc2VpZiAoaXNzZXQoJG9wdGlvbnNbJ2FjY291bnQnXSkpIHsKKyAgICAgICAg
JGZyb20gPSBzdHJ0b2xvd2VyKHRyaW0oJG9wdGlvbnNbJ2FjY291bnQnXSkpOyAkdXNlciA9IChp
bnQpKCRvcHRpb25zWyd1c2VyJ10gPz8gMCk7CisgICAgICAgICRyZXN1bHQgPSAobmV3IENvcnJl
b0N1ZW50YUNvbmVjdGFkYVNlcnZpY2UoKSktPnJlc29sdmVyKCRmcm9tLCAkdXNlciwgdHJ1ZSk7
CisgICAgICAgIGlmIChlbXB0eSgkcmVzdWx0WydvayddKSkgeyBlY2hvICdDdWVudGE6ICcgLiAk
cmVzdWx0WydtZW5zYWplJ10gLiBQSFBfRU9MOyBleGl0KDIpOyB9CisgICAgICAgICRhID0gJHJl
c3VsdFsnYWNjb3VudCddOwogICAgIH0gZWxzZSB7Ci0gICAgICAgICRlc3RhZG8gPSAkdGV4dG8g
IT09ICcnID8gJ09LICgnIC4gJHRleHRvIC4gJyknIDogJ0ZBTFRBJzsKKyAgICAgICAgZWNobyAn
Q29uZmlndXJhY2nDs24gZGUgcmVjdXBlcmFjacOzbjogJyAuIChpbXBlUnV0YUNvbmZpZ1ByaXZh
ZGEoJ3Bhc3N3b3JkX3Jlc2V0X21haWwubG9jYWwucGhwJykgIT09IG51bGwgPyAnYXJjaGl2byBk
ZWRpY2FkbycgOiAnTUFJTF8qIGRlIGFyY2hpdm8gcHJpdmFkby9lbnRvcm5vJykgLiBQSFBfRU9M
OworICAgICAgICAkYSA9IE1haWxTZXJ2aWNlOjpjdWVudGFSZWN1cGVyYWNpb24oKTsKKyAgICAg
ICAgJGZyb20gPSAoc3RyaW5nKSgkYVsnY29ycmVvJ10gPz8gJGFbJ3NtdHBfdXNlcm5hbWUnXSA/
PyAnJyk7CisgICAgICAgICR1c2VyID0gMDsKKyAgICAgICAgZWNobyAiQ3VlbnRhIGRlZGljYWRh
IGRlIHJlY3VwZXJhY2nDs24gKG5vIG1vZGlmaWNhIHVzdWFyaW9zIG5pIGNvbnRyYXNlw7Fhcyku
XG4iOwogICAgIH0KLQotICAgIGlmIChzdHJwb3MoJGVzdGFkbywgJ0ZBTFRBJykgPT09IDApIHsK
LSAgICAgICAgJGVycm9yZXMrKzsKKyAgICAkdHJhbnNwb3J0ID0gJGFbJ3RyYW5zcG9ydCddID8/
ICdzbXRwJzsKKyAgICBlY2hvICdUcmFuc3BvcnRlOiAnIC4gJHRyYW5zcG9ydCAuIFBIUF9FT0w7
CisgICAgaWYgKCR0cmFuc3BvcnQgPT09ICdob3N0aW5nZXJfYXBpJyAmJiAkZnJvbSA9PT0gJycp
IHsgZWNobyAiSWRlbnRpZGFkOiBzZWxlY2Npw7NuIGF1dG9tw6F0aWNhIGRlbCBidXrDs24gZGVs
IHVzdWFyaW8gcmVnaXN0cmFkby5cbiI7IH0KKyAgICBlbHNlIHsgZmxhZ01haWwoJ0lkZW50aWRh
ZCBjb25maWd1cmFkYScsIGZpbHRlcl92YXIoJGZyb20sIEZJTFRFUl9WQUxJREFURV9FTUFJTCkg
IT09IGZhbHNlKTsgfQorICAgIGlmICgkdHJhbnNwb3J0ID09PSAnc210cCcpIHsKKyAgICAgICAg
JG1haWwgPSBuZXcgXFBIUE1haWxlclxQSFBNYWlsZXJcUEhQTWFpbGVyKHRydWUpOworICAgICAg
ICBDb3JyZW9TbXRwVHJhbnNwb3J0Ojpjb25maWd1cmFyKCRtYWlsLCAkYSk7CisgICAgICAgIGVj
aG8gJ1NNVFAgJyAuICRtYWlsLT5Ib3N0IC4gJzonIC4gJG1haWwtPlBvcnQgLiAnICcgLiAkbWFp
bC0+U01UUFNlY3VyZSAuIFBIUF9FT0w7CisgICAgICAgIGZsYWdNYWlsKCdUTFMgeSBjZXJ0aWZp
Y2Fkb3MgdmVyaWZpY2Fkb3MnLCAkbWFpbC0+U01UUE9wdGlvbnNbJ3NzbCddWyd2ZXJpZnlfcGVl
ciddICYmICRtYWlsLT5TTVRQT3B0aW9uc1snc3NsJ11bJ3ZlcmlmeV9wZWVyX25hbWUnXSk7Cisg
ICAgICAgIGZsYWdNYWlsKCdETlMgU01UUCcsIGZpbHRlcl92YXIoJG1haWwtPkhvc3QsIEZJTFRF
Ul9WQUxJREFURV9JUCkgfHwgZ2V0aG9zdGJ5bmFtZSgkbWFpbC0+SG9zdCkgIT09ICRtYWlsLT5I
b3N0KTsKKyAgICAgICAgaWYgKGlzc2V0KCRvcHRpb25zWydwcm9iZSddKSkgeworICAgICAgICAg
ICAgLy8gRUhMTy9UTFMvQVVUSCwgc2luIE1BSUwvUkNQVC9EQVRBLiBPQXV0aCBwdWVkZSByZW5v
dmFyIHN1IHRva2VuIHByaXZhZG8uCisgICAgICAgICAgICAkY29ubmVjdGVkID0gJG1haWwtPnNt
dHBDb25uZWN0KCk7IGZsYWdNYWlsKCdDb25leGnDs24gVExTIHkgYXV0ZW50aWNhY2nDs24nLCAk
Y29ubmVjdGVkKTsgJG1haWwtPnNtdHBDbG9zZSgpOworICAgICAgICAgICAgaWYgKCEkY29ubmVj
dGVkKSB7IGV4aXQoMik7IH0KKyAgICAgICAgfQorICAgIH0gZWxzZWlmICgkdHJhbnNwb3J0ID09
PSAnaG9zdGluZ2VyX2FwaScpIHsKKyAgICAgICAgZmxhZ01haWwoJ1Rva2VuIEFQSSBjb25maWd1
cmFkbycsICFlbXB0eSgkYVsnYXBpX3Rva2VuJ10pKTsKKyAgICAgICAgaWYgKGlzc2V0KCRvcHRp
b25zWydwcm9iZSddKSkgeworICAgICAgICAgICAgaWYgKCgkYVsnYXBpX2Jhc2VfdXJsJ10gPz8g
JycpICE9PSAnaHR0cHM6Ly9hcGkubWFpbC5ob3N0aW5nZXIuY29tJykgeyB0aHJvdyBuZXcgUnVu
dGltZUV4Y2VwdGlvbignTUFJTF9BUElfQkFTRScpOyB9CisgICAgICAgICAgICAkYyA9IGN1cmxf
aW5pdCgnaHR0cHM6Ly9hcGkubWFpbC5ob3N0aW5nZXIuY29tL2FwaS92MS9tZScpOworICAgICAg
ICAgICAgY3VybF9zZXRvcHRfYXJyYXkoJGMsIFtDVVJMT1BUX1JFVFVSTlRSQU5TRkVSID0+IHRy
dWUsIENVUkxPUFRfSFRUUEhFQURFUiA9PiBbJ0F1dGhvcml6YXRpb246IEJlYXJlciAnIC4gJGFb
J2FwaV90b2tlbiddXSwKKyAgICAgICAgICAgICAgICBDVVJMT1BUX1NTTF9WRVJJRllQRUVSID0+
IHRydWUsIENVUkxPUFRfU1NMX1ZFUklGWUhPU1QgPT4gMiwgQ1VSTE9QVF9GT0xMT1dMT0NBVElP
TiA9PiBmYWxzZSwgQ1VSTE9QVF9DT05ORUNUVElNRU9VVCA9PiAxMCwgQ1VSTE9QVF9USU1FT1VU
ID0+IDI1XSk7CisgICAgICAgICAgICAkYm9keSA9IGN1cmxfZXhlYygkYyk7ICRjb2RlID0gKGlu
dCljdXJsX2dldGluZm8oJGMsIENVUkxJTkZPX0hUVFBfQ09ERSk7IGN1cmxfY2xvc2UoJGMpOwor
ICAgICAgICAgICAgJGpzb24gPSBqc29uX2RlY29kZSgoc3RyaW5nKSRib2R5LCB0cnVlKTsgJGFs
bG93ZWQgPSAkZnJvbSA9PT0gJycgJiYgIWVtcHR5KCRqc29uWydkYXRhJ11bJ21haWxib3hlcydd
KTsKKyAgICAgICAgICAgIGZvcmVhY2ggKCgkanNvblsnZGF0YSddWydtYWlsYm94ZXMnXSA/PyBb
XSkgYXMgJGJveCkgeyBpZiAoc3RyY2FzZWNtcCgoc3RyaW5nKSgkYm94WydhZGRyZXNzJ10gPz8g
JycpLCAkZnJvbSkgPT09IDApIHsgJGFsbG93ZWQgPSB0cnVlOyB9IH0KKyAgICAgICAgICAgIGVj
aG8gJ0hUVFAgY29uc3VsdGEgQVBJOiAnIC4gJGNvZGUgLiBQSFBfRU9MOyBmbGFnTWFpbCgnQnV6
w7NuIGF1dG9yaXphZG8nLCAkY29kZSA9PT0gMjAwICYmICRhbGxvd2VkKTsKKyAgICAgICAgICAg
IGVjaG8gJ0J1em9uZXMgZGlzcG9uaWJsZXM6ICcgLiBjb3VudCgkanNvblsnZGF0YSddWydtYWls
Ym94ZXMnXSA/PyBbXSkgLiBQSFBfRU9MOworICAgICAgICAgICAgaWYgKCRjb2RlICE9PSAyMDAg
fHwgISRhbGxvd2VkKSB7IGV4aXQoMik7IH0KKyAgICAgICAgfQogICAgIH0KLQotICAgIGVjaG8g
c3RyX3BhZCgkY2xhdmUsIDIyKSAuICI6ICIgLiAkZXN0YWRvIC4gUEhQX0VPTDsKLX0KLQotZWNo
byAiXG4iOwotCi0kaG9zdCA9IGRlZmluZWQoJ01BSUxfSE9TVCcpID8gdHJpbSgoc3RyaW5nKU1B
SUxfSE9TVCkgOiAnJzsKLSRwb3J0ID0gZGVmaW5lZCgnTUFJTF9QT1JUJykgPyAoaW50KU1BSUxf
UE9SVCA6IDA7Ci0KLWlmICgkaG9zdCAhPT0gJycgJiYgJHBvcnQgPiAwKSB7Ci0gICAgZWNobyAi
UHJvYmFuZG8gY29uZXhpw7NuIFRDUCBhIHskaG9zdH06eyRwb3J0fS4uLlxuIjsKLSAgICAkZXJy
bm8gPSAwOwotICAgICRlcnJzdHIgPSAnJzsKLSAgICAkc29ja2V0ID0gQGZzb2Nrb3BlbigkaG9z
dCwgJHBvcnQsICRlcnJubywgJGVycnN0ciwgOCk7Ci0KLSAgICBpZiAoaXNfcmVzb3VyY2UoJHNv
Y2tldCkpIHsKLSAgICAgICAgZWNobyAiQ09ORVhJw5NOIFNNVFA6IE9LXG4iOwotICAgICAgICBm
Y2xvc2UoJHNvY2tldCk7Ci0gICAgfSBlbHNlIHsKLSAgICAgICAgZWNobyAiQ09ORVhJw5NOIFNN
VFA6IEVSUk9SICh7JGVycm5vfSkgeyRlcnJzdHJ9XG4iOwotICAgICAgICAkZXJyb3JlcysrOwor
ICAgIGlmIChpc3NldCgkb3B0aW9uc1snZG9tYWluJ10pKSB7CisgICAgICAgICRkb21haW4gPSAk
b3B0aW9uc1snZG9tYWluJ107ICRzZWxlY3RvciA9ICRvcHRpb25zWydka2ltJ10gPz8gJyc7Cisg
ICAgICAgIGlmICghcHJlZ19tYXRjaCgnL15bYS16QS1aMC05Li1dKyQvRCcsICRkb21haW4gLiAk
c2VsZWN0b3IpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdNQUlMX0ROU19JTlBVVCcp
OyB9CisgICAgICAgICRzcGYgPSBmYWxzZTsKKyAgICAgICAgZm9yZWFjaCAoZG5zX2dldF9yZWNv
cmQoJGRvbWFpbiwgRE5TX1RYVCkgPzogW10gYXMgJHJlY29yZCkgeyBpZiAoc3RyX3N0YXJ0c193
aXRoKCRyZWNvcmRbJ3R4dCddID8/ICcnLCAndj1zcGYxJykpIHsgJHNwZiA9IHRydWU7IH0gfQor
ICAgICAgICBmbGFnTWFpbCgnUmVnaXN0cm8gU1BGIHByZXNlbnRlIChubyBjZXJ0aWZpY2EgYWxp
bmVhY2nDs24pJywgJHNwZik7CisgICAgICAgIGZsYWdNYWlsKCdSZWdpc3RybyBETUFSQyBwcmVz
ZW50ZScsIChib29sKWRuc19nZXRfcmVjb3JkKCdfZG1hcmMuJyAuICRkb21haW4sIEROU19UWFQp
KTsKKyAgICAgICAgaWYgKCRzZWxlY3RvciAhPT0gJycpIHsgZmxhZ01haWwoJ1JlZ2lzdHJvIERL
SU0gcHJlc2VudGUnLCAoYm9vbClkbnNfZ2V0X3JlY29yZCgkc2VsZWN0b3IgLiAnLl9kb21haW5r
ZXkuJyAuICRkb21haW4sIEROU19UWFQgfCBETlNfQ05BTUUpKTsgfQogICAgIH0KLX0KLQotZWNo
byAiXG4iOwotCi1pZiAoJGVycm9yZXMgPiAwKSB7Ci0gICAgZWNobyAiUkVTVUxUQURPOiBjb25m
aWd1cmFjacOzbiBpbmNvbXBsZXRhIG8gY29uZXhpw7NuIG5vIGRpc3BvbmlibGUuXG4iOworICAg
IGlmIChpc3NldCgkb3B0aW9uc1snc2VuZC10ZXN0J10pKSB7CisgICAgICAgIGlmIChpc3NldCgk
b3B0aW9uc1snY2VudHJhbCddKSAmJiAkdXNlciA8PSAwKSB7IHRocm93IG5ldyBSdW50aW1lRXhj
ZXB0aW9uKCdNQUlMX1RFU1RfVVNFUicpOyB9CisgICAgICAgIGlmIChpc3NldCgkb3B0aW9uc1sn
Y2VudHJhbCddKSAmJiAkZnJvbSA9PT0gJycgJiYgJHVzZXIgPiAwKSB7CisgICAgICAgICAgICBy
ZXF1aXJlX29uY2UgZGlybmFtZShfX0RJUl9fKSAuICcvYXBwL21vZGVscy9Vc3VhcmlvTW9kZWwu
cGhwJzsKKyAgICAgICAgICAgICRmcm9tID0gKHN0cmluZykoKG5ldyBVc3VhcmlvTW9kZWwoKSkt
PmJ1c2NhclBvcklkKCR1c2VyKVsnY29ycmVvJ10gPz8gJycpOworICAgICAgICB9CisgICAgICAg
ICR0byA9IHRyaW0oKHN0cmluZykoJG9wdGlvbnNbJ3RvJ10gPz8gJycpKTsKKyAgICAgICAgaWYg
KCFmaWx0ZXJfdmFyKCR0bywgRklMVEVSX1ZBTElEQVRFX0VNQUlMKSkgeyB0aHJvdyBuZXcgUnVu
dGltZUV4Y2VwdGlvbignTUFJTF9URVNUX1JFQ0lQSUVOVCcpOyB9CisgICAgICAgICRzdWJqZWN0
ID0gJ0lNUEUgcHJ1ZWJhIGF1dG9yaXphZGEgJyAuIGdtZGF0ZSgnWS1tLWQgSDppOnMnKTsKKyAg
ICAgICAgJGJvZHkgPSAnTWVuc2FqZSBmaWN0aWNpbyBkZSBkaWFnbsOzc3RpY28uIE5vIGNvbnRp
ZW5lIHVuYSBjb250cmFzZcOxYSBuaSBjYW1iaWEgbmluZ3VuYSBjdWVudGEuIENvbmZpcm1hciBy
ZWNlcGNpw7NuIG1hbnVhbG1lbnRlLic7CisgICAgICAgIGlmICgkdXNlciA+IDApIHsKKyAgICAg
ICAgICAgICRyID0gKG5ldyBDb3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZSgpKS0+ZW52
aWFyKFsndXN1YXJpb19pZCcgPT4gJHVzZXIsICdwZXJmaWxfbWFya2V0aW5nJyA9PiB0cnVlLAor
ICAgICAgICAgICAgICAgICdyZW1pdGVudGUnID0+ICRmcm9tLCAnZGVzdGluYXRhcmlvJyA9PiAk
dG8sICdhc3VudG8nID0+ICRzdWJqZWN0LCAnY3VlcnBvJyA9PiAkYm9keSwKKyAgICAgICAgICAg
ICAgICAnaWRlbXBvdGVuY3lfa2V5JyA9PiAncWEtY2xpOicgLiBiaW4yaGV4KHJhbmRvbV9ieXRl
cygxNikpXSk7CisgICAgICAgIH0gZWxzZSB7CisgICAgICAgICAgICAkbWFpbC0+c2V0RnJvbSgk
ZnJvbSk7ICRtYWlsLT5hZGRBZGRyZXNzKCR0byk7ICRtYWlsLT5TdWJqZWN0ID0gJHN1YmplY3Q7
ICRtYWlsLT5Cb2R5ID0gJGJvZHk7CisgICAgICAgICAgICAkciA9IENvcnJlb1NtdHBUcmFuc3Bv
cnQ6OmVudmlhcigkbWFpbCwgJ3JlY292ZXJ5X3Rlc3QnKTsKKyAgICAgICAgfQorICAgICAgICBl
Y2hvICdSZXN1bHRhZG86ICcgLiAoJHJbJ2VzdGFkb19lbnRyZWdhJ10gPz8gJ1JFQ0hBWkFETycp
IC4gJzsgZW50cmVnYSBhbCBkZXN0aW5hdGFyaW8gTk8gY29uZmlybWFkYS4nIC4gUEhQX0VPTDsK
KyAgICAgICAgaWYgKGVtcHR5KCRyWydvayddKSkgeyBleGl0KDIpOyB9CisgICAgfSBlbHNlIHsg
ZWNobyAiU2luIGVudsOtbyBkZSBtZW5zYWplcy4gVXNhIC0tcHJvYmUgcGFyYSBjb21wcm9iYXIg
VExTL2F1dGVudGljYWNpw7NuIGRlc2RlIFNTSC5cbiI7IH0KK30gY2F0Y2ggKFRocm93YWJsZSAk
ZSkgeworICAgICRhbGxvd2VkID0gWydNQUlMX1JFU0VUX0NPTkZJRycsICdNQUlMX1JFU0VUX1RS
QU5TUE9SVCcsICdNQUlMX1JFU0VUX0lERU5USVRZJywgJ01BSUxfU01UUF9DT05GSUcnLCAnTUFJ
TF9TTVRQX0NBJywgJ01BSUxfQ0VOVFJBTF9DT05GSUcnLCAnTUFJTF9URVNUX1VTRVInXTsKKyAg
ICAkcmVhc29uID0gaW5fYXJyYXkoJGUtPmdldE1lc3NhZ2UoKSwgJGFsbG93ZWQsIHRydWUpID8g
JGUtPmdldE1lc3NhZ2UoKSA6ICdNQUlMX0RJQUdOT1NUSUNfRkFJTEVEJzsKKyAgICAkc210cENv
ZGUgPSBpc3NldCgkbWFpbCkgPyAoaW50KSgkbWFpbC0+Z2V0U01UUEluc3RhbmNlKCktPmdldEVy
cm9yKClbJ3NtdHBfY29kZSddID8/IDApIDogMDsKKyAgICBlcnJvcl9sb2coJ01BSUxfRElBR05P
U1RJQ19GQUlMRUQgcmVhc29uPScgLiAkcmVhc29uIC4gJyBzbXRwX2NvZGU9JyAuICRzbXRwQ29k
ZSAuICcgZXhjZXB0aW9uPScgLiBnZXRfY2xhc3MoJGUpKTsKKyAgICBlY2hvICdDw7NkaWdvIHNl
Z3VybzogJyAuICRyZWFzb24gLiAoJHNtdHBDb2RlID8gJzsgU01UUCAnIC4gJHNtdHBDb2RlIDog
JycpIC4gUEhQX0VPTDsKKyAgICBlY2hvICJEaWFnbsOzc3RpY28gaW5jb21wbGV0by4gUmV2aXNh
ciBjb25maWd1cmFjacOzbiwgcGVybWlzb3MgcHJpdmFkb3MsIGNlcnRpZmljYWRvcywgYXV0ZW50
aWNhY2nDs24gbyByZXN0cmljY2lvbmVzIHNhbGllbnRlcy4gTm8gc2UgbXVlc3RyYW4gc2VjcmV0
b3MuXG4iOwogICAgIGV4aXQoMik7CiB9Ci0KLWVjaG8gIlJFU1VMVEFETzogY29uZmlndXJhY2nD
s24gYmFzZSBjb21wbGV0YS5cbiI7Ci1lY2hvICJTaSBlbCBlbnbDrW8gYcO6biBmYWxsYSwgcmV2
aXNhIGF1dGVudGljYWNpw7NuL2NyZWRlbmNpYWxlcyBvIGVsIGxvZyBQSFAuXG4iOwo=
CENTRAL_PATCH_11_4
 [[ "$(digest "$TMP/patches/11/4.patch")" == '0b5f70b7c138c1676d184e7b28549e4ab410fddb2d72db4ca73ed06b8f935beb' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/12"
 base64 --decode > "$TMP/patches/12/1.patch" <<'CENTRAL_PATCH_12_1'
ZGlmZiAtLWdpdCBhL2luZGV4LnBocCBiL2luZGV4LnBocAotLS0gYS9pbmRleC5waHAKKysrIGIv
aW5kZXgucGhwCkBAIC0yMjEsNiArMjIxLDcgQEAKICAqIGF1dG9tw6F0aWNhbWVudGUgZGVzZGUg
ZGFzaGJvYXJkX2hlYWQucGhwLgogICovCiAkY29udHJvbGFkb3Jlc1Byb3RlZ2lkb3NDc3JmID0g
WworICAgICdjb3JyZW9NYXJrZXRpbmcnLAogICAgICdzZWd1aW1pZW50b1ZpbmN1bGFjaW9uJywK
ICAgICAnc2VndWltaWVudG9GbHVqbycsCiAgICAgJ3NlZ3VpbWllbnRvSW50ZXJhY2Npb24nLApA
QCAtNTY0LDYgKzU2NSwxMSBAQAogICAgICAgICBicmVhazsKIAogCisgICAgY2FzZSAnY29ycmVv
Q29uZXhpb24nOgorICAgICAgICByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvYXBwL2NvbnRyb2xs
ZXJzL0NvcnJlb0NvbmV4aW9uQ29udHJvbGxlci5waHAnOworICAgICAgICAkY29udHJvbGxlcklu
c3RhbmNlID0gbmV3IENvcnJlb0NvbmV4aW9uQ29udHJvbGxlcigpOworICAgICAgICBicmVhazsK
KwogICAgIGNhc2UgJ2NvcnJlb01hcmtldGluZyc6CiAKICAgICAgICAgcmVxdWlyZV9vbmNlIF9f
RElSX18gLgpAQCAtNjk3LDQgKzcwMyw0IEBACiB9CiAKIAotJGNvbnRyb2xsZXJJbnN0YW5jZS0+
JGFjdGlvbigpOwpcIE5vIG5ld2xpbmUgYXQgZW5kIG9mIGZpbGUKKyRjb250cm9sbGVySW5zdGFu
Y2UtPiRhY3Rpb24oKTsK
CENTRAL_PATCH_12_1
 [[ "$(digest "$TMP/patches/12/1.patch")" == '8fd1bd6ea5c9bc974e3cfce04146ed9c53333b6caf63fc29d2c4efc3e44f666c' ]] || fail 'Parche de entrega alterado.'
 declare -A EOLS=()
 f='config/mail_config.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != 'a6a67cbb5e81395c8c7f909e22ee981477f6d12387147e5ccd545695a1702d3c' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '4b2d59abbd2508cd0e78b9c04051b20d334eb98ee46e7122d61bac6635c73c54' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/0/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/0/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == 'e8d2f5560b111046f0968601d4bcdb88ebe21ba2bccc0a7fe93f915014da5036' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/0/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/0/2.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == 'a6a67cbb5e81395c8c7f909e22ee981477f6d12387147e5ccd545695a1702d3c' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/services/MailService.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '3cd9a3c96fa32358c881f30043de084f3e73cd46ea735ef2fe9c3ad4322f66f1' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '12b8d6902f1d2a986d08034e87b53e6e894c92154d12d53eb0ea104e00b504c1' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/1/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/1/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '5915d6e4bb614183e0d85964719e8285e58b4243880822171e5acd1d642cda79' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/1/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/1/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '5a572c50beeccffe4f21d44beae49839c6a8074ed1f9527c70e008d9605d4c97' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/1/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/1/3.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '3cd9a3c96fa32358c881f30043de084f3e73cd46ea735ef2fe9c3ad4322f66f1' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/services/CorreoSalidaInstitucionalService.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '4007f9192323cbf6831e1b383aebc3b407b778a6bbe137abab63cb06deff4dff' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '554b402353038e218c55540d79ca223a421082e276c8ddbd74c65b4a9d5c3f68' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/2/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/2/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '7d76e53b38c61523c2a8243aef4920385119e9024a9a6fe02e816a86fd38d017' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/2/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/2/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == 'f4b4062acf6b596eec0f9d66dc417f7c21acbd6d32229a5a33c930ee8e2fd338' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/2/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/2/3.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '4007f9192323cbf6831e1b383aebc3b407b778a6bbe137abab63cb06deff4dff' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/services/CorreoMarketingService.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '4962e63bbb777300567e9e59d2a0ad0701a23ceffdf11384381f1c7246c0ddc2' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == 'd3d0b336054a77638f67c5c1b0903a281efb2901265792bb4c150b17f0000e03' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/3/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/3/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '8d2aa728cf5dcce219905a3d01efe5c3dc36e266087d600128c8d0402efad1d5' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/3/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/3/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '162f6eea2aad64a54242ca2286a9f95596514cf1ce3bcb5503c066e0f4356b3c' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/3/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/3/3.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '4962e63bbb777300567e9e59d2a0ad0701a23ceffdf11384381f1c7246c0ddc2' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/services/SeguimientoCorreoService.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '506d11ddc735fb5da9f64d56f808ff4b1c3be2ed69c4c155b892123c42d05ccf' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '382795138bf979db2eaed7f60a26070a02bdd62eebb0308b6d3499046167f7e5' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/4/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/4/1.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '506d11ddc735fb5da9f64d56f808ff4b1c3be2ed69c4c155b892123c42d05ccf' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/services/OficioCorreoService.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '80f0515cc389e8dad0a6d421b6a0215968dd9b937d8e2b1ce4c25e213d5298ff' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == 'f3541dd9e4ac8111a26fd584dcf7ca1ce3438e500e7d4374d502c2f0cc60cef6' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/5/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/5/1.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '80f0515cc389e8dad0a6d421b6a0215968dd9b937d8e2b1ce4c25e213d5298ff' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/controllers/CorreoMarketingController.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '680c5d70f19beb9332f2f5cd9b805918c69d3449be99af48b17f06adb416891d' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == 'e77c73702142a2ffb914aa30aa1a860649c1395ddafc5108bf613db9aa5fb1bc' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/6/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/6/1.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '680c5d70f19beb9332f2f5cd9b805918c69d3449be99af48b17f06adb416891d' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/controllers/LoginController.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '538f0ce621419cfa3cdf8dfc340854cfa67aecff37b68068a769dcc30ac26e67' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '6ea8c65de30f67906a8b3623aeecb608b5735501a859722de786f6647353a5e1' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/7/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/7/1.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '538f0ce621419cfa3cdf8dfc340854cfa67aecff37b68068a769dcc30ac26e67' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/models/UsuarioModel.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '26e8989d532b7daffb8cf29738d440bef373ab716bdca1337b9d0eec340fb428' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '0b856125188a40fb8cf17a621ddd8812d8979252ae3dd6b5422b5602652c4082' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/8/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/8/1.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '26e8989d532b7daffb8cf29738d440bef373ab716bdca1337b9d0eec340fb428' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/views/correos/index.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '2f130c3a2c4d8c54b80a1823ad590a9ff8a598cc0a1130879911d3fe196b0778' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '05fc342591ba9f63c1ba16bd694cc7141a657a2323576051457350e2b2c82ad1' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/9/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/9/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == 'e97fbddeb61194ced66f02de2253c46dfa560a59555597460d792a586f4b8ad5' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/9/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/9/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '0c40de7cfe1599ac8395af31cf7cafd8d82efeaaa7addf625b86f71a5199882f' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/9/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/9/3.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '03352ebbe8562c792a7735c1648c8b52e16b0c134fb1ca677346cb287c97b767' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/9/4.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/9/4.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '2f130c3a2c4d8c54b80a1823ad590a9ff8a598cc0a1130879911d3fe196b0778' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='public/javascript/correos_marketing.js'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '3ad842a04ddbd77c16cd939635e44e0249ede2df0a3f2d62eb479d4ebbb7611f' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '5b6c82a1c2f92c9b0315e928c355a3f1af599b234830b29e3b1c15e425fbbdff' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/10/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/10/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '95c25263cee156a35be76f168d550cacd01f5f5cf47941bb862fc2a99d60ecf6' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/10/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/10/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '2f8054f9d1731201ceacec75331c019c5100a17b2edf4ce48af33b14e32e96af' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/10/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/10/3.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == 'cb172de4882047b05c6d0d2e1e6661c552fcc9188998d3d4a7be3eae7633ed5d' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/10/4.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/10/4.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '3ad842a04ddbd77c16cd939635e44e0249ede2df0a3f2d62eb479d4ebbb7611f' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='tools/diagnosticar_correo_hostinger.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != 'ee0fa6c456dce2cd2c25dbebdb8c17c38d545c0dd64053ab1647925cfde6326f' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == 'a5b54ef98b9d1aaea14c685134f6ef16e942099b5801fa29337cc59446dec672' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/11/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/11/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == 'cc787c309c8aeacb414c5f76f0557b0b51090a3b7befd041000fff97bdd5430f' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/11/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/11/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '12ccd3d30ba5a86f7ba5db096857f401ab48beebbe88a9f14643599c888bac77' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/11/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/11/3.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '9d460ba9efafce239c66bf207260b1bac07a6d6897dde011cf2b8e1bfc66a72b' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/11/4.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/11/4.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == 'ee0fa6c456dce2cd2c25dbebdb8c17c38d545c0dd64053ab1647925cfde6326f' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='index.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '58163994defcea8d5e4d1d18550a81c2caf22b84e448a1e7ec7636923aab3a28' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '06b8cad93d5f7560ebab46b6d0047b805c9fe001c78acbafba412b8f0c7b4b80' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/12/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/12/1.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '58163994defcea8d5e4d1d18550a81c2caf22b84e448a1e7ec7636923aab3a28' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
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
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/correos-multiproveedor-$(date +%Y%m%d-%H%M%S)-$$";mkdir -m 700 "$BACKUP"
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt"
 if [[ ! -d "$LIVE/tools" ]];then : > "$BACKUP/tools-directory-created";fi
 for f in "${CHANGED[@]}";do
  printf '%s\t%s\t%s\n' "$f" "${BEFORE[$f]}" "${AFTER[$f]}" >> "$BACKUP/archivos.tsv"
  if [[ "${BEFORE[$f]}" != MISSING ]];then mkdir -p "$BACKUP/$(dirname "$f")";cp -p -- "$TMP/original/$f" "$BACKUP/$f";[[ "$(digest "$BACKUP/$f")" == "${BEFORE[$f]}" ]] || fail 'Respaldo inconsistente.';fi
 done
fi
WRITING=1
if [[ "$MODE" == --apply && ! -d "$LIVE/tools" ]];then no_links "$LIVE/tools";mkdir -- "$LIVE/tools";CREATED_TOOLS=1;fi
for f in "${FILES[@]}";do
 [[ -n "${AFTER[$f]:-}" && "${BEFORE[$f]}" != "${AFTER[$f]}" ]] || continue
 unchanged "$f";[[ ! -e "$LIVE/$f.impe-new-$$" && ! -L "$LIVE/$f.impe-new-$$" ]] || fail 'Existe un temporal de instalación.'
 WRITTEN+=("$f")
 if [[ "${AFTER[$f]}" == MISSING ]];then rm -- "$LIVE/$f";
 else cp -p -- "$TMP/stage/$f" "$LIVE/$f.impe-new-$$";mv -f -- "$LIVE/$f.impe-new-$$" "$LIVE/$f";[[ "$(digest "$LIVE/$f")" == "${AFTER[$f]}" ]] || fail 'Archivo instalado distinto.';fi
done
WRITING=0
if [[ "$MODE" == --restore && -f "$RESTORE/tools-directory-created" ]];then rmdir -- "$LIVE/tools" 2>/dev/null || :;fi
echo "OK: $MODE terminado. ${#CHANGED[@]} archivo(s). Sin cambios de BD ni credenciales. Verificar envíos reales por separado."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
