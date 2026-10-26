#!/usr/bin/env bash
# Avatar de Resumen personal en Desempeño. Sólo vista y CSS; sin SQL ni envíos.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='desempeno-avatar-v1-b85e19676c005449'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/helpers/AvatarHelper.php'
FILES=("public/css/desempeno.css" "app/views/desempeno/index.php")
PATCH_FILES=("public/css/desempeno.css" "app/views/desempeno/index.php")
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
[[ -d "$LIVE/app/controllers" && -d "$LIVE/app/models" && -d "$LIVE/app/helpers" ]] || fail 'Falta la estructura de controladores/modelos del CRM.'
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
 for f in "${WRITTEN[@]}";do rm -f -- "$LIVE/$f.impe-new-$$";done
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
FILES=("app/views/desempeno/index.php" "public/css/desempeno.css")
else
 php -r 'exit(PHP_VERSION_ID>=80200?0:1);' || fail 'Se requiere PHP 8.2+.'
 function code_digest(){
 if [[ "$1" == *.php ]];then
  php -r '$out=[];foreach(token_get_all(file_get_contents($argv[1])) as $t){if(is_array($t)){if(in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$out[]=[$t[0],$t[0]===T_OPEN_TAG?trim($t[1]):$t[1]];}else{$out[]=$t;}}echo hash("sha256",json_encode($out));' "$1"
 else digest "$1";fi
 }
 php -r 'exit(function_exists("token_get_all")?0:1);' || fail 'Se requiere el tokenizador de PHP para reconocer versiones sin modificar comentarios.'
 declare -A EOLS=()
 base64 --decode > "$TMP/feature-0.patch" <<'KPI_PATCH_0'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9jc3MvZGVzZW1wZW5vLmNzcyBiL3B1YmxpYy9jc3MvZGVzZW1w
ZW5vLmNzcwotLS0gYS9wdWJsaWMvY3NzL2Rlc2VtcGVuby5jc3MKKysrIGIvcHVibGljL2Nzcy9k
ZXNlbXBlbm8uY3NzCkBAIC0xNjk5LDMgKzE2OTksMzMgQEAKICAgICAgICAgZ3JpZC1jb2x1bW46
IDE7CiAgICAgfQogfQorCisvKiBSZXN1bWVuIHBlcnNvbmFsOiBtaXNtbyBjb21wb25lbnRlIGRl
bCBlbmNhYmV6YWRvLCBhaXNsYWRvIGRlbCB0ZXh0byBkZWwgcm9sLiAqLworLnBlcmZvcm1hbmNl
LW1vZHVsZSAucGVyZm9ybWFuY2UtcGVyc29uID4gLnBlcmZvcm1hbmNlLXBlcnNvbmFsLWF2YXRh
ciB7CisgICAgd2lkdGg6IDQ0cHg7CisgICAgbWluLXdpZHRoOiA0NHB4OworICAgIGhlaWdodDog
NDRweDsKKyAgICBmbGV4OiAwIDAgNDRweDsKKyAgICBkaXNwbGF5OiBpbmxpbmUtZmxleDsKKyAg
ICBhbGlnbi1pdGVtczogY2VudGVyOworICAgIGp1c3RpZnktY29udGVudDogY2VudGVyOworICAg
IHBhZGRpbmc6IDA7CisgICAgbWFyZ2luOiAxLjVweCAwOworICAgIGNvbG9yOiB2YXIoLS1jb2xv
ci1wcmltYXJ5KTsKKyAgICBmb250LWZhbWlseTogaW5oZXJpdDsKKyAgICBmb250LXNpemU6IDE2
cHg7CisgICAgZm9udC13ZWlnaHQ6IDcwMDsKKyAgICBsaW5lLWhlaWdodDogMTsKKyAgICB0ZXh0
LWFsaWduOiBjZW50ZXI7CisgICAgd2hpdGUtc3BhY2U6IG5vd3JhcDsKK30KKy5wZXJmb3JtYW5j
ZS1tb2R1bGUgLnBlcmZvcm1hbmNlLXBlcnNvbiA+IC5wZXJmb3JtYW5jZS1wZXJzb25hbC1hdmF0
YXIgPiAuc3lzdGVtLWF2YXRhci1pbWFnZSB7CisgICAgZGlzcGxheTogYmxvY2s7CisgICAgZmxl
eDogMCAwIDEwMCU7CisgICAgbWluLXdpZHRoOiAwOworICAgIHdpZHRoOiAxMDAlOworICAgIGhl
aWdodDogMTAwJTsKKyAgICBib3JkZXI6IDA7CisgICAgb2JqZWN0LWZpdDogY292ZXI7CisgICAg
b2JqZWN0LXBvc2l0aW9uOiBjZW50ZXI7Cit9Cg==
KPI_PATCH_0
 [[ "$(digest "$TMP/feature-0.patch")" == 'bd14f350d64d5c75e92b7e834ada064afef4dc4694ec329909d0c53d578d243e' ]] || fail 'Parche alterado.'
 f='public/css/desempeno.css'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 33ab177f1c0488845611ab59925b9e6eb1f6bb569365e3cd454560592d7308cb) ;;
 9836bc7dce279d2e0c33e2593c96d9609721f99041cc6200fea20502b1371505)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-0.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-0.patch")
  case "$(code_digest "$TMP/stage/$f")" in 33ab177f1c0488845611ab59925b9e6eb1f6bb569365e3cd454560592d7308cb) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-1.patch" <<'KPI_PATCH_1'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9kZXNlbXBlbm8vaW5kZXgucGhwIGIvYXBwL3ZpZXdzL2Rl
c2VtcGVuby9pbmRleC5waHAKLS0tIGEvYXBwL3ZpZXdzL2Rlc2VtcGVuby9pbmRleC5waHAKKysr
IGIvYXBwL3ZpZXdzL2Rlc2VtcGVuby9pbmRleC5waHAKQEAgLTEsNCArMSw2IEBACiA8P3BocAor
CityZXF1aXJlX29uY2UgX19ESVJfXyAuICcvLi4vLi4vaGVscGVycy9BdmF0YXJIZWxwZXIucGhw
JzsKIAogJGRlc2VtcGVubyA9IGlzX2FycmF5KCRkZXNlbXBlbm8gPz8gbnVsbCkKICAgICA/ICRk
ZXNlbXBlbm8KQEAgLTYxMCw2ICs2MTIsMTggQEAKICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICA8P3BocCBlbmRpZjsgPz4KIAogICAgICAgICAgICAgICAgICAgICAgICAgICAgIDxkaXYgY2xh
c3M9InBlcmZvcm1hbmNlLXBlcnNvbiI+CisgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
IDw/cGhwIGlmICgkdmlzdGEgPT09ICdwcm9waW8nKTogPz4KKyAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgIDw/PSByZW5kZXJBdmF0YXJVc3VhcmlvKAorICAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgICRub21icmUsCisgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgJycsCisgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgJHJvbEFjdHVhbERlc2VtcGVubywKKyAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAkZmlsYVsnZm90b19wZXJmaWwnXSA/PyAnJywKKyAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAnbWQnLAorICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAgICdnZW5lcmFsJywKKyAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAncGVyZm9ybWFuY2UtcGVyc29uYWwtYXZhdGFyJywKKyAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICBmYWxzZQorICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgKSA/PgorICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8P3BocCBlbHNl
OiA/PgogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8c3BhbiBjbGFzcz0icGVyZm9y
bWFuY2UtYXZhdGFyIj4KICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIDw/cGhw
IGlmICgkZm90byAhPT0gJycpOiA/PgogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgIDxpbWcgc3JjPSI8Pz0gJGVzYygkZm90bykgPz4iIGFsdD0iPD89ICRlc2MoJG5vbWJy
ZSkgPz4iPgpAQCAtNjE3LDYgKzYzMSw3IEBACiAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgICAgPD89ICRlc2MoJGluaWNpYWxlcygkbm9tYnJlKSkgPz4KICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgIDw/cGhwIGVuZGlmOyA/PgogICAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICA8L3NwYW4+CisgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
IDw/cGhwIGVuZGlmOyA/PgogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8ZGl2Pgog
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgPHN0cm9uZz48Pz0gJGVzYygkbm9t
YnJlKSA/Pjwvc3Ryb25nPgogICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgPHNw
YW4+CkBAIC04NzUsMyArODkwLDE5IEBACiAgICAgICAgIDwvZGl2PgogICAgIDwvc2VjdGlvbj4K
IDwvc2VjdGlvbj4KKworPD9waHAgaWYgKCR2aXN0YSA9PT0gJ3Byb3BpbycpOiA/PgorPHNjcmlw
dD4KKy8vIEVsIGZvb3RlciByZWdpc3RyYSBlbCBmYWxsYmFjayBjb21wYXJ0aWRvIGFsIHRlcm1p
bmFyIERPTUNvbnRlbnRMb2FkZWQuCisvLyBSZWVudHJlZ2FyIHPDs2xvIGxvcyBlcnJvcmVzIGRl
IGVzdGFzIGZvdG9zIHF1ZSBvY3Vycmllcm9uIGFudGVzIGRlIGVzZSByZWdpc3Ryby4KK2RvY3Vt
ZW50LmFkZEV2ZW50TGlzdGVuZXIoJ0RPTUNvbnRlbnRMb2FkZWQnLCBmdW5jdGlvbiAoKSB7Cisg
ICAgd2luZG93LnNldFRpbWVvdXQoZnVuY3Rpb24gKCkgeworICAgICAgICBkb2N1bWVudC5xdWVy
eVNlbGVjdG9yQWxsKCcucGVyZm9ybWFuY2UtbW9kdWxlIC5wZXJmb3JtYW5jZS1wZXJzb25hbC1h
dmF0YXIgPiAuc3lzdGVtLWF2YXRhci1pbWFnZScpLmZvckVhY2goZnVuY3Rpb24gKGltYWdlbikg
eworICAgICAgICAgICAgaWYgKGltYWdlbi5jb21wbGV0ZSAmJiBpbWFnZW4ubmF0dXJhbFdpZHRo
ID09PSAwKSB7CisgICAgICAgICAgICAgICAgaW1hZ2VuLmRpc3BhdGNoRXZlbnQobmV3IEV2ZW50
KCdlcnJvcicpKTsKKyAgICAgICAgICAgIH0KKyAgICAgICAgfSk7CisgICAgfSwgMCk7Cit9LCB7
IG9uY2U6IHRydWUgfSk7Cis8L3NjcmlwdD4KKzw/cGhwIGVuZGlmOyA/Pgo=
KPI_PATCH_1
 [[ "$(digest "$TMP/feature-1.patch")" == 'cb2c532f5bf6aabe4f4e8ad1d8666a319c44cbaacf281efb103bc30ae892acec' ]] || fail 'Parche alterado.'
 f='app/views/desempeno/index.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 5570765cda8c5db402a1c8f0d205096e2b957934570a2f53083d5b51c347d4d5) ;;
 9966bf29d38a51f22bf90f60c5c867b7f1caa255cba3e2ea6029cb9f235cdcef)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-1.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-1.patch")
  case "$(code_digest "$TMP/stage/$f")" in 5570765cda8c5db402a1c8f0d205096e2b957934570a2f53083d5b51c347d4d5) ;; *) fail "Resultado inesperado: $f";; esac ;;
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
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/desempeno-avatar-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
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
echo "OK: $MODE terminado. ${#CHANGED[@]} archivo(s). Sin cambios de BD ni credenciales. Comprobar los tres widgets por separado."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
