#!/usr/bin/env bash
# Correos autenticados. Publicación selectiva; sin SQL, vendor ni credenciales.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='hostinger-comercial-v2-8fd03e45b6f4d1cb'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/services/CorreoSalidaInstitucionalService.php'
DIAGNOSTIC='tools/diagnosticar_hostinger_comercial.php'
FILES=("app/services/CorreoHostingerApiService.php" "app/services/CorreoCuentaConectadaService.php" "tools/diagnosticar_hostinger_comercial.php" "tools/configurar_hostinger_comercial.sh" "app/services/CorreoSalidaInstitucionalService.php" "app/services/CorreoMarketingService.php" "app/controllers/CorreoMarketingController.php" "app/views/correos/index.php" "public/javascript/correos_marketing.js" "index.php")
PATCH_FILES=("app/services/CorreoSalidaInstitucionalService.php" "app/services/CorreoMarketingService.php" "app/controllers/CorreoMarketingController.php" "app/views/correos/index.php" "public/javascript/correos_marketing.js" "index.php")
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
FILES=("index.php" "public/javascript/correos_marketing.js" "app/views/correos/index.php" "app/controllers/CorreoMarketingController.php" "app/services/CorreoMarketingService.php" "app/services/CorreoSalidaInstitucionalService.php" "tools/configurar_hostinger_comercial.sh" "tools/diagnosticar_hostinger_comercial.php" "app/services/CorreoCuentaConectadaService.php" "app/services/CorreoHostingerApiService.php")
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
 base64 --decode > "$TMP/stage/app/services/CorreoHostingerApiService.php" <<'CENTRAL_HELPER_0'
PD9waHAKcmVxdWlyZV9vbmNlIGRpcm5hbWUoX19ESVJfXywgMikgLiAnL2NvbmZpZy9wcml2YXRl
X2NvbmZpZy5waHAnOwoKLyoqIFRyYW5zcG9ydGUgY29tZXJjaWFsIGFpc2xhZG8gZGUgU01UUCwg
bG9naW4geSByZWN1cGVyYWNpw7NuLiAqLwpmaW5hbCBjbGFzcyBDb3JyZW9Ib3N0aW5nZXJBcGlT
ZXJ2aWNlCnsKICAgIHB1YmxpYyBjb25zdCBCQVNFID0gJ2h0dHBzOi8vYXBpLm1haWwuaG9zdGlu
Z2VyLmNvbSc7CiAgICBwdWJsaWMgY29uc3QgVkVSU0lPTiA9ICdob3N0aW5nZXItY29tZXJjaWFs
LWJ1em9uZXMtdjInOwogICAgcHVibGljIGNvbnN0IERPTUlOSU8gPSAncmVkZWR1Y2F0aXZhbWV4
aWNvLm9yZyc7CiAgICBwcml2YXRlICRyZXF1ZXN0OwogICAgcHJpdmF0ZSAkZGVzY3VicmltaWVu
dG8gPSBbXTsKICAgIHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0cnVjdCg/Y2FsbGFibGUgJHJlcXVl
c3Q9bnVsbCkgeyAkdGhpcy0+cmVxdWVzdD0kcmVxdWVzdDsgfQoKICAgIHB1YmxpYyBmdW5jdGlv
biBjb25maWd1cmFjaW9uKCk6IGFycmF5CiAgICB7CiAgICAgICAgJGNlbnRyYWw9aW1wZUxlZXJD
b25maWdQcml2YWRhKCdtYWlsX2NlbnRyYWwubG9jYWwucGhwJyk7CiAgICAgICAgaWYoKCRjZW50
cmFsWydhY3Rpdm8nXT8/dHJ1ZSkhPT10cnVlKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9u
KCdIT1NUSU5HRVJfREVTQUNUSVZBRE8nKTsgfQogICAgICAgICR0b2tlbj10cmltKChzdHJpbmcp
Z2V0ZW52KCdIT1NUSU5HRVJfTUFJTF9BUElfVE9LRU4nKSk7CiAgICAgICAgJHNvdXJjZT0nZW50
b3JubyBIT1NUSU5HRVJfTUFJTF9BUElfVE9LRU4nOwogICAgICAgIGlmKCR0b2tlbj09PScnICYm
ICgkY2VudHJhbFsndHJhbnNwb3J0J10/PycnKT09PSdob3N0aW5nZXJfYXBpJykgewogICAgICAg
ICAgICAkdG9rZW49dHJpbSgoc3RyaW5nKSgkY2VudHJhbFsnYXBpX3Rva2VuJ10/PycnKSk7JHNv
dXJjZT0nbWFpbF9jZW50cmFsLmxvY2FsLnBocCc7CiAgICAgICAgfQogICAgICAgIC8vIExhcyBj
b25maWd1cmFjaW9uZXMgYW50aWd1YXMgbm8gc2UgZWplY3V0YW4gc2kgeWEgaGF5IHVuYSBjcmVk
ZW5jaWFsIGNlbnRyYWwuCiAgICAgICAgaWYoJHRva2VuPT09JycpIHsKICAgICAgICAgICAgJHBh
dGg9aW1wZVJ1dGFDb25maWdQcml2YWRhKCdob3N0aW5nZXJfbWFpbF9jb25maWcucGhwJyk7CiAg
ICAgICAgICAgIGlmKCRwYXRoKSB7IHJlcXVpcmVfb25jZSAkcGF0aDsgfQogICAgICAgICAgICBp
ZihkZWZpbmVkKCdIT1NUSU5HRVJfTUFJTF9BUElfVE9LRU4nKSkgeyAkdG9rZW49dHJpbSgoc3Ry
aW5nKUhPU1RJTkdFUl9NQUlMX0FQSV9UT0tFTik7JHNvdXJjZT0naG9zdGluZ2VyX21haWxfY29u
ZmlnLnBocCAvIGNvbnN0YW50ZSBIT1NUSU5HRVJfTUFJTF9BUElfVE9LRU4nOyB9CiAgICAgICAg
fQogICAgICAgIGlmKCR0b2tlbj09PScnKSB7CiAgICAgICAgICAgICRsZWdhY3k9aW1wZUxlZXJD
b25maWdQcml2YWRhKCdtYXJrZXRpbmdfbWFpbC5sb2NhbC5waHAnKTsKICAgICAgICAgICAgJHRv
a2VuPXRyaW0oKHN0cmluZykoJGxlZ2FjeVsnaG9zdGluZ2VyX21haWxfYXBpX3Rva2VuJ10/Pycn
KSk7JHNvdXJjZT0nbWFya2V0aW5nX21haWwubG9jYWwucGhwJzsKICAgICAgICB9CiAgICAgICAg
aWYoJHRva2VuPT09JycgfHwgc3RybGVuKCR0b2tlbik+MTYzODQgfHwgcHJlZ19tYXRjaCgnL1tc
eDAwLVx4MjBceDdmXS8nLCR0b2tlbikpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hP
U1RJTkdFUl9UT0tFTl9BVVNFTlRFJyk7IH0KICAgICAgICAkYmFzZT1kZWZpbmVkKCdIT1NUSU5H
RVJfTUFJTF9BUElfQkFTRV9VUkwnKT9IT1NUSU5HRVJfTUFJTF9BUElfQkFTRV9VUkw6KGdldGVu
dignSE9TVElOR0VSX01BSUxfQVBJX0JBU0VfVVJMJyk/OnNlbGY6OkJBU0UpOwogICAgICAgIGlm
KHJ0cmltKChzdHJpbmcpJGJhc2UsJy8nKSE9PXNlbGY6OkJBU0UpIHsgdGhyb3cgbmV3IFJ1bnRp
bWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9DT05GSUdfSU5WQUxJREEnKTsgfQogICAgICAgIHJldHVy
biBbJ3Rva2VuJz0+JHRva2VuLCdwb2xpY3knPT4kY2VudHJhbCwnc291cmNlJz0+JHNvdXJjZV07
CiAgICB9CgogICAgcHVibGljIGZ1bmN0aW9uIGJ1em9uZXMoc3RyaW5nICR0b2tlbik6IGFycmF5
CiAgICB7CiAgICAgICAgJHRoaXMtPmRlc2N1YnJpbWllbnRvPVtdOwogICAgICAgIC8vIC9tZSBk
ZXZ1ZWx2ZSBsYSBjb2xlY2Npw7NuIGNvbXBsZXRhOiBlbCBjb250cmF0byBvZmljaWFsIG5vIGFk
bWl0ZSBwYWdpbmFjacOzbiBhcXXDrS4KICAgICAgICAkcj0kdGhpcy0+c29saWNpdGFyKCdHRVQn
LHNlbGY6OkJBU0UuJy9hcGkvdjEvbWUnLCR0b2tlbixudWxsKTsKICAgICAgICBpZihlbXB0eSgk
clsnb2snXSkgfHwgKGludCkoJHJbJ2NvZGlnb19odHRwJ10/PzApIT09MjAwKSB7IHRocm93IG5l
dyBSdW50aW1lRXhjZXB0aW9uKCdIT1NUSU5HRVJfQ09OU1VMVEFfJy4oaW50KSgkclsnY29kaWdv
X2h0dHAnXT8/MCkpOyB9CiAgICAgICAgJGJveGVzPSRyWydqc29uJ11bJ2RhdGEnXVsnbWFpbGJv
eGVzJ10/P251bGw7CiAgICAgICAgaWYoIWlzX2FycmF5KCRib3hlcykpIHsgdGhyb3cgbmV3IFJ1
bnRpbWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9SRVNQVUVTVEFfSU5WQUxJREEnKTsgfQogICAgICAg
ICRvdXQ9W107JHZhbGlkPVtdOyRpZHM9W107CiAgICAgICAgZm9yZWFjaCgkYm94ZXMgYXMgJGJv
eCkgewogICAgICAgICAgICBpZighaXNfYXJyYXkoJGJveCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVF
eGNlcHRpb24oJ0hPU1RJTkdFUl9SRVNQVUVTVEFfSU5WQUxJREEnKTsgfQogICAgICAgICAgICAk
ZW1haWw9c3RydG9sb3dlcih0cmltKChzdHJpbmcpKCRib3hbJ2FkZHJlc3MnXT8/JycpKSk7JGlk
PShzdHJpbmcpKCRib3hbJ3Jlc291cmNlSWQnXT8/JycpOwogICAgICAgICAgICBpZighZmlsdGVy
X3ZhcigkZW1haWwsRklMVEVSX1ZBTElEQVRFX0VNQUlMKSB8fCAhcHJlZ19tYXRjaCgnL15bYS16
QS1aMC05Xy1dezEsMTI4fSQvRCcsJGlkKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbign
SE9TVElOR0VSX1JFU1BVRVNUQV9JTlZBTElEQScpOyB9CiAgICAgICAgICAgIGlmKChpc3NldCgk
dmFsaWRbJGVtYWlsXSkgJiYgJHZhbGlkWyRlbWFpbF0hPT0kaWQpIHx8IChpc3NldCgkaWRzWyRp
ZF0pICYmICRpZHNbJGlkXSE9PSRlbWFpbCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24o
J0hPU1RJTkdFUl9SRVNQVUVTVEFfSU5WQUxJREEnKTsgfQogICAgICAgICAgICAkdmFsaWRbJGVt
YWlsXT0kaWQ7JGlkc1skaWRdPSRlbWFpbDsKICAgICAgICAgICAgaWYoJHRoaXMtPmluc3RpdHVj
aW9uYWwoJGVtYWlsKSkgeyAkb3V0WyRlbWFpbF09JGlkOyB9CiAgICAgICAgfQogICAgICAgICR0
aGlzLT5kZXNjdWJyaW1pZW50bz1bJ3ZlcnNpb24nPT5zZWxmOjpWRVJTSU9OLCdkb21pbmlvJz0+
c2VsZjo6RE9NSU5JTywndG90YWxfYXBpJz0+Y291bnQoJHZhbGlkKSwndG90YWxfaW5zdGl0dWNp
b25hbCc9PmNvdW50KCRvdXQpXTsKICAgICAgICBpZighJHZhbGlkKSB7IHRocm93IG5ldyBSdW50
aW1lRXhjZXB0aW9uKCdIT1NUSU5HRVJfU0lOX0JVWk9ORVMnKTsgfQogICAgICAgIGlmKCEkb3V0
KSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdIT1NUSU5HRVJfU0lOX0JVWk9ORVNfSU5T
VElUVUNJT05BTEVTJyk7IH0KICAgICAgICBrc29ydCgkb3V0LFNPUlRfU1RSSU5HKTsKICAgICAg
ICByZXR1cm4gJG91dDsKICAgIH0KCiAgICBwdWJsaWMgZnVuY3Rpb24gZGVzY3VicmltaWVudG8o
KTogYXJyYXkgeyByZXR1cm4gJHRoaXMtPmRlc2N1YnJpbWllbnRvOyB9CiAgICBwdWJsaWMgZnVu
Y3Rpb24gaW5zdGl0dWNpb25hbChzdHJpbmcgJGVtYWlsKTogYm9vbAogICAgewogICAgICAgIHJl
dHVybiBmaWx0ZXJfdmFyKCRlbWFpbCxGSUxURVJfVkFMSURBVEVfRU1BSUwpIT09ZmFsc2UgJiYg
c3RydG9sb3dlcihzdWJzdHIoc3RycmNocigkZW1haWwsJ0AnKSwxKSk9PT1zZWxmOjpET01JTklP
OwogICAgfQoKICAgIHB1YmxpYyBmdW5jdGlvbiBwZXJtaXRpZG9zKGludCAkdXNlcixhcnJheSAk
aWRlbnRpdHkpOiBhcnJheQogICAgewogICAgICAgICRhPSR0aGlzLT5jb25maWd1cmFjaW9uKCk7
JGJveGVzPSR0aGlzLT5idXpvbmVzKCRhWyd0b2tlbiddKTskcG9saWN5PSRhWydwb2xpY3knXTsk
bGlzdHM9W107CiAgICAgICAgZm9yZWFjaChbJ2FsbG93ZWRfc2VuZGVycycsJ3VzZXJfc2VuZGVy
cycsJ3JvbGVfc2VuZGVycyddIGFzICRmaWVsZCkgewogICAgICAgICAgICBpZihhcnJheV9rZXlf
ZXhpc3RzKCRmaWVsZCwkcG9saWN5KSAmJiAhaXNfYXJyYXkoJHBvbGljeVskZmllbGRdKSkgeyB0
aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0NPTkZJR19JTlZBTElEQScpOyB9
CiAgICAgICAgfQogICAgICAgIGlmKGFycmF5X2tleV9leGlzdHMoJ2FsbG93ZWRfc2VuZGVycycs
JHBvbGljeSkpIHsgJGxpc3RzW109JHBvbGljeVsnYWxsb3dlZF9zZW5kZXJzJ107IH0KICAgICAg
ICBpZihhcnJheV9rZXlfZXhpc3RzKCR1c2VyLCRwb2xpY3lbJ3VzZXJfc2VuZGVycyddPz9bXSkp
IHsgJGxpc3RzW109JHBvbGljeVsndXNlcl9zZW5kZXJzJ11bJHVzZXJdOyB9CiAgICAgICAgZm9y
ZWFjaChbKHN0cmluZykoJGlkZW50aXR5Wydyb2xfaWQnXT8/JycpLChzdHJpbmcpKCRpZGVudGl0
eVsncm9sJ10/PycnKV0gYXMgJHJvbGUpIHsKICAgICAgICAgICAgaWYoJHJvbGUhPT0nJyAmJiBh
cnJheV9rZXlfZXhpc3RzKCRyb2xlLCRwb2xpY3lbJ3JvbGVfc2VuZGVycyddPz9bXSkpIHsgJGxp
c3RzW109JHBvbGljeVsncm9sZV9zZW5kZXJzJ11bJHJvbGVdOyB9CiAgICAgICAgfQogICAgICAg
IGZvcmVhY2goJGxpc3RzIGFzICRsaXN0KSB7CiAgICAgICAgICAgIGlmKCFpc19hcnJheSgkbGlz
dCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9DT05GSUdfSU5WQUxJ
REEnKTsgfQogICAgICAgICAgICAkYm94ZXM9YXJyYXlfaW50ZXJzZWN0X2tleSgkYm94ZXMsYXJy
YXlfZmxpcChhcnJheV9tYXAoc3RhdGljIGZ1bmN0aW9uKCR2KXtyZXR1cm4gc3RydG9sb3dlcih0
cmltKChzdHJpbmcpJHYpKTt9LCRsaXN0KSkpOwogICAgICAgIH0KICAgICAgICAvLyBSZXN0cmlj
Y2lvbmVzIGFkbWluaXN0cmF0aXZhcyBleGlzdGVudGVzOiBudW5jYSBlbHVkaXIgZGVzYWN0aXZh
Y2nDs24vcHJvcGllZGFkLgogICAgICAgICRhY2NvdW50cz1pbXBlTGVlckNvbmZpZ1ByaXZhZGEo
J21haWxfYWNjb3VudHMubG9jYWwucGhwJylbJ2FjY291bnRzJ10/P1tdOwogICAgICAgIGlmKCFp
c19hcnJheSgkYWNjb3VudHMpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdIT1NUSU5H
RVJfQ09ORklHX0lOVkFMSURBJyk7IH0KICAgICAgICBmb3JlYWNoKCRhY2NvdW50cyBhcyAka2V5
PT4kZW50cnkpIHsKICAgICAgICAgICAgaWYoIWlzX2FycmF5KCRlbnRyeSkpIHsgY29udGludWU7
IH0KICAgICAgICAgICAgJGVtYWlsPXN0cnRvbG93ZXIodHJpbSgoc3RyaW5nKSgkZW50cnlbJ2Nv
cnJlbyddPz8oaXNfc3RyaW5nKCRrZXkpPyRrZXk6JycpKSkpOwogICAgICAgICAgICBpZihlbXB0
eSgkZW50cnlbJ2FjdGl2byddKSB8fCAhaW5fYXJyYXkoJHVzZXIsYXJyYXlfbWFwKCdpbnR2YWwn
LChhcnJheSkoJGVudHJ5Wyd1c3VhcmlvcyddPz9bXSkpLHRydWUpKSB7IHVuc2V0KCRib3hlc1sk
ZW1haWxdKTsgfQogICAgICAgIH0KICAgICAgICByZXR1cm4gWydhY2NvdW50Jz0+Wyd0cmFuc3Bv
cnQnPT4naG9zdGluZ2VyX2FwaScsJ2FwaV90b2tlbic9PiRhWyd0b2tlbiddLCdhcGlfYmFzZV91
cmwnPT5zZWxmOjpCQVNFXSwnYm94ZXMnPT4kYm94ZXMsCiAgICAgICAgICAgICdkaWFnbm9zdGlj
byc9PiR0aGlzLT5kZXNjdWJyaW1pZW50bytbJ3RvdGFsX3Blcm1pdGlkb3MnPT5jb3VudCgkYm94
ZXMpLCdyZXN0cmljY2lvbmVzX2xvY2FsZXMnPT5jb3VudCgkbGlzdHMpPjAgfHwgY291bnQoJGFj
Y291bnRzKT4wXV07CiAgICB9CgogICAgcHVibGljIGZ1bmN0aW9uIGV4dGVybm8oc3RyaW5nICRl
bWFpbCk6IGJvb2wgeyByZXR1cm4gcHJlZ19tYXRjaCgnL0AoZ21haWx8Z29vZ2xlbWFpbHxvdXRs
b29rfGhvdG1haWx8bGl2ZXxtc258eWFob28pXC5bYS16Ll0rJC9pRCcsJGVtYWlsKT09PTE7IH0K
CiAgICBwdWJsaWMgZnVuY3Rpb24gZmFsbG8oVGhyb3dhYmxlICRlKTogYXJyYXkKICAgIHsKICAg
ICAgICAkY29kZT1wcmVnX21hdGNoKCcvXkhPU1RJTkdFUl9bQS1aMC05X10rJC9EJywkZS0+Z2V0
TWVzc2FnZSgpKT8kZS0+Z2V0TWVzc2FnZSgpOidIT1NUSU5HRVJfQ09ORklHX0lOVkFMSURBJzsK
ICAgICAgICBlcnJvcl9sb2coJ01BSUxfQ09NTUVSQ0lBTCBjb2RlPScuJGNvZGUuJyBleGNlcHRp
b249Jy5nZXRfY2xhc3MoJGUpKTsKICAgICAgICAkbWVzc2FnZXM9WwogICAgICAgICAgICAnSE9T
VElOR0VSX1RPS0VOX0FVU0VOVEUnPT4nRmFsdGEgZWwgdG9rZW4gY2VudHJhbCBkZSBIb3N0aW5n
ZXIgTWFpbCBBUEkuIEVsIGFkbWluaXN0cmFkb3IgZGViZSBjb25maWd1cmFyIEhPU1RJTkdFUl9N
QUlMX0FQSV9UT0tFTiBlbiBob3N0aW5nZXJfbWFpbF9jb25maWcucGhwIG8gYXBpX3Rva2VuIGVu
IG1haWxfY2VudHJhbC5sb2NhbC5waHAuJywKICAgICAgICAgICAgJ0hPU1RJTkdFUl9ERVNBQ1RJ
VkFETyc9PidFbCBlbnbDrW8gY29tZXJjaWFsIGVzdMOhIGRlc2FjdGl2YWRvIGVuIGxhIGNvbmZp
Z3VyYWNpw7NuIGNlbnRyYWwuJywKICAgICAgICAgICAgJ0hPU1RJTkdFUl9DT05TVUxUQV80MDEn
PT4nSG9zdGluZ2VyIHJlY2hhesOzIGVsIHRva2VuIGRlIE1haWwgQVBJLiBSZXZpc2Egc3Ugdmln
ZW5jaWEuJywKICAgICAgICAgICAgJ0hPU1RJTkdFUl9DT05TVUxUQV80MDMnPT4nRWwgdG9rZW4g
bm8gdGllbmUgYXV0b3JpemFjacOzbiBwYXJhIGNvbnN1bHRhciBlc29zIGJ1em9uZXMgSG9zdGlu
Z2VyLicsCiAgICAgICAgICAgICdIT1NUSU5HRVJfQ09OU1VMVEFfNDI5Jz0+J0hvc3RpbmdlciBs
aW1pdMOzIHRlbXBvcmFsbWVudGUgbGFzIGNvbnN1bHRhcy4gRXNwZXJhIGFudGVzIGRlIHZvbHZl
ciBhIGludGVudGFyLicsCiAgICAgICAgICAgICdIT1NUSU5HRVJfU0lOX0JVWk9ORVMnPT4nRWwg
dG9rZW4gbm8gZGV2dWVsdmUgYnV6b25lcyBhdXRvcml6YWRvcy4gUmV2aXNhIHN1IG9yZGVuIHkg
ZWwgYWxjYW5jZSBkZSBidXpvbmVzIGVuIEhvc3Rpbmdlci4nLAogICAgICAgICAgICAnSE9TVElO
R0VSX1NJTl9CVVpPTkVTX0lOU1RJVFVDSU9OQUxFUyc9PidFbCB0b2tlbiBlcyB2w6FsaWRvLCBw
ZXJvIEhvc3RpbmdlciBubyBkZXZ1ZWx2ZSBidXpvbmVzIGRlIEByZWRlZHVjYXRpdmFtZXhpY28u
b3JnLiBSZXZpc2Egc3Ugb3JkZW4geSBsb3MgYnV6b25lcyBhdXRvcml6YWRvcy4nLAogICAgICAg
ICAgICAnSE9TVElOR0VSX1NFU0lPTl9JTlZBTElEQSc9PidMYSBzZXNpw7NuIG5vIGVzdMOhIGF1
dG9yaXphZGEgcGFyYSBlbnZpYXIuJywKICAgICAgICAgICAgJ0hPU1RJTkdFUl9SRVNQVUVTVEFf
SU5WQUxJREEnPT4nSG9zdGluZ2VyIGRldm9sdmnDsyB1bmEgcmVzcHVlc3RhIGRlIGJ1em9uZXMg
bm8gdsOhbGlkYS4nCiAgICAgICAgXTsKICAgICAgICByZXR1cm4gWydvayc9PmZhbHNlLCd2ZXJz
aW9uJz0+c2VsZjo6VkVSU0lPTiwnY29kaWdvX2h0dHAnPT4kY29kZT09PSdIT1NUSU5HRVJfU0VT
SU9OX0lOVkFMSURBJz80MDM6NTAzLCdjb2RpZ29fdGVjbmljbyc9PiRjb2RlLAogICAgICAgICAg
ICAnZXN0YWRvX2VudHJlZ2EnPT4nUkVDSEFaQURPJywnZW50cmVnYV9jb25maXJtYWRhJz0+ZmFs
c2UsJ21lbnNhamUnPT4kbWVzc2FnZXNbJGNvZGVdPz8nTm8gZnVlIHBvc2libGUgdmFsaWRhciBI
b3N0aW5nZXIgTWFpbCBBUEkuIFJldmlzYSBlbCBkaWFnbsOzc3RpY28gY29tZXJjaWFsIGRlbCBz
ZXJ2aWRvci4nXTsKICAgIH0KCiAgICBwdWJsaWMgZnVuY3Rpb24gc29saWNpdGFyKHN0cmluZyAk
bWV0aG9kLHN0cmluZyAkdXJsLHN0cmluZyAkdG9rZW4sP2FycmF5ICRwYXlsb2FkKTogYXJyYXkK
ICAgIHsKICAgICAgICBpZigkdGhpcy0+cmVxdWVzdCkgeyByZXR1cm4gKCR0aGlzLT5yZXF1ZXN0
KSgkbWV0aG9kLCR1cmwsJHRva2VuLCRwYXlsb2FkKTsgfQogICAgICAgIGlmKCFmdW5jdGlvbl9l
eGlzdHMoJ2N1cmxfaW5pdCcpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdIT1NUSU5H
RVJfQ1VSTF9BVVNFTlRFJyk7IH0KICAgICAgICBpZigkdXJsIT09c2VsZjo6QkFTRS4nL2FwaS92
MS9tZScgJiYgIXByZWdfbWF0Y2goJ35eaHR0cHM6Ly9hcGlcLm1haWxcLmhvc3RpbmdlclwuY29t
L2FwaS92MS9tYWlsYm94ZXMvW2EtekEtWjAtOV8tXXsxLDEyOH0vc2VuZCR+RCcsJHVybCkpIHsg
dGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9DT05GSUdfSU5WQUxJREEnKTsg
fQogICAgICAgICRjPWN1cmxfaW5pdCgkdXJsKTskYm9keT0nJzsKICAgICAgICBjdXJsX3NldG9w
dF9hcnJheSgkYyxbQ1VSTE9QVF9DVVNUT01SRVFVRVNUPT4kbWV0aG9kLENVUkxPUFRfSFRUUEhF
QURFUj0+WydBY2NlcHQ6IGFwcGxpY2F0aW9uL2pzb24nLCdDb250ZW50LVR5cGU6IGFwcGxpY2F0
aW9uL2pzb24nLCdBdXRob3JpemF0aW9uOiBCZWFyZXIgJy4kdG9rZW5dLAogICAgICAgICAgICBD
VVJMT1BUX0ZPTExPV0xPQ0FUSU9OPT5mYWxzZSxDVVJMT1BUX0NPTk5FQ1RUSU1FT1VUPT4xMCxD
VVJMT1BUX1RJTUVPVVQ9PjMwLENVUkxPUFRfU1NMX1ZFUklGWVBFRVI9PnRydWUsQ1VSTE9QVF9T
U0xfVkVSSUZZSE9TVD0+MiwKICAgICAgICAgICAgQ1VSTE9QVF9XUklURUZVTkNUSU9OPT5zdGF0
aWMgZnVuY3Rpb24oJGMsJHBhcnQpdXNlKCYkYm9keSl7aWYoc3RybGVuKCRib2R5KStzdHJsZW4o
JHBhcnQpPjEwNDg1NzYpcmV0dXJuIDA7JGJvZHkuPSRwYXJ0O3JldHVybiBzdHJsZW4oJHBhcnQp
O31dKTsKICAgICAgICBpZigkcGF5bG9hZCE9PW51bGwpIHsgY3VybF9zZXRvcHQoJGMsQ1VSTE9Q
VF9QT1NURklFTERTLGpzb25fZW5jb2RlKCRwYXlsb2FkLEpTT05fVEhST1dfT05fRVJST1IpKTsg
fQogICAgICAgIHRyeSB7CiAgICAgICAgICAgICRvaz1jdXJsX2V4ZWMoJGMpOyRzdGF0dXM9KGlu
dCljdXJsX2dldGluZm8oJGMsQ1VSTElORk9fSFRUUF9DT0RFKTsKICAgICAgICAgICAgZXJyb3Jf
bG9nKCdNQUlMX0NPTU1FUkNJQUwgbWV0aG9kPScuJG1ldGhvZC4nIGh0dHA9Jy4kc3RhdHVzLicg
Y29tcGxldGVkPScuKCRvayE9PWZhbHNlPycxJzonMCcpKTsKICAgICAgICAgICAgcmV0dXJuIFsn
b2snPT4kb2shPT1mYWxzZSYmJHN0YXR1cz49MjAwJiYkc3RhdHVzPDMwMCwnY29kaWdvX2h0dHAn
PT4kc3RhdHVzLCdpbmNpZXJ0byc9PiRtZXRob2Q9PT0nUE9TVCcmJiRvaz09PWZhbHNlLCdqc29u
Jz0+anNvbl9kZWNvZGUoJGJvZHksdHJ1ZSk/OltdXTsKICAgICAgICB9IGZpbmFsbHkgeyBjdXJs
X2Nsb3NlKCRjKTsgfQogICAgfQp9Cg==
CENTRAL_HELPER_0
 [[ "$(digest "$TMP/stage/app/services/CorreoHostingerApiService.php")" == '0b2915865f7fc3888d179a361415eb1de6a29debfe8046a77227c6b5973f3c45' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoHostingerApiService.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoHostingerApiService.php")"
  case "$existing" in 0b2915865f7fc3888d179a361415eb1de6a29debfe8046a77227c6b5973f3c45|98947bf66a07bf92d5b88501892169dfc7bda99501c2470f7f206616dcfd91cd) ;; *) fail 'Dependencia personalizada: app/services/CorreoHostingerApiService.php. No se sobrescribe.';; esac
  if [[ "$existing" == '0b2915865f7fc3888d179a361415eb1de6a29debfe8046a77227c6b5973f3c45' ]];then cp -p -- "$LIVE/app/services/CorreoHostingerApiService.php" "$TMP/stage/app/services/CorreoHostingerApiService.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoHostingerApiService.php" "$TMP/stage/app/services/CorreoHostingerApiService.php";chmod --reference="$LIVE/app/services/CorreoHostingerApiService.php" "$TMP/stage/app/services/CorreoHostingerApiService.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoHostingerApiService.php";fi
 base64 --decode > "$TMP/stage/app/services/CorreoCuentaConectadaService.php" <<'CENTRAL_HELPER_1'
PD9waHAKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0hvc3RpbmdlckFwaVNlcnZpY2Uu
cGhwJzsKCi8qKiBJbnRlcmZheiBjb25zZXJ2YWRhIHBhcmEgbG9zIG3Ds2R1bG9zOyBzw7NsbyB1
dGlsaXphIEhvc3RpbmdlciBjZW50cmFsLiAqLwpmaW5hbCBjbGFzcyBDb3JyZW9DdWVudGFDb25l
Y3RhZGFTZXJ2aWNlCnsKICAgIHByaXZhdGUgJGlkZW50aXR5TG9hZGVyLCAkYXBpOwogICAgcHVi
bGljIGZ1bmN0aW9uIF9fY29uc3RydWN0KD9jYWxsYWJsZSAkaWRlbnRpdHlMb2FkZXI9bnVsbCkg
eyAkdGhpcy0+aWRlbnRpdHlMb2FkZXI9JGlkZW50aXR5TG9hZGVyOyR0aGlzLT5hcGk9bmV3IENv
cnJlb0hvc3RpbmdlckFwaVNlcnZpY2UoKTsgfQogICAgcHVibGljIGZ1bmN0aW9uIGNvbmZpZ3Vy
YXJDb25zdWx0YUFwaShjYWxsYWJsZSAkcmVxdWVzdCk6IHZvaWQgeyAkdGhpcy0+YXBpPW5ldyBD
b3JyZW9Ib3N0aW5nZXJBcGlTZXJ2aWNlKCRyZXF1ZXN0KTsgfQogICAgcHVibGljIGZ1bmN0aW9u
IHJlbWl0ZW50ZXMoaW50ICR1c2VyKTogYXJyYXkKICAgIHsKICAgICAgICB0cnkgeyAkcj0kdGhp
cy0+YXBpLT5wZXJtaXRpZG9zKCR1c2VyLCR0aGlzLT5pZGVudGlkYWQoJHVzZXIpKTtyZXR1cm4g
Wydvayc9PnRydWUsJ3JlbWl0ZW50ZXMnPT5hcnJheV9rZXlzKCRyWydib3hlcyddKSwKICAgICAg
ICAgICAgJ2RpYWdub3N0aWNvJz0+JHJbJ2RpYWdub3N0aWNvJ10sJ2FjdHVhbGl6YWRvX3V0Yyc9
PmdtZGF0ZSgnYycpXTsgfQogICAgICAgIGNhdGNoKFRocm93YWJsZSAkZSkgeyByZXR1cm4gJHRo
aXMtPmFwaS0+ZmFsbG8oJGUpOyB9CiAgICB9CiAgICBwdWJsaWMgZnVuY3Rpb24gcmVzb2x2ZXIo
c3RyaW5nICRmcm9tLGludCAkdXNlcixib29sICRtYXJrZXRpbmc9ZmFsc2UpOiBhcnJheQogICAg
ewogICAgICAgIHRyeSB7CiAgICAgICAgICAgICRpZGVudGl0eT0kdGhpcy0+aWRlbnRpZGFkKCR1
c2VyKTskZnJvbT1zdHJ0b2xvd2VyKHRyaW0oJGZyb20pKTsKICAgICAgICAgICAgaWYoISR0aGlz
LT5hcGktPmluc3RpdHVjaW9uYWwoJGZyb20pKSB7IHJldHVybiAkdGhpcy0+cmVjaGF6bygpOyB9
CiAgICAgICAgICAgICRyPSR0aGlzLT5hcGktPnBlcm1pdGlkb3MoJHVzZXIsJGlkZW50aXR5KTsK
ICAgICAgICAgICAgaWYoIWlzc2V0KCRyWydib3hlcyddWyRmcm9tXSkpIHsgcmV0dXJuICR0aGlz
LT5yZWNoYXpvKCk7IH0KICAgICAgICAgICAgcmV0dXJuIFsnb2snPT50cnVlLCdhY2NvdW50Jz0+
JHJbJ2FjY291bnQnXStbJ2NvcnJlbyc9PiRmcm9tXV07CiAgICAgICAgfSBjYXRjaChUaHJvd2Fi
bGUgJGUpIHsgcmV0dXJuICR0aGlzLT5hcGktPmZhbGxvKCRlKTsgfQogICAgfQogICAgcHJpdmF0
ZSBmdW5jdGlvbiBpZGVudGlkYWQoaW50ICR1c2VyKTogYXJyYXkKICAgIHsKICAgICAgICBpZigk
dXNlcjw9MCB8fCAoaXNzZXQoJF9TRVNTSU9OWyd1c3VhcmlvX2lkJ10pJiYoaW50KSRfU0VTU0lP
TlsndXN1YXJpb19pZCddIT09JHVzZXIpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdI
T1NUSU5HRVJfU0VTSU9OX0lOVkFMSURBJyk7IH0KICAgICAgICBpZigkdGhpcy0+aWRlbnRpdHlM
b2FkZXIpIHsgJGlkZW50aXR5PSgkdGhpcy0+aWRlbnRpdHlMb2FkZXIpKCR1c2VyKTsgfQogICAg
ICAgIGVsc2UgeyByZXF1aXJlX29uY2UgX19ESVJfXy4nLy4uL21vZGVscy9Vc3VhcmlvTW9kZWwu
cGhwJzskaWRlbnRpdHk9KG5ldyBVc3VhcmlvTW9kZWwoKSktPmJ1c2NhclBvcklkKCR1c2VyKTsg
fQogICAgICAgIGlmKCEkaWRlbnRpdHkgfHwgKGludCkoJGlkZW50aXR5Wydlc3RhZG8nXT8/MCkh
PT0xIHx8IChpbnQpKCRpZGVudGl0eVsncm9sX2VzdGFkbyddPz8xKSE9PTEpIHsgdGhyb3cgbmV3
IFJ1bnRpbWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9TRVNJT05fSU5WQUxJREEnKTsgfQogICAgICAg
IHJldHVybiAkaWRlbnRpdHk7CiAgICB9CiAgICBwcml2YXRlIGZ1bmN0aW9uIHJlY2hhem8oKTog
YXJyYXkKICAgIHsKICAgICAgICByZXR1cm4gWydvayc9PmZhbHNlLCdjb2RpZ29faHR0cCc9PjQy
MiwnZXN0YWRvX2VudHJlZ2EnPT4nUkVDSEFaQURPJywnZW50cmVnYV9jb25maXJtYWRhJz0+ZmFs
c2UsCiAgICAgICAgICAgICdtZW5zYWplJz0+J0VsIHJlbWl0ZW50ZSBkZWJlIHNlciB1biBidXrD
s24gZGUgQHJlZGVkdWNhdGl2YW1leGljby5vcmcgZGV2dWVsdG8gcG9yIEhvc3RpbmdlciB5IHBl
cm1pdGlkbyBwb3IgZWwgQ1JNLiBDb21wYXJ0aXIgZG9taW5pbyBubyBhdXRvcml6YSB1bmEgZGly
ZWNjacOzbiBhdXNlbnRlIGRlIGxhIGxpc3RhLiddOwogICAgfQp9Cg==
CENTRAL_HELPER_1
 [[ "$(digest "$TMP/stage/app/services/CorreoCuentaConectadaService.php")" == '5c5ad9566f973f854d9700aec952960dc1af28fd3b3d39b192222264707e889f' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["app/services/CorreoCuentaConectadaService.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/services/CorreoCuentaConectadaService.php")"
  case "$existing" in 292ac1c52185fff0fecf35e0a1ae3e54946edf04b7a32f854afdddc06a6ecdf3|384047b54bd375ba66fc025b38a1e03c4b7346f2e559246f39d5c52fd7321ba9|4572c61bd650bb0d98251fe4c8f1e3dd257c6846172e7c536a026c47386f886d|5c5ad9566f973f854d9700aec952960dc1af28fd3b3d39b192222264707e889f|8d04ffe6e97c0f061fccdf75fff6d416e79a04a4411bf3349abfafc657e692b2|945bf7557c345e2182011d631d176679c35d8e2baa9dbb7a4cbefed5cdc1b68e) ;; *) fail 'Dependencia personalizada: app/services/CorreoCuentaConectadaService.php. No se sobrescribe.';; esac
  if [[ "$existing" == '5c5ad9566f973f854d9700aec952960dc1af28fd3b3d39b192222264707e889f' ]];then cp -p -- "$LIVE/app/services/CorreoCuentaConectadaService.php" "$TMP/stage/app/services/CorreoCuentaConectadaService.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/app/services/CorreoCuentaConectadaService.php" "$TMP/stage/app/services/CorreoCuentaConectadaService.php";chmod --reference="$LIVE/app/services/CorreoCuentaConectadaService.php" "$TMP/stage/app/services/CorreoCuentaConectadaService.php";fi
 else chmod 0644 "$TMP/stage/app/services/CorreoCuentaConectadaService.php";fi
 base64 --decode > "$TMP/stage/tools/diagnosticar_hostinger_comercial.php" <<'CENTRAL_HELPER_2'
PD9waHAKLy8gRGlhZ27Ds3N0aWNvIHkgY29uZmlndXJhY2nDs24gZXhjbHVzaXZhbWVudGUgY29t
ZXJjaWFsZXMuIE5pbmfDum4gZW52w61vIG5pIGFjY2VzbyBhIFNNVFAuCmlmKFBIUF9TQVBJIT09
J2NsaScpIHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwNCk7ZXhpdDsgfQpyZXF1aXJlX29uY2UgZGly
bmFtZShfX0RJUl9fKS4nL2FwcC9zZXJ2aWNlcy9Db3JyZW9Ib3N0aW5nZXJBcGlTZXJ2aWNlLnBo
cCc7CgpmdW5jdGlvbiBndWFyZGFyVG9rZW5Ib3N0aW5nZXJDb21lcmNpYWwoc3RyaW5nICR0b2tl
biw/Y2FsbGFibGUgJHByb2JlPW51bGwpOiBhcnJheQp7CiAgICBpZigkdG9rZW49PT0nJyB8fCBz
dHJsZW4oJHRva2VuKT4xNjM4NCB8fCBwcmVnX21hdGNoKCcvW1x4MDAtXHgyMFx4N2ZdLycsJHRv
a2VuKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX1RPS0VOX0FVU0VO
VEUnKTsgfQogICAgJGFwaT1uZXcgQ29ycmVvSG9zdGluZ2VyQXBpU2VydmljZSgpOwogICAgJGJv
eGVzPSRwcm9iZSA/ICRwcm9iZSgkdG9rZW4pIDogJGFwaS0+YnV6b25lcygkdG9rZW4pOwogICAg
aWYoISRib3hlcykgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX1NJTl9C
VVpPTkVTJyk7IH0KICAgICRyb290PXJlYWxwYXRoKGRpcm5hbWUoX19ESVJfXykpOwogICAgJGRp
cj1ydHJpbSgoc3RyaW5nKShnZXRlbnYoJ0lNUEVfUFJJVkFURV9DT05GSUdfRElSJyk/OmRpcm5h
bWUoJHJvb3QpLicvaW1wZS1wcml2YXRlJyksJy9cXCcpOwogICAgJG5vcm1hbD1zdHJfcmVwbGFj
ZSgnXFwnLCcvJywkZGlyKTsKICAgIGlmKCghc3RyX3N0YXJ0c193aXRoKCRub3JtYWwsJy8nKSAm
JiAhcHJlZ19tYXRjaCgnL15bYS16QS1aXTpcLy9EJywkbm9ybWFsKSkgfHwgcHJlZ19tYXRjaCgn
fihefC8pXC57MSwyfSgvfCQpficsJG5vcm1hbCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRp
b24oJ0hPU1RJTkdFUl9SVVRBX1BSSVZBREEnKTsgfQogICAgZm9yKCRwPSRkaXI7ZGlybmFtZSgk
cCkhPT0kcDskcD1kaXJuYW1lKCRwKSkgeyBpZihpc19saW5rKCRwKSkgeyB0aHJvdyBuZXcgUnVu
dGltZUV4Y2VwdGlvbignSE9TVElOR0VSX1JVVEFfUFJJVkFEQScpOyB9IH0KICAgICRhbmNlc3Rv
cj0kZGlyO3doaWxlKCFmaWxlX2V4aXN0cygkYW5jZXN0b3IpICYmIGRpcm5hbWUoJGFuY2VzdG9y
KSE9PSRhbmNlc3RvcikgeyAkYW5jZXN0b3I9ZGlybmFtZSgkYW5jZXN0b3IpOyB9CiAgICAkYW5j
ZXN0b3I9cmVhbHBhdGgoJGFuY2VzdG9yKTskZG9jdW1lbnRSb290PXRyaW0oKHN0cmluZykoJF9T
RVJWRVJbJ0RPQ1VNRU5UX1JPT1QnXT8/JycpKTsKICAgIGZvcmVhY2goWyRyb290LCRkb2N1bWVu
dFJvb3QhPT0nJz9yZWFscGF0aCgkZG9jdW1lbnRSb290KTpmYWxzZV0gYXMgJHB1YmxpYykgewog
ICAgICAgIGlmKCRhbmNlc3RvciAmJiAkcHVibGljICYmIHN0cl9zdGFydHNfd2l0aChzdHJ0b2xv
d2VyKCRhbmNlc3Rvci5ESVJFQ1RPUllfU0VQQVJBVE9SKSxzdHJ0b2xvd2VyKCRwdWJsaWMuRElS
RUNUT1JZX1NFUEFSQVRPUikpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdIT1NUSU5H
RVJfUlVUQV9QUklWQURBJyk7IH0KICAgIH0KICAgIGlmKCFpc19kaXIoJGRpcikgJiYgIW1rZGly
KCRkaXIsMDcwMCx0cnVlKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VS
X0VTQ1JJVFVSQV9QUklWQURBJyk7IH0KICAgICRkaXI9cmVhbHBhdGgoJGRpcik7JHBhdGg9JGRp
ci4nL21haWxfY2VudHJhbC5sb2NhbC5waHAnOyRsb2NrUGF0aD0kZGlyLicvLmhvc3Rpbmdlci1j
b21lcmNpYWwubG9jayc7CiAgICBpZihpc19saW5rKCRwYXRoKSB8fCBpc19saW5rKCRsb2NrUGF0
aCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9SVVRBX1BSSVZBREEn
KTsgfQogICAgJGxvY2s9Zm9wZW4oJGxvY2tQYXRoLCdjK2InKTtpZighJGxvY2spIHsgdGhyb3cg
bmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9FU0NSSVRVUkFfUFJJVkFEQScpOyB9Y2ht
b2QoJGxvY2tQYXRoLDA2MDApOwogICAgdHJ5IHsKICAgICAgICBpZighZmxvY2soJGxvY2ssTE9D
S19FWHxMT0NLX05CKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0NP
TkZJR19PQ1VQQURBJyk7IH0KICAgICAgICAkZGF0YT1pc19maWxlKCRwYXRoKT9yZXF1aXJlICRw
YXRoOmltcGVMZWVyQ29uZmlnUHJpdmFkYSgnbWFpbF9jZW50cmFsLmxvY2FsLnBocCcpOwogICAg
ICAgIGlmKCFpc19hcnJheSgkZGF0YSkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hP
U1RJTkdFUl9DT05GSUdfSU5WQUxJREEnKTsgfQogICAgICAgIGlmKGlzX2ZpbGUoJHBhdGgpICYm
ICgkZGF0YVsndHJhbnNwb3J0J10/PycnKT09PSdob3N0aW5nZXJfYXBpJyAmJiAoJGRhdGFbJ2Fj
dGl2byddPz90cnVlKT09PXRydWUKICAgICAgICAgICAgJiYgaGFzaF9lcXVhbHMoKHN0cmluZyko
JGRhdGFbJ2FwaV90b2tlbiddPz8nJyksJHRva2VuKSkgewogICAgICAgICAgICByZXR1cm4gWydy
dXRhJz0+JHBhdGgsJ3Jlc3BhbGRvJz0+bnVsbCwnYnV6b25lcyc9PmNvdW50KCRib3hlcyksJ3Np
bl9jYW1iaW9zJz0+dHJ1ZV07CiAgICAgICAgfQogICAgICAgICRiYWNrdXA9bnVsbDsKICAgICAg
ICBpZihpc19maWxlKCRwYXRoKSkgewogICAgICAgICAgICAkYmFja3VwPSRwYXRoLicucmVzcGFs
ZG8tJy5nbWRhdGUoJ1ltZC1IaXMnKS4nLScuYmluMmhleChyYW5kb21fYnl0ZXMoNCkpOwogICAg
ICAgICAgICBpZighY29weSgkcGF0aCwkYmFja3VwKSB8fCAhY2htb2QoJGJhY2t1cCwwNjAwKSB8
fCBoYXNoX2ZpbGUoJ3NoYTI1NicsJHBhdGgpIT09aGFzaF9maWxlKCdzaGEyNTYnLCRiYWNrdXAp
KSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdIT1NUSU5HRVJfUkVTUEFMRE8nKTsgfQog
ICAgICAgIH0KICAgICAgICAkZGF0YT1hcnJheV9yZXBsYWNlKCRkYXRhLFsndHJhbnNwb3J0Jz0+
J2hvc3Rpbmdlcl9hcGknLCdhY3Rpdm8nPT50cnVlLCdhcGlfdG9rZW4nPT4kdG9rZW5dKTsKICAg
ICAgICAkdGV4dD0iPD9waHBcbi8vIENvbmZpZ3VyYWNpw7NuIHByaXZhZGEgY29tZXJjaWFsLiBO
byBwdWJsaWNhci5cbnJldHVybiAiLnZhcl9leHBvcnQoJGRhdGEsdHJ1ZSkuIjtcbiI7CiAgICAg
ICAgJHRtcD0kcGF0aC4nLnRtcC0nLmJpbjJoZXgocmFuZG9tX2J5dGVzKDgpKTsKICAgICAgICB0
cnkgewogICAgICAgICAgICAkaD1mb3BlbigkdG1wLCd4YicpO2lmKCEkaCkgeyB0aHJvdyBuZXcg
UnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0VTQ1JJVFVSQV9QUklWQURBJyk7IH0KICAgICAg
ICAgICAgdHJ5IHsgaWYoIWNobW9kKCR0bXAsMDYwMCkgfHwgZndyaXRlKCRoLCR0ZXh0KSE9PXN0
cmxlbigkdGV4dCkgfHwgIWZmbHVzaCgkaCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24o
J0hPU1RJTkdFUl9FU0NSSVRVUkFfUFJJVkFEQScpOyB9IH0KICAgICAgICAgICAgZmluYWxseSB7
IGZjbG9zZSgkaCk7IH0KICAgICAgICAgICAgaWYoIXJlbmFtZSgkdG1wLCRwYXRoKSkgeyB0aHJv
dyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0VTQ1JJVFVSQV9QUklWQURBJyk7IH0K
ICAgICAgICB9IGZpbmFsbHkgeyBpZihpc19maWxlKCR0bXApKSB7IHVubGluaygkdG1wKTsgfSB9
CiAgICAgICAgcmV0dXJuIFsncnV0YSc9PiRwYXRoLCdyZXNwYWxkbyc9PiRiYWNrdXAsJ2J1em9u
ZXMnPT5jb3VudCgkYm94ZXMpXTsKICAgIH0gZmluYWxseSB7IGZsb2NrKCRsb2NrLExPQ0tfVU4p
O2ZjbG9zZSgkbG9jayk7IH0KfQoKZnVuY3Rpb24gZGlhZ25vc3RpY2FySG9zdGluZ2VyQ29tZXJj
aWFsKENvcnJlb0hvc3RpbmdlckFwaVNlcnZpY2UgJGFwaSwgc3RyaW5nICRyZW1pdGVudGU9Jycp
OiBhcnJheQp7CiAgICAkY29uZmlnPSRhcGktPmNvbmZpZ3VyYWNpb24oKTskYm94ZXM9JGFwaS0+
YnV6b25lcygkY29uZmlnWyd0b2tlbiddKTskZD0kYXBpLT5kZXNjdWJyaW1pZW50bygpOwogICAg
JGxpbmVzPVsnRnVlbnRlIGVmZWN0aXZhIGRlbCB0b2tlbjogJy4kY29uZmlnWydzb3VyY2UnXSwK
ICAgICAgICAnQVBJIGF1dG9yaXphZG9zOiAnLiRkWyd0b3RhbF9hcGknXS4nOyBpbnN0aXR1Y2lv
bmFsZXMgQCcuQ29ycmVvSG9zdGluZ2VyQXBpU2VydmljZTo6RE9NSU5JTy4nOiAnLiRkWyd0b3Rh
bF9pbnN0aXR1Y2lvbmFsJ10sCiAgICAgICAgJ0NvbnN1bHRhIGNvbXBsZXRhIGRlIEdFVCAvYXBp
L3YxL21lOyBzaW4gY2FjaMOpIGxvY2FsIG5pIHBhZ2luYWNpw7NuIGVuIGVzdGUgZW5kcG9pbnQu
J107CiAgICBmb3JlYWNoKFsnYWxsb3dlZF9zZW5kZXJzJywndXNlcl9zZW5kZXJzJywncm9sZV9z
ZW5kZXJzJ10gYXMgJGZpZWxkKSB7CiAgICAgICAgJGxpbmVzW109J1Jlc3RyaWNjacOzbiAnLiRm
aWVsZC4nOiAnLihhcnJheV9rZXlfZXhpc3RzKCRmaWVsZCwkY29uZmlnWydwb2xpY3knXSk/J2Nv
bmZpZ3VyYWRhOyByZXZpc2FyIGludGVyc2VjY2nDs24gY29uIGVsIHVzdWFyaW8vcm9sJzonYXVz
ZW50ZScpOwogICAgfQogICAgJGFjY291bnRzPWltcGVMZWVyQ29uZmlnUHJpdmFkYSgnbWFpbF9h
Y2NvdW50cy5sb2NhbC5waHAnKVsnYWNjb3VudHMnXT8/W107CiAgICBpZighaXNfYXJyYXkoJGFj
Y291bnRzKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0NPTkZJR19J
TlZBTElEQScpOyB9CiAgICAkbGluZXNbXT0nUmVzdHJpY2Npb25lcyBwcmV2aWFzIGVuIG1haWxf
YWNjb3VudHMubG9jYWwucGhwOiAnLmNvdW50KCRhY2NvdW50cykuJyBlbnRyYWRhcyAobm8gc2Ug
Y3JlYW4gY3VlbnRhcyBudWV2YXMpLic7CiAgICBmb3JlYWNoKGFycmF5X2tleXMoJGJveGVzKSBh
cyAkZW1haWwpIHsgJGxpbmVzW109J0J1esOzbiBBUEk6ICcuJGVtYWlsOyB9CiAgICAkbGluZXNb
XT0nU2kgZmFsdGEgdW4gYnV6w7NuOiBhdXRvcml6YXJsbyBlbiBlbCB0b2tlbiBNYWlsIEFQSSBk
ZSBsYSBtaXNtYSBvcmRlbiBkZSBIb3N0aW5nZXI7IGNvbXBhcnRpciBkb21pbmlvIG5vIGxvIGF1
dG9yaXphLic7CiAgICBpZigkcmVtaXRlbnRlIT09JycpIHsKICAgICAgICAkcmVtaXRlbnRlPXN0
cnRvbG93ZXIodHJpbSgkcmVtaXRlbnRlKSk7CiAgICAgICAgaWYoISRhcGktPmluc3RpdHVjaW9u
YWwoJHJlbWl0ZW50ZSkpIHsgJGxpbmVzW109J1JlbWl0ZW50ZSBzb2xpY2l0YWRvOiBkb21pbmlv
L2Zvcm1hdG8gbm8gaW5zdGl0dWNpb25hbDsgcmVjaGF6YWRvLic7IH0KICAgICAgICBlbHNlIHsg
JGxpbmVzW109J1JlbWl0ZW50ZSBzb2xpY2l0YWRvOiAnLihpc3NldCgkYm94ZXNbJHJlbWl0ZW50
ZV0pPydkZXZ1ZWx0byBwb3IgSG9zdGluZ2VyOyBzZSBhcGxpY2FuIGFkZW3DoXMgcmVzdHJpY2Np
b25lcyBkZWwgdXN1YXJpby9yb2wuJzonTk8gZGV2dWVsdG8gcG9yIEhvc3Rpbmdlcjsgbm8gc2Ug
cGVybWl0ZSBlbnZpYXIuJyk7IH0KICAgIH0KICAgIHJldHVybiAkbGluZXM7Cn0KCmlmKHJlYWxw
YXRoKChzdHJpbmcpKCRfU0VSVkVSWydTQ1JJUFRfRklMRU5BTUUnXT8/JycpKT09PV9fRklMRV9f
KSB7CiAgICBpbmlfc2V0KCdkaXNwbGF5X2Vycm9ycycsJzAnKTskYXBpPW5ldyBDb3JyZW9Ib3N0
aW5nZXJBcGlTZXJ2aWNlKCk7CiAgICB0cnkgewogICAgICAgICRtb2RlPSRhcmd2WzFdPz8nLS1j
aGVjayc7CiAgICAgICAgaWYoJG1vZGU9PT0nLS1ndWFyZGFyLXRva2VuJykgewogICAgICAgICAg
ICAkcj1ndWFyZGFyVG9rZW5Ib3N0aW5nZXJDb21lcmNpYWwodHJpbSgoc3RyaW5nKWdldGVudign
SU1QRV9IT1NUSU5HRVJfVE9LRU5fU0VUVVAnKSkpOwogICAgICAgICAgICBlY2hvICdPSzogdG9r
ZW4gdmFsaWRhZG8geSBndWFyZGFkbyBmdWVyYSBkZWwgc2l0aW8gcMO6YmxpY287IGJ1em9uZXM9
Jy4kclsnYnV6b25lcyddLlBIUF9FT0w7CiAgICAgICAgICAgIGlmKCRyWydyZXNwYWxkbyddKSB7
IGVjaG8gJ1Jlc3RhdXJhciBjb25maWd1cmFjacOzbiBjb21lcmNpYWw6IGNwIC1wIC0tICcuZXNj
YXBlc2hlbGxhcmcoJHJbJ3Jlc3BhbGRvJ10pLicgJy5lc2NhcGVzaGVsbGFyZygkclsncnV0YSdd
KS5QSFBfRU9MOyB9CiAgICAgICAgfSBlbHNlaWYoJG1vZGU9PT0nLS1jaGVjaycpIHsKICAgICAg
ICAgICAgZWNobyAnVmVyc2nDs24gY29tZXJjaWFsOiAnLkNvcnJlb0hvc3RpbmdlckFwaVNlcnZp
Y2U6OlZFUlNJT04uJzsgUEhQICcuUEhQX1ZFUlNJT04uJyBTQVBJICcuUEhQX1NBUEkuUEhQX0VP
TDsKICAgICAgICAgICAgZm9yZWFjaChbJ2hvc3Rpbmdlcl9tYWlsX2NvbmZpZy5waHAnLCdtYWls
X2NlbnRyYWwubG9jYWwucGhwJywnbWFya2V0aW5nX21haWwubG9jYWwucGhwJ10gYXMgJGZpbGUp
IHsKICAgICAgICAgICAgICAgICRwYXRoPWltcGVSdXRhQ29uZmlnUHJpdmFkYSgkZmlsZSk7CiAg
ICAgICAgICAgICAgICBlY2hvICRmaWxlLic6ICcuKCRwYXRoIT09bnVsbD8nbGVnaWJsZSAoJy4o
cmVhbHBhdGgoZGlybmFtZSgkcGF0aCkpPT09cmVhbHBhdGgoZGlybmFtZShfX0RJUl9fKS4nL2Nv
bmZpZycpPydjb25maWcgZGUgY29tcGF0aWJpbGlkYWQnOidkaXJlY3RvcmlvIHByaXZhZG8nKS4n
KSc6J25vIGVuY29udHJhZG8nKS5QSFBfRU9MOwogICAgICAgICAgICB9CiAgICAgICAgICAgIGZv
cmVhY2goWydhcHAvc2VydmljZXMvQ29ycmVvSG9zdGluZ2VyQXBpU2VydmljZS5waHAnLCdhcHAv
c2VydmljZXMvQ29ycmVvQ3VlbnRhQ29uZWN0YWRhU2VydmljZS5waHAnLCdwdWJsaWMvamF2YXNj
cmlwdC9jb3JyZW9zX21hcmtldGluZy5qcyddIGFzICRjb2RlRmlsZSkgewogICAgICAgICAgICAg
ICAgZWNobyAnQ8OzZGlnbyBTSEEyNTYgJy4kY29kZUZpbGUuJzogJy5oYXNoX2ZpbGUoJ3NoYTI1
NicsZGlybmFtZShfX0RJUl9fKS4nLycuJGNvZGVGaWxlKS5QSFBfRU9MOwogICAgICAgICAgICB9
CiAgICAgICAgICAgICRzZW5kZXI9Jyc7CiAgICAgICAgICAgIGlmKGlzc2V0KCRhcmd2WzJdKSkg
ewogICAgICAgICAgICAgICAgaWYoIXN0cl9zdGFydHNfd2l0aCgkYXJndlsyXSwnLS1yZW1pdGVu
dGU9JykgfHwgY291bnQoJGFyZ3YpPjMpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hP
U1RJTkdFUl9NT0RPX0lOVkFMSURPJyk7IH0KICAgICAgICAgICAgICAgICRzZW5kZXI9c3Vic3Ry
KCRhcmd2WzJdLDEyKTsKICAgICAgICAgICAgfQogICAgICAgICAgICBmb3JlYWNoKGRpYWdub3N0
aWNhckhvc3RpbmdlckNvbWVyY2lhbCgkYXBpLCRzZW5kZXIpIGFzICRsaW5lKSB7IGVjaG8gJGxp
bmUuUEhQX0VPTDsgfQogICAgICAgIH0gZWxzZSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9u
KCdIT1NUSU5HRVJfTU9ET19JTlZBTElETycpOyB9CiAgICAgICAgZWNobyAiU2luIGVudsOtbyBk
ZSBtZW5zYWplcyBuaSBjYW1iaW9zIGVuIHJlY3VwZXJhY2nDs24sIFNNVFAgbyBiYXNlIGRlIGRh
dG9zLlxuIjsKICAgIH0gY2F0Y2goVGhyb3dhYmxlICRlKSB7CiAgICAgICAgJHI9JGFwaS0+ZmFs
bG8oJGUpO2Z3cml0ZShTVERFUlIsJHJbJ2NvZGlnb190ZWNuaWNvJ10uJzogJy4kclsnbWVuc2Fq
ZSddLlBIUF9FT0wpO2V4aXQoMik7CiAgICB9Cn0K
CENTRAL_HELPER_2
 [[ "$(digest "$TMP/stage/tools/diagnosticar_hostinger_comercial.php")" == '0c0a754c71a3757ace6d0e9a95314109c31f28492400ff0108e3cb5110a8fcba' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["tools/diagnosticar_hostinger_comercial.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/tools/diagnosticar_hostinger_comercial.php")"
  case "$existing" in 0c0a754c71a3757ace6d0e9a95314109c31f28492400ff0108e3cb5110a8fcba|35f8f35dda34a5ecd7cac36fdf9c54766f1588a5a184f29fbb91d6811781ebbf) ;; *) fail 'Dependencia personalizada: tools/diagnosticar_hostinger_comercial.php. No se sobrescribe.';; esac
  if [[ "$existing" == '0c0a754c71a3757ace6d0e9a95314109c31f28492400ff0108e3cb5110a8fcba' ]];then cp -p -- "$LIVE/tools/diagnosticar_hostinger_comercial.php" "$TMP/stage/tools/diagnosticar_hostinger_comercial.php"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/tools/diagnosticar_hostinger_comercial.php" "$TMP/stage/tools/diagnosticar_hostinger_comercial.php";chmod --reference="$LIVE/tools/diagnosticar_hostinger_comercial.php" "$TMP/stage/tools/diagnosticar_hostinger_comercial.php";fi
 else chmod 0644 "$TMP/stage/tools/diagnosticar_hostinger_comercial.php";fi
 base64 --decode > "$TMP/stage/tools/configurar_hostinger_comercial.sh" <<'CENTRAL_HELPER_3'
IyEvdXNyL2Jpbi9lbnYgYmFzaAojIFPDs2xvIHRva2VuIGNvbWVyY2lhbDsgbnVuY2EgcHJlZ3Vu
dGEgbmkgdXRpbGl6YSBTTVRQIGRlIHJlY3VwZXJhY2nDs24uCnNldCAreApzZXQgLWV1byBwaXBl
ZmFpbAp1bWFzayAwNzcKW1sgJCMgPT0gMCB8fCAoICQjID09IDEgJiYgIiQxIiA9PSAtLXJlZW1w
bGF6YXItdG9rZW4gKSBdXSB8fCB7IGVjaG8gJ1VzbzogYmFzaCBjb25maWd1cmFyX2hvc3Rpbmdl
cl9jb21lcmNpYWwuc2ggWy0tcmVlbXBsYXphci10b2tlbl0nID4mMjtleGl0IDI7IH0KW1sgLXQg
MCBdXSB8fCB7IGVjaG8gJ0VqZWN1dGEgZGVzZGUgdW5hIHRlcm1pbmFsIFNTSCBpbnRlcmFjdGl2
YS4nID4mMjtleGl0IDI7IH0KUk9PVD0iJChjZCAiJChkaXJuYW1lICIkMCIpLy4uIiAmJiBwd2Qg
LVApIgppZiBbWyAkIyA9PSAwIF1dICYmIHBocCAiJFJPT1QvdG9vbHMvZGlhZ25vc3RpY2FyX2hv
c3Rpbmdlcl9jb21lcmNpYWwucGhwIiAtLWNoZWNrID4vZGV2L251bGwgMj4mMTt0aGVuCiBlY2hv
ICdMYSBjb25maWd1cmFjacOzbiBjb21lcmNpYWwgZXhpc3RlbnRlIGVzIHbDoWxpZGEgeSBkZXZ1
ZWx2ZSBidXpvbmVzIGluc3RpdHVjaW9uYWxlcy4gU2UgY29uc2VydmEgc2luIGNhbWJpb3MuJwog
ZWNobyAnUGFyYSBjb21wcm9iYXIgYnV6b25lczogcGhwIHRvb2xzL2RpYWdub3N0aWNhcl9ob3N0
aW5nZXJfY29tZXJjaWFsLnBocCAtLWNoZWNrJwogZXhpdCAwCmZpClRPS0VOPScnCnRyYXAgJ3Vu
c2V0IFRPS0VOIElNUEVfSE9TVElOR0VSX1RPS0VOX1NFVFVQJyBFWElUCnJlYWQgLXIgLXMgLXAg
J1Rva2VuIEVTUEVDw41GSUNPIGRlIEhvc3RpbmdlciBNYWlsIEFQSSAoZW50cmFkYSBvY3VsdGEp
OiAnIFRPS0VOCnByaW50ZiAnXG4nCklNUEVfSE9TVElOR0VSX1RPS0VOX1NFVFVQPSIkVE9LRU4i
IHBocCAiJFJPT1QvdG9vbHMvZGlhZ25vc3RpY2FyX2hvc3Rpbmdlcl9jb21lcmNpYWwucGhwIiAt
LWd1YXJkYXItdG9rZW4K
CENTRAL_HELPER_3
 [[ "$(digest "$TMP/stage/tools/configurar_hostinger_comercial.sh")" == 'e93edbb553f850085ee7a43a6a2a5f6d76efdcb272d2c081aabc0e7eae1d1a3d' ]] || fail 'Dependencia de entrega alterada.'
 if [[ "${BEFORE["tools/configurar_hostinger_comercial.sh"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/tools/configurar_hostinger_comercial.sh")"
  case "$existing" in bee00235c9f6f6921a83d7b5481d5e2dda75dd55701df1a219a90f54b0d3bdd7|e93edbb553f850085ee7a43a6a2a5f6d76efdcb272d2c081aabc0e7eae1d1a3d) ;; *) fail 'Dependencia personalizada: tools/configurar_hostinger_comercial.sh. No se sobrescribe.';; esac
  if [[ "$existing" == 'e93edbb553f850085ee7a43a6a2a5f6d76efdcb272d2c081aabc0e7eae1d1a3d' ]];then cp -p -- "$LIVE/tools/configurar_hostinger_comercial.sh" "$TMP/stage/tools/configurar_hostinger_comercial.sh"
  else php -r '$s=file_get_contents($argv[1]);if(strpos($s,"\r\n")!==false)file_put_contents($argv[2],str_replace("\n","\r\n",file_get_contents($argv[2])));' "$LIVE/tools/configurar_hostinger_comercial.sh" "$TMP/stage/tools/configurar_hostinger_comercial.sh";chmod --reference="$LIVE/tools/configurar_hostinger_comercial.sh" "$TMP/stage/tools/configurar_hostinger_comercial.sh";fi
 else chmod 0644 "$TMP/stage/tools/configurar_hostinger_comercial.sh";fi
 base64 --decode > "$TMP/diagnostic.php" <<'CENTRAL_DIAGNOSTIC'
PD9waHAKLy8gRGlhZ27Ds3N0aWNvIHkgY29uZmlndXJhY2nDs24gZXhjbHVzaXZhbWVudGUgY29t
ZXJjaWFsZXMuIE5pbmfDum4gZW52w61vIG5pIGFjY2VzbyBhIFNNVFAuCmlmKFBIUF9TQVBJIT09
J2NsaScpIHsgaHR0cF9yZXNwb25zZV9jb2RlKDQwNCk7ZXhpdDsgfQpyZXF1aXJlX29uY2UgZGly
bmFtZShfX0RJUl9fKS4nL2FwcC9zZXJ2aWNlcy9Db3JyZW9Ib3N0aW5nZXJBcGlTZXJ2aWNlLnBo
cCc7CgpmdW5jdGlvbiBndWFyZGFyVG9rZW5Ib3N0aW5nZXJDb21lcmNpYWwoc3RyaW5nICR0b2tl
biw/Y2FsbGFibGUgJHByb2JlPW51bGwpOiBhcnJheQp7CiAgICBpZigkdG9rZW49PT0nJyB8fCBz
dHJsZW4oJHRva2VuKT4xNjM4NCB8fCBwcmVnX21hdGNoKCcvW1x4MDAtXHgyMFx4N2ZdLycsJHRv
a2VuKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX1RPS0VOX0FVU0VO
VEUnKTsgfQogICAgJGFwaT1uZXcgQ29ycmVvSG9zdGluZ2VyQXBpU2VydmljZSgpOwogICAgJGJv
eGVzPSRwcm9iZSA/ICRwcm9iZSgkdG9rZW4pIDogJGFwaS0+YnV6b25lcygkdG9rZW4pOwogICAg
aWYoISRib3hlcykgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX1NJTl9C
VVpPTkVTJyk7IH0KICAgICRyb290PXJlYWxwYXRoKGRpcm5hbWUoX19ESVJfXykpOwogICAgJGRp
cj1ydHJpbSgoc3RyaW5nKShnZXRlbnYoJ0lNUEVfUFJJVkFURV9DT05GSUdfRElSJyk/OmRpcm5h
bWUoJHJvb3QpLicvaW1wZS1wcml2YXRlJyksJy9cXCcpOwogICAgJG5vcm1hbD1zdHJfcmVwbGFj
ZSgnXFwnLCcvJywkZGlyKTsKICAgIGlmKCghc3RyX3N0YXJ0c193aXRoKCRub3JtYWwsJy8nKSAm
JiAhcHJlZ19tYXRjaCgnL15bYS16QS1aXTpcLy9EJywkbm9ybWFsKSkgfHwgcHJlZ19tYXRjaCgn
fihefC8pXC57MSwyfSgvfCQpficsJG5vcm1hbCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRp
b24oJ0hPU1RJTkdFUl9SVVRBX1BSSVZBREEnKTsgfQogICAgZm9yKCRwPSRkaXI7ZGlybmFtZSgk
cCkhPT0kcDskcD1kaXJuYW1lKCRwKSkgeyBpZihpc19saW5rKCRwKSkgeyB0aHJvdyBuZXcgUnVu
dGltZUV4Y2VwdGlvbignSE9TVElOR0VSX1JVVEFfUFJJVkFEQScpOyB9IH0KICAgICRhbmNlc3Rv
cj0kZGlyO3doaWxlKCFmaWxlX2V4aXN0cygkYW5jZXN0b3IpICYmIGRpcm5hbWUoJGFuY2VzdG9y
KSE9PSRhbmNlc3RvcikgeyAkYW5jZXN0b3I9ZGlybmFtZSgkYW5jZXN0b3IpOyB9CiAgICAkYW5j
ZXN0b3I9cmVhbHBhdGgoJGFuY2VzdG9yKTskZG9jdW1lbnRSb290PXRyaW0oKHN0cmluZykoJF9T
RVJWRVJbJ0RPQ1VNRU5UX1JPT1QnXT8/JycpKTsKICAgIGZvcmVhY2goWyRyb290LCRkb2N1bWVu
dFJvb3QhPT0nJz9yZWFscGF0aCgkZG9jdW1lbnRSb290KTpmYWxzZV0gYXMgJHB1YmxpYykgewog
ICAgICAgIGlmKCRhbmNlc3RvciAmJiAkcHVibGljICYmIHN0cl9zdGFydHNfd2l0aChzdHJ0b2xv
d2VyKCRhbmNlc3Rvci5ESVJFQ1RPUllfU0VQQVJBVE9SKSxzdHJ0b2xvd2VyKCRwdWJsaWMuRElS
RUNUT1JZX1NFUEFSQVRPUikpKSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdIT1NUSU5H
RVJfUlVUQV9QUklWQURBJyk7IH0KICAgIH0KICAgIGlmKCFpc19kaXIoJGRpcikgJiYgIW1rZGly
KCRkaXIsMDcwMCx0cnVlKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VS
X0VTQ1JJVFVSQV9QUklWQURBJyk7IH0KICAgICRkaXI9cmVhbHBhdGgoJGRpcik7JHBhdGg9JGRp
ci4nL21haWxfY2VudHJhbC5sb2NhbC5waHAnOyRsb2NrUGF0aD0kZGlyLicvLmhvc3Rpbmdlci1j
b21lcmNpYWwubG9jayc7CiAgICBpZihpc19saW5rKCRwYXRoKSB8fCBpc19saW5rKCRsb2NrUGF0
aCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9SVVRBX1BSSVZBREEn
KTsgfQogICAgJGxvY2s9Zm9wZW4oJGxvY2tQYXRoLCdjK2InKTtpZighJGxvY2spIHsgdGhyb3cg
bmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hPU1RJTkdFUl9FU0NSSVRVUkFfUFJJVkFEQScpOyB9Y2ht
b2QoJGxvY2tQYXRoLDA2MDApOwogICAgdHJ5IHsKICAgICAgICBpZighZmxvY2soJGxvY2ssTE9D
S19FWHxMT0NLX05CKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0NP
TkZJR19PQ1VQQURBJyk7IH0KICAgICAgICAkZGF0YT1pc19maWxlKCRwYXRoKT9yZXF1aXJlICRw
YXRoOmltcGVMZWVyQ29uZmlnUHJpdmFkYSgnbWFpbF9jZW50cmFsLmxvY2FsLnBocCcpOwogICAg
ICAgIGlmKCFpc19hcnJheSgkZGF0YSkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hP
U1RJTkdFUl9DT05GSUdfSU5WQUxJREEnKTsgfQogICAgICAgIGlmKGlzX2ZpbGUoJHBhdGgpICYm
ICgkZGF0YVsndHJhbnNwb3J0J10/PycnKT09PSdob3N0aW5nZXJfYXBpJyAmJiAoJGRhdGFbJ2Fj
dGl2byddPz90cnVlKT09PXRydWUKICAgICAgICAgICAgJiYgaGFzaF9lcXVhbHMoKHN0cmluZyko
JGRhdGFbJ2FwaV90b2tlbiddPz8nJyksJHRva2VuKSkgewogICAgICAgICAgICByZXR1cm4gWydy
dXRhJz0+JHBhdGgsJ3Jlc3BhbGRvJz0+bnVsbCwnYnV6b25lcyc9PmNvdW50KCRib3hlcyksJ3Np
bl9jYW1iaW9zJz0+dHJ1ZV07CiAgICAgICAgfQogICAgICAgICRiYWNrdXA9bnVsbDsKICAgICAg
ICBpZihpc19maWxlKCRwYXRoKSkgewogICAgICAgICAgICAkYmFja3VwPSRwYXRoLicucmVzcGFs
ZG8tJy5nbWRhdGUoJ1ltZC1IaXMnKS4nLScuYmluMmhleChyYW5kb21fYnl0ZXMoNCkpOwogICAg
ICAgICAgICBpZighY29weSgkcGF0aCwkYmFja3VwKSB8fCAhY2htb2QoJGJhY2t1cCwwNjAwKSB8
fCBoYXNoX2ZpbGUoJ3NoYTI1NicsJHBhdGgpIT09aGFzaF9maWxlKCdzaGEyNTYnLCRiYWNrdXAp
KSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdIT1NUSU5HRVJfUkVTUEFMRE8nKTsgfQog
ICAgICAgIH0KICAgICAgICAkZGF0YT1hcnJheV9yZXBsYWNlKCRkYXRhLFsndHJhbnNwb3J0Jz0+
J2hvc3Rpbmdlcl9hcGknLCdhY3Rpdm8nPT50cnVlLCdhcGlfdG9rZW4nPT4kdG9rZW5dKTsKICAg
ICAgICAkdGV4dD0iPD9waHBcbi8vIENvbmZpZ3VyYWNpw7NuIHByaXZhZGEgY29tZXJjaWFsLiBO
byBwdWJsaWNhci5cbnJldHVybiAiLnZhcl9leHBvcnQoJGRhdGEsdHJ1ZSkuIjtcbiI7CiAgICAg
ICAgJHRtcD0kcGF0aC4nLnRtcC0nLmJpbjJoZXgocmFuZG9tX2J5dGVzKDgpKTsKICAgICAgICB0
cnkgewogICAgICAgICAgICAkaD1mb3BlbigkdG1wLCd4YicpO2lmKCEkaCkgeyB0aHJvdyBuZXcg
UnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0VTQ1JJVFVSQV9QUklWQURBJyk7IH0KICAgICAg
ICAgICAgdHJ5IHsgaWYoIWNobW9kKCR0bXAsMDYwMCkgfHwgZndyaXRlKCRoLCR0ZXh0KSE9PXN0
cmxlbigkdGV4dCkgfHwgIWZmbHVzaCgkaCkpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24o
J0hPU1RJTkdFUl9FU0NSSVRVUkFfUFJJVkFEQScpOyB9IH0KICAgICAgICAgICAgZmluYWxseSB7
IGZjbG9zZSgkaCk7IH0KICAgICAgICAgICAgaWYoIXJlbmFtZSgkdG1wLCRwYXRoKSkgeyB0aHJv
dyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0VTQ1JJVFVSQV9QUklWQURBJyk7IH0K
ICAgICAgICB9IGZpbmFsbHkgeyBpZihpc19maWxlKCR0bXApKSB7IHVubGluaygkdG1wKTsgfSB9
CiAgICAgICAgcmV0dXJuIFsncnV0YSc9PiRwYXRoLCdyZXNwYWxkbyc9PiRiYWNrdXAsJ2J1em9u
ZXMnPT5jb3VudCgkYm94ZXMpXTsKICAgIH0gZmluYWxseSB7IGZsb2NrKCRsb2NrLExPQ0tfVU4p
O2ZjbG9zZSgkbG9jayk7IH0KfQoKZnVuY3Rpb24gZGlhZ25vc3RpY2FySG9zdGluZ2VyQ29tZXJj
aWFsKENvcnJlb0hvc3RpbmdlckFwaVNlcnZpY2UgJGFwaSwgc3RyaW5nICRyZW1pdGVudGU9Jycp
OiBhcnJheQp7CiAgICAkY29uZmlnPSRhcGktPmNvbmZpZ3VyYWNpb24oKTskYm94ZXM9JGFwaS0+
YnV6b25lcygkY29uZmlnWyd0b2tlbiddKTskZD0kYXBpLT5kZXNjdWJyaW1pZW50bygpOwogICAg
JGxpbmVzPVsnRnVlbnRlIGVmZWN0aXZhIGRlbCB0b2tlbjogJy4kY29uZmlnWydzb3VyY2UnXSwK
ICAgICAgICAnQVBJIGF1dG9yaXphZG9zOiAnLiRkWyd0b3RhbF9hcGknXS4nOyBpbnN0aXR1Y2lv
bmFsZXMgQCcuQ29ycmVvSG9zdGluZ2VyQXBpU2VydmljZTo6RE9NSU5JTy4nOiAnLiRkWyd0b3Rh
bF9pbnN0aXR1Y2lvbmFsJ10sCiAgICAgICAgJ0NvbnN1bHRhIGNvbXBsZXRhIGRlIEdFVCAvYXBp
L3YxL21lOyBzaW4gY2FjaMOpIGxvY2FsIG5pIHBhZ2luYWNpw7NuIGVuIGVzdGUgZW5kcG9pbnQu
J107CiAgICBmb3JlYWNoKFsnYWxsb3dlZF9zZW5kZXJzJywndXNlcl9zZW5kZXJzJywncm9sZV9z
ZW5kZXJzJ10gYXMgJGZpZWxkKSB7CiAgICAgICAgJGxpbmVzW109J1Jlc3RyaWNjacOzbiAnLiRm
aWVsZC4nOiAnLihhcnJheV9rZXlfZXhpc3RzKCRmaWVsZCwkY29uZmlnWydwb2xpY3knXSk/J2Nv
bmZpZ3VyYWRhOyByZXZpc2FyIGludGVyc2VjY2nDs24gY29uIGVsIHVzdWFyaW8vcm9sJzonYXVz
ZW50ZScpOwogICAgfQogICAgJGFjY291bnRzPWltcGVMZWVyQ29uZmlnUHJpdmFkYSgnbWFpbF9h
Y2NvdW50cy5sb2NhbC5waHAnKVsnYWNjb3VudHMnXT8/W107CiAgICBpZighaXNfYXJyYXkoJGFj
Y291bnRzKSkgeyB0aHJvdyBuZXcgUnVudGltZUV4Y2VwdGlvbignSE9TVElOR0VSX0NPTkZJR19J
TlZBTElEQScpOyB9CiAgICAkbGluZXNbXT0nUmVzdHJpY2Npb25lcyBwcmV2aWFzIGVuIG1haWxf
YWNjb3VudHMubG9jYWwucGhwOiAnLmNvdW50KCRhY2NvdW50cykuJyBlbnRyYWRhcyAobm8gc2Ug
Y3JlYW4gY3VlbnRhcyBudWV2YXMpLic7CiAgICBmb3JlYWNoKGFycmF5X2tleXMoJGJveGVzKSBh
cyAkZW1haWwpIHsgJGxpbmVzW109J0J1esOzbiBBUEk6ICcuJGVtYWlsOyB9CiAgICAkbGluZXNb
XT0nU2kgZmFsdGEgdW4gYnV6w7NuOiBhdXRvcml6YXJsbyBlbiBlbCB0b2tlbiBNYWlsIEFQSSBk
ZSBsYSBtaXNtYSBvcmRlbiBkZSBIb3N0aW5nZXI7IGNvbXBhcnRpciBkb21pbmlvIG5vIGxvIGF1
dG9yaXphLic7CiAgICBpZigkcmVtaXRlbnRlIT09JycpIHsKICAgICAgICAkcmVtaXRlbnRlPXN0
cnRvbG93ZXIodHJpbSgkcmVtaXRlbnRlKSk7CiAgICAgICAgaWYoISRhcGktPmluc3RpdHVjaW9u
YWwoJHJlbWl0ZW50ZSkpIHsgJGxpbmVzW109J1JlbWl0ZW50ZSBzb2xpY2l0YWRvOiBkb21pbmlv
L2Zvcm1hdG8gbm8gaW5zdGl0dWNpb25hbDsgcmVjaGF6YWRvLic7IH0KICAgICAgICBlbHNlIHsg
JGxpbmVzW109J1JlbWl0ZW50ZSBzb2xpY2l0YWRvOiAnLihpc3NldCgkYm94ZXNbJHJlbWl0ZW50
ZV0pPydkZXZ1ZWx0byBwb3IgSG9zdGluZ2VyOyBzZSBhcGxpY2FuIGFkZW3DoXMgcmVzdHJpY2Np
b25lcyBkZWwgdXN1YXJpby9yb2wuJzonTk8gZGV2dWVsdG8gcG9yIEhvc3Rpbmdlcjsgbm8gc2Ug
cGVybWl0ZSBlbnZpYXIuJyk7IH0KICAgIH0KICAgIHJldHVybiAkbGluZXM7Cn0KCmlmKHJlYWxw
YXRoKChzdHJpbmcpKCRfU0VSVkVSWydTQ1JJUFRfRklMRU5BTUUnXT8/JycpKT09PV9fRklMRV9f
KSB7CiAgICBpbmlfc2V0KCdkaXNwbGF5X2Vycm9ycycsJzAnKTskYXBpPW5ldyBDb3JyZW9Ib3N0
aW5nZXJBcGlTZXJ2aWNlKCk7CiAgICB0cnkgewogICAgICAgICRtb2RlPSRhcmd2WzFdPz8nLS1j
aGVjayc7CiAgICAgICAgaWYoJG1vZGU9PT0nLS1ndWFyZGFyLXRva2VuJykgewogICAgICAgICAg
ICAkcj1ndWFyZGFyVG9rZW5Ib3N0aW5nZXJDb21lcmNpYWwodHJpbSgoc3RyaW5nKWdldGVudign
SU1QRV9IT1NUSU5HRVJfVE9LRU5fU0VUVVAnKSkpOwogICAgICAgICAgICBlY2hvICdPSzogdG9r
ZW4gdmFsaWRhZG8geSBndWFyZGFkbyBmdWVyYSBkZWwgc2l0aW8gcMO6YmxpY287IGJ1em9uZXM9
Jy4kclsnYnV6b25lcyddLlBIUF9FT0w7CiAgICAgICAgICAgIGlmKCRyWydyZXNwYWxkbyddKSB7
IGVjaG8gJ1Jlc3RhdXJhciBjb25maWd1cmFjacOzbiBjb21lcmNpYWw6IGNwIC1wIC0tICcuZXNj
YXBlc2hlbGxhcmcoJHJbJ3Jlc3BhbGRvJ10pLicgJy5lc2NhcGVzaGVsbGFyZygkclsncnV0YSdd
KS5QSFBfRU9MOyB9CiAgICAgICAgfSBlbHNlaWYoJG1vZGU9PT0nLS1jaGVjaycpIHsKICAgICAg
ICAgICAgZWNobyAnVmVyc2nDs24gY29tZXJjaWFsOiAnLkNvcnJlb0hvc3RpbmdlckFwaVNlcnZp
Y2U6OlZFUlNJT04uJzsgUEhQICcuUEhQX1ZFUlNJT04uJyBTQVBJICcuUEhQX1NBUEkuUEhQX0VP
TDsKICAgICAgICAgICAgZm9yZWFjaChbJ2hvc3Rpbmdlcl9tYWlsX2NvbmZpZy5waHAnLCdtYWls
X2NlbnRyYWwubG9jYWwucGhwJywnbWFya2V0aW5nX21haWwubG9jYWwucGhwJ10gYXMgJGZpbGUp
IHsKICAgICAgICAgICAgICAgICRwYXRoPWltcGVSdXRhQ29uZmlnUHJpdmFkYSgkZmlsZSk7CiAg
ICAgICAgICAgICAgICBlY2hvICRmaWxlLic6ICcuKCRwYXRoIT09bnVsbD8nbGVnaWJsZSAoJy4o
cmVhbHBhdGgoZGlybmFtZSgkcGF0aCkpPT09cmVhbHBhdGgoZGlybmFtZShfX0RJUl9fKS4nL2Nv
bmZpZycpPydjb25maWcgZGUgY29tcGF0aWJpbGlkYWQnOidkaXJlY3RvcmlvIHByaXZhZG8nKS4n
KSc6J25vIGVuY29udHJhZG8nKS5QSFBfRU9MOwogICAgICAgICAgICB9CiAgICAgICAgICAgIGZv
cmVhY2goWydhcHAvc2VydmljZXMvQ29ycmVvSG9zdGluZ2VyQXBpU2VydmljZS5waHAnLCdhcHAv
c2VydmljZXMvQ29ycmVvQ3VlbnRhQ29uZWN0YWRhU2VydmljZS5waHAnLCdwdWJsaWMvamF2YXNj
cmlwdC9jb3JyZW9zX21hcmtldGluZy5qcyddIGFzICRjb2RlRmlsZSkgewogICAgICAgICAgICAg
ICAgZWNobyAnQ8OzZGlnbyBTSEEyNTYgJy4kY29kZUZpbGUuJzogJy5oYXNoX2ZpbGUoJ3NoYTI1
NicsZGlybmFtZShfX0RJUl9fKS4nLycuJGNvZGVGaWxlKS5QSFBfRU9MOwogICAgICAgICAgICB9
CiAgICAgICAgICAgICRzZW5kZXI9Jyc7CiAgICAgICAgICAgIGlmKGlzc2V0KCRhcmd2WzJdKSkg
ewogICAgICAgICAgICAgICAgaWYoIXN0cl9zdGFydHNfd2l0aCgkYXJndlsyXSwnLS1yZW1pdGVu
dGU9JykgfHwgY291bnQoJGFyZ3YpPjMpIHsgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ0hP
U1RJTkdFUl9NT0RPX0lOVkFMSURPJyk7IH0KICAgICAgICAgICAgICAgICRzZW5kZXI9c3Vic3Ry
KCRhcmd2WzJdLDEyKTsKICAgICAgICAgICAgfQogICAgICAgICAgICBmb3JlYWNoKGRpYWdub3N0
aWNhckhvc3RpbmdlckNvbWVyY2lhbCgkYXBpLCRzZW5kZXIpIGFzICRsaW5lKSB7IGVjaG8gJGxp
bmUuUEhQX0VPTDsgfQogICAgICAgIH0gZWxzZSB7IHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9u
KCdIT1NUSU5HRVJfTU9ET19JTlZBTElETycpOyB9CiAgICAgICAgZWNobyAiU2luIGVudsOtbyBk
ZSBtZW5zYWplcyBuaSBjYW1iaW9zIGVuIHJlY3VwZXJhY2nDs24sIFNNVFAgbyBiYXNlIGRlIGRh
dG9zLlxuIjsKICAgIH0gY2F0Y2goVGhyb3dhYmxlICRlKSB7CiAgICAgICAgJHI9JGFwaS0+ZmFs
bG8oJGUpO2Z3cml0ZShTVERFUlIsJHJbJ2NvZGlnb190ZWNuaWNvJ10uJzogJy4kclsnbWVuc2Fq
ZSddLlBIUF9FT0wpO2V4aXQoMik7CiAgICB9Cn0K
CENTRAL_DIAGNOSTIC
 [[ "$(digest "$TMP/diagnostic.php")" == '0c0a754c71a3757ace6d0e9a95314109c31f28492400ff0108e3cb5110a8fcba' ]] || fail 'Diagnóstico alterado.'
 [[ ! -e "$LIVE/tools" || -d "$LIVE/tools" ]] || fail 'tools no es directorio.'
 mkdir -p "$TMP/patches/0"
 base64 --decode > "$TMP/patches/0/1.patch" <<'CENTRAL_PATCH_0_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2Vydmlj
ZS5waHAgYi9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhw
Ci0tLSBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5waHAK
KysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocApA
QCAtMiw3ICsyLDYgQEAKIAogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0Zpcm1hQ29ycmVvU2Vy
dmljZS5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0N1ZW50YUNvbmVjdGFk
YVNlcnZpY2UucGhwJzsKLXJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9TbXRwVHJhbnNw
b3J0LnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvRW52aW9Db250cm9sLnBo
cCc7CiAKIGNsYXNzIENvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlCkBAIC0xNiw2ICsx
NSw3IEBACiAgICAgcHJpdmF0ZSAkbWFpbGVyRmFjdG9yeTsKICAgICBwcml2YXRlICRjdWVudGFz
OwogICAgIHByaXZhdGUgJGFwaVJlcXVlc3Q7CisgICAgcHJpdmF0ZSAkaG9zdGluZ2VyOwogCiAg
ICAgcHVibGljIGZ1bmN0aW9uIF9fY29uc3RydWN0KD9jYWxsYWJsZSAkbWFpbGVyRmFjdG9yeSA9
IG51bGwsID9Db3JyZW9DdWVudGFDb25lY3RhZGFTZXJ2aWNlICRjdWVudGFzID0gbnVsbCwgP2Nh
bGxhYmxlICRhcGlSZXF1ZXN0ID0gbnVsbCkKICAgICB7CkBAIC0yMyw3ICsyMywxNyBAQAogICAg
ICAgICAkdGhpcy0+bWFpbGVyRmFjdG9yeSA9ICRtYWlsZXJGYWN0b3J5OwogICAgICAgICAkdGhp
cy0+Y3VlbnRhcyA9ICRjdWVudGFzID8/IG5ldyBDb3JyZW9DdWVudGFDb25lY3RhZGFTZXJ2aWNl
KCk7CiAgICAgICAgICR0aGlzLT5hcGlSZXF1ZXN0ID0gJGFwaVJlcXVlc3Q7CisgICAgICAgICR0
aGlzLT5ob3N0aW5nZXIgPSBuZXcgQ29ycmVvSG9zdGluZ2VyQXBpU2VydmljZSgkYXBpUmVxdWVz
dCk7CisgICAgICAgICR0aGlzLT5jdWVudGFzLT5jb25maWd1cmFyQ29uc3VsdGFBcGkoWyR0aGlz
LT5ob3N0aW5nZXIsICdzb2xpY2l0YXInXSk7CiAgICAgICAgICR0aGlzLT5maXJtYVNlcnZpY2Ug
PSBuZXcgRmlybWFDb3JyZW9TZXJ2aWNlKCk7CisgICAgfQorCisgICAgcHVibGljIGZ1bmN0aW9u
IHJlbWl0ZW50ZXMoaW50ICR1c2VyKTogYXJyYXkgeyByZXR1cm4gJHRoaXMtPmN1ZW50YXMtPnJl
bWl0ZW50ZXMoJHVzZXIpOyB9CisKKyAgICBwdWJsaWMgZnVuY3Rpb24gdmFsaWRhclJlbWl0ZW50
ZShzdHJpbmcgJGZyb20sIGludCAkdXNlcik6IGFycmF5CisgICAgeworICAgICAgICAkcj0kdGhp
cy0+Y3VlbnRhcy0+cmVzb2x2ZXIoJGZyb20sJHVzZXIpOworICAgICAgICByZXR1cm4gZW1wdHko
JHJbJ29rJ10pID8gJHIgOiBbJ29rJz0+dHJ1ZSwncmVtaXRlbnRlJz0+JGZyb21dOwogICAgIH0K
IAogICAgIHB1YmxpYyBmdW5jdGlvbiBlbnZpYXIoJGRhdG9zKQpAQCAtMzUsNyArNDUsNyBAQAog
ICAgICAgICAvKgogICAgICAgICAgKiBDb21wYXRpYmlsaWRhZCBjb24gbcOzZHVsb3MgZXhpc3Rl
bnRlczogc2kgZWwgbGxhbWFkb3IgdG9kYXbDrWEgbm8KICAgICAgICAgICogbWFuZGEgdXN1YXJp
b19pZCwgcmV1dGlsaXphbW9zIGVsIHVzdWFyaW8gYXV0ZW50aWNhZG8gZGUgbGEgc2VzacOzbi4K
LSAgICAgICAgICogQXPDrSB0b2RvcyBsb3MgZW52w61vcyBpbnRlcmFjdGl2b3MgdXNhbiBsYSBj
dWVudGEgU01UUCBwZXJzb25hbC4KKyAgICAgICAgICogVG9kb3MgbG9zIGVudsOtb3MgY29tZXJj
aWFsZXMgdmFsaWRhbiBlbCByZW1pdGVudGUgY29uIGxhIEFQSSBjZW50cmFsLgogICAgICAgICAg
Ki8KICAgICAgICAgaWYgKCR1c3VhcmlvSWQgPD0gMCAmJiBpc3NldCgkX1NFU1NJT05bJ3VzdWFy
aW9faWQnXSkpIHsKICAgICAgICAgICAgICR1c3VhcmlvSWQgPSAoaW50KSRfU0VTU0lPTlsndXN1
YXJpb19pZCddOwpAQCAtODAsOCArOTAsNiBAQAogICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5l
cnJvcignRWwgYXN1bnRvIG8gZWwgbWVuc2FqZSBzdXBlcmEgZWwgdGFtYcOxbyBwZXJtaXRpZG8u
JywgNDIyKTsKICAgICAgICAgfQogCi0gICAgICAgICRydXRhRmlybWEgPSAkdGhpcy0+ZmlybWFT
ZXJ2aWNlLT5vYnRlbmVyUnV0YSgkcmVtaXRlbnRlKTsKLQogICAgICAgICB0cnkgewogICAgICAg
ICAgICAgJGNvbm5lY3Rpb24gPSAkdGhpcy0+Y3VlbnRhcy0+cmVzb2x2ZXIoJHJlbWl0ZW50ZSwg
JHVzdWFyaW9JZCwgJHBlcmZpbE1hcmtldGluZyk7CiAgICAgICAgIH0gY2F0Y2ggKFRocm93YWJs
ZSAkZXJyb3IpIHsKQEAgLTkwLDYgKzk4LDcgQEAKICAgICAgICAgfQogICAgICAgICBpZiAoZW1w
dHkoJGNvbm5lY3Rpb25bJ29rJ10pKSB7IHJldHVybiAkY29ubmVjdGlvbjsgfQogICAgICAgICAk
YWNjb3VudCA9ICRjb25uZWN0aW9uWydhY2NvdW50J107CisgICAgICAgICRydXRhRmlybWEgPSAk
dGhpcy0+ZmlybWFTZXJ2aWNlLT5vYnRlbmVyUnV0YSgkcmVtaXRlbnRlKTsKICAgICAgICAgJHNl
bmQgPSBmdW5jdGlvbiAoKSB1c2UgKCRhY2NvdW50LCAkcmVtaXRlbnRlLCAkbm9tYnJlUmVtaXRl
bnRlLCAkZGVzdGluYXRhcmlvLCAkbm9tYnJlRGVzdGluYXRhcmlvLAogICAgICAgICAgICAgJGFz
dW50bywgJGN1ZXJwbywgJHJ1dGFGaXJtYSwgJGltYWdlbmVzRW1iZWJpZGFzLCAkYWRqdW50b3Ms
ICRodG1sQWRpY2lvbmFsKSB7CiAgICAgICAgICAgICBpZiAoJGFjY291bnRbJ3RyYW5zcG9ydCdd
ID09PSAnaG9zdGluZ2VyX2FwaScpIHsKQEAgLTEwMCw4ICsxMDksNyBAQAogICAgICAgICAgICAg
ICAgIHJldHVybiAkdGhpcy0+ZW52aWFySG9zdGluZ2VyKCRyZW1pdGVudGUsICRub21icmVSZW1p
dGVudGUsICRkZXN0aW5hdGFyaW8sICRhc3VudG8sICRjdWVycG8sICRydXRhRmlybWEsCiAgICAg
ICAgICAgICAgICAgICAgIFsndG9rZW4nID0+ICRhY2NvdW50WydhcGlfdG9rZW4nXSwgJ2Jhc2Vf
dXJsJyA9PiAkYmFzZV0sICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9zLCAkaHRtbEFkaWNp
b25hbCwgdHJ1ZSwgJHJlbWl0ZW50ZSk7CiAgICAgICAgICAgICB9Ci0gICAgICAgICAgICByZXR1
cm4gJHRoaXMtPmVudmlhclNtdHAoJHJlbWl0ZW50ZSwgJG5vbWJyZVJlbWl0ZW50ZSwgJGRlc3Rp
bmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJpbywgJGFzdW50bywgJGN1ZXJwbywKLSAgICAgICAg
ICAgICAgICAkcnV0YUZpcm1hLCAkaW1hZ2VuZXNFbWJlYmlkYXMsICRhZGp1bnRvcywgJGh0bWxB
ZGljaW9uYWwsICRhY2NvdW50KTsKKyAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJ0Vs
IGVudsOtbyBjb21lcmNpYWwgc8OzbG8gYWRtaXRlIEhvc3RpbmdlciBNYWlsIEFQSS4nLCA0MjIp
OwogICAgICAgICB9OwogICAgICAgICAkYXR0YWNobWVudHMgPSBhcnJheV9tYXAoc3RhdGljIGZ1
bmN0aW9uICgkYSkgeyByZXR1cm4gWyRhWydub21icmUnXSwgaGFzaF9maWxlKCdzaGEyNTYnLCAk
YVsncnV0YSddKV07IH0sIGFycmF5X21lcmdlKCRhZGp1bnRvcywgJGltYWdlbmVzRW1iZWJpZGFz
KSk7CiAgICAgICAgICRmaW5nZXJwcmludCA9IGhhc2goJ3NoYTI1NicsIGpzb25fZW5jb2RlKFsk
dXN1YXJpb0lkLCAkcmVtaXRlbnRlLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRvLCAkY3VlcnBvLCAk
aHRtbEFkaWNpb25hbCwgJGF0dGFjaG1lbnRzLApAQCAtMzA2LDcgKzMxNCw3IEBACiAgICAgICAg
ICAgICAkcGF5bG9hZAogICAgICAgICApOwogCi0gICAgICAgIGlmICghKCRyZXNwdWVzdGFbJ29r
J10gPz8gZmFsc2UpKSB7CisgICAgICAgIGlmICghKCRyZXNwdWVzdGFbJ29rJ10gPz8gZmFsc2Up
IHx8IChpbnQpKCRyZXNwdWVzdGFbJ2NvZGlnb19odHRwJ10gPz8gMCkgIT09IDIwNCkgewogICAg
ICAgICAgICAgJGNvZGUgPSAoaW50KSgkcmVzcHVlc3RhWydjb2RpZ29faHR0cCddID8/IDUwMik7
CiAgICAgICAgICAgICAkdW5rbm93biA9ICFlbXB0eSgkcmVzcHVlc3RhWydpbmNpZXJ0byddKSB8
fCAkY29kZSA+PSA1MDAgfHwgJGNvZGUgPCA0MDAgfHwgJGNvZGUgPT09IDQwODsKICAgICAgICAg
ICAgIHJldHVybiBbJ29rJyA9PiBmYWxzZSwgJ2NvZGlnb19odHRwJyA9PiA1MDIsICdwcm92ZWVk
b3InID0+ICdIT1NUSU5HRVJfTUFJTF9BUEknLApAQCAtNTk2LDU3ICs2MDQsNyBAQAogCiAgICAg
cHJpdmF0ZSBmdW5jdGlvbiBzb2xpY2l0YXJIb3N0aW5nZXIoJG1ldG9kbywgJHVybCwgJHRva2Vu
LCAkcGF5bG9hZCkKICAgICB7Ci0gICAgICAgIGlmICgkdGhpcy0+YXBpUmVxdWVzdCkgeyByZXR1
cm4gKCR0aGlzLT5hcGlSZXF1ZXN0KSgkbWV0b2RvLCAkdXJsLCAkdG9rZW4sICRwYXlsb2FkKTsg
fQotICAgICAgICAkY3VybCA9IGN1cmxfaW5pdCgkdXJsKTsKLSAgICAgICAgJGhlYWRlcnMgPSBb
Ci0gICAgICAgICAgICAnQWNjZXB0OiBhcHBsaWNhdGlvbi9qc29uJywKLSAgICAgICAgICAgICdB
dXRob3JpemF0aW9uOiBCZWFyZXIgJyAuICR0b2tlbgotICAgICAgICBdOwotCi0gICAgICAgIGN1
cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX1JFVFVSTlRSQU5TRkVSLCB0cnVlKTsKLSAgICAgICAg
Y3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfQ1VTVE9NUkVRVUVTVCwgc3RydG91cHBlcigoc3Ry
aW5nKSRtZXRvZG8pKTsKLSAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfQ09OTkVD
VFRJTUVPVVQsIDEwKTsKLSAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfVElNRU9V
VCwgMzApOwotICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9TU0xfVkVSSUZZUEVF
UiwgdHJ1ZSk7Ci0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX1NTTF9WRVJJRllI
T1NULCAyKTsKLSAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfRk9MTE9XTE9DQVRJ
T04sIGZhbHNlKTsKLQotICAgICAgICBpZiAoaXNfYXJyYXkoJHBheWxvYWQpKSB7Ci0gICAgICAg
ICAgICAkanNvbiA9IGpzb25fZW5jb2RlKCRwYXlsb2FkLCBKU09OX1VORVNDQVBFRF9VTklDT0RF
IHwgSlNPTl9VTkVTQ0FQRURfU0xBU0hFUyk7Ci0gICAgICAgICAgICBpZiAoJGpzb24gPT09IGZh
bHNlKSB7Ci0gICAgICAgICAgICAgICAgY3VybF9jbG9zZSgkY3VybCk7Ci0gICAgICAgICAgICAg
ICAgcmV0dXJuIFsKLSAgICAgICAgICAgICAgICAgICAgJ29rJyA9PiBmYWxzZSwKLSAgICAgICAg
ICAgICAgICAgICAgJ2NvZGlnb19odHRwJyA9PiA1MDAKLSAgICAgICAgICAgICAgICBdOwotICAg
ICAgICAgICAgfQotICAgICAgICAgICAgJGhlYWRlcnNbXSA9ICdDb250ZW50LVR5cGU6IGFwcGxp
Y2F0aW9uL2pzb24nOwotICAgICAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfUE9T
VEZJRUxEUywgJGpzb24pOwotICAgICAgICB9Ci0KLSAgICAgICAgY3VybF9zZXRvcHQoJGN1cmws
IENVUkxPUFRfSFRUUEhFQURFUiwgJGhlYWRlcnMpOwotICAgICAgICAkcmVzcHVlc3RhID0gY3Vy
bF9leGVjKCRjdXJsKTsKLSAgICAgICAgJGNvZGlnbyA9IChpbnQpY3VybF9nZXRpbmZvKCRjdXJs
LCBDVVJMSU5GT19IVFRQX0NPREUpOwotICAgICAgICAkZXJyb3IgPSBjdXJsX2Vycm9yKCRjdXJs
KTsKLSAgICAgICAgY3VybF9jbG9zZSgkY3VybCk7Ci0KLSAgICAgICAgaWYgKCRyZXNwdWVzdGEg
PT09IGZhbHNlIHx8ICRlcnJvciAhPT0gJycpIHsKLSAgICAgICAgICAgIGVycm9yX2xvZygnTUFJ
TF9UUkFOU1BPUlQgaG9zdGluZ2VyX2Nvbm5lY3Rpb25fZmFpbGVkJyk7Ci0gICAgICAgICAgICBy
ZXR1cm4gWydvaycgPT4gZmFsc2UsICdjb2RpZ29faHR0cCcgPT4gNTAyLCAnaW5jaWVydG8nID0+
IHN0cnRvdXBwZXIoJG1ldG9kbykgPT09ICdQT1NUJ107Ci0gICAgICAgIH0KLQotICAgICAgICAk
anNvblJlc3B1ZXN0YSA9IGpzb25fZGVjb2RlKChzdHJpbmcpJHJlc3B1ZXN0YSwgdHJ1ZSk7Ci0g
ICAgICAgICRvayA9ICRjb2RpZ28gPj0gMjAwICYmICRjb2RpZ28gPCAzMDA7Ci0KLSAgICAgICAg
aWYgKCEkb2spIHsKLSAgICAgICAgICAgIGVycm9yX2xvZygnTUFJTF9UUkFOU1BPUlQgaG9zdGlu
Z2VyX2h0dHA9JyAuICRjb2RpZ28pOwotICAgICAgICB9Ci0KLSAgICAgICAgcmV0dXJuIFsKLSAg
ICAgICAgICAgICdvaycgPT4gJG9rLAotICAgICAgICAgICAgJ2NvZGlnb19odHRwJyA9PiAkY29k
aWdvID4gMCA/ICRjb2RpZ28gOiA1MDIsCi0gICAgICAgICAgICAnanNvbicgPT4gaXNfYXJyYXko
JGpzb25SZXNwdWVzdGEpID8gJGpzb25SZXNwdWVzdGEgOiBbXQotICAgICAgICBdOworICAgICAg
ICByZXR1cm4gJHRoaXMtPmhvc3Rpbmdlci0+c29saWNpdGFyKCRtZXRvZG8sICR1cmwsICR0b2tl
biwgJHBheWxvYWQpOwogICAgIH0KIAogICAgIHByaXZhdGUgZnVuY3Rpb24gY2FyZ2FySG9zdGlu
Z2VyKCkK
CENTRAL_PATCH_0_1
 [[ "$(digest "$TMP/patches/0/1.patch")" == '317a215f0606529a30bc13770d5d1b2edc08f80eaf4c9723b1fd7217b377a7df' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/0/2.patch" <<'CENTRAL_PATCH_0_2'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2Vydmlj
ZS5waHAgYi9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhw
Ci0tLSBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5waHAK
KysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocApA
QCAtMiw5ICsyLDcgQEAKIAogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0Zpcm1hQ29ycmVvU2Vy
dmljZS5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0N1ZW50YUNvbmVjdGFk
YVNlcnZpY2UucGhwJzsKLXJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9TbXRwVHJhbnNw
b3J0LnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvRW52aW9Db250cm9sLnBo
cCc7Ci1yZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvTGltaXRlU2VydmljZS5waHAnOwog
CiBjbGFzcyBDb3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZQogewpAQCAtMTcsNiArMTUs
NyBAQAogICAgIHByaXZhdGUgJG1haWxlckZhY3Rvcnk7CiAgICAgcHJpdmF0ZSAkY3VlbnRhczsK
ICAgICBwcml2YXRlICRhcGlSZXF1ZXN0OworICAgIHByaXZhdGUgJGhvc3RpbmdlcjsKIAogICAg
IHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0cnVjdCg/Y2FsbGFibGUgJG1haWxlckZhY3RvcnkgPSBu
dWxsLCA/Q29ycmVvQ3VlbnRhQ29uZWN0YWRhU2VydmljZSAkY3VlbnRhcyA9IG51bGwsID9jYWxs
YWJsZSAkYXBpUmVxdWVzdCA9IG51bGwpCiAgICAgewpAQCAtMjQsOCArMjMsMTcgQEAKICAgICAg
ICAgJHRoaXMtPm1haWxlckZhY3RvcnkgPSAkbWFpbGVyRmFjdG9yeTsKICAgICAgICAgJHRoaXMt
PmN1ZW50YXMgPSAkY3VlbnRhcyA/PyBuZXcgQ29ycmVvQ3VlbnRhQ29uZWN0YWRhU2VydmljZSgp
OwogICAgICAgICAkdGhpcy0+YXBpUmVxdWVzdCA9ICRhcGlSZXF1ZXN0OwotICAgICAgICAkdGhp
cy0+Y3VlbnRhcy0+Y29uZmlndXJhckNvbnN1bHRhQXBpKGZ1bmN0aW9uICgkbWV0aG9kLCR1cmws
JHRva2VuLCRwYXlsb2FkKSB7IHJldHVybiAkdGhpcy0+c29saWNpdGFySG9zdGluZ2VyKCRtZXRo
b2QsJHVybCwkdG9rZW4sJHBheWxvYWQpOyB9KTsKKyAgICAgICAgJHRoaXMtPmhvc3RpbmdlciA9
IG5ldyBDb3JyZW9Ib3N0aW5nZXJBcGlTZXJ2aWNlKCRhcGlSZXF1ZXN0KTsKKyAgICAgICAgJHRo
aXMtPmN1ZW50YXMtPmNvbmZpZ3VyYXJDb25zdWx0YUFwaShbJHRoaXMtPmhvc3RpbmdlciwgJ3Nv
bGljaXRhciddKTsKICAgICAgICAgJHRoaXMtPmZpcm1hU2VydmljZSA9IG5ldyBGaXJtYUNvcnJl
b1NlcnZpY2UoKTsKKyAgICB9CisKKyAgICBwdWJsaWMgZnVuY3Rpb24gcmVtaXRlbnRlcyhpbnQg
JHVzZXIpOiBhcnJheSB7IHJldHVybiAkdGhpcy0+Y3VlbnRhcy0+cmVtaXRlbnRlcygkdXNlcik7
IH0KKworICAgIHB1YmxpYyBmdW5jdGlvbiB2YWxpZGFyUmVtaXRlbnRlKHN0cmluZyAkZnJvbSwg
aW50ICR1c2VyKTogYXJyYXkKKyAgICB7CisgICAgICAgICRyPSR0aGlzLT5jdWVudGFzLT5yZXNv
bHZlcigkZnJvbSwkdXNlcik7CisgICAgICAgIHJldHVybiBlbXB0eSgkclsnb2snXSkgPyAkciA6
IFsnb2snPT50cnVlLCdyZW1pdGVudGUnPT4kZnJvbV07CiAgICAgfQogCiAgICAgcHVibGljIGZ1
bmN0aW9uIGVudmlhcigkZGF0b3MpCkBAIC0zNyw3ICs0NSw3IEBACiAgICAgICAgIC8qCiAgICAg
ICAgICAqIENvbXBhdGliaWxpZGFkIGNvbiBtw7NkdWxvcyBleGlzdGVudGVzOiBzaSBlbCBsbGFt
YWRvciB0b2RhdsOtYSBubwogICAgICAgICAgKiBtYW5kYSB1c3VhcmlvX2lkLCByZXV0aWxpemFt
b3MgZWwgdXN1YXJpbyBhdXRlbnRpY2FkbyBkZSBsYSBzZXNpw7NuLgotICAgICAgICAgKiBUb2Rv
cyBsb3MgZW52w61vcyBpbnRlcmFjdGl2b3MgY29uc2VydmFuIGxhIHJlZmVyZW5jaWEgZGUgc3Ug
dXN1YXJpby4KKyAgICAgICAgICogVG9kb3MgbG9zIGVudsOtb3MgY29tZXJjaWFsZXMgdmFsaWRh
biBlbCByZW1pdGVudGUgY29uIGxhIEFQSSBjZW50cmFsLgogICAgICAgICAgKi8KICAgICAgICAg
aWYgKCR1c3VhcmlvSWQgPD0gMCAmJiBpc3NldCgkX1NFU1NJT05bJ3VzdWFyaW9faWQnXSkpIHsK
ICAgICAgICAgICAgICR1c3VhcmlvSWQgPSAoaW50KSRfU0VTU0lPTlsndXN1YXJpb19pZCddOwpA
QCAtOTAsMjkgKzk4LDIxIEBACiAgICAgICAgIH0KICAgICAgICAgaWYgKGVtcHR5KCRjb25uZWN0
aW9uWydvayddKSkgeyByZXR1cm4gJGNvbm5lY3Rpb247IH0KICAgICAgICAgJGFjY291bnQgPSAk
Y29ubmVjdGlvblsnYWNjb3VudCddOwotICAgICAgICAkaWRlbnRpZGFkID0gJGNvbm5lY3Rpb25b
J2lkZW50aWRhZCddOwotICAgICAgICAkcnV0YUZpcm1hID0gJHRoaXMtPmZpcm1hU2VydmljZS0+
b2J0ZW5lclJ1dGEoJGlkZW50aWRhZFsnY29udGFjdG9fZmlybWEnXSk7Ci0gICAgICAgIGlmICgh
ZW1wdHkoJGRhdG9zWydjb25maXJtYXJfaWRlbnRpZGFkJ10pICYmICEkdGhpcy0+Y29uZmlybWFj
aW9uVmFsaWRhKCRpZGVudGlkYWQsIChzdHJpbmcpKCRkYXRvc1snaWRlbnRpZGFkX2VudmlvJ10g
Pz8gJycpKSkgewotICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lcnJvcignRWwgcmVtaXRlbnRl
IGluc3RpdHVjaW9uYWwgY2FtYmnDsyBvIHZlbmNpw7Mgc3UgY29tcHJvYmFjacOzbi4gUmV2aXNh
IGVsIHJlbWl0ZW50ZSB2aXNpYmxlIHkgdnVlbHZlIGEgZW52aWFyLicsIDQyMik7Ci0gICAgICAg
IH0KLSAgICAgICAgJHNlbmQgPSBmdW5jdGlvbiAoKSB1c2UgKCR1c3VhcmlvSWQsICRpZGVudGlk
YWQsICRhY2NvdW50LCAkcmVtaXRlbnRlLCAkbm9tYnJlUmVtaXRlbnRlLCAkZGVzdGluYXRhcmlv
LCAkbm9tYnJlRGVzdGluYXRhcmlvLAorICAgICAgICAkcnV0YUZpcm1hID0gJHRoaXMtPmZpcm1h
U2VydmljZS0+b2J0ZW5lclJ1dGEoJHJlbWl0ZW50ZSk7CisgICAgICAgICRzZW5kID0gZnVuY3Rp
b24gKCkgdXNlICgkYWNjb3VudCwgJHJlbWl0ZW50ZSwgJG5vbWJyZVJlbWl0ZW50ZSwgJGRlc3Rp
bmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJpbywKICAgICAgICAgICAgICRhc3VudG8sICRjdWVy
cG8sICRydXRhRmlybWEsICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9zLCAkaHRtbEFkaWNp
b25hbCkgewotICAgICAgICAgICAgaWYgKCFDb3JyZW9MaW1pdGVTZXJ2aWNlOjp0b21hcignY29t
bWVyY2lhbC1zZW5kJywgJHVzdWFyaW9JZCwgNjAsIDM2MDApKSB7IHJldHVybiAkdGhpcy0+ZXJy
b3IoJ1NlIGFsY2FuesOzIGVsIGzDrW1pdGUgZGUgZW52w61vcy4gRXNwZXJhIGFudGVzIGRlIHZv
bHZlciBhIGVudmlhci4nLCA0MjkpOyB9CiAgICAgICAgICAgICBpZiAoJGFjY291bnRbJ3RyYW5z
cG9ydCddID09PSAnaG9zdGluZ2VyX2FwaScpIHsKICAgICAgICAgICAgICAgICAkYmFzZSA9IHJ0
cmltKChzdHJpbmcpKCRhY2NvdW50WydhcGlfYmFzZV91cmwnXSA/PyBzZWxmOjpIT1NUSU5HRVJf
QkFTRV9ERUZBVUxUKSwgJy8nKTsKICAgICAgICAgICAgICAgICBpZiAoJGJhc2UgIT09IHNlbGY6
OkhPU1RJTkdFUl9CQVNFX0RFRkFVTFQgfHwgZW1wdHkoJGFjY291bnRbJ2FwaV90b2tlbiddKSkg
ewogICAgICAgICAgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCdGYWx0YSBsYSBjb25m
aWd1cmFjacOzbiBwcml2YWRhIGRlIGxhIEFQSSBIb3N0aW5nZXIgcGFyYSBlc3RhIGN1ZW50YS4n
LCA0MjIpOwogICAgICAgICAgICAgICAgIH0KLSAgICAgICAgICAgICAgICAkcmVzdWx0ID0gJHRo
aXMtPmVudmlhckhvc3RpbmdlcigkaWRlbnRpZGFkWydyZW1pdGVudGVfdmlzaWJsZSddLCAkaWRl
bnRpZGFkWydub21icmVfdmlzaWJsZSddLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRvLCAkY3VlcnBv
LCAkcnV0YUZpcm1hLAotICAgICAgICAgICAgICAgICAgICBbJ3Rva2VuJyA9PiAkYWNjb3VudFsn
YXBpX3Rva2VuJ10sICdiYXNlX3VybCcgPT4gJGJhc2VdLCAkaW1hZ2VuZXNFbWJlYmlkYXMsICRh
ZGp1bnRvcywgJGh0bWxBZGljaW9uYWwsIHRydWUsICRpZGVudGlkYWRbJ3JlbWl0ZW50ZV92aXNp
YmxlJ10pOwotICAgICAgICAgICAgfSBlbHNlIHsKLSAgICAgICAgICAgICAgICAkcmVzdWx0ID0g
JHRoaXMtPmVudmlhclNtdHAoJGlkZW50aWRhZFsncmVtaXRlbnRlX3Zpc2libGUnXSwgJGlkZW50
aWRhZFsnbm9tYnJlX3Zpc2libGUnXSwgJGRlc3RpbmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJp
bywgJGFzdW50bywgJGN1ZXJwbywKLSAgICAgICAgICAgICAgICAgICAgJHJ1dGFGaXJtYSwgJGlt
YWdlbmVzRW1iZWJpZGFzLCAkYWRqdW50b3MsICRodG1sQWRpY2lvbmFsLCBhcnJheV9yZXBsYWNl
KCRhY2NvdW50LCBbJ3JlcGx5X3RvJyA9PiAkaWRlbnRpZGFkWydyZXBseV90byddXSkpOwotICAg
ICAgICAgICAgfQotICAgICAgICAgICAgcmV0dXJuICRyZXN1bHQgKyAkaWRlbnRpZGFkOworICAg
ICAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZW52aWFySG9zdGluZ2VyKCRyZW1pdGVudGUsICRu
b21icmVSZW1pdGVudGUsICRkZXN0aW5hdGFyaW8sICRhc3VudG8sICRjdWVycG8sICRydXRhRmly
bWEsCisgICAgICAgICAgICAgICAgICAgIFsndG9rZW4nID0+ICRhY2NvdW50WydhcGlfdG9rZW4n
XSwgJ2Jhc2VfdXJsJyA9PiAkYmFzZV0sICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9zLCAk
aHRtbEFkaWNpb25hbCwgdHJ1ZSwgJHJlbWl0ZW50ZSk7CisgICAgICAgICAgICB9CisgICAgICAg
ICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCdFbCBlbnbDrW8gY29tZXJjaWFsIHPDs2xvIGFkbWl0
ZSBIb3N0aW5nZXIgTWFpbCBBUEkuJywgNDIyKTsKICAgICAgICAgfTsKICAgICAgICAgJGF0dGFj
aG1lbnRzID0gYXJyYXlfbWFwKHN0YXRpYyBmdW5jdGlvbiAoJGEpIHsgcmV0dXJuIFskYVsnbm9t
YnJlJ10sIGhhc2hfZmlsZSgnc2hhMjU2JywgJGFbJ3J1dGEnXSldOyB9LCBhcnJheV9tZXJnZSgk
YWRqdW50b3MsICRpbWFnZW5lc0VtYmViaWRhcykpOwotICAgICAgICAkZmluZ2VycHJpbnQgPSBo
YXNoKCdzaGEyNTYnLCBqc29uX2VuY29kZShbJHVzdWFyaW9JZCwgJGlkZW50aWRhZCwgJGRlc3Rp
bmF0YXJpbywgJGFzdW50bywgJGN1ZXJwbywgJGh0bWxBZGljaW9uYWwsICRhdHRhY2htZW50cywK
KyAgICAgICAgJGZpbmdlcnByaW50ID0gaGFzaCgnc2hhMjU2JywganNvbl9lbmNvZGUoWyR1c3Vh
cmlvSWQsICRyZW1pdGVudGUsICRkZXN0aW5hdGFyaW8sICRhc3VudG8sICRjdWVycG8sICRodG1s
QWRpY2lvbmFsLCAkYXR0YWNobWVudHMsCiAgICAgICAgICAgICBpc19maWxlKCRydXRhRmlybWEp
ID8gaGFzaF9maWxlKCdzaGEyNTYnLCAkcnV0YUZpcm1hKSA6ICcnXSkpOwogICAgICAgICAkZXhw
bGljaXQgPSB0cmltKChzdHJpbmcpKCRkYXRvc1snaWRlbXBvdGVuY3lfa2V5J10gPz8gJycpKTsK
ICAgICAgICAgaWYgKCRleHBsaWNpdCAhPT0gJycgJiYgIXByZWdfbWF0Y2goJy9eW2EtekEtWjAt
OTpfLi1dezgsMTgwfSQvRCcsICRleHBsaWNpdCkpIHsgcmV0dXJuICR0aGlzLT5lcnJvcignTGEg
c29saWNpdHVkIGRlIGVudsOtbyBubyBlcyB2w6FsaWRhLicsIDQyMik7IH0KQEAgLTEyMyw0NSAr
MTIzLDYgQEAKICAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJ05vIHNlIHB1ZG8gY29u
ZmlybWFyIHVuIGVudsOtbyBzZWd1cm8uIFJldmlzYSBlbCBwcm92ZWVkb3IgeSBlbCBoaXN0b3Jp
YWwgYW50ZXMgZGUgcmVwZXRpcmxvLicsIDUwMykKICAgICAgICAgICAgICAgICArIFsnZXN0YWRv
X2VudHJlZ2EnID0+ICdERVNDT05PQ0lETycsICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+IGZhbHNl
XTsKICAgICAgICAgfQotICAgIH0KLQotICAgIHB1YmxpYyBmdW5jdGlvbiBpZGVudGlkYWQoc3Ry
aW5nICRjb250YWN0bywgaW50ICR1c2VyKTogYXJyYXkKLSAgICB7Ci0gICAgICAgIHRyeSB7Ci0g
ICAgICAgICAgICBpZiAoIWZpbHRlcl92YXIoJGNvbnRhY3RvLCBGSUxURVJfVkFMSURBVEVfRU1B
SUwpKSB7IHJldHVybiAkdGhpcy0+ZXJyb3IoJ0luZ3Jlc2EgdW4gY29ycmVvIGRlIGNvbnRhY3Rv
IHbDoWxpZG8uJywgNDIyKTsgfQotICAgICAgICAgICAgJHIgPSAkdGhpcy0+Y3VlbnRhcy0+cmVz
b2x2ZXIoc3RydG9sb3dlcih0cmltKCRjb250YWN0bykpLCAkdXNlcik7Ci0gICAgICAgICAgICBp
ZiAoZW1wdHkoJHJbJ29rJ10pKSB7IHJldHVybiAkcjsgfQotICAgICAgICAgICAgJGlkZW50aXR5
ID0gJHJbJ2lkZW50aWRhZCddOyAkZXhwaXJlcyA9IHRpbWUoKSArIDYwMDsKLSAgICAgICAgICAg
ICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0eV9rZXknXSA9ICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0
eV9rZXknXSA/PyBiaW4yaGV4KHJhbmRvbV9ieXRlcygzMikpOwotICAgICAgICAgICAgJHRva2Vu
ID0gJGV4cGlyZXMgLiAnLicgLiBoYXNoX2htYWMoJ3NoYTI1NicsIGpzb25fZW5jb2RlKFskaWRl
bnRpdHksICRleHBpcmVzXSksICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0eV9rZXknXSk7Ci0gICAg
ICAgICAgICByZXR1cm4gWydvaycgPT4gdHJ1ZSwgJ2lkZW50aWRhZCcgPT4gJGlkZW50aXR5LCAn
aWRlbnRpZGFkX2VudmlvJyA9PiAkdG9rZW5dOwotICAgICAgICB9IGNhdGNoIChUaHJvd2FibGUg
JGUpIHsKLSAgICAgICAgICAgICRhbGxvd2VkID0gWydNQUlMX0NFTlRSQUxfQ09ORklHJywnTUFJ
TF9DRU5UUkFMX0ZST00nLCdNQUlMX0NFTlRSQUxfQVBJJywnTUFJTF9TTVRQX0NPTkZJRycsJ01B
SUxfU01UUF9JREVOVElUWScsJ01BSUxfT0FVVEhfQ09ORklHJywnTUFJTF9TVEFURV9ESVJFQ1RP
UlknLCdNQUlMX1NUQVRFX09QRU4nLCdNQUlMX1NUQVRFX0JVU1knXTsKLSAgICAgICAgICAgICRy
ZWFzb24gPSBpbl9hcnJheSgkZS0+Z2V0TWVzc2FnZSgpLCAkYWxsb3dlZCwgdHJ1ZSkgPyAkZS0+
Z2V0TWVzc2FnZSgpIDogJ01BSUxfSURFTlRJVFlfRkFJTEVEJzsKLSAgICAgICAgICAgIGVycm9y
X2xvZygnTUFJTF9JREVOVElUWSByZWFzb249JyAuICRyZWFzb24gLiAnIGV4Y2VwdGlvbj0nIC4g
Z2V0X2NsYXNzKCRlKSk7Ci0gICAgICAgICAgICAkbWVzc2FnZXMgPSBbCi0gICAgICAgICAgICAg
ICAgJ01BSUxfQ0VOVFJBTF9DT05GSUcnID0+ICdFbCBzZXJ2aWRvciBubyB0aWVuZSBjb25maWd1
cmFkYSB1bmEgY3VlbnRhIGRlIHNhbGlkYSB2w6FsaWRhLiBFbCBhZG1pbmlzdHJhZG9yIGRlYmUg
Y2FyZ2FyIGVsIHRva2VuIEhvc3RpbmdlciBvIGxhcyBjcmVkZW5jaWFsZXMgU01UUCBlbiBsYSBj
b25maWd1cmFjacOzbiBwcml2YWRhLicsCi0gICAgICAgICAgICAgICAgJ01BSUxfQ0VOVFJBTF9G
Uk9NJyA9PiAnVHUgY29ycmVvIG5vIGNvcnJlc3BvbmRlIGEgdW4gYnV6w7NuIEhvc3RpbmdlciBh
dXRvcml6YWRvLiBSZXZpc2EgbGEgYXV0b3JpemFjacOzbiBkZSBlc2EgY3VlbnRhIGNvbiBlbCBh
ZG1pbmlzdHJhZG9yLicsCi0gICAgICAgICAgICAgICAgJ01BSUxfQ0VOVFJBTF9BUEknID0+ICdI
b3N0aW5nZXIgbm8gcGVybWl0acOzIGNvbnN1bHRhciBsb3MgYnV6b25lcy4gUmV2aXNhIGVsIHRv
a2VuIGVzcGVjw61maWNvIGRlIE1haWwgQVBJIHkgc3UgYXV0b3JpemFjacOzbi4nLAotICAgICAg
ICAgICAgICAgICdNQUlMX09BVVRIX0NPTkZJRycgPT4gJ0ZhbHRhIGNvbmZpZ3VyYXIgbGEgYXBs
aWNhY2nDs24gR29vZ2xlIHBhcmEgZXN0ZSBHbWFpbC4gVGFtYmnDqW4gcHVlZGUgdXRpbGl6YXJz
ZSBTTVRQIGNvbiB1bmEgY2xhdmUgZGUgYXBsaWNhY2nDs24gY29uZmlndXJhZGEgcG9yIGVsIGFk
bWluaXN0cmFkb3IuJywKLSAgICAgICAgICAgICAgICAnTUFJTF9TTVRQX0NPTkZJRycgPT4gJ0xh
cyBjcmVkZW5jaWFsZXMgU01UUCBkZSBlc3RhIGN1ZW50YSBlc3TDoW4gaW5jb21wbGV0YXMuIEVs
IGFkbWluaXN0cmFkb3IgZGViZSByZXZpc2FyIHN1IGNvbmZpZ3VyYWNpw7NuIHByaXZhZGEuJwot
ICAgICAgICAgICAgXTsKLSAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJG1lc3NhZ2Vz
WyRyZWFzb25dID8/ICdObyBzZSBwdWRvIGNvbXByb2JhciBsYSBjdWVudGEgZGUgZW52w61vLiBT
b2xpY2l0YSByZXZpc2FyIGVsIGRpYWduw7NzdGljbyBwcml2YWRvIGRlbCBzZXJ2aWRvci4nLCA1
MDMpOwotICAgICAgICB9Ci0gICAgfQotCi0gICAgcHJpdmF0ZSBmdW5jdGlvbiBjb25maXJtYWNp
b25WYWxpZGEoYXJyYXkgJGlkZW50aXR5LCBzdHJpbmcgJHRva2VuKTogYm9vbAotICAgIHsKLSAg
ICAgICAgaWYgKCFwcmVnX21hdGNoKCcvXihbMC05XXsxMH0pXC4oW2EtZjAtOV17NjR9KSQvRCcs
ICR0b2tlbiwgJG0pIHx8IChpbnQpJG1bMV0gPCB0aW1lKCkgfHwgKGludCkkbVsxXSA+IHRpbWUo
KSArIDYwMCB8fCBlbXB0eSgkX1NFU1NJT05bJ21haWxfaWRlbnRpdHlfa2V5J10pKSB7IHJldHVy
biBmYWxzZTsgfQotICAgICAgICByZXR1cm4gaGFzaF9lcXVhbHMoaGFzaF9obWFjKCdzaGEyNTYn
LCBqc29uX2VuY29kZShbJGlkZW50aXR5LCAoaW50KSRtWzFdXSksICRfU0VTU0lPTlsnbWFpbF9p
ZGVudGl0eV9rZXknXSksICRtWzJdKTsKLSAgICB9Ci0KLSAgICBwdWJsaWMgZnVuY3Rpb24gY29t
cHJvYmFySWRlbnRpZGFkKHN0cmluZyAkY29udGFjdG8sIGludCAkdXNlciwgc3RyaW5nICR0b2tl
bik6IGFycmF5Ci0gICAgewotICAgICAgICAkciA9ICR0aGlzLT5pZGVudGlkYWQoJGNvbnRhY3Rv
LCAkdXNlcik7Ci0gICAgICAgIGlmIChlbXB0eSgkclsnb2snXSkpIHsgcmV0dXJuICRyOyB9Ci0g
ICAgICAgIHJldHVybiAkdGhpcy0+Y29uZmlybWFjaW9uVmFsaWRhKCRyWydpZGVudGlkYWQnXSwg
JHRva2VuKSA/IFsnb2snID0+IHRydWUsICdpZGVudGlkYWQnID0+ICRyWydpZGVudGlkYWQnXV0K
LSAgICAgICAgICAgIDogJHRoaXMtPmVycm9yKCdSZXZpc2EgZWwgcmVtaXRlbnRlIGluc3RpdHVj
aW9uYWwgdmlzaWJsZSBhbnRlcyBkZSBlbnZpYXIuJywgNDIyKTsKICAgICB9CiAKICAgICBwcml2
YXRlIGZ1bmN0aW9uIGVudmlhckhvc3RpbmdlcigKQEAgLTM1Myw3ICszMTQsNyBAQAogICAgICAg
ICAgICAgJHBheWxvYWQKICAgICAgICAgKTsKIAotICAgICAgICBpZiAoISgkcmVzcHVlc3RhWydv
ayddID8/IGZhbHNlKSkgeworICAgICAgICBpZiAoISgkcmVzcHVlc3RhWydvayddID8/IGZhbHNl
KSB8fCAoaW50KSgkcmVzcHVlc3RhWydjb2RpZ29faHR0cCddID8/IDApICE9PSAyMDQpIHsKICAg
ICAgICAgICAgICRjb2RlID0gKGludCkoJHJlc3B1ZXN0YVsnY29kaWdvX2h0dHAnXSA/PyA1MDIp
OwogICAgICAgICAgICAgJHVua25vd24gPSAhZW1wdHkoJHJlc3B1ZXN0YVsnaW5jaWVydG8nXSkg
fHwgJGNvZGUgPj0gNTAwIHx8ICRjb2RlIDwgNDAwIHx8ICRjb2RlID09PSA0MDg7CiAgICAgICAg
ICAgICByZXR1cm4gWydvaycgPT4gZmFsc2UsICdjb2RpZ29faHR0cCcgPT4gNTAyLCAncHJvdmVl
ZG9yJyA9PiAnSE9TVElOR0VSX01BSUxfQVBJJywKQEAgLTQwMiwxMCArMzYzLDggQEAKICAgICAg
ICAgdHJ5IHsKICAgICAgICAgICAgICRjb25maWdVc3VhcmlvID0gaXNfYXJyYXkoJGNvbmZpZ1Vz
dWFyaW8pID8gJGNvbmZpZ1VzdWFyaW8gOiBbXTsKICAgICAgICAgICAgICRtYWlsID0gJHRoaXMt
Pm1haWxlckZhY3RvcnkgPyAoJHRoaXMtPm1haWxlckZhY3RvcnkpKCkgOiBuZXcgXFBIUE1haWxl
clxQSFBNYWlsZXJcUEhQTWFpbGVyKHRydWUpOwotICAgICAgICAgICAgaWYgKCgkY29uZmlnVXN1
YXJpb1sndHJhbnNwb3J0J10gPz8gJycpID09PSAnZ21haWxfYXBpJykgeyAkbWFpbC0+Q2hhclNl
dCA9ICdVVEYtOCc7IH0KLSAgICAgICAgICAgIGVsc2UgeyBDb3JyZW9TbXRwVHJhbnNwb3J0Ojpj
b25maWd1cmFyKCRtYWlsLCAkY29uZmlnVXN1YXJpbyk7IH0KKyAgICAgICAgICAgIENvcnJlb1Nt
dHBUcmFuc3BvcnQ6OmNvbmZpZ3VyYXIoJG1haWwsICRjb25maWdVc3VhcmlvKTsKICAgICAgICAg
ICAgICRtYWlsLT5zZXRGcm9tKCRyZW1pdGVudGVVc3VhcmlvLCAkbm9tYnJlUmVtaXRlbnRlICE9
PSAnJyA/ICRub21icmVSZW1pdGVudGUgOiAkcmVtaXRlbnRlVXN1YXJpbyk7Ci0gICAgICAgICAg
ICBpZiAoIWVtcHR5KCRjb25maWdVc3VhcmlvWydyZXBseV90byddKSkgeyAkbWFpbC0+YWRkUmVw
bHlUbygkY29uZmlnVXN1YXJpb1sncmVwbHlfdG8nXSk7IH0KICAgICAgICAgICAgICRtYWlsLT5h
ZGRBZGRyZXNzKCRkZXN0aW5hdGFyaW8sICRub21icmVEZXN0aW5hdGFyaW8pOwogICAgICAgICAg
ICAgJGZpcm1hRGlzcG9uaWJsZSA9ICRydXRhRmlybWEgIT09ICcnICYmIGlzX2ZpbGUoJHJ1dGFG
aXJtYSk7CiAgICAgICAgICAgICBpZiAoJGZpcm1hRGlzcG9uaWJsZSkgewpAQCAtNDQ1LDkgKzQw
NCw3IEBACiAgICAgICAgICAgICAgICAgJGh0bWxBZGljaW9uYWwKICAgICAgICAgICAgICk7CiAg
ICAgICAgICAgICAkbWFpbC0+QWx0Qm9keSA9ICRjdWVycG87Ci0gICAgICAgICAgICAkb3V0Y29t
ZSA9ICgkY29uZmlnVXN1YXJpb1sndHJhbnNwb3J0J10gPz8gJycpID09PSAnZ21haWxfYXBpJwot
ICAgICAgICAgICAgICAgID8gQ29ycmVvR21haWxBcGlUcmFuc3BvcnQ6OmVudmlhcigkbWFpbCwg
JGNvbmZpZ1VzdWFyaW8sICR0aGlzLT5hcGlSZXF1ZXN0KQotICAgICAgICAgICAgICAgIDogQ29y
cmVvU210cFRyYW5zcG9ydDo6ZW52aWFyKCRtYWlsLCAnY29tbWVyY2lhbCcpOworICAgICAgICAg
ICAgJG91dGNvbWUgPSBDb3JyZW9TbXRwVHJhbnNwb3J0OjplbnZpYXIoJG1haWwsICdjb21tZXJj
aWFsJyk7CiAgICAgICAgICAgICBpZiAoZW1wdHkoJG91dGNvbWVbJ29rJ10pKSB7IHJldHVybiAk
b3V0Y29tZTsgfQogICAgICAgICAgICAgcmV0dXJuICRvdXRjb21lICsgWwogICAgICAgICAgICAg
ICAgICdmaXJtYV9pbmNsdWlkYScgPT4gJGZpcm1hRGlzcG9uaWJsZSwKQEAgLTY0Nyw1NyArNjA0
LDcgQEAKIAogICAgIHByaXZhdGUgZnVuY3Rpb24gc29saWNpdGFySG9zdGluZ2VyKCRtZXRvZG8s
ICR1cmwsICR0b2tlbiwgJHBheWxvYWQpCiAgICAgewotICAgICAgICBpZiAoJHRoaXMtPmFwaVJl
cXVlc3QpIHsgcmV0dXJuICgkdGhpcy0+YXBpUmVxdWVzdCkoJG1ldG9kbywgJHVybCwgJHRva2Vu
LCAkcGF5bG9hZCk7IH0KLSAgICAgICAgJGN1cmwgPSBjdXJsX2luaXQoJHVybCk7Ci0gICAgICAg
ICRoZWFkZXJzID0gWwotICAgICAgICAgICAgJ0FjY2VwdDogYXBwbGljYXRpb24vanNvbicsCi0g
ICAgICAgICAgICAnQXV0aG9yaXphdGlvbjogQmVhcmVyICcgLiAkdG9rZW4KLSAgICAgICAgXTsK
LQotICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9SRVRVUk5UUkFOU0ZFUiwgdHJ1
ZSk7Ci0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX0NVU1RPTVJFUVVFU1QsIHN0
cnRvdXBwZXIoKHN0cmluZykkbWV0b2RvKSk7Ci0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBD
VVJMT1BUX0NPTk5FQ1RUSU1FT1VULCAxMCk7Ci0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBD
VVJMT1BUX1RJTUVPVVQsIDMwKTsKLSAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRf
U1NMX1ZFUklGWVBFRVIsIHRydWUpOwotICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9Q
VF9TU0xfVkVSSUZZSE9TVCwgMik7Ci0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BU
X0ZPTExPV0xPQ0FUSU9OLCBmYWxzZSk7Ci0KLSAgICAgICAgaWYgKGlzX2FycmF5KCRwYXlsb2Fk
KSkgewotICAgICAgICAgICAgJGpzb24gPSBqc29uX2VuY29kZSgkcGF5bG9hZCwgSlNPTl9VTkVT
Q0FQRURfVU5JQ09ERSB8IEpTT05fVU5FU0NBUEVEX1NMQVNIRVMpOwotICAgICAgICAgICAgaWYg
KCRqc29uID09PSBmYWxzZSkgewotICAgICAgICAgICAgICAgIGN1cmxfY2xvc2UoJGN1cmwpOwot
ICAgICAgICAgICAgICAgIHJldHVybiBbCi0gICAgICAgICAgICAgICAgICAgICdvaycgPT4gZmFs
c2UsCi0gICAgICAgICAgICAgICAgICAgICdjb2RpZ29faHR0cCcgPT4gNTAwCi0gICAgICAgICAg
ICAgICAgXTsKLSAgICAgICAgICAgIH0KLSAgICAgICAgICAgICRoZWFkZXJzW10gPSAnQ29udGVu
dC1UeXBlOiBhcHBsaWNhdGlvbi9qc29uJzsKLSAgICAgICAgICAgIGN1cmxfc2V0b3B0KCRjdXJs
LCBDVVJMT1BUX1BPU1RGSUVMRFMsICRqc29uKTsKLSAgICAgICAgfQotCi0gICAgICAgIGN1cmxf
c2V0b3B0KCRjdXJsLCBDVVJMT1BUX0hUVFBIRUFERVIsICRoZWFkZXJzKTsKLSAgICAgICAgJHJl
c3B1ZXN0YSA9IGN1cmxfZXhlYygkY3VybCk7Ci0gICAgICAgICRjb2RpZ28gPSAoaW50KWN1cmxf
Z2V0aW5mbygkY3VybCwgQ1VSTElORk9fSFRUUF9DT0RFKTsKLSAgICAgICAgJGVycm9yID0gY3Vy
bF9lcnJvcigkY3VybCk7Ci0gICAgICAgIGN1cmxfY2xvc2UoJGN1cmwpOwotCi0gICAgICAgIGlm
ICgkcmVzcHVlc3RhID09PSBmYWxzZSB8fCAkZXJyb3IgIT09ICcnKSB7Ci0gICAgICAgICAgICBl
cnJvcl9sb2coJ01BSUxfVFJBTlNQT1JUIGhvc3Rpbmdlcl9jb25uZWN0aW9uX2ZhaWxlZCcpOwot
ICAgICAgICAgICAgcmV0dXJuIFsnb2snID0+IGZhbHNlLCAnY29kaWdvX2h0dHAnID0+IDUwMiwg
J2luY2llcnRvJyA9PiBzdHJ0b3VwcGVyKCRtZXRvZG8pID09PSAnUE9TVCddOwotICAgICAgICB9
Ci0KLSAgICAgICAgJGpzb25SZXNwdWVzdGEgPSBqc29uX2RlY29kZSgoc3RyaW5nKSRyZXNwdWVz
dGEsIHRydWUpOwotICAgICAgICAkb2sgPSAkY29kaWdvID49IDIwMCAmJiAkY29kaWdvIDwgMzAw
OwotCi0gICAgICAgIGlmICghJG9rKSB7Ci0gICAgICAgICAgICBlcnJvcl9sb2coJ01BSUxfVFJB
TlNQT1JUIGhvc3Rpbmdlcl9odHRwPScgLiAkY29kaWdvKTsKLSAgICAgICAgfQotCi0gICAgICAg
IHJldHVybiBbCi0gICAgICAgICAgICAnb2snID0+ICRvaywKLSAgICAgICAgICAgICdjb2RpZ29f
aHR0cCcgPT4gJGNvZGlnbyA+IDAgPyAkY29kaWdvIDogNTAyLAotICAgICAgICAgICAgJ2pzb24n
ID0+IGlzX2FycmF5KCRqc29uUmVzcHVlc3RhKSA/ICRqc29uUmVzcHVlc3RhIDogW10KLSAgICAg
ICAgXTsKKyAgICAgICAgcmV0dXJuICR0aGlzLT5ob3N0aW5nZXItPnNvbGljaXRhcigkbWV0b2Rv
LCAkdXJsLCAkdG9rZW4sICRwYXlsb2FkKTsKICAgICB9CiAKICAgICBwcml2YXRlIGZ1bmN0aW9u
IGNhcmdhckhvc3RpbmdlcigpCg==
CENTRAL_PATCH_0_2
 [[ "$(digest "$TMP/patches/0/2.patch")" == '7adb9644da7964327c0b6cf3ab97ad49bfaf15f1e84912ba922817ce5a19dddc' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/0/3.patch" <<'CENTRAL_PATCH_0_3'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2Vydmlj
ZS5waHAgYi9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhw
Ci0tLSBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5waHAK
KysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocApA
QCAtMiw5ICsyLDcgQEAKIAogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0Zpcm1hQ29ycmVvU2Vy
dmljZS5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0N1ZW50YUNvbmVjdGFk
YVNlcnZpY2UucGhwJzsKLXJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9TbXRwVHJhbnNw
b3J0LnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvRW52aW9Db250cm9sLnBo
cCc7Ci1yZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvTGltaXRlU2VydmljZS5waHAnOwog
CiBjbGFzcyBDb3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZQogewpAQCAtMTcsNiArMTUs
NyBAQAogICAgIHByaXZhdGUgJG1haWxlckZhY3Rvcnk7CiAgICAgcHJpdmF0ZSAkY3VlbnRhczsK
ICAgICBwcml2YXRlICRhcGlSZXF1ZXN0OworICAgIHByaXZhdGUgJGhvc3RpbmdlcjsKIAogICAg
IHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0cnVjdCg/Y2FsbGFibGUgJG1haWxlckZhY3RvcnkgPSBu
dWxsLCA/Q29ycmVvQ3VlbnRhQ29uZWN0YWRhU2VydmljZSAkY3VlbnRhcyA9IG51bGwsID9jYWxs
YWJsZSAkYXBpUmVxdWVzdCA9IG51bGwpCiAgICAgewpAQCAtMjQsOCArMjMsMTcgQEAKICAgICAg
ICAgJHRoaXMtPm1haWxlckZhY3RvcnkgPSAkbWFpbGVyRmFjdG9yeTsKICAgICAgICAgJHRoaXMt
PmN1ZW50YXMgPSAkY3VlbnRhcyA/PyBuZXcgQ29ycmVvQ3VlbnRhQ29uZWN0YWRhU2VydmljZSgp
OwogICAgICAgICAkdGhpcy0+YXBpUmVxdWVzdCA9ICRhcGlSZXF1ZXN0OwotICAgICAgICAkdGhp
cy0+Y3VlbnRhcy0+Y29uZmlndXJhckNvbnN1bHRhQXBpKGZ1bmN0aW9uICgkbWV0aG9kLCR1cmws
JHRva2VuLCRwYXlsb2FkKSB7IHJldHVybiAkdGhpcy0+c29saWNpdGFySG9zdGluZ2VyKCRtZXRo
b2QsJHVybCwkdG9rZW4sJHBheWxvYWQpOyB9KTsKKyAgICAgICAgJHRoaXMtPmhvc3RpbmdlciA9
IG5ldyBDb3JyZW9Ib3N0aW5nZXJBcGlTZXJ2aWNlKCRhcGlSZXF1ZXN0KTsKKyAgICAgICAgJHRo
aXMtPmN1ZW50YXMtPmNvbmZpZ3VyYXJDb25zdWx0YUFwaShbJHRoaXMtPmhvc3RpbmdlciwgJ3Nv
bGljaXRhciddKTsKICAgICAgICAgJHRoaXMtPmZpcm1hU2VydmljZSA9IG5ldyBGaXJtYUNvcnJl
b1NlcnZpY2UoKTsKKyAgICB9CisKKyAgICBwdWJsaWMgZnVuY3Rpb24gcmVtaXRlbnRlcyhpbnQg
JHVzZXIpOiBhcnJheSB7IHJldHVybiAkdGhpcy0+Y3VlbnRhcy0+cmVtaXRlbnRlcygkdXNlcik7
IH0KKworICAgIHB1YmxpYyBmdW5jdGlvbiB2YWxpZGFyUmVtaXRlbnRlKHN0cmluZyAkZnJvbSwg
aW50ICR1c2VyKTogYXJyYXkKKyAgICB7CisgICAgICAgICRyPSR0aGlzLT5jdWVudGFzLT5yZXNv
bHZlcigkZnJvbSwkdXNlcik7CisgICAgICAgIHJldHVybiBlbXB0eSgkclsnb2snXSkgPyAkciA6
IFsnb2snPT50cnVlLCdyZW1pdGVudGUnPT4kZnJvbV07CiAgICAgfQogCiAgICAgcHVibGljIGZ1
bmN0aW9uIGVudmlhcigkZGF0b3MpCkBAIC0zNyw3ICs0NSw3IEBACiAgICAgICAgIC8qCiAgICAg
ICAgICAqIENvbXBhdGliaWxpZGFkIGNvbiBtw7NkdWxvcyBleGlzdGVudGVzOiBzaSBlbCBsbGFt
YWRvciB0b2RhdsOtYSBubwogICAgICAgICAgKiBtYW5kYSB1c3VhcmlvX2lkLCByZXV0aWxpemFt
b3MgZWwgdXN1YXJpbyBhdXRlbnRpY2FkbyBkZSBsYSBzZXNpw7NuLgotICAgICAgICAgKiBUb2Rv
cyBsb3MgZW52w61vcyBpbnRlcmFjdGl2b3MgY29uc2VydmFuIGxhIHJlZmVyZW5jaWEgZGUgc3Ug
dXN1YXJpby4KKyAgICAgICAgICogVG9kb3MgbG9zIGVudsOtb3MgY29tZXJjaWFsZXMgdmFsaWRh
biBlbCByZW1pdGVudGUgY29uIGxhIEFQSSBjZW50cmFsLgogICAgICAgICAgKi8KICAgICAgICAg
aWYgKCR1c3VhcmlvSWQgPD0gMCAmJiBpc3NldCgkX1NFU1NJT05bJ3VzdWFyaW9faWQnXSkpIHsK
ICAgICAgICAgICAgICR1c3VhcmlvSWQgPSAoaW50KSRfU0VTU0lPTlsndXN1YXJpb19pZCddOwpA
QCAtOTAsMjkgKzk4LDIxIEBACiAgICAgICAgIH0KICAgICAgICAgaWYgKGVtcHR5KCRjb25uZWN0
aW9uWydvayddKSkgeyByZXR1cm4gJGNvbm5lY3Rpb247IH0KICAgICAgICAgJGFjY291bnQgPSAk
Y29ubmVjdGlvblsnYWNjb3VudCddOwotICAgICAgICAkaWRlbnRpZGFkID0gJGNvbm5lY3Rpb25b
J2lkZW50aWRhZCddOwotICAgICAgICAkcnV0YUZpcm1hID0gJHRoaXMtPmZpcm1hU2VydmljZS0+
b2J0ZW5lclJ1dGEoJGlkZW50aWRhZFsnY29udGFjdG9fZmlybWEnXSk7Ci0gICAgICAgIGlmICgh
ZW1wdHkoJGRhdG9zWydjb25maXJtYXJfaWRlbnRpZGFkJ10pICYmICEkdGhpcy0+Y29uZmlybWFj
aW9uVmFsaWRhKCRpZGVudGlkYWQsIChzdHJpbmcpKCRkYXRvc1snaWRlbnRpZGFkX2VudmlvJ10g
Pz8gJycpKSkgewotICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lcnJvcignRWwgcmVtaXRlbnRl
IGluc3RpdHVjaW9uYWwgY2FtYmnDsyBvIHZlbmNpw7Mgc3UgY29tcHJvYmFjacOzbi4gUmV2aXNh
IGVsIHJlbWl0ZW50ZSB2aXNpYmxlIHkgdnVlbHZlIGEgZW52aWFyLicsIDQyMik7Ci0gICAgICAg
IH0KLSAgICAgICAgJHNlbmQgPSBmdW5jdGlvbiAoKSB1c2UgKCR1c3VhcmlvSWQsICRpZGVudGlk
YWQsICRhY2NvdW50LCAkcmVtaXRlbnRlLCAkbm9tYnJlUmVtaXRlbnRlLCAkZGVzdGluYXRhcmlv
LCAkbm9tYnJlRGVzdGluYXRhcmlvLAorICAgICAgICAkcnV0YUZpcm1hID0gJHRoaXMtPmZpcm1h
U2VydmljZS0+b2J0ZW5lclJ1dGEoJHJlbWl0ZW50ZSk7CisgICAgICAgICRzZW5kID0gZnVuY3Rp
b24gKCkgdXNlICgkYWNjb3VudCwgJHJlbWl0ZW50ZSwgJG5vbWJyZVJlbWl0ZW50ZSwgJGRlc3Rp
bmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJpbywKICAgICAgICAgICAgICRhc3VudG8sICRjdWVy
cG8sICRydXRhRmlybWEsICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9zLCAkaHRtbEFkaWNp
b25hbCkgewotICAgICAgICAgICAgaWYgKCFDb3JyZW9MaW1pdGVTZXJ2aWNlOjp0b21hcignY29t
bWVyY2lhbC1zZW5kJywgJHVzdWFyaW9JZCwgNjAsIDM2MDApKSB7IHJldHVybiAkdGhpcy0+ZXJy
b3IoJ1NlIGFsY2FuesOzIGVsIGzDrW1pdGUgZGUgZW52w61vcy4gRXNwZXJhIGFudGVzIGRlIHZv
bHZlciBhIGVudmlhci4nLCA0MjkpOyB9CiAgICAgICAgICAgICBpZiAoJGFjY291bnRbJ3RyYW5z
cG9ydCddID09PSAnaG9zdGluZ2VyX2FwaScpIHsKICAgICAgICAgICAgICAgICAkYmFzZSA9IHJ0
cmltKChzdHJpbmcpKCRhY2NvdW50WydhcGlfYmFzZV91cmwnXSA/PyBzZWxmOjpIT1NUSU5HRVJf
QkFTRV9ERUZBVUxUKSwgJy8nKTsKICAgICAgICAgICAgICAgICBpZiAoJGJhc2UgIT09IHNlbGY6
OkhPU1RJTkdFUl9CQVNFX0RFRkFVTFQgfHwgZW1wdHkoJGFjY291bnRbJ2FwaV90b2tlbiddKSkg
ewogICAgICAgICAgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCdGYWx0YSBsYSBjb25m
aWd1cmFjacOzbiBwcml2YWRhIGRlIGxhIEFQSSBIb3N0aW5nZXIgcGFyYSBlc3RhIGN1ZW50YS4n
LCA0MjIpOwogICAgICAgICAgICAgICAgIH0KLSAgICAgICAgICAgICAgICAkcmVzdWx0ID0gJHRo
aXMtPmVudmlhckhvc3RpbmdlcigkaWRlbnRpZGFkWydyZW1pdGVudGVfdmlzaWJsZSddLCAkaWRl
bnRpZGFkWydub21icmVfdmlzaWJsZSddLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRvLCAkY3VlcnBv
LCAkcnV0YUZpcm1hLAotICAgICAgICAgICAgICAgICAgICBbJ3Rva2VuJyA9PiAkYWNjb3VudFsn
YXBpX3Rva2VuJ10sICdiYXNlX3VybCcgPT4gJGJhc2VdLCAkaW1hZ2VuZXNFbWJlYmlkYXMsICRh
ZGp1bnRvcywgJGh0bWxBZGljaW9uYWwsIHRydWUsICRpZGVudGlkYWRbJ3JlbWl0ZW50ZV92aXNp
YmxlJ10pOwotICAgICAgICAgICAgfSBlbHNlIHsKLSAgICAgICAgICAgICAgICAkcmVzdWx0ID0g
JHRoaXMtPmVudmlhclNtdHAoJGlkZW50aWRhZFsncmVtaXRlbnRlX3Zpc2libGUnXSwgJGlkZW50
aWRhZFsnbm9tYnJlX3Zpc2libGUnXSwgJGRlc3RpbmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJp
bywgJGFzdW50bywgJGN1ZXJwbywKLSAgICAgICAgICAgICAgICAgICAgJHJ1dGFGaXJtYSwgJGlt
YWdlbmVzRW1iZWJpZGFzLCAkYWRqdW50b3MsICRodG1sQWRpY2lvbmFsLCBhcnJheV9yZXBsYWNl
KCRhY2NvdW50LCBbJ3JlcGx5X3RvJyA9PiAkaWRlbnRpZGFkWydyZXBseV90byddXSkpOwotICAg
ICAgICAgICAgfQotICAgICAgICAgICAgcmV0dXJuICRyZXN1bHQgKyAkaWRlbnRpZGFkOworICAg
ICAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZW52aWFySG9zdGluZ2VyKCRyZW1pdGVudGUsICRu
b21icmVSZW1pdGVudGUsICRkZXN0aW5hdGFyaW8sICRhc3VudG8sICRjdWVycG8sICRydXRhRmly
bWEsCisgICAgICAgICAgICAgICAgICAgIFsndG9rZW4nID0+ICRhY2NvdW50WydhcGlfdG9rZW4n
XSwgJ2Jhc2VfdXJsJyA9PiAkYmFzZV0sICRpbWFnZW5lc0VtYmViaWRhcywgJGFkanVudG9zLCAk
aHRtbEFkaWNpb25hbCwgdHJ1ZSwgJHJlbWl0ZW50ZSk7CisgICAgICAgICAgICB9CisgICAgICAg
ICAgICByZXR1cm4gJHRoaXMtPmVycm9yKCdFbCBlbnbDrW8gY29tZXJjaWFsIHPDs2xvIGFkbWl0
ZSBIb3N0aW5nZXIgTWFpbCBBUEkuJywgNDIyKTsKICAgICAgICAgfTsKICAgICAgICAgJGF0dGFj
aG1lbnRzID0gYXJyYXlfbWFwKHN0YXRpYyBmdW5jdGlvbiAoJGEpIHsgcmV0dXJuIFskYVsnbm9t
YnJlJ10sIGhhc2hfZmlsZSgnc2hhMjU2JywgJGFbJ3J1dGEnXSldOyB9LCBhcnJheV9tZXJnZSgk
YWRqdW50b3MsICRpbWFnZW5lc0VtYmViaWRhcykpOwotICAgICAgICAkZmluZ2VycHJpbnQgPSBo
YXNoKCdzaGEyNTYnLCBqc29uX2VuY29kZShbJHVzdWFyaW9JZCwgJGlkZW50aWRhZCwgJGRlc3Rp
bmF0YXJpbywgJGFzdW50bywgJGN1ZXJwbywgJGh0bWxBZGljaW9uYWwsICRhdHRhY2htZW50cywK
KyAgICAgICAgJGZpbmdlcnByaW50ID0gaGFzaCgnc2hhMjU2JywganNvbl9lbmNvZGUoWyR1c3Vh
cmlvSWQsICRyZW1pdGVudGUsICRkZXN0aW5hdGFyaW8sICRhc3VudG8sICRjdWVycG8sICRodG1s
QWRpY2lvbmFsLCAkYXR0YWNobWVudHMsCiAgICAgICAgICAgICBpc19maWxlKCRydXRhRmlybWEp
ID8gaGFzaF9maWxlKCdzaGEyNTYnLCAkcnV0YUZpcm1hKSA6ICcnXSkpOwogICAgICAgICAkZXhw
bGljaXQgPSB0cmltKChzdHJpbmcpKCRkYXRvc1snaWRlbXBvdGVuY3lfa2V5J10gPz8gJycpKTsK
ICAgICAgICAgaWYgKCRleHBsaWNpdCAhPT0gJycgJiYgIXByZWdfbWF0Y2goJy9eW2EtekEtWjAt
OTpfLi1dezgsMTgwfSQvRCcsICRleHBsaWNpdCkpIHsgcmV0dXJuICR0aGlzLT5lcnJvcignTGEg
c29saWNpdHVkIGRlIGVudsOtbyBubyBlcyB2w6FsaWRhLicsIDQyMik7IH0KQEAgLTEyMywzNyAr
MTIzLDYgQEAKICAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJ05vIHNlIHB1ZG8gY29u
ZmlybWFyIHVuIGVudsOtbyBzZWd1cm8uIFJldmlzYSBlbCBwcm92ZWVkb3IgeSBlbCBoaXN0b3Jp
YWwgYW50ZXMgZGUgcmVwZXRpcmxvLicsIDUwMykKICAgICAgICAgICAgICAgICArIFsnZXN0YWRv
X2VudHJlZ2EnID0+ICdERVNDT05PQ0lETycsICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+IGZhbHNl
XTsKICAgICAgICAgfQotICAgIH0KLQotICAgIHB1YmxpYyBmdW5jdGlvbiBpZGVudGlkYWQoc3Ry
aW5nICRjb250YWN0bywgaW50ICR1c2VyKTogYXJyYXkKLSAgICB7Ci0gICAgICAgIHRyeSB7Ci0g
ICAgICAgICAgICBpZiAoIWZpbHRlcl92YXIoJGNvbnRhY3RvLCBGSUxURVJfVkFMSURBVEVfRU1B
SUwpKSB7IHJldHVybiAkdGhpcy0+ZXJyb3IoJ0luZ3Jlc2EgdW4gY29ycmVvIGRlIGNvbnRhY3Rv
IHbDoWxpZG8uJywgNDIyKTsgfQotICAgICAgICAgICAgJHIgPSAkdGhpcy0+Y3VlbnRhcy0+cmVz
b2x2ZXIoc3RydG9sb3dlcih0cmltKCRjb250YWN0bykpLCAkdXNlcik7Ci0gICAgICAgICAgICBp
ZiAoZW1wdHkoJHJbJ29rJ10pKSB7IHJldHVybiAkcjsgfQotICAgICAgICAgICAgJGlkZW50aXR5
ID0gJHJbJ2lkZW50aWRhZCddOyAkZXhwaXJlcyA9IHRpbWUoKSArIDYwMDsKLSAgICAgICAgICAg
ICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0eV9rZXknXSA9ICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0
eV9rZXknXSA/PyBiaW4yaGV4KHJhbmRvbV9ieXRlcygzMikpOwotICAgICAgICAgICAgJHRva2Vu
ID0gJGV4cGlyZXMgLiAnLicgLiBoYXNoX2htYWMoJ3NoYTI1NicsIGpzb25fZW5jb2RlKFskaWRl
bnRpdHksICRleHBpcmVzXSksICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0eV9rZXknXSk7Ci0gICAg
ICAgICAgICByZXR1cm4gWydvaycgPT4gdHJ1ZSwgJ2lkZW50aWRhZCcgPT4gJGlkZW50aXR5LCAn
aWRlbnRpZGFkX2VudmlvJyA9PiAkdG9rZW5dOwotICAgICAgICB9IGNhdGNoIChUaHJvd2FibGUg
JGUpIHsKLSAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJGUtPmdldE1lc3NhZ2UoKSA9
PT0gJ01BSUxfQ0VOVFJBTF9GUk9NJwotICAgICAgICAgICAgICAgID8gJ1R1IGNvcnJlbyBubyBj
b3JyZXNwb25kZSBhIHVuIGJ1esOzbiBIb3N0aW5nZXIgYXV0b3JpemFkby4gRWwgYWRtaW5pc3Ry
YWRvciBkZWJlIGluZGljYXIgbGEgY3VlbnRhIGluc3RpdHVjaW9uYWwgZGUgc2FsaWRhIHBhcmEg
ZXN0b3MgY29udGFjdG9zLicKLSAgICAgICAgICAgICAgICA6ICdObyBzZSBwdWRvIGNvbXByb2Jh
ciBlbCBjb3JyZW8gaW5zdGl0dWNpb25hbCBkZWwgc2lzdGVtYS4gU29saWNpdGEgcmV2aXNhciBz
dSBjb25maWd1cmFjacOzbiBwcml2YWRhIHkgYXV0b3JpemFjacOzbi4nLCA1MDMpOwotICAgICAg
ICB9Ci0gICAgfQotCi0gICAgcHJpdmF0ZSBmdW5jdGlvbiBjb25maXJtYWNpb25WYWxpZGEoYXJy
YXkgJGlkZW50aXR5LCBzdHJpbmcgJHRva2VuKTogYm9vbAotICAgIHsKLSAgICAgICAgaWYgKCFw
cmVnX21hdGNoKCcvXihbMC05XXsxMH0pXC4oW2EtZjAtOV17NjR9KSQvRCcsICR0b2tlbiwgJG0p
IHx8IChpbnQpJG1bMV0gPCB0aW1lKCkgfHwgKGludCkkbVsxXSA+IHRpbWUoKSArIDYwMCB8fCBl
bXB0eSgkX1NFU1NJT05bJ21haWxfaWRlbnRpdHlfa2V5J10pKSB7IHJldHVybiBmYWxzZTsgfQot
ICAgICAgICByZXR1cm4gaGFzaF9lcXVhbHMoaGFzaF9obWFjKCdzaGEyNTYnLCBqc29uX2VuY29k
ZShbJGlkZW50aXR5LCAoaW50KSRtWzFdXSksICRfU0VTU0lPTlsnbWFpbF9pZGVudGl0eV9rZXkn
XSksICRtWzJdKTsKLSAgICB9Ci0KLSAgICBwdWJsaWMgZnVuY3Rpb24gY29tcHJvYmFySWRlbnRp
ZGFkKHN0cmluZyAkY29udGFjdG8sIGludCAkdXNlciwgc3RyaW5nICR0b2tlbik6IGFycmF5Ci0g
ICAgewotICAgICAgICAkciA9ICR0aGlzLT5pZGVudGlkYWQoJGNvbnRhY3RvLCAkdXNlcik7Ci0g
ICAgICAgIGlmIChlbXB0eSgkclsnb2snXSkpIHsgcmV0dXJuICRyOyB9Ci0gICAgICAgIHJldHVy
biAkdGhpcy0+Y29uZmlybWFjaW9uVmFsaWRhKCRyWydpZGVudGlkYWQnXSwgJHRva2VuKSA/IFsn
b2snID0+IHRydWUsICdpZGVudGlkYWQnID0+ICRyWydpZGVudGlkYWQnXV0KLSAgICAgICAgICAg
IDogJHRoaXMtPmVycm9yKCdSZXZpc2EgZWwgcmVtaXRlbnRlIGluc3RpdHVjaW9uYWwgdmlzaWJs
ZSBhbnRlcyBkZSBlbnZpYXIuJywgNDIyKTsKICAgICB9CiAKICAgICBwcml2YXRlIGZ1bmN0aW9u
IGVudmlhckhvc3RpbmdlcigKQEAgLTM0NSw3ICszMTQsNyBAQAogICAgICAgICAgICAgJHBheWxv
YWQKICAgICAgICAgKTsKIAotICAgICAgICBpZiAoISgkcmVzcHVlc3RhWydvayddID8/IGZhbHNl
KSkgeworICAgICAgICBpZiAoISgkcmVzcHVlc3RhWydvayddID8/IGZhbHNlKSB8fCAoaW50KSgk
cmVzcHVlc3RhWydjb2RpZ29faHR0cCddID8/IDApICE9PSAyMDQpIHsKICAgICAgICAgICAgICRj
b2RlID0gKGludCkoJHJlc3B1ZXN0YVsnY29kaWdvX2h0dHAnXSA/PyA1MDIpOwogICAgICAgICAg
ICAgJHVua25vd24gPSAhZW1wdHkoJHJlc3B1ZXN0YVsnaW5jaWVydG8nXSkgfHwgJGNvZGUgPj0g
NTAwIHx8ICRjb2RlIDwgNDAwIHx8ICRjb2RlID09PSA0MDg7CiAgICAgICAgICAgICByZXR1cm4g
WydvaycgPT4gZmFsc2UsICdjb2RpZ29faHR0cCcgPT4gNTAyLCAncHJvdmVlZG9yJyA9PiAnSE9T
VElOR0VSX01BSUxfQVBJJywKQEAgLTM5NCwxMCArMzYzLDggQEAKICAgICAgICAgdHJ5IHsKICAg
ICAgICAgICAgICRjb25maWdVc3VhcmlvID0gaXNfYXJyYXkoJGNvbmZpZ1VzdWFyaW8pID8gJGNv
bmZpZ1VzdWFyaW8gOiBbXTsKICAgICAgICAgICAgICRtYWlsID0gJHRoaXMtPm1haWxlckZhY3Rv
cnkgPyAoJHRoaXMtPm1haWxlckZhY3RvcnkpKCkgOiBuZXcgXFBIUE1haWxlclxQSFBNYWlsZXJc
UEhQTWFpbGVyKHRydWUpOwotICAgICAgICAgICAgaWYgKCgkY29uZmlnVXN1YXJpb1sndHJhbnNw
b3J0J10gPz8gJycpID09PSAnZ21haWxfYXBpJykgeyAkbWFpbC0+Q2hhclNldCA9ICdVVEYtOCc7
IH0KLSAgICAgICAgICAgIGVsc2UgeyBDb3JyZW9TbXRwVHJhbnNwb3J0Ojpjb25maWd1cmFyKCRt
YWlsLCAkY29uZmlnVXN1YXJpbyk7IH0KKyAgICAgICAgICAgIENvcnJlb1NtdHBUcmFuc3BvcnQ6
OmNvbmZpZ3VyYXIoJG1haWwsICRjb25maWdVc3VhcmlvKTsKICAgICAgICAgICAgICRtYWlsLT5z
ZXRGcm9tKCRyZW1pdGVudGVVc3VhcmlvLCAkbm9tYnJlUmVtaXRlbnRlICE9PSAnJyA/ICRub21i
cmVSZW1pdGVudGUgOiAkcmVtaXRlbnRlVXN1YXJpbyk7Ci0gICAgICAgICAgICBpZiAoIWVtcHR5
KCRjb25maWdVc3VhcmlvWydyZXBseV90byddKSkgeyAkbWFpbC0+YWRkUmVwbHlUbygkY29uZmln
VXN1YXJpb1sncmVwbHlfdG8nXSk7IH0KICAgICAgICAgICAgICRtYWlsLT5hZGRBZGRyZXNzKCRk
ZXN0aW5hdGFyaW8sICRub21icmVEZXN0aW5hdGFyaW8pOwogICAgICAgICAgICAgJGZpcm1hRGlz
cG9uaWJsZSA9ICRydXRhRmlybWEgIT09ICcnICYmIGlzX2ZpbGUoJHJ1dGFGaXJtYSk7CiAgICAg
ICAgICAgICBpZiAoJGZpcm1hRGlzcG9uaWJsZSkgewpAQCAtNDM3LDkgKzQwNCw3IEBACiAgICAg
ICAgICAgICAgICAgJGh0bWxBZGljaW9uYWwKICAgICAgICAgICAgICk7CiAgICAgICAgICAgICAk
bWFpbC0+QWx0Qm9keSA9ICRjdWVycG87Ci0gICAgICAgICAgICAkb3V0Y29tZSA9ICgkY29uZmln
VXN1YXJpb1sndHJhbnNwb3J0J10gPz8gJycpID09PSAnZ21haWxfYXBpJwotICAgICAgICAgICAg
ICAgID8gQ29ycmVvR21haWxBcGlUcmFuc3BvcnQ6OmVudmlhcigkbWFpbCwgJGNvbmZpZ1VzdWFy
aW8sICR0aGlzLT5hcGlSZXF1ZXN0KQotICAgICAgICAgICAgICAgIDogQ29ycmVvU210cFRyYW5z
cG9ydDo6ZW52aWFyKCRtYWlsLCAnY29tbWVyY2lhbCcpOworICAgICAgICAgICAgJG91dGNvbWUg
PSBDb3JyZW9TbXRwVHJhbnNwb3J0OjplbnZpYXIoJG1haWwsICdjb21tZXJjaWFsJyk7CiAgICAg
ICAgICAgICBpZiAoZW1wdHkoJG91dGNvbWVbJ29rJ10pKSB7IHJldHVybiAkb3V0Y29tZTsgfQog
ICAgICAgICAgICAgcmV0dXJuICRvdXRjb21lICsgWwogICAgICAgICAgICAgICAgICdmaXJtYV9p
bmNsdWlkYScgPT4gJGZpcm1hRGlzcG9uaWJsZSwKQEAgLTYzOSw1NyArNjA0LDcgQEAKIAogICAg
IHByaXZhdGUgZnVuY3Rpb24gc29saWNpdGFySG9zdGluZ2VyKCRtZXRvZG8sICR1cmwsICR0b2tl
biwgJHBheWxvYWQpCiAgICAgewotICAgICAgICBpZiAoJHRoaXMtPmFwaVJlcXVlc3QpIHsgcmV0
dXJuICgkdGhpcy0+YXBpUmVxdWVzdCkoJG1ldG9kbywgJHVybCwgJHRva2VuLCAkcGF5bG9hZCk7
IH0KLSAgICAgICAgJGN1cmwgPSBjdXJsX2luaXQoJHVybCk7Ci0gICAgICAgICRoZWFkZXJzID0g
WwotICAgICAgICAgICAgJ0FjY2VwdDogYXBwbGljYXRpb24vanNvbicsCi0gICAgICAgICAgICAn
QXV0aG9yaXphdGlvbjogQmVhcmVyICcgLiAkdG9rZW4KLSAgICAgICAgXTsKLQotICAgICAgICBj
dXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9SRVRVUk5UUkFOU0ZFUiwgdHJ1ZSk7Ci0gICAgICAg
IGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX0NVU1RPTVJFUVVFU1QsIHN0cnRvdXBwZXIoKHN0
cmluZykkbWV0b2RvKSk7Ci0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX0NPTk5F
Q1RUSU1FT1VULCAxMCk7Ci0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX1RJTUVP
VVQsIDMwKTsKLSAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfU1NMX1ZFUklGWVBF
RVIsIHRydWUpOwotICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9TU0xfVkVSSUZZ
SE9TVCwgMik7Ci0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX0ZPTExPV0xPQ0FU
SU9OLCBmYWxzZSk7Ci0KLSAgICAgICAgaWYgKGlzX2FycmF5KCRwYXlsb2FkKSkgewotICAgICAg
ICAgICAgJGpzb24gPSBqc29uX2VuY29kZSgkcGF5bG9hZCwgSlNPTl9VTkVTQ0FQRURfVU5JQ09E
RSB8IEpTT05fVU5FU0NBUEVEX1NMQVNIRVMpOwotICAgICAgICAgICAgaWYgKCRqc29uID09PSBm
YWxzZSkgewotICAgICAgICAgICAgICAgIGN1cmxfY2xvc2UoJGN1cmwpOwotICAgICAgICAgICAg
ICAgIHJldHVybiBbCi0gICAgICAgICAgICAgICAgICAgICdvaycgPT4gZmFsc2UsCi0gICAgICAg
ICAgICAgICAgICAgICdjb2RpZ29faHR0cCcgPT4gNTAwCi0gICAgICAgICAgICAgICAgXTsKLSAg
ICAgICAgICAgIH0KLSAgICAgICAgICAgICRoZWFkZXJzW10gPSAnQ29udGVudC1UeXBlOiBhcHBs
aWNhdGlvbi9qc29uJzsKLSAgICAgICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX1BP
U1RGSUVMRFMsICRqc29uKTsKLSAgICAgICAgfQotCi0gICAgICAgIGN1cmxfc2V0b3B0KCRjdXJs
LCBDVVJMT1BUX0hUVFBIRUFERVIsICRoZWFkZXJzKTsKLSAgICAgICAgJHJlc3B1ZXN0YSA9IGN1
cmxfZXhlYygkY3VybCk7Ci0gICAgICAgICRjb2RpZ28gPSAoaW50KWN1cmxfZ2V0aW5mbygkY3Vy
bCwgQ1VSTElORk9fSFRUUF9DT0RFKTsKLSAgICAgICAgJGVycm9yID0gY3VybF9lcnJvcigkY3Vy
bCk7Ci0gICAgICAgIGN1cmxfY2xvc2UoJGN1cmwpOwotCi0gICAgICAgIGlmICgkcmVzcHVlc3Rh
ID09PSBmYWxzZSB8fCAkZXJyb3IgIT09ICcnKSB7Ci0gICAgICAgICAgICBlcnJvcl9sb2coJ01B
SUxfVFJBTlNQT1JUIGhvc3Rpbmdlcl9jb25uZWN0aW9uX2ZhaWxlZCcpOwotICAgICAgICAgICAg
cmV0dXJuIFsnb2snID0+IGZhbHNlLCAnY29kaWdvX2h0dHAnID0+IDUwMiwgJ2luY2llcnRvJyA9
PiBzdHJ0b3VwcGVyKCRtZXRvZG8pID09PSAnUE9TVCddOwotICAgICAgICB9Ci0KLSAgICAgICAg
JGpzb25SZXNwdWVzdGEgPSBqc29uX2RlY29kZSgoc3RyaW5nKSRyZXNwdWVzdGEsIHRydWUpOwot
ICAgICAgICAkb2sgPSAkY29kaWdvID49IDIwMCAmJiAkY29kaWdvIDwgMzAwOwotCi0gICAgICAg
IGlmICghJG9rKSB7Ci0gICAgICAgICAgICBlcnJvcl9sb2coJ01BSUxfVFJBTlNQT1JUIGhvc3Rp
bmdlcl9odHRwPScgLiAkY29kaWdvKTsKLSAgICAgICAgfQotCi0gICAgICAgIHJldHVybiBbCi0g
ICAgICAgICAgICAnb2snID0+ICRvaywKLSAgICAgICAgICAgICdjb2RpZ29faHR0cCcgPT4gJGNv
ZGlnbyA+IDAgPyAkY29kaWdvIDogNTAyLAotICAgICAgICAgICAgJ2pzb24nID0+IGlzX2FycmF5
KCRqc29uUmVzcHVlc3RhKSA/ICRqc29uUmVzcHVlc3RhIDogW10KLSAgICAgICAgXTsKKyAgICAg
ICAgcmV0dXJuICR0aGlzLT5ob3N0aW5nZXItPnNvbGljaXRhcigkbWV0b2RvLCAkdXJsLCAkdG9r
ZW4sICRwYXlsb2FkKTsKICAgICB9CiAKICAgICBwcml2YXRlIGZ1bmN0aW9uIGNhcmdhckhvc3Rp
bmdlcigpCg==
CENTRAL_PATCH_0_3
 [[ "$(digest "$TMP/patches/0/3.patch")" == '68bea0f42b86f0496172fb92efbb0ee0edc5bc08f924b33b1cd51731ea440d29' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/0/4.patch" <<'CENTRAL_PATCH_0_4'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2Vydmlj
ZS5waHAgYi9hcHAvc2VydmljZXMvQ29ycmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UucGhw
Ci0tLSBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZS5waHAK
KysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocApA
QCAtMiw3ICsyLDYgQEAKIAogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0Zpcm1hQ29ycmVvU2Vy
dmljZS5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnL0NvcnJlb0N1ZW50YUNvbmVjdGFk
YVNlcnZpY2UucGhwJzsKLXJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy9Db3JyZW9TbXRwVHJhbnNw
b3J0LnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvQ29ycmVvRW52aW9Db250cm9sLnBo
cCc7CiAKIGNsYXNzIENvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlCkBAIC0xNiw2ICsx
NSw3IEBACiAgICAgcHJpdmF0ZSAkbWFpbGVyRmFjdG9yeTsKICAgICBwcml2YXRlICRjdWVudGFz
OwogICAgIHByaXZhdGUgJGFwaVJlcXVlc3Q7CisgICAgcHJpdmF0ZSAkaG9zdGluZ2VyOwogCiAg
ICAgcHVibGljIGZ1bmN0aW9uIF9fY29uc3RydWN0KD9jYWxsYWJsZSAkbWFpbGVyRmFjdG9yeSA9
IG51bGwsID9Db3JyZW9DdWVudGFDb25lY3RhZGFTZXJ2aWNlICRjdWVudGFzID0gbnVsbCwgP2Nh
bGxhYmxlICRhcGlSZXF1ZXN0ID0gbnVsbCkKICAgICB7CkBAIC0yMyw3ICsyMywxNyBAQAogICAg
ICAgICAkdGhpcy0+bWFpbGVyRmFjdG9yeSA9ICRtYWlsZXJGYWN0b3J5OwogICAgICAgICAkdGhp
cy0+Y3VlbnRhcyA9ICRjdWVudGFzID8/IG5ldyBDb3JyZW9DdWVudGFDb25lY3RhZGFTZXJ2aWNl
KCk7CiAgICAgICAgICR0aGlzLT5hcGlSZXF1ZXN0ID0gJGFwaVJlcXVlc3Q7CisgICAgICAgICR0
aGlzLT5ob3N0aW5nZXIgPSBuZXcgQ29ycmVvSG9zdGluZ2VyQXBpU2VydmljZSgkYXBpUmVxdWVz
dCk7CisgICAgICAgICR0aGlzLT5jdWVudGFzLT5jb25maWd1cmFyQ29uc3VsdGFBcGkoWyR0aGlz
LT5ob3N0aW5nZXIsICdzb2xpY2l0YXInXSk7CiAgICAgICAgICR0aGlzLT5maXJtYVNlcnZpY2Ug
PSBuZXcgRmlybWFDb3JyZW9TZXJ2aWNlKCk7CisgICAgfQorCisgICAgcHVibGljIGZ1bmN0aW9u
IHJlbWl0ZW50ZXMoaW50ICR1c2VyKTogYXJyYXkgeyByZXR1cm4gJHRoaXMtPmN1ZW50YXMtPnJl
bWl0ZW50ZXMoJHVzZXIpOyB9CisKKyAgICBwdWJsaWMgZnVuY3Rpb24gdmFsaWRhclJlbWl0ZW50
ZShzdHJpbmcgJGZyb20sIGludCAkdXNlcik6IGFycmF5CisgICAgeworICAgICAgICAkcj0kdGhp
cy0+Y3VlbnRhcy0+cmVzb2x2ZXIoJGZyb20sJHVzZXIpOworICAgICAgICByZXR1cm4gZW1wdHko
JHJbJ29rJ10pID8gJHIgOiBbJ29rJz0+dHJ1ZSwncmVtaXRlbnRlJz0+JGZyb21dOwogICAgIH0K
IAogICAgIHB1YmxpYyBmdW5jdGlvbiBlbnZpYXIoJGRhdG9zKQpAQCAtMzUsNyArNDUsNyBAQAog
ICAgICAgICAvKgogICAgICAgICAgKiBDb21wYXRpYmlsaWRhZCBjb24gbcOzZHVsb3MgZXhpc3Rl
bnRlczogc2kgZWwgbGxhbWFkb3IgdG9kYXbDrWEgbm8KICAgICAgICAgICogbWFuZGEgdXN1YXJp
b19pZCwgcmV1dGlsaXphbW9zIGVsIHVzdWFyaW8gYXV0ZW50aWNhZG8gZGUgbGEgc2VzacOzbi4K
LSAgICAgICAgICogQXPDrSB0b2RvcyBsb3MgZW52w61vcyBpbnRlcmFjdGl2b3MgdXNhbiBsYSBj
dWVudGEgU01UUCBwZXJzb25hbC4KKyAgICAgICAgICogVG9kb3MgbG9zIGVudsOtb3MgY29tZXJj
aWFsZXMgdmFsaWRhbiBlbCByZW1pdGVudGUgY29uIGxhIEFQSSBjZW50cmFsLgogICAgICAgICAg
Ki8KICAgICAgICAgaWYgKCR1c3VhcmlvSWQgPD0gMCAmJiBpc3NldCgkX1NFU1NJT05bJ3VzdWFy
aW9faWQnXSkpIHsKICAgICAgICAgICAgICR1c3VhcmlvSWQgPSAoaW50KSRfU0VTU0lPTlsndXN1
YXJpb19pZCddOwpAQCAtODAsOCArOTAsNiBAQAogICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5l
cnJvcignRWwgYXN1bnRvIG8gZWwgbWVuc2FqZSBzdXBlcmEgZWwgdGFtYcOxbyBwZXJtaXRpZG8u
JywgNDIyKTsKICAgICAgICAgfQogCi0gICAgICAgICRydXRhRmlybWEgPSAkdGhpcy0+ZmlybWFT
ZXJ2aWNlLT5vYnRlbmVyUnV0YSgkcmVtaXRlbnRlKTsKLQogICAgICAgICB0cnkgewogICAgICAg
ICAgICAgJGNvbm5lY3Rpb24gPSAkdGhpcy0+Y3VlbnRhcy0+cmVzb2x2ZXIoJHJlbWl0ZW50ZSwg
JHVzdWFyaW9JZCwgJHBlcmZpbE1hcmtldGluZyk7CiAgICAgICAgIH0gY2F0Y2ggKFRocm93YWJs
ZSAkZXJyb3IpIHsKQEAgLTkwLDE0ICs5OCw5IEBACiAgICAgICAgIH0KICAgICAgICAgaWYgKGVt
cHR5KCRjb25uZWN0aW9uWydvayddKSkgeyByZXR1cm4gJGNvbm5lY3Rpb247IH0KICAgICAgICAg
JGFjY291bnQgPSAkY29ubmVjdGlvblsnYWNjb3VudCddOwotICAgICAgICAkc2VuZCA9IGZ1bmN0
aW9uICgpIHVzZSAoJHVzdWFyaW9JZCwgJGFjY291bnQsICRyZW1pdGVudGUsICRub21icmVSZW1p
dGVudGUsICRkZXN0aW5hdGFyaW8sICRub21icmVEZXN0aW5hdGFyaW8sCisgICAgICAgICRydXRh
RmlybWEgPSAkdGhpcy0+ZmlybWFTZXJ2aWNlLT5vYnRlbmVyUnV0YSgkcmVtaXRlbnRlKTsKKyAg
ICAgICAgJHNlbmQgPSBmdW5jdGlvbiAoKSB1c2UgKCRhY2NvdW50LCAkcmVtaXRlbnRlLCAkbm9t
YnJlUmVtaXRlbnRlLCAkZGVzdGluYXRhcmlvLCAkbm9tYnJlRGVzdGluYXRhcmlvLAogICAgICAg
ICAgICAgJGFzdW50bywgJGN1ZXJwbywgJHJ1dGFGaXJtYSwgJGltYWdlbmVzRW1iZWJpZGFzLCAk
YWRqdW50b3MsICRodG1sQWRpY2lvbmFsKSB7Ci0gICAgICAgICAgICAkbGltaXRzID0gaW1wZUxl
ZXJDb25maWdQcml2YWRhKCdtYWlsX2FjY291bnRzLmxvY2FsLnBocCcpWydsaW1pdHMnXSA/PyBb
XTsKLSAgICAgICAgICAgICRtYXhpbXVtID0gbWF4KDEsIG1pbig1MDAsIChpbnQpKCRsaW1pdHNb
J3Blcl91c2VyX2hvdXInXSA/PyA2MCkpKTsKLSAgICAgICAgICAgIGlmICghQ29ycmVvTGltaXRl
U2VydmljZTo6dG9tYXIoJ2NvbW1lcmNpYWwtc2VuZCcsICR1c3VhcmlvSWQsICRtYXhpbXVtLCAz
NjAwKSkgewotICAgICAgICAgICAgICAgIHJldHVybiAkdGhpcy0+ZXJyb3IoJ1NlIGFsY2FuesOz
IGVsIGzDrW1pdGUgZGUgZW52w61vcyBkZSB0dSB1c3VhcmlvLiBFc3BlcmEgYW50ZXMgZGUgaW5p
Y2lhciBvdHJvIGVudsOtby4nLCA0MjkpCi0gICAgICAgICAgICAgICAgICAgICsgWydlc3RhZG9f
ZW50cmVnYScgPT4gJ1JFQ0hBWkFETycsICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+IGZhbHNlXTsK
LSAgICAgICAgICAgIH0KICAgICAgICAgICAgIGlmICgkYWNjb3VudFsndHJhbnNwb3J0J10gPT09
ICdob3N0aW5nZXJfYXBpJykgewogICAgICAgICAgICAgICAgICRiYXNlID0gcnRyaW0oKHN0cmlu
ZykoJGFjY291bnRbJ2FwaV9iYXNlX3VybCddID8/IHNlbGY6OkhPU1RJTkdFUl9CQVNFX0RFRkFV
TFQpLCAnLycpOwogICAgICAgICAgICAgICAgIGlmICgkYmFzZSAhPT0gc2VsZjo6SE9TVElOR0VS
X0JBU0VfREVGQVVMVCB8fCBlbXB0eSgkYWNjb3VudFsnYXBpX3Rva2VuJ10pKSB7CkBAIC0xMDYs
OCArMTA5LDcgQEAKICAgICAgICAgICAgICAgICByZXR1cm4gJHRoaXMtPmVudmlhckhvc3Rpbmdl
cigkcmVtaXRlbnRlLCAkbm9tYnJlUmVtaXRlbnRlLCAkZGVzdGluYXRhcmlvLCAkYXN1bnRvLCAk
Y3VlcnBvLCAkcnV0YUZpcm1hLAogICAgICAgICAgICAgICAgICAgICBbJ3Rva2VuJyA9PiAkYWNj
b3VudFsnYXBpX3Rva2VuJ10sICdiYXNlX3VybCcgPT4gJGJhc2VdLCAkaW1hZ2VuZXNFbWJlYmlk
YXMsICRhZGp1bnRvcywgJGh0bWxBZGljaW9uYWwsIHRydWUsICRyZW1pdGVudGUpOwogICAgICAg
ICAgICAgfQotICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lbnZpYXJTbXRwKCRyZW1pdGVudGUs
ICRub21icmVSZW1pdGVudGUsICRkZXN0aW5hdGFyaW8sICRub21icmVEZXN0aW5hdGFyaW8sICRh
c3VudG8sICRjdWVycG8sCi0gICAgICAgICAgICAgICAgJHJ1dGFGaXJtYSwgJGltYWdlbmVzRW1i
ZWJpZGFzLCAkYWRqdW50b3MsICRodG1sQWRpY2lvbmFsLCAkYWNjb3VudCk7CisgICAgICAgICAg
ICByZXR1cm4gJHRoaXMtPmVycm9yKCdFbCBlbnbDrW8gY29tZXJjaWFsIHPDs2xvIGFkbWl0ZSBI
b3N0aW5nZXIgTWFpbCBBUEkuJywgNDIyKTsKICAgICAgICAgfTsKICAgICAgICAgJGF0dGFjaG1l
bnRzID0gYXJyYXlfbWFwKHN0YXRpYyBmdW5jdGlvbiAoJGEpIHsgcmV0dXJuIFskYVsnbm9tYnJl
J10sIGhhc2hfZmlsZSgnc2hhMjU2JywgJGFbJ3J1dGEnXSldOyB9LCBhcnJheV9tZXJnZSgkYWRq
dW50b3MsICRpbWFnZW5lc0VtYmViaWRhcykpOwogICAgICAgICAkZmluZ2VycHJpbnQgPSBoYXNo
KCdzaGEyNTYnLCBqc29uX2VuY29kZShbJHVzdWFyaW9JZCwgJHJlbWl0ZW50ZSwgJGRlc3RpbmF0
YXJpbywgJGFzdW50bywgJGN1ZXJwbywgJGh0bWxBZGljaW9uYWwsICRhdHRhY2htZW50cywKQEAg
LTIyMywxMCArMjI1LDYgQEAKICAgICAgICAgaWYgKCRyZXNvdXJjZUlkID09PSAnJykgewogICAg
ICAgICAgICAgcmV0dXJuIFsKICAgICAgICAgICAgICAgICAnb2snID0+IGZhbHNlLAotICAgICAg
ICAgICAgICAgICdjb2RpZ29faHR0cCcgPT4gNDIyLAotICAgICAgICAgICAgICAgICdlc3RhZG9f
ZW50cmVnYScgPT4gJ1JFQ0hBWkFETycsCi0gICAgICAgICAgICAgICAgJ2NvbmV4aW9uX3JlcXVl
cmlkYScgPT4gdHJ1ZSwKLSAgICAgICAgICAgICAgICAnY29uZXhpb25fdXJsJyA9PiAnaW5kZXgu
cGhwP2NvbnRyb2xsZXI9Y29ycmVvQ29uZXhpb24mYWN0aW9uPWluZGV4JmNvcnJlbz0nIC4gcmF3
dXJsZW5jb2RlKCRyZW1pdGVudGUpLAogICAgICAgICAgICAgICAgICdtZW5zYWplJyA9PiAkdXNh
clJlbWl0ZW50ZUV4YWN0bwogICAgICAgICAgICAgICAgICAgICA/ICdFbCBjb3JyZW8gZGUgb3Jp
Z2VuIG5vIGVzdMOhIGF1dG9yaXphZG8gY29tbyBjdWVudGEgZGUgc2FsaWRhLiBVc2EgdW5hIGN1
ZW50YSBjb25maWd1cmFkYSBlbiBlbCBzaXN0ZW1hIG8gdW4gYnV6w7NuIGF1dG9yaXphZG8gZGUg
SG9zdGluZ2VyLicKICAgICAgICAgICAgICAgICAgICAgOiAnTm8gaGF5IG5pbmfDum4gYnV6w7Nu
IGRlIHNhbGlkYSBhdXRvcml6YWRvIGRpc3BvbmlibGUuJwpAQCAtMzE2LDcgKzMxNCw3IEBACiAg
ICAgICAgICAgICAkcGF5bG9hZAogICAgICAgICApOwogCi0gICAgICAgIGlmICghKCRyZXNwdWVz
dGFbJ29rJ10gPz8gZmFsc2UpKSB7CisgICAgICAgIGlmICghKCRyZXNwdWVzdGFbJ29rJ10gPz8g
ZmFsc2UpIHx8IChpbnQpKCRyZXNwdWVzdGFbJ2NvZGlnb19odHRwJ10gPz8gMCkgIT09IDIwNCkg
ewogICAgICAgICAgICAgJGNvZGUgPSAoaW50KSgkcmVzcHVlc3RhWydjb2RpZ29faHR0cCddID8/
IDUwMik7CiAgICAgICAgICAgICAkdW5rbm93biA9ICFlbXB0eSgkcmVzcHVlc3RhWydpbmNpZXJ0
byddKSB8fCAkY29kZSA+PSA1MDAgfHwgJGNvZGUgPCA0MDAgfHwgJGNvZGUgPT09IDQwODsKICAg
ICAgICAgICAgIHJldHVybiBbJ29rJyA9PiBmYWxzZSwgJ2NvZGlnb19odHRwJyA9PiA1MDIsICdw
cm92ZWVkb3InID0+ICdIT1NUSU5HRVJfTUFJTF9BUEknLApAQCAtMzY1LDggKzM2Myw3IEBACiAg
ICAgICAgIHRyeSB7CiAgICAgICAgICAgICAkY29uZmlnVXN1YXJpbyA9IGlzX2FycmF5KCRjb25m
aWdVc3VhcmlvKSA/ICRjb25maWdVc3VhcmlvIDogW107CiAgICAgICAgICAgICAkbWFpbCA9ICR0
aGlzLT5tYWlsZXJGYWN0b3J5ID8gKCR0aGlzLT5tYWlsZXJGYWN0b3J5KSgpIDogbmV3IFxQSFBN
YWlsZXJcUEhQTWFpbGVyXFBIUE1haWxlcih0cnVlKTsKLSAgICAgICAgICAgIGlmICgoJGNvbmZp
Z1VzdWFyaW9bJ3RyYW5zcG9ydCddID8/ICcnKSA9PT0gJ2dtYWlsX2FwaScpIHsgJG1haWwtPkNo
YXJTZXQgPSAnVVRGLTgnOyB9Ci0gICAgICAgICAgICBlbHNlIHsgQ29ycmVvU210cFRyYW5zcG9y
dDo6Y29uZmlndXJhcigkbWFpbCwgJGNvbmZpZ1VzdWFyaW8pOyB9CisgICAgICAgICAgICBDb3Jy
ZW9TbXRwVHJhbnNwb3J0Ojpjb25maWd1cmFyKCRtYWlsLCAkY29uZmlnVXN1YXJpbyk7CiAgICAg
ICAgICAgICAkbWFpbC0+c2V0RnJvbSgkcmVtaXRlbnRlVXN1YXJpbywgJG5vbWJyZVJlbWl0ZW50
ZSAhPT0gJycgPyAkbm9tYnJlUmVtaXRlbnRlIDogJHJlbWl0ZW50ZVVzdWFyaW8pOwogICAgICAg
ICAgICAgJG1haWwtPmFkZEFkZHJlc3MoJGRlc3RpbmF0YXJpbywgJG5vbWJyZURlc3RpbmF0YXJp
byk7CiAgICAgICAgICAgICAkZmlybWFEaXNwb25pYmxlID0gJHJ1dGFGaXJtYSAhPT0gJycgJiYg
aXNfZmlsZSgkcnV0YUZpcm1hKTsKQEAgLTQwNyw5ICs0MDQsNyBAQAogICAgICAgICAgICAgICAg
ICRodG1sQWRpY2lvbmFsCiAgICAgICAgICAgICApOwogICAgICAgICAgICAgJG1haWwtPkFsdEJv
ZHkgPSAkY3VlcnBvOwotICAgICAgICAgICAgJG91dGNvbWUgPSAoJGNvbmZpZ1VzdWFyaW9bJ3Ry
YW5zcG9ydCddID8/ICcnKSA9PT0gJ2dtYWlsX2FwaScKLSAgICAgICAgICAgICAgICA/IENvcnJl
b0dtYWlsQXBpVHJhbnNwb3J0OjplbnZpYXIoJG1haWwsICRjb25maWdVc3VhcmlvLCAkdGhpcy0+
YXBpUmVxdWVzdCkKLSAgICAgICAgICAgICAgICA6IENvcnJlb1NtdHBUcmFuc3BvcnQ6OmVudmlh
cigkbWFpbCwgJ2NvbW1lcmNpYWwnKTsKKyAgICAgICAgICAgICRvdXRjb21lID0gQ29ycmVvU210
cFRyYW5zcG9ydDo6ZW52aWFyKCRtYWlsLCAnY29tbWVyY2lhbCcpOwogICAgICAgICAgICAgaWYg
KGVtcHR5KCRvdXRjb21lWydvayddKSkgeyByZXR1cm4gJG91dGNvbWU7IH0KICAgICAgICAgICAg
IHJldHVybiAkb3V0Y29tZSArIFsKICAgICAgICAgICAgICAgICAnZmlybWFfaW5jbHVpZGEnID0+
ICRmaXJtYURpc3BvbmlibGUsCkBAIC02MDksNTcgKzYwNCw3IEBACiAKICAgICBwcml2YXRlIGZ1
bmN0aW9uIHNvbGljaXRhckhvc3RpbmdlcigkbWV0b2RvLCAkdXJsLCAkdG9rZW4sICRwYXlsb2Fk
KQogICAgIHsKLSAgICAgICAgaWYgKCR0aGlzLT5hcGlSZXF1ZXN0KSB7IHJldHVybiAoJHRoaXMt
PmFwaVJlcXVlc3QpKCRtZXRvZG8sICR1cmwsICR0b2tlbiwgJHBheWxvYWQpOyB9Ci0gICAgICAg
ICRjdXJsID0gY3VybF9pbml0KCR1cmwpOwotICAgICAgICAkaGVhZGVycyA9IFsKLSAgICAgICAg
ICAgICdBY2NlcHQ6IGFwcGxpY2F0aW9uL2pzb24nLAotICAgICAgICAgICAgJ0F1dGhvcml6YXRp
b246IEJlYXJlciAnIC4gJHRva2VuCi0gICAgICAgIF07Ci0KLSAgICAgICAgY3VybF9zZXRvcHQo
JGN1cmwsIENVUkxPUFRfUkVUVVJOVFJBTlNGRVIsIHRydWUpOwotICAgICAgICBjdXJsX3NldG9w
dCgkY3VybCwgQ1VSTE9QVF9DVVNUT01SRVFVRVNULCBzdHJ0b3VwcGVyKChzdHJpbmcpJG1ldG9k
bykpOwotICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9DT05ORUNUVElNRU9VVCwg
MTApOwotICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9USU1FT1VULCAzMCk7Ci0g
ICAgICAgIGN1cmxfc2V0b3B0KCRjdXJsLCBDVVJMT1BUX1NTTF9WRVJJRllQRUVSLCB0cnVlKTsK
LSAgICAgICAgY3VybF9zZXRvcHQoJGN1cmwsIENVUkxPUFRfU1NMX1ZFUklGWUhPU1QsIDIpOwot
ICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9GT0xMT1dMT0NBVElPTiwgZmFsc2Up
OwotCi0gICAgICAgIGlmIChpc19hcnJheSgkcGF5bG9hZCkpIHsKLSAgICAgICAgICAgICRqc29u
ID0ganNvbl9lbmNvZGUoJHBheWxvYWQsIEpTT05fVU5FU0NBUEVEX1VOSUNPREUgfCBKU09OX1VO
RVNDQVBFRF9TTEFTSEVTKTsKLSAgICAgICAgICAgIGlmICgkanNvbiA9PT0gZmFsc2UpIHsKLSAg
ICAgICAgICAgICAgICBjdXJsX2Nsb3NlKCRjdXJsKTsKLSAgICAgICAgICAgICAgICByZXR1cm4g
WwotICAgICAgICAgICAgICAgICAgICAnb2snID0+IGZhbHNlLAotICAgICAgICAgICAgICAgICAg
ICAnY29kaWdvX2h0dHAnID0+IDUwMAotICAgICAgICAgICAgICAgIF07Ci0gICAgICAgICAgICB9
Ci0gICAgICAgICAgICAkaGVhZGVyc1tdID0gJ0NvbnRlbnQtVHlwZTogYXBwbGljYXRpb24vanNv
bic7Ci0gICAgICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9QT1NURklFTERTLCAk
anNvbik7Ci0gICAgICAgIH0KLQotICAgICAgICBjdXJsX3NldG9wdCgkY3VybCwgQ1VSTE9QVF9I
VFRQSEVBREVSLCAkaGVhZGVycyk7Ci0gICAgICAgICRyZXNwdWVzdGEgPSBjdXJsX2V4ZWMoJGN1
cmwpOwotICAgICAgICAkY29kaWdvID0gKGludCljdXJsX2dldGluZm8oJGN1cmwsIENVUkxJTkZP
X0hUVFBfQ09ERSk7Ci0gICAgICAgICRlcnJvciA9IGN1cmxfZXJyb3IoJGN1cmwpOwotICAgICAg
ICBjdXJsX2Nsb3NlKCRjdXJsKTsKLQotICAgICAgICBpZiAoJHJlc3B1ZXN0YSA9PT0gZmFsc2Ug
fHwgJGVycm9yICE9PSAnJykgewotICAgICAgICAgICAgZXJyb3JfbG9nKCdNQUlMX1RSQU5TUE9S
VCBob3N0aW5nZXJfY29ubmVjdGlvbl9mYWlsZWQnKTsKLSAgICAgICAgICAgIHJldHVybiBbJ29r
JyA9PiBmYWxzZSwgJ2NvZGlnb19odHRwJyA9PiA1MDIsICdpbmNpZXJ0bycgPT4gc3RydG91cHBl
cigkbWV0b2RvKSA9PT0gJ1BPU1QnXTsKLSAgICAgICAgfQotCi0gICAgICAgICRqc29uUmVzcHVl
c3RhID0ganNvbl9kZWNvZGUoKHN0cmluZykkcmVzcHVlc3RhLCB0cnVlKTsKLSAgICAgICAgJG9r
ID0gJGNvZGlnbyA+PSAyMDAgJiYgJGNvZGlnbyA8IDMwMDsKLQotICAgICAgICBpZiAoISRvaykg
ewotICAgICAgICAgICAgZXJyb3JfbG9nKCdNQUlMX1RSQU5TUE9SVCBob3N0aW5nZXJfaHR0cD0n
IC4gJGNvZGlnbyk7Ci0gICAgICAgIH0KLQotICAgICAgICByZXR1cm4gWwotICAgICAgICAgICAg
J29rJyA9PiAkb2ssCi0gICAgICAgICAgICAnY29kaWdvX2h0dHAnID0+ICRjb2RpZ28gPiAwID8g
JGNvZGlnbyA6IDUwMiwKLSAgICAgICAgICAgICdqc29uJyA9PiBpc19hcnJheSgkanNvblJlc3B1
ZXN0YSkgPyAkanNvblJlc3B1ZXN0YSA6IFtdCi0gICAgICAgIF07CisgICAgICAgIHJldHVybiAk
dGhpcy0+aG9zdGluZ2VyLT5zb2xpY2l0YXIoJG1ldG9kbywgJHVybCwgJHRva2VuLCAkcGF5bG9h
ZCk7CiAgICAgfQogCiAgICAgcHJpdmF0ZSBmdW5jdGlvbiBjYXJnYXJIb3N0aW5nZXIoKQo=
CENTRAL_PATCH_0_4
 [[ "$(digest "$TMP/patches/0/4.patch")" == '0c586a5c5d194fc83e38c9d644460ffc4f9be6990f9fe89f7af789e61f49814a' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/1"
 base64 --decode > "$TMP/patches/1/1.patch" <<'CENTRAL_PATCH_1_1'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocCBiL2Fw
cC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocAotLS0gYS9hcHAvc2VydmljZXMv
Q29ycmVvTWFya2V0aW5nU2VydmljZS5waHAKKysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb01hcmtl
dGluZ1NlcnZpY2UucGhwCkBAIC03MzAsNiArNzMwLDkgQEAKICAgICAgICAgICAgICAgICA0MjIK
ICAgICAgICAgICAgICk7CiAgICAgICAgIH0KKworICAgICAgICAkYXV0b3JpemFjaW9uID0gJHRo
aXMtPnNlbmRlci0+dmFsaWRhclJlbWl0ZW50ZSgkcmVtaXRlbnRlLCAkdXN1YXJpb0lkKTsKKyAg
ICAgICAgaWYgKGVtcHR5KCRhdXRvcml6YWNpb25bJ29rJ10pKSB7IHJldHVybiAkYXV0b3JpemFj
aW9uOyB9CiAKICAgICAgICAgJHByZXBhcmFjaW9uID0gJHRoaXMtPnByZXBhcmFyQWRqdW50b3Mo
JGFyY2hpdm9zKTsKICAgICAgICAgaWYgKCEoJHByZXBhcmFjaW9uWydvayddID8/IGZhbHNlKSkg
ewo=
CENTRAL_PATCH_1_1
 [[ "$(digest "$TMP/patches/1/1.patch")" == 'ac91044df4e98917e16243a70efc8be85f0902b8ff8074053de10712e15961a9' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/1/2.patch" <<'CENTRAL_PATCH_1_2'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocCBiL2Fw
cC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocAotLS0gYS9hcHAvc2VydmljZXMv
Q29ycmVvTWFya2V0aW5nU2VydmljZS5waHAKKysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb01hcmtl
dGluZ1NlcnZpY2UucGhwCkBAIC0zLDcgKzMsNiBAQAogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAn
Ly4uLy4uL2NvbmZpZy9kYl9jb25uZWN0aW9uLnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAu
ICcvLi4vbW9kZWxzL1VzdWFyaW9Nb2RlbC5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAn
L0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocCc7Ci1yZXF1aXJlX29uY2UgX19E
SVJfXyAuICcvQ29ycmVvUmVnaXN0cm9Fc3RhZG9TZXJ2aWNlLnBocCc7CiAKIGNsYXNzIENvcnJl
b01hcmtldGluZ1NlcnZpY2UKIHsKQEAgLTkxLDcgKzkwLDYgQEAKICAgICAgICAgICAgIH0KIAog
ICAgICAgICAgICAgJHRpbWVzdGFtcCA9ICRmZWNoYSAhPT0gJycgPyBzdHJ0b3RpbWUoJGZlY2hh
KSA6IGZhbHNlOwotICAgICAgICAgICAgJHRyYW5zcG9ydFN0YXRlID0gQ29ycmVvUmVnaXN0cm9F
c3RhZG9TZXJ2aWNlOjpjb25zdWx0YXIoJHVzdWFyaW9JZCwgKGludCkkZmlsYVsnaWQnXSwgKHN0
cmluZykkZmlsYVsnZXN0YWRvJ10pOwogCiAgICAgICAgICAgICAkc2FsaWRhW10gPSBbCiAgICAg
ICAgICAgICAgICAgJ2lkJyA9PiAoaW50KSgkZmlsYVsnaWQnXSA/PyAwKSwKQEAgLTEwMywxMCAr
MTAxLDcgQEAKICAgICAgICAgICAgICAgICAndGlwbycgPT4gJHRoaXMtPmV0aXF1ZXRhVGlwbygk
ZmlsYVsndGlwbyddID8/ICcnKSwKICAgICAgICAgICAgICAgICAndGlwb19jb2RpZ28nID0+IHN0
cnRvbG93ZXIoKHN0cmluZykoJGZpbGFbJ3RpcG8nXSA/PyAnZ2VuZXJhbCcpKSwKICAgICAgICAg
ICAgICAgICAnZmVjaGEnID0+ICR0aW1lc3RhbXAgIT09IGZhbHNlID8gZGF0ZSgnZC9tL1kgSDpp
JywgJHRpbWVzdGFtcCkgOiAn4oCUJywKLSAgICAgICAgICAgICAgICAnZXN0YWRvJyA9PiAkdHJh
bnNwb3J0U3RhdGVbJ2V0aXF1ZXRhX2VudmlvJ10gPz8gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRm
aWxhWydlc3RhZG8nXSA/PyAnJyksCi0gICAgICAgICAgICAgICAgJ2VzdGFkb19lbnZpbycgPT4g
JHRyYW5zcG9ydFN0YXRlWydlc3RhZG9fZW52aW8nXSwKLSAgICAgICAgICAgICAgICAncmVtaXRl
bnRlX3Zpc2libGUnID0+ICR0cmFuc3BvcnRTdGF0ZVsncmVtaXRlbnRlX3Zpc2libGUnXSwgJ3Jl
cGx5X3RvJyA9PiAkdHJhbnNwb3J0U3RhdGVbJ3JlcGx5X3RvJ10sCi0gICAgICAgICAgICAgICAg
J2VudHJlZ2FfY29uZmlybWFkYScgPT4gZmFsc2UsCisgICAgICAgICAgICAgICAgJ2VzdGFkbycg
PT4gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydlc3RhZG8nXSA/PyAnJyksCiAgICAgICAg
ICAgICAgICAgJ2VzdGFkb19jb2RpZ28nID0+IHN0cnRvbG93ZXIoKHN0cmluZykoJGZpbGFbJ2Vz
dGFkbyddID8/ICdwZW5kaWVudGUnKSksCiAgICAgICAgICAgICAgICAgJ2FkanVudG9zX2NvdW50
JyA9PiAoaW50KSgkZmlsYVsnYWRqdW50b3NfY291bnQnXSA/PyAwKQogICAgICAgICAgICAgXTsK
QEAgLTIxNyw3ICsyMTIsNiBAQAogICAgICAgICAgICAgXTsKICAgICAgICAgfQogCi0gICAgICAg
ICR0cmFuc3BvcnRTdGF0ZSA9IENvcnJlb1JlZ2lzdHJvRXN0YWRvU2VydmljZTo6Y29uc3VsdGFy
KCR1c3VhcmlvSWQsIChpbnQpJGZpbGFbJ2lkJ10sIChzdHJpbmcpJGZpbGFbJ2VzdGFkbyddKTsK
ICAgICAgICAgcmV0dXJuIFsKICAgICAgICAgICAgICdpZCcgPT4gKGludCkkZmlsYVsnaWQnXSwK
ICAgICAgICAgICAgICdkZXN0aW5hdGFyaW8nID0+IChzdHJpbmcpKCRmaWxhWydkZXN0aW5hdGFy
aW8nXSA/PyAnJyksCkBAIC0yMjYsMTEgKzIyMCw3IEBACiAgICAgICAgICAgICAnYXN1bnRvJyA9
PiAoc3RyaW5nKSgkZmlsYVsnYXN1bnRvJ10gPz8gJycpLAogICAgICAgICAgICAgJ2N1ZXJwbycg
PT4gKHN0cmluZykoJGZpbGFbJ2N1ZXJwbyddID8/ICcnKSwKICAgICAgICAgICAgICd0aXBvJyA9
PiAkdGhpcy0+ZXRpcXVldGFUaXBvKCRmaWxhWyd0aXBvJ10gPz8gJycpLAotICAgICAgICAgICAg
J2VzdGFkbycgPT4gJHRyYW5zcG9ydFN0YXRlWydldGlxdWV0YV9lbnZpbyddID8/ICR0aGlzLT5l
dGlxdWV0YUVzdGFkbygkZmlsYVsnZXN0YWRvJ10gPz8gJycpLAotICAgICAgICAgICAgJ2VzdGFk
b19lbnZpbycgPT4gJHRyYW5zcG9ydFN0YXRlWydlc3RhZG9fZW52aW8nXSwKLSAgICAgICAgICAg
ICdlbnRyZWdhX2NvbmZpcm1hZGEnID0+IGZhbHNlLAotICAgICAgICAgICAgJ3JlZ2lzdHJvX2Nv
bmZpcm1hZG8nID0+ICR0cmFuc3BvcnRTdGF0ZVsncmVnaXN0cm9fY29uZmlybWFkbyddLAotICAg
ICAgICAgICAgJ3JlbWl0ZW50ZV92aXNpYmxlJyA9PiAkdHJhbnNwb3J0U3RhdGVbJ3JlbWl0ZW50
ZV92aXNpYmxlJ10sICdyZXBseV90bycgPT4gJHRyYW5zcG9ydFN0YXRlWydyZXBseV90byddLAor
ICAgICAgICAgICAgJ2VzdGFkbycgPT4gJHRoaXMtPmV0aXF1ZXRhRXN0YWRvKCRmaWxhWydlc3Rh
ZG8nXSA/PyAnJyksCiAgICAgICAgICAgICAnZXN0YWRvX2NvZGlnbycgPT4gc3RydG9sb3dlcigK
ICAgICAgICAgICAgICAgICB0cmltKChzdHJpbmcpKCRmaWxhWydlc3RhZG8nXSA/PyAncGVuZGll
bnRlJykpCiAgICAgICAgICAgICApLApAQCAtNzQxLDggKzczMSw4IEBACiAgICAgICAgICAgICAp
OwogICAgICAgICB9CiAKLSAgICAgICAgJGlkZW50aWRhZCA9ICR0aGlzLT5zZW5kZXItPmNvbXBy
b2JhcklkZW50aWRhZCgkcmVtaXRlbnRlLCAkdXN1YXJpb0lkLCAoc3RyaW5nKSgkZGF0b3NbJ2lk
ZW50aWRhZF9lbnZpbyddID8/ICcnKSk7Ci0gICAgICAgIGlmIChlbXB0eSgkaWRlbnRpZGFkWydv
ayddKSkgeyByZXR1cm4gJGlkZW50aWRhZDsgfQorICAgICAgICAkYXV0b3JpemFjaW9uID0gJHRo
aXMtPnNlbmRlci0+dmFsaWRhclJlbWl0ZW50ZSgkcmVtaXRlbnRlLCAkdXN1YXJpb0lkKTsKKyAg
ICAgICAgaWYgKGVtcHR5KCRhdXRvcml6YWNpb25bJ29rJ10pKSB7IHJldHVybiAkYXV0b3JpemFj
aW9uOyB9CiAKICAgICAgICAgJHByZXBhcmFjaW9uID0gJHRoaXMtPnByZXBhcmFyQWRqdW50b3Mo
JGFyY2hpdm9zKTsKICAgICAgICAgaWYgKCEoJHByZXBhcmFjaW9uWydvayddID8/IGZhbHNlKSkg
ewpAQCAtNzk5LDE0ICs3ODksOSBAQAogICAgICAgICAgICAgICAgIH0KICAgICAgICAgICAgIH0K
IAotICAgICAgICAgICAgaWYgKCRyZWdpc3Ryb0lkIDw9IDApIHsgcmV0dXJuICR0aGlzLT5lcnJv
cignTm8gc2UgcHVkbyByZWdpc3RyYXIgbGEgc29saWNpdHVkIGVuIGxhIGJhbmRlamEuIEVsIGNv
cnJlbyBubyBzZSBoYSBlbnZpYWRvLicsIDUwMyk7IH0KLSAgICAgICAgICAgIENvcnJlb1JlZ2lz
dHJvRXN0YWRvU2VydmljZTo6Z3VhcmRhcigkdXN1YXJpb0lkLCAkcmVnaXN0cm9JZCwgJ1BFTkRJ
RU5URScpOwotICAgICAgICAgICAgQ29ycmVvUmVnaXN0cm9Fc3RhZG9TZXJ2aWNlOjpndWFyZGFy
KCR1c3VhcmlvSWQsICRyZWdpc3Ryb0lkLCAnRU5fUFJPQ0VTTycpOwogICAgICAgICAgICAgJGVu
dmlvID0gJHRoaXMtPnNlbmRlci0+ZW52aWFyKFsKICAgICAgICAgICAgICAgICAndXN1YXJpb19p
ZCcgPT4gJHVzdWFyaW9JZCwKICAgICAgICAgICAgICAgICAncGVyZmlsX21hcmtldGluZycgPT4g
dHJ1ZSwKLSAgICAgICAgICAgICAgICAnY29uZmlybWFyX2lkZW50aWRhZCcgPT4gdHJ1ZSwKLSAg
ICAgICAgICAgICAgICAnaWRlbnRpZGFkX2VudmlvJyA9PiAoc3RyaW5nKSgkZGF0b3NbJ2lkZW50
aWRhZF9lbnZpbyddID8/ICcnKSwKICAgICAgICAgICAgICAgICAnaWRlbXBvdGVuY3lfa2V5JyA9
PiAkZGF0b3NbJ2lkZW1wb3RlbmN5X2tleSddLAogICAgICAgICAgICAgICAgICd1c2FyX3JlbWl0
ZW50ZV9leGFjdG8nID0+IHRydWUsCiAgICAgICAgICAgICAgICAgJ3JlbWl0ZW50ZScgPT4gJHJl
bWl0ZW50ZSwKQEAgLTgyMSwxMSArODA2LDYgQEAKICAgICAgICAgICAgICAgICAnYWRqdW50b3Mn
ID0+ICRhZGp1bnRvc0VudmlvCiAgICAgICAgICAgICBdKTsKIAotICAgICAgICAgICAgJHRyYW5z
cG9ydFN0YXR1cyA9ICFlbXB0eSgkZW52aW9bJ29rJ10pID8gJ0FDRVBUQURPJyA6ICgoJGVudmlv
Wydlc3RhZG9fZW50cmVnYSddID8/ICcnKSA9PT0gJ0RFU0NPTk9DSURPJyA/ICdERVNDT05PQ0lE
TycgOiAnRkFMTElETycpOwotICAgICAgICAgICAgLy8gVW4gZHVwbGljYWRvIGFjZXB0YWRvIHRp
ZW5lIG9rPWZhbHNlOiBjb25zZXJ2YXIgZXNhIGV2aWRlbmNpYSB5IG5vIHRyYXRhcmxvIGNvbW8g
cmVjaGF6by4KLSAgICAgICAgICAgIGlmICgoJGVudmlvWydlc3RhZG9fZW50cmVnYSddID8/ICcn
KSA9PT0gJ0FDRVBUQURPJykgeyAkdHJhbnNwb3J0U3RhdHVzID0gJ0FDRVBUQURPJzsgfQotICAg
ICAgICAgICAgQ29ycmVvUmVnaXN0cm9Fc3RhZG9TZXJ2aWNlOjpndWFyZGFyKCR1c3VhcmlvSWQs
ICRyZWdpc3Ryb0lkLCAkdHJhbnNwb3J0U3RhdHVzLCAkZW52aW8pOwotCiAgICAgICAgICAgICBp
ZiAoISgkZW52aW9bJ29rJ10gPz8gZmFsc2UpKSB7CiAgICAgICAgICAgICAgICAgaWYgKCRyZWdp
c3Ryb0lkID4gMCkgewogICAgICAgICAgICAgICAgICAgICAkdGhpcy0+YWN0dWFsaXphclJlZ2lz
dHJvRXJyb3IoCkBAIC04NjAsMTQgKzg0MCwxMyBAQAogCiAgICAgICAgICAgICByZXR1cm4gWwog
ICAgICAgICAgICAgICAgICdvaycgPT4gdHJ1ZSwKLSAgICAgICAgICAgICAgICAnbWVuc2FqZScg
PT4gJ0VsIHByb3ZlZWRvciBhY2VwdMOzIGVsIGNvcnJlbyBkZXNkZSAnIC4gKCRlbnZpb1sncmVt
aXRlbnRlX3Zpc2libGUnXSA/PyAnbGEgY3VlbnRhIGluc3RpdHVjaW9uYWwnKSAuICcuIExhIGVu
dHJlZ2EgYcO6biBubyBlc3TDoSBjb25maXJtYWRhLicsCisgICAgICAgICAgICAgICAgJ21lbnNh
amUnID0+ICdFbCBwcm92ZWVkb3IgYWNlcHTDsyBlbCBjb3JyZW8uIExhIGVudHJlZ2EgYWwgZGVz
dGluYXRhcmlvIGHDum4gbm8gZXN0w6EgY29uZmlybWFkYS4nLAogICAgICAgICAgICAgICAgICdl
c3RhZG9fZW50cmVnYScgPT4gJ0FDRVBUQURPJywKICAgICAgICAgICAgICAgICAnZW50cmVnYV9j
b25maXJtYWRhJyA9PiBmYWxzZSwKICAgICAgICAgICAgICAgICAnY29ycmVvX2lkJyA9PiAkcmVn
aXN0cm9JZCwKICAgICAgICAgICAgICAgICAncHJvdmVlZG9yJyA9PiAoc3RyaW5nKSgkZW52aW9b
J3Byb3ZlZWRvciddID8/ICcnKSwKICAgICAgICAgICAgICAgICAnZmlybWFfaW5jbHVpZGEnID0+
IChib29sKSgkZW52aW9bJ2Zpcm1hX2luY2x1aWRhJ10gPz8gZmFsc2UpLAotICAgICAgICAgICAg
ICAgICdhZGp1bnRvcycgPT4gY291bnQoJGFkanVudG9zRW52aW8pLAotICAgICAgICAgICAgICAg
ICdyZW1pdGVudGVfdmlzaWJsZScgPT4gJGVudmlvWydyZW1pdGVudGVfdmlzaWJsZSddID8/ICcn
LCAncmVwbHlfdG8nID0+ICRlbnZpb1sncmVwbHlfdG8nXSA/PyAnJworICAgICAgICAgICAgICAg
ICdhZGp1bnRvcycgPT4gY291bnQoJGFkanVudG9zRW52aW8pCiAgICAgICAgICAgICBdOwogICAg
ICAgICB9IGZpbmFsbHkgewogICAgICAgICAgICAgJHRoaXMtPmxpbXBpYXJUZW1wb3JhbGVzKCRy
dXRhc1RlbXBvcmFsZXMpOwo=
CENTRAL_PATCH_1_2
 [[ "$(digest "$TMP/patches/1/2.patch")" == 'b43169446787cf170764980d4575820928add3b814572e7c4ac3b40dfacd1c26' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/1/3.patch" <<'CENTRAL_PATCH_1_3'
ZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocCBiL2Fw
cC9zZXJ2aWNlcy9Db3JyZW9NYXJrZXRpbmdTZXJ2aWNlLnBocAotLS0gYS9hcHAvc2VydmljZXMv
Q29ycmVvTWFya2V0aW5nU2VydmljZS5waHAKKysrIGIvYXBwL3NlcnZpY2VzL0NvcnJlb01hcmtl
dGluZ1NlcnZpY2UucGhwCkBAIC0zLDcgKzMsNiBAQAogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAn
Ly4uLy4uL2NvbmZpZy9kYl9jb25uZWN0aW9uLnBocCc7CiByZXF1aXJlX29uY2UgX19ESVJfXyAu
ICcvLi4vbW9kZWxzL1VzdWFyaW9Nb2RlbC5waHAnOwogcmVxdWlyZV9vbmNlIF9fRElSX18gLiAn
L0NvcnJlb1NhbGlkYUluc3RpdHVjaW9uYWxTZXJ2aWNlLnBocCc7Ci1yZXF1aXJlX29uY2UgX19E
SVJfXyAuICcvQ29ycmVvUmVnaXN0cm9Fc3RhZG9TZXJ2aWNlLnBocCc7CiAKIGNsYXNzIENvcnJl
b01hcmtldGluZ1NlcnZpY2UKIHsKQEAgLTkxLDcgKzkwLDYgQEAKICAgICAgICAgICAgIH0KIAog
ICAgICAgICAgICAgJHRpbWVzdGFtcCA9ICRmZWNoYSAhPT0gJycgPyBzdHJ0b3RpbWUoJGZlY2hh
KSA6IGZhbHNlOwotICAgICAgICAgICAgJHRyYW5zcG9ydFN0YXRlID0gQ29ycmVvUmVnaXN0cm9F
c3RhZG9TZXJ2aWNlOjpjb25zdWx0YXIoJHVzdWFyaW9JZCwgKGludCkkZmlsYVsnaWQnXSwgKHN0
cmluZykkZmlsYVsnZXN0YWRvJ10pOwogCiAgICAgICAgICAgICAkc2FsaWRhW10gPSBbCiAgICAg
ICAgICAgICAgICAgJ2lkJyA9PiAoaW50KSgkZmlsYVsnaWQnXSA/PyAwKSwKQEAgLTEwMyw5ICsx
MDEsNyBAQAogICAgICAgICAgICAgICAgICd0aXBvJyA9PiAkdGhpcy0+ZXRpcXVldGFUaXBvKCRm
aWxhWyd0aXBvJ10gPz8gJycpLAogICAgICAgICAgICAgICAgICd0aXBvX2NvZGlnbycgPT4gc3Ry
dG9sb3dlcigoc3RyaW5nKSgkZmlsYVsndGlwbyddID8/ICdnZW5lcmFsJykpLAogICAgICAgICAg
ICAgICAgICdmZWNoYScgPT4gJHRpbWVzdGFtcCAhPT0gZmFsc2UgPyBkYXRlKCdkL20vWSBIOmkn
LCAkdGltZXN0YW1wKSA6ICfigJQnLAotICAgICAgICAgICAgICAgICdlc3RhZG8nID0+ICR0cmFu
c3BvcnRTdGF0ZVsnZXRpcXVldGFfZW52aW8nXSA/PyAkdGhpcy0+ZXRpcXVldGFFc3RhZG8oJGZp
bGFbJ2VzdGFkbyddID8/ICcnKSwKLSAgICAgICAgICAgICAgICAnZXN0YWRvX2VudmlvJyA9PiAk
dHJhbnNwb3J0U3RhdGVbJ2VzdGFkb19lbnZpbyddLAotICAgICAgICAgICAgICAgICdlbnRyZWdh
X2NvbmZpcm1hZGEnID0+IGZhbHNlLAorICAgICAgICAgICAgICAgICdlc3RhZG8nID0+ICR0aGlz
LT5ldGlxdWV0YUVzdGFkbygkZmlsYVsnZXN0YWRvJ10gPz8gJycpLAogICAgICAgICAgICAgICAg
ICdlc3RhZG9fY29kaWdvJyA9PiBzdHJ0b2xvd2VyKChzdHJpbmcpKCRmaWxhWydlc3RhZG8nXSA/
PyAncGVuZGllbnRlJykpLAogICAgICAgICAgICAgICAgICdhZGp1bnRvc19jb3VudCcgPT4gKGlu
dCkoJGZpbGFbJ2FkanVudG9zX2NvdW50J10gPz8gMCkKICAgICAgICAgICAgIF07CkBAIC0yMTYs
NyArMjEyLDYgQEAKICAgICAgICAgICAgIF07CiAgICAgICAgIH0KIAotICAgICAgICAkdHJhbnNw
b3J0U3RhdGUgPSBDb3JyZW9SZWdpc3Ryb0VzdGFkb1NlcnZpY2U6OmNvbnN1bHRhcigkdXN1YXJp
b0lkLCAoaW50KSRmaWxhWydpZCddLCAoc3RyaW5nKSRmaWxhWydlc3RhZG8nXSk7CiAgICAgICAg
IHJldHVybiBbCiAgICAgICAgICAgICAnaWQnID0+IChpbnQpJGZpbGFbJ2lkJ10sCiAgICAgICAg
ICAgICAnZGVzdGluYXRhcmlvJyA9PiAoc3RyaW5nKSgkZmlsYVsnZGVzdGluYXRhcmlvJ10gPz8g
JycpLApAQCAtMjI1LDEwICsyMjAsNyBAQAogICAgICAgICAgICAgJ2FzdW50bycgPT4gKHN0cmlu
ZykoJGZpbGFbJ2FzdW50byddID8/ICcnKSwKICAgICAgICAgICAgICdjdWVycG8nID0+IChzdHJp
bmcpKCRmaWxhWydjdWVycG8nXSA/PyAnJyksCiAgICAgICAgICAgICAndGlwbycgPT4gJHRoaXMt
PmV0aXF1ZXRhVGlwbygkZmlsYVsndGlwbyddID8/ICcnKSwKLSAgICAgICAgICAgICdlc3RhZG8n
ID0+ICR0cmFuc3BvcnRTdGF0ZVsnZXRpcXVldGFfZW52aW8nXSA/PyAkdGhpcy0+ZXRpcXVldGFF
c3RhZG8oJGZpbGFbJ2VzdGFkbyddID8/ICcnKSwKLSAgICAgICAgICAgICdlc3RhZG9fZW52aW8n
ID0+ICR0cmFuc3BvcnRTdGF0ZVsnZXN0YWRvX2VudmlvJ10sCi0gICAgICAgICAgICAnZW50cmVn
YV9jb25maXJtYWRhJyA9PiBmYWxzZSwKLSAgICAgICAgICAgICdyZWdpc3Ryb19jb25maXJtYWRv
JyA9PiAkdHJhbnNwb3J0U3RhdGVbJ3JlZ2lzdHJvX2NvbmZpcm1hZG8nXSwKKyAgICAgICAgICAg
ICdlc3RhZG8nID0+ICR0aGlzLT5ldGlxdWV0YUVzdGFkbygkZmlsYVsnZXN0YWRvJ10gPz8gJycp
LAogICAgICAgICAgICAgJ2VzdGFkb19jb2RpZ28nID0+IHN0cnRvbG93ZXIoCiAgICAgICAgICAg
ICAgICAgdHJpbSgoc3RyaW5nKSgkZmlsYVsnZXN0YWRvJ10gPz8gJ3BlbmRpZW50ZScpKQogICAg
ICAgICAgICAgKSwKQEAgLTczOCw2ICs3MzAsOSBAQAogICAgICAgICAgICAgICAgIDQyMgogICAg
ICAgICAgICAgKTsKICAgICAgICAgfQorCisgICAgICAgICRhdXRvcml6YWNpb24gPSAkdGhpcy0+
c2VuZGVyLT52YWxpZGFyUmVtaXRlbnRlKCRyZW1pdGVudGUsICR1c3VhcmlvSWQpOworICAgICAg
ICBpZiAoZW1wdHkoJGF1dG9yaXphY2lvblsnb2snXSkpIHsgcmV0dXJuICRhdXRvcml6YWNpb247
IH0KIAogICAgICAgICAkcHJlcGFyYWNpb24gPSAkdGhpcy0+cHJlcGFyYXJBZGp1bnRvcygkYXJj
aGl2b3MpOwogICAgICAgICBpZiAoISgkcHJlcGFyYWNpb25bJ29rJ10gPz8gZmFsc2UpKSB7CkBA
IC03OTQsOSArNzg5LDYgQEAKICAgICAgICAgICAgICAgICB9CiAgICAgICAgICAgICB9CiAKLSAg
ICAgICAgICAgIGlmICgkcmVnaXN0cm9JZCA8PSAwKSB7IHJldHVybiAkdGhpcy0+ZXJyb3IoJ05v
IHNlIHB1ZG8gcmVnaXN0cmFyIGxhIHNvbGljaXR1ZCBlbiBsYSBiYW5kZWphLiBFbCBjb3JyZW8g
bm8gc2UgaGEgZW52aWFkby4nLCA1MDMpOyB9Ci0gICAgICAgICAgICBDb3JyZW9SZWdpc3Ryb0Vz
dGFkb1NlcnZpY2U6Omd1YXJkYXIoJHVzdWFyaW9JZCwgJHJlZ2lzdHJvSWQsICdQRU5ESUVOVEUn
KTsKLSAgICAgICAgICAgIENvcnJlb1JlZ2lzdHJvRXN0YWRvU2VydmljZTo6Z3VhcmRhcigkdXN1
YXJpb0lkLCAkcmVnaXN0cm9JZCwgJ0VOX1BST0NFU08nKTsKICAgICAgICAgICAgICRlbnZpbyA9
ICR0aGlzLT5zZW5kZXItPmVudmlhcihbCiAgICAgICAgICAgICAgICAgJ3VzdWFyaW9faWQnID0+
ICR1c3VhcmlvSWQsCiAgICAgICAgICAgICAgICAgJ3BlcmZpbF9tYXJrZXRpbmcnID0+IHRydWUs
CkBAIC04MTMsMTEgKzgwNSw2IEBACiAgICAgICAgICAgICAgICAgJ2N1ZXJwbycgPT4gJGN1ZXJw
bywKICAgICAgICAgICAgICAgICAnYWRqdW50b3MnID0+ICRhZGp1bnRvc0VudmlvCiAgICAgICAg
ICAgICBdKTsKLQotICAgICAgICAgICAgJHRyYW5zcG9ydFN0YXR1cyA9ICFlbXB0eSgkZW52aW9b
J29rJ10pID8gJ0FDRVBUQURPJyA6ICgoJGVudmlvWydlc3RhZG9fZW50cmVnYSddID8/ICcnKSA9
PT0gJ0RFU0NPTk9DSURPJyA/ICdERVNDT05PQ0lETycgOiAnRkFMTElETycpOwotICAgICAgICAg
ICAgLy8gVW4gZHVwbGljYWRvIGFjZXB0YWRvIHRpZW5lIG9rPWZhbHNlOiBjb25zZXJ2YXIgZXNh
IGV2aWRlbmNpYSB5IG5vIHRyYXRhcmxvIGNvbW8gcmVjaGF6by4KLSAgICAgICAgICAgIGlmICgo
JGVudmlvWydlc3RhZG9fZW50cmVnYSddID8/ICcnKSA9PT0gJ0FDRVBUQURPJykgeyAkdHJhbnNw
b3J0U3RhdHVzID0gJ0FDRVBUQURPJzsgfQotICAgICAgICAgICAgQ29ycmVvUmVnaXN0cm9Fc3Rh
ZG9TZXJ2aWNlOjpndWFyZGFyKCR1c3VhcmlvSWQsICRyZWdpc3Ryb0lkLCAkdHJhbnNwb3J0U3Rh
dHVzLCAkZW52aW8pOwogCiAgICAgICAgICAgICBpZiAoISgkZW52aW9bJ29rJ10gPz8gZmFsc2Up
KSB7CiAgICAgICAgICAgICAgICAgaWYgKCRyZWdpc3Ryb0lkID4gMCkgewo=
CENTRAL_PATCH_1_3
 [[ "$(digest "$TMP/patches/1/3.patch")" == 'c9e1711154d14ab15229efc345c554b99d50fe2cd96ae947e7a71a52bc92ce54' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/2"
 base64 --decode > "$TMP/patches/2/1.patch" <<'CENTRAL_PATCH_2_1'
ZGlmZiAtLWdpdCBhL2FwcC9jb250cm9sbGVycy9Db3JyZW9NYXJrZXRpbmdDb250cm9sbGVyLnBo
cCBiL2FwcC9jb250cm9sbGVycy9Db3JyZW9NYXJrZXRpbmdDb250cm9sbGVyLnBocAotLS0gYS9h
cHAvY29udHJvbGxlcnMvQ29ycmVvTWFya2V0aW5nQ29udHJvbGxlci5waHAKKysrIGIvYXBwL2Nv
bnRyb2xsZXJzL0NvcnJlb01hcmtldGluZ0NvbnRyb2xsZXIucGhwCkBAIC02LDYgKzYsMTUgQEAK
IAogY2xhc3MgQ29ycmVvTWFya2V0aW5nQ29udHJvbGxlcgogeworICAgIHB1YmxpYyBmdW5jdGlv
biByZW1pdGVudGVzKCkKKyAgICB7CisgICAgICAgICR0aGlzLT52YWxpZGFyQWNjZXNvTWFya2V0
aW5nKCk7CisgICAgICAgIGhlYWRlcignQ2FjaGUtQ29udHJvbDogbm8tc3RvcmUnKTsKKyAgICAg
ICAgaWYgKCgkX1NFUlZFUlsnUkVRVUVTVF9NRVRIT0QnXSA/PyAnJykgIT09ICdHRVQnKSB7ICR0
aGlzLT5yZXNwb25kZXIoWydvayc9PmZhbHNlLCdtZW5zYWplJz0+J03DqXRvZG8gbm8gcGVybWl0
aWRvLiddLDQwNSk7IH0KKyAgICAgICAgJHI9KG5ldyBDb3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFs
U2VydmljZSgpKS0+cmVtaXRlbnRlcygoaW50KSRfU0VTU0lPTlsndXN1YXJpb19pZCddKTsKKyAg
ICAgICAgJGNvZGU9KGludCkoJHJbJ2NvZGlnb19odHRwJ10/PzIwMCk7dW5zZXQoJHJbJ2NvZGln
b19odHRwJ10pOyR0aGlzLT5yZXNwb25kZXIoJHIsJGNvZGUpOworICAgIH0KKwogICAgIHB1Ymxp
YyBmdW5jdGlvbiBpbmRleCgpCiAgICAgewogICAgICAgICAkdGhpcy0+dmFsaWRhckFjY2Vzb01h
cmtldGluZygpOwo=
CENTRAL_PATCH_2_1
 [[ "$(digest "$TMP/patches/2/1.patch")" == '5bf17315f7387b021dae847f3069f45f41a273137b41457be8beca3a53085302' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/2/2.patch" <<'CENTRAL_PATCH_2_2'
ZGlmZiAtLWdpdCBhL2FwcC9jb250cm9sbGVycy9Db3JyZW9NYXJrZXRpbmdDb250cm9sbGVyLnBo
cCBiL2FwcC9jb250cm9sbGVycy9Db3JyZW9NYXJrZXRpbmdDb250cm9sbGVyLnBocAotLS0gYS9h
cHAvY29udHJvbGxlcnMvQ29ycmVvTWFya2V0aW5nQ29udHJvbGxlci5waHAKKysrIGIvYXBwL2Nv
bnRyb2xsZXJzL0NvcnJlb01hcmtldGluZ0NvbnRyb2xsZXIucGhwCkBAIC02LDEzICs2LDE1IEBA
CiAKIGNsYXNzIENvcnJlb01hcmtldGluZ0NvbnRyb2xsZXIKIHsKLSAgICBwdWJsaWMgZnVuY3Rp
b24gaWRlbnRpZGFkKCkKLSAgICB7Ci0gICAgICAgICR0aGlzLT52YWxpZGFyQWNjZXNvTWFya2V0
aW5nKCk7Ci0gICAgICAgIGlmICgoJF9TRVJWRVJbJ1JFUVVFU1RfTUVUSE9EJ10gPz8gJycpICE9
PSAnUE9TVCcpIHsgJHRoaXMtPnJlc3BvbmRlcihbJ29rJyA9PiBmYWxzZSwgJ21lbnNhamUnID0+
ICdNw6l0b2RvIG5vIHBlcm1pdGlkby4nXSwgNDA1KTsgfQotICAgICAgICAkciA9IChuZXcgQ29y
cmVvU2FsaWRhSW5zdGl0dWNpb25hbFNlcnZpY2UoKSktPmlkZW50aWRhZChzdHJ0b2xvd2VyKHRy
aW0oKHN0cmluZykoJF9QT1NUWydyZW1pdGVudGUnXSA/PyAnJykpKSwgKGludCkkX1NFU1NJT05b
J3VzdWFyaW9faWQnXSk7Ci0gICAgICAgICRjb2RlID0gKGludCkoJHJbJ2NvZGlnb19odHRwJ10g
Pz8gMjAwKTsgdW5zZXQoJHJbJ2NvZGlnb19odHRwJ10pOyAkdGhpcy0+cmVzcG9uZGVyKCRyLCAk
Y29kZSk7Ci0gICAgfQorICAgIHB1YmxpYyBmdW5jdGlvbiByZW1pdGVudGVzKCkKKyAgICB7Cisg
ICAgICAgICR0aGlzLT52YWxpZGFyQWNjZXNvTWFya2V0aW5nKCk7CisgICAgICAgIGhlYWRlcign
Q2FjaGUtQ29udHJvbDogbm8tc3RvcmUnKTsKKyAgICAgICAgaWYgKCgkX1NFUlZFUlsnUkVRVUVT
VF9NRVRIT0QnXSA/PyAnJykgIT09ICdHRVQnKSB7ICR0aGlzLT5yZXNwb25kZXIoWydvayc9PmZh
bHNlLCdtZW5zYWplJz0+J03DqXRvZG8gbm8gcGVybWl0aWRvLiddLDQwNSk7IH0KKyAgICAgICAg
JHI9KG5ldyBDb3JyZW9TYWxpZGFJbnN0aXR1Y2lvbmFsU2VydmljZSgpKS0+cmVtaXRlbnRlcygo
aW50KSRfU0VTU0lPTlsndXN1YXJpb19pZCddKTsKKyAgICAgICAgJGNvZGU9KGludCkoJHJbJ2Nv
ZGlnb19odHRwJ10/PzIwMCk7dW5zZXQoJHJbJ2NvZGlnb19odHRwJ10pOyR0aGlzLT5yZXNwb25k
ZXIoJHIsJGNvZGUpOworICAgIH0KKwogICAgIHB1YmxpYyBmdW5jdGlvbiBpbmRleCgpCiAgICAg
ewogICAgICAgICAkdGhpcy0+dmFsaWRhckFjY2Vzb01hcmtldGluZygpOwo=
CENTRAL_PATCH_2_2
 [[ "$(digest "$TMP/patches/2/2.patch")" == '8057c18988a26e6f5ce2602680f90d1ddedec8e1f34ec48208a3b11da25bd0cd' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/3"
 base64 --decode > "$TMP/patches/3/1.patch" <<'CENTRAL_PATCH_3_1'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9jb3JyZW9zL2luZGV4LnBocCBiL2FwcC92aWV3cy9jb3Jy
ZW9zL2luZGV4LnBocAotLS0gYS9hcHAvdmlld3MvY29ycmVvcy9pbmRleC5waHAKKysrIGIvYXBw
L3ZpZXdzL2NvcnJlb3MvaW5kZXgucGhwCkBAIC0zNjQsNiArMzY0LDcgQEAKICAgICAgICAgICAg
ICAgICAgICAgICAgIDxpbnB1dAogICAgICAgICAgICAgICAgICAgICAgICAgICAgIGNsYXNzPSJm
b3JtLWNvbnRyb2wgc3lzdGVtLWZvcm0tY29udHJvbCIKICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICBpZD0iY29ycmVvX21hcmtldGluZ19kZSIKKyAgICAgICAgICAgICAgICAgICAgICAgICAg
ICBsaXN0PSJjb3JyZW9faG9zdGluZ2VyX3JlbWl0ZW50ZXMiCiAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgdHlwZT0iZW1haWwiCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgbmFtZT0i
cmVtaXRlbnRlIgogICAgICAgICAgICAgICAgICAgICAgICAgICAgIG1heGxlbmd0aD0iMjU1IgpA
QCAtMzcyLDExICszNzMsOCBAQAogICAgICAgICAgICAgICAgICAgICAgICAgICAgIGRhdGEtbWFy
a2V0aW5nLW1haWwtZnJvbQogICAgICAgICAgICAgICAgICAgICAgICAgICAgIHJlcXVpcmVkPgog
Ci0gICAgICAgICAgICAgICAgICAgICAgICA8ZGl2IGNsYXNzPSJmb3JtLXRleHQiPgotICAgICAg
ICAgICAgICAgICAgICAgICAgICAgIEVzY3JpYmUgdW5hIGN1ZW50YSBjb25lY3RhZGEgeSBhdXRv
cml6YWRhIHBhcmEgdHUgdXN1YXJpby4KLSAgICAgICAgICAgICAgICAgICAgICAgICAgICBFbCBz
aXN0ZW1hIHV0aWxpemFyw6Egc3UgQVBJIG8gU01UUCBhdXRlbnRpY2FkbzsgbGFzIGN1ZW50YXMK
LSAgICAgICAgICAgICAgICAgICAgICAgICAgICBudWV2YXMgcmVxdWllcmVuIGNvbmZpZ3VyYWNp
w7NuIGRlbCBhZG1pbmlzdHJhZG9yLgotICAgICAgICAgICAgICAgICAgICAgICAgPC9kaXY+Cisg
ICAgICAgICAgICAgICAgICAgICAgICA8ZGF0YWxpc3QgaWQ9ImNvcnJlb19ob3N0aW5nZXJfcmVt
aXRlbnRlcyI+PC9kYXRhbGlzdD4KKyAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9
ImZvcm0tdGV4dCIgZGF0YS1ob3N0aW5nZXItc2VuZGVyLXN0YXR1cyBhcmlhLWxpdmU9InBvbGl0
ZSI+PC9kaXY+CiAgICAgICAgICAgICAgICAgICAgIDwvZGl2PgogCiAgICAgICAgICAgICAgICAg
ICAgIDxkaXYgY2xhc3M9ImNvcnJlby1tYXJrZXRpbmctcmVjaXBpZW50LWNhcmQgbWItMyI+Cg==
CENTRAL_PATCH_3_1
 [[ "$(digest "$TMP/patches/3/1.patch")" == '6a3f930543f017bc38982f1e0c434b53d9f471ff6d89eb1f6373dbc74ab06b28' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/3/2.patch" <<'CENTRAL_PATCH_3_2'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9jb3JyZW9zL2luZGV4LnBocCBiL2FwcC92aWV3cy9jb3Jy
ZW9zL2luZGV4LnBocAotLS0gYS9hcHAvdmlld3MvY29ycmVvcy9pbmRleC5waHAKKysrIGIvYXBw
L3ZpZXdzL2NvcnJlb3MvaW5kZXgucGhwCkBAIC0zNjQsNiArMzY0LDcgQEAKICAgICAgICAgICAg
ICAgICAgICAgICAgIDxpbnB1dAogICAgICAgICAgICAgICAgICAgICAgICAgICAgIGNsYXNzPSJm
b3JtLWNvbnRyb2wgc3lzdGVtLWZvcm0tY29udHJvbCIKICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICBpZD0iY29ycmVvX21hcmtldGluZ19kZSIKKyAgICAgICAgICAgICAgICAgICAgICAgICAg
ICBsaXN0PSJjb3JyZW9faG9zdGluZ2VyX3JlbWl0ZW50ZXMiCiAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgdHlwZT0iZW1haWwiCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgbmFtZT0i
cmVtaXRlbnRlIgogICAgICAgICAgICAgICAgICAgICAgICAgICAgIG1heGxlbmd0aD0iMjU1IgpA
QCAtMzcyLDcgKzM3Myw4IEBACiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgZGF0YS1tYXJr
ZXRpbmctbWFpbC1mcm9tCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgcmVxdWlyZWQ+CiAK
LSAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9ImZvcm0tdGV4dCIgZGF0YS1tYXJr
ZXRpbmctbWFpbC1pZGVudGl0eSByb2xlPSJzdGF0dXMiIGFyaWEtbGl2ZT0icG9saXRlIj48L2Rp
dj4KKyAgICAgICAgICAgICAgICAgICAgICAgIDxkYXRhbGlzdCBpZD0iY29ycmVvX2hvc3Rpbmdl
cl9yZW1pdGVudGVzIj48L2RhdGFsaXN0PgorICAgICAgICAgICAgICAgICAgICAgICAgPGRpdiBj
bGFzcz0iZm9ybS10ZXh0IiBkYXRhLWhvc3Rpbmdlci1zZW5kZXItc3RhdHVzIGFyaWEtbGl2ZT0i
cG9saXRlIj48L2Rpdj4KICAgICAgICAgICAgICAgICAgICAgPC9kaXY+CiAKICAgICAgICAgICAg
ICAgICAgICAgPGRpdiBjbGFzcz0iY29ycmVvLW1hcmtldGluZy1yZWNpcGllbnQtY2FyZCBtYi0z
Ij4K
CENTRAL_PATCH_3_2
 [[ "$(digest "$TMP/patches/3/2.patch")" == 'eeb5c29145af9519c90c144e7329117e4fb21c14810521c09475409614669790' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/3/3.patch" <<'CENTRAL_PATCH_3_3'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9jb3JyZW9zL2luZGV4LnBocCBiL2FwcC92aWV3cy9jb3Jy
ZW9zL2luZGV4LnBocAotLS0gYS9hcHAvdmlld3MvY29ycmVvcy9pbmRleC5waHAKKysrIGIvYXBw
L3ZpZXdzL2NvcnJlb3MvaW5kZXgucGhwCkBAIC0zNCw3ICszNCw2IEBACiAgICAgICAgICAgICA8
L2Rpdj4KIAogICAgICAgICAgICAgPGRpdiBjbGFzcz0iY29ycmVvLW1hcmtldGluZy1zZWxlY3Rv
ci1hY3Rpb25zIj4KLSAgICAgICAgICAgICAgICA8YSBjbGFzcz0iYnRuIGJ0bi1zeXN0ZW0tbGln
aHQiIGhyZWY9ImluZGV4LnBocD9jb250cm9sbGVyPWNvcnJlb0NvbmV4aW9uJmFtcDthY3Rpb249
aW5kZXgiIHRhcmdldD0iX2JsYW5rIiByZWw9Im5vb3BlbmVyIG5vcmVmZXJyZXIiPkNvbmVjdGFy
IGN1ZW50YSBkZSBjb3JyZW88L2E+CiAgICAgICAgICAgICAgICAgPGJ1dHRvbgogICAgICAgICAg
ICAgICAgICAgICB0eXBlPSJidXR0b24iCiAgICAgICAgICAgICAgICAgICAgIGNsYXNzPSJidG4g
YnRuLXN5c3RlbS1zYXZlIGNvcnJlby1tYXJrZXRpbmctY29tcG9zZS1idXR0b24iCkBAIC0zNjUs
NiArMzY0LDcgQEAKICAgICAgICAgICAgICAgICAgICAgICAgIDxpbnB1dAogICAgICAgICAgICAg
ICAgICAgICAgICAgICAgIGNsYXNzPSJmb3JtLWNvbnRyb2wgc3lzdGVtLWZvcm0tY29udHJvbCIK
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICBpZD0iY29ycmVvX21hcmtldGluZ19kZSIKKyAg
ICAgICAgICAgICAgICAgICAgICAgICAgICBsaXN0PSJjb3JyZW9faG9zdGluZ2VyX3JlbWl0ZW50
ZXMiCiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgdHlwZT0iZW1haWwiCiAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgbmFtZT0icmVtaXRlbnRlIgogICAgICAgICAgICAgICAgICAgICAg
ICAgICAgIG1heGxlbmd0aD0iMjU1IgpAQCAtMzczLDYgKzM3Myw4IEBACiAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgZGF0YS1tYXJrZXRpbmctbWFpbC1mcm9tCiAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAgcmVxdWlyZWQ+CiAKKyAgICAgICAgICAgICAgICAgICAgICAgIDxkYXRhbGlz
dCBpZD0iY29ycmVvX2hvc3Rpbmdlcl9yZW1pdGVudGVzIj48L2RhdGFsaXN0PgorICAgICAgICAg
ICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0iZm9ybS10ZXh0IiBkYXRhLWhvc3Rpbmdlci1zZW5k
ZXItc3RhdHVzIGFyaWEtbGl2ZT0icG9saXRlIj48L2Rpdj4KICAgICAgICAgICAgICAgICAgICAg
PC9kaXY+CiAKICAgICAgICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0iY29ycmVvLW1hcmtldGlu
Zy1yZWNpcGllbnQtY2FyZCBtYi0zIj4K
CENTRAL_PATCH_3_3
 [[ "$(digest "$TMP/patches/3/3.patch")" == '6d73b72c61d99aaa8f2161e2cada5ea46baaee671f455c3ddaca4e866b1e84f3' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/4"
 base64 --decode > "$TMP/patches/4/1.patch" <<'CENTRAL_PATCH_4_1'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb3NfbWFya2V0aW5nLmpzIGIvcHVi
bGljL2phdmFzY3JpcHQvY29ycmVvc19tYXJrZXRpbmcuanMKLS0tIGEvcHVibGljL2phdmFzY3Jp
cHQvY29ycmVvc19tYXJrZXRpbmcuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQvY29ycmVvc19t
YXJrZXRpbmcuanMKQEAgLTEyOCw2ICsxMjgsNjAgQEAKICAgICAgICAgICAgIH0pLnNob3coKTsK
ICAgICAgICAgfTsKIAorICAgICAgICBjb25zdCBlc3RhZG9SZW1pdGVudGUgPSBtb2RhbEVsZW1l
bnQucXVlcnlTZWxlY3RvcignW2RhdGEtaG9zdGluZ2VyLXNlbmRlci1zdGF0dXNdJyk7CisgICAg
ICAgIGNvbnN0IG9wY2lvbmVzUmVtaXRlbnRlID0gbW9kYWxFbGVtZW50LnF1ZXJ5U2VsZWN0b3Io
JyNjb3JyZW9faG9zdGluZ2VyX3JlbWl0ZW50ZXMnKTsKKyAgICAgICAgbGV0IHJlbWl0ZW50ZXNQ
ZXJtaXRpZG9zID0gbnVsbDsKKyAgICAgICAgbGV0IGNvbnN1bHRhUmVtaXRlbnRlcyA9IDA7Cisg
ICAgICAgIGxldCB1bHRpbW9SZWZyZXNjb1JlbWl0ZW50ZXMgPSAwOworICAgICAgICBsZXQgZGlh
Z25vc3RpY29SZW1pdGVudGVzID0gbnVsbDsKKyAgICAgICAgY29uc3QgdmFsaWRhclJlbWl0ZW50
ZVZpc2libGUgPSBmdW5jdGlvbiAoKSB7CisgICAgICAgICAgICBjb25zdCBjb3JyZW8gPSBTdHJp
bmcocmVtaXRlbnRlPy52YWx1ZSB8fCAnJykudHJpbSgpLnRvTG93ZXJDYXNlKCk7CisgICAgICAg
ICAgICBjb25zdCBwZXJtaXRpZG8gPSBBcnJheS5pc0FycmF5KHJlbWl0ZW50ZXNQZXJtaXRpZG9z
KSAmJiByZW1pdGVudGVzUGVybWl0aWRvcy5pbmNsdWRlcyhjb3JyZW8pOworICAgICAgICAgICAg
aWYgKGVzdGFkb1JlbWl0ZW50ZSAmJiBBcnJheS5pc0FycmF5KHJlbWl0ZW50ZXNQZXJtaXRpZG9z
KSkgeworICAgICAgICAgICAgICAgIGNvbnN0IHRvdGFsID0gcmVtaXRlbnRlc1Blcm1pdGlkb3Mu
bGVuZ3RoOworICAgICAgICAgICAgICAgIGVzdGFkb1JlbWl0ZW50ZS50ZXh0Q29udGVudCA9IHBl
cm1pdGlkbyA/ICdSZW1pdGVudGUgSG9zdGluZ2VyIGF1dG9yaXphZG86ICcgKyBjb3JyZW8KKyAg
ICAgICAgICAgICAgICAgICAgOiB0b3RhbCA9PT0gMCA/ICdMYXMgcmVzdHJpY2Npb25lcyBkZWwg
Q1JNIG5vIHBlcm1pdGVuIHV0aWxpemFyIGxvcyBidXpvbmVzIGRldnVlbHRvcyBwb3IgSG9zdGlu
Z2VyLiBTb2xpY2l0YSByZXZpc2FyIGxhIGNvbmZpZ3VyYWNpw7NuIGNvbWVyY2lhbC4nCisgICAg
ICAgICAgICAgICAgICAgIDogY29ycmVvID09PSAnJyA/ICdTZWxlY2Npb25hIHVubyBkZSBsb3Mg
JyArIHRvdGFsICsgJyBidXpvbmVzIGluc3RpdHVjaW9uYWxlcyBhdXRvcml6YWRvcy4nCisgICAg
ICAgICAgICAgICAgICAgIDogJ0VzdGEgZGlyZWNjacOzbiBubyBlc3TDoSBlbnRyZSBsb3MgJyAr
IHRvdGFsICsgJyBidXpvbmVzIHBlcm1pdGlkb3MuIEhvc3RpbmdlciBkZXZvbHZpw7MgJyArCisg
ICAgICAgICAgICAgICAgICAgICAgICAoZGlhZ25vc3RpY29SZW1pdGVudGVzPy50b3RhbF9pbnN0
aXR1Y2lvbmFsID8/IHRvdGFsKSArICcgYnV6b25lcyBpbnN0aXR1Y2lvbmFsZXMnICsKKyAgICAg
ICAgICAgICAgICAgICAgICAgIChkaWFnbm9zdGljb1JlbWl0ZW50ZXM/LnJlc3RyaWNjaW9uZXNf
bG9jYWxlcyA/ICcgeSBleGlzdGVuIHJlc3RyaWNjaW9uZXMgbG9jYWxlcyBkZWwgQ1JNLicgOiAn
LiBSZXZpc2EgZWwgYWxjYW5jZSBkZWwgdG9rZW4gc2kgZmFsdGEgdW4gYnV6w7NuLicpOworICAg
ICAgICAgICAgfQorICAgICAgICAgICAgcmV0dXJuIHBlcm1pdGlkbzsKKyAgICAgICAgfTsKKyAg
ICAgICAgY29uc3QgY2FyZ2FyUmVtaXRlbnRlcyA9IGFzeW5jIGZ1bmN0aW9uICgpIHsKKyAgICAg
ICAgICAgIGNvbnN0IGNvbnN1bHRhID0gKytjb25zdWx0YVJlbWl0ZW50ZXM7CisgICAgICAgICAg
ICB1bHRpbW9SZWZyZXNjb1JlbWl0ZW50ZXMgPSBEYXRlLm5vdygpOworICAgICAgICAgICAgcmVt
aXRlbnRlc1Blcm1pdGlkb3MgPSBudWxsOworICAgICAgICAgICAgZGlhZ25vc3RpY29SZW1pdGVu
dGVzID0gbnVsbDsKKyAgICAgICAgICAgIG9wY2lvbmVzUmVtaXRlbnRlPy5yZXBsYWNlQ2hpbGRy
ZW4oKTsKKyAgICAgICAgICAgIGlmIChlc3RhZG9SZW1pdGVudGUpIHsgZXN0YWRvUmVtaXRlbnRl
LnRleHRDb250ZW50ID0gJ0NvbnN1bHRhbmRvIHJlbWl0ZW50ZXMgSG9zdGluZ2Vy4oCmJzsgfQor
ICAgICAgICAgICAgdHJ5IHsKKyAgICAgICAgICAgICAgICBjb25zdCByZXNwb25zZSA9IGF3YWl0
IGZldGNoKCdpbmRleC5waHA/Y29udHJvbGxlcj1jb3JyZW9NYXJrZXRpbmcmYWN0aW9uPXJlbWl0
ZW50ZXMnLCB7aGVhZGVyczp7J1gtUmVxdWVzdGVkLVdpdGgnOidmZXRjaCd9LGNhY2hlOiduby1z
dG9yZSd9KTsKKyAgICAgICAgICAgICAgICBjb25zdCBqc29uID0gYXdhaXQgcmVzcG9uc2UuanNv
bigpOworICAgICAgICAgICAgICAgIGlmIChjb25zdWx0YSAhPT0gY29uc3VsdGFSZW1pdGVudGVz
KSB7IHJldHVybiBmYWxzZTsgfQorICAgICAgICAgICAgICAgIGlmICghcmVzcG9uc2Uub2sgfHwg
IWpzb24ub2spIHsgdGhyb3cgbmV3IEVycm9yKGpzb24ubWVuc2FqZSB8fCAnTm8gc2UgcHVkaWVy
b24gY29uc3VsdGFyIGxvcyByZW1pdGVudGVzIEhvc3Rpbmdlci4nKTsgfQorICAgICAgICAgICAg
ICAgIGlmICghQXJyYXkuaXNBcnJheShqc29uLnJlbWl0ZW50ZXMpIHx8IGpzb24ucmVtaXRlbnRl
cy5zb21lKGZ1bmN0aW9uIChjb3JyZW8pIHsgcmV0dXJuIHR5cGVvZiBjb3JyZW8gIT09ICdzdHJp
bmcnOyB9KSkgeworICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoJ0xhIGxpc3Rh
IGRlIGJ1em9uZXMgcmVjaWJpZGEgbm8gZXMgdsOhbGlkYS4gVnVlbHZlIGEgYWJyaXIgZWwgZm9y
bXVsYXJpby4nKTsKKyAgICAgICAgICAgICAgICB9CisgICAgICAgICAgICAgICAgcmVtaXRlbnRl
c1Blcm1pdGlkb3MgPSBBcnJheS5mcm9tKG5ldyBTZXQoanNvbi5yZW1pdGVudGVzKSk7CisgICAg
ICAgICAgICAgICAgZGlhZ25vc3RpY29SZW1pdGVudGVzID0ganNvbi5kaWFnbm9zdGljbyB8fCBu
dWxsOworICAgICAgICAgICAgICAgIHVsdGltb1JlZnJlc2NvUmVtaXRlbnRlcyA9IERhdGUubm93
KCk7CisgICAgICAgICAgICAgICAgcmVtaXRlbnRlc1Blcm1pdGlkb3MuZm9yRWFjaChmdW5jdGlv
biAoY29ycmVvKSB7CisgICAgICAgICAgICAgICAgICAgIGNvbnN0IG9wdGlvbiA9IGRvY3VtZW50
LmNyZWF0ZUVsZW1lbnQoJ29wdGlvbicpOyBvcHRpb24udmFsdWUgPSBjb3JyZW87IG9wY2lvbmVz
UmVtaXRlbnRlPy5hcHBlbmRDaGlsZChvcHRpb24pOworICAgICAgICAgICAgICAgIH0pOworICAg
ICAgICAgICAgICAgIHZhbGlkYXJSZW1pdGVudGVWaXNpYmxlKCk7CisgICAgICAgICAgICAgICAg
cmV0dXJuIHRydWU7CisgICAgICAgICAgICB9IGNhdGNoIChlcnJvcikgeworICAgICAgICAgICAg
ICAgIGlmIChjb25zdWx0YSA9PT0gY29uc3VsdGFSZW1pdGVudGVzICYmIGVzdGFkb1JlbWl0ZW50
ZSkgeyBlc3RhZG9SZW1pdGVudGUudGV4dENvbnRlbnQgPSBlcnJvci5tZXNzYWdlOyB9CisgICAg
ICAgICAgICAgICAgcmV0dXJuIGZhbHNlOworICAgICAgICAgICAgfQorICAgICAgICB9OworICAg
ICAgICBtb2RhbEVsZW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignc2hvd24uYnMubW9kYWwnLCBjYXJn
YXJSZW1pdGVudGVzKTsKKyAgICAgICAgcmVtaXRlbnRlPy5hZGRFdmVudExpc3RlbmVyKCdpbnB1
dCcsIHZhbGlkYXJSZW1pdGVudGVWaXNpYmxlKTsKKyAgICAgICAgcmVtaXRlbnRlPy5hZGRFdmVu
dExpc3RlbmVyKCdmb2N1cycsIGZ1bmN0aW9uICgpIHsKKyAgICAgICAgICAgIGlmIChEYXRlLm5v
dygpIC0gdWx0aW1vUmVmcmVzY29SZW1pdGVudGVzID4gMzAwMDApIHsgY2FyZ2FyUmVtaXRlbnRl
cygpOyB9CisgICAgICAgIH0pOworCiAgICAgICAgIGNvbnN0IG1vc3RyYXJFcnJvciA9IGZ1bmN0
aW9uIChtZW5zYWplKSB7CiAgICAgICAgICAgICBtb3N0cmFyVG9hc3QoCiAgICAgICAgICAgICAg
ICAgbWVuc2FqZSB8fCAnTm8gZnVlIHBvc2libGUgZW52aWFyIGVsIGNvcnJlby4nLApAQCAtMTI5
NCw2ICsxMzQ4LDE1IEBACiAgICAgICAgICAgICAgICAgcmV0dXJuOwogICAgICAgICAgICAgfQog
CisgICAgICAgICAgICAvLyBBY3R1YWxpemFyIGFudGVzIGRlIHZhbGlkYXIgZXZpdGEgYmxvcXVl
YXIgYnV6b25lcyByZWNpw6luIGF1dG9yaXphZG9zIHBvciB1bmEgbGlzdGEgdmllamEuCisgICAg
ICAgICAgICBlbnZpYW5kbyA9IHRydWU7CisgICAgICAgICAgICB0cnkgeworICAgICAgICAgICAg
ICAgIGlmICghKGF3YWl0IGNhcmdhclJlbWl0ZW50ZXMoKSkgfHwgIXZhbGlkYXJSZW1pdGVudGVW
aXNpYmxlKCkpIHsKKyAgICAgICAgICAgICAgICAgICAgbW9zdHJhckVycm9yKGVzdGFkb1JlbWl0
ZW50ZT8udGV4dENvbnRlbnQgfHwgJ05vIGZ1ZSBwb3NpYmxlIGNvbXByb2JhciBsb3MgYnV6b25l
cyBIb3N0aW5nZXIuJyk7CisgICAgICAgICAgICAgICAgICAgIHJlbWl0ZW50ZT8uZm9jdXMoKTsg
cmV0dXJuOworICAgICAgICAgICAgICAgIH0KKyAgICAgICAgICAgIH0gZmluYWxseSB7IGVudmlh
bmRvID0gZmFsc2U7IH0KKwogICAgICAgICAgICAgY29uc3QgZXJyb3JBcmNoaXZvcyA9IHZhbGlk
YXJBcmNoaXZvcygpOwogICAgICAgICAgICAgaWYgKGVycm9yQXJjaGl2b3MgIT09ICcnKSB7CiAg
ICAgICAgICAgICAgICAgbW9zdHJhckVycm9yKGVycm9yQXJjaGl2b3MpOwo=
CENTRAL_PATCH_4_1
 [[ "$(digest "$TMP/patches/4/1.patch")" == '0f0b76f083f80a6f9cfb2433c885dd4d71d7e029a0e5b9ca6511b1b3e8f1da85' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/4/2.patch" <<'CENTRAL_PATCH_4_2'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb3NfbWFya2V0aW5nLmpzIGIvcHVi
bGljL2phdmFzY3JpcHQvY29ycmVvc19tYXJrZXRpbmcuanMKLS0tIGEvcHVibGljL2phdmFzY3Jp
cHQvY29ycmVvc19tYXJrZXRpbmcuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQvY29ycmVvc19t
YXJrZXRpbmcuanMKQEAgLTg2LDkgKzg2LDYgQEAKICAgICAgICAgfTsKIAogICAgICAgICBjb25z
dCBtb3N0cmFyVG9hc3QgPSBmdW5jdGlvbiAobWVuc2FqZSwgZXNFcnJvcikgewotICAgICAgICAg
ICAgZG9jdW1lbnQucXVlcnlTZWxlY3RvckFsbCgnW2RhdGEtbWFya2V0aW5nLW1haWwtdG9hc3Rd
JykuZm9yRWFjaChmdW5jdGlvbiAoYW50ZXJpb3IpIHsKLSAgICAgICAgICAgICAgICBib290c3Ry
YXAuVG9hc3QuZ2V0SW5zdGFuY2UoYW50ZXJpb3IpPy5kaXNwb3NlKCk7IGFudGVyaW9yLnJlbW92
ZSgpOwotICAgICAgICAgICAgfSk7CiAgICAgICAgICAgICBsZXQgY29udGVuZWRvciA9IGRvY3Vt
ZW50LnF1ZXJ5U2VsZWN0b3IoJy50b2FzdC1jb250YWluZXInKTsKIAogICAgICAgICAgICAgaWYg
KCFjb250ZW5lZG9yKSB7CkBAIC0xMDAsNyArOTcsNiBAQAogICAgICAgICAgICAgfQogCiAgICAg
ICAgICAgICBjb25zdCB0b2FzdCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpOwotICAg
ICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdkYXRhLW1hcmtldGluZy1tYWlsLXRvYXN0Jywg
JycpOwogICAgICAgICAgICAgdG9hc3QuY2xhc3NOYW1lID0gJ3RvYXN0IHN5c3RlbS10b2FzdCcg
KwogICAgICAgICAgICAgICAgIChlc0Vycm9yID8gJyBzeXN0ZW0tdG9hc3QtZXJyb3InIDogJycp
OwogICAgICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdyb2xlJywgZXNFcnJvciA/ICdhbGVy
dCcgOiAnc3RhdHVzJyk7CkBAIC0xMzIsNTIgKzEyOCw1OCBAQAogICAgICAgICAgICAgfSkuc2hv
dygpOwogICAgICAgICB9OwogCi0gICAgICAgIGNvbnN0IGlkZW50aWRhZEVsZW1lbnRvID0gbW9k
YWxFbGVtZW50LnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLW1hcmtldGluZy1tYWlsLWlkZW50aXR5XScp
OwotICAgICAgICBsZXQgaWRlbnRpZGFkQWN0dWFsID0gbnVsbDsKLSAgICAgICAgbGV0IGNvbnN1
bHRhSWRlbnRpZGFkID0gMDsKLSAgICAgICAgbGV0IGVzcGVyYUlkZW50aWRhZDsKLSAgICAgICAg
Y29uc3QgY29tcHJvYmFyUmVtaXRlbnRlID0gYXN5bmMgZnVuY3Rpb24gKCkgewotICAgICAgICAg
ICAgY29uc3QgY29udGFjdG8gPSBTdHJpbmcocmVtaXRlbnRlPy52YWx1ZSB8fCAnJykudHJpbSgp
LnRvTG93ZXJDYXNlKCk7Ci0gICAgICAgICAgICBjb25zdCBzZWN1ZW5jaWEgPSArK2NvbnN1bHRh
SWRlbnRpZGFkOwotICAgICAgICAgICAgY29uc3QgZGF0b3MgPSBuZXcgRm9ybURhdGEoKTsgZGF0
b3Muc2V0KCdyZW1pdGVudGUnLCBjb250YWN0byk7Ci0gICAgICAgICAgICBjb25zdCByZXNwb25z
ZSA9IGF3YWl0IGZldGNoKCdpbmRleC5waHA/Y29udHJvbGxlcj1jb3JyZW9NYXJrZXRpbmcmYWN0
aW9uPWlkZW50aWRhZCcsIHsKLSAgICAgICAgICAgICAgICBtZXRob2Q6ICdQT1NUJywgYm9keTog
ZGF0b3MsIGhlYWRlcnM6IHsnWC1SZXF1ZXN0ZWQtV2l0aCc6ICdmZXRjaCd9Ci0gICAgICAgICAg
ICB9KTsKLSAgICAgICAgICAgIGNvbnN0IGpzb24gPSBhd2FpdCByZXNwb25zZS5qc29uKCk7Ci0g
ICAgICAgICAgICBpZiAoc2VjdWVuY2lhICE9PSBjb25zdWx0YUlkZW50aWRhZCB8fCBjb250YWN0
byAhPT0gU3RyaW5nKHJlbWl0ZW50ZT8udmFsdWUgfHwgJycpLnRyaW0oKS50b0xvd2VyQ2FzZSgp
KSB7IHJldHVybiBudWxsOyB9Ci0gICAgICAgICAgICBpZiAoIXJlc3BvbnNlLm9rIHx8ICFqc29u
Lm9rKSB7Ci0gICAgICAgICAgICAgICAgaWRlbnRpZGFkQWN0dWFsID0gbnVsbDsKLSAgICAgICAg
ICAgICAgICBpZiAoaWRlbnRpZGFkRWxlbWVudG8pIHsKLSAgICAgICAgICAgICAgICAgICAgaWRl
bnRpZGFkRWxlbWVudG8udGV4dENvbnRlbnQgPSBqc29uLm1lbnNhamUgfHwgJ05vIHNlIHB1ZG8g
Y29tcHJvYmFyIGVsIHJlbWl0ZW50ZS4nOwotICAgICAgICAgICAgICAgICAgICBpZiAoanNvbi5j
b25leGlvbl9yZXF1ZXJpZGEgJiYgdHlwZW9mIGpzb24uY29uZXhpb25fdXJsID09PSAnc3RyaW5n
JyAmJiBqc29uLmNvbmV4aW9uX3VybC5zdGFydHNXaXRoKCdpbmRleC5waHA/Y29udHJvbGxlcj1j
b3JyZW9Db25leGlvbiZhY3Rpb249aW5kZXgmY29ycmVvPScpKSB7Ci0gICAgICAgICAgICAgICAg
ICAgICAgICBjb25zdCBlbmxhY2UgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdhJyk7IGVubGFj
ZS5ocmVmID0ganNvbi5jb25leGlvbl91cmw7Ci0gICAgICAgICAgICAgICAgICAgICAgICBlbmxh
Y2UudGFyZ2V0ID0gJ19ibGFuayc7IGVubGFjZS5yZWwgPSAnbm9vcGVuZXInOyBlbmxhY2UudGV4
dENvbnRlbnQgPSAnIEF1dG9yaXphciBtaSBHbWFpbCc7Ci0gICAgICAgICAgICAgICAgICAgICAg
ICBpZGVudGlkYWRFbGVtZW50by5hcHBlbmRDaGlsZChlbmxhY2UpOwotICAgICAgICAgICAgICAg
ICAgICB9Ci0gICAgICAgICAgICAgICAgfQotICAgICAgICAgICAgICAgIHJldHVybiBudWxsOwot
ICAgICAgICAgICAgfQotICAgICAgICAgICAgY29uc3QgaWRlbnRpdHkgPSBqc29uLmlkZW50aWRh
ZDsKLSAgICAgICAgICAgIGlkZW50aWRhZEFjdHVhbCA9IHtjb250YWN0bzogY29udGFjdG8sIHRv
a2VuOiBqc29uLmlkZW50aWRhZF9lbnZpbywgZmlybWE6IEpTT04uc3RyaW5naWZ5KGlkZW50aXR5
KX07Ci0gICAgICAgICAgICBpZiAoaWRlbnRpZGFkRWxlbWVudG8pIHsKLSAgICAgICAgICAgICAg
ICBpZGVudGlkYWRFbGVtZW50by50ZXh0Q29udGVudCA9ICdSZW1pdGVudGUgdmlzaWJsZTogJyAr
IGlkZW50aXR5LnJlbWl0ZW50ZV92aXNpYmxlICsgJy4gUmVzcHVlc3RhcyBhOiAnICsgaWRlbnRp
dHkucmVwbHlfdG8gKyAnLiAnICsgKGlkZW50aXR5LmF2aXNvIHx8ICcnKTsKLSAgICAgICAgICAg
IH0KLSAgICAgICAgICAgIHJldHVybiBpZGVudGlkYWRBY3R1YWw7Ci0gICAgICAgIH07Ci0gICAg
ICAgIGNvbnN0IGFjdHVhbGl6YXJSZW1pdGVudGUgPSBmdW5jdGlvbiAoKSB7Ci0gICAgICAgICAg
ICBpZGVudGlkYWRBY3R1YWwgPSBudWxsOwotICAgICAgICAgICAgaWYgKGlkZW50aWRhZEVsZW1l
bnRvKSB7IGlkZW50aWRhZEVsZW1lbnRvLnRleHRDb250ZW50ID0gJ0NvbXByb2JhbmRvIHJlbWl0
ZW50ZSBpbnN0aXR1Y2lvbmFs4oCmJzsgfQotICAgICAgICAgICAgY29tcHJvYmFyUmVtaXRlbnRl
KCkuY2F0Y2goZnVuY3Rpb24gKCkgewotICAgICAgICAgICAgICAgIGlkZW50aWRhZEFjdHVhbCA9
IG51bGw7Ci0gICAgICAgICAgICAgICAgaWYgKGlkZW50aWRhZEVsZW1lbnRvKSB7IGlkZW50aWRh
ZEVsZW1lbnRvLnRleHRDb250ZW50ID0gJ05vIHNlIHB1ZG8gY29tcHJvYmFyIGVsIHJlbWl0ZW50
ZSBpbnN0aXR1Y2lvbmFsLiBWdWVsdmUgYSBpbnRlbnRhcmxvLic7IH0KLSAgICAgICAgICAgIH0p
OwotICAgICAgICB9OwotICAgICAgICBtb2RhbEVsZW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignc2hv
d24uYnMubW9kYWwnLCBhY3R1YWxpemFyUmVtaXRlbnRlKTsKLSAgICAgICAgd2luZG93LmFkZEV2
ZW50TGlzdGVuZXIoJ2ZvY3VzJywgZnVuY3Rpb24gKCkgeyBpZiAobW9kYWxFbGVtZW50LmNsYXNz
TGlzdC5jb250YWlucygnc2hvdycpKSB7IGFjdHVhbGl6YXJSZW1pdGVudGUoKTsgfSB9KTsKLSAg
ICAgICAgcmVtaXRlbnRlPy5hZGRFdmVudExpc3RlbmVyKCdpbnB1dCcsIGZ1bmN0aW9uICgpIHsK
LSAgICAgICAgICAgIGlkZW50aWRhZEFjdHVhbCA9IG51bGw7ICsrY29uc3VsdGFJZGVudGlkYWQ7
IGNsZWFyVGltZW91dChlc3BlcmFJZGVudGlkYWQpOwotICAgICAgICAgICAgaWYgKGlkZW50aWRh
ZEVsZW1lbnRvKSB7IGlkZW50aWRhZEVsZW1lbnRvLnRleHRDb250ZW50ID0gJyc7IH0KLSAgICAg
ICAgICAgIGVzcGVyYUlkZW50aWRhZCA9IHdpbmRvdy5zZXRUaW1lb3V0KGFjdHVhbGl6YXJSZW1p
dGVudGUsIDI1MCk7CisgICAgICAgIGNvbnN0IGVzdGFkb1JlbWl0ZW50ZSA9IG1vZGFsRWxlbWVu
dC5xdWVyeVNlbGVjdG9yKCdbZGF0YS1ob3N0aW5nZXItc2VuZGVyLXN0YXR1c10nKTsKKyAgICAg
ICAgY29uc3Qgb3BjaW9uZXNSZW1pdGVudGUgPSBtb2RhbEVsZW1lbnQucXVlcnlTZWxlY3Rvcign
I2NvcnJlb19ob3N0aW5nZXJfcmVtaXRlbnRlcycpOworICAgICAgICBsZXQgcmVtaXRlbnRlc1Bl
cm1pdGlkb3MgPSBudWxsOworICAgICAgICBsZXQgY29uc3VsdGFSZW1pdGVudGVzID0gMDsKKyAg
ICAgICAgbGV0IHVsdGltb1JlZnJlc2NvUmVtaXRlbnRlcyA9IDA7CisgICAgICAgIGxldCBkaWFn
bm9zdGljb1JlbWl0ZW50ZXMgPSBudWxsOworICAgICAgICBjb25zdCB2YWxpZGFyUmVtaXRlbnRl
VmlzaWJsZSA9IGZ1bmN0aW9uICgpIHsKKyAgICAgICAgICAgIGNvbnN0IGNvcnJlbyA9IFN0cmlu
ZyhyZW1pdGVudGU/LnZhbHVlIHx8ICcnKS50cmltKCkudG9Mb3dlckNhc2UoKTsKKyAgICAgICAg
ICAgIGNvbnN0IHBlcm1pdGlkbyA9IEFycmF5LmlzQXJyYXkocmVtaXRlbnRlc1Blcm1pdGlkb3Mp
ICYmIHJlbWl0ZW50ZXNQZXJtaXRpZG9zLmluY2x1ZGVzKGNvcnJlbyk7CisgICAgICAgICAgICBp
ZiAoZXN0YWRvUmVtaXRlbnRlICYmIEFycmF5LmlzQXJyYXkocmVtaXRlbnRlc1Blcm1pdGlkb3Mp
KSB7CisgICAgICAgICAgICAgICAgY29uc3QgdG90YWwgPSByZW1pdGVudGVzUGVybWl0aWRvcy5s
ZW5ndGg7CisgICAgICAgICAgICAgICAgZXN0YWRvUmVtaXRlbnRlLnRleHRDb250ZW50ID0gcGVy
bWl0aWRvID8gJ1JlbWl0ZW50ZSBIb3N0aW5nZXIgYXV0b3JpemFkbzogJyArIGNvcnJlbworICAg
ICAgICAgICAgICAgICAgICA6IHRvdGFsID09PSAwID8gJ0xhcyByZXN0cmljY2lvbmVzIGRlbCBD
Uk0gbm8gcGVybWl0ZW4gdXRpbGl6YXIgbG9zIGJ1em9uZXMgZGV2dWVsdG9zIHBvciBIb3N0aW5n
ZXIuIFNvbGljaXRhIHJldmlzYXIgbGEgY29uZmlndXJhY2nDs24gY29tZXJjaWFsLicKKyAgICAg
ICAgICAgICAgICAgICAgOiBjb3JyZW8gPT09ICcnID8gJ1NlbGVjY2lvbmEgdW5vIGRlIGxvcyAn
ICsgdG90YWwgKyAnIGJ1em9uZXMgaW5zdGl0dWNpb25hbGVzIGF1dG9yaXphZG9zLicKKyAgICAg
ICAgICAgICAgICAgICAgOiAnRXN0YSBkaXJlY2Npw7NuIG5vIGVzdMOhIGVudHJlIGxvcyAnICsg
dG90YWwgKyAnIGJ1em9uZXMgcGVybWl0aWRvcy4gSG9zdGluZ2VyIGRldm9sdmnDsyAnICsKKyAg
ICAgICAgICAgICAgICAgICAgICAgIChkaWFnbm9zdGljb1JlbWl0ZW50ZXM/LnRvdGFsX2luc3Rp
dHVjaW9uYWwgPz8gdG90YWwpICsgJyBidXpvbmVzIGluc3RpdHVjaW9uYWxlcycgKworICAgICAg
ICAgICAgICAgICAgICAgICAgKGRpYWdub3N0aWNvUmVtaXRlbnRlcz8ucmVzdHJpY2Npb25lc19s
b2NhbGVzID8gJyB5IGV4aXN0ZW4gcmVzdHJpY2Npb25lcyBsb2NhbGVzIGRlbCBDUk0uJyA6ICcu
IFJldmlzYSBlbCBhbGNhbmNlIGRlbCB0b2tlbiBzaSBmYWx0YSB1biBidXrDs24uJyk7CisgICAg
ICAgICAgICB9CisgICAgICAgICAgICByZXR1cm4gcGVybWl0aWRvOworICAgICAgICB9OworICAg
ICAgICBjb25zdCBjYXJnYXJSZW1pdGVudGVzID0gYXN5bmMgZnVuY3Rpb24gKCkgeworICAgICAg
ICAgICAgY29uc3QgY29uc3VsdGEgPSArK2NvbnN1bHRhUmVtaXRlbnRlczsKKyAgICAgICAgICAg
IHVsdGltb1JlZnJlc2NvUmVtaXRlbnRlcyA9IERhdGUubm93KCk7CisgICAgICAgICAgICByZW1p
dGVudGVzUGVybWl0aWRvcyA9IG51bGw7CisgICAgICAgICAgICBkaWFnbm9zdGljb1JlbWl0ZW50
ZXMgPSBudWxsOworICAgICAgICAgICAgb3BjaW9uZXNSZW1pdGVudGU/LnJlcGxhY2VDaGlsZHJl
bigpOworICAgICAgICAgICAgaWYgKGVzdGFkb1JlbWl0ZW50ZSkgeyBlc3RhZG9SZW1pdGVudGUu
dGV4dENvbnRlbnQgPSAnQ29uc3VsdGFuZG8gcmVtaXRlbnRlcyBIb3N0aW5nZXLigKYnOyB9Cisg
ICAgICAgICAgICB0cnkgeworICAgICAgICAgICAgICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQg
ZmV0Y2goJ2luZGV4LnBocD9jb250cm9sbGVyPWNvcnJlb01hcmtldGluZyZhY3Rpb249cmVtaXRl
bnRlcycsIHtoZWFkZXJzOnsnWC1SZXF1ZXN0ZWQtV2l0aCc6J2ZldGNoJ30sY2FjaGU6J25vLXN0
b3JlJ30pOworICAgICAgICAgICAgICAgIGNvbnN0IGpzb24gPSBhd2FpdCByZXNwb25zZS5qc29u
KCk7CisgICAgICAgICAgICAgICAgaWYgKGNvbnN1bHRhICE9PSBjb25zdWx0YVJlbWl0ZW50ZXMp
IHsgcmV0dXJuIGZhbHNlOyB9CisgICAgICAgICAgICAgICAgaWYgKCFyZXNwb25zZS5vayB8fCAh
anNvbi5vaykgeyB0aHJvdyBuZXcgRXJyb3IoanNvbi5tZW5zYWplIHx8ICdObyBzZSBwdWRpZXJv
biBjb25zdWx0YXIgbG9zIHJlbWl0ZW50ZXMgSG9zdGluZ2VyLicpOyB9CisgICAgICAgICAgICAg
ICAgaWYgKCFBcnJheS5pc0FycmF5KGpzb24ucmVtaXRlbnRlcykgfHwganNvbi5yZW1pdGVudGVz
LnNvbWUoZnVuY3Rpb24gKGNvcnJlbykgeyByZXR1cm4gdHlwZW9mIGNvcnJlbyAhPT0gJ3N0cmlu
Zyc7IH0pKSB7CisgICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcignTGEgbGlzdGEg
ZGUgYnV6b25lcyByZWNpYmlkYSBubyBlcyB2w6FsaWRhLiBWdWVsdmUgYSBhYnJpciBlbCBmb3Jt
dWxhcmlvLicpOworICAgICAgICAgICAgICAgIH0KKyAgICAgICAgICAgICAgICByZW1pdGVudGVz
UGVybWl0aWRvcyA9IEFycmF5LmZyb20obmV3IFNldChqc29uLnJlbWl0ZW50ZXMpKTsKKyAgICAg
ICAgICAgICAgICBkaWFnbm9zdGljb1JlbWl0ZW50ZXMgPSBqc29uLmRpYWdub3N0aWNvIHx8IG51
bGw7CisgICAgICAgICAgICAgICAgdWx0aW1vUmVmcmVzY29SZW1pdGVudGVzID0gRGF0ZS5ub3co
KTsKKyAgICAgICAgICAgICAgICByZW1pdGVudGVzUGVybWl0aWRvcy5mb3JFYWNoKGZ1bmN0aW9u
IChjb3JyZW8pIHsKKyAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3B0aW9uID0gZG9jdW1lbnQu
Y3JlYXRlRWxlbWVudCgnb3B0aW9uJyk7IG9wdGlvbi52YWx1ZSA9IGNvcnJlbzsgb3BjaW9uZXNS
ZW1pdGVudGU/LmFwcGVuZENoaWxkKG9wdGlvbik7CisgICAgICAgICAgICAgICAgfSk7CisgICAg
ICAgICAgICAgICAgdmFsaWRhclJlbWl0ZW50ZVZpc2libGUoKTsKKyAgICAgICAgICAgICAgICBy
ZXR1cm4gdHJ1ZTsKKyAgICAgICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7CisgICAgICAgICAgICAg
ICAgaWYgKGNvbnN1bHRhID09PSBjb25zdWx0YVJlbWl0ZW50ZXMgJiYgZXN0YWRvUmVtaXRlbnRl
KSB7IGVzdGFkb1JlbWl0ZW50ZS50ZXh0Q29udGVudCA9IGVycm9yLm1lc3NhZ2U7IH0KKyAgICAg
ICAgICAgICAgICByZXR1cm4gZmFsc2U7CisgICAgICAgICAgICB9CisgICAgICAgIH07CisgICAg
ICAgIG1vZGFsRWxlbWVudC5hZGRFdmVudExpc3RlbmVyKCdzaG93bi5icy5tb2RhbCcsIGNhcmdh
clJlbWl0ZW50ZXMpOworICAgICAgICByZW1pdGVudGU/LmFkZEV2ZW50TGlzdGVuZXIoJ2lucHV0
JywgdmFsaWRhclJlbWl0ZW50ZVZpc2libGUpOworICAgICAgICByZW1pdGVudGU/LmFkZEV2ZW50
TGlzdGVuZXIoJ2ZvY3VzJywgZnVuY3Rpb24gKCkgeworICAgICAgICAgICAgaWYgKERhdGUubm93
KCkgLSB1bHRpbW9SZWZyZXNjb1JlbWl0ZW50ZXMgPiAzMDAwMCkgeyBjYXJnYXJSZW1pdGVudGVz
KCk7IH0KICAgICAgICAgfSk7CiAKICAgICAgICAgY29uc3QgbW9zdHJhckVycm9yID0gZnVuY3Rp
b24gKG1lbnNhamUpIHsKQEAgLTEzNDYsNiArMTM0OCwxNSBAQAogICAgICAgICAgICAgICAgIHJl
dHVybjsKICAgICAgICAgICAgIH0KIAorICAgICAgICAgICAgLy8gQWN0dWFsaXphciBhbnRlcyBk
ZSB2YWxpZGFyIGV2aXRhIGJsb3F1ZWFyIGJ1em9uZXMgcmVjacOpbiBhdXRvcml6YWRvcyBwb3Ig
dW5hIGxpc3RhIHZpZWphLgorICAgICAgICAgICAgZW52aWFuZG8gPSB0cnVlOworICAgICAgICAg
ICAgdHJ5IHsKKyAgICAgICAgICAgICAgICBpZiAoIShhd2FpdCBjYXJnYXJSZW1pdGVudGVzKCkp
IHx8ICF2YWxpZGFyUmVtaXRlbnRlVmlzaWJsZSgpKSB7CisgICAgICAgICAgICAgICAgICAgIG1v
c3RyYXJFcnJvcihlc3RhZG9SZW1pdGVudGU/LnRleHRDb250ZW50IHx8ICdObyBmdWUgcG9zaWJs
ZSBjb21wcm9iYXIgbG9zIGJ1em9uZXMgSG9zdGluZ2VyLicpOworICAgICAgICAgICAgICAgICAg
ICByZW1pdGVudGU/LmZvY3VzKCk7IHJldHVybjsKKyAgICAgICAgICAgICAgICB9CisgICAgICAg
ICAgICB9IGZpbmFsbHkgeyBlbnZpYW5kbyA9IGZhbHNlOyB9CisKICAgICAgICAgICAgIGNvbnN0
IGVycm9yQXJjaGl2b3MgPSB2YWxpZGFyQXJjaGl2b3MoKTsKICAgICAgICAgICAgIGlmIChlcnJv
ckFyY2hpdm9zICE9PSAnJykgewogICAgICAgICAgICAgICAgIG1vc3RyYXJFcnJvcihlcnJvckFy
Y2hpdm9zKTsKQEAgLTEzNjYsMTMgKzEzNzcsNiBAQAogICAgICAgICAgICAgfQogCiAgICAgICAg
ICAgICB0cnkgewotICAgICAgICAgICAgICAgIGNvbnN0IGFudGVyaW9yID0gaWRlbnRpZGFkQWN0
dWFsOwotICAgICAgICAgICAgICAgIGNvbnN0IGFjdHVhbCA9IGF3YWl0IGNvbXByb2JhclJlbWl0
ZW50ZSgpOwotICAgICAgICAgICAgICAgIGlmICghYWN0dWFsKSB7IG1vc3RyYXJFcnJvcignTm8g
c2UgcHVkbyBjb21wcm9iYXIgZWwgcmVtaXRlbnRlIGluc3RpdHVjaW9uYWwuJyk7IHJldHVybjsg
fQotICAgICAgICAgICAgICAgIGlmICghYW50ZXJpb3IgfHwgYW50ZXJpb3IuY29udGFjdG8gIT09
IGFjdHVhbC5jb250YWN0byB8fCBhbnRlcmlvci5maXJtYSAhPT0gYWN0dWFsLmZpcm1hKSB7Ci0g
ICAgICAgICAgICAgICAgICAgIG1vc3RyYXJFcnJvcignUmV2aXNhIGVsIHJlbWl0ZW50ZSB2aXNp
YmxlIHkgcHVsc2EgRW52aWFyIGNvcnJlbyBwYXJhIGNvbmZpcm1hci4nKTsgcmV0dXJuOwotICAg
ICAgICAgICAgICAgIH0KLSAgICAgICAgICAgICAgICBkYXRvcy5zZXQoJ2lkZW50aWRhZF9lbnZp
bycsIGFjdHVhbC50b2tlbik7CiAgICAgICAgICAgICAgICAgY29uc3QgcmVzcHVlc3RhID0gYXdh
aXQgZmV0Y2godXJsRW52aWFyLCB7CiAgICAgICAgICAgICAgICAgICAgIG1ldGhvZDogJ1BPU1Qn
LAogICAgICAgICAgICAgICAgICAgICBib2R5OiBkYXRvcywK
CENTRAL_PATCH_4_2
 [[ "$(digest "$TMP/patches/4/2.patch")" == '36745e32a03e324550e95eb6d658bc85d8242b2c2744ce7c87c606c035a2d23c' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/4/3.patch" <<'CENTRAL_PATCH_4_3'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb3NfbWFya2V0aW5nLmpzIGIvcHVi
bGljL2phdmFzY3JpcHQvY29ycmVvc19tYXJrZXRpbmcuanMKLS0tIGEvcHVibGljL2phdmFzY3Jp
cHQvY29ycmVvc19tYXJrZXRpbmcuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQvY29ycmVvc19t
YXJrZXRpbmcuanMKQEAgLTg1LDEyICs4NSw3IEBACiAgICAgICAgICAgICAvLyBMb3MgYXZpc29z
IGRlbCBmb3JtdWxhcmlvIHNlIG11ZXN0cmFuIG1lZGlhbnRlIHRvYXN0LgogICAgICAgICB9Owog
Ci0gICAgICAgIGNvbnN0IG1vc3RyYXJUb2FzdCA9IGZ1bmN0aW9uIChtZW5zYWplLCBlc0Vycm9y
LCBjb25leGlvblVybCkgewotICAgICAgICAgICAgLy8gU3VzdGl0dWlyIGVsIGF2aXNvIGFudGVy
aW9yIGRlIGVzdGUgbcOzZHVsbyBlbiBsb3MgcmVpbnRlbnRvcy4KLSAgICAgICAgICAgIGRvY3Vt
ZW50LnF1ZXJ5U2VsZWN0b3JBbGwoJ1tkYXRhLW1hcmtldGluZy1tYWlsLXRvYXN0XScpLmZvckVh
Y2goZnVuY3Rpb24gKGFudGVyaW9yKSB7Ci0gICAgICAgICAgICAgICAgYm9vdHN0cmFwLlRvYXN0
LmdldEluc3RhbmNlKGFudGVyaW9yKT8uZGlzcG9zZSgpOwotICAgICAgICAgICAgICAgIGFudGVy
aW9yLnJlbW92ZSgpOwotICAgICAgICAgICAgfSk7CisgICAgICAgIGNvbnN0IG1vc3RyYXJUb2Fz
dCA9IGZ1bmN0aW9uIChtZW5zYWplLCBlc0Vycm9yKSB7CiAgICAgICAgICAgICBsZXQgY29udGVu
ZWRvciA9IGRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJy50b2FzdC1jb250YWluZXInKTsKIAogICAg
ICAgICAgICAgaWYgKCFjb250ZW5lZG9yKSB7CkBAIC0xMDIsNyArOTcsNiBAQAogICAgICAgICAg
ICAgfQogCiAgICAgICAgICAgICBjb25zdCB0b2FzdCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQo
J2RpdicpOwotICAgICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdkYXRhLW1hcmtldGluZy1t
YWlsLXRvYXN0JywgJycpOwogICAgICAgICAgICAgdG9hc3QuY2xhc3NOYW1lID0gJ3RvYXN0IHN5
c3RlbS10b2FzdCcgKwogICAgICAgICAgICAgICAgIChlc0Vycm9yID8gJyBzeXN0ZW0tdG9hc3Qt
ZXJyb3InIDogJycpOwogICAgICAgICAgICAgdG9hc3Quc2V0QXR0cmlidXRlKCdyb2xlJywgZXNF
cnJvciA/ICdhbGVydCcgOiAnc3RhdHVzJyk7CkBAIC0xMjIsMTUgKzExNiw2IEBACiAgICAgICAg
ICAgICAgICAgICAgID8gJ05vIGZ1ZSBwb3NpYmxlIGVudmlhciBlbCBjb3JyZW8uJwogICAgICAg
ICAgICAgICAgICAgICA6ICdDb3JyZW8gZW52aWFkbyBjb3JyZWN0YW1lbnRlLicpCiAgICAgICAg
ICAgICApOwotICAgICAgICAgICAgaWYgKGNvbmV4aW9uVXJsKSB7Ci0gICAgICAgICAgICAgICAg
Y29uc3QgdXJsID0gbmV3IFVSTChjb25leGlvblVybCwgd2luZG93LmxvY2F0aW9uLmhyZWYpOwot
ICAgICAgICAgICAgICAgIGlmICh1cmwub3JpZ2luID09PSB3aW5kb3cubG9jYXRpb24ub3JpZ2lu
ICYmIHVybC5zZWFyY2hQYXJhbXMuZ2V0KCdjb250cm9sbGVyJykgPT09ICdjb3JyZW9Db25leGlv
bicgJiYgdXJsLnNlYXJjaFBhcmFtcy5nZXQoJ2FjdGlvbicpID09PSAnaW5kZXgnKSB7Ci0gICAg
ICAgICAgICAgICAgICAgIGNvbnN0IGxpbmsgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdhJyk7
Ci0gICAgICAgICAgICAgICAgICAgIGxpbmsuaHJlZiA9IHVybC5ocmVmOyBsaW5rLnRhcmdldCA9
ICdfYmxhbmsnOyBsaW5rLnJlbCA9ICdub29wZW5lciBub3JlZmVycmVyJzsKLSAgICAgICAgICAg
ICAgICAgICAgbGluay50ZXh0Q29udGVudCA9ICcgQ29uZWN0YXIgY3VlbnRhIGRlIGNvcnJlbyc7
Ci0gICAgICAgICAgICAgICAgICAgIHRvYXN0LnF1ZXJ5U2VsZWN0b3IoJ3NwYW4nKS5hcHBlbmRD
aGlsZChsaW5rKTsKLSAgICAgICAgICAgICAgICB9Ci0gICAgICAgICAgICB9CiAKICAgICAgICAg
ICAgIGNvbnRlbmVkb3IuYXBwZW5kQ2hpbGQodG9hc3QpOwogICAgICAgICAgICAgdG9hc3QuYWRk
RXZlbnRMaXN0ZW5lcignaGlkZGVuLmJzLnRvYXN0JywgZnVuY3Rpb24gKCkgewpAQCAtMTM5LDkg
KzEyNCw2MyBAQAogCiAgICAgICAgICAgICBib290c3RyYXAuVG9hc3QuZ2V0T3JDcmVhdGVJbnN0
YW5jZSh0b2FzdCwgewogICAgICAgICAgICAgICAgIGF1dG9oaWRlOiB0cnVlLAotICAgICAgICAg
ICAgICAgIGRlbGF5OiBjb25leGlvblVybCA/IDMwMDAwIDogKGVzRXJyb3IgPyA0NTAwIDogMzAw
MCkKKyAgICAgICAgICAgICAgICBkZWxheTogZXNFcnJvciA/IDQ1MDAgOiAzMDAwCiAgICAgICAg
ICAgICB9KS5zaG93KCk7CiAgICAgICAgIH07CisKKyAgICAgICAgY29uc3QgZXN0YWRvUmVtaXRl
bnRlID0gbW9kYWxFbGVtZW50LnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLWhvc3Rpbmdlci1zZW5kZXIt
c3RhdHVzXScpOworICAgICAgICBjb25zdCBvcGNpb25lc1JlbWl0ZW50ZSA9IG1vZGFsRWxlbWVu
dC5xdWVyeVNlbGVjdG9yKCcjY29ycmVvX2hvc3Rpbmdlcl9yZW1pdGVudGVzJyk7CisgICAgICAg
IGxldCByZW1pdGVudGVzUGVybWl0aWRvcyA9IG51bGw7CisgICAgICAgIGxldCBjb25zdWx0YVJl
bWl0ZW50ZXMgPSAwOworICAgICAgICBsZXQgdWx0aW1vUmVmcmVzY29SZW1pdGVudGVzID0gMDsK
KyAgICAgICAgbGV0IGRpYWdub3N0aWNvUmVtaXRlbnRlcyA9IG51bGw7CisgICAgICAgIGNvbnN0
IHZhbGlkYXJSZW1pdGVudGVWaXNpYmxlID0gZnVuY3Rpb24gKCkgeworICAgICAgICAgICAgY29u
c3QgY29ycmVvID0gU3RyaW5nKHJlbWl0ZW50ZT8udmFsdWUgfHwgJycpLnRyaW0oKS50b0xvd2Vy
Q2FzZSgpOworICAgICAgICAgICAgY29uc3QgcGVybWl0aWRvID0gQXJyYXkuaXNBcnJheShyZW1p
dGVudGVzUGVybWl0aWRvcykgJiYgcmVtaXRlbnRlc1Blcm1pdGlkb3MuaW5jbHVkZXMoY29ycmVv
KTsKKyAgICAgICAgICAgIGlmIChlc3RhZG9SZW1pdGVudGUgJiYgQXJyYXkuaXNBcnJheShyZW1p
dGVudGVzUGVybWl0aWRvcykpIHsKKyAgICAgICAgICAgICAgICBjb25zdCB0b3RhbCA9IHJlbWl0
ZW50ZXNQZXJtaXRpZG9zLmxlbmd0aDsKKyAgICAgICAgICAgICAgICBlc3RhZG9SZW1pdGVudGUu
dGV4dENvbnRlbnQgPSBwZXJtaXRpZG8gPyAnUmVtaXRlbnRlIEhvc3RpbmdlciBhdXRvcml6YWRv
OiAnICsgY29ycmVvCisgICAgICAgICAgICAgICAgICAgIDogdG90YWwgPT09IDAgPyAnTGFzIHJl
c3RyaWNjaW9uZXMgZGVsIENSTSBubyBwZXJtaXRlbiB1dGlsaXphciBsb3MgYnV6b25lcyBkZXZ1
ZWx0b3MgcG9yIEhvc3Rpbmdlci4gU29saWNpdGEgcmV2aXNhciBsYSBjb25maWd1cmFjacOzbiBj
b21lcmNpYWwuJworICAgICAgICAgICAgICAgICAgICA6IGNvcnJlbyA9PT0gJycgPyAnU2VsZWNj
aW9uYSB1bm8gZGUgbG9zICcgKyB0b3RhbCArICcgYnV6b25lcyBpbnN0aXR1Y2lvbmFsZXMgYXV0
b3JpemFkb3MuJworICAgICAgICAgICAgICAgICAgICA6ICdFc3RhIGRpcmVjY2nDs24gbm8gZXN0
w6EgZW50cmUgbG9zICcgKyB0b3RhbCArICcgYnV6b25lcyBwZXJtaXRpZG9zLiBIb3N0aW5nZXIg
ZGV2b2x2acOzICcgKworICAgICAgICAgICAgICAgICAgICAgICAgKGRpYWdub3N0aWNvUmVtaXRl
bnRlcz8udG90YWxfaW5zdGl0dWNpb25hbCA/PyB0b3RhbCkgKyAnIGJ1em9uZXMgaW5zdGl0dWNp
b25hbGVzJyArCisgICAgICAgICAgICAgICAgICAgICAgICAoZGlhZ25vc3RpY29SZW1pdGVudGVz
Py5yZXN0cmljY2lvbmVzX2xvY2FsZXMgPyAnIHkgZXhpc3RlbiByZXN0cmljY2lvbmVzIGxvY2Fs
ZXMgZGVsIENSTS4nIDogJy4gUmV2aXNhIGVsIGFsY2FuY2UgZGVsIHRva2VuIHNpIGZhbHRhIHVu
IGJ1esOzbi4nKTsKKyAgICAgICAgICAgIH0KKyAgICAgICAgICAgIHJldHVybiBwZXJtaXRpZG87
CisgICAgICAgIH07CisgICAgICAgIGNvbnN0IGNhcmdhclJlbWl0ZW50ZXMgPSBhc3luYyBmdW5j
dGlvbiAoKSB7CisgICAgICAgICAgICBjb25zdCBjb25zdWx0YSA9ICsrY29uc3VsdGFSZW1pdGVu
dGVzOworICAgICAgICAgICAgdWx0aW1vUmVmcmVzY29SZW1pdGVudGVzID0gRGF0ZS5ub3coKTsK
KyAgICAgICAgICAgIHJlbWl0ZW50ZXNQZXJtaXRpZG9zID0gbnVsbDsKKyAgICAgICAgICAgIGRp
YWdub3N0aWNvUmVtaXRlbnRlcyA9IG51bGw7CisgICAgICAgICAgICBvcGNpb25lc1JlbWl0ZW50
ZT8ucmVwbGFjZUNoaWxkcmVuKCk7CisgICAgICAgICAgICBpZiAoZXN0YWRvUmVtaXRlbnRlKSB7
IGVzdGFkb1JlbWl0ZW50ZS50ZXh0Q29udGVudCA9ICdDb25zdWx0YW5kbyByZW1pdGVudGVzIEhv
c3RpbmdlcuKApic7IH0KKyAgICAgICAgICAgIHRyeSB7CisgICAgICAgICAgICAgICAgY29uc3Qg
cmVzcG9uc2UgPSBhd2FpdCBmZXRjaCgnaW5kZXgucGhwP2NvbnRyb2xsZXI9Y29ycmVvTWFya2V0
aW5nJmFjdGlvbj1yZW1pdGVudGVzJywge2hlYWRlcnM6eydYLVJlcXVlc3RlZC1XaXRoJzonZmV0
Y2gnfSxjYWNoZTonbm8tc3RvcmUnfSk7CisgICAgICAgICAgICAgICAgY29uc3QganNvbiA9IGF3
YWl0IHJlc3BvbnNlLmpzb24oKTsKKyAgICAgICAgICAgICAgICBpZiAoY29uc3VsdGEgIT09IGNv
bnN1bHRhUmVtaXRlbnRlcykgeyByZXR1cm4gZmFsc2U7IH0KKyAgICAgICAgICAgICAgICBpZiAo
IXJlc3BvbnNlLm9rIHx8ICFqc29uLm9rKSB7IHRocm93IG5ldyBFcnJvcihqc29uLm1lbnNhamUg
fHwgJ05vIHNlIHB1ZGllcm9uIGNvbnN1bHRhciBsb3MgcmVtaXRlbnRlcyBIb3N0aW5nZXIuJyk7
IH0KKyAgICAgICAgICAgICAgICBpZiAoIUFycmF5LmlzQXJyYXkoanNvbi5yZW1pdGVudGVzKSB8
fCBqc29uLnJlbWl0ZW50ZXMuc29tZShmdW5jdGlvbiAoY29ycmVvKSB7IHJldHVybiB0eXBlb2Yg
Y29ycmVvICE9PSAnc3RyaW5nJzsgfSkpIHsKKyAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3
IEVycm9yKCdMYSBsaXN0YSBkZSBidXpvbmVzIHJlY2liaWRhIG5vIGVzIHbDoWxpZGEuIFZ1ZWx2
ZSBhIGFicmlyIGVsIGZvcm11bGFyaW8uJyk7CisgICAgICAgICAgICAgICAgfQorICAgICAgICAg
ICAgICAgIHJlbWl0ZW50ZXNQZXJtaXRpZG9zID0gQXJyYXkuZnJvbShuZXcgU2V0KGpzb24ucmVt
aXRlbnRlcykpOworICAgICAgICAgICAgICAgIGRpYWdub3N0aWNvUmVtaXRlbnRlcyA9IGpzb24u
ZGlhZ25vc3RpY28gfHwgbnVsbDsKKyAgICAgICAgICAgICAgICB1bHRpbW9SZWZyZXNjb1JlbWl0
ZW50ZXMgPSBEYXRlLm5vdygpOworICAgICAgICAgICAgICAgIHJlbWl0ZW50ZXNQZXJtaXRpZG9z
LmZvckVhY2goZnVuY3Rpb24gKGNvcnJlbykgeworICAgICAgICAgICAgICAgICAgICBjb25zdCBv
cHRpb24gPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdvcHRpb24nKTsgb3B0aW9uLnZhbHVlID0g
Y29ycmVvOyBvcGNpb25lc1JlbWl0ZW50ZT8uYXBwZW5kQ2hpbGQob3B0aW9uKTsKKyAgICAgICAg
ICAgICAgICB9KTsKKyAgICAgICAgICAgICAgICB2YWxpZGFyUmVtaXRlbnRlVmlzaWJsZSgpOwor
ICAgICAgICAgICAgICAgIHJldHVybiB0cnVlOworICAgICAgICAgICAgfSBjYXRjaCAoZXJyb3Ip
IHsKKyAgICAgICAgICAgICAgICBpZiAoY29uc3VsdGEgPT09IGNvbnN1bHRhUmVtaXRlbnRlcyAm
JiBlc3RhZG9SZW1pdGVudGUpIHsgZXN0YWRvUmVtaXRlbnRlLnRleHRDb250ZW50ID0gZXJyb3Iu
bWVzc2FnZTsgfQorICAgICAgICAgICAgICAgIHJldHVybiBmYWxzZTsKKyAgICAgICAgICAgIH0K
KyAgICAgICAgfTsKKyAgICAgICAgbW9kYWxFbGVtZW50LmFkZEV2ZW50TGlzdGVuZXIoJ3Nob3du
LmJzLm1vZGFsJywgY2FyZ2FyUmVtaXRlbnRlcyk7CisgICAgICAgIHJlbWl0ZW50ZT8uYWRkRXZl
bnRMaXN0ZW5lcignaW5wdXQnLCB2YWxpZGFyUmVtaXRlbnRlVmlzaWJsZSk7CisgICAgICAgIHJl
bWl0ZW50ZT8uYWRkRXZlbnRMaXN0ZW5lcignZm9jdXMnLCBmdW5jdGlvbiAoKSB7CisgICAgICAg
ICAgICBpZiAoRGF0ZS5ub3coKSAtIHVsdGltb1JlZnJlc2NvUmVtaXRlbnRlcyA+IDMwMDAwKSB7
IGNhcmdhclJlbWl0ZW50ZXMoKTsgfQorICAgICAgICB9KTsKIAogICAgICAgICBjb25zdCBtb3N0
cmFyRXJyb3IgPSBmdW5jdGlvbiAobWVuc2FqZSkgewogICAgICAgICAgICAgbW9zdHJhclRvYXN0
KApAQCAtMTMwOSw2ICsxMzQ4LDE1IEBACiAgICAgICAgICAgICAgICAgcmV0dXJuOwogICAgICAg
ICAgICAgfQogCisgICAgICAgICAgICAvLyBBY3R1YWxpemFyIGFudGVzIGRlIHZhbGlkYXIgZXZp
dGEgYmxvcXVlYXIgYnV6b25lcyByZWNpw6luIGF1dG9yaXphZG9zIHBvciB1bmEgbGlzdGEgdmll
amEuCisgICAgICAgICAgICBlbnZpYW5kbyA9IHRydWU7CisgICAgICAgICAgICB0cnkgeworICAg
ICAgICAgICAgICAgIGlmICghKGF3YWl0IGNhcmdhclJlbWl0ZW50ZXMoKSkgfHwgIXZhbGlkYXJS
ZW1pdGVudGVWaXNpYmxlKCkpIHsKKyAgICAgICAgICAgICAgICAgICAgbW9zdHJhckVycm9yKGVz
dGFkb1JlbWl0ZW50ZT8udGV4dENvbnRlbnQgfHwgJ05vIGZ1ZSBwb3NpYmxlIGNvbXByb2JhciBs
b3MgYnV6b25lcyBIb3N0aW5nZXIuJyk7CisgICAgICAgICAgICAgICAgICAgIHJlbWl0ZW50ZT8u
Zm9jdXMoKTsgcmV0dXJuOworICAgICAgICAgICAgICAgIH0KKyAgICAgICAgICAgIH0gZmluYWxs
eSB7IGVudmlhbmRvID0gZmFsc2U7IH0KKwogICAgICAgICAgICAgY29uc3QgZXJyb3JBcmNoaXZv
cyA9IHZhbGlkYXJBcmNoaXZvcygpOwogICAgICAgICAgICAgaWYgKGVycm9yQXJjaGl2b3MgIT09
ICcnKSB7CiAgICAgICAgICAgICAgICAgbW9zdHJhckVycm9yKGVycm9yQXJjaGl2b3MpOwpAQCAt
MTM0OCwxMCArMTM5Niw2IEBACiAgICAgICAgICAgICAgICAgfQogCiAgICAgICAgICAgICAgICAg
aWYgKCFyZXNwdWVzdGEub2sgfHwgIWpzb24ub2spIHsKLSAgICAgICAgICAgICAgICAgICAgaWYg
KGpzb24uY29uZXhpb25fcmVxdWVyaWRhICYmIGpzb24uY29uZXhpb25fdXJsKSB7Ci0gICAgICAg
ICAgICAgICAgICAgICAgICBtb3N0cmFyVG9hc3QoanNvbi5tZW5zYWplLCB0cnVlLCBqc29uLmNv
bmV4aW9uX3VybCk7Ci0gICAgICAgICAgICAgICAgICAgICAgICByZXR1cm47Ci0gICAgICAgICAg
ICAgICAgICAgIH0KICAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKAogICAgICAg
ICAgICAgICAgICAgICAgICAganNvbi5tZW5zYWplIHx8CiAgICAgICAgICAgICAgICAgICAgICAg
ICAnTm8gZnVlIHBvc2libGUgZW52aWFyIGVsIGNvcnJlby4nCg==
CENTRAL_PATCH_4_3
 [[ "$(digest "$TMP/patches/4/3.patch")" == '2a2b16e737f0628a6cc0f291d94af73edbf827d9ddb028299197ad073bfb0a66' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/4/4.patch" <<'CENTRAL_PATCH_4_4'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9qYXZhc2NyaXB0L2NvcnJlb3NfbWFya2V0aW5nLmpzIGIvcHVi
bGljL2phdmFzY3JpcHQvY29ycmVvc19tYXJrZXRpbmcuanMKLS0tIGEvcHVibGljL2phdmFzY3Jp
cHQvY29ycmVvc19tYXJrZXRpbmcuanMKKysrIGIvcHVibGljL2phdmFzY3JpcHQvY29ycmVvc19t
YXJrZXRpbmcuanMKQEAgLTEzMiwzNCArMTMyLDU1IEBACiAgICAgICAgIGNvbnN0IG9wY2lvbmVz
UmVtaXRlbnRlID0gbW9kYWxFbGVtZW50LnF1ZXJ5U2VsZWN0b3IoJyNjb3JyZW9faG9zdGluZ2Vy
X3JlbWl0ZW50ZXMnKTsKICAgICAgICAgbGV0IHJlbWl0ZW50ZXNQZXJtaXRpZG9zID0gbnVsbDsK
ICAgICAgICAgbGV0IGNvbnN1bHRhUmVtaXRlbnRlcyA9IDA7CisgICAgICAgIGxldCB1bHRpbW9S
ZWZyZXNjb1JlbWl0ZW50ZXMgPSAwOworICAgICAgICBsZXQgZGlhZ25vc3RpY29SZW1pdGVudGVz
ID0gbnVsbDsKICAgICAgICAgY29uc3QgdmFsaWRhclJlbWl0ZW50ZVZpc2libGUgPSBmdW5jdGlv
biAoKSB7CiAgICAgICAgICAgICBjb25zdCBjb3JyZW8gPSBTdHJpbmcocmVtaXRlbnRlPy52YWx1
ZSB8fCAnJykudHJpbSgpLnRvTG93ZXJDYXNlKCk7CiAgICAgICAgICAgICBjb25zdCBwZXJtaXRp
ZG8gPSBBcnJheS5pc0FycmF5KHJlbWl0ZW50ZXNQZXJtaXRpZG9zKSAmJiByZW1pdGVudGVzUGVy
bWl0aWRvcy5pbmNsdWRlcyhjb3JyZW8pOwogICAgICAgICAgICAgaWYgKGVzdGFkb1JlbWl0ZW50
ZSAmJiBBcnJheS5pc0FycmF5KHJlbWl0ZW50ZXNQZXJtaXRpZG9zKSkgeworICAgICAgICAgICAg
ICAgIGNvbnN0IHRvdGFsID0gcmVtaXRlbnRlc1Blcm1pdGlkb3MubGVuZ3RoOwogICAgICAgICAg
ICAgICAgIGVzdGFkb1JlbWl0ZW50ZS50ZXh0Q29udGVudCA9IHBlcm1pdGlkbyA/ICdSZW1pdGVu
dGUgSG9zdGluZ2VyIGF1dG9yaXphZG86ICcgKyBjb3JyZW8KLSAgICAgICAgICAgICAgICAgICAg
OiAnU2VsZWNjaW9uYSB1bmEgZGlyZWNjacOzbiBIb3N0aW5nZXIgYXV0b3JpemFkYSBkZSBsYSBs
aXN0YS4gTm8gc2Ugc3VzdGl0dWlyw6EgZWwgcmVtaXRlbnRlLic7CisgICAgICAgICAgICAgICAg
ICAgIDogdG90YWwgPT09IDAgPyAnTGFzIHJlc3RyaWNjaW9uZXMgZGVsIENSTSBubyBwZXJtaXRl
biB1dGlsaXphciBsb3MgYnV6b25lcyBkZXZ1ZWx0b3MgcG9yIEhvc3Rpbmdlci4gU29saWNpdGEg
cmV2aXNhciBsYSBjb25maWd1cmFjacOzbiBjb21lcmNpYWwuJworICAgICAgICAgICAgICAgICAg
ICA6IGNvcnJlbyA9PT0gJycgPyAnU2VsZWNjaW9uYSB1bm8gZGUgbG9zICcgKyB0b3RhbCArICcg
YnV6b25lcyBpbnN0aXR1Y2lvbmFsZXMgYXV0b3JpemFkb3MuJworICAgICAgICAgICAgICAgICAg
ICA6ICdFc3RhIGRpcmVjY2nDs24gbm8gZXN0w6EgZW50cmUgbG9zICcgKyB0b3RhbCArICcgYnV6
b25lcyBwZXJtaXRpZG9zLiBIb3N0aW5nZXIgZGV2b2x2acOzICcgKworICAgICAgICAgICAgICAg
ICAgICAgICAgKGRpYWdub3N0aWNvUmVtaXRlbnRlcz8udG90YWxfaW5zdGl0dWNpb25hbCA/PyB0
b3RhbCkgKyAnIGJ1em9uZXMgaW5zdGl0dWNpb25hbGVzJyArCisgICAgICAgICAgICAgICAgICAg
ICAgICAoZGlhZ25vc3RpY29SZW1pdGVudGVzPy5yZXN0cmljY2lvbmVzX2xvY2FsZXMgPyAnIHkg
ZXhpc3RlbiByZXN0cmljY2lvbmVzIGxvY2FsZXMgZGVsIENSTS4nIDogJy4gUmV2aXNhIGVsIGFs
Y2FuY2UgZGVsIHRva2VuIHNpIGZhbHRhIHVuIGJ1esOzbi4nKTsKICAgICAgICAgICAgIH0KICAg
ICAgICAgICAgIHJldHVybiBwZXJtaXRpZG87CiAgICAgICAgIH07CiAgICAgICAgIGNvbnN0IGNh
cmdhclJlbWl0ZW50ZXMgPSBhc3luYyBmdW5jdGlvbiAoKSB7CiAgICAgICAgICAgICBjb25zdCBj
b25zdWx0YSA9ICsrY29uc3VsdGFSZW1pdGVudGVzOworICAgICAgICAgICAgdWx0aW1vUmVmcmVz
Y29SZW1pdGVudGVzID0gRGF0ZS5ub3coKTsKICAgICAgICAgICAgIHJlbWl0ZW50ZXNQZXJtaXRp
ZG9zID0gbnVsbDsKKyAgICAgICAgICAgIGRpYWdub3N0aWNvUmVtaXRlbnRlcyA9IG51bGw7Cisg
ICAgICAgICAgICBvcGNpb25lc1JlbWl0ZW50ZT8ucmVwbGFjZUNoaWxkcmVuKCk7CiAgICAgICAg
ICAgICBpZiAoZXN0YWRvUmVtaXRlbnRlKSB7IGVzdGFkb1JlbWl0ZW50ZS50ZXh0Q29udGVudCA9
ICdDb25zdWx0YW5kbyByZW1pdGVudGVzIEhvc3RpbmdlcuKApic7IH0KICAgICAgICAgICAgIHRy
eSB7CiAgICAgICAgICAgICAgICAgY29uc3QgcmVzcG9uc2UgPSBhd2FpdCBmZXRjaCgnaW5kZXgu
cGhwP2NvbnRyb2xsZXI9Y29ycmVvTWFya2V0aW5nJmFjdGlvbj1yZW1pdGVudGVzJywge2hlYWRl
cnM6eydYLVJlcXVlc3RlZC1XaXRoJzonZmV0Y2gnfSxjYWNoZTonbm8tc3RvcmUnfSk7CiAgICAg
ICAgICAgICAgICAgY29uc3QganNvbiA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKTsKLSAgICAgICAg
ICAgICAgICBpZiAoY29uc3VsdGEgIT09IGNvbnN1bHRhUmVtaXRlbnRlcykgeyByZXR1cm47IH0K
KyAgICAgICAgICAgICAgICBpZiAoY29uc3VsdGEgIT09IGNvbnN1bHRhUmVtaXRlbnRlcykgeyBy
ZXR1cm4gZmFsc2U7IH0KICAgICAgICAgICAgICAgICBpZiAoIXJlc3BvbnNlLm9rIHx8ICFqc29u
Lm9rKSB7IHRocm93IG5ldyBFcnJvcihqc29uLm1lbnNhamUgfHwgJ05vIHNlIHB1ZGllcm9uIGNv
bnN1bHRhciBsb3MgcmVtaXRlbnRlcyBIb3N0aW5nZXIuJyk7IH0KLSAgICAgICAgICAgICAgICBy
ZW1pdGVudGVzUGVybWl0aWRvcyA9IGpzb24ucmVtaXRlbnRlczsKLSAgICAgICAgICAgICAgICBv
cGNpb25lc1JlbWl0ZW50ZT8ucmVwbGFjZUNoaWxkcmVuKCk7CisgICAgICAgICAgICAgICAgaWYg
KCFBcnJheS5pc0FycmF5KGpzb24ucmVtaXRlbnRlcykgfHwganNvbi5yZW1pdGVudGVzLnNvbWUo
ZnVuY3Rpb24gKGNvcnJlbykgeyByZXR1cm4gdHlwZW9mIGNvcnJlbyAhPT0gJ3N0cmluZyc7IH0p
KSB7CisgICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcignTGEgbGlzdGEgZGUgYnV6
b25lcyByZWNpYmlkYSBubyBlcyB2w6FsaWRhLiBWdWVsdmUgYSBhYnJpciBlbCBmb3JtdWxhcmlv
LicpOworICAgICAgICAgICAgICAgIH0KKyAgICAgICAgICAgICAgICByZW1pdGVudGVzUGVybWl0
aWRvcyA9IEFycmF5LmZyb20obmV3IFNldChqc29uLnJlbWl0ZW50ZXMpKTsKKyAgICAgICAgICAg
ICAgICBkaWFnbm9zdGljb1JlbWl0ZW50ZXMgPSBqc29uLmRpYWdub3N0aWNvIHx8IG51bGw7Cisg
ICAgICAgICAgICAgICAgdWx0aW1vUmVmcmVzY29SZW1pdGVudGVzID0gRGF0ZS5ub3coKTsKICAg
ICAgICAgICAgICAgICByZW1pdGVudGVzUGVybWl0aWRvcy5mb3JFYWNoKGZ1bmN0aW9uIChjb3Jy
ZW8pIHsKICAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3B0aW9uID0gZG9jdW1lbnQuY3JlYXRl
RWxlbWVudCgnb3B0aW9uJyk7IG9wdGlvbi52YWx1ZSA9IGNvcnJlbzsgb3BjaW9uZXNSZW1pdGVu
dGU/LmFwcGVuZENoaWxkKG9wdGlvbik7CiAgICAgICAgICAgICAgICAgfSk7CiAgICAgICAgICAg
ICAgICAgdmFsaWRhclJlbWl0ZW50ZVZpc2libGUoKTsKLSAgICAgICAgICAgIH0gY2F0Y2ggKGVy
cm9yKSB7IGlmIChjb25zdWx0YSA9PT0gY29uc3VsdGFSZW1pdGVudGVzICYmIGVzdGFkb1JlbWl0
ZW50ZSkgeyBlc3RhZG9SZW1pdGVudGUudGV4dENvbnRlbnQgPSBlcnJvci5tZXNzYWdlOyB9IH0K
KyAgICAgICAgICAgICAgICByZXR1cm4gdHJ1ZTsKKyAgICAgICAgICAgIH0gY2F0Y2ggKGVycm9y
KSB7CisgICAgICAgICAgICAgICAgaWYgKGNvbnN1bHRhID09PSBjb25zdWx0YVJlbWl0ZW50ZXMg
JiYgZXN0YWRvUmVtaXRlbnRlKSB7IGVzdGFkb1JlbWl0ZW50ZS50ZXh0Q29udGVudCA9IGVycm9y
Lm1lc3NhZ2U7IH0KKyAgICAgICAgICAgICAgICByZXR1cm4gZmFsc2U7CisgICAgICAgICAgICB9
CiAgICAgICAgIH07CiAgICAgICAgIG1vZGFsRWxlbWVudC5hZGRFdmVudExpc3RlbmVyKCdzaG93
bi5icy5tb2RhbCcsIGNhcmdhclJlbWl0ZW50ZXMpOwogICAgICAgICByZW1pdGVudGU/LmFkZEV2
ZW50TGlzdGVuZXIoJ2lucHV0JywgdmFsaWRhclJlbWl0ZW50ZVZpc2libGUpOworICAgICAgICBy
ZW1pdGVudGU/LmFkZEV2ZW50TGlzdGVuZXIoJ2ZvY3VzJywgZnVuY3Rpb24gKCkgeworICAgICAg
ICAgICAgaWYgKERhdGUubm93KCkgLSB1bHRpbW9SZWZyZXNjb1JlbWl0ZW50ZXMgPiAzMDAwMCkg
eyBjYXJnYXJSZW1pdGVudGVzKCk7IH0KKyAgICAgICAgfSk7CiAKICAgICAgICAgY29uc3QgbW9z
dHJhckVycm9yID0gZnVuY3Rpb24gKG1lbnNhamUpIHsKICAgICAgICAgICAgIG1vc3RyYXJUb2Fz
dCgKQEAgLTEzMjcsOSArMTM0OCwxNCBAQAogICAgICAgICAgICAgICAgIHJldHVybjsKICAgICAg
ICAgICAgIH0KIAotICAgICAgICAgICAgaWYgKCF2YWxpZGFyUmVtaXRlbnRlVmlzaWJsZSgpKSB7
Ci0gICAgICAgICAgICAgICAgbW9zdHJhckVycm9yKGVzdGFkb1JlbWl0ZW50ZT8udGV4dENvbnRl
bnQgfHwgJ1NlbGVjY2lvbmEgdW4gcmVtaXRlbnRlIEhvc3RpbmdlciBhdXRvcml6YWRvLicpOyBy
ZW1pdGVudGU/LmZvY3VzKCk7IHJldHVybjsKLSAgICAgICAgICAgIH0KKyAgICAgICAgICAgIC8v
IEFjdHVhbGl6YXIgYW50ZXMgZGUgdmFsaWRhciBldml0YSBibG9xdWVhciBidXpvbmVzIHJlY2nD
qW4gYXV0b3JpemFkb3MgcG9yIHVuYSBsaXN0YSB2aWVqYS4KKyAgICAgICAgICAgIGVudmlhbmRv
ID0gdHJ1ZTsKKyAgICAgICAgICAgIHRyeSB7CisgICAgICAgICAgICAgICAgaWYgKCEoYXdhaXQg
Y2FyZ2FyUmVtaXRlbnRlcygpKSB8fCAhdmFsaWRhclJlbWl0ZW50ZVZpc2libGUoKSkgeworICAg
ICAgICAgICAgICAgICAgICBtb3N0cmFyRXJyb3IoZXN0YWRvUmVtaXRlbnRlPy50ZXh0Q29udGVu
dCB8fCAnTm8gZnVlIHBvc2libGUgY29tcHJvYmFyIGxvcyBidXpvbmVzIEhvc3Rpbmdlci4nKTsK
KyAgICAgICAgICAgICAgICAgICAgcmVtaXRlbnRlPy5mb2N1cygpOyByZXR1cm47CisgICAgICAg
ICAgICAgICAgfQorICAgICAgICAgICAgfSBmaW5hbGx5IHsgZW52aWFuZG8gPSBmYWxzZTsgfQog
CiAgICAgICAgICAgICBjb25zdCBlcnJvckFyY2hpdm9zID0gdmFsaWRhckFyY2hpdm9zKCk7CiAg
ICAgICAgICAgICBpZiAoZXJyb3JBcmNoaXZvcyAhPT0gJycpIHsK
CENTRAL_PATCH_4_4
 [[ "$(digest "$TMP/patches/4/4.patch")" == '6c716b87df19c1629a55fb7c7f151afb8027d2c77be1148ffda904bc4035b9d6' ]] || fail 'Parche de entrega alterado.'
 mkdir -p "$TMP/patches/5"
 base64 --decode > "$TMP/patches/5/1.patch" <<'CENTRAL_PATCH_5_1'
ZGlmZiAtLWdpdCBhL2luZGV4LnBocCBiL2luZGV4LnBocAotLS0gYS9pbmRleC5waHAKKysrIGIv
aW5kZXgucGhwCkBAIC0yMjEsNiArMjIxLDcgQEAKICAqIGF1dG9tw6F0aWNhbWVudGUgZGVzZGUg
ZGFzaGJvYXJkX2hlYWQucGhwLgogICovCiAkY29udHJvbGFkb3Jlc1Byb3RlZ2lkb3NDc3JmID0g
WworICAgICdjb3JyZW9NYXJrZXRpbmcnLAogICAgICdzZWd1aW1pZW50b1ZpbmN1bGFjaW9uJywK
ICAgICAnc2VndWltaWVudG9GbHVqbycsCiAgICAgJ3NlZ3VpbWllbnRvSW50ZXJhY2Npb24nLApA
QCAtNTY0LDYgKzU2NSwxMiBAQAogICAgICAgICBicmVhazsKIAogCisgICAgLy8gQ29tcGF0aWJp
bGlkYWQgY29uIGVubGFjZXMgY29tZXJjaWFsZXMgYW50ZXJpb3Jlczogbm8gcHJvY2VzYXIgT0F1
dGggcGVyc29uYWwuCisgICAgY2FzZSAnY29ycmVvQ29uZXhpb24nOgorICAgICAgICBoZWFkZXIo
J0xvY2F0aW9uOiAnIC4gQkFTRV9VUkwgLiAoc3RyY2FzZWNtcCgoc3RyaW5nKSgkX1NFU1NJT05b
J3JvbCddID8/ICcnKSwnTWFya2V0aW5nJyk9PT0wCisgICAgICAgICAgICA/ICdpbmRleC5waHA/
Y29udHJvbGxlcj1jb3JyZW9NYXJrZXRpbmcmYWN0aW9uPWluZGV4JyA6ICdpbmRleC5waHA/Y29u
dHJvbGxlcj1ob21lJmFjdGlvbj1pbmRleCcpKTsKKyAgICAgICAgZXhpdDsKKwogICAgIGNhc2Ug
J2NvcnJlb01hcmtldGluZyc6CiAKICAgICAgICAgcmVxdWlyZV9vbmNlIF9fRElSX18gLgo=
CENTRAL_PATCH_5_1
 [[ "$(digest "$TMP/patches/5/1.patch")" == '61f5303e24efdb7a2f4ed9f03637b3b007cdabeeff2e2688b8a78365fc61871e' ]] || fail 'Parche de entrega alterado.'
 base64 --decode > "$TMP/patches/5/2.patch" <<'CENTRAL_PATCH_5_2'
ZGlmZiAtLWdpdCBhL2luZGV4LnBocCBiL2luZGV4LnBocAotLS0gYS9pbmRleC5waHAKKysrIGIv
aW5kZXgucGhwCkBAIC01NjUsMTAgKzU2NSwxMSBAQAogICAgICAgICBicmVhazsKIAogCisgICAg
Ly8gQ29tcGF0aWJpbGlkYWQgY29uIGVubGFjZXMgY29tZXJjaWFsZXMgYW50ZXJpb3Jlczogbm8g
cHJvY2VzYXIgT0F1dGggcGVyc29uYWwuCiAgICAgY2FzZSAnY29ycmVvQ29uZXhpb24nOgotICAg
ICAgICByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvYXBwL2NvbnRyb2xsZXJzL0NvcnJlb0NvbmV4
aW9uQ29udHJvbGxlci5waHAnOwotICAgICAgICAkY29udHJvbGxlckluc3RhbmNlID0gbmV3IENv
cnJlb0NvbmV4aW9uQ29udHJvbGxlcigpOwotICAgICAgICBicmVhazsKKyAgICAgICAgaGVhZGVy
KCdMb2NhdGlvbjogJyAuIEJBU0VfVVJMIC4gKHN0cmNhc2VjbXAoKHN0cmluZykoJF9TRVNTSU9O
Wydyb2wnXSA/PyAnJyksJ01hcmtldGluZycpPT09MAorICAgICAgICAgICAgPyAnaW5kZXgucGhw
P2NvbnRyb2xsZXI9Y29ycmVvTWFya2V0aW5nJmFjdGlvbj1pbmRleCcgOiAnaW5kZXgucGhwP2Nv
bnRyb2xsZXI9aG9tZSZhY3Rpb249aW5kZXgnKSk7CisgICAgICAgIGV4aXQ7CiAKICAgICBjYXNl
ICdjb3JyZW9NYXJrZXRpbmcnOgogCkBAIC03MDMsNCArNzA0LDQgQEAKIH0KIAogCi0kY29udHJv
bGxlckluc3RhbmNlLT4kYWN0aW9uKCk7CiskY29udHJvbGxlckluc3RhbmNlLT4kYWN0aW9uKCk7
ClwgTm8gbmV3bGluZSBhdCBlbmQgb2YgZmlsZQo=
CENTRAL_PATCH_5_2
 [[ "$(digest "$TMP/patches/5/2.patch")" == 'dcb5053f53b2430a6d74fc83cea990e5e17e11496a96b94196fb3c53a327c7f8' ]] || fail 'Parche de entrega alterado.'
 declare -A EOLS=()
 f='app/services/CorreoSalidaInstitucionalService.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '0745bbb8e64ec30e3ff7f1d588d35a2803a73a12f14d1a33818301aa978472e1' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '554b402353038e218c55540d79ca223a421082e276c8ddbd74c65b4a9d5c3f68' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/0/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/0/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '6a287dfd3f0bde4a708b86158806754d6779a88210df53c3ff683f435f57679b' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/0/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/0/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '4007f9192323cbf6831e1b383aebc3b407b778a6bbe137abab63cb06deff4dff' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/0/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/0/3.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '7d76e53b38c61523c2a8243aef4920385119e9024a9a6fe02e816a86fd38d017' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/0/4.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/0/4.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '0745bbb8e64ec30e3ff7f1d588d35a2803a73a12f14d1a33818301aa978472e1' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/services/CorreoMarketingService.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '7146ee6e7f77038feaec8fdd5fdc6df5cab4a0f99a48afa088c7d0e97fb2d513' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == 'd3d0b336054a77638f67c5c1b0903a281efb2901265792bb4c150b17f0000e03' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/1/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/1/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '4962e63bbb777300567e9e59d2a0ad0701a23ceffdf11384381f1c7246c0ddc2' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/1/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/1/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '8d2aa728cf5dcce219905a3d01efe5c3dc36e266087d600128c8d0402efad1d5' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/1/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/1/3.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '7146ee6e7f77038feaec8fdd5fdc6df5cab4a0f99a48afa088c7d0e97fb2d513' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/controllers/CorreoMarketingController.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '206fe77365a4f9d016f7386daf970ed74001847b968e4df67e31bebf00d685c6' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == 'e77c73702142a2ffb914aa30aa1a860649c1395ddafc5108bf613db9aa5fb1bc' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/2/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/2/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '680c5d70f19beb9332f2f5cd9b805918c69d3449be99af48b17f06adb416891d' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/2/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/2/2.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '206fe77365a4f9d016f7386daf970ed74001847b968e4df67e31bebf00d685c6' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='app/views/correos/index.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '1f826a4a5bf17bb5a9fa8dc9e10b51062711f047ee87ecadd78e68987236623c' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '05fc342591ba9f63c1ba16bd694cc7141a657a2323576051457350e2b2c82ad1' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/3/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/3/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '2f130c3a2c4d8c54b80a1823ad590a9ff8a598cc0a1130879911d3fe196b0778' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/3/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/3/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '0c40de7cfe1599ac8395af31cf7cafd8d82efeaaa7addf625b86f71a5199882f' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/3/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/3/3.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '1f826a4a5bf17bb5a9fa8dc9e10b51062711f047ee87ecadd78e68987236623c' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='public/javascript/correos_marketing.js'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != '449702ed396460134a9a7708376eba711721721d0cc9f4fa849bb4e31d48c374' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '5b6c82a1c2f92c9b0315e928c355a3f1af599b234830b29e3b1c15e425fbbdff' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/4/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/4/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '3ad842a04ddbd77c16cd939635e44e0249ede2df0a3f2d62eb479d4ebbb7611f' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/4/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/4/2.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '2f8054f9d1731201ceacec75331c019c5100a17b2edf4ce48af33b14e32e96af' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/4/3.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/4/3.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == 'ab13ab708f0f53670e45b956c4616c10a1d710a1d367bfec6849420ac6f9007f' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/4/4.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/4/4.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == '449702ed396460134a9a7708376eba711721721d0cc9f4fa849bb4e31d48c374' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
 fi
 f='index.php'
 if [[ "${BEFORE[$f]}" == MISSING ]];then
  [[ "$f" == "$DIAGNOSTIC" ]] || fail "Falta archivo requerido: $f"
  cp -- "$TMP/diagnostic.php" "$TMP/stage/$f"
 else cp -p -- "$LIVE/$f" "$TMP/stage/$f";fi
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual_code="$(code_digest "$TMP/stage/$f")"
 if [[ "$actual_code" != 'db7ac34b4e078a62bd3d59db9abce4797c7823d6ed7431af919a86310a881dfa' ]];then
  ready=0
  if [[ "$ready" == 0 && "$actual_code" == '06b8cad93d5f7560ebab46b6d0047b805c9fe001c78acbafba412b8f0c7b4b80' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/5/1.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/5/1.patch");ready=1
   fi
  fi
  if [[ "$ready" == 0 && "$actual_code" == '58163994defcea8d5e4d1d18550a81c2caf22b84e448a1e7ec7636923aab3a28' ]];then
   if (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/patches/5/2.patch" >/dev/null 2>&1);then
    (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/patches/5/2.patch");ready=1
   fi
  fi

  [[ "$ready" == 1 ]] || fail "Versión personalizada/incompatible: $f. SITIO INTACTO."
  [[ "$(code_digest "$TMP/stage/$f")" == 'db7ac34b4e078a62bd3d59db9abce4797c7823d6ed7431af919a86310a881dfa' ]] || fail "Código resultante distinto de la revisión: $f. SITIO INTACTO."
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
