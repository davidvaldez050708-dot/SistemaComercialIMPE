#!/usr/bin/env bash
# Preparación selectiva de la entrega revisada: no ejecuta SQL ni el CRM.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
TARGET='b41f18bc209e0e5a752a6bea4ea1db813eb266a9'
REVIEWED='2abc206bf9c999bdafcdccb1b0abdf978e3a39c0'
REPO='https://github.com/davidvaldez050708-dot/SistemaComercialIMPE.git'
RESOURCE='tools/ventas_conciliacion_actual'
SOURCE="${IMPE_VENTAS_CONCILIACION_ACTUAL_SOURCE:-$HOME/crm-publicaciones/ventas-conciliacion-actual-actual}"
LIVE="${IMPE_VENTAS_CONCILIACION_ACTUAL_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_VENTAS_CONCILIACION_ACTUAL_BACKUPS:-$HOME/respaldos-crm}"
fail(){ echo "ERROR: $*" >&2; exit 1; }
digest(){ sha256sum "$1" | cut -d' ' -f1; }
no_links(){ local part="$1";while [[ "$part" != / && "$part" != . ]];do
 [[ ! -L "$part" ]] || fail "Enlace no permitido: $1"
 case "$part" in */*) part="${part%/*}";[[ -n "$part" ]] || part=/;; *) part=.;;esac
done; }
for tool in git php sha256sum cut mktemp realpath cmp cp mv mkdir rm rmdir dirname cat date chmod grep; do command -v "$tool" >/dev/null || fail "Falta $tool"; done
no_links "$LIVE"
[[ -d "$LIVE" && -f "$LIVE/index.php" ]] || fail 'No se encontró la raíz del CRM.'
LIVE="$(cd "$LIVE" && pwd -P)"
for private in "$SOURCE" "$BACKUPS"; do
 no_links "$private"; absolute="$(realpath -m "$private")"
 [[ "$absolute" != / && "$absolute/" != "$LIVE/"* && "$LIVE/" != "$absolute/"* ]] || fail 'Preparación y respaldos deben estar fuera del sitio y sus antecesores.'
done
SOURCE="$(realpath -m "$SOURCE")"; BACKUPS="$(realpath -m "$BACKUPS")"
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0
unset GIT_CONFIG GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT
git_public(){ command git -c remote.origin.url="$REPO" -c http.sslVerify=true "$@"; }
[[ ! -e "$SOURCE" || -d "$SOURCE/.git" ]] || fail 'La preparación existe sin repositorio.'
if [[ ! -e "$SOURCE" ]]; then mkdir -p "$(dirname "$SOURCE")"; git_public clone --quiet --no-checkout --filter=blob:none "$REPO" "$SOURCE"; fi
if git_public -C "$SOURCE" config --name-only --get-regexp '^url\..*\.insteadof$' >/dev/null; then fail 'La preparación tiene reglas locales de URL; usa otra carpeta privada.'; fi
if ! git_public -C "$SOURCE" cat-file -e "$TARGET^{commit}" 2>/dev/null; then git_public -C "$SOURCE" fetch --quiet "$REPO" "$TARGET"; fi
if ! git_public -C "$SOURCE" cat-file -e "$REVIEWED^{commit}" 2>/dev/null; then git_public -C "$SOURCE" fetch --quiet "$REPO" "$REVIEWED"; fi
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)"; TMP="$(mktemp -d "$SCRATCH/impe-ventas-conciliacion-actual.XXXXXX")"; touch "$TMP/.impe-owned"
mkdir "$TMP/stage" "$TMP/original"
LOCK="$(dirname "$LIVE")/.impe-atencion-deploy.lock"; LOCKED=0; WRITING=0; BACKUP=''
declare -a FILES=() CHANGED=() WRITTEN=()
declare -A BEFORE=() AFTER=()
finish(){
 local code=$? file
 trap - EXIT INT TERM
 if [[ "$WRITING" == 1 && "$code" != 0 ]]; then
  for file in "${WRITTEN[@]}"; do
   if [[ "${BEFORE[$file]}" == MISSING ]]; then rm -f -- "$LIVE/$file";
   else cp -p -- "$TMP/original/$file" "$LIVE/$file" || echo "REVISAR RESTAURACIÓN: $file; $BACKUP" >&2; fi
  done
  echo 'Publicación interrumpida: se restauraron los archivos alcanzados; revisa el respaldo.' >&2
 fi
 for file in "${CHANGED[@]}"; do rm -f -- "$LIVE/$file.impe-new-$$" || true; done
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK" || true
 if [[ "$TMP" == "$SCRATCH"/impe-ventas-conciliacion-actual.* && -f "$TMP/.impe-owned" ]]; then rm -rf -- "$TMP"; fi
 exit "$code"
}
trap finish EXIT; trap 'exit 130' INT; trap 'exit 143' TERM
git_public -C "$SOURCE" show "$REVIEWED:$RESOURCE/manifest.json" > "$TMP/manifest.json"
php -r '
 $m=json_decode(file_get_contents($argv[1]),true);if(($m["version"]??0)!==1)exit(1);$seen=[];
 foreach($m["files"]??[] as $f){$p=$f["path"]??"";
 if(($p!=="app/views/layout/dashboard_head.php"&&!preg_match("#^(app/(controllers|models|services|views/telefonia)/|public/(javascript|css)/|prueba_telefonia/api/|tools/)[a-zA-Z0-9_./-]+\\.(php|js|css)$#D",$p))||str_contains($p,"..")||isset($seen[$p]))exit(1);
 $seen[$p]=true;echo $p."\n";}if(count($seen)<1||count($seen)>40)exit(1);
' "$TMP/manifest.json" > "$TMP/files" || fail 'Manifiesto inválido.'
mapfile -t FILES < "$TMP/files"
guard(){ no_links "$LIVE/$1"; [[ ! -e "$LIVE/$1" || -f "$LIVE/$1" ]] || fail "No es archivo: $1"; }
unchanged(){ local f="$1";guard "$f";if [[ "${BEFORE[$f]}" == MISSING ]]; then [[ ! -e "$LIVE/$f" ]] || fail "Apareció durante la revisión: $f";else [[ -f "$LIVE/$f" && "$(digest "$LIVE/$f")" == "${BEFORE[$f]}" ]] || fail "Cambió durante la revisión: $f";fi; }
if [[ "$MODE" != --check ]]; then mkdir "$LOCK" 2>/dev/null || fail "Hay otra publicación o bloqueo pendiente: $LOCK";LOCKED=1;fi
for file in "${FILES[@]}"; do
 guard "$file";mkdir -p "$TMP/original/$(dirname "$file")" "$TMP/stage/$(dirname "$file")"
 if [[ -f "$LIVE/$file" ]]; then BEFORE["$file"]="$(digest "$LIVE/$file")";cp -p -- "$LIVE/$file" "$TMP/original/$file";else BEFORE["$file"]=MISSING;fi
done
if [[ "$MODE" == --restore ]]; then
 no_links "$2";RESTORE="$(realpath -m "$2")"
 [[ "$RESTORE/" == "$BACKUPS/"* && "$RESTORE" != "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
 no_links "$RESTORE/version.txt";no_links "$RESTORE/archivos.tsv"
 [[ -f "$RESTORE/version.txt" && "$(cat "$RESTORE/version.txt")" == "$TARGET" && -f "$RESTORE/archivos.tsv" ]] || fail 'Respaldo de otra entrega o incompleto.'
 declare -A SEEN=()
 while IFS=$'\t' read -r file old installed; do
  [[ -n "$file" && -z "${SEEN[$file]:-}" && -n "${BEFORE[$file]:-}" && "$installed" =~ ^[a-f0-9]{64}$ && ( "$old" == MISSING || "$old" =~ ^[a-f0-9]{64}$ ) ]] || fail 'Registro de respaldo inválido.'
  SEEN["$file"]=1;no_links "$RESTORE/$file"
  if [[ "$old" != MISSING ]]; then [[ -f "$RESTORE/$file" && "$(digest "$RESTORE/$file")" == "$old" ]] || fail "Respaldo alterado: $file";fi
  [[ "${BEFORE[$file]}" != "$old" ]] || continue
  [[ "${BEFORE[$file]}" == "$installed" ]] || fail "Archivo editado después de publicar: $file. No se sobrescribe."
  CHANGED+=("$file");AFTER["$file"]="$old"
  if [[ "$old" != MISSING ]]; then cp -p -- "$RESTORE/$file" "$TMP/stage/$file";fi
 done < "$RESTORE/archivos.tsv"
 [[ "${#SEEN[@]}" -gt 0 ]] || fail 'Respaldo vacío.'
else
 echo "Sitio: $LIVE; entrega: $TARGET"
 for file in "${FILES[@]}"; do
  git_public -C "$SOURCE" show "$TARGET:$file" > "$TMP/target"
  if [[ "${BEFORE[$file]}" != MISSING ]]; then cp -p -- "$TMP/original/$file" "$TMP/stage/$file";else cp -- "$TMP/target" "$TMP/stage/$file";chmod 0644 "$TMP/stage/$file";fi
  php -r '
   $m=json_decode(file_get_contents($argv[1]),true);$f=null;foreach($m["files"] as $row)if($row["path"]===$argv[2])$f=$row;if(!$f)exit(1);
   if($argv[4]==="MISSING"&&!$f["new"])exit(10);
   $s=file_get_contents($argv[3]);$crlf=substr_count($s,"\r\n");if($crlf&&$crlf!==substr_count($s,"\n"))exit(11);$s=str_replace("\r\n","\n",$s);file_put_contents($argv[3],$s);$hash=hash("sha256",$s);$eol=$crlf?"CRLF":"LF";
   if($hash===$f["target_sha256"]){echo "APPLIED - - $hash $eol\n";exit;}
   foreach($f["variants"] as $v)if($hash===$v["after"]){echo "APPLIED - - $hash $eol\n";exit;}
   $expected="-";$label="PHP-PERSONALIZADO";$vp=null;$vh=null;
   foreach($f["variants"] as $v)if($hash===$v["before"]){$label=$v["label"];$expected=$v["after"];$vp=$v["patch"]??null;$vh=$v["patch_sha256"]??null;break;}
   if($expected==="-"&&($f["kind"]!=="php"||$f["new"]))exit(10);
   $patch=$vp??$f["patch"]??"";if(!preg_match("#^tools/ventas_conciliacion_actual/[0-9]+\\.patch$#D",$patch))exit(1);
   echo "$label $patch " .($vh??$f["patch_sha256"])." $expected $eol\n";
  ' "$TMP/manifest.json" "$file" "$TMP/stage/$file" "${BEFORE[$file]}" > "$TMP/variant" || fail "CONFLICTO: variante no revisada: $file. SITIO INTACTO."
  read -r variant patch patchHash expected eol < "$TMP/variant"
  if [[ "$variant" != APPLIED ]]; then
   git_public -C "$SOURCE" show "$REVIEWED:$patch" > "$TMP/change.patch"
   [[ "$(digest "$TMP/change.patch")" == "$patchHash" ]] || fail 'Parche con huella incorrecta.'
   if [[ "$variant" == PHP-PERSONALIZADO ]] && (cd "$TMP/stage" && git -c core.autocrlf=false apply --reverse --check "$TMP/change.patch" >/dev/null 2>&1); then :
   else (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/change.patch" && git -c core.autocrlf=false apply "$TMP/change.patch") || fail "Parche incompatible: $file. SITIO INTACTO.";fi
   [[ "$expected" == - || "$(digest "$TMP/stage/$file")" == "$expected" ]] || fail "Salida distinta del archivo probado: $file"
  fi
  if grep -nE '^(<<<<<<<([[:space:]]|$)|=======$|>>>>>>>([[:space:]]|$)|[|]{7}([[:space:]]|$))' "$TMP/stage/$file"; then fail "Marcadores de conflicto: $file";fi
  # JS/CSS deben coincidir íntegramente con una huella probada en desarrollo/CI.
  # No depender de Node en el alojamiento compartido.
  case "$file" in *.php) php -l "$TMP/stage/$file" >/dev/null;; esac
  if [[ "$eol" == CRLF ]];then php -r '$p=$argv[1];file_put_contents($p,str_replace("\n","\r\n",file_get_contents($p)));' "$TMP/stage/$file";fi
  # git apply puede recrear el archivo bajo umask 077. Recuperar sus permisos
  # originales antes de instalar, para conservar el acceso del servidor web.
  if [[ "${BEFORE[$file]}" != MISSING ]];then chmod --reference="$TMP/original/$file" "$TMP/stage/$file";fi
  AFTER["$file"]="$(digest "$TMP/stage/$file")"
  if [[ "${BEFORE[$file]}" != "${AFTER[$file]}" ]];then CHANGED+=("$file");echo "PREPARADO: $file ($variant)";fi
 done
fi
[[ "${#CHANGED[@]}" -gt 0 ]] || { echo 'OK: entrega ya aplicada o respaldo ya restaurado. Ningún archivo reemplazado.';exit 0; }
if [[ "$MODE" == --check ]];then echo "OK: revisión compatible. ${#CHANGED[@]} archivo(s) preparados. SITIO INTACTO. Sin ejecutar SQL; archivos selectivos PHP/JS/CSS. Node no requerido.";exit 0;fi
for file in "${FILES[@]}";do unchanged "$file";done
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/ventas-conciliacion-actual-$(date +%Y%m%d-%H%M%S)-$$";mkdir -m 700 "$BACKUP"
 printf '%s\n' "$TARGET" > "$BACKUP/version.txt"
 for file in "${CHANGED[@]}";do
  printf '%s\t%s\t%s\n' "$file" "${BEFORE[$file]}" "${AFTER[$file]}" >> "$BACKUP/archivos.tsv"
  if [[ "${BEFORE[$file]}" != MISSING ]];then mkdir -p "$BACKUP/$(dirname "$file")";cp -p -- "$TMP/original/$file" "$BACKUP/$file";[[ "$(digest "$BACKUP/$file")" == "${BEFORE[$file]}" ]] || fail "Respaldo inconsistente: $file";fi
 done
fi
WRITING=1
for file in "${CHANGED[@]}";do
 unchanged "$file";[[ ! -e "$LIVE/$file.impe-new-$$" && ! -L "$LIVE/$file.impe-new-$$" ]] || fail 'Existe un temporal de instalación.'
 mkdir -p "$LIVE/$(dirname "$file")";WRITTEN+=("$file")
 if [[ "${AFTER[$file]}" == MISSING ]];then rm -- "$LIVE/$file";
 else cp -p -- "$TMP/stage/$file" "$LIVE/$file.impe-new-$$";mv -f -- "$LIVE/$file.impe-new-$$" "$LIVE/$file";[[ "$(digest "$LIVE/$file")" == "${AFTER[$file]}" ]] || fail "Archivo instalado distinto: $file";fi
done
WRITING=0
echo "OK: $MODE terminado. ${#CHANGED[@]} archivo(s). Sin cambios de configuración ni base de datos."
[[ "$MODE" != --apply ]] || { echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP"; }
