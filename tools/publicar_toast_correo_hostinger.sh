#!/usr/bin/env bash
# Sólo texto y duración visible del toast de correo enviado. Sin SQL, red ni envíos.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='toast-correo-v1'
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
$pairs=json_decode(base64_decode('W1siICAgICAgICAgICAgLy8gTG9zIGF2aXNvcyBkZWwgZm9ybXVsYXJpbyBzZSBtdWVzdHJhbiBtZWRpYW50ZSB0b2FzdC5cbiAgICAgICAgfTtcblxuICAgICAgICBjb25zdCBtb3N0cmFyVG9hc3QgPSBmdW5jdGlvbiAobWVuc2FqZSwgZXNFcnJvcikge1xuICAgICAgICAgICAgbGV0IGNvbnRlbmVkb3IgPSBkb2N1bWVudC5xdWVyeVNlbGVjdG9yKCcudG9hc3QtY29udGFpbmVyJyk7XG5cbiAgICAgICAgICAgIGlmICghY29udGVuZWRvcikge1xuIiwgIiAgICAgICAgICAgIC8vIExvcyBhdmlzb3MgZGVsIGZvcm11bGFyaW8gc2UgbXVlc3RyYW4gbWVkaWFudGUgdG9hc3QuXG4gICAgICAgIH07XG5cbiAgICAgICAgY29uc3QgbW9zdHJhclRvYXN0ID0gZnVuY3Rpb24gKG1lbnNhamUsIGVzRXJyb3IsIGR1cmFjaW9uKSB7XG4gICAgICAgICAgICBsZXQgY29udGVuZWRvciA9IGRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJy50b2FzdC1jb250YWluZXInKTtcblxuICAgICAgICAgICAgaWYgKCFjb250ZW5lZG9yKSB7XG4iXSwgWyJcbiAgICAgICAgICAgIGJvb3RzdHJhcC5Ub2FzdC5nZXRPckNyZWF0ZUluc3RhbmNlKHRvYXN0LCB7XG4gICAgICAgICAgICAgICAgYXV0b2hpZGU6IHRydWUsXG4gICAgICAgICAgICAgICAgZGVsYXk6IGVzRXJyb3IgPyA0NTAwIDogMzAwMFxuICAgICAgICAgICAgfSkuc2hvdygpO1xuICAgICAgICB9O1xuXG4gICAgICAgIGNvbnN0IG1vc3RyYXJFcnJvciA9IGZ1bmN0aW9uIChtZW5zYWplKSB7XG4gICAgICAgICAgICBtb3N0cmFyVG9hc3QoXG4iLCAiXG4gICAgICAgICAgICBib290c3RyYXAuVG9hc3QuZ2V0T3JDcmVhdGVJbnN0YW5jZSh0b2FzdCwge1xuICAgICAgICAgICAgICAgIGF1dG9oaWRlOiB0cnVlLFxuICAgICAgICAgICAgICAgIGRlbGF5OiBkdXJhY2lvbiB8fCAoZXNFcnJvciA/IDQ1MDAgOiAzMDAwKVxuICAgICAgICAgICAgfSkuc2hvdygpO1xuICAgICAgICB9O1xuXG4gICAgICAgIC8vIE1hbnRlbmVyIGVsIGF2aXNvIGRlIGVudsOtbyBkdXJhbnRlIGxhIHJlY2FyZ2EgZXhpc3RlbnRlIGRlIGxhIGJhbmRlamEuXG4gICAgICAgIGNvbnN0IGNsYXZlVG9hc3RFbnZpbyA9ICdjb3JyZW9NYXJrZXRpbmdUb2FzdEVudmlvOicgKyBTdHJpbmcod2luZG93LklNUEVfQ1VSUkVOVF9VU0VSX0lEIHx8ICdzZXNpb24nKTtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIGNvbnN0IHBlbmRpZW50ZSA9IEpTT04ucGFyc2Uoc2Vzc2lvblN0b3JhZ2UuZ2V0SXRlbShjbGF2ZVRvYXN0RW52aW8pIHx8ICdudWxsJyk7XG4gICAgICAgICAgICBzZXNzaW9uU3RvcmFnZS5yZW1vdmVJdGVtKGNsYXZlVG9hc3RFbnZpbyk7XG4gICAgICAgICAgICBjb25zdCByZXN0YW50ZSA9IE51bWJlcihwZW5kaWVudGU/Lmhhc3RhIHx8IDApIC0gRGF0ZS5ub3coKTtcbiAgICAgICAgICAgIGlmICh0eXBlb2YgcGVuZGllbnRlPy5tZW5zYWplID09PSAnc3RyaW5nJyAmJiByZXN0YW50ZSA+IDAgJiYgcmVzdGFudGUgPD0gMzUwMCkge1xuICAgICAgICAgICAgICAgIG1vc3RyYXJUb2FzdChwZW5kaWVudGUubWVuc2FqZSwgZmFsc2UsIHJlc3RhbnRlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSBjYXRjaCAoXykgeyAvKiBFbCBhbG1hY2VuYW1pZW50byBvcGNpb25hbCBubyBpbXBpZGUgcmVkYWN0YXIgbmkgZW52aWFyLiAqLyB9XG5cbiAgICAgICAgY29uc3QgbW9zdHJhckVycm9yID0gZnVuY3Rpb24gKG1lbnNhamUpIHtcbiAgICAgICAgICAgIG1vc3RyYXJUb2FzdChcbiJdLCBbIiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICBtb2RhbC5oaWRlKCk7XG4gICAgICAgICAgICAgICAgbW9zdHJhclRvYXN0KFxuICAgICAgICAgICAgICAgICAgICBqc29uLm1lbnNhamUgfHwgJ0NvcnJlbyBlbnZpYWRvIGNvcnJlY3RhbWVudGUuJyxcbiAgICAgICAgICAgICAgICAgICAgZmFsc2VcbiAgICAgICAgICAgICAgICApO1xuICAgICAgICAgICAgICAgIHNlc3Npb25TdG9yYWdlLnNldEl0ZW0oXG4gICAgICAgICAgICAgICAgICAgICdjb3JyZW9NYXJrZXRpbmdUYWInLFxuIiwgIiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICBtb2RhbC5oaWRlKCk7XG4gICAgICAgICAgICAgICAgY29uc3QgYnV6b25SZXNwdWVzdGFzID0gU3RyaW5nKGpzb24uY3VlbnRhX3NhbGlkYSB8fCBjb3JyZW9PcmlnZW4pLnRyaW0oKTtcbiAgICAgICAgICAgICAgICBjb25zdCBtZW5zYWplRW52aWFkbyA9ICdDb3JyZW8gZW52aWFkby4gTGFzIHJlc3B1ZXN0YXMgc2UgcmVjaWJpcsOhbiBlbiBlbCBidXrDs24gZGUgJyArXG4gICAgICAgICAgICAgICAgICAgIGJ1em9uUmVzcHVlc3RhcyArICcuJztcbiAgICAgICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgICAgICBzZXNzaW9uU3RvcmFnZS5zZXRJdGVtKGNsYXZlVG9hc3RFbnZpbywgSlNPTi5zdHJpbmdpZnkoe1xuICAgICAgICAgICAgICAgICAgICAgICAgbWVuc2FqZTogbWVuc2FqZUVudmlhZG8sXG4gICAgICAgICAgICAgICAgICAgICAgICBoYXN0YTogRGF0ZS5ub3coKSArIDM1MDBcbiAgICAgICAgICAgICAgICAgICAgfSkpO1xuICAgICAgICAgICAgICAgIH0gY2F0Y2ggKF8pIHsgLyogRWwgdG9hc3QgYWN0dWFsIHBlcm1hbmVjZSBkaXNwb25pYmxlIHNpbiBhbG1hY2VuYW1pZW50by4gKi8gfVxuICAgICAgICAgICAgICAgIG1vc3RyYXJUb2FzdChcbiAgICAgICAgICAgICAgICAgICAgbWVuc2FqZUVudmlhZG8sXG4gICAgICAgICAgICAgICAgICAgIGZhbHNlLFxuICAgICAgICAgICAgICAgICAgICAzNTAwXG4gICAgICAgICAgICAgICAgKTtcbiAgICAgICAgICAgICAgICBzZXNzaW9uU3RvcmFnZS5zZXRJdGVtKFxuICAgICAgICAgICAgICAgICAgICAnY29ycmVvTWFya2V0aW5nVGFiJyxcbiJdXQ=='),true,512,JSON_THROW_ON_ERROR);
$s=file_get_contents($argv[2]);$crlf=substr_count($s,"\r\n");
if($crlf&&$crlf!==substr_count($s,"\n"))throw new RuntimeException('Finales de línea mixtos.');
$s=str_replace("\r\n","\n",$s);
foreach($pairs as [$old,$new]){
 if(substr_count($s,$new)===1)continue;
 if(substr_count($s,$new)!==0||substr_count($s,$old)!==1)throw new RuntimeException('Toast personalizado o incompatible. No se sustituye el archivo completo.');
 $s=str_replace($old,$new,$s);
}
if($crlf)$s=str_replace("\n","\r\n",$s);
if(file_put_contents($argv[3],$s)!==strlen($s))throw new RuntimeException('No se pudo preparar el archivo.');
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
