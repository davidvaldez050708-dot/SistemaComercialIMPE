#!/usr/bin/env bash
# Retirar sólo Cobertura internacional de Aliados. Sin BD ni red.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='aliados-sin-cobertura-v1'
FILE='app/views/aliados/index.php'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
fail(){ echo "ERROR: $*" >&2;exit 1; }
hash(){ sha256sum "$1" | cut -d' ' -f1; }
no_links(){ local part="$1";while [[ "$part" != / && "$part" != . ]];do
 [[ ! -L "$part" ]] || fail "Enlace simbólico no permitido: $1"
 case "$part" in */*) part="${part%/*}";[[ -n "$part" ]] || part=/;; *) part=.;;esac
done; }
for tool in php base64 sha256sum cut mktemp realpath cp mv mkdir rm rmdir dirname date chmod;do command -v "$tool" >/dev/null || fail "Falta $tool";done
no_links "$LIVE";[[ -d "$LIVE" ]] || fail 'Raíz del CRM no encontrada.'
LIVE="$(cd "$LIVE" && pwd -P)";no_links "$LIVE/$FILE"
[[ -f "$LIVE/index.php" && -f "$LIVE/$FILE" ]] || fail 'Falta la vista requerida del CRM.'
no_links "$BACKUPS";BACKUPS="$(realpath -m "$BACKUPS")"
[[ "$BACKUPS" != / && "$BACKUPS/" != "$LIVE/"* && "$LIVE/" != "$BACKUPS/"* ]] || fail 'El respaldo debe estar fuera del sitio y sus antecesores.'
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)";[[ "$SCRATCH/" != "$LIVE/"* ]] || fail 'Temporales fuera del sitio requeridos.'
TMP="$(mktemp -d "$SCRATCH/impe-aliados-cobertura.XXXXXX")"
LOCK="$(dirname "$LIVE")/.impe-correos-deploy.lock";LOCKED=0;WRITING=0;INSTALL_CREATED=0
INSTALL="$LIVE/$FILE.impe-aliados-cobertura-$$"
finish(){ local code=$?;trap - EXIT INT TERM
 if [[ "$code" != 0 && "$WRITING" == 1 ]];then cp -p -- "$TMP/original.php" "$LIVE/$FILE" || echo 'REVISAR RESTAURACIÓN DE LA VISTA' >&2;fi
 [[ "$INSTALL_CREATED" == 0 ]] || rm -f -- "$INSTALL"
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 rm -f -- "$TMP/original.php" "$TMP/normal.php" "$TMP/block.php" "$TMP/stage.php"
 rmdir -- "$TMP";exit "$code"
}
trap finish EXIT;trap 'exit 130' INT;trap 'exit 143' TERM
[[ ! -e "$INSTALL" && ! -L "$INSTALL" ]] || fail 'Existe un temporal de instalación.'
if [[ "$MODE" != --check ]];then no_links "$LOCK";mkdir "$LOCK" 2>/dev/null || fail 'Otra publicación está en curso.';LOCKED=1;fi
BEFORE="$(hash "$LIVE/$FILE")";cp -p -- "$LIVE/$FILE" "$TMP/original.php"
if [[ "$MODE" == --restore ]];then
 no_links "$2";RESTORE="$(realpath -e "$2")"
 [[ "$(dirname "$RESTORE")" == "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
 for f in version.txt original.php original.sha256 publicado.sha256;do no_links "$RESTORE/$f";[[ -f "$RESTORE/$f" ]] || fail 'Respaldo incompleto.';done
 [[ "$(<"$RESTORE/version.txt")" == "$VERSION" ]] || fail 'Respaldo de otra entrega.'
 ORIGINAL="$(<"$RESTORE/original.sha256")";PUBLISHED="$(<"$RESTORE/publicado.sha256")"
 [[ "$ORIGINAL" =~ ^[a-f0-9]{64}$ && "$PUBLISHED" =~ ^[a-f0-9]{64}$ && "$(hash "$RESTORE/original.php")" == "$ORIGINAL" ]] || fail 'Respaldo alterado.'
 [[ "$BEFORE" != "$ORIGINAL" ]] || { echo 'OK: ya restaurado.';exit 0; }
 [[ "$BEFORE" == "$PUBLISHED" ]] || fail 'Vista editada después de publicar. No se sobrescribe.'
 cp -p -- "$RESTORE/original.php" "$TMP/stage.php"
else
 EOL="$(php -r '$s=file_get_contents($argv[1]);$c=substr_count($s,"\r\n");$n=substr_count($s,"\n");if($c&&$c!==$n)exit(2);echo $c?"CRLF":"LF";file_put_contents($argv[2],str_replace("\r\n","\n",$s));' "$TMP/original.php" "$TMP/normal.php")" || fail 'Finales de línea mixtos.'
 # Revisar el bloque concreto; las diferencias fuera de él se conservan.
 php -r '
 $s=file_get_contents($argv[1]);
 $b=base64_decode("PD9waHAgaWYgKChpbnQpKCRfU0VTU0lPTlsncm9sX2lkJ10gPz8gMCkgPT09IDEpOiA/Pgo8c2VjdGlvbiBjbGFzcz0iZGFzaGJvYXJkLXBhbmVsIG1iLTMiIHN0eWxlPSJwYWRkaW5nOjIwcHgiPgogPGRpdiBjbGFzcz0iZC1mbGV4IGFsaWduLWl0ZW1zLWNlbnRlciBqdXN0aWZ5LWNvbnRlbnQtYmV0d2VlbiBmbGV4LXdyYXAgZ2FwLTMiPgogIDxkaXY+PHNwYW4gY2xhc3M9InRleHQtbXV0ZWQgdGV4dC11cHBlcmNhc2UiPjxpIGNsYXNzPSJiaSBiaS1nbG9iZS1hbWVyaWNhcyI+PC9pPiBDb2JlcnR1cmEgaW50ZXJuYWNpb25hbDwvc3Bhbj4KICA8aDIgY2xhc3M9InBhbmVsLXRpdGxlIG10LTIgbWItMSI+QWxpYWRvcyBleHRlcm5vczwvaDI+CiAgPHAgY2xhc3M9InRleHQtbXV0ZWQgbWItMCI+Q29uc3VsdGEgSGFuZGx5IExlbmd1YWplIENlbnRlciB5IG90cm9zIGFsaWFkb3Mgc2luIGFzaWduYWNpw7NuIHRlcnJpdG9yaWFsIG1leGljYW5hLCBzdXMgZW5sYWNlcyBleGNsdXNpdm9zIHkgbGFzIHJlc3B1ZXN0YXMgcmVjaWJpZGFzLjwvcD48L2Rpdj4KICA8YSBjbGFzcz0iYnRuIGJ0bi1zeXN0ZW0tc2F2ZSIgaHJlZj0iPD89IEJBU0VfVVJMID8+aW5kZXgucGhwP2NvbnRyb2xsZXI9YWxpYWRvJmFtcDthY3Rpb249aW50ZXJuYWNpb25hbGVzIj48aSBjbGFzcz0iYmkgYmktYXJyb3ctdXAtcmlnaHQtY2lyY2xlIj48L2k+IFZlciBhbGlhZG9zIGludGVybmFjaW9uYWxlczwvYT4KIDwvZGl2Pgo8L3NlY3Rpb24+Cjw/cGhwIGVuZGlmOyA/Pg==",true);
 $count=substr_count($s,$b);
 $markers=["Cobertura internacional","Aliados externos","Ver aliados internacionales"];
 if($count===0){
   foreach($markers as $marker){if(stripos($s,$marker)!==false){fwrite(STDERR,"Tarjeta con estructura distinta: requiere revisión del bloque.\n");exit(2);}}
   if(strpos($s,"aliados-territory-selector")===false){fwrite(STDERR,"No se reconoce la vista territorial de Aliados.\n");exit(2);}
   exit(3);
 }
 if($count!==1){fwrite(STDERR,"El bloque aparece más de una vez.\n");exit(2);}
 foreach($markers as $marker){if(substr_count($s,$marker)!==1){fwrite(STDERR,"Marcadores ambiguos de la tarjeta.\n");exit(2);}}
 $pos=strpos($s,$b);$offset=0;$active=false;
 foreach(token_get_all($s) as $token){
   $text=is_array($token)?$token[1]:$token;
   if($offset===$pos){$active=is_array($token)&&$token[0]===T_OPEN_TAG;break;}
   $offset+=strlen($text);
 }
 if(!$active){fwrite(STDERR,"El bloque está en un comentario o cadena; no se modifica.\n");exit(2);}
 $result=substr($s,0,$pos).substr($s,$pos+strlen($b));
 if(file_put_contents($argv[2],$result)!==strlen($result))exit(2);
 ' "$TMP/normal.php" "$TMP/stage.php" && PATCH_STATUS=0 || PATCH_STATUS=$?
 if [[ "$PATCH_STATUS" == 3 ]];then
   echo 'OK: tarjeta ausente (ya aplicado o retirada previamente). Ningún archivo reemplazado.';exit 0
 fi
 [[ "$PATCH_STATUS" == 0 ]] || fail 'No se pudo identificar inequívocamente el bloque revisado. SITIO INTACTO.'
 if [[ "$EOL" == CRLF ]];then php -r '$p=$argv[1];file_put_contents($p,str_replace("\n","\r\n",file_get_contents($p)));' "$TMP/stage.php";fi
 chmod --reference="$TMP/original.php" "$TMP/stage.php"
fi
php -l "$TMP/stage.php" >/dev/null || fail 'Error de sintaxis en la vista preparada.'
no_links "$LIVE/$FILE";[[ "$(hash "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'Vista cambió durante la revisión.'
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. 1 vista PHP. SITIO INTACTO. Sin SQL ni envíos.';exit 0;fi
AFTER="$(hash "$TMP/stage.php")"
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/aliados-cobertura-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
 cp -p -- "$TMP/original.php" "$BACKUP/original.php"
 [[ "$(hash "$BACKUP/original.php")" == "$BEFORE" ]] || fail 'Respaldo inconsistente.'
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt";printf '%s\n' "$BEFORE" > "$BACKUP/original.sha256";printf '%s\n' "$AFTER" > "$BACKUP/publicado.sha256"
fi
[[ ! -e "$INSTALL" && ! -L "$INSTALL" ]] || fail 'Temporal de instalación inesperado.'
INSTALL_CREATED=1;cp -p -- "$TMP/stage.php" "$INSTALL"
no_links "$LIVE/$FILE";[[ "$(hash "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'Vista cambió antes de instalar.'
WRITING=1;mv -f -- "$INSTALL" "$LIVE/$FILE"
[[ "$(hash "$LIVE/$FILE")" == "$AFTER" ]] || fail 'Vista instalada distinta.'
WRITING=0
echo "OK: $MODE terminado. Sólo $FILE. Sin cambios funcionales, BD ni credenciales."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
