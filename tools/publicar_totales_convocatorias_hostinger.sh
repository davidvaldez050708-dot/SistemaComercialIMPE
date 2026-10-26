#!/usr/bin/env bash
# Sólo contador informativo de convocatorias por Estado. Sin SQL durante la publicación ni cambios de registros.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='convocatorias-total-estado-v1'
FILES=('app/models/ConvocatoriaModel.php' 'app/controllers/ConvocatoriaController.php' 'app/views/convocatorias/index.php')
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
TMP="$(mktemp -d "$SCRATCH/impe-convocatorias-total.XXXXXX")"
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
 if [[ "$INSTALLING" != -1 ]];then rm -f -- "$LIVE/${FILES[$INSTALLING]}.impe-convocatorias-total-$$";fi
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 for i in "${!FILES[@]}";do rm -f -- "$TMP/original.$i" "$TMP/stage.$i";done
 rm -f -- "$TMP/patch.php"
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
 $cfg=json_decode(base64_decode('W1siICAgIHB1YmxpYyBmdW5jdGlvbiBvYnRlbmVyTGlzdGFkbyhcbiIsICIgICAgcHVibGljIGZ1bmN0aW9uIG9idGVuZXJUb3RhbGVzUG9yRXN0YWRvKGFycmF5ICRlc3RhZG9zSWRzKVxuICAgIHtcbiAgICAgICAgJGlkcyA9IGFycmF5X3ZhbHVlcyhhcnJheV91bmlxdWUoYXJyYXlfZmlsdGVyKGFycmF5X21hcCgnaW50dmFsJywgJGVzdGFkb3NJZHMpLFxuICAgICAgICAgICAgc3RhdGljIGZuKCRpZCkgPT4gJGlkID4gMCkpKTtcbiAgICAgICAgaWYgKCEkaWRzKSB7XG4gICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgIH1cblxuICAgICAgICAkbWFyY2Fkb3JlcyA9IGltcGxvZGUoJywnLCBhcnJheV9maWxsKDAsIGNvdW50KCRpZHMpLCAnPycpKTtcbiAgICAgICAgJHNxbCA9IFwiU0VMRUNUIGNlLmVzdGFkb19pZCwgQ09VTlQoRElTVElOQ1QgYy5pZCkgQVMgdG90YWxcbiAgICAgICAgICAgICAgICBGUk9NIGNvbnZvY2F0b3JpYV9lc3RhZG9zIGNlXG4gICAgICAgICAgICAgICAgSU5ORVIgSk9JTiBjb252b2NhdG9yaWFzIGMgT04gYy5pZCA9IGNlLmNvbnZvY2F0b3JpYV9pZFxuICAgICAgICAgICAgICAgIFdIRVJFIGNlLmVzdGFkb19pZCBJTiAoJG1hcmNhZG9yZXMpXG4gICAgICAgICAgICAgICAgR1JPVVAgQlkgY2UuZXN0YWRvX2lkXCI7XG4gICAgICAgICRzdG10ID0gJHRoaXMtPmNvbm5lY3Rpb24tPnByZXBhcmUoJHNxbCk7XG4gICAgICAgICR0aGlzLT52aW5jdWxhclBhcmFtZXRyb3MoJHN0bXQsIHN0cl9yZXBlYXQoJ2knLCBjb3VudCgkaWRzKSksICRpZHMpO1xuICAgICAgICAkc3RtdC0+ZXhlY3V0ZSgpO1xuICAgICAgICAkcmVzdWx0YWRvID0gJHN0bXQtPmdldF9yZXN1bHQoKTtcbiAgICAgICAgJHRvdGFsZXMgPSBbXTtcbiAgICAgICAgd2hpbGUgKCRmaWxhID0gJHJlc3VsdGFkby0+ZmV0Y2hfYXNzb2MoKSkge1xuICAgICAgICAgICAgJHRvdGFsZXNbKGludCkkZmlsYVsnZXN0YWRvX2lkJ11dID0gKGludCkkZmlsYVsndG90YWwnXTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gJHRvdGFsZXM7XG4gICAgfVxuXG4gICAgcHVibGljIGZ1bmN0aW9uIG9idGVuZXJMaXN0YWRvKFxuIl0sIFsiICAgICAgICAkZXNDaGlodWFodWEgPSAkdGVycml0b3Jpb1NlbGVjY2lvbmFkbyAmJlxuIiwgIiAgICAgICAgJHRvdGFsZXNDb252b2NhdG9yaWFzUG9yRXN0YWRvID0gbnVsbDtcbiAgICAgICAgaWYgKCEkdGVycml0b3Jpb1NlbGVjY2lvbmFkbykge1xuICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICAkdG90YWxlc0NvbnZvY2F0b3JpYXNQb3JFc3RhZG8gPSAkbW9kZWxvLT5vYnRlbmVyVG90YWxlc1BvckVzdGFkbyhhcnJheV9jb2x1bW4oJGVzdGFkb3MsICdpZCcpKTtcbiAgICAgICAgICAgIH0gY2F0Y2ggKFRocm93YWJsZSAkZXJyb3IpIHtcbiAgICAgICAgICAgICAgICAvLyBFbCBjb250YWRvciBpbmZvcm1hdGl2byBubyBkZWJlIGltcGVkaXIgY29uc3VsdGFyIGxhcyBjb252b2NhdG9yaWFzLlxuICAgICAgICAgICAgICAgIGVycm9yX2xvZygnW2NvbnZvY2F0b3JpYXNfdG90YWxlc19lc3RhZG9dICcgLiBnZXRfY2xhc3MoJGVycm9yKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICAgICAgJGVzQ2hpaHVhaHVhID0gJHRlcnJpdG9yaW9TZWxlY2Npb25hZG8gJiZcbiJdLCBbIiAgICAgICAgICAgICAgICA8ZGl2IGNsYXNzPVwiZGF0YS1jYXJkLWhlYWRpbmdcIj5cbiAgICAgICAgICAgICAgICAgICAgPGgzPjw/PSAkdGV4dG8oJG5vbWJyZUVzdGFkbykgPz48L2gzPlxuICAgICAgICAgICAgICAgIDwvZGl2PlxuIiwgIiAgICAgICAgICAgICAgICA8ZGl2IGNsYXNzPVwiZGF0YS1jYXJkLWhlYWRpbmdcIj5cbiAgICAgICAgICAgICAgICAgICAgPGgzPjw/PSAkdGV4dG8oJG5vbWJyZUVzdGFkbykgPz48L2gzPlxuICAgICAgICAgICAgICAgICAgICA8cCBjbGFzcz1cInNtYWxsIG10LTIgbWItMCB0ZXh0LW11dGVkXCI+XG4gICAgICAgICAgICAgICAgICAgICAgICA8P3BocCBpZiAoaXNfYXJyYXkoJHRvdGFsZXNDb252b2NhdG9yaWFzUG9yRXN0YWRvID8/IG51bGwpKTogPz5cbiAgICAgICAgICAgICAgICAgICAgICAgICAgICA8P3BocCAkdG90YWxFc3RhZG8gPSAoaW50KSgkdG90YWxlc0NvbnZvY2F0b3JpYXNQb3JFc3RhZG9bKGludCkkZXN0YWRvWydpZCddXSA/PyAwKTsgPz5cbiAgICAgICAgICAgICAgICAgICAgICAgICAgICBUb3RhbDogPHN0cm9uZyBjbGFzcz1cInRleHQtZGFya1wiPjw/PSAkdG90YWxFc3RhZG8gPz4gPD89ICR0b3RhbEVzdGFkbyA9PT0gMSA/ICdjb252b2NhdG9yaWEnIDogJ2NvbnZvY2F0b3JpYXMnID8+PC9zdHJvbmc+XG4gICAgICAgICAgICAgICAgICAgICAgICA8P3BocCBlbHNlOiA/PlxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIFRvdGFsIG5vIGRpc3BvbmlibGVcbiAgICAgICAgICAgICAgICAgICAgICAgIDw/cGhwIGVuZGlmOyA/PlxuICAgICAgICAgICAgICAgICAgICA8L3A+XG4gICAgICAgICAgICAgICAgPC9kaXY+XG4iXV0='),true,512,JSON_THROW_ON_ERROR);
 [$old,$new]=$cfg[(int)$argv[1]];$s=file_get_contents($argv[2]);$crlf=substr_count($s,"\r\n");
 if($crlf&&$crlf!==substr_count($s,"\n"))throw new RuntimeException('Finales de línea mixtos.');
 $s=str_replace("\r\n","\n",$s);
 if(substr_count($s,$new)!==1){
  if(substr_count($s,$new)!==0||substr_count($s,$old)!==1)throw new RuntimeException('Bloque incompatible en archivo '.(int)$argv[1].'. No se sustituye completo.');
  $s=str_replace($old,$new,$s);
 }
 if($crlf)$s=str_replace("\n","\r\n",$s);
 if(file_put_contents($argv[3],$s)!==strlen($s))throw new RuntimeException('No se pudo preparar el archivo.');
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
IMPE_PATCH
 for i in "${!FILES[@]}";do php "$TMP/patch.php" "$i" "$TMP/original.$i" "$TMP/stage.$i" || fail 'Contador de convocatorias incompatible. SITIO INTACTO.';chmod --reference="$TMP/original.$i" "$TMP/stage.$i";done
fi
for i in "${!FILES[@]}";do php -l "$TMP/stage.$i" >/dev/null || fail 'Error de sintaxis en el servicio preparado.';done
for i in "${!FILES[@]}";do
 no_links "$LIVE/${FILES[$i]}";[[ "$(hash "$LIVE/${FILES[$i]}")" == "${BEFORE[$i]}" ]] || fail 'Un archivo cambió durante la revisión.'
 AFTER[$i]="$(hash "$TMP/stage.$i")";[[ "${AFTER[$i]}" == "${BEFORE[$i]}" ]] || CHANGED+=("$i")
done
if [[ "${#CHANGED[@]}" == 0 ]];then echo 'OK: ya aplicado o restaurado. Ningún archivo reemplazado.';exit 0;fi
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. Contador en tres archivos; búsqueda, enlaces y acciones conservados. SITIO INTACTO. Sin envíos ni SQL.';exit 0;fi
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/convocatorias-total-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
 for i in "${!FILES[@]}";do
  cp -p -- "$TMP/original.$i" "$BACKUP/original.$i";[[ "$(hash "$BACKUP/original.$i")" == "${BEFORE[$i]}" ]] || fail 'Respaldo inconsistente.'
  printf '%s\n' "${BEFORE[$i]}" > "$BACKUP/original.$i.sha256";printf '%s\n' "${AFTER[$i]}" > "$BACKUP/publicado.$i.sha256"
 done
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt"
fi
for i in "${CHANGED[@]}";do
 install="$LIVE/${FILES[$i]}.impe-convocatorias-total-$$";[[ ! -e "$install" && ! -L "$install" ]] || fail 'Existe un temporal de instalación.'
 INSTALLING="$i";cp -p -- "$TMP/stage.$i" "$install"
 for j in "${CHANGED[@]}";do
  expected="${BEFORE[$j]}";for done_i in "${WRITTEN[@]}";do [[ "$done_i" != "$j" ]] || expected="${AFTER[$j]}";done
  no_links "$LIVE/${FILES[$j]}";[[ "$(hash "$LIVE/${FILES[$j]}")" == "$expected" ]] || fail 'Un archivo cambió antes de instalar.'
 done
 WRITTEN+=("$i");mv -f -- "$install" "$LIVE/${FILES[$i]}";INSTALLING=-1
 [[ "$(hash "$LIVE/${FILES[$i]}")" == "${AFTER[$i]}" ]] || fail 'Archivo instalado distinto.'
done
echo "OK: $MODE terminado. Sólo total de convocatorias en tarjetas de Estado. Sin SQL durante publicación, cambios en registros, permisos, acciones, JavaScript o CSS."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
