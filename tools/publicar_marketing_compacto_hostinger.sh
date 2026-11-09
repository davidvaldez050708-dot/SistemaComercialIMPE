#!/usr/bin/env bash
# Dashboard Marketing compacto. Sólo vista y CSS, sin ejecutar SQL ni envíos.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='marketing-compacto-v1-11d89416f70a823f'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/models/ConvocatoriaModel.php'
FILES=("public/css/convocatorias.css" "app/views/dashboard/marketing.php")
PATCH_FILES=("public/css/convocatorias.css" "app/views/dashboard/marketing.php")
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
FILES=("app/views/dashboard/marketing.php" "public/css/convocatorias.css")
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
ZGlmZiAtLWdpdCBhL3B1YmxpYy9jc3MvY29udm9jYXRvcmlhcy5jc3MgYi9wdWJsaWMvY3NzL2Nv
bnZvY2F0b3JpYXMuY3NzCi0tLSBhL3B1YmxpYy9jc3MvY29udm9jYXRvcmlhcy5jc3MKKysrIGIv
cHVibGljL2Nzcy9jb252b2NhdG9yaWFzLmNzcwpAQCAtMjgxNyw2ICsyODE3LDM0IEBACiAgICAg
Lm1hcmtldGluZy1vcGVyYXRpb25zLWdyaWQgLm1hcmtldGluZy1hY3Rpdml0eS1yb3cgdGltZSB7
IGdyaWQtY29sdW1uOiAyOyB0ZXh0LWFsaWduOiBsZWZ0OyB9CiB9CiAKKy8qIFJlc3VtZW4gbWVu
c3VhbCBjb21wYWN0bzogbm8gbW9kaWZpY2EgbGFzIG90cmFzIHRhcmpldGFzIG5pIEluZGljYWRv
cmVzIENsYXZlLiAqLworLm1hcmtldGluZy1vcGVyYXRpb25zLWdyaWQgLm1hcmtldGluZy1vcGVy
YXRpb24tY2FyZC5tYXJrZXRpbmctbW9udGhseSB7CisgICAgYWxpZ24tc2VsZjogc3RhcnQ7Cisg
ICAgbWluLWhlaWdodDogMzEycHg7CisgICAgcGFkZGluZy1ibG9jazogMTZweDsKK30KKy5tYXJr
ZXRpbmctb3BlcmF0aW9ucy1ncmlkIC5tYXJrZXRpbmctbW9udGhseSBoMiB7IGZvbnQtc2l6ZTog
MTZweDsgbWFyZ2luLXRvcDogNHB4OyB9CisubWFya2V0aW5nLW9wZXJhdGlvbnMtZ3JpZCAubWFy
a2V0aW5nLW1vbnRobHkgLm1hcmtldGluZy1vcGVyYXRpb24tZGVzY3JpcHRpb24geyBtYXJnaW4t
dG9wOiA2cHg7IGZvbnQtc2l6ZTogMTBweDsgfQorLm1hcmtldGluZy1vcGVyYXRpb25zLWdyaWQg
Lm1hcmtldGluZy1tb250aGx5IC5tYXJrZXRpbmctbW9udGhseS1tZXRyaWNzIHsgbWFyZ2luLXRv
cDogMThweDsgfQorLm1hcmtldGluZy1vcGVyYXRpb25zLWdyaWQgLm1hcmtldGluZy1tb250aGx5
IC5tYXJrZXRpbmctbW9udGhseS1tZXRyaWNzID4gZGl2IHsgcGFkZGluZy1ibG9jazogMTRweDsg
fQorLm1hcmtldGluZy1vcGVyYXRpb25zLWdyaWQgLm1hcmtldGluZy1tb250aGx5IC5tYXJrZXRp
bmctbW9udGhseS1tZXRyaWNzIHN0cm9uZyB7IGZvbnQtc2l6ZTogMjhweDsgfQorLm1hcmtldGlu
Zy1vcGVyYXRpb25zLWdyaWQgLm1hcmtldGluZy1tb250aGx5IC5tYXJrZXRpbmctbW9udGhseS1t
ZXRyaWNzIGgzIHsgbWFyZ2luLXRvcDogOHB4OyBmb250LXNpemU6IDExcHg7IH0KKy5tYXJrZXRp
bmctb3BlcmF0aW9ucy1ncmlkIC5tYXJrZXRpbmctbW9udGhseSAubWFya2V0aW5nLW1vbnRobHkt
bWV0cmljcyBwIHsgbWFyZ2luLXRvcDogNXB4OyBmb250LXNpemU6IDEwcHg7IH0KK0BtZWRpYSAo
bWluLXdpZHRoOiAxMjAwcHgpIHsKKyAgICAubWFya2V0aW5nLW9wZXJhdGlvbnMtZ3JpZCAubWFy
a2V0aW5nLW9wZXJhdGlvbi1jYXJkLm1hcmtldGluZy1tb250aGx5IHsKKyAgICAgICAgaGVpZ2h0
OiA4MiU7CisgICAgICAgIG1pbi1oZWlnaHQ6IG1pbi1jb250ZW50OworICAgIH0KK30KK0BtZWRp
YSAobWluLXdpZHRoOiAxMjAwcHgpIGFuZCAobWF4LXdpZHRoOiAxMzk5Ljk4cHgpIHsKKyAgICAu
bWFya2V0aW5nLW9wZXJhdGlvbnMtZ3JpZCAubWFya2V0aW5nLW1vbnRobHkgLm1hcmtldGluZy1t
b250aGx5LW1ldHJpY3MgaDMgeyBmb250LXNpemU6IDEwcHg7IH0KK30KK0BtZWRpYSAobWF4LXdp
ZHRoOiA3NjcuOThweCkgeworICAgIC5tYXJrZXRpbmctb3BlcmF0aW9ucy1ncmlkIC5tYXJrZXRp
bmctb3BlcmF0aW9uLWNhcmQubWFya2V0aW5nLW1vbnRobHkgeyBtaW4taGVpZ2h0OiBhdXRvOyBw
YWRkaW5nLWJsb2NrOiAxMnB4OyB9CisgICAgLm1hcmtldGluZy1vcGVyYXRpb25zLWdyaWQgLm1h
cmtldGluZy1tb250aGx5IC5tYXJrZXRpbmctbW9udGhseS1tZXRyaWNzIHsgbWFyZ2luLXRvcDog
MTJweDsgZ2FwOiAxMHB4IDA7IH0KKyAgICAubWFya2V0aW5nLW9wZXJhdGlvbnMtZ3JpZCAubWFy
a2V0aW5nLW1vbnRobHkgLm1hcmtldGluZy1tb250aGx5LW1ldHJpY3MgPiBkaXYgeyBwYWRkaW5n
LWJsb2NrOiAxMHB4OyB9Cit9CisKIC8qID09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09
PT09PT09PT09PT09PT09PT09PT09PT09PQogICAgUFVCTElDQUNJT05FUyBERUwgRMONQSDCtyBN
QVJLRVRJTkcKICAgID09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09PT09
PT09PT09PT09PT09PSAqLwo=
KPI_PATCH_0
 [[ "$(digest "$TMP/feature-0.patch")" == 'e6615a24b65e3a0eca8fb06d5eb02a25bd66285a9be5189befc299045d727bfe' ]] || fail 'Parche alterado.'
 f='public/css/convocatorias.css'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 8d87b6c561d629710e9035eea0f28bd52b810ba5aa9970e95c0d4bbe6b4cc530) ;;
 58cf0604637660e63f2ffe54a9b6ebb78b6a9be1e7940c2a41251c553a80afa3)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-0.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-0.patch")
  case "$(code_digest "$TMP/stage/$f")" in 8d87b6c561d629710e9035eea0f28bd52b810ba5aa9970e95c0d4bbe6b4cc530) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-1.patch" <<'KPI_PATCH_1'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9kYXNoYm9hcmQvbWFya2V0aW5nLnBocCBiL2FwcC92aWV3
cy9kYXNoYm9hcmQvbWFya2V0aW5nLnBocAotLS0gYS9hcHAvdmlld3MvZGFzaGJvYXJkL21hcmtl
dGluZy5waHAKKysrIGIvYXBwL3ZpZXdzL2Rhc2hib2FyZC9tYXJrZXRpbmcucGhwCkBAIC0yODcs
NzQgKzI4Nyw3IEBACiB9CiA/PgogCi08c2VjdGlvbiBjbGFzcz0iZGFzaGJvYXJkLXBhbmVsIG1h
cmtldGluZy10ZXJyaXRvcnktY292ZXJhZ2UgbXQtNCI+Ci0gICAgPGRpdiBjbGFzcz0ibWFya2V0
aW5nLWNvdmVyYWdlLWhlYWRpbmciPgotICAgICAgICA8ZGl2PgotICAgICAgICAgICAgPHNwYW4g
Y2xhc3M9ImFuYWx5c3Qtc2VjdGlvbi1raWNrZXIiPkNPQkVSVFVSQSBURVJSSVRPUklBTDwvc3Bh
bj4KLSAgICAgICAgICAgIDxoMiBjbGFzcz0icGFuZWwtdGl0bGUgbWItMCI+Q29iZXJ0dXJhIHRl
cnJpdG9yaWFsIGRlIGNvbnZvY2F0b3JpYXMgYWN0aXZhczwvaDI+Ci0gICAgICAgIDwvZGl2Pgot
Ci0gICAgICAgIDxhIGNsYXNzPSJhbmFseXN0LXBhbmVsLWxpbmsiIGhyZWY9Ijw/PSAkY29udm9j
YXRvcmlhc1VybCA/PiI+Ci0gICAgICAgICAgICBWZXIgdGVycml0b3Jpb3MKLSAgICAgICAgICAg
IDxpIGNsYXNzPSJiaSBiaS1hcnJvdy1yaWdodCI+PC9pPgotICAgICAgICA8L2E+Ci0gICAgPC9k
aXY+Ci0KLSAgICA8ZGl2IGNsYXNzPSJtYXJrZXRpbmctY292ZXJhZ2UtZ3JpZCI+Ci0gICAgICAg
IDxkaXYgY2xhc3M9Im1hcmtldGluZy1jb3ZlcmFnZS1vdmVydmlldyI+Ci0gICAgICAgICAgICA8
ZGl2Ci0gICAgICAgICAgICAgICAgY2xhc3M9Im1hcmtldGluZy1jb3ZlcmFnZS1kb251dCIKLSAg
ICAgICAgICAgICAgICBzdHlsZT0iLS1jb3ZlcmFnZS1wZXJjZW50OiA8Pz0gJHBvcmNlbnRhamVD
b2JlcnR1cmEgPz4lOyI+Ci0gICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0ibWFya2V0aW5nLWNv
dmVyYWdlLWRvbnV0LWNlbnRlciI+Ci0gICAgICAgICAgICAgICAgICAgIDxzdHJvbmc+PD89ICRl
c3RhZG9zQ3ViaWVydG9zID8+IGRlIDw/PSAkdG90YWxFc3RhZG9zQ29iZXJ0dXJhID8+PC9zdHJv
bmc+Ci0gICAgICAgICAgICAgICAgICAgIDxzcGFuPmVzdGFkb3M8L3NwYW4+Ci0gICAgICAgICAg
ICAgICAgPC9kaXY+Ci0gICAgICAgICAgICA8L2Rpdj4KLQotICAgICAgICAgICAgPGRpdiBjbGFz
cz0ibWFya2V0aW5nLWNvdmVyYWdlLWNvcHkiPgotICAgICAgICAgICAgICAgIDxzdHJvbmc+Y29u
IGNvbnZvY2F0b3JpYXMgYWN0aXZhczwvc3Ryb25nPgotICAgICAgICAgICAgICAgIDxwPgotICAg
ICAgICAgICAgICAgICAgICBBY3R1YWxtZW50ZSBleGlzdGVuIGNvbnZvY2F0b3JpYXMgYWN0aXZh
cyBlbiBlbAotICAgICAgICAgICAgICAgICAgICA8Pz0gJHBvcmNlbnRhamVDb2JlcnR1cmEgPz4l
IGRlbCB0ZXJyaXRvcmlvIG5hY2lvbmFsLgotICAgICAgICAgICAgICAgIDwvcD4KLSAgICAgICAg
ICAgIDwvZGl2PgotICAgICAgICA8L2Rpdj4KLQotICAgICAgICA8ZGl2IGNsYXNzPSJtYXJrZXRp
bmctY292ZXJhZ2UtcmFua2luZyI+Ci0gICAgICAgICAgICA8aDM+RXN0YWRvcyBjb24gbcOhcyBj
b252b2NhdG9yaWFzIGFjdGl2YXM8L2gzPgotCi0gICAgICAgICAgICA8P3BocCBpZiAoIWVtcHR5
KCR0ZXJyaXRvcmlvc0NvYmVydHVyYSkpOiA/PgotICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9
Im1hcmtldGluZy1jb3ZlcmFnZS1saXN0Ij4KLSAgICAgICAgICAgICAgICAgICAgPD9waHAgZm9y
ZWFjaCAoJHRlcnJpdG9yaW9zQ29iZXJ0dXJhIGFzICRpbmRpY2UgPT4gJHRlcnJpdG9yaW8pOiA/
PgotICAgICAgICAgICAgICAgICAgICAgICAgPD9waHAKLSAgICAgICAgICAgICAgICAgICAgICAg
ICRjYW50aWRhZEFjdGl2YXMgPSAoaW50KSgkdGVycml0b3Jpb1snY29udm9jYXRvcmlhc19hY3Rp
dmFzJ10gPz8gMCk7Ci0gICAgICAgICAgICAgICAgICAgICAgICAkYW5jaG9CYXJyYSA9ICRtYXhD
b252b2NhdG9yaWFzVGVycml0b3JpbyA+IDAKLSAgICAgICAgICAgICAgICAgICAgICAgICAgICA/
ICgkY2FudGlkYWRBY3RpdmFzIC8gJG1heENvbnZvY2F0b3JpYXNUZXJyaXRvcmlvKSAqIDEwMAot
ICAgICAgICAgICAgICAgICAgICAgICAgICAgIDogMDsKLSAgICAgICAgICAgICAgICAgICAgICAg
ID8+Ci0gICAgICAgICAgICAgICAgICAgICAgICA8ZGl2IGNsYXNzPSJtYXJrZXRpbmctY292ZXJh
Z2UtaXRlbSI+Ci0gICAgICAgICAgICAgICAgICAgICAgICAgICAgPHNwYW4gY2xhc3M9Im1hcmtl
dGluZy1jb3ZlcmFnZS1wb3NpdGlvbiI+PD89ICRpbmRpY2UgKyAxID8+PC9zcGFuPgotICAgICAg
ICAgICAgICAgICAgICAgICAgICAgIDxzcGFuIGNsYXNzPSJtYXJrZXRpbmctY292ZXJhZ2Utc3Rh
dGUiPgotICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8Pz0gaHRtbHNwZWNpYWxjaGFy
cygoc3RyaW5nKSgkdGVycml0b3Jpb1snbm9tYnJlJ10gPz8gJycpLCBFTlRfUVVPVEVTLCAnVVRG
LTgnKSA/PgotICAgICAgICAgICAgICAgICAgICAgICAgICAgIDwvc3Bhbj4KLSAgICAgICAgICAg
ICAgICAgICAgICAgICAgICA8c3BhbiBjbGFzcz0ibWFya2V0aW5nLWNvdmVyYWdlLXRyYWNrIiBh
cmlhLWhpZGRlbj0idHJ1ZSI+Ci0gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIDxzcGFu
Ci0gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICBjbGFzcz0ibWFya2V0aW5nLWNv
dmVyYWdlLWJhciIKLSAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgIHN0eWxlPSJ3
aWR0aDogPD89IG51bWJlcl9mb3JtYXQoJGFuY2hvQmFycmEsIDIsICcuJywgJycpID8+JTsiPgot
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8L3NwYW4+Ci0gICAgICAgICAgICAgICAg
ICAgICAgICAgICAgPC9zcGFuPgotICAgICAgICAgICAgICAgICAgICAgICAgICAgIDxzdHJvbmc+
PD89ICRjYW50aWRhZEFjdGl2YXMgPz48L3N0cm9uZz4KLSAgICAgICAgICAgICAgICAgICAgICAg
IDwvZGl2PgotICAgICAgICAgICAgICAgICAgICA8P3BocCBlbmRmb3JlYWNoOyA/PgotICAgICAg
ICAgICAgICAgIDwvZGl2PgotICAgICAgICAgICAgPD9waHAgZWxzZTogPz4KLSAgICAgICAgICAg
ICAgICA8ZGl2IGNsYXNzPSJtYXJrZXRpbmctY292ZXJhZ2UtZW1wdHkiPgotICAgICAgICAgICAg
ICAgICAgICBBw7puIG5vIGhheSBjb2JlcnR1cmEgdGVycml0b3JpYWwgYWN0aXZhLgotICAgICAg
ICAgICAgICAgIDwvZGl2PgotICAgICAgICAgICAgPD9waHAgZW5kaWY7ID8+Ci0gICAgICAgIDwv
ZGl2PgotICAgIDwvZGl2PgotPC9zZWN0aW9uPgorCiAKIDw/cGhwCiAkcGVyaW9kb1B1YmxpY2Fj
aW9uZXMgPSAoaW50KSgkcGVyaW9kb1B1YmxpY2FjaW9uZXMgPz8gMzApOwpAQCAtMzcxLDEwNSAr
MzA0LDggQEAKICAgICA6IDA7CiA/PgogCi08ZGl2IGNsYXNzPSJtYXJrZXRpbmctYm90dG9tLWdy
aWQgbXQtNCI+Ci08c2VjdGlvbiBjbGFzcz0iZGFzaGJvYXJkLXBhbmVsIG1hcmtldGluZy1wdWJs
aWNhdGlvbnMtYnktdHlwZSI+Ci0gICAgPD9waHAKLSAgICAkcHVibGljYWNpb25lc1BvclRpcG9N
YXJrZXRpbmcgPSBpc19hcnJheSgkcHVibGljYWNpb25lc1BvclRpcG9NYXJrZXRpbmcgPz8gbnVs
bCkKLSAgICAgICAgPyAkcHVibGljYWNpb25lc1BvclRpcG9NYXJrZXRpbmcKLSAgICAgICAgOiBb
XTsKLQotICAgICR0aXBvc1B1YmxpY2FjaW9uZXNNYXJrZXRpbmcgPSBbCi0gICAgICAgICdiYWNo
aWxsZXJhdG8nID0+IFsKLSAgICAgICAgICAgICd0aXR1bG8nID0+ICdCYWNoaWxsZXJhdG8nLAot
ICAgICAgICAgICAgJ2ljb25vJyA9PiAnYmktYm9vaycKLSAgICAgICAgXSwKLSAgICAgICAgJ3Rp
dHVsYWNpb24nID0+IFsKLSAgICAgICAgICAgICd0aXR1bG8nID0+ICdUaXR1bGFjacOzbicsCi0g
ICAgICAgICAgICAnaWNvbm8nID0+ICdiaS1tb3J0YXJib2FyZCcKLSAgICAgICAgXQotICAgIF07
Ci0gICAgPz4KLQotICAgIDxkaXYgY2xhc3M9Im1hcmtldGluZy1wdWJsaWNhdGlvbnMtdHlwZXMt
aGVhZGluZyI+Ci0gICAgICAgIDxkaXY+Ci0gICAgICAgICAgICA8c3BhbiBjbGFzcz0iYW5hbHlz
dC1zZWN0aW9uLWtpY2tlciI+UFVCTElDQUNJT05FUyBQT1IgVElQTzwvc3Bhbj4KLSAgICAgICAg
ICAgIDxoMj5Ub3RhbCBkZSBwdWJsaWNhY2lvbmVzIHNlZ8O6biBlbCB0aXBvIGRlIGNvbnZvY2F0
b3JpYS48L2gyPgotICAgICAgICA8L2Rpdj4KLQotICAgICAgICA8Zm9ybSBtZXRob2Q9IkdFVCIg
YWN0aW9uPSI8Pz0gQkFTRV9VUkwgPz5pbmRleC5waHAiIGNsYXNzPSJtYXJrZXRpbmctcHVibGlj
YXRpb25zLWZpbHRlciI+Ci0gICAgICAgICAgICA8aW5wdXQgdHlwZT0iaGlkZGVuIiBuYW1lPSJj
b250cm9sbGVyIiB2YWx1ZT0iaG9tZSI+Ci0gICAgICAgICAgICA8aW5wdXQgdHlwZT0iaGlkZGVu
IiBuYW1lPSJhY3Rpb24iIHZhbHVlPSJpbmRleCI+Ci0gICAgICAgICAgICA8c2VsZWN0Ci0gICAg
ICAgICAgICAgICAgY2xhc3M9ImZvcm0tc2VsZWN0IHN5c3RlbS1mb3JtLWNvbnRyb2wiCi0gICAg
ICAgICAgICAgICAgbmFtZT0icGVyaW9kb19wdWJsaWNhY2lvbmVzIgotICAgICAgICAgICAgICAg
IGFyaWEtbGFiZWw9IlBlcmlvZG8gZGUgcHVibGljYWNpb25lcyIKLSAgICAgICAgICAgICAgICBv
bmNoYW5nZT0idGhpcy5mb3JtLnN1Ym1pdCgpIj4KLSAgICAgICAgICAgICAgICA8b3B0aW9uIHZh
bHVlPSI3IiA8Pz0gJHBlcmlvZG9QdWJsaWNhY2lvbmVzID09PSA3ID8gJ3NlbGVjdGVkJyA6ICcn
ID8+PsOabHRpbW9zIDcgZMOtYXM8L29wdGlvbj4KLSAgICAgICAgICAgICAgICA8b3B0aW9uIHZh
bHVlPSIzMCIgPD89ICRwZXJpb2RvUHVibGljYWNpb25lcyA9PT0gMzAgPyAnc2VsZWN0ZWQnIDog
JycgPz4+w5psdGltb3MgMzAgZMOtYXM8L29wdGlvbj4KLSAgICAgICAgICAgICAgICA8b3B0aW9u
IHZhbHVlPSI5MCIgPD89ICRwZXJpb2RvUHVibGljYWNpb25lcyA9PT0gOTAgPyAnc2VsZWN0ZWQn
IDogJycgPz4+w5psdGltb3MgOTAgZMOtYXM8L29wdGlvbj4KLSAgICAgICAgICAgIDwvc2VsZWN0
PgotICAgICAgICA8L2Zvcm0+Ci0gICAgPC9kaXY+Ci0KLSAgICA8ZGl2IGNsYXNzPSJtYXJrZXRp
bmctcHVibGljYXRpb25zLXR5cGUtZ3JpZCI+Ci0gICAgICAgIDw/cGhwIGZvcmVhY2ggKCR0aXBv
c1B1YmxpY2FjaW9uZXNNYXJrZXRpbmcgYXMgJHRpcG9DbGF2ZSA9PiAkdGlwb0NvbmZpZyk6ID8+
Ci0gICAgICAgICAgICA8P3BocAotICAgICAgICAgICAgJGRhdG9zVGlwbyA9IGlzX2FycmF5KCRw
dWJsaWNhY2lvbmVzUG9yVGlwb01hcmtldGluZ1skdGlwb0NsYXZlXSA/PyBudWxsKQotICAgICAg
ICAgICAgICAgID8gJHB1YmxpY2FjaW9uZXNQb3JUaXBvTWFya2V0aW5nWyR0aXBvQ2xhdmVdCi0g
ICAgICAgICAgICAgICAgOiBbXTsKLSAgICAgICAgICAgICRtZXNlc1RpcG8gPSBpc19hcnJheSgk
ZGF0b3NUaXBvWydtZXNlcyddID8/IG51bGwpCi0gICAgICAgICAgICAgICAgPyAkZGF0b3NUaXBv
WydtZXNlcyddCi0gICAgICAgICAgICAgICAgOiBbXTsKLSAgICAgICAgICAgICR2YWxvcmVzTWVz
ID0gYXJyYXlfbWFwKAotICAgICAgICAgICAgICAgIHN0YXRpYyBmbigkbWVzKSA9PiAoaW50KSgk
bWVzWyd0b3RhbCddID8/IDApLAotICAgICAgICAgICAgICAgICRtZXNlc1RpcG8KLSAgICAgICAg
ICAgICk7Ci0gICAgICAgICAgICAkbWF4VmFsb3JNZXMgPSAhZW1wdHkoJHZhbG9yZXNNZXMpID8g
bWF4KCR2YWxvcmVzTWVzKSA6IDA7Ci0gICAgICAgICAgICAkZXNjYWxhTWVzID0gbWF4KDEsICRt
YXhWYWxvck1lcyk7Ci0gICAgICAgICAgICA/PgotCi0gICAgICAgICAgICA8YXJ0aWNsZSBjbGFz
cz0ibWFya2V0aW5nLXB1YmxpY2F0aW9uLXR5cGUtY2FyZCI+Ci0gICAgICAgICAgICAgICAgPGRp
diBjbGFzcz0ibWFya2V0aW5nLXB1YmxpY2F0aW9uLXR5cGUtaGVhZGVyIj4KLSAgICAgICAgICAg
ICAgICAgICAgPHNwYW4gY2xhc3M9Im1hcmtldGluZy1wdWJsaWNhdGlvbi10eXBlLWljb24iPgot
ICAgICAgICAgICAgICAgICAgICAgICAgPGkgY2xhc3M9ImJpIDw/PSBodG1sc3BlY2lhbGNoYXJz
KCR0aXBvQ29uZmlnWydpY29ubyddLCBFTlRfUVVPVEVTLCAnVVRGLTgnKSA/PiI+PC9pPgotICAg
ICAgICAgICAgICAgICAgICA8L3NwYW4+Ci0gICAgICAgICAgICAgICAgICAgIDxzdHJvbmc+PD89
IGh0bWxzcGVjaWFsY2hhcnMoJHRpcG9Db25maWdbJ3RpdHVsbyddLCBFTlRfUVVPVEVTLCAnVVRG
LTgnKSA/Pjwvc3Ryb25nPgotICAgICAgICAgICAgICAgIDwvZGl2PgotCi0gICAgICAgICAgICAg
ICAgPGRpdiBjbGFzcz0ibWFya2V0aW5nLXB1YmxpY2F0aW9uLXR5cGUtYm9keSI+Ci0gICAgICAg
ICAgICAgICAgICAgIDxkaXYgY2xhc3M9Im1hcmtldGluZy1wdWJsaWNhdGlvbi10eXBlLXRvdGFs
Ij4KLSAgICAgICAgICAgICAgICAgICAgICAgIDxzdHJvbmc+PD89IChpbnQpKCRkYXRvc1RpcG9b
J3RvdGFsJ10gPz8gMCkgPz48L3N0cm9uZz4KLSAgICAgICAgICAgICAgICAgICAgICAgIDxzcGFu
PnB1YmxpY2FjaW9uZXM8L3NwYW4+Ci0gICAgICAgICAgICAgICAgICAgIDwvZGl2PgotCi0gICAg
ICAgICAgICAgICAgICAgIDxkaXYgY2xhc3M9Im1hcmtldGluZy1wdWJsaWNhdGlvbi10eXBlLWNo
YXJ0Ij4KLSAgICAgICAgICAgICAgICAgICAgICAgIDw/cGhwIGZvcmVhY2ggKCRtZXNlc1RpcG8g
YXMgJG1lcyk6ID8+Ci0gICAgICAgICAgICAgICAgICAgICAgICAgICAgPD9waHAKLSAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAkY2FudGlkYWRNZXMgPSAoaW50KSgkbWVzWyd0b3RhbCddID8/
IDApOwotICAgICAgICAgICAgICAgICAgICAgICAgICAgICRhbHR1cmFCYXJyYSA9ICRjYW50aWRh
ZE1lcyA+IDAKLSAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgPyBtYXgoMTgsIChpbnQp
cm91bmQoKCRjYW50aWRhZE1lcyAvICRlc2NhbGFNZXMpICogMTAwKSkKLSAgICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgOiAxMDsKLSAgICAgICAgICAgICAgICAgICAgICAgICAgICAkZXNE
ZXN0YWNhZG8gPSAkbWF4VmFsb3JNZXMgPiAwICYmICRjYW50aWRhZE1lcyA9PT0gJG1heFZhbG9y
TWVzOwotICAgICAgICAgICAgICAgICAgICAgICAgICAgID8+Ci0gICAgICAgICAgICAgICAgICAg
ICAgICAgICAgPGRpdiBjbGFzcz0ibWFya2V0aW5nLXB1YmxpY2F0aW9uLXR5cGUtbW9udGgiPgot
ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8c3Ryb25nPjw/PSAkY2FudGlkYWRNZXMg
Pz48L3N0cm9uZz4KLSAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgPGRpdiBjbGFzcz0i
bWFya2V0aW5nLXB1YmxpY2F0aW9uLXR5cGUtYmFyLXNwYWNlIiBhcmlhLWhpZGRlbj0idHJ1ZSI+
Ci0gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8c3BhbgotICAgICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgICAgIGNsYXNzPSI8Pz0gJGVzRGVzdGFjYWRvID8gJ2lz
LWhpZ2hsaWdodGVkJyA6ICcnID8+IgotICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAg
ICAgICAgIHN0eWxlPSJoZWlnaHQ6IDw/PSAkYWx0dXJhQmFycmEgPz4lOyI+Ci0gICAgICAgICAg
ICAgICAgICAgICAgICAgICAgICAgICAgICA8L3NwYW4+Ci0gICAgICAgICAgICAgICAgICAgICAg
ICAgICAgICAgIDwvZGl2PgotICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8c21hbGw+
Ci0gICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICA8Pz0gaHRtbHNwZWNpYWxjaGFy
cygoc3RyaW5nKSgkbWVzWydsYWJlbCddID8/ICcnKSwgRU5UX1FVT1RFUywgJ1VURi04JykgPz4K
LSAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgPC9zbWFsbD4KLSAgICAgICAgICAgICAg
ICAgICAgICAgICAgICA8L2Rpdj4KLSAgICAgICAgICAgICAgICAgICAgICAgIDw/cGhwIGVuZGZv
cmVhY2g7ID8+Ci0gICAgICAgICAgICAgICAgICAgIDwvZGl2PgotICAgICAgICAgICAgICAgIDwv
ZGl2PgotICAgICAgICAgICAgPC9hcnRpY2xlPgotICAgICAgICA8P3BocCBlbmRmb3JlYWNoOyA/
PgotICAgIDwvZGl2PgotPC9zZWN0aW9uPgorPGRpdiBjbGFzcz0ibXQtNCI+CisKIAogICAgIDxz
ZWN0aW9uCiAgICAgICAgIGNsYXNzPSJkYXNoYm9hcmQtcGFuZWwgbWFya2V0aW5nLXBlbmRpbmct
Y292ZXJhZ2UiCg==
KPI_PATCH_1
 [[ "$(digest "$TMP/feature-1.patch")" == '852f37d03076cdd7811765c4f3992cf8feab7aba2c9703c0ee4a4180a8cf1289' ]] || fail 'Parche alterado.'
 f='app/views/dashboard/marketing.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 dd9ee0a06461afb2b65b6e067f54d063d9059c1d185ef567a5731f1429d9dc24) ;;
 3f286c5ee4ba3c90956bdacd89a4a167f9bbd541cb05ce822004877d571b0ff8)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-1.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-1.patch")
  case "$(code_digest "$TMP/stage/$f")" in dd9ee0a06461afb2b65b6e067f54d063d9059c1d185ef567a5731f1429d9dc24) ;; *) fail "Resultado inesperado: $f";; esac ;;
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
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/marketing-compacto-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
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
