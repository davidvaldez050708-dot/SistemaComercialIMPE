#!/usr/bin/env bash
# Sólo tipografía de Redactar correo. Sin BD, red, credenciales ni mensajes.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='correo-tipografia-v1'
FILE='public/css/correos_marketing.css'
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
[[ -f "$LIVE/index.php" && -f "$LIVE/$FILE" ]] || fail 'Falta el CSS requerido del CRM.'
no_links "$BACKUPS";BACKUPS="$(realpath -m "$BACKUPS")"
[[ "$BACKUPS" != / && "$BACKUPS/" != "$LIVE/"* && "$LIVE/" != "$BACKUPS/"* ]] || fail 'El respaldo debe estar fuera del sitio y sus antecesores.'
SCRATCH="$(cd "${TMPDIR:-/tmp}" && pwd -P)";[[ "$SCRATCH/" != "$LIVE/"* ]] || fail 'Temporales fuera del sitio requeridos.'
TMP="$(mktemp -d "$SCRATCH/impe-tipografia.XXXXXX")"
LOCK="$(dirname "$LIVE")/.impe-correos-deploy.lock";LOCKED=0;WRITING=0;INSTALL_CREATED=0
INSTALL="$LIVE/$FILE.impe-tipografia-$$"
finish(){ local code=$?;trap - EXIT INT TERM
 if [[ "$code" != 0 && "$WRITING" == 1 ]];then cp -p -- "$TMP/original.css" "$LIVE/$FILE" || echo 'REVISAR RESTAURACIÓN DEL CSS' >&2;fi
 [[ "$INSTALL_CREATED" == 0 ]] || rm -f -- "$INSTALL"
 [[ "$LOCKED" == 0 ]] || rmdir -- "$LOCK"
 rm -f -- "$TMP/original.css" "$TMP/normal.css" "$TMP/block.css" "$TMP/stage.css"
 rmdir -- "$TMP";exit "$code"
}
trap finish EXIT;trap 'exit 130' INT;trap 'exit 143' TERM
[[ ! -e "$INSTALL" && ! -L "$INSTALL" ]] || fail 'Existe un temporal de instalación.'
if [[ "$MODE" != --check ]];then no_links "$LOCK";mkdir "$LOCK" 2>/dev/null || fail 'Otra publicación está en curso.';LOCKED=1;fi
BEFORE="$(hash "$LIVE/$FILE")";cp -p -- "$LIVE/$FILE" "$TMP/original.css"
if [[ "$MODE" == --restore ]];then
 no_links "$2";RESTORE="$(realpath -e "$2")"
 [[ "$(dirname "$RESTORE")" == "$BACKUPS" ]] || fail 'Respaldo fuera de la carpeta prevista.'
 for f in version.txt original.css original.sha256 publicado.sha256;do no_links "$RESTORE/$f";[[ -f "$RESTORE/$f" ]] || fail 'Respaldo incompleto.';done
 [[ "$(<"$RESTORE/version.txt")" == "$VERSION" ]] || fail 'Respaldo de otra entrega.'
 ORIGINAL="$(<"$RESTORE/original.sha256")";PUBLISHED="$(<"$RESTORE/publicado.sha256")"
 [[ "$ORIGINAL" =~ ^[a-f0-9]{64}$ && "$PUBLISHED" =~ ^[a-f0-9]{64}$ && "$(hash "$RESTORE/original.css")" == "$ORIGINAL" ]] || fail 'Respaldo alterado.'
 [[ "$BEFORE" != "$ORIGINAL" ]] || { echo 'OK: ya restaurado.';exit 0; }
 [[ "$BEFORE" == "$PUBLISHED" ]] || fail 'CSS editado después de publicar. No se sobrescribe.'
 cp -p -- "$RESTORE/original.css" "$TMP/stage.css"
else
 EOL="$(php -r '$s=file_get_contents($argv[1]);$c=substr_count($s,"\r\n");$n=substr_count($s,"\n");if($c&&$c!==$n)exit(2);echo $c?"CRLF":"LF";file_put_contents($argv[2],str_replace("\r\n","\n",$s));' "$TMP/original.css" "$TMP/normal.css")" || fail 'Finales de línea mixtos.'
 NORMAL="$(hash "$TMP/normal.css")"
 case "$NORMAL" in
    033c1e7d188e77aa38b9dd90f5a4256dec2e6a73de1bc6c707091742c92262db) EXPECTED='608ed2fe7ee3c690593923b7a613446a165b1d349fa4f2298d8f5df53142b6ff';;
    608ed2fe7ee3c690593923b7a613446a165b1d349fa4f2298d8f5df53142b6ff) echo 'OK: ya aplicado. Ningún archivo reemplazado.';exit 0;;
    *) fail 'CSS personalizado o incompatible. SITIO INTACTO.';;
 esac
 base64 --decode > "$TMP/block.css" <<'IMPE_TIPOGRAFIA'
Ci8qIFJlZGFjdGFyIGNvcnJlbzogZXNjYWxhIGRlIGxvcyBmb3JtdWxhcmlvcyBpbnN0aXR1Y2lv
bmFsZXMgKGRhc2hib2FyZC5jc3MpLgogICBNYW5yb3BlIHNlIGhlcmVkYSBkZSBnbG9iYWwuY3Nz
OyBzw7NsbyB0aXBvZ3JhZsOtYSwgc2luIGNhbWJpYXIgY29udGVuZWRvcmVzLiAqLwojbW9kYWxD
b3JyZW9NYXJrZXRpbmcgewogICAgZm9udC1zaXplOiAxMnB4Owp9CgojbW9kYWxDb3JyZW9NYXJr
ZXRpbmcgOmlzKGlucHV0LCBzZWxlY3QsIHRleHRhcmVhLCBidXR0b24sIC51bmlmb3JtLXNlbGVj
dCkgewogICAgZm9udC1mYW1pbHk6IGluaGVyaXQ7Cn0KCiNtb2RhbENvcnJlb01hcmtldGluZyA6
aXMoLmZvcm0tbGFiZWwsIC5jb3JyZW8tbWFya2V0aW5nLW1lc3NhZ2UtZWRpdG9yLWxhYmVsKSB7
CiAgICBmb250LXNpemU6IDExcHg7CiAgICBmb250LXdlaWdodDogNjAwOwogICAgbGluZS1oZWln
aHQ6IDEuNDU7Cn0KCiNtb2RhbENvcnJlb01hcmtldGluZyA6aXMoLnN5c3RlbS1mb3JtLWNvbnRy
b2wsIC5jb3JyZW8tbWFya2V0aW5nLW1lc3NhZ2UsCiAgICAudW5pZm9ybS1zZWxlY3QtdHJpZ2dl
ciwgLnVuaWZvcm0tc2VsZWN0LW9wdGlvbikgewogICAgZm9udC1zaXplOiAxMnB4Owp9CgojbW9k
YWxDb3JyZW9NYXJrZXRpbmcgLmNvcnJlby1tYXJrZXRpbmctbWVzc2FnZSB7CiAgICBmb250LXdl
aWdodDogNTAwOwp9CgojbW9kYWxDb3JyZW9NYXJrZXRpbmcgOmlzKC5mb3JtLXRleHQsIC5jb3Jy
ZW8tbWFya2V0aW5nLWZpbGUtaGVscCwKICAgIC5jb3JyZW8tbWFya2V0aW5nLW1haWwtbm90ZSwg
LmNvcnJlby1tYXJrZXRpbmctZmlsZXMgPiBzcGFuKSB7CiAgICBmb250LXNpemU6IDExcHg7CiAg
ICBsaW5lLWhlaWdodDogMS40NTsKfQoKI21vZGFsQ29ycmVvTWFya2V0aW5nIDppcyhhLCBbZGF0
YS1jb252b2NhdG9yaWEtbGltcGlhcl0pIHsKICAgIGZvbnQtc2l6ZTogMTJweDsKICAgIGxpbmUt
aGVpZ2h0OiAxLjQ1Owp9CgojbW9kYWxDb3JyZW9NYXJrZXRpbmcgI2NvcnJlb19tYXJrZXRpbmdf
YWRqdW50b3M6OmZpbGUtc2VsZWN0b3ItYnV0dG9uIHsKICAgIGZvbnQtZmFtaWx5OiBpbmhlcml0
OwogICAgZm9udC1zaXplOiBpbmhlcml0OwogICAgZm9udC13ZWlnaHQ6IGluaGVyaXQ7Cn0K
IMPE_TIPOGRAFIA
 [[ "$(hash "$TMP/block.css")" == '63fb404d09448f75f8453787b0b8124ffa2fe66f9e980b5e326589d34e9340c9' ]] || fail 'Bloque tipográfico alterado.'
 php -r '$s=file_get_contents($argv[1]).file_get_contents($argv[2]);file_put_contents($argv[3],$s);' "$TMP/normal.css" "$TMP/block.css" "$TMP/stage.css"
 [[ "$(hash "$TMP/stage.css")" == "$EXPECTED" ]] || fail 'Resultado distinto al revisado.'
 if [[ "$EOL" == CRLF ]];then php -r '$p=$argv[1];file_put_contents($p,str_replace("\n","\r\n",file_get_contents($p)));' "$TMP/stage.css";fi
 chmod --reference="$TMP/original.css" "$TMP/stage.css"
fi
no_links "$LIVE/$FILE";[[ "$(hash "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'CSS cambió durante la revisión.'
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. 1 archivo CSS. SITIO INTACTO. Sin SQL ni envíos.';exit 0;fi
AFTER="$(hash "$TMP/stage.css")"
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/correo-tipografia-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
 cp -p -- "$TMP/original.css" "$BACKUP/original.css"
 [[ "$(hash "$BACKUP/original.css")" == "$BEFORE" ]] || fail 'Respaldo inconsistente.'
 printf '%s\n' "$VERSION" > "$BACKUP/version.txt";printf '%s\n' "$BEFORE" > "$BACKUP/original.sha256";printf '%s\n' "$AFTER" > "$BACKUP/publicado.sha256"
fi
[[ ! -e "$INSTALL" && ! -L "$INSTALL" ]] || fail 'Temporal de instalación inesperado.'
INSTALL_CREATED=1;cp -p -- "$TMP/stage.css" "$INSTALL"
no_links "$LIVE/$FILE";[[ "$(hash "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'CSS cambió antes de instalar.'
WRITING=1;mv -f -- "$INSTALL" "$LIVE/$FILE"
[[ "$(hash "$LIVE/$FILE")" == "$AFTER" ]] || fail 'CSS instalado distinto.'
WRITING=0
echo "OK: $MODE terminado. Sólo $FILE. Sin cambios funcionales, BD ni credenciales."
if [[ "$MODE" == --apply ]];then echo "Respaldo: $BACKUP";printf 'Rollback: bash %q --restore %q\n' "$(realpath "$0")" "$BACKUP";fi
