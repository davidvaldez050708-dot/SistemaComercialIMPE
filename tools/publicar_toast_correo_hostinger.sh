#!/usr/bin/env bash
# Sólo texto y duración visible del toast de correo enviado. Sin SQL, red ni envíos.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='toast-correo-v2'
FILES=('public/javascript/correos_marketing.js')
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
TMP="$(mktemp -d "$SCRATCH/impe-toast-correo.XXXXXX")"
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
 if [[ "$INSTALLING" != -1 ]];then rm -f -- "$LIVE/${FILES[$INSTALLING]}.impe-toast-correo-$$";fi
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
 $cfg=json_decode(base64_decode('W1siZmlybWEgZGVsIHRvYXN0IiwgWyIgICAgICAgIGNvbnN0IG1vc3RyYXJUb2FzdCA9IGZ1bmN0aW9uIChtZW5zYWplLCBlc0Vycm9yKSB7Il0sICIgICAgICAgIGNvbnN0IG1vc3RyYXJUb2FzdCA9IGZ1bmN0aW9uIChtZW5zYWplLCBlc0Vycm9yLCBkdXJhY2lvbikgeyJdLCBbImR1cmFjacOzbiBkZWwgdG9hc3QiLCBbIiAgICAgICAgICAgICAgICBkZWxheTogZXNFcnJvciA/IDQ1MDAgOiAzMDAwIl0sICIgICAgICAgICAgICAgICAgZGVsYXk6IGR1cmFjaW9uIHx8IChlc0Vycm9yID8gNDUwMCA6IDMwMDApIl0sIFsiY29udGludWlkYWQgdHJhcyByZWNhcmdhIiwgWyIgICAgICAgIGNvbnN0IG1vc3RyYXJFcnJvciA9IGZ1bmN0aW9uIChtZW5zYWplKSB7Il0sICIgICAgICAgIC8vIE1hbnRlbmVyIGVsIGF2aXNvIGRlIGVudsOtbyBkdXJhbnRlIGxhIHJlY2FyZ2EgZXhpc3RlbnRlIGRlIGxhIGJhbmRlamEuXG4gICAgICAgIGNvbnN0IGNsYXZlVG9hc3RFbnZpbyA9ICdjb3JyZW9NYXJrZXRpbmdUb2FzdEVudmlvOicgKyBTdHJpbmcod2luZG93LklNUEVfQ1VSUkVOVF9VU0VSX0lEIHx8ICdzZXNpb24nKTtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIGNvbnN0IHBlbmRpZW50ZSA9IEpTT04ucGFyc2Uoc2Vzc2lvblN0b3JhZ2UuZ2V0SXRlbShjbGF2ZVRvYXN0RW52aW8pIHx8ICdudWxsJyk7XG4gICAgICAgICAgICBzZXNzaW9uU3RvcmFnZS5yZW1vdmVJdGVtKGNsYXZlVG9hc3RFbnZpbyk7XG4gICAgICAgICAgICBjb25zdCByZXN0YW50ZSA9IE51bWJlcihwZW5kaWVudGU/Lmhhc3RhIHx8IDApIC0gRGF0ZS5ub3coKTtcbiAgICAgICAgICAgIGlmICh0eXBlb2YgcGVuZGllbnRlPy5tZW5zYWplID09PSAnc3RyaW5nJyAmJiByZXN0YW50ZSA+IDAgJiYgcmVzdGFudGUgPD0gMzUwMCkge1xuICAgICAgICAgICAgICAgIG1vc3RyYXJUb2FzdChwZW5kaWVudGUubWVuc2FqZSwgZmFsc2UsIHJlc3RhbnRlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSBjYXRjaCAoXykgeyAvKiBFbCBhbG1hY2VuYW1pZW50byBvcGNpb25hbCBubyBpbXBpZGUgcmVkYWN0YXIgbmkgZW52aWFyLiAqLyB9XG5cbiAgICAgICAgY29uc3QgbW9zdHJhckVycm9yID0gZnVuY3Rpb24gKG1lbnNhamUpIHsiXSwgWyJ0ZXh0byBkZWwgZW52w61vIiwgWyIgICAgICAgICAgICAgICAgbW9kYWwuaGlkZSgpO1xuICAgICAgICAgICAgICAgIG1vc3RyYXJUb2FzdChcbiAgICAgICAgICAgICAgICAgICAganNvbi5tZW5zYWplIHx8ICdDb3JyZW8gZW52aWFkbyBjb3JyZWN0YW1lbnRlLicsXG4gICAgICAgICAgICAgICAgICAgIGZhbHNlXG4gICAgICAgICAgICAgICAgKTtcbiIsICIgICAgICAgICAgICAgICAgbW9kYWwuaGlkZSgpO1xuICAgICAgICAgICAgICAgIG1vc3RyYXJUb2FzdChcbiAgICAgICAgICAgICAgICAgICAgKGpzb24ubWVuc2FqZSB8fCAnQ29ycmVvIGFjZXB0YWRvIHBvciBlbCBwcm92ZWVkb3IuJykgK1xuICAgICAgICAgICAgICAgICAgICAgICAgKGpzb24uaWRlbnRpZGFkX2VudmlvPy5hdmlzbyA/ICcgJyArIGpzb24uaWRlbnRpZGFkX2VudmlvLmF2aXNvIDogJycpLFxuICAgICAgICAgICAgICAgICAgICBmYWxzZVxuICAgICAgICAgICAgICAgICk7XG4iLCAiICAgICAgICAgICAgICAgIG1vZGFsLmhpZGUoKTtcbiAgICAgICAgICAgICAgICBjb25zdCBidXpvblJlc3B1ZXN0YXMgPSBTdHJpbmcoanNvbi5jdWVudGFfc2FsaWRhIHx8IGNvcnJlb09yaWdlbikudHJpbSgpO1xuICAgICAgICAgICAgICAgIGNvbnN0IG1lbnNhamVFbnZpYWRvID0gJ0NvcnJlbyBlbnZpYWRvLiBMYXMgcmVzcHVlc3RhcyBzZSByZWNpYmlyw6FuIGVuIGVsIGJ1esOzbiBkZSAnICtcbiAgICAgICAgICAgICAgICAgICAgYnV6b25SZXNwdWVzdGFzICsgJy4nO1xuICAgICAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgICAgIHNlc3Npb25TdG9yYWdlLnNldEl0ZW0oY2xhdmVUb2FzdEVudmlvLCBKU09OLnN0cmluZ2lmeSh7XG4gICAgICAgICAgICAgICAgICAgICAgICBtZW5zYWplOiBtZW5zYWplRW52aWFkbyxcbiAgICAgICAgICAgICAgICAgICAgICAgIGhhc3RhOiBEYXRlLm5vdygpICsgMzUwMFxuICAgICAgICAgICAgICAgICAgICB9KSk7XG4gICAgICAgICAgICAgICAgfSBjYXRjaCAoXykgeyAvKiBFbCB0b2FzdCBhY3R1YWwgcGVybWFuZWNlIGRpc3BvbmlibGUgc2luIGFsbWFjZW5hbWllbnRvLiAqLyB9XG4gICAgICAgICAgICAgICAgbW9zdHJhclRvYXN0KFxuICAgICAgICAgICAgICAgICAgICBtZW5zYWplRW52aWFkbyxcbiAgICAgICAgICAgICAgICAgICAgZmFsc2UsXG4gICAgICAgICAgICAgICAgICAgIDM1MDBcbiAgICAgICAgICAgICAgICApO1xuIl0sICIgICAgICAgICAgICAgICAgbW9kYWwuaGlkZSgpO1xuICAgICAgICAgICAgICAgIGNvbnN0IGJ1em9uUmVzcHVlc3RhcyA9IFN0cmluZyhqc29uLmlkZW50aWRhZF9lbnZpbz8ucmVzcHVlc3Rhc19hIHx8IGpzb24uY3VlbnRhX3NhbGlkYSB8fCBjb3JyZW9PcmlnZW4pLnRyaW0oKTtcbiAgICAgICAgICAgICAgICBjb25zdCBtZW5zYWplRW52aWFkbyA9ICdDb3JyZW8gZW52aWFkby4gTGFzIHJlc3B1ZXN0YXMgc2UgcmVjaWJpcsOhbiBlbiBlbCBidXrDs24gZGUgJyArXG4gICAgICAgICAgICAgICAgICAgIGJ1em9uUmVzcHVlc3RhcyArICcuJztcbiAgICAgICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgICAgICBzZXNzaW9uU3RvcmFnZS5zZXRJdGVtKGNsYXZlVG9hc3RFbnZpbywgSlNPTi5zdHJpbmdpZnkoe1xuICAgICAgICAgICAgICAgICAgICAgICAgbWVuc2FqZTogbWVuc2FqZUVudmlhZG8sXG4gICAgICAgICAgICAgICAgICAgICAgICBoYXN0YTogRGF0ZS5ub3coKSArIDM1MDBcbiAgICAgICAgICAgICAgICAgICAgfSkpO1xuICAgICAgICAgICAgICAgIH0gY2F0Y2ggKF8pIHsgLyogRWwgdG9hc3QgYWN0dWFsIHBlcm1hbmVjZSBkaXNwb25pYmxlIHNpbiBhbG1hY2VuYW1pZW50by4gKi8gfVxuICAgICAgICAgICAgICAgIG1vc3RyYXJUb2FzdChcbiAgICAgICAgICAgICAgICAgICAgbWVuc2FqZUVudmlhZG8sXG4gICAgICAgICAgICAgICAgICAgIGZhbHNlLFxuICAgICAgICAgICAgICAgICAgICAzNTAwXG4gICAgICAgICAgICAgICAgKTtcbiJdXQ=='),true,512,JSON_THROW_ON_ERROR);
 $s=file_get_contents($argv[2]);$crlf=substr_count($s,"\r\n");
 if($crlf&&$crlf!==substr_count($s,"\n"))throw new RuntimeException('Finales de línea mixtos.');
 $s=str_replace("\r\n","\n",$s);
 foreach($cfg as [$name,$variants,$target]){
  if(substr_count($s,$target)===1)continue;
  $matches=[];
  foreach($variants as $old){if(substr_count($s,$old)===1)$matches[]=$old;}
  if(count($matches)!==1||substr_count($s,$target)!==0)throw new RuntimeException('Bloque incompatible: '.$name.'. No se sobrescribe el archivo completo.');
  $s=str_replace($matches[0],$target,$s);
 }
 if($crlf)$s=str_replace("\n","\r\n",$s);
 if(file_put_contents($argv[3],$s)!==strlen($s))throw new RuntimeException('No se pudo preparar el archivo.');
} catch(Throwable $error) {fwrite(STDERR,$error->getMessage()."\n");exit(1);}
IMPE_PATCH
 for i in "${!FILES[@]}";do php "$TMP/patch.php" "$i" "$TMP/original.$i" "$TMP/stage.$i" || fail 'Toast incompatible. SITIO INTACTO.';chmod --reference="$TMP/original.$i" "$TMP/stage.$i";done
fi
for i in "${!FILES[@]}";do
 no_links "$LIVE/${FILES[$i]}";[[ "$(hash "$LIVE/${FILES[$i]}")" == "${BEFORE[$i]}" ]] || fail 'Un archivo cambió durante la revisión.'
 AFTER[$i]="$(hash "$TMP/stage.$i")";[[ "${AFTER[$i]}" == "${BEFORE[$i]}" ]] || CHANGED+=("$i")
done
if [[ "${#CHANGED[@]}" == 0 ]];then echo 'OK: ya aplicado o restaurado. Ningún archivo reemplazado.';exit 0;fi
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. Sólo el toast de correo enviado en un archivo JavaScript. SITIO INTACTO. Sin envíos ni SQL.';exit 0;fi
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/toast-correo-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
 for i in "${!FILES[@]}";do
  cp -p -- "$TMP/original.$i" "$BACKUP/original.$i";[[ "$(hash "$BACKUP/original.$i")" == "${BEFORE[$i]}" ]] || fail 'Respaldo inconsistente.'
  printf '%s\n' "${BEFORE[$i]}" > "$BACKUP/original.$i.sha256";printf '%s\n' "${AFTER[$i]}" > "$BACKUP/publicado.$i.sha256"
 done
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt"
fi
for i in "${CHANGED[@]}";do
 install="$LIVE/${FILES[$i]}.impe-toast-correo-$$";[[ ! -e "$install" && ! -L "$install" ]] || fail 'Existe un temporal de instalación.'
 INSTALLING="$i";cp -p -- "$TMP/stage.$i" "$install"
 for j in "${CHANGED[@]}";do
  expected="${BEFORE[$j]}";for done_i in "${WRITTEN[@]}";do [[ "$done_i" != "$j" ]] || expected="${AFTER[$j]}";done
  no_links "$LIVE/${FILES[$j]}";[[ "$(hash "$LIVE/${FILES[$j]}")" == "$expected" ]] || fail 'Un archivo cambió antes de instalar.'
 done
 WRITTEN+=("$i");mv -f -- "$install" "$LIVE/${FILES[$i]}";INSTALLING=-1
 [[ "$(hash "$LIVE/${FILES[$i]}")" == "${AFTER[$i]}" ]] || fail 'Archivo instalado distinto.'
done
echo "OK: $MODE terminado. Sólo texto y presentación temporal del toast. Recargar el CRM. Sin SQL ni cambios en envío, remitentes, destinatarios, adjuntos, permisos o credenciales."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
