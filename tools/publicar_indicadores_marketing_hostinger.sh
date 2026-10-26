#!/usr/bin/env bash
# Indicadores de Marketing. Sólo archivos del panel; sin SQL, mensajes ni credenciales.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
VERSION='marketing-indicadores-v1-1d4f964aa55c5597'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
SERVICE='app/models/ConvocatoriaModel.php'
FILES=("app/models/MarketingIndicadoresModel.php" "app/views/dashboard/marketing_indicadores.php" "tools/diagnosticar_indicadores_marketing.php" "public/css/convocatorias.css" "app/views/dashboard/marketing.php" "app/controllers/HomeController.php")
PATCH_FILES=("public/css/convocatorias.css" "app/views/dashboard/marketing.php" "app/controllers/HomeController.php")
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
FILES=("app/controllers/HomeController.php" "app/views/dashboard/marketing.php" "public/css/convocatorias.css" "tools/diagnosticar_indicadores_marketing.php" "app/views/dashboard/marketing_indicadores.php" "app/models/MarketingIndicadoresModel.php")
else
 [[ -d "$LIVE/tools" ]] || fail 'Falta tools del CRM; revisar la instalación previa.'
 php -r 'exit(PHP_VERSION_ID>=80200?0:1);' || fail 'Se requiere PHP 8.2+.'
 function code_digest(){
 if [[ "$1" == *.php ]];then
  php -r '$out=[];foreach(token_get_all(file_get_contents($argv[1])) as $t){if(is_array($t)){if(in_array($t[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;$out[]=[$t[0],$t[0]===T_OPEN_TAG?trim($t[1]):$t[1]];}else{$out[]=$t;}}echo hash("sha256",json_encode($out));' "$1"
 else digest "$1";fi
 }
 php -r 'exit(function_exists("token_get_all")?0:1);' || fail 'Se requiere el tokenizador de PHP para reconocer versiones sin modificar comentarios.'
 base64 --decode > "$TMP/stage/app/models/MarketingIndicadoresModel.php" <<'KPI_HELPER_0'
PD9waHAKcmVxdWlyZV9vbmNlIF9fRElSX18gLiAnLy4uLy4uL2NvbmZpZy9kYl9jb25uZWN0aW9u
LnBocCc7CgovKiogTGVjdHVyYSBleGNsdXNpdmEgZGVsIHBhbmVsIGRlIE1hcmtldGluZzsgbm8g
aW5pY2lhbGl6YSBuaSBtaWdyYSB0YWJsYXMuICovCmNsYXNzIE1hcmtldGluZ0luZGljYWRvcmVz
TW9kZWwKewogICAgcHJpdmF0ZSAkZGI7CiAgICBwdWJsaWMgZnVuY3Rpb24gX19jb25zdHJ1Y3Qo
JGRiID0gbnVsbCkgeyAkdGhpcy0+ZGIgPSAkZGI7IH0KICAgIHByaXZhdGUgZnVuY3Rpb24gZmls
YXMoc3RyaW5nICRzcWwpOiBhcnJheQogICAgewogICAgICAgICR0aGlzLT5kYiA/Pz0gKG5ldyBE
YXRhYmFzZSgpKS0+Y29ubmVjdCgpOwogICAgICAgICRyZXN1bHRhZG8gPSAkdGhpcy0+ZGItPnF1
ZXJ5KCRzcWwpOwogICAgICAgIGlmICghJHJlc3VsdGFkbykgeyB0aHJvdyBuZXcgUnVudGltZUV4
Y2VwdGlvbignTUFSS0VUSU5HX0lORElDQURPUkVTX0NPTlNVTFRBJyk7IH0KICAgICAgICAkZmls
YXMgPSBbXTsKICAgICAgICB3aGlsZSAoJGZpbGEgPSAkcmVzdWx0YWRvLT5mZXRjaF9hc3NvYygp
KSB7ICRmaWxhc1tdID0gJGZpbGE7IH0KICAgICAgICByZXR1cm4gJGZpbGFzOwogICAgfQogICAg
cHJpdmF0ZSBzdGF0aWMgZnVuY3Rpb24gcG9yY2VudGFqZShpbnQgJHBhcnRlLCBpbnQgJHRvdGFs
KTogZmxvYXQKICAgIHsKICAgICAgICByZXR1cm4gJHRvdGFsID4gMCA/IHJvdW5kKDEwMCAqICRw
YXJ0ZSAvICR0b3RhbCwgMSkgOiAwOwogICAgfQogICAgcHVibGljIGZ1bmN0aW9uIG9idGVuZXIo
YXJyYXkgJHJlc3VtZW4sIGFycmF5ICRjb2JlcnR1cmEpOiBhcnJheQogICAgewogICAgICAgIC8v
IFJldXRpbGl6YXIgbGFzIG3DqXRyaWNhcyBleGlzdGVudGVzLCBzaW4gZmlsdHJvcyBtZW5zdWFs
ZXMgbnVldm9zLgogICAgICAgICR0b3RhbEVzdGFkb3MgPSAoaW50KSgkY29iZXJ0dXJhWyd0b3Rh
bF9lc3RhZG9zJ10gPz8gMCk7CiAgICAgICAgJGN1YmllcnRvcyA9IChpbnQpKCRjb2JlcnR1cmFb
J2VzdGFkb3NfY3ViaWVydG9zJ10gPz8gMCk7CiAgICAgICAgJGFjdGl2YXMgPSAoaW50KSgkcmVz
dW1lblsnYWN0aXZhcyddID8/IDApOwogICAgICAgICRwcm94aW1hcyA9IChpbnQpKCRyZXN1bWVu
Wydwcm94aW1hc19maW5hbGl6YXInXSA/PyAwKTsKICAgICAgICAkZGF0b3MgPSBbCiAgICAgICAg
ICAgICdwdWJsaWNhY2lvbmVzJyA9PiAoaW50KSgkcmVzdW1lblsndG90YWwnXSA/PyAwKSwKICAg
ICAgICAgICAgJ3RvdGFsX2VzdGFkb3MnID0+ICR0b3RhbEVzdGFkb3MsICdlc3RhZG9zX2N1Ymll
cnRvcycgPT4gJGN1YmllcnRvcywKICAgICAgICAgICAgJ2NvYmVydHVyYV9wb3JjZW50YWplJyA9
PiBzZWxmOjpwb3JjZW50YWplKCRjdWJpZXJ0b3MsICR0b3RhbEVzdGFkb3MpLAogICAgICAgICAg
ICAncHJveGltYXMnID0+ICRwcm94aW1hcywgJ2FjdGl2YXMnID0+ICRhY3RpdmFzLAogICAgICAg
ICAgICAncHJveGltYXNfcG9yY2VudGFqZScgPT4gc2VsZjo6cG9yY2VudGFqZSgkcHJveGltYXMs
ICRhY3RpdmFzKSwKICAgICAgICAgICAgJ2NvbnZlbmlvcycgPT4gbnVsbCwgJ2VzdGFkb3NfY29u
dmVuaW9zJyA9PiBudWxsLCAnY29udmVuaW9zX3BvcmNlbnRhamUnID0+IG51bGwsCiAgICAgICAg
ICAgICdhbGlhZG9zJyA9PiBudWxsLCAnYWxpYWRvc19jb25fY29udm9jYXRvcmlhJyA9PiBudWxs
LCAnYWxpYWRvc19wb3JjZW50YWplJyA9PiBudWxsLAogICAgICAgIF07CiAgICAgICAgdHJ5IHsK
ICAgICAgICAgICAgJHRhYmxhcyA9IGFycmF5X2NvbHVtbigkdGhpcy0+ZmlsYXMoIlNFTEVDVCB0
YWJsZV9uYW1lIEFTIG5vbWJyZV90YWJsYSBGUk9NIGluZm9ybWF0aW9uX3NjaGVtYS50YWJsZXMK
ICAgICAgICAgICAgICAgIFdIRVJFIHRhYmxlX3NjaGVtYSA9IERBVEFCQVNFKCkgQU5EIHRhYmxl
X25hbWUgSU4gKCdhbGlhZG9zX21hbnVhbGVzJywKICAgICAgICAgICAgICAgICdtYXJrZXRpbmdf
Y29udmVuaW9fY29udm9jYXRvcmlhcycsICdtYXJrZXRpbmdfYWxpYWRvX21hbnVhbF9jb252b2Nh
dG9yaWFzJywKICAgICAgICAgICAgICAgICdtYXJrZXRpbmdfYWxpYWRvX2NvbnZvY2F0b3JpYXNf
b3JpZ2VuJykiKSwgJ25vbWJyZV90YWJsYScpOwogICAgICAgICAgICAkdGllbmUgPSBzdGF0aWMg
Zm4oc3RyaW5nICR0YWJsYSk6IGJvb2wgPT4gaW5fYXJyYXkoJHRhYmxhLCAkdGFibGFzLCB0cnVl
KTsKICAgICAgICAgICAgJHJlbGFjaW9uZXMgPSBbXTsKICAgICAgICAgICAgaWYgKCR0aWVuZSgn
bWFya2V0aW5nX2NvbnZlbmlvX2NvbnZvY2F0b3JpYXMnKSkgewogICAgICAgICAgICAgICAgJHJl
bGFjaW9uZXNbXSA9ICJTRUxFQ1QgJ2luc3RpdHVjaW9uYWwnIEFTIG9yaWdlbiwgc2VndWltaWVu
dG9faWQgQVMgb3JpZ2VuX2lkLCBjb252b2NhdG9yaWFfaWQgRlJPTSBtYXJrZXRpbmdfY29udmVu
aW9fY29udm9jYXRvcmlhcyI7CiAgICAgICAgICAgIH0KICAgICAgICAgICAgaWYgKCR0aWVuZSgn
bWFya2V0aW5nX2FsaWFkb19tYW51YWxfY29udm9jYXRvcmlhcycpKSB7CiAgICAgICAgICAgICAg
ICAkcmVsYWNpb25lc1tdID0gIlNFTEVDVCAnbWFudWFsJyBBUyBvcmlnZW4sIG1hbnVhbF9pZCBB
UyBvcmlnZW5faWQsIGNvbnZvY2F0b3JpYV9pZCBGUk9NIG1hcmtldGluZ19hbGlhZG9fbWFudWFs
X2NvbnZvY2F0b3JpYXMiOwogICAgICAgICAgICB9CiAgICAgICAgICAgIGlmICgkdGllbmUoJ21h
cmtldGluZ19hbGlhZG9fY29udm9jYXRvcmlhc19vcmlnZW4nKSkgewogICAgICAgICAgICAgICAg
JHJlbGFjaW9uZXNbXSA9ICdTRUxFQ1Qgb3JpZ2VuX3RpcG8gQVMgb3JpZ2VuLCBvcmlnZW5faWQs
IGNvbnZvY2F0b3JpYV9pZCBGUk9NIG1hcmtldGluZ19hbGlhZG9fY29udm9jYXRvcmlhc19vcmln
ZW4nOwogICAgICAgICAgICB9CiAgICAgICAgICAgICRyZWxhY2lvbiA9ICRyZWxhY2lvbmVzID8g
aW1wbG9kZSgnIFVOSU9OICcsICRyZWxhY2lvbmVzKSA6IG51bGw7CiAgICAgICAgICAgICRhY3Rp
dmEgPSBzdGF0aWMgZm4oc3RyaW5nICR0aXBvLCBzdHJpbmcgJGlkKTogc3RyaW5nID0+ICRyZWxh
Y2lvbiA9PT0gbnVsbCA/ICcwJyA6CiAgICAgICAgICAgICAgICAiRVhJU1RTKFNFTEVDVCAxIEZS
T00gKCRyZWxhY2lvbikgciBKT0lOIGNvbnZvY2F0b3JpYXMgYyBPTiBjLmlkPXIuY29udm9jYXRv
cmlhX2lkCiAgICAgICAgICAgICAgICAgICAgV0hFUkUgci5vcmlnZW49JyR0aXBvJyBBTkQgci5v
cmlnZW5faWQ9JGlkIEFORCBjLmVzdGFkbz0xKSI7CiAgICAgICAgICAgIC8vIENvbnZlbmlvczog
ZnVlbnRlIGRlIENyZWFyQ29udm9jYXRvcmlhTW9kZWwgKGFzaWduYWNpb25lcyB2aWdlbnRlcywK
ICAgICAgICAgICAgLy8gaW5jbHVpZG9zIGhpc3TDs3JpY29zL21pZ3JhZG9zKS4gQWxpYWRvczog
Zm9ybWFsaXphY2nDs24gZGUgQWxpYWRvTW9kZWwuCiAgICAgICAgICAgICRhc2lnbmFkbyA9ICdF
WElTVFMoU0VMRUNUIDEgRlJPTSBhbGlhZG9zX2FzaWduYWNpb25lcyBhIFdIRVJFIGEuc2VndWlt
aWVudG9faWQ9cy5pZCBBTkQgYS5hY3Rpdm89MSknOwogICAgICAgICAgICAkaW5zdGl0dWNpb25h
bGVzID0gJHRoaXMtPmZpbGFzKCJTRUxFQ1QgRElTVElOQ1QgJ2luc3RpdHVjaW9uYWwnIEFTIG9y
aWdlbiwgcy5pZCwgcy5lc3RhZG9faWQsCiAgICAgICAgICAgICAgICAkYXNpZ25hZG8gQVMgY29u
dmVuaW8sCiAgICAgICAgICAgICAgICBDQVNFIFdIRU4gcC5jb252ZW5pb19mb3JtYWxpemFkb19h
dCBJUyBOT1QgTlVMTCBUSEVOIDEgRUxTRSAwIEVORCBBUyBhbGlhZG8sCiAgICAgICAgICAgICAg
ICAiIC4gJGFjdGl2YSgnaW5zdGl0dWNpb25hbCcsICdzLmlkJykgLiAiIEFTIGNvbnZvY2F0b3Jp
YV9hY3RpdmEKICAgICAgICAgICAgICAgIEZST00gc2VndWltaWVudG9zX3ZpbmN1bGFjaW9uIHMg
Sk9JTiBlc3RhZG9zIGUgT04gZS5pZD1zLmVzdGFkb19pZAogICAgICAgICAgICAgICAgTEVGVCBK
T0lOIHNlZ3VpbWllbnRvc192aW5jdWxhY2lvbl9wb3N0X2VudmlvIHAgT04gcC5zZWd1aW1pZW50
b19pZD1zLmlkCiAgICAgICAgICAgICAgICBXSEVSRSBzLmFjdGl2bz0xIEFORCAoJGFzaWduYWRv
IE9SIHAuY29udmVuaW9fZm9ybWFsaXphZG9fYXQgSVMgTk9UIE5VTEwpIik7CiAgICAgICAgICAg
ICRtYW51YWxlcyA9ICR0aWVuZSgnYWxpYWRvc19tYW51YWxlcycpID8gJHRoaXMtPmZpbGFzKCJT
RUxFQ1QgJ21hbnVhbCcgQVMgb3JpZ2VuLCBtLmlkLCBtLmVzdGFkb19pZCwKICAgICAgICAgICAg
ICAgIDEgQVMgY29udmVuaW8sIDEgQVMgYWxpYWRvLCAiIC4gJGFjdGl2YSgnbWFudWFsJywgJ20u
aWQnKSAuICIgQVMgY29udm9jYXRvcmlhX2FjdGl2YQogICAgICAgICAgICAgICAgRlJPTSBhbGlh
ZG9zX21hbnVhbGVzIG0gSk9JTiBlc3RhZG9zIGUgT04gZS5pZD1tLmVzdGFkb19pZCBXSEVSRSBt
LmFjdGl2bz0xIikgOiBbXTsKICAgICAgICAgICAgJGNvbnZlbmlvcyA9IFtdOyAkYWxpYWRvcyA9
IFtdOyAkY29uQWN0aXZhID0gW107ICRlc3RhZG9zID0gW107CiAgICAgICAgICAgIGZvcmVhY2gg
KGFycmF5X21lcmdlKCRpbnN0aXR1Y2lvbmFsZXMsICRtYW51YWxlcykgYXMgJGZpbGEpIHsKICAg
ICAgICAgICAgICAgICRjbGF2ZSA9ICRmaWxhWydvcmlnZW4nXSAuICc6JyAuIChpbnQpJGZpbGFb
J2lkJ107CiAgICAgICAgICAgICAgICBpZiAoKGludCkkZmlsYVsnY29udmVuaW8nXSA9PT0gMSkg
ewogICAgICAgICAgICAgICAgICAgICRjb252ZW5pb3NbJGNsYXZlXSA9IHRydWU7CiAgICAgICAg
ICAgICAgICAgICAgJGVzdGFkb3NbKGludCkkZmlsYVsnZXN0YWRvX2lkJ11dID0gdHJ1ZTsKICAg
ICAgICAgICAgICAgIH0KICAgICAgICAgICAgICAgIGlmICgoaW50KSRmaWxhWydhbGlhZG8nXSA9
PT0gMSkgewogICAgICAgICAgICAgICAgICAgICRhbGlhZG9zWyRjbGF2ZV0gPSB0cnVlOwogICAg
ICAgICAgICAgICAgICAgIGlmICgoaW50KSRmaWxhWydjb252b2NhdG9yaWFfYWN0aXZhJ10gPT09
IDEpIHsgJGNvbkFjdGl2YVskY2xhdmVdID0gdHJ1ZTsgfQogICAgICAgICAgICAgICAgfQogICAg
ICAgICAgICB9CiAgICAgICAgICAgICRkYXRvc1snY29udmVuaW9zJ10gPSBjb3VudCgkY29udmVu
aW9zKTsKICAgICAgICAgICAgJGRhdG9zWydlc3RhZG9zX2NvbnZlbmlvcyddID0gY291bnQoJGVz
dGFkb3MpOwogICAgICAgICAgICAkZGF0b3NbJ2NvbnZlbmlvc19wb3JjZW50YWplJ10gPSBzZWxm
Ojpwb3JjZW50YWplKGNvdW50KCRlc3RhZG9zKSwgJHRvdGFsRXN0YWRvcyk7CiAgICAgICAgICAg
ICRkYXRvc1snYWxpYWRvcyddID0gY291bnQoJGFsaWFkb3MpOwogICAgICAgICAgICAkZGF0b3Nb
J2FsaWFkb3NfY29uX2NvbnZvY2F0b3JpYSddID0gJHJlbGFjaW9uID09PSBudWxsID8gbnVsbCA6
IGNvdW50KCRjb25BY3RpdmEpOwogICAgICAgICAgICAkZGF0b3NbJ2FsaWFkb3NfcG9yY2VudGFq
ZSddID0gJHJlbGFjaW9uID09PSBudWxsID8gbnVsbCA6IHNlbGY6OnBvcmNlbnRhamUoY291bnQo
JGNvbkFjdGl2YSksIGNvdW50KCRhbGlhZG9zKSk7CiAgICAgICAgfSBjYXRjaCAoVGhyb3dhYmxl
ICRlcnJvcikgewogICAgICAgICAgICAvLyBTaW4gU1FMLCBjb250YWN0b3MgbmkgZGV0YWxsZXMg
ZGUgY29uZXhpw7NuIGVuIGVsIHJlZ2lzdHJvLgogICAgICAgICAgICBlcnJvcl9sb2coJ01BUktF
VElOR19JTkRJQ0FET1JFUyBleGNlcHRpb249JyAuIGdldF9jbGFzcygkZXJyb3IpIC4gJyBjb2Rl
PScgLiAoaW50KSRlcnJvci0+Z2V0Q29kZSgpKTsKICAgICAgICB9CiAgICAgICAgcmV0dXJuICRk
YXRvczsKICAgIH0KfQo=
KPI_HELPER_0
 [[ "$(digest "$TMP/stage/app/models/MarketingIndicadoresModel.php")" == '742c3fb895e862d963c3cfb6eeb9eb9866d7ae078a1e825f63106c4d48aad558' ]] || fail 'Dependencia alterada.'
 if [[ "${BEFORE["app/models/MarketingIndicadoresModel.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/models/MarketingIndicadoresModel.php")"
  [[ "$existing" == '742c3fb895e862d963c3cfb6eeb9eb9866d7ae078a1e825f63106c4d48aad558' ]] || fail 'Dependencia personalizada: app/models/MarketingIndicadoresModel.php. SITIO INTACTO.'
  cp -p -- "$LIVE/app/models/MarketingIndicadoresModel.php" "$TMP/stage/app/models/MarketingIndicadoresModel.php"
 else chmod 0644 "$TMP/stage/app/models/MarketingIndicadoresModel.php";fi
 base64 --decode > "$TMP/stage/app/views/dashboard/marketing_indicadores.php" <<'KPI_HELPER_1'
PD9waHAKJGluZGljYWRvcmVzID0gJGluZGljYWRvcmVzQ2xhdmVNYXJrZXRpbmcgPz8gW107CiRu
dW1lcm9JbmRpY2Fkb3IgPSBzdGF0aWMgZm4oJHZhbG9yKTogc3RyaW5nID0+ICR2YWxvciA9PT0g
bnVsbCA/ICfigJQnIDogbnVtYmVyX2Zvcm1hdCgoaW50KSR2YWxvciwgMCwgJy4nLCAnLCcpOwok
cG9yY2VudGFqZUluZGljYWRvciA9IHN0YXRpYyBmbigkdmFsb3IpOiBzdHJpbmcgPT4gJHZhbG9y
ID09PSBudWxsID8gJ+KAlCcgOiBydHJpbShydHJpbShudW1iZXJfZm9ybWF0KChmbG9hdCkkdmFs
b3IsIDEsICcuJywgJycpLCAnMCcpLCAnLicpOwokYmFycmFJbmRpY2Fkb3IgPSBzdGF0aWMgZnVu
Y3Rpb24gKCR2YWxvciwgc3RyaW5nICRldGlxdWV0YSk6IHZvaWQgewogICAgaWYgKCR2YWxvciA9
PT0gbnVsbCkgeyByZXR1cm47IH0KICAgICR2YWxvciA9IG1heCgwLCBtaW4oMTAwLCAoZmxvYXQp
JHZhbG9yKSk7CiAgICA/PgogICAgPGRpdiBjbGFzcz0ibWFya2V0aW5nLWtleS1iYXIiIHJvbGU9
InByb2dyZXNzYmFyIgogICAgICAgICBhcmlhLWxhYmVsPSI8Pz0gaHRtbHNwZWNpYWxjaGFycygk
ZXRpcXVldGEsIEVOVF9RVU9URVMsICdVVEYtOCcpID8+IgogICAgICAgICBhcmlhLXZhbHVlbWlu
PSIwIiBhcmlhLXZhbHVlbWF4PSIxMDAiIGFyaWEtdmFsdWVub3c9Ijw/PSAkdmFsb3IgPz4iCiAg
ICAgICAgIHRpdGxlPSI8Pz0gaHRtbHNwZWNpYWxjaGFycygkZXRpcXVldGEsIEVOVF9RVU9URVMs
ICdVVEYtOCcpID8+Ij4KICAgICAgICA8c3BhbiBzdHlsZT0id2lkdGg6PD89ICR2YWxvciA/PiUi
Pjwvc3Bhbj4KICAgIDwvZGl2PgogICAgPD9waHAKfTsKPz4KPHNlY3Rpb24gY2xhc3M9Im1hcmtl
dGluZy1rZXktaW5kaWNhdG9ycyBtYi00IiBhcmlhLWxhYmVsbGVkYnk9Im1hcmtldGluZ0tleVRp
dGxlIj4KICAgIDxkaXYgY2xhc3M9Im1hcmtldGluZy1rZXktaW50cm8iPgogICAgICAgIDxoMiBp
ZD0ibWFya2V0aW5nS2V5VGl0bGUiPklORElDQURPUkVTIENMQVZFPC9oMj4KICAgICAgICA8cD5F
c3RhZG8gYWN0dWFsIGRlIHR1cyBhY3RpdmlkYWRlcyBkZSBNYXJrZXRpbmcuPC9wPgogICAgPC9k
aXY+CiAgICA8YXJ0aWNsZSBjbGFzcz0ibWFya2V0aW5nLWtleS1pdGVtIiBkYXRhLW1hcmtldGlu
Zy1rZXk9InB1YmxpY2FjaW9uZXMiPgogICAgICAgIDxkaXYgY2xhc3M9Im1hcmtldGluZy1rZXkt
bWFpbiI+CiAgICAgICAgICAgIDxzcGFuIGNsYXNzPSJtYXJrZXRpbmcta2V5LWljb24iIGFyaWEt
aGlkZGVuPSJ0cnVlIj48aSBjbGFzcz0iYmkgYmktbWVnYXBob25lIj48L2k+PC9zcGFuPgogICAg
ICAgICAgICA8ZGl2PjxzdHJvbmc+PD89ICRudW1lcm9JbmRpY2Fkb3IoJGluZGljYWRvcmVzWydw
dWJsaWNhY2lvbmVzJ10gPz8gbnVsbCkgPz48L3N0cm9uZz48aDM+Q29udm9jYXRvcmlhcyBwdWJs
aWNhZGFzPC9oMz48L2Rpdj4KICAgICAgICA8L2Rpdj4KICAgICAgICA8cD5Ub3RhbCBkZSBwdWJs
aWNhY2lvbmVzIGVuIGVsIHNpc3RlbWE8L3A+CiAgICA8L2FydGljbGU+CiAgICA8YXJ0aWNsZSBj
bGFzcz0ibWFya2V0aW5nLWtleS1pdGVtIiBkYXRhLW1hcmtldGluZy1rZXk9ImNvbnZlbmlvcyI+
CiAgICAgICAgPGRpdiBjbGFzcz0ibWFya2V0aW5nLWtleS1tYWluIj4KICAgICAgICAgICAgPHNw
YW4gY2xhc3M9Im1hcmtldGluZy1rZXktaWNvbiIgYXJpYS1oaWRkZW49InRydWUiPjxpIGNsYXNz
PSJiaSBiaS1maWxlLWVhcm1hcmstdGV4dCI+PC9pPjwvc3Bhbj4KICAgICAgICAgICAgPGRpdj48
c3Ryb25nPjw/PSAkbnVtZXJvSW5kaWNhZG9yKCRpbmRpY2Fkb3Jlc1snY29udmVuaW9zJ10gPz8g
bnVsbCkgPz48L3N0cm9uZz48aDM+Q29udmVuaW9zIHJlZ2lzdHJhZG9zPC9oMz48L2Rpdj4KICAg
ICAgICA8L2Rpdj4KICAgICAgICA8P3BocCAkYmFycmFJbmRpY2Fkb3IoJGluZGljYWRvcmVzWydj
b252ZW5pb3NfcG9yY2VudGFqZSddID8/IG51bGwsICdQb3JjZW50YWplIGRlIGVudGlkYWRlcyBk
ZWwgY2F0w6Fsb2dvIGNvbiBjb252ZW5pb3MgcmVnaXN0cmFkb3MnKTsgPz4KICAgICAgICA8cD48
Pz0gJG51bWVyb0luZGljYWRvcigkaW5kaWNhZG9yZXNbJ2VzdGFkb3NfY29udmVuaW9zJ10gPz8g
bnVsbCkgPz4gZXN0YWRvcyBjb24gY29udmVuaW9zPC9wPgogICAgPC9hcnRpY2xlPgogICAgPGFy
dGljbGUgY2xhc3M9Im1hcmtldGluZy1rZXktaXRlbSIgZGF0YS1tYXJrZXRpbmcta2V5PSJhbGlh
ZG9zIj4KICAgICAgICA8ZGl2IGNsYXNzPSJtYXJrZXRpbmcta2V5LW1haW4iPgogICAgICAgICAg
ICA8c3BhbiBjbGFzcz0ibWFya2V0aW5nLWtleS1pY29uIiBhcmlhLWhpZGRlbj0idHJ1ZSI+PGkg
Y2xhc3M9ImJpIGJpLXBlb3BsZSI+PC9pPjwvc3Bhbj4KICAgICAgICAgICAgPGRpdj48c3Ryb25n
Pjw/PSAkbnVtZXJvSW5kaWNhZG9yKCRpbmRpY2Fkb3Jlc1snYWxpYWRvcyddID8/IG51bGwpID8+
PC9zdHJvbmc+PGgzPkFsaWFkb3MgaW5zdGl0dWNpb25hbGVzPC9oMz48L2Rpdj4KICAgICAgICA8
L2Rpdj4KICAgICAgICA8P3BocCAkYmFycmFJbmRpY2Fkb3IoJGluZGljYWRvcmVzWydhbGlhZG9z
X3BvcmNlbnRhamUnXSA/PyBudWxsLCAnQWxpYWRvcyBjb24gYWwgbWVub3MgdW5hIGNvbnZvY2F0
b3JpYSBhY3RpdmEgLyB0b3RhbCBkZSBhbGlhZG9zJyk7ID8+CiAgICAgICAgPHA+PD89IGlzc2V0
KCRpbmRpY2Fkb3Jlc1snYWxpYWRvc19wb3JjZW50YWplJ10pID8gJHBvcmNlbnRhamVJbmRpY2Fk
b3IoJGluZGljYWRvcmVzWydhbGlhZG9zX3BvcmNlbnRhamUnXSkgLiAnJSBjb24gY29udm9jYXRv
cmlhIGFjdGl2YScgOiAnUmVsYWNpw7NuIG5vIGRpc3BvbmlibGUnID8+PC9wPgogICAgPC9hcnRp
Y2xlPgogICAgPGFydGljbGUgY2xhc3M9Im1hcmtldGluZy1rZXktaXRlbSIgZGF0YS1tYXJrZXRp
bmcta2V5PSJjb2JlcnR1cmEiPgogICAgICAgIDxkaXYgY2xhc3M9Im1hcmtldGluZy1rZXktbWFp
biI+CiAgICAgICAgICAgIDxzcGFuIGNsYXNzPSJtYXJrZXRpbmcta2V5LWljb24iIGFyaWEtaGlk
ZGVuPSJ0cnVlIj48aSBjbGFzcz0iYmkgYmktcGllLWNoYXJ0Ij48L2k+PC9zcGFuPgogICAgICAg
ICAgICA8ZGl2PjxzdHJvbmc+PD89ICRudW1lcm9JbmRpY2Fkb3IoJGluZGljYWRvcmVzWydlc3Rh
ZG9zX2N1YmllcnRvcyddID8/IG51bGwpID8+IGRlIDw/PSAkbnVtZXJvSW5kaWNhZG9yKCRpbmRp
Y2Fkb3Jlc1sndG90YWxfZXN0YWRvcyddID8/IG51bGwpID8+PC9zdHJvbmc+PGgzPkNvYmVydHVy
YSB0ZXJyaXRvcmlhbDwvaDM+PC9kaXY+CiAgICAgICAgPC9kaXY+CiAgICAgICAgPD9waHAgJGJh
cnJhSW5kaWNhZG9yKCRpbmRpY2Fkb3Jlc1snY29iZXJ0dXJhX3BvcmNlbnRhamUnXSA/PyBudWxs
LCAnRW50aWRhZGVzIGNvbiBjb252b2NhdG9yaWEgYWN0aXZhIC8gZW50aWRhZGVzIGRlbCBjYXTD
oWxvZ28nKTsgPz4KICAgICAgICA8cD48Pz0gJHBvcmNlbnRhamVJbmRpY2Fkb3IoJGluZGljYWRv
cmVzWydjb2JlcnR1cmFfcG9yY2VudGFqZSddID8/IG51bGwpID8+JSBkZWwgdGVycml0b3JpbyBj
b24gY29iZXJ0dXJhPC9wPgogICAgPC9hcnRpY2xlPgogICAgPGFydGljbGUgY2xhc3M9Im1hcmtl
dGluZy1rZXktaXRlbSIgZGF0YS1tYXJrZXRpbmcta2V5PSJwcm94aW1hcyI+CiAgICAgICAgPGRp
diBjbGFzcz0ibWFya2V0aW5nLWtleS1tYWluIj4KICAgICAgICAgICAgPHNwYW4gY2xhc3M9Im1h
cmtldGluZy1rZXktaWNvbiIgYXJpYS1oaWRkZW49InRydWUiPjxpIGNsYXNzPSJiaSBiaS1jbG9j
ayI+PC9pPjwvc3Bhbj4KICAgICAgICAgICAgPGRpdj48c3Ryb25nPjw/PSAkbnVtZXJvSW5kaWNh
ZG9yKCRpbmRpY2Fkb3Jlc1sncHJveGltYXMnXSA/PyBudWxsKSA/Pjwvc3Ryb25nPjxoMz5QcsOz
eGltYXMgYSB2ZW5jZXI8L2gzPjwvZGl2PgogICAgICAgIDwvZGl2PgogICAgICAgIDw/cGhwICRi
YXJyYUluZGljYWRvcigkaW5kaWNhZG9yZXNbJ3Byb3hpbWFzX3BvcmNlbnRhamUnXSA/PyBudWxs
LCAnQ29udm9jYXRvcmlhcyBxdWUgdmVuY2VuIGVudHJlIGhveSB5IGxvcyBwcsOzeGltb3MgNyBk
w61hcyAvIGNvbnZvY2F0b3JpYXMgYWN0aXZhcycpOyA/PgogICAgICAgIDxwPkVuIGxvcyBwcsOz
eGltb3MgNyBkw61hczwvcD4KICAgIDwvYXJ0aWNsZT4KICAgIDxkaXYgY2xhc3M9Im1hcmtldGlu
Zy1rZXktYWN0aW9uIj4KICAgICAgICA8P3BocCBpZiAodGllbmVQZXJtaXNvKCdjb252b2NhdG9y
aWFzLnZlcicpKTogPz4KICAgICAgICA8YSBjbGFzcz0ibWFya2V0aW5nLWtleS1kZXRhaWxzIiBo
cmVmPSI8Pz0gaHRtbHNwZWNpYWxjaGFycygkY29udm9jYXRvcmlhc1VybCwgRU5UX1FVT1RFUywg
J1VURi04JykgPz4iIHRpdGxlPSJBYnJpciBHZXN0acOzbiBkZSBDb252b2NhdG9yaWFzIj4KICAg
ICAgICAgICAgPGkgY2xhc3M9ImJpIGJpLWJhci1jaGFydC1saW5lIiBhcmlhLWhpZGRlbj0idHJ1
ZSI+PC9pPjxzcGFuPlZlciBtw6FzIGRldGFsbGVzPC9zcGFuPjxpIGNsYXNzPSJiaSBiaS1hcnJv
dy1yaWdodCIgYXJpYS1oaWRkZW49InRydWUiPjwvaT4KICAgICAgICA8L2E+CiAgICAgICAgPD9w
aHAgZW5kaWY7ID8+CiAgICA8L2Rpdj4KPC9zZWN0aW9uPgo=
KPI_HELPER_1
 [[ "$(digest "$TMP/stage/app/views/dashboard/marketing_indicadores.php")" == '6fea11b94e32c19ff5e00264a267a3d026fdeb4cfeeb0ecae8fcf650168f4153' ]] || fail 'Dependencia alterada.'
 if [[ "${BEFORE["app/views/dashboard/marketing_indicadores.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/app/views/dashboard/marketing_indicadores.php")"
  [[ "$existing" == '6fea11b94e32c19ff5e00264a267a3d026fdeb4cfeeb0ecae8fcf650168f4153' ]] || fail 'Dependencia personalizada: app/views/dashboard/marketing_indicadores.php. SITIO INTACTO.'
  cp -p -- "$LIVE/app/views/dashboard/marketing_indicadores.php" "$TMP/stage/app/views/dashboard/marketing_indicadores.php"
 else chmod 0644 "$TMP/stage/app/views/dashboard/marketing_indicadores.php";fi
 base64 --decode > "$TMP/stage/tools/diagnosticar_indicadores_marketing.php" <<'KPI_HELPER_2'
PD9waHAKLy8gU8OzbG8gY29uc3VsdGFzIGRlIHJlc3VtZW4gZXhpc3RlbnRlcyB5IFNFTEVDVCBk
ZWwgcGFuZWwuIE5vIHNpbmNyb25pemEgZmVjaGFzLgppZiAoUEhQX1NBUEkgIT09ICdjbGknKSB7
IGh0dHBfcmVzcG9uc2VfY29kZSg0MDQpOyBleGl0OyB9CmluaV9zZXQoJ2Rpc3BsYXlfZXJyb3Jz
JywgJzAnKTsKdHJ5IHsKICAgIHJlcXVpcmVfb25jZSBfX0RJUl9fIC4gJy8uLi9hcHAvbW9kZWxz
L0NvbnZvY2F0b3JpYU1vZGVsLnBocCc7CiAgICByZXF1aXJlX29uY2UgX19ESVJfXyAuICcvLi4v
YXBwL21vZGVscy9NYXJrZXRpbmdJbmRpY2Fkb3Jlc01vZGVsLnBocCc7CiAgICAkbW9kZWxvID0g
bmV3IENvbnZvY2F0b3JpYU1vZGVsKCk7CiAgICAkcmVzdW1lbiA9ICRtb2RlbG8tPm9idGVuZXJS
ZXN1bWVuRGFzaGJvYXJkKCk7CiAgICAkcmVzdW1lblsncHJveGltYXNfZmluYWxpemFyJ10gPSBj
b3VudCgkbW9kZWxvLT5vYnRlbmVyUHJveGltYXNGaW5hbGl6YXJEYXNoYm9hcmQoKSk7CiAgICAk
Y29iZXJ0dXJhID0gJG1vZGVsby0+b2J0ZW5lckNvYmVydHVyYVRlcnJpdG9yaWFsRGFzaGJvYXJk
KCk7CiAgICAkZGF0b3MgPSAobmV3IE1hcmtldGluZ0luZGljYWRvcmVzTW9kZWwoKSktPm9idGVu
ZXIoJHJlc3VtZW4sICRjb2JlcnR1cmEpOwogICAgZWNobyBqc29uX2VuY29kZShbJ3ZlcnNpb24n
ID0+ICdtYXJrZXRpbmctaW5kaWNhZG9yZXMtdjEnLCAnaW5kaWNhZG9yZXMnID0+ICRkYXRvc10s
IEpTT05fUFJFVFRZX1BSSU5UIHwgSlNPTl9VTkVTQ0FQRURfVU5JQ09ERSksIFBIUF9FT0w7CiAg
ICBlY2hvICJTT0xPIExFQ1RVUkEuIFNpbiBzaW5jcm9uaXphY2nDs24sIGVzY3JpdHVyYXMsIGNv
bnRhY3RvcywgY3JlZGVuY2lhbGVzIG5pIGVudsOtb3MuXG4iOwogICAgZXhpdCgkZGF0b3NbJ2Nv
bnZlbmlvcyddID09PSBudWxsIHx8ICRkYXRvc1snYWxpYWRvcyddID09PSBudWxsID8gMiA6IDAp
Owp9IGNhdGNoIChUaHJvd2FibGUgJGVycm9yKSB7CiAgICBmd3JpdGUoU1RERVJSLCAnTUFSS0VU
SU5HX0lORElDQURPUkVTX0RJQUdOT1NUSUNPIGNsYXNlPScgLiBnZXRfY2xhc3MoJGVycm9yKSAu
ICcgY29kaWdvPScgLiAoaW50KSRlcnJvci0+Z2V0Q29kZSgpIC4gIi4gU2luIGRhdG9zIHByaXZh
ZG9zLlxuIik7CiAgICBleGl0KDIpOwp9Cg==
KPI_HELPER_2
 [[ "$(digest "$TMP/stage/tools/diagnosticar_indicadores_marketing.php")" == 'b466a53663acfd6ed5a0a189d660d5f115418d13e45fe122b628fe1e569213c6' ]] || fail 'Dependencia alterada.'
 if [[ "${BEFORE["tools/diagnosticar_indicadores_marketing.php"]}" != MISSING ]];then
  existing="$(php -r '$s=file_get_contents($argv[1]);echo hash("sha256",str_replace("\r\n","\n",$s));' "$LIVE/tools/diagnosticar_indicadores_marketing.php")"
  [[ "$existing" == 'b466a53663acfd6ed5a0a189d660d5f115418d13e45fe122b628fe1e569213c6' ]] || fail 'Dependencia personalizada: tools/diagnosticar_indicadores_marketing.php. SITIO INTACTO.'
  cp -p -- "$LIVE/tools/diagnosticar_indicadores_marketing.php" "$TMP/stage/tools/diagnosticar_indicadores_marketing.php"
 else chmod 0644 "$TMP/stage/tools/diagnosticar_indicadores_marketing.php";fi
 declare -A EOLS=()
 base64 --decode > "$TMP/feature-0.patch" <<'KPI_PATCH_0'
ZGlmZiAtLWdpdCBhL3B1YmxpYy9jc3MvY29udm9jYXRvcmlhcy5jc3MgYi9wdWJsaWMvY3NzL2Nv
bnZvY2F0b3JpYXMuY3NzCi0tLSBhL3B1YmxpYy9jc3MvY29udm9jYXRvcmlhcy5jc3MKKysrIGIv
cHVibGljL2Nzcy9jb252b2NhdG9yaWFzLmNzcwpAQCAtMjI5Myw2ICsyMjkzLDE0NiBAQAogCiAK
IC8qIEJpZW52ZW5pZGEgZGVsIHBhbmVsIHByaW5jaXBhbCBkZSBNYXJrZXRpbmcuICovCisvKiBQ
YW5lbCDDum5pY28gZGUgaW5kaWNhZG9yZXM6IGV4Y2x1c2l2byBkZWwgaW5pY2lvIGRlIE1hcmtl
dGluZy4gKi8KKy5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgeworICAgIGRpc3BsYXk6IGdyaWQ7
CisgICAgZ3JpZC10ZW1wbGF0ZS1jb2x1bW5zOiAxLjFmciByZXBlYXQoNSwgbWlubWF4KDAsIDFm
cikpIC44NWZyOworICAgIGFsaWduLWl0ZW1zOiBzdHJldGNoOworICAgIG1pbi1oZWlnaHQ6IDE5
MHB4OworICAgIHBhZGRpbmc6IDMwcHggMjBweDsKKyAgICBib3JkZXI6IDFweCBzb2xpZCB2YXIo
LS1jb2xvci1ib3JkZXIpOworICAgIGJvcmRlci1yYWRpdXM6IDE4cHg7CisgICAgYmFja2dyb3Vu
ZDogI2ZmZjsKKyAgICBib3gtc2hhZG93OiAwIDhweCAyOHB4IHJnYmEoMjIsIDM0LCA1OSwgLjA0
KTsKK30KKy5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgPiAqIHsKKyAgICBtaW4td2lkdGg6IDA7
CisgICAgcGFkZGluZzogMCAxOHB4OworfQorLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyA+ICog
KyAqIHsKKyAgICBib3JkZXItbGVmdDogMXB4IHNvbGlkIHZhcigtLWNvbG9yLWJvcmRlcik7Cit9
CisubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRpbmcta2V5LWludHJvIHsKKyAgICBk
aXNwbGF5OiBmbGV4OworICAgIGZsZXgtZGlyZWN0aW9uOiBjb2x1bW47CisgICAganVzdGlmeS1j
b250ZW50OiBjZW50ZXI7CisgICAgcGFkZGluZy1sZWZ0OiA0cHg7Cit9CisubWFya2V0aW5nLWtl
eS1pbmRpY2F0b3JzIGgyIHsKKyAgICBtYXJnaW46IDAgMCA4cHg7CisgICAgY29sb3I6IHZhcigt
LWNvbG9yLXByaW1hcnkpOworICAgIGZvbnQtc2l6ZTogMThweDsKKyAgICBmb250LXdlaWdodDog
ODAwOworICAgIGxpbmUtaGVpZ2h0OiAxLjM7Cit9CisubWFya2V0aW5nLWtleS1pbmRpY2F0b3Jz
IHAgeworICAgIG1hcmdpbjogMTJweCAwIDA7CisgICAgY29sb3I6IHZhcigtLWNvbG9yLXRleHQt
c2Vjb25kYXJ5KTsKKyAgICBmb250LXNpemU6IDEycHg7CisgICAgZm9udC13ZWlnaHQ6IDYwMDsK
KyAgICBsaW5lLWhlaWdodDogMS41OworfQorLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFy
a2V0aW5nLWtleS1pbnRybyBwIHsKKyAgICBtYXJnaW46IDA7CisgICAgZm9udC1zaXplOiAxNHB4
OworfQorLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFya2V0aW5nLWtleS1tYWluIHsKKyAg
ICBkaXNwbGF5OiBmbGV4OworICAgIGFsaWduLWl0ZW1zOiBmbGV4LXN0YXJ0OworICAgIGdhcDog
MTJweDsKK30KKy5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXktbWFpbiA+
IGRpdiB7IG1pbi13aWR0aDogMDsgfQorLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFya2V0
aW5nLWtleS1pY29uIHsKKyAgICB3aWR0aDogNDZweDsKKyAgICBoZWlnaHQ6IDQ2cHg7CisgICAg
ZmxleDogMCAwIDQ2cHg7CisgICAgZGlzcGxheTogaW5saW5lLWZsZXg7CisgICAgYWxpZ24taXRl
bXM6IGNlbnRlcjsKKyAgICBqdXN0aWZ5LWNvbnRlbnQ6IGNlbnRlcjsKKyAgICBib3JkZXItcmFk
aXVzOiAxMXB4OworICAgIGJhY2tncm91bmQ6IHZhcigtLWNvbG9yLXByaW1hcnktc29mdCk7Cisg
ICAgY29sb3I6IHZhcigtLWNvbG9yLXNlY29uZGFyeSk7CisgICAgZm9udC1zaXplOiAyM3B4Owor
fQorLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyBzdHJvbmcgeworICAgIGRpc3BsYXk6IGJsb2Nr
OworICAgIGNvbG9yOiB2YXIoLS1jb2xvci1uYXZ5KTsKKyAgICBmb250LXNpemU6IDMycHg7Cisg
ICAgZm9udC13ZWlnaHQ6IDgwMDsKKyAgICBsaW5lLWhlaWdodDogMS4xNTsKKyAgICB3aGl0ZS1z
cGFjZTogbm93cmFwOworfQorLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyBoMyB7CisgICAgbWFy
Z2luOiA1cHggMCAwOworICAgIGNvbG9yOiB2YXIoLS1jb2xvci10ZXh0LXNlY29uZGFyeSk7Cisg
ICAgZm9udC1zaXplOiAxMnB4OworICAgIGZvbnQtd2VpZ2h0OiA3MDA7CisgICAgbGluZS1oZWln
aHQ6IDEuNDsKK30KKy5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXktYmFy
IHsKKyAgICBoZWlnaHQ6IDhweDsKKyAgICBtYXJnaW4tdG9wOiAxN3B4OworICAgIG92ZXJmbG93
OiBoaWRkZW47CisgICAgYm9yZGVyLXJhZGl1czogOTk5cHg7CisgICAgYmFja2dyb3VuZDogdmFy
KC0tY29sb3ItYm9yZGVyKTsKK30KKy5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGlu
Zy1rZXktYmFyID4gc3BhbiB7CisgICAgaGVpZ2h0OiAxMDAlOworICAgIGRpc3BsYXk6IGJsb2Nr
OworICAgIGJvcmRlci1yYWRpdXM6IGluaGVyaXQ7CisgICAgYmFja2dyb3VuZDogdmFyKC0tY29s
b3Itc2Vjb25kYXJ5KTsKK30KKy5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1r
ZXktYmFyICsgcCB7IG1hcmdpbi10b3A6IDlweDsgfQorLm1hcmtldGluZy1rZXktaW5kaWNhdG9y
cyAubWFya2V0aW5nLWtleS1hY3Rpb24geworICAgIGRpc3BsYXk6IGZsZXg7CisgICAgYWxpZ24t
aXRlbXM6IGNlbnRlcjsKKyAgICBwYWRkaW5nLXJpZ2h0OiA0cHg7Cit9CisubWFya2V0aW5nLWtl
eS1pbmRpY2F0b3JzIC5tYXJrZXRpbmcta2V5LWRldGFpbHMgeworICAgIHdpZHRoOiAxMDAlOwor
ICAgIG1pbi1oZWlnaHQ6IDYycHg7CisgICAgZGlzcGxheTogZmxleDsKKyAgICBhbGlnbi1pdGVt
czogY2VudGVyOworICAgIGp1c3RpZnktY29udGVudDogY2VudGVyOworICAgIGdhcDogOXB4Owor
ICAgIHBhZGRpbmc6IDEycHg7CisgICAgYm9yZGVyOiAxcHggc29saWQgdmFyKC0tY29sb3ItYm9y
ZGVyKTsKKyAgICBib3JkZXItcmFkaXVzOiAxMnB4OworICAgIGJhY2tncm91bmQ6ICNmOGZhZmM7
CisgICAgY29sb3I6IHZhcigtLWNvbG9yLXByaW1hcnkpOworICAgIGZvbnQtc2l6ZTogMTJweDsK
KyAgICBmb250LXdlaWdodDogODAwOworICAgIGxpbmUtaGVpZ2h0OiAxLjQ7CisgICAgdGV4dC1k
ZWNvcmF0aW9uOiBub25lOworfQorLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFya2V0aW5n
LWtleS1kZXRhaWxzOmZvY3VzLXZpc2libGUgeworICAgIG91dGxpbmU6IDJweCBzb2xpZCB2YXIo
LS1jb2xvci1zZWNvbmRhcnkpOworICAgIG91dGxpbmUtb2Zmc2V0OiAzcHg7Cit9CitAbWVkaWEg
KG1pbi13aWR0aDogMTIwMHB4KSBhbmQgKG1heC13aWR0aDogMTU5OS45OHB4KSB7CisgICAgLm1h
cmtldGluZy1rZXktaW5kaWNhdG9ycyA+ICogeyBwYWRkaW5nLWxlZnQ6IDEwcHg7IHBhZGRpbmct
cmlnaHQ6IDEwcHg7IH0KKyAgICAubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRpbmct
a2V5LWljb24geyB3aWR0aDogMjhweDsgaGVpZ2h0OiAyOHB4OyBmbGV4LWJhc2lzOiAyOHB4OyBm
b250LXNpemU6IDE4cHg7IH0KKyAgICAubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRp
bmcta2V5LW1haW4geyBkaXNwbGF5OiBncmlkOyBncmlkLXRlbXBsYXRlLWNvbHVtbnM6IDI4cHgg
bWlubWF4KDAsIDFmcik7IGdhcDogN3B4OyB9CisgICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9y
cyAubWFya2V0aW5nLWtleS1tYWluID4gZGl2IHsgZGlzcGxheTogY29udGVudHM7IH0KKyAgICAu
bWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIGgzIHsgZ3JpZC1jb2x1bW46IDEgLyAtMTsgbWluLWhl
aWdodDogMzFweDsgbWFyZ2luLXRvcDogMDsgfQorICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRv
cnMgc3Ryb25nIHsgZm9udC1zaXplOiAyMHB4OyB9CisgICAgLm1hcmtldGluZy1rZXktaW5kaWNh
dG9ycyBoMiB7IGZvbnQtc2l6ZTogMTRweDsgfQorICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRv
cnMgLm1hcmtldGluZy1rZXktZGV0YWlscyB7IHBhZGRpbmc6IDhweDsgZ2FwOiA2cHg7IGZvbnQt
c2l6ZTogMTFweDsgfQorfQorQG1lZGlhIChtYXgtd2lkdGg6IDExOTkuOThweCkgeworICAgIC5t
YXJrZXRpbmcta2V5LWluZGljYXRvcnMgeyBncmlkLXRlbXBsYXRlLWNvbHVtbnM6IHJlcGVhdCgz
LCBtaW5tYXgoMCwgMWZyKSk7IGdhcDogMjJweCAwOyB9CisgICAgLm1hcmtldGluZy1rZXktaW5k
aWNhdG9ycyAubWFya2V0aW5nLWtleS1pbnRybyB7IGdyaWQtY29sdW1uOiAxIC8gLTE7IHBhZGRp
bmctbGVmdDogMThweDsgfQorICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgPiA6bnRoLWNo
aWxkKDIpLAorICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgPiA6bnRoLWNoaWxkKDUpIHsg
Ym9yZGVyLWxlZnQ6IDA7IH0KK30KK0BtZWRpYSAobWF4LXdpZHRoOiA3NjcuOThweCkgeworICAg
IC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgeyBncmlkLXRlbXBsYXRlLWNvbHVtbnM6IDFmcjsg
Z2FwOiAwOyBwYWRkaW5nOiAyMHB4OyB9CisgICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyA+
ICogeyBwYWRkaW5nOiAxOHB4IDA7IH0KKyAgICAubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzID4g
KiArICogeyBib3JkZXItbGVmdDogMDsgYm9yZGVyLXRvcDogMXB4IHNvbGlkIHZhcigtLWNvbG9y
LWJvcmRlcik7IH0KKyAgICAubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRpbmcta2V5
LWludHJvIHsgcGFkZGluZzogMCAwIDE4cHg7IH0KKyAgICAubWFya2V0aW5nLWtleS1pbmRpY2F0
b3JzIC5tYXJrZXRpbmcta2V5LWFjdGlvbiB7IHBhZGRpbmc6IDE4cHggMCAwOyB9Cit9CisKIC5t
YXJrZXRpbmctd2VsY29tZS1zaGVsbCB7CiAgICAgbWFyZ2luLWJvdHRvbTogMTZweDsKIH0K
KPI_PATCH_0
 [[ "$(digest "$TMP/feature-0.patch")" == 'c3bf842b79d8efb134937e3449dbedf33b99844d024146707ca1b220455559cb' ]] || fail 'Parche alterado.'
 f='public/css/convocatorias.css'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 3f19c7058b89dc471f13db3d69d4e349c0869ed6233b13d7aaa3d6b785dd2d67) ;;
 a7cdd428f57d0626ca19482384911e2b7f4092faaf4f27db60b7ded1ad916b6a)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-0.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-0.patch")
  case "$(code_digest "$TMP/stage/$f")" in 3f19c7058b89dc471f13db3d69d4e349c0869ed6233b13d7aaa3d6b785dd2d67) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-1.patch" <<'KPI_PATCH_1'
ZGlmZiAtLWdpdCBhL2FwcC92aWV3cy9kYXNoYm9hcmQvbWFya2V0aW5nLnBocCBiL2FwcC92aWV3
cy9kYXNoYm9hcmQvbWFya2V0aW5nLnBocAotLS0gYS9hcHAvdmlld3MvZGFzaGJvYXJkL21hcmtl
dGluZy5waHAKKysrIGIvYXBwL3ZpZXdzL2Rhc2hib2FyZC9tYXJrZXRpbmcucGhwCkBAIC0xMTcs
MzkgKzExNyw3IEBACiAgICAgPC9zZWN0aW9uPgogPC9zZWN0aW9uPgogCi08ZGl2IGNsYXNzPSJt
ZXRyaWMtZ3JpZCBtYi00Ij4KLSAgICA8YXJ0aWNsZSBjbGFzcz0ibWV0cmljLWNhcmQiPgotICAg
ICAgICA8ZGl2IGNsYXNzPSJtZXRyaWMtaWNvbiI+PGkgY2xhc3M9ImJpIGJpLW1lZ2FwaG9uZSI+
PC9pPjwvZGl2PgotICAgICAgICA8ZGl2PgotICAgICAgICAgICAgPHAgY2xhc3M9Im1ldHJpYy12
YWx1ZSI+PD89IChpbnQpKCRyZXN1bWVuTWFya2V0aW5nWyd0b3RhbCddID8/IDApID8+PC9wPgot
ICAgICAgICAgICAgPHAgY2xhc3M9Im1ldHJpYy1sYWJlbCI+VG90YWwgZGUgY29udm9jYXRvcmlh
czwvcD4KLSAgICAgICAgPC9kaXY+Ci0gICAgPC9hcnRpY2xlPgotCi0gICAgPGFydGljbGUgY2xh
c3M9Im1ldHJpYy1jYXJkIj4KLSAgICAgICAgPGRpdiBjbGFzcz0ibWV0cmljLWljb24gbWV0cmlj
LWljb24tc3VjY2VzcyI+PGkgY2xhc3M9ImJpIGJpLWNoZWNrLWNpcmNsZSI+PC9pPjwvZGl2Pgot
ICAgICAgICA8ZGl2PgotICAgICAgICAgICAgPHAgY2xhc3M9Im1ldHJpYy12YWx1ZSI+PD89IChp
bnQpKCRyZXN1bWVuTWFya2V0aW5nWydhY3RpdmFzJ10gPz8gMCkgPz48L3A+Ci0gICAgICAgICAg
ICA8cCBjbGFzcz0ibWV0cmljLWxhYmVsIj5Db252b2NhdG9yaWFzIGFjdGl2YXM8L3A+Ci0gICAg
ICAgIDwvZGl2PgotICAgIDwvYXJ0aWNsZT4KLQotICAgIDxhcnRpY2xlIGNsYXNzPSJtZXRyaWMt
Y2FyZCI+Ci0gICAgICAgIDxkaXYgY2xhc3M9Im1ldHJpYy1pY29uIG1ldHJpYy1pY29uLW11dGVk
Ij48aSBjbGFzcz0iYmkgYmktc2xhc2gtY2lyY2xlIj48L2k+PC9kaXY+Ci0gICAgICAgIDxkaXY+
Ci0gICAgICAgICAgICA8cCBjbGFzcz0ibWV0cmljLXZhbHVlIj48Pz0gKGludCkoJHJlc3VtZW5N
YXJrZXRpbmdbJ2luYWN0aXZhcyddID8/IDApID8+PC9wPgotICAgICAgICAgICAgPHAgY2xhc3M9
Im1ldHJpYy1sYWJlbCI+Q29udm9jYXRvcmlhcyBpbmFjdGl2YXM8L3A+Ci0gICAgICAgIDwvZGl2
PgotICAgIDwvYXJ0aWNsZT4KLQotICAgIDxhcnRpY2xlIGNsYXNzPSJtZXRyaWMtY2FyZCI+Ci0g
ICAgICAgIDxkaXYgY2xhc3M9Im1ldHJpYy1pY29uIj48aSBjbGFzcz0iYmkgYmktY2FsZW5kYXIt
Y2hlY2siPjwvaT48L2Rpdj4KLSAgICAgICAgPGRpdj4KLSAgICAgICAgICAgIDxwIGNsYXNzPSJt
ZXRyaWMtdmFsdWUiPjw/PSAoaW50KSgkcmVzdW1lbk1hcmtldGluZ1sndmlnZW50ZXMnXSA/PyAw
KSA/PjwvcD4KLSAgICAgICAgICAgIDxwIGNsYXNzPSJtZXRyaWMtbGFiZWwiPkNvbnZvY2F0b3Jp
YXMgdmlnZW50ZXM8L3A+Ci0gICAgICAgIDwvZGl2PgotICAgIDwvYXJ0aWNsZT4KLTwvZGl2Pgor
PD9waHAgcmVxdWlyZSBfX0RJUl9fIC4gJy9tYXJrZXRpbmdfaW5kaWNhZG9yZXMucGhwJzsgPz4K
IAogCiA8c2VjdGlvbiBjbGFzcz0iZGFzaGJvYXJkLXBhbmVsIG1hcmtldGluZy1leHBpcmluZy1w
YW5lbCI+Cg==
KPI_PATCH_1
 [[ "$(digest "$TMP/feature-1.patch")" == 'b8675224051a1ea8aef500c818a017adb3e98a652924e5ee92baff7d926b9e00' ]] || fail 'Parche alterado.'
 f='app/views/dashboard/marketing.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 4446c141f53a00fed30f5bb3db0a5d54b352f37f320fa990280319f6f8ac4940) ;;
 dcf3b12032e97642c5ab45d22ed915f9e559be6f00013d59e01318e7597a0e75)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-1.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-1.patch")
  case "$(code_digest "$TMP/stage/$f")" in 4446c141f53a00fed30f5bb3db0a5d54b352f37f320fa990280319f6f8ac4940) ;; *) fail "Resultado inesperado: $f";; esac ;;
 *) fail "Versión personalizada/incompatible: $f. SITIO INTACTO.";;
 esac
 base64 --decode > "$TMP/feature-2.patch" <<'KPI_PATCH_2'
ZGlmZiAtLWdpdCBhL2FwcC9jb250cm9sbGVycy9Ib21lQ29udHJvbGxlci5waHAgYi9hcHAvY29u
dHJvbGxlcnMvSG9tZUNvbnRyb2xsZXIucGhwCi0tLSBhL2FwcC9jb250cm9sbGVycy9Ib21lQ29u
dHJvbGxlci5waHAKKysrIGIvYXBwL2NvbnRyb2xsZXJzL0hvbWVDb250cm9sbGVyLnBocApAQCAt
MTY0LDYgKzE2NCwxMSBAQAogICAgICAgICAgICAgICAgICAgICAkcmVzdW1lbk1hcmtldGluZ1sn
cHJveGltYXNfZmluYWxpemFyJ10gPQogICAgICAgICAgICAgICAgICAgICAgICAgY291bnQoJHBy
b3hpbWFzRmluYWxpemFyTWFya2V0aW5nKTsKICAgICAgICAgICAgICAgICAgICAgJGNvYmVydHVy
YU1hcmtldGluZyA9ICRtb2RlbG9Db252b2NhdG9yaWEtPm9idGVuZXJDb2JlcnR1cmFUZXJyaXRv
cmlhbERhc2hib2FyZCg0KTsKKyAgICAgICAgICAgICAgICAgICAgcmVxdWlyZV9vbmNlIF9fRElS
X18gLiAnLy4uL21vZGVscy9NYXJrZXRpbmdJbmRpY2Fkb3Jlc01vZGVsLnBocCc7CisgICAgICAg
ICAgICAgICAgICAgICRpbmRpY2Fkb3Jlc0NsYXZlTWFya2V0aW5nID0gKG5ldyBNYXJrZXRpbmdJ
bmRpY2Fkb3Jlc01vZGVsKCkpLT5vYnRlbmVyKAorICAgICAgICAgICAgICAgICAgICAgICAgJHJl
c3VtZW5NYXJrZXRpbmcsCisgICAgICAgICAgICAgICAgICAgICAgICAkY29iZXJ0dXJhTWFya2V0
aW5nCisgICAgICAgICAgICAgICAgICAgICk7CiAgICAgICAgICAgICAgICAgICAgICRlc3RhZG9z
U2luQ29udm9jYXRvcmlhID0gJG1vZGVsb0NvbnZvY2F0b3JpYQogICAgICAgICAgICAgICAgICAg
ICAgICAgLT5vYnRlbmVyRXN0YWRvc1NpbkNvbnZvY2F0b3JpYUFjdGl2YURhc2hib2FyZCgpOwog
Cg==
KPI_PATCH_2
 [[ "$(digest "$TMP/feature-2.patch")" == 'b671f62c007eda7ac59184e22e00ca8c2ffb04197a74c800dc4e02645a2bf7af' ]] || fail 'Parche alterado.'
 f='app/controllers/HomeController.php'
 [[ "${BEFORE[$f]}" != MISSING ]] || fail "Falta archivo requerido: $f"
 cp -p -- "$LIVE/$f" "$TMP/stage/$f"
 EOLS["$f"]="$(php -r '$s=file_get_contents($argv[1]);$n=substr_count($s,"\n");$c=substr_count($s,"\r\n");if($c&&$c!==$n)exit(1);echo $c?"CRLF":"LF";file_put_contents($argv[1],str_replace("\r\n","\n",$s));' "$TMP/stage/$f")" || fail "Finales de línea mixtos: $f"
 actual="$(code_digest "$TMP/stage/$f")"
 case "$actual" in
 2a4007342990f9ce515a32cb76a0ff4feefa4c77e860fe2088849529ad46b8a7) ;;
 dde4c1b6373ae5e684984e79b75c64e1edf1da34a58d5848ab703381448433c9)
  (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/feature-2.patch") || fail "Personalización incompatible: $f. SITIO INTACTO."
  (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/feature-2.patch")
  case "$(code_digest "$TMP/stage/$f")" in 2a4007342990f9ce515a32cb76a0ff4feefa4c77e860fe2088849529ad46b8a7) ;; *) fail "Resultado inesperado: $f";; esac ;;
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
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/marketing-indicadores-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
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
echo "OK: $MODE terminado. ${#CHANGED[@]} archivo(s). Sin cambios de BD ni credenciales. Comprobar el panel y su diagnóstico de sólo lectura."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
