#!/usr/bin/env bash
# Revisión post fase E: 9 archivos, huellas de Hostinger exactas. Sin migración SQL.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$#" == 2 && "$MODE" == --restore ) ]] || { echo 'Uso: --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2; }
TARGET='9692b73077bc093c3a0a07d3f1c920e63e9328df'
REPO='https://github.com/davidvaldez050708-dot/SistemaComercialIMPE.git'
SOURCE="${IMPE_VENTAS_REVISION_SOURCE:-$HOME/crm-publicaciones/ventas-revision-20261019-actual}"
LIVE="${IMPE_VENTAS_REVISION_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_VENTAS_REVISION_BACKUPS:-$HOME/respaldos-crm}"
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
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)"; TMP="$(mktemp -d "$SCRATCH/impe-ventas-revision.XXXXXX")"; touch "$TMP/.impe-owned"
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
 if [[ "$TMP" == "$SCRATCH"/impe-ventas-revision.* && -f "$TMP/.impe-owned" ]]; then rm -rf -- "$TMP"; fi
 exit "$code"
}
trap finish EXIT; trap 'exit 130' INT; trap 'exit 143' TERM
# Sólo los archivos solicitados y cuyas huellas reales fueron cotejadas.
# Se rechaza cualquier personalización posterior sin sobreescribirla.
FILES=(
 'app/services/TelefoniaMarcacionesVentasService.php'
 'app/services/TelefoniaVentasLlamadasService.php'
 'app/views/telefonia/marcador.php'
 'app/views/telefonia/ventas_llamadas.php'
 'public/javascript/telefonia_marcador.js'
 'public/javascript/telefonia_ventas_shell.js'
 'public/css/telefonia_shell.css'
 'public/css/telefonia_marcador.css'
 'public/css/telefonia_ventas_ui.css'
)
declare -A PINNED_BEFORE=(
 ['app/services/TelefoniaMarcacionesVentasService.php']='61bf714d243bd1742e8a2ea00b883e2b7c10c61da562622bfec63251f236bb2e'
 ['app/services/TelefoniaVentasLlamadasService.php']='9e8295ad5340895f7acb3cdc8611ca28e040bb3c88ab2891f45039cd7bf0a161'
 ['app/views/telefonia/marcador.php']='3114a95de36ee952413a47565f970a90beca884b3e72f05e91c7646a0ffae36e'
 ['app/views/telefonia/ventas_llamadas.php']='9d4ffacca65722fcc6b8aa2d0cdd595830b549530fb1859bb87dadaf17ba2b05'
 ['public/javascript/telefonia_marcador.js']='aece60de5f4a7b994d0639313b9034e154b6c2bd62be78a3c6b5918ce079972e'
 ['public/javascript/telefonia_ventas_shell.js']='92d884534ab2c7b42f4ae9368289d7b7a010d3de29e34a59960caa5c52f96e1e'
 ['public/css/telefonia_shell.css']='5298d0505cde80425b4710b39d2b270f5d6ecbdb686f854bc1d97698d7bdd272'
 ['public/css/telefonia_marcador.css']='43ab353c2a5df461eecc2493710ddc104a8dd814e6b38c5634cbffe9c9b43cdc'
 ['public/css/telefonia_ventas_ui.css']='MISSING'
)
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
 for file in "${FILES[@]}";do
  git_public -C "$SOURCE" show "$TARGET:$file" > "$TMP/stage/$file" || fail "Fuente GitHub no disponible: $file"
  target_hash="$(digest "$TMP/stage/$file")"
  # Aceptar únicamente la huella exacta de Hostinger o el nuevo archivo ya instalado.
  if [[ "${BEFORE[$file]}" != "${PINNED_BEFORE[$file]}" && "${BEFORE[$file]}" != "$target_hash" ]];then
   fail "CONFLICTO: variante no revisada: $file. SITIO INTACTO."
  fi
  [[ "${BEFORE[$file]}" != MISSING || "${PINNED_BEFORE[$file]}" == MISSING ]] || fail "CONFLICTO: archivo existente desapareció: $file"
  if [[ "${BEFORE[$file]}" != MISSING ]];then
   chmod --reference="$TMP/original/$file" "$TMP/stage/$file"
  else
   chmod 0644 "$TMP/stage/$file"
  fi
  case "$file" in *.php) php -l "$TMP/stage/$file" >/dev/null || fail "PHP inválido: $file";; esac
  AFTER["$file"]="$target_hash"
  if [[ "${BEFORE[$file]}" != "$target_hash" ]];then CHANGED+=("$file");echo "PREPARADO: $file (Hostinger cotejado)";fi
 done
fi
[[ "${#CHANGED[@]}" -gt 0 ]] || { echo 'OK: entrega ya aplicada o respaldo ya restaurado. Ningún archivo reemplazado.';exit 0; }
if [[ "$MODE" == --check ]];then echo "OK: revisión compatible. ${#CHANGED[@]} archivo(s) preparados. SITIO INTACTO. Sin SQL; JavaScript cotejado con huellas probadas; Node no requerido.";exit 0;fi
for file in "${FILES[@]}";do unchanged "$file";done
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/ventas-revision-20261019-$(date +%Y%m%d-%H%M%S)-$$";mkdir -m 700 "$BACKUP"
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
[[ "$MODE" != --apply ]] || { echo "Respaldo: $BACKUP";echo "Rollback: bash \"\$HOME/publicar-ventas-revision-20261019.sh\" --restore \"$BACKUP\""; }
