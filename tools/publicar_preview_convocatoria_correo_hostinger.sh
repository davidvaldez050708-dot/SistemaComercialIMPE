#!/usr/bin/env bash
# Sólo presentación de la imagen en Redactar correo. Sin red, BD ni mensajes.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='correo-preview-v1'
FILES=('app/views/correos/index.php' 'public/css/correos_marketing.css')
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
TMP="$(mktemp -d "$SCRATCH/impe-correo-preview.XXXXXX")"
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
 if [[ "$INSTALLING" != -1 ]];then rm -f -- "$LIVE/${FILES[$INSTALLING]}.impe-preview-$$";fi
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 rm -f -- "$TMP/original.0" "$TMP/original.1" "$TMP/stage.0" "$TMP/stage.1" "$TMP/patch.php"
 rmdir -- "$TMP";exit "$code"
}
trap finish EXIT;trap 'exit 130' INT;trap 'exit 143' TERM
if [[ "$MODE" != --check ]];then no_links "$LOCK";mkdir "$LOCK" 2>/dev/null || fail 'Otra publicación está en curso.';LOCKED=1;fi
for i in 0 1;do BEFORE[$i]="$(hash "$LIVE/${FILES[$i]}")";cp -p -- "$LIVE/${FILES[$i]}" "$TMP/original.$i";done
if [[ "$MODE" == --restore ]];then
 no_links "$2";RESTORE="$(realpath -e "$2")"
 [[ "$(dirname "$RESTORE")" == "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
 no_links "$RESTORE/version.txt";[[ -f "$RESTORE/version.txt" && "$(<"$RESTORE/version.txt")" == "$VERSION" ]] || fail 'Respaldo de otra entrega.'
 for i in 0 1;do
  for file in "original.$i" "original.$i.sha256" "publicado.$i.sha256";do no_links "$RESTORE/$file";[[ -f "$RESTORE/$file" ]] || fail 'Respaldo incompleto.';done
  original="$(<"$RESTORE/original.$i.sha256")";published="$(<"$RESTORE/publicado.$i.sha256")"
  [[ "$original" =~ ^[a-f0-9]{64}$ && "$published" =~ ^[a-f0-9]{64}$ && "$(hash "$RESTORE/original.$i")" == "$original" ]] || fail 'Respaldo alterado.'
  [[ "${BEFORE[$i]}" == "$published" || "${BEFORE[$i]}" == "$original" ]] || fail 'Archivo editado después de publicar. No se sobrescribe.'
  cp -p -- "$RESTORE/original.$i" "$TMP/stage.$i"
 done
else
 cat > "$TMP/patch.php" <<'IMPE_PATCH'
<?php
$c=json_decode(base64_decode('eyJvbGQiOiAiICAgICAgICAgICAgICAgICAgICA8ZmlndXJlIGNsYXNzPVwibWItMyBkLW5vbmVcIiBkYXRhLWNvbnZvY2F0b3JpYS1wcmV2aWV3PlxuICAgICAgICAgICAgICAgICAgICAgICAgPGltZyBjbGFzcz1cImltZy1mbHVpZFwiIHN0eWxlPVwiZGlzcGxheTpibG9jazttYXgtd2lkdGg6MTAwJTtoZWlnaHQ6YXV0b1wiIGFsdD1cIkltYWdlbiBkZSBsYSBjb252b2NhdG9yaWEgc2VsZWNjaW9uYWRhXCIgZGF0YS1jb252b2NhdG9yaWEtaW1hZ2VuPlxuICAgICAgICAgICAgICAgICAgICAgICAgPGZpZ2NhcHRpb24gY2xhc3M9XCJmb3JtLXRleHRcIiBkYXRhLWNvbnZvY2F0b3JpYS1jYXB0aW9uPjwvZmlnY2FwdGlvbj5cbiAgICAgICAgICAgICAgICAgICAgPC9maWd1cmU+XG5cbiIsICJuZXciOiAiICAgICAgICAgICAgICAgICAgICAgICAgPGZpZ3VyZSBjbGFzcz1cImNvcnJlby1tYXJrZXRpbmctY29udm9jYXRvcmlhLXByZXZpZXcgZC1ub25lXCIgZGF0YS1jb252b2NhdG9yaWEtcHJldmlldz5cbiAgICAgICAgICAgICAgICAgICAgICAgICAgICA8aW1nIGNsYXNzPVwiaW1nLWZsdWlkXCIgYWx0PVwiSW1hZ2VuIGRlIGxhIGNvbnZvY2F0b3JpYSBzZWxlY2Npb25hZGFcIiBkYXRhLWNvbnZvY2F0b3JpYS1pbWFnZW4+XG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgPGZpZ2NhcHRpb24gY2xhc3M9XCJmb3JtLXRleHRcIiBkYXRhLWNvbnZvY2F0b3JpYS1jYXB0aW9uPjwvZmlnY2FwdGlvbj5cbiAgICAgICAgICAgICAgICAgICAgICAgIDwvZmlndXJlPlxuIiwgImFuY2hvciI6ICIgICAgICAgICAgICAgICAgICAgICAgICA8aW5wdXQgdHlwZT1cImhpZGRlblwiIG5hbWU9XCJjb252b2NhdG9yaWFfaW1hZ2VuX3NoYVwiIGRhdGEtY29udm9jYXRvcmlhLWltYWdlbi1zaGE+XG4iLCAiY3NzIjogIi8qIFZpc3RhIHByZXZpYSBkZSBjb252b2NhdG9yaWE6IHPDs2xvIHByZXNlbnRhY2nDs24gZGVudHJvIGRlIFJlZGFjdGFyIGNvcnJlby4gKi9cbiNtb2RhbENvcnJlb01hcmtldGluZyAuY29ycmVvLW1hcmtldGluZy1jb252b2NhdG9yaWEtcHJldmlldyB7XG4gICAgbWFyZ2luOiAxMHB4IDAgMDtcbn1cblxuI21vZGFsQ29ycmVvTWFya2V0aW5nIC5jb3JyZW8tbWFya2V0aW5nLWNvbnZvY2F0b3JpYS1wcmV2aWV3IGltZyB7XG4gICAgZGlzcGxheTogYmxvY2s7XG4gICAgd2lkdGg6IDMwMHB4O1xuICAgIG1heC13aWR0aDogMTAwJTtcbiAgICBoZWlnaHQ6IGF1dG87XG4gICAgb2JqZWN0LWZpdDogY29udGFpbjtcbn1cblxuIn0='),true,512,JSON_THROW_ON_ERROR);
$i=(int)$argv[1];$s=file_get_contents($argv[2]);
$crlf=substr_count($s,"\r\n");if($crlf&&$crlf!==substr_count($s,"\n"))throw new RuntimeException('Finales de línea mixtos.');
$s=str_replace("\r\n","\n",$s);
if($i===0){
 $old=substr_count($s,$c['old']);$new=substr_count($s,$c['new']);
 if(substr_count($s,$c['anchor'])!==1)throw new RuntimeException('Ancla de imagen ausente o ambigua.');
 if($old===1&&$new===0){
  // Los dos fragmentos deben ser HTML activo de la vista, no cadenas PHP.
  $positions=[strpos($s,$c['old']),strpos($s,$c['anchor'])];$offset=0;$found=0;
  foreach(token_get_all($s) as $t){$text=is_array($t)?$t[1]:$t;foreach($positions as $p){if($p>=$offset&&$p<$offset+strlen($text)&&is_array($t)&&$t[0]===T_INLINE_HTML)$found++;}$offset+=strlen($text);}
  if($found!==2)throw new RuntimeException('Los fragmentos no son HTML activo.');
  $s=str_replace($c['old'],'',$s);$s=str_replace($c['anchor'],$c['anchor'].$c['new'],$s);
 }elseif(!($old===0&&$new===1&&substr_count($s,$c['anchor'].$c['new'])===1)){
  throw new RuntimeException('Vista previa distinta o ambigua. No se sustituye la vista completa.');
 }
}else{
 $count=substr_count($s,$c['css']);
 if($count===0){
  if(strpos($s,'.correo-marketing-convocatoria-preview')!==false)throw new RuntimeException('Estilos de vista previa personalizados: revisar.');
  $anchor='/* Redactar correo: escala';
  if(substr_count($s,$anchor)!==1)throw new RuntimeException('Ancla de estilos ausente o ambigua.');
  $s=str_replace($anchor,$c['css'].$anchor,$s);
 }elseif($count!==1)throw new RuntimeException('Estilos duplicados.');
}
if($crlf)$s=str_replace("\n","\r\n",$s);
if(file_put_contents($argv[3],$s)!==strlen($s))throw new RuntimeException('No se pudo preparar el archivo.');
IMPE_PATCH
 for i in 0 1;do php "$TMP/patch.php" "$i" "$TMP/original.$i" "$TMP/stage.$i" || fail 'Presentación incompatible. SITIO INTACTO.';chmod --reference="$TMP/original.$i" "$TMP/stage.$i";done
fi
php -l "$TMP/stage.0" >/dev/null || fail 'Error de sintaxis en la vista preparada.'
for i in 0 1;do
 no_links "$LIVE/${FILES[$i]}";[[ "$(hash "$LIVE/${FILES[$i]}")" == "${BEFORE[$i]}" ]] || fail 'Un archivo cambió durante la revisión.'
 AFTER[$i]="$(hash "$TMP/stage.$i")";[[ "${AFTER[$i]}" == "${BEFORE[$i]}" ]] || CHANGED+=("$i")
done
if [[ "${#CHANGED[@]}" == 0 ]];then echo 'OK: ya aplicado o restaurado. Ningún archivo reemplazado.';exit 0;fi
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. Sólo vista y CSS del modal. SITIO INTACTO. Sin envíos ni SQL.';exit 0;fi
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/correo-preview-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
 for i in 0 1;do
  cp -p -- "$TMP/original.$i" "$BACKUP/original.$i";[[ "$(hash "$BACKUP/original.$i")" == "${BEFORE[$i]}" ]] || fail 'Respaldo inconsistente.'
  printf '%s\n' "${BEFORE[$i]}" > "$BACKUP/original.$i.sha256";printf '%s\n' "${AFTER[$i]}" > "$BACKUP/publicado.$i.sha256"
 done
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt"
fi
for i in "${CHANGED[@]}";do
 install="$LIVE/${FILES[$i]}.impe-preview-$$";[[ ! -e "$install" && ! -L "$install" ]] || fail 'Existe un temporal de instalación.'
 INSTALLING="$i";cp -p -- "$TMP/stage.$i" "$install"
 for j in "${CHANGED[@]}";do
  expected="${BEFORE[$j]}";for done_i in "${WRITTEN[@]}";do [[ "$done_i" != "$j" ]] || expected="${AFTER[$j]}";done
  no_links "$LIVE/${FILES[$j]}";[[ "$(hash "$LIVE/${FILES[$j]}")" == "$expected" ]] || fail 'Un archivo cambió antes de instalar.'
 done
 WRITTEN+=("$i");mv -f -- "$install" "$LIVE/${FILES[$i]}";INSTALLING=-1
 [[ "$(hash "$LIVE/${FILES[$i]}")" == "${AFTER[$i]}" ]] || fail 'Archivo instalado distinto.'
done
echo "OK: $MODE terminado. Sólo presentación de la vista previa. Sin cambios en JavaScript, servicios, imágenes originales, BD ni credenciales."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
