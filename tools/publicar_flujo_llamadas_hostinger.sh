#!/usr/bin/env bash
# Publicador de la entrega validada. --check prepara y revisa, sin escribir en el sitio.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ]] || {
    echo 'Uso: bash publicar_flujo_llamadas_hostinger.sh --check|--apply' >&2; exit 2;
}
REPO='https://github.com/davidvaldez050708-dot/SistemaComercialIMPE.git'
BASE='c1afd6888e8dad1cae18aeb04147475ba56fb5a7'
TARGET='bb78c8f36ddf2adb5efeec51739f5575d524fe59'
SOURCE="${IMPE_FLUJO_SOURCE:-$HOME/crm-publicaciones/flujo-llamadas-20261018}"
LIVE="${IMPE_FLUJO_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_FLUJO_BACKUPS:-$HOME/respaldos-crm}"
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
    app/services/TelefoniaTransferenciaOperacionService.php
    app/services/TelefoniaNotificacionService.php
    public/javascript/telefonia_transferencia_runtime.js
    public/javascript/telefonia_notificaciones.js
    prueba_telefonia/api/transferencia_operacion.php
    prueba_telefonia/api/leer_notificacion_llamada.php
    app/services/TelefoniaAnalistaWebrtcService.php
    app/services/TelefoniaEntrantesAnalistaService.php
    app/services/TelefoniaRecepcionMarketingService.php
    app/services/TelefoniaTransferenciaContextoService.php
    public/css/telefonia_enrutamiento.css
    public/javascript/convocatoria_notificaciones.js
    public/javascript/recordatorios.js
    public/javascript/telefonia_analista_historial_pasivo.js
    public/javascript/telefonia_audio.js
    public/javascript/telefonia_enrutamiento.js
    public/javascript/telefonia_host.js
    public/javascript/telefonia_shell.js
    app/controllers/ConvocatoriaNotificacionController.php
    app/controllers/ReminderController.php
    app/controllers/TelefoniaController.php
    app/views/layout/dashboard_footer.php
    app/views/layout/topbar.php
    app/views/seguimiento_vinculacion/llamadas_entrantes.php
    app/views/telefonia/host.php
    app/views/telefonia/shell.php
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
    echo 'Se validó sintaxis PHP. Los servicios preparan dos tablas nuevas al utilizarse; no se modifica el historial anterior.'
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
BACKUP="$BACKUPS/flujo-llamadas-$(date +%Y%m%d-%H%M%S)-$$"
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
echo "OK: flujo de llamadas publicado. ${#CHANGED[@]} archivo(s)."
echo "Respaldo: $BACKUP"
echo 'Cierra sesión y vuelve a entrar en el CRM para recargar también el teléfono persistente.'
echo 'Comprueba Consultar primero → Completar transferencia, transferencia directa, devolución sin respuesta y avisos en ambas campanas. Este publicador no ejecuta llamadas ni modifica datos o configuración privada.'
