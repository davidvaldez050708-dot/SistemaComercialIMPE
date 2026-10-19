#!/usr/bin/env bash
# Único archivo publicado: recordatorios.js. Sin PHP del CRM, SQL ni Node en el servidor.
set -euo pipefail
umask 077
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) ||
   ( "$#" == 2 && "$MODE" == --restore ) ]] || {
    echo 'Uso: bash publicar_toasts_hostinger.sh --check|--apply|--restore RUTA_RESPALDO' >&2; exit 2;
}
REPO='https://github.com/davidvaldez050708-dot/SistemaComercialIMPE.git'
TARGET='6e8811dd15708e53d7e0bd5104ebc1f14f600997'
FILE='public/javascript/recordatorios.js'
SOURCE="${IMPE_TOASTS_SOURCE:-$HOME/crm-publicaciones/toasts-8m-20261018}"
LIVE="${IMPE_TOASTS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_TOASTS_BACKUPS:-$HOME/respaldos-crm}"
fail() { echo "ERROR: $*" >&2; exit 1; }
digest() { sha256sum "$1" | cut -d' ' -f1; }
no_links() {
    local part="$1"
    while [[ "$part" != / && "$part" != . ]]; do
        [[ ! -L "$part" ]] || fail "Enlace no permitido: $1"
        part="$(dirname "$part")"
    done
}
for tool in git php sha256sum cut mktemp realpath cmp cp mv mkdir rm rmdir dirname cat grep date chmod; do
    command -v "$tool" >/dev/null || fail "Falta $tool. No se modificó el sitio."
done
no_links "$LIVE"
[[ -d "$LIVE" && -f "$LIVE/index.php" ]] || fail "No se encontró la raíz del CRM: $LIVE"
LIVE="$(cd "$LIVE" && pwd -P)"
no_links "$LIVE/$FILE"
[[ -f "$LIVE/$FILE" ]] || fail "Falta $FILE. No se modificó el sitio."
for private in "$SOURCE" "$BACKUPS"; do
    no_links "$private"
    absolute="$(realpath -m "$private")"
    [[ "$absolute" != / && "$absolute/" != "$LIVE/"* && "$LIVE/" != "$absolute/"* ]] ||
        fail 'Preparación y respaldos deben estar fuera del sitio y de sus antecesores.'
done
SOURCE="$(realpath -m "$SOURCE")"
BACKUPS="$(realpath -m "$BACKUPS")"
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
TMP="$(mktemp -d "$SCRATCH/impe-toasts.XXXXXX")"
touch "$TMP/.impe-owned"
LOCK="$(dirname "$LIVE")/.impe-atencion-deploy.lock"
LOCKED=0 WRITING=0
ROLLBACK_SOURCE='' BACKUP=''
finish() {
    local code=$?
    trap - EXIT INT TERM
    if [[ "$WRITING" == 1 && "$code" != 0 ]]; then
        if cp -p -- "$ROLLBACK_SOURCE" "$LIVE/$FILE"; then
            echo 'Publicación interrumpida. Archivo anterior restaurado.' >&2
        else
            echo "REVISAR RESTAURACIÓN: $BACKUP" >&2
        fi
    fi
    [[ "$WRITING" == 0 ]] || rm -f -- "$LIVE/$FILE.impe-new-$$" || true
    [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK" || true
    if [[ "$TMP" == "$SCRATCH"/impe-toasts.* && -f "$TMP/.impe-owned" ]]; then rm -rf -- "$TMP"; fi
    exit "$code"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
if [[ "$MODE" != --check ]]; then
    mkdir "$LOCK" 2>/dev/null || fail "Existe otra publicación o un bloqueo pendiente: $LOCK"
    LOCKED=1
fi
BEFORE="$(digest "$LIVE/$FILE")"
if [[ "$MODE" == --restore ]]; then
    no_links "$2"
    RESTORE="$(realpath -m "$2")"
    [[ "$RESTORE/" == "$BACKUPS/"* && "$RESTORE" != "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
    no_links "$RESTORE/$FILE"
    no_links "$RESTORE/metadata.json"
    [[ -f "$RESTORE/metadata.json" && -f "$RESTORE/$FILE" ]] || fail 'Respaldo incompleto.'
    php -r '
        $m=json_decode(file_get_contents($argv[1]),true);
        if (!is_array($m) || ($m["file"]??"")!==$argv[2]) exit(1);
        foreach (["before","installed"] as $key) if (!preg_match("/^[a-f0-9]{64}$/",$m[$key]??"")) exit(1);
        echo $m["before"]." ".$m["installed"]."\n";
    ' "$RESTORE/metadata.json" "$FILE" > "$TMP/restore-data" || fail 'Metadatos de respaldo inválidos.'
    read -r ORIGINAL INSTALLED < "$TMP/restore-data"
    [[ "$(digest "$RESTORE/$FILE")" == "$ORIGINAL" ]] || fail 'El respaldo no coincide con su huella.'
    if [[ "$BEFORE" == "$ORIGINAL" ]]; then echo 'OK: el archivo original ya está restaurado.'; exit 0; fi
    [[ "$BEFORE" == "$INSTALLED" ]] || fail 'El archivo cambió después de publicar. No se sobrescribe esa edición.'
    cp -p -- "$LIVE/$FILE" "$TMP/antes-restaurar"
    cp -p -- "$RESTORE/$FILE" "$TMP/stage-restore"
    no_links "$LIVE/$FILE"
    [[ "$(digest "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'Cambió durante la revisión de restauración.'
    [[ ! -e "$LIVE/$FILE.impe-new-$$" && ! -L "$LIVE/$FILE.impe-new-$$" ]] || fail 'Existe el archivo temporal de instalación.'
    ROLLBACK_SOURCE="$TMP/antes-restaurar" BACKUP="$RESTORE" WRITING=1
    cp -p -- "$TMP/stage-restore" "$LIVE/$FILE.impe-new-$$"
    mv -f -- "$LIVE/$FILE.impe-new-$$" "$LIVE/$FILE"
    [[ "$(digest "$LIVE/$FILE")" == "$ORIGINAL" ]] || fail 'No coincidió la restauración.'
    WRITING=0
    echo 'OK: recordatorios.js restaurado. Recarga la vista del CRM.'
    exit 0
fi

# HTTPS público independiente de las reglas globales que redirigen Git hacia SSH.
export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0
unset GIT_CONFIG GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT
git_public() { command git -c remote.origin.url="$REPO" -c http.sslVerify=true "$@"; }
[[ ! -e "$SOURCE" || -d "$SOURCE/.git" ]] || fail 'Preparación existente sin repositorio Git.'
if [[ ! -e "$SOURCE" ]]; then
    mkdir -p "$(dirname "$SOURCE")"
    git_public clone --quiet --no-checkout --filter=blob:none "$REPO" "$SOURCE"
fi
if git_public -C "$SOURCE" config --name-only --get-regexp '^url\..*\.insteadof$' >/dev/null; then
    fail 'La preparación tiene reglas locales de URL. Usa otra carpeta privada.'
fi
if ! git_public -C "$SOURCE" cat-file -e "$TARGET^{commit}" 2>/dev/null; then
    if [[ "$(git_public -C "$SOURCE" rev-parse --is-shallow-repository)" == true ]]; then
        git_public -C "$SOURCE" fetch --quiet --unshallow "$REPO" main
    else
        git_public -C "$SOURCE" fetch --quiet "$REPO" main
    fi
fi
git_public -C "$SOURCE" show "$TARGET:tools/toasts_8m/manifest.json" > "$TMP/manifest.json"
mkdir -p "$TMP/stage/$(dirname "$FILE")"
cp -p -- "$LIVE/$FILE" "$TMP/original"
cp -p -- "$TMP/original" "$TMP/stage/$FILE"
# Normalizar sólo LF/CRLF uniformes para cotejar la variante completa. La salida
# conserva los finales originales. No se acepta por coincidencias de cadenas.
php -r '
    $b=file_get_contents($argv[1]);$crlf=substr_count($b,"\r\n");
    if ($crlf && $crlf!==substr_count($b,"\n")) exit(1);
    file_put_contents($argv[2],str_replace("\r\n","\n",$b));
    echo $crlf?"CRLF":"LF";
' "$TMP/original" "$TMP/stage/$FILE" > "$TMP/eol" || fail 'Finales de línea mezclados. SITIO INTACTO.'
NORMALIZED="$(digest "$TMP/stage/$FILE")"
php -r '
    $m=json_decode(file_get_contents($argv[1]),true);
    if (!is_array($m)||($m["version"]??0)!==1||($m["file"]??"")!==$argv[2]) exit(1);
    if (!preg_match("/^[a-f0-9]{64}$/",$m["after"]??"")) exit(1);
    if ($argv[3]===$m["after"]) {echo "APPLIED - - ".$m["after"]."\n";exit(0);}
    foreach ($m["variants"]??[] as $v) {
        if (($v["before"]??"")!==$argv[3]) continue;
        if (!preg_match("#^tools/toasts_8m/[a-z0-9-]+\.patch$#",$v["patch"]??"")) exit(1);
        if (!preg_match("/^[a-f0-9]{64}$/",$v["patch_sha256"]??"")) exit(1);
        echo $v["label"]." ".$v["patch"]." ".$v["patch_sha256"]." ".$m["after"]."\n";exit(0);
    }
    exit(10);
' "$TMP/manifest.json" "$FILE" "$NORMALIZED" > "$TMP/variant" ||
    fail "CONFLICTO: variante de recordatorios.js no revisada. SHA-256 normalizado: $NORMALIZED. SITIO INTACTO."
read -r VARIANT PATCH PATCH_HASH EXPECTED < "$TMP/variant"
echo "Sitio: $LIVE"
echo "Entrega: $TARGET; variante: $VARIANT"
if [[ "$VARIANT" == APPLIED ]]; then echo 'OK: esta corrección ya está publicada; no se reemplazó ningún archivo.'; exit 0; fi
git_public -C "$SOURCE" show "$TARGET:$PATCH" > "$TMP/change.patch"
[[ "$(digest "$TMP/change.patch")" == "$PATCH_HASH" ]] || fail 'El parche no coincide con su huella.'
(cd "$TMP/stage" && git -c core.autocrlf=false apply --check "$TMP/change.patch") || fail 'Parche incompatible. SITIO INTACTO.'
(cd "$TMP/stage" && git -c core.autocrlf=false apply "$TMP/change.patch")
[[ "$(digest "$TMP/stage/$FILE")" == "$EXPECTED" ]] || fail 'El resultado no coincide íntegramente con el JavaScript probado. SITIO INTACTO.'
if grep -nE '^(<<<<<<<|=======|>>>>>>>)' "$TMP/stage/$FILE"; then fail 'Marcadores de conflicto. SITIO INTACTO.'; fi
if [[ "$(cat "$TMP/eol")" == CRLF ]]; then
    php -r '$p=$argv[1];$s=file_get_contents($p);file_put_contents($p,str_replace("\n","\r\n",$s));' "$TMP/stage/$FILE"
fi
AFTER="$(digest "$TMP/stage/$FILE")"
chmod --reference="$TMP/original" "$TMP/stage/$FILE"
echo "VALIDADO: archivo íntegro probado con Node y Playwright: $EXPECTED (LF). Node no requerido aquí."
if [[ "$MODE" == --check ]]; then echo 'OK: revisión compatible. 1 archivo preparado. SITIO INTACTO.'; exit 0; fi
no_links "$LIVE/$FILE"
[[ "$(digest "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'El archivo cambió durante la revisión. SITIO INTACTO.'
mkdir -p "$BACKUPS"
BACKUP="$BACKUPS/toasts-8m-$(date +%Y%m%d-%H%M%S)-$$"
mkdir -m 700 "$BACKUP"
mkdir -p "$BACKUP/$(dirname "$FILE")"
cp -p -- "$LIVE/$FILE" "$BACKUP/$FILE"
[[ "$(digest "$BACKUP/$FILE")" == "$BEFORE" ]] || fail 'El respaldo cambió durante la copia; no se publicó.'
php -r 'echo json_encode(["file"=>$argv[1],"before"=>$argv[2],"installed"=>$argv[3],"target"=>$argv[4]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;' \
    "$FILE" "$BEFORE" "$AFTER" "$TARGET" > "$BACKUP/metadata.json"
[[ "$(digest "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'El archivo cambió antes de instalar; no se publicó.'
[[ ! -e "$LIVE/$FILE.impe-new-$$" && ! -L "$LIVE/$FILE.impe-new-$$" ]] || fail 'Existe el archivo temporal de instalación.'
ROLLBACK_SOURCE="$BACKUP/$FILE" WRITING=1
cp -p -- "$TMP/stage/$FILE" "$LIVE/$FILE.impe-new-$$"
mv -f -- "$LIVE/$FILE.impe-new-$$" "$LIVE/$FILE"
[[ "$(digest "$LIVE/$FILE")" == "$AFTER" ]] || fail 'No coincidió el archivo publicado.'
WRITING=0
echo 'OK: toasts publicados. Sólo se modificó public/javascript/recordatorios.js.'
echo "Respaldo: $BACKUP"
echo "Restaurar: bash \"\$HOME/publicar-toasts.sh\" --restore \"$BACKUP\""
echo 'Recarga la vista del CRM y comprueba window.IMPE_REMINDER_STATUS(). Primer aviso a los ocho minutos; cierre a los siete segundos.'
