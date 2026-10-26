#!/usr/bin/env bash
# Sólo presentación: retirar Nueva convocatoria de Gestión. Sin SQL, red ni cambios en operaciones.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='convocatorias-sin-modal-v2'
FILES=('app/views/convocatorias/index.php')
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
fail(){ echo "ERROR: $*" >&2;exit 1; }
hash(){ sha256sum "$1" | cut -d' ' -f1; }
no_links(){ local part="$1";while [[ "$part" != / && "$part" != . ]];do
 [[ ! -L "$part" ]] || fail "Enlace simbólico no permitido: $1"
 case "$part" in */*) part="${part%/*}";[[ -n "$part" ]] || part=/;; *) part=.;;esac
done; }
for tool in php sha256sum cut mktemp realpath cp mv mkdir rm rmdir dirname date chmod;do command -v "$tool" >/dev/null || fail "Falta $tool";done
no_links "$LIVE";[[ -d "$LIVE" ]] || fail 'Raíz del CRM no encontrada.'
LIVE="$(cd "$LIVE" && pwd -P)";[[ -f "$LIVE/index.php" ]] || fail 'Falta el índice del CRM.'
for file in "${FILES[@]}";do no_links "$LIVE/$file";[[ -f "$LIVE/$file" ]] || fail "Falta $file";done
no_links "$BACKUPS";BACKUPS="$(realpath -m "$BACKUPS")"
[[ "$BACKUPS" != / && "$BACKUPS/" != "$LIVE/"* && "$LIVE/" != "$BACKUPS/"* ]] || fail 'El respaldo debe estar fuera del sitio y sus antecesores.'
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)";[[ "$SCRATCH/" != "$LIVE/"* ]] || fail 'Temporales fuera del sitio requeridos.'
TMP="$(mktemp -d "$SCRATCH/impe-convocatorias-sin-modal.XXXXXX")"
LOCK="$(dirname "$LIVE")/.impe-correos-deploy.lock";LOCKED=0;INSTALLING=-1
BEFORE=();AFTER=();WRITTEN=();CHANGED=()
finish(){ local code=$?;trap - EXIT INT TERM
 if [[ "$code" != 0 ]];then
  for i in "${WRITTEN[@]}";do
   if [[ -f "$LIVE/${FILES[$i]}" && "$(hash "$LIVE/${FILES[$i]}")" == "${AFTER[$i]}" ]];then
    cp -p -- "$TMP/original.$i" "$LIVE/${FILES[$i]}" || echo 'REVISAR RESTAURACIÓN DEL ARCHIVO' >&2
   else echo "REVISAR: archivo cambiado durante la publicación: ${FILES[$i]}" >&2;fi
  done
 fi
 if [[ "$INSTALLING" != -1 ]];then rm -f -- "$LIVE/${FILES[$INSTALLING]}.impe-convocatorias-sin-modal-$$";fi
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 rm -f -- "$TMP/original.0" "$TMP/stage.0" "$TMP/patch.php"
 rmdir -- "$TMP";exit "$code"
}
trap finish EXIT;trap 'exit 130' INT;trap 'exit 143' TERM
if [[ "$MODE" != --check ]];then no_links "$LOCK";mkdir "$LOCK" 2>/dev/null || fail 'Otra publicación está en curso.';LOCKED=1;fi
for i in "${!FILES[@]}";do BEFORE[$i]="$(hash "$LIVE/${FILES[$i]}")";cp -p -- "$LIVE/${FILES[$i]}" "$TMP/original.$i";done
if [[ "$MODE" == --restore ]];then
 no_links "$2";RESTORE="$(realpath -e "$2")"
 [[ "$(dirname "$RESTORE")" == "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
 no_links "$RESTORE/version.txt";[[ -f "$RESTORE/version.txt" && "$(<"$RESTORE/version.txt")" == "$VERSION" ]] || fail 'Respaldo de otra entrega.'
 for i in "${!FILES[@]}";do
  for file in "original.$i" "original.$i.sha256" "publicado.$i.sha256";do no_links "$RESTORE/$file";[[ -f "$RESTORE/$file" ]] || fail 'Respaldo incompleto.';done
  original="$(<"$RESTORE/original.$i.sha256")";published="$(<"$RESTORE/publicado.$i.sha256")"
  [[ "$original" =~ ^[a-f0-9]{64}$ && "$published" =~ ^[a-f0-9]{64}$ && "$(hash "$RESTORE/original.$i")" == "$original" ]] || fail 'Respaldo alterado.'
  [[ "${BEFORE[$i]}" == "$published" || "${BEFORE[$i]}" == "$original" ]] || fail 'Archivo editado después de publicar. No se sobrescribe.'
  cp -p -- "$RESTORE/original.$i" "$TMP/stage.$i"
 done
else
 cat > "$TMP/patch.php" <<'IMPE_PATCH'
<?php
try {
 $cfg=json_decode(base64_decode('eyJwYWlycyI6IFtbIiAgICAgICAgPGRpdiBjbGFzcz1cIm1vZHVsZS10b29sYmFyLWFjdGlvbnNcIiBkYXRhLWNvbnZvY2F0b3JpYS1jcmVhdGUtYWN0aW9uPlxuICAgICAgICAgICAgPD9waHAgaWYgKCRwdWVkZUNyZWFyKTogPz4iLCAiICAgICAgICA8ZGl2IGNsYXNzPVwibW9kdWxlLXRvb2xiYXItYWN0aW9uc1wiIGRhdGEtY29udm9jYXRvcmlhLWNyZWF0ZS1hY3Rpb24+XG4gICAgICAgICAgICA8P3BocCBpZiAoJHB1ZWRlQ3JlYXIgJiYgIWVtcHR5KCRkZXNkZUNvbnZlbmlvKSk6ID8+Il0sIFsiPGRpdlxuICAgIGNsYXNzPVwibW9kYWwgZmFkZVwiXG4gICAgaWQ9XCJtb2RhbENyZWFyQ29udm9jYXRvcmlhXCIiLCAiPD9waHAgaWYgKCFlbXB0eSgkZGVzZGVDb252ZW5pbykpOiA/PlxuPGRpdlxuICAgIGNsYXNzPVwibW9kYWwgZmFkZVwiXG4gICAgaWQ9XCJtb2RhbENyZWFyQ29udm9jYXRvcmlhXCIiXSwgWyI8ZGl2XG4gICAgY2xhc3M9XCJtb2RhbCBmYWRlXCJcbiAgICBpZD1cIm1vZGFsQ29udm9jYXRvcmlhUXJcIiIsICI8P3BocCBlbmRpZjsgPz5cbjxkaXZcbiAgICBjbGFzcz1cIm1vZGFsIGZhZGVcIlxuICAgIGlkPVwibW9kYWxDb252b2NhdG9yaWFRclwiIl1dLCAic2hhcmVkX29sZCI6ICI8P3BocCByZXF1aXJlIF9fRElSX18gLiAnL3BhcnRpYWxzL2NyZWFyX21vZGFsLnBocCc7ID8+IiwgInNoYXJlZF9uZXciOiAiPD9waHAgcmVxdWlyZSBfX0RJUl9fIC4gJy9wYXJ0aWFscy9jcmVhcl9tb2RhbC5waHAnOyA/PlxuPD9waHAgaWYgKGVtcHR5KCRkZXNkZUNvbnZlbmlvKSk6ID8+XG48c2NyaXB0IGRhdGEtY29udm9jYXRvcmlhLWdlc3Rpb24tc2luLW1vZGFsPlxuKGZ1bmN0aW9uICgpIHtcbiAgICAvLyBTw7NsbyBlc3RhIHBhbnRhbGxhOiBjb25zZXJ2YXIgbGEgcGxhbnRpbGxhIGNvbXBhcnRpZGEgeSBlbCBtb2RhbCBkZSBRUi5cbiAgICBjb25zdCBtb2RhbENyZWFyR2VzdGlvbiA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdtb2RhbENyZWFyQ29udm9jYXRvcmlhJyk7XG4gICAgaWYgKG1vZGFsQ3JlYXJHZXN0aW9uKSBtb2RhbENyZWFyR2VzdGlvbi5yZW1vdmUoKTtcbn0pKCk7XG48L3NjcmlwdD5cbjw/cGhwIGVuZGlmOyA/PiJ9'),true,512,JSON_THROW_ON_ERROR);
 $s=file_get_contents($argv[2]);$crlf=substr_count($s,"\r\n");
 if($crlf&&$crlf!==substr_count($s,"\n"))throw new RuntimeException('Finales de línea mixtos.');
 $s=str_replace("\r\n","\n",$s);
 $reemplazar=static function($s,$old,$new,$name){
  if(substr_count($s,$new)===1)return $s;
  if(substr_count($s,$new)!==0||substr_count($s,$old)!==1)throw new RuntimeException('Bloque incompatible: '.$name.'. No se reemplaza la vista completa.');
  return str_replace($old,$new,$s);
 };
 $s=$reemplazar($s,$cfg['pairs'][0][0],$cfg['pairs'][0][1],'botón');
 if(substr_count($s,$cfg['shared_old'])===1){
  if(strpos($s,$cfg['pairs'][1][0])!==false)throw new RuntimeException('Vista ambigua con dos definiciones del modal.');
  $s=$reemplazar($s,$cfg['shared_old'],$cfg['shared_new'],'inclusión compartida');
 }else{
  foreach([1,2] as $i)$s=$reemplazar($s,$cfg['pairs'][$i][0],$cfg['pairs'][$i][1],'modal integrado '.($i+1));
 }
 if($crlf)$s=str_replace("\n","\r\n",$s);
 if(file_put_contents($argv[3],$s)!==strlen($s))throw new RuntimeException('No se pudo preparar el archivo.');
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
IMPE_PATCH
 for i in "${!FILES[@]}";do php "$TMP/patch.php" "$i" "$TMP/original.$i" "$TMP/stage.$i" || fail 'Vista de convocatorias incompatible. SITIO INTACTO.';chmod --reference="$TMP/original.$i" "$TMP/stage.$i";done
fi
for i in "${!FILES[@]}";do php -l "$TMP/stage.$i" >/dev/null || fail 'Error de sintaxis en el servicio preparado.';done
for i in "${!FILES[@]}";do
 no_links "$LIVE/${FILES[$i]}";[[ "$(hash "$LIVE/${FILES[$i]}")" == "${BEFORE[$i]}" ]] || fail 'Un archivo cambió durante la revisión.'
 AFTER[$i]="$(hash "$TMP/stage.$i")";[[ "${AFTER[$i]}" == "${BEFORE[$i]}" ]] || CHANGED+=("$i")
done
if [[ "${#CHANGED[@]}" == 0 ]];then echo 'OK: ya aplicado o restaurado. Ningún archivo reemplazado.';exit 0;fi
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. Sólo visibilidad de Nueva convocatoria en la vista de Gestión; modalidad integrada o compartida. SITIO INTACTO. Sin envíos ni SQL.';exit 0;fi
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/convocatorias-sin-modal-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
 for i in "${!FILES[@]}";do
  cp -p -- "$TMP/original.$i" "$BACKUP/original.$i";[[ "$(hash "$BACKUP/original.$i")" == "${BEFORE[$i]}" ]] || fail 'Respaldo inconsistente.'
  printf '%s\n' "${BEFORE[$i]}" > "$BACKUP/original.$i.sha256";printf '%s\n' "${AFTER[$i]}" > "$BACKUP/publicado.$i.sha256"
 done
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt"
fi
for i in "${CHANGED[@]}";do
 install="$LIVE/${FILES[$i]}.impe-convocatorias-sin-modal-$$";[[ ! -e "$install" && ! -L "$install" ]] || fail 'Existe un temporal de instalación.'
 INSTALLING="$i";cp -p -- "$TMP/stage.$i" "$install"
 for j in "${CHANGED[@]}";do
  expected="${BEFORE[$j]}";for done_i in "${WRITTEN[@]}";do [[ "$done_i" != "$j" ]] || expected="${AFTER[$j]}";done
  no_links "$LIVE/${FILES[$j]}";[[ "$(hash "$LIVE/${FILES[$j]}")" == "$expected" ]] || fail 'Un archivo cambió antes de instalar.'
 done
 WRITTEN+=("$i");mv -f -- "$install" "$LIVE/${FILES[$i]}";INSTALLING=-1
 [[ "$(hash "$LIVE/${FILES[$i]}")" == "${AFTER[$i]}" ]] || fail 'Archivo instalado distinto.'
done
echo "OK: $MODE terminado. Sólo visibilidad de Nueva convocatoria en Gestión. Creación desde Convenios y demás acciones conservadas. Sin SQL ni cambios en controladores, modelos, archivos JavaScript o CSS."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
