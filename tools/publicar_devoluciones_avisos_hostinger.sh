#!/usr/bin/env bash
# Publicador de la entrega validada. --check prepara y revisa, sin escribir en el sitio.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ]] || {
    echo 'Uso: bash publicar_devoluciones_avisos_hostinger.sh --check|--apply' >&2; exit 2;
}
REPO='https://github.com/davidvaldez050708-dot/SistemaComercialIMPE.git'
BASE='f344c81115b29724a547f0f37cd083438da3079f'
TARGET='ffe726848f175d243d7980481ad4911fd2c6af3f'
SOURCE="${IMPE_AVISOS_SOURCE:-$HOME/crm-publicaciones/devoluciones-avisos-20261018}"
LIVE="${IMPE_AVISOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_AVISOS_BACKUPS:-$HOME/respaldos-crm}"
fail() { echo "ERROR: $*" >&2; exit 1; }
# Sólo afecta este proceso. Una regla insteadOf de la cuenta no debe convertir
# la descarga pública HTTPS en una conexión SSH que requiera llaves.
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0
unset GIT_CONFIG GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT
git_public() { command git -c remote.origin.url="$REPO" -c http.sslVerify=true "$@"; }
for tool in git php sha256sum mktemp realpath cmp cp mv mkdir rm rmdir diff; do
    command -v "$tool" >/dev/null || fail "Falta $tool. No se modificó el sitio."
done
[[ -d "$LIVE" && ! -L "$LIVE" && -f "$LIVE/index.php" && ! -L "$LIVE/index.php" ]] ||
    fail "No se encontró la raíz del CRM: $LIVE"
LIVE="$(cd "$LIVE" && pwd -P)"
SOURCE="$(realpath -m "$SOURCE")"
BACKUPS="$(realpath -m "$BACKUPS")"
for private in "$SOURCE" "$BACKUPS"; do
    [[ "$private" != / ]] || fail 'La raíz del servidor no puede utilizarse para preparación o respaldos.'
    [[ "$private/" != "$LIVE/"* && "$LIVE/" != "$private/"* ]] ||
        fail 'La preparación y los respaldos deben estar fuera de la carpeta pública.'
done
[[ ! -L "$SOURCE" && ! -L "$BACKUPS" ]] || fail 'Preparación o respaldos no pueden ser enlaces.'

# Los archivos nuevos van primero, antes de los controladores que los requieren.
FILES=(
    public/javascript/telefonia_persistente.js
    public/javascript/telefonia_devoluciones.js
    public/javascript/seguimiento_entrantes_analista.js
    public/javascript/recordatorios.js
    public/javascript/convocatoria_notificaciones.js
    public/javascript/telefonia_notificaciones.js
    app/views/telefonia/resultado_analista_form.php
)
[[ ! -e "$SOURCE" || -d "$SOURCE/.git" ]] || fail 'La carpeta de preparación existe pero no es un repositorio Git.'
if [[ ! -e "$SOURCE" ]]; then
    echo "Preparando copia independiente por HTTPS: $SOURCE"
    mkdir -p "$(dirname "$SOURCE")"
    git_public clone --quiet --no-checkout --filter=blob:none "$REPO" "$SOURCE"
fi
if git_public -C "$SOURCE" config --name-only --get-regexp '^url\..*\.insteadof$' >/dev/null; then
    fail 'La preparación contiene reglas locales de URL. Utiliza una carpeta nueva fuera del sitio.'
fi
# No cambia la rama, el índice ni los archivos de la copia de preparación.
if ! git_public -C "$SOURCE" cat-file -e "$TARGET^{commit}" 2>/dev/null ||
   ! git_public -C "$SOURCE" cat-file -e "$BASE^{commit}" 2>/dev/null; then
    echo 'Descargando la versión comprobada de GitHub…'
    if [[ "$(git_public -C "$SOURCE" rev-parse --is-shallow-repository)" == true ]]; then
        git_public -C "$SOURCE" fetch --quiet --unshallow "$REPO" main
    else
        git_public -C "$SOURCE" fetch --quiet "$REPO" main
    fi
fi
git_public -C "$SOURCE" cat-file -e "$TARGET^{commit}"
git_public -C "$SOURCE" cat-file -e "$BASE^{commit}"

SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
TMP="$(mktemp -d "$SCRATCH/impe-atencion.XXXXXX")"
touch "$TMP/.impe-owned"
mkdir "$TMP/stage"
LOCK="$(dirname "$LIVE")/.impe-atencion-deploy.lock"
LOCKED=0
WRITING=0
BACKUP=''
declare -a CHANGED=()
declare -A BEFORE=()
digest() { sha256sum "$1" | cut -d' ' -f1; }
guard_path() {
    local part="$LIVE" item
    local -a components
    IFS=/ read -r -a components <<< "$1"
    for item in "${components[@]}"; do
        part="$part/$item"
        [[ ! -L "$part" ]] || fail "Enlace no permitido: $1"
        [[ ! -e "$part" || -d "$part" || -f "$part" ]] || fail "Ruta no regular: $1"
    done
}
finish() {
    local code=$? file restored=1
    trap - EXIT INT TERM
    if [[ "$WRITING" == 1 && "$code" != 0 ]]; then
        echo 'Publicación interrumpida. Restaurando los archivos anteriores…' >&2
        for file in "${CHANGED[@]}"; do
            if [[ "${BEFORE[$file]}" == MISSING ]]; then
                rm -f -- "$LIVE/$file" || restored=0
            else
                cp -p -- "$BACKUP/$file" "$LIVE/$file" || restored=0
            fi
            rm -f -- "$LIVE/$file.impe-new-$$" || restored=0
        done
        [[ "$restored" == 1 ]] && echo "Archivos restaurados. Respaldo: $BACKUP" >&2 ||
            echo "REVISAR RESTAURACIÓN: $BACKUP" >&2
    fi
    [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK" || true
    if [[ "$TMP" == "$SCRATCH"/impe-atencion.* && -f "$TMP/.impe-owned" ]]; then rm -rf -- "$TMP"; fi
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
if [[ "$MODE" == --apply ]]; then
    mkdir "$LOCK" 2>/dev/null || fail "Existe otra publicación o un bloqueo pendiente: $LOCK"
    LOCKED=1
fi
echo "Sitio: $LIVE"
echo "Versión: $TARGET"
conflicts=0
for file in "${FILES[@]}"; do
    guard_path "$file"
    mkdir -p "$TMP/stage/$(dirname "$file")"
    git_public -C "$SOURCE" show "$TARGET:$file" > "$TMP/target"
    if [[ -e "$LIVE/$file" && ! -f "$LIVE/$file" ]]; then fail "No es archivo: $file"; fi
    if [[ -f "$LIVE/$file" ]]; then
        BEFORE["$file"]="$(digest "$LIVE/$file")"
        cp -p "$LIVE/$file" "$TMP/stage/$file"
    else
        BEFORE["$file"]=MISSING
    fi
    if ! git_public -C "$SOURCE" cat-file -e "$BASE:$file" 2>/dev/null; then
        if [[ "${BEFORE[$file]}" == MISSING ]]; then
            cp "$TMP/target" "$TMP/stage/$file"
        elif ! cmp -s "$TMP/target" "$LIVE/$file"; then
            echo "CONFLICTO: archivo nuevo ya personalizado: $file"; conflicts=$((conflicts+1)); continue
        fi
    elif [[ "${BEFORE[$file]}" == MISSING ]]; then
        echo "CONFLICTO: falta archivo existente: $file"; conflicts=$((conflicts+1)); continue
    elif ! cmp -s "$TMP/target" "$LIVE/$file"; then
        git_public -C "$SOURCE" diff --binary "$BASE" "$TARGET" -- "$file" > "$TMP/change.patch"
        if (cd "$TMP/stage" && git -c core.autocrlf=false apply --reverse --check "$TMP/change.patch" >/dev/null 2>&1); then
            : # El parche ya está aplicado; conserva personalizaciones ajenas.
        elif (cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/change.patch" >/dev/null 2>&1); then
            (cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/change.patch")
        else
            echo "CONFLICTO: revisión necesaria: $file"; conflicts=$((conflicts+1)); continue
        fi
    fi
    case "$file" in
        *.php) php -l "$TMP/stage/$file" >/dev/null ;;
        *.js) if command -v node >/dev/null; then node --check "$TMP/stage/$file"; fi ;;
    esac
    if [[ "${BEFORE[$file]}" == MISSING ]] || ! cmp -s "$LIVE/$file" "$TMP/stage/$file"; then
        CHANGED+=("$file"); echo "PREPARADO: $file"
    else
        echo "ACTUALIZADO: $file"
    fi
done
[[ "$conflicts" == 0 ]] || fail "$conflicts archivo(s) requieren revisión. SITIO INTACTO. No se sobrescribieron personalizaciones."
if [[ "${#CHANGED[@]}" == 0 ]]; then
    echo 'OK: esta entrega ya está publicada. No se reemplazó ningún archivo.'; exit 0
fi
if [[ "$MODE" == --check ]]; then
    echo "OK: revisión compatible. ${#CHANGED[@]} archivo(s) preparados. SITIO INTACTO."
    echo 'Se validó sintaxis PHP. Esta entrega no modifica la base de datos ni los filtros de devoluciones.'
    echo 'Siguiente paso, con las extensiones sin llamada activa: ejecutar este mismo publicador con --apply.'
    exit 0
fi
# Comprobar de nuevo TODOS los archivos antes de respaldar o escribir.
for file in "${FILES[@]}"; do
    guard_path "$file"
    if [[ "${BEFORE[$file]}" == MISSING ]]; then
        [[ ! -e "$LIVE/$file" ]] || fail "El archivo apareció durante la revisión: $file"
    else
        [[ -f "$LIVE/$file" && "$(digest "$LIVE/$file")" == "${BEFORE[$file]}" ]] ||
            fail "Cambió durante la revisión: $file. SITIO INTACTO."
    fi
done
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/devoluciones-avisos-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -m 700 "$BACKUP"
for file in "${CHANGED[@]}"; do
    printf '%s\t%s\t%s\n' "$file" "${BEFORE[$file]}" "$(digest "$TMP/stage/$file")" >> "$BACKUP/archivos.tsv"
    if [[ "${BEFORE[$file]}" != MISSING ]]; then
        mkdir -p "$BACKUP/$(dirname "$file")"
        cp -p "$LIVE/$file" "$BACKUP/$file"
    fi
done
printf '%s\n' "$TARGET" > "$BACKUP/version.txt"
WRITING=1
for file in "${CHANGED[@]}"; do
    mkdir -p "$LIVE/$(dirname "$file")"
    cp "$TMP/stage/$file" "$LIVE/$file.impe-new-$$"
    chmod 0644 "$LIVE/$file.impe-new-$$"
    mv -f "$LIVE/$file.impe-new-$$" "$LIVE/$file"
    cmp -s "$LIVE/$file" "$TMP/stage/$file" || fail "No coincidió el archivo publicado: $file"
done
WRITING=0
echo "OK: panel único y avisos de devoluciones publicados. ${#CHANGED[@]} archivo(s)."
echo "Respaldo: $BACKUP"
echo 'Cierra sesión y vuelve a entrar en el CRM para recargar también el teléfono persistente.'
echo 'Comprueba devolución sin segundo teléfono; botones y ayuda del CRM; institución nueva seleccionable con regla explícita; toast y campana de llamadas sin repetición. Este publicador no ejecuta llamadas ni modifica datos o configuración privada.'
