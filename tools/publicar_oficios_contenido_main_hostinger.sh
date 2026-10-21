#!/usr/bin/env bash
# Publicación exclusiva de preparación DOCX de oficios. No cambia el motor PDF, SQL ni plantillas.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='oficios-contenido-main-v1-c4532c25cd3e12ed'
PATCH_SHA='c4532c25cd3e12edd6eb3fc166ea1ba4e9c5c41515a4d8a6f6a87179935e89c6'
LIVE="${IMPE_OFICIOS_PDF_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_OFICIOS_PDF_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/services/OficioDocxPdfService.php'
FILES=("app/controllers/OficioVinculacionController.php" "app/services/OficioDocxPdfService.php")
PATCH_FILES=("${FILES[@]}")
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
no_links "$BACKUPS";BACKUPS="$(realpath -m "$BACKUPS")"
[[ "$BACKUPS" != / && "$BACKUPS/" != "$LIVE/"* && "$LIVE/" != "$BACKUPS/"* ]] || fail 'El respaldo debe estar fuera del sitio y sus antecesores.'
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
[[ "$SCRATCH/" != "$LIVE/"* ]] || fail 'Los temporales deben estar fuera del sitio.'
TMP="$(mktemp -d "$SCRATCH/impe-oficios-pdf.XXXXXX")";touch "$TMP/.owned"
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE
export GIT_CEILING_DIRECTORIES="$TMP"
for f in "${FILES[@]}";do mkdir -p "$TMP/stage/$(dirname "$f")" "$TMP/original/$(dirname "$f")";done
LOCK="$(dirname "$LIVE")/.impe-oficios-pdf-deploy.lock";LOCKED=0;WRITING=0
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
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 if [[ "$TMP" == "$SCRATCH"/impe-oficios-pdf.* && -f "$TMP/.owned" ]];then rm -rf -- "$TMP";fi
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
 no_links "$RESTORE/version.txt";no_links "$RESTORE/archivos.tsv"
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
 # Restaurar únicamente los dos archivos incluidos en esta entrega.
FILES=("app/controllers/OficioVinculacionController.php" "app/services/OficioDocxPdfService.php")
else
 [[ -f "$LIVE/vendor/autoload.php" ]] || fail 'Falta vendor/autoload.php.'
 php -r 'require $argv[1]."/vendor/autoload.php";foreach(["ZipArchive","DOMDocument","PhpOffice\\PhpWord\\TemplateProcessor"] as $c)if(!class_exists($c))exit(1);' "$LIVE" || fail 'Falta una dependencia PHP de preparación DOCX.'
 [[ -f "$LIVE/storage/templates/oficio_general_redmex.docx" && "$(digest "$LIVE/storage/templates/oficio_general_redmex.docx")" == '9c4750cc6f72bb2b182daaefca4d5a9cec67b13f3c4e960c031100a9e56341ad' ]] || fail 'La plantilla general difiere del perfil verificado. SITIO INTACTO.'
 base64 --decode > "$TMP/change.patch" <<'OFICIOS_PATCH'
ZGlmZiAtLWdpdCBhL2FwcC9jb250cm9sbGVycy9PZmljaW9WaW5jdWxhY2lvbkNvbnRyb2xsZXIu
cGhwIGIvYXBwL2NvbnRyb2xsZXJzL09maWNpb1ZpbmN1bGFjaW9uQ29udHJvbGxlci5waHAKaW5k
ZXggNTdmMWQxNjUuLjMzOWUwYTAzIDEwMDY0NAotLS0gYS9hcHAvY29udHJvbGxlcnMvT2ZpY2lv
VmluY3VsYWNpb25Db250cm9sbGVyLnBocAorKysgYi9hcHAvY29udHJvbGxlcnMvT2ZpY2lvVmlu
Y3VsYWNpb25Db250cm9sbGVyLnBocApAQCAtMTI3LDcgKzEyNyw4IEBAIGNsYXNzIE9maWNpb1Zp
bmN1bGFjaW9uQ29udHJvbGxlcgogICAgICAgICAgICAgfQogCiAgICAgICAgICAgICAkZG9jdW1l
bnRvID0gKG5ldyBPZmljaW9Eb2N4UGRmU2VydmljZSgpKS0+Z2VuZXJhckRvY3goCi0gICAgICAg
ICAgICAgICAgKGFycmF5KSgkdmlzdGFbJ3Zpc3RhX3ByZXZpYSddID8/IFtdKQorICAgICAgICAg
ICAgICAgIChhcnJheSkoJHZpc3RhWyd2aXN0YV9wcmV2aWEnXSA/PyBbXSksCisgICAgICAgICAg
ICAgICAgdHJ1ZSAvLyBQcmltZXJhIHByZXBhcmFjacOzbiBkZSBlc3RhIGNvcGlhLCBubyB1bmEg
ZGVzY2FyZ2EgZGUgdW4gb2ZpY2lvIHByZXBhcmFkby4KICAgICAgICAgICAgICk7CiAgICAgICAg
ICAgICBpZiAoISgkZG9jdW1lbnRvWydvayddID8/IGZhbHNlKSkgewogICAgICAgICAgICAgICAg
ICR0aGlzLT5yZXNwb25kZXJKc29uKFsKZGlmZiAtLWdpdCBhL2FwcC9zZXJ2aWNlcy9PZmljaW9E
b2N4UGRmU2VydmljZS5waHAgYi9hcHAvc2VydmljZXMvT2ZpY2lvRG9jeFBkZlNlcnZpY2UucGhw
CmluZGV4IDQxYmI2NmI2Li5iOGYyZTYzNSAxMDA2NDQKLS0tIGEvYXBwL3NlcnZpY2VzL09maWNp
b0RvY3hQZGZTZXJ2aWNlLnBocAorKysgYi9hcHAvc2VydmljZXMvT2ZpY2lvRG9jeFBkZlNlcnZp
Y2UucGhwCkBAIC0yNDYsNyArMjQ2LDcgQEAgY2xhc3MgT2ZpY2lvRG9jeFBkZlNlcnZpY2UKICAg
ICAgICAgcmV0dXJuICRyZWVtcGxhem9zICsgJHBlcnNvbmFsaXphZG9zOwogICAgIH0KIAotICAg
IHB1YmxpYyBmdW5jdGlvbiBnZW5lcmFyRG9jeChhcnJheSAkdmlzdGEpCisgICAgcHVibGljIGZ1
bmN0aW9uIGdlbmVyYXJEb2N4KGFycmF5ICR2aXN0YSwgJHByZXBhcmFyUGxhbnRpbGxhID0gZmFs
c2UpCiAgICAgewogICAgICAgICAkcnV0YVBsYW50aWxsYSA9ICR0aGlzLT5yZXNvbHZlclJ1dGFQ
bGFudGlsbGEoJHZpc3RhKTsKICAgICAgICAgaWYgKCFpc19maWxlKCRydXRhUGxhbnRpbGxhKSB8
fCAoIWNsYXNzX2V4aXN0cygnWmlwQXJjaGl2ZScpICYmICFjbGFzc19leGlzdHMoJ1BoYXJEYXRh
JykpKSB7CkBAIC0yNjYsNyArMjY2LDE3IEBAIGNsYXNzIE9maWNpb0RvY3hQZGZTZXJ2aWNlCiAg
ICAgICAgICAgICAgICAgcmV0dXJuICR0aGlzLT5lcnJvcignTm8gZnVlIHBvc2libGUgY29waWFy
IGxhIHBsYW50aWxsYSBzZWxlY2Npb25hZGEuJywgJ2NvcHkoKSBmYWxsw7MuJyk7CiAgICAgICAg
ICAgICB9CiAKLSAgICAgICAgICAgICRyZWxsZW5hZG8gPSAkdGhpcy0+cmVsbGVuYXJDb25UZW1w
bGF0ZVByb2Nlc3NvcigkcnV0YURvY3gsICR2aXN0YSk7CisgICAgICAgICAgICAvLyBMYSBjb3Bp
YSBnZW5lcmFjaW9uXyogeWEgZnVlIHJlbGxlbmFkYSBlbiBHZW5lcmFyIG9maWNpby4gRGVzY2Fy
Z2EKKyAgICAgICAgICAgIC8vIHkgUERGIGRlYmVuIHJlcHJlc2VudGFyIGV4YWN0YW1lbnRlIGVz
ZSBhcmNoaXZvLCBzaW4gcmVwZXRpciByZWVtcGxhem9zLgorICAgICAgICAgICAgJHByb2Nlc2Fk
YSA9ICEkcHJlcGFyYXJQbGFudGlsbGEgJiYgcHJlZ19tYXRjaCgKKyAgICAgICAgICAgICAgICAn
fl5zdG9yYWdlL3RlbXBsYXRlcy9vZmljaW9zX3BlcnNvbmFsaXphZG9zL2dlbmVyYWNpb25fXGR7
OH1fXGR7Nn1fW2EtZjAtOV17MTJ9XC5kb2N4JH5EJywKKyAgICAgICAgICAgICAgICBzdHJfcmVw
bGFjZSgnXFwnLCAnLycsIChzdHJpbmcpKCR2aXN0YVsnYXJjaGl2b19wbGFudGlsbGEnXSA/PyAn
JykpCisgICAgICAgICAgICApID09PSAxOworICAgICAgICAgICAgJHJlbGxlbmFkbyA9ICRwcm9j
ZXNhZGEgPyBbJ29rJyA9PiB0cnVlXSA6ICgKKyAgICAgICAgICAgICAgICB0cmltKChzdHJpbmcp
KCR2aXN0YVsnYXJjaGl2b19wbGFudGlsbGEnXSA/PyAnJykpICE9PSAnJworICAgICAgICAgICAg
ICAgICAgICA/ICR0aGlzLT5yZWxsZW5hckNvblRlbXBsYXRlUHJvY2Vzc29yKCRydXRhRG9jeCwg
JHZpc3RhKQorICAgICAgICAgICAgICAgICAgICA6ICR0aGlzLT5yZWxsZW5hckRvY3goJHJ1dGFE
b2N4LCAkdGhpcy0+Y29uc3RydWlyUmVlbXBsYXpvcygkdmlzdGEpKQorICAgICAgICAgICAgKTsK
ICAgICAgICAgICAgIGlmICghKCRyZWxsZW5hZG9bJ29rJ10gPz8gZmFsc2UpKSB7CiAgICAgICAg
ICAgICAgICAgcmV0dXJuICRyZWxsZW5hZG87CiAgICAgICAgICAgICB9Cg==
OFICIOS_PATCH
 [[ "$(digest "$TMP/change.patch")" == "$PATCH_SHA" ]] || fail 'Parche alterado.'
 declare -A EOLS=()
 for f in "${PATCH_FILES[@]}";do
  [[ -f "$LIVE/$f" ]] || fail "Falta archivo requerido: $f"
  cp -p -- "$LIVE/$f" "$TMP/stage/$f"
  EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 done
 if (cd "$TMP/stage" && git -c core.autocrlf=false apply --reverse --check "$TMP/change.patch" >/dev/null 2>&1);then :
 else (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/change.patch" && git -c core.autocrlf=false apply "$TMP/change.patch") || fail 'Parche incompatible. SITIO INTACTO.';fi
 for f in "${PATCH_FILES[@]}";do
  if [[ "${EOLS[$f]}" == CRLF ]];then php -r '$p=$argv[1];file_put_contents($p,str_replace("\n","\r\n",file_get_contents($p)));' "$TMP/stage/$f";fi
  chmod --reference="$TMP/original/$f" "$TMP/stage/$f"
 done
 for f in "${FILES[@]}";do
  php -l "$TMP/stage/$f" >/dev/null || fail "Sintaxis PHP inválida: $f"
  AFTER["$f"]="$(digest "$TMP/stage/$f")"
  if [[ "${BEFORE[$f]}" != "${AFTER[$f]}" ]];then CHANGED+=("$f");fi
 done
fi
[[ "${#CHANGED[@]}" -gt 0 ]] || { echo 'OK: ya aplicado o restaurado. Ningún archivo reemplazado.';exit 0; }
for f in "${FILES[@]}";do unchanged "$f";done
if [[ "$MODE" == --check ]];then echo "OK: revisión compatible. ${#CHANGED[@]} archivo(s). SITIO INTACTO. Sin SQL ni conversión de documentos.";exit 0;fi
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/oficios-contenido-main-$(date +%Y%m%d-%H%M%S)-$$";mkdir -m 700 "$BACKUP"
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
echo "OK: $MODE terminado. ${#CHANGED[@]} archivo(s). Sin cambios de BD, plantillas o configuración."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
