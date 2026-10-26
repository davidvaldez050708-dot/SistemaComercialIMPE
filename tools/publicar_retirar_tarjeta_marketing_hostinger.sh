#!/usr/bin/env bash
# Retirar sólo la tarjeta Gestión de Convocatorias de Inicio Marketing. Sin BD ni red.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='marketing-sin-tarjeta-v1'
FILE='app/views/dashboard/marketing.php'
LIVE="${IMPE_CORREOS_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
BACKUPS="${IMPE_CORREOS_BACKUPS:-$HOME/respaldos-crm}"
fail(){ echo "ERROR: $*" >&2;exit 1; }
hash(){ sha256sum "$1" | cut -d' ' -f1; }
no_links(){ local part="$1";while [[ "$part" != / && "$part" != . ]];do
 [[ ! -L "$part" ]] || fail "Enlace simbólico no permitido: $1"
 case "$part" in */*) part="${part%/*}";[[ -n "$part" ]] || part=/;; *) part=.;;esac
done; }
for tool in php base64 sha256sum cut mktemp realpath cp mv mkdir rm rmdir dirname date chmod;do command -v "$tool" >/dev/null || fail "Falta $tool";done
no_links "$LIVE";[[ -d "$LIVE" ]] || fail 'Raíz del CRM no encontrada.'
LIVE="$(cd "$LIVE" && pwd -P)";no_links "$LIVE/$FILE"
[[ -f "$LIVE/index.php" && -f "$LIVE/$FILE" ]] || fail 'Falta la vista requerida del CRM.'
no_links "$BACKUPS";BACKUPS="$(realpath -m "$BACKUPS")"
[[ "$BACKUPS" != / && "$BACKUPS/" != "$LIVE/"* && "$LIVE/" != "$BACKUPS/"* ]] || fail 'El respaldo debe estar fuera del sitio y sus antecesores.'
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)";[[ "$SCRATCH/" != "$LIVE/"* ]] || fail 'Temporales fuera del sitio requeridos.'
TMP="$(mktemp -d "$SCRATCH/impe-marketing-tarjeta.XXXXXX")"
LOCK="$(dirname "$LIVE")/.impe-correos-deploy.lock";LOCKED=0;WRITING=0;INSTALL_CREATED=0
INSTALL="$LIVE/$FILE.impe-marketing-tarjeta-$$"
finish(){ local code=$?;trap - EXIT INT TERM
 if [[ "$code" != 0 && "$WRITING" == 1 ]];then cp -p -- "$TMP/original.php" "$LIVE/$FILE" || echo 'REVISAR RESTAURACIÓN DE LA VISTA' >&2;fi
 [[ "$INSTALL_CREATED" == 0 ]] || rm -f -- "$INSTALL"
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 rm -f -- "$TMP/original.php" "$TMP/normal.php" "$TMP/block.php" "$TMP/stage.php"
 rmdir -- "$TMP";exit "$code"
}
trap finish EXIT;trap 'exit 130' INT;trap 'exit 143' TERM
[[ ! -e "$INSTALL" && ! -L "$INSTALL" ]] || fail 'Existe un temporal de instalación.'
if [[ "$MODE" != --check ]];then no_links "$LOCK";mkdir "$LOCK" 2>/dev/null || fail 'Otra publicación está en curso.';LOCKED=1;fi
BEFORE="$(hash "$LIVE/$FILE")";cp -p -- "$LIVE/$FILE" "$TMP/original.php"
if [[ "$MODE" == --restore ]];then
 no_links "$2";RESTORE="$(realpath -e "$2")"
 [[ "$(dirname "$RESTORE")" == "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
 for f in version.txt original.php original.sha256 publicado.sha256;do no_links "$RESTORE/$f";[[ -f "$RESTORE/$f" ]] || fail 'Respaldo incompleto.';done
 [[ "$(<"$RESTORE/version.txt")" == "$VERSION" ]] || fail 'Respaldo de otra entrega.'
 ORIGINAL="$(<"$RESTORE/original.sha256")";PUBLISHED="$(<"$RESTORE/publicado.sha256")"
 [[ "$ORIGINAL" =~ ^[a-f0-9]{64}$ && "$PUBLISHED" =~ ^[a-f0-9]{64}$ && "$(hash "$RESTORE/original.php")" == "$ORIGINAL" ]] || fail 'Respaldo alterado.'
 [[ "$BEFORE" != "$ORIGINAL" ]] || { echo 'OK: ya restaurado.';exit 0; }
 [[ "$BEFORE" == "$PUBLISHED" ]] || fail 'Vista editada después de publicar. No se sobrescribe.'
 cp -p -- "$RESTORE/original.php" "$TMP/stage.php"
else
 EOL="$(php -r '$s=file_get_contents($argv[1]);$c=substr_count($s,"\r\n");$n=substr_count($s,"\n");if($c&&$c!==$n)exit(2);echo $c?"CRLF":"LF";file_put_contents($argv[2],str_replace("\r\n","\n",$s));' "$TMP/original.php" "$TMP/normal.php")" || fail 'Finales de línea mixtos.'
 NORMAL="$(hash "$TMP/normal.php")"
 case "$NORMAL" in
    9de93541678d4841244d1cb90483426050f81226fd8228cbd042463fe9a4a445) EXPECTED='8a88f488ce995b21eae4e38b142cc4a3ca6efafb428380a6c9df22a153ec40e3';;
    8a88f488ce995b21eae4e38b142cc4a3ca6efafb428380a6c9df22a153ec40e3) echo 'OK: ya aplicado. Ningún archivo reemplazado.';exit 0;;
    *) fail 'Vista personalizada o incompatible. SITIO INTACTO.';;
 esac
 php -r '$s=file_get_contents($argv[1]);$a=strpos($s,"<section class=\"dashboard-panel marketing-expiring-panel\">");$b=$a===false?false:strpos($s,"</section>",$a);if($a===false||$b===false)exit(2);file_put_contents($argv[2],substr($s,0,$a).substr($s,$b+strlen("</section>")));' "$TMP/normal.php" "$TMP/stage.php"
 [[ "$(hash "$TMP/stage.php")" == "$EXPECTED" ]] || fail 'Resultado distinto al revisado.'
 if [[ "$EOL" == CRLF ]];then php -r '$p=$argv[1];file_put_contents($p,str_replace("\n","\r\n",file_get_contents($p)));' "$TMP/stage.php";fi
 chmod --reference="$TMP/original.php" "$TMP/stage.php"
fi
php -l "$TMP/stage.php" >/dev/null || fail 'Error de sintaxis en la vista preparada.'
no_links "$LIVE/$FILE";[[ "$(hash "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'Vista cambió durante la revisión.'
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. 1 vista PHP. SITIO INTACTO. Sin SQL ni envíos.';exit 0;fi
AFTER="$(hash "$TMP/stage.php")"
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/marketing-tarjeta-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
 cp -p -- "$TMP/original.php" "$BACKUP/original.php"
 [[ "$(hash "$BACKUP/original.php")" == "$BEFORE" ]] || fail 'Respaldo inconsistente.'
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt";printf '%s\n' "$BEFORE" > "$BACKUP/original.sha256";printf '%s\n' "$AFTER" > "$BACKUP/publicado.sha256"
fi
[[ ! -e "$INSTALL" && ! -L "$INSTALL" ]] || fail 'Temporal de instalación inesperado.'
INSTALL_CREATED=1;cp -p -- "$TMP/stage.php" "$INSTALL"
no_links "$LIVE/$FILE";[[ "$(hash "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'Vista cambió antes de instalar.'
WRITING=1;mv -f -- "$INSTALL" "$LIVE/$FILE"
[[ "$(hash "$LIVE/$FILE")" == "$AFTER" ]] || fail 'Vista instalada distinta.'
WRITING=0
echo "OK: $MODE terminado. Sólo $FILE. Sin cambios funcionales, BD ni credenciales."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
