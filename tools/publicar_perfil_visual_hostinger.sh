#!/usr/bin/env bash
# Sólo presentación de avatares de perfil y selector nativo. Sin BD ni usuarios reales.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='perfil-avatar-visual-v1'
FILE='public/css/global.css'
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
TMP="$(mktemp -d "$SCRATCH/impe-perfil-visual.XXXXXX")"
LOCK="$(dirname "$LIVE")/.impe-correos-deploy.lock";LOCKED=0;WRITING=0;INSTALL_CREATED=0
INSTALL="$LIVE/$FILE.impe-perfil-visual-$$"
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
    94f7be439c6ae323644b4dfaef024aa43b268aa966ebf34145d8c7e7af49c636) EXPECTED='9669a5ae4f0a75ab549353fcfb9b485290343eb390829a01b2659f956d4dcbf8';;
    9669a5ae4f0a75ab549353fcfb9b485290343eb390829a01b2659f956d4dcbf8) echo 'OK: ya aplicado. Ningún archivo reemplazado.';exit 0;;
    *) fail 'CSS personalizado o incompatible. SITIO INTACTO.';;
 esac
 base64 --decode > "$TMP/block.css" <<'IMPE_TIPOGRAFIA'
LyogUGVyZmlsOiBtaXNtbyBjZW50cmFkbyBlbiBlbCBlbmNhYmV6YWRvIHkgbGEgdGFyamV0YSBs
YXRlcmFsLCBzaW4gY2FtYmlhcgogICBsYXMgZGltZW5zaW9uZXMgcHJvcGlhcyBkZWwgYXZhdGFy
IG5pIGxhIGzDs2dpY2EgZGUgZm90by9pbmljaWFsZXMuICovCi50b3BiYXItdXNlci1hdmF0YXIu
c3lzdGVtLWF2YXRhciwKLnNpZGViYXItdXNlciA+IC5zeXN0ZW0tYXZhdGFyIHsKICAgIGRpc3Bs
YXk6IGlubGluZS1mbGV4OwogICAgYWxpZ24taXRlbXM6IGNlbnRlcjsKICAgIGp1c3RpZnktY29u
dGVudDogY2VudGVyOwogICAgcGFkZGluZzogMDsKICAgIG1hcmdpbjogMDsKICAgIGZvbnQtZmFt
aWx5OiBpbmhlcml0OwogICAgZm9udC1zaXplOiAxNHB4OwogICAgZm9udC13ZWlnaHQ6IDcwMDsK
ICAgIGxpbmUtaGVpZ2h0OiAxOwogICAgdGV4dC1hbGlnbjogY2VudGVyOwogICAgdGV4dC1vdmVy
ZmxvdzogY2xpcDsKICAgIHdoaXRlLXNwYWNlOiBub3dyYXA7Cn0KCi50b3BiYXItdXNlci1hdmF0
YXIuc3lzdGVtLWF2YXRhciA+IC5zeXN0ZW0tYXZhdGFyLWltYWdlLAouc2lkZWJhci11c2VyID4g
LnN5c3RlbS1hdmF0YXIgPiAuc3lzdGVtLWF2YXRhci1pbWFnZSB7CiAgICBkaXNwbGF5OiBibG9j
azsKICAgIGZsZXg6IDAgMCAxMDAlOwogICAgbWluLXdpZHRoOiAwOwogICAgd2lkdGg6IDEwMCU7
CiAgICBoZWlnaHQ6IDEwMCU7CiAgICBib3JkZXI6IDA7CiAgICBvYmplY3QtZml0OiBjb3ZlcjsK
ICAgIG9iamVjdC1wb3NpdGlvbjogY2VudGVyOwp9CgojbW9kYWxNaVBlcmZpbCBbZGF0YS1teS1w
cm9maWxlLXByZXZpZXddID4gc3Ryb25nIHsKICAgIGRpc3BsYXk6IGJsb2NrOwogICAgbWFyZ2lu
OiAwOwogICAgZm9udC1zaXplOiBpbmhlcml0OwogICAgZm9udC13ZWlnaHQ6IDYwMDsKICAgIGxp
bmUtaGVpZ2h0OiAxOwogICAgdGV4dC1hbGlnbjogY2VudGVyOwp9CgojbW9kYWxNaVBlcmZpbCBb
ZGF0YS1teS1wcm9maWxlLXByZXZpZXddID4gaW1nIHsKICAgIGRpc3BsYXk6IGJsb2NrOwogICAg
d2lkdGg6IDEwMCU7CiAgICBoZWlnaHQ6IDEwMCU7CiAgICBvYmplY3QtZml0OiBjb3ZlcjsKICAg
IG9iamVjdC1wb3NpdGlvbjogY2VudGVyOwp9CgovKiBDb25zZXJ2YXIgZWwgaW5wdXQgbmF0aXZv
IHkgc3UgZm9jbzsgYWxpbmVhciBib3TDs24geSBub21icmUgYSA0NCBweC4gKi8KI21vZGFsTWlQ
ZXJmaWwgI21pX3BlcmZpbF9mb3RvIHsKICAgIG1pbi13aWR0aDogMDsKICAgIG1heC13aWR0aDog
MTAwJTsKICAgIGhlaWdodDogNDRweDsKICAgIG1pbi1oZWlnaHQ6IDQ0cHg7CiAgICBwYWRkaW5n
OiAwIDEycHggMCAwOwogICAgZm9udC1mYW1pbHk6IGluaGVyaXQ7CiAgICBmb250LXNpemU6IDEy
cHg7CiAgICBsaW5lLWhlaWdodDogNDJweDsKICAgIG92ZXJmbG93OiBoaWRkZW47CiAgICB0ZXh0
LW92ZXJmbG93OiBlbGxpcHNpczsKICAgIHdoaXRlLXNwYWNlOiBub3dyYXA7Cn0KCiNtb2RhbE1p
UGVyZmlsICNtaV9wZXJmaWxfZm90bzo6ZmlsZS1zZWxlY3Rvci1idXR0b24gewogICAgaGVpZ2h0
OiA0MnB4OwogICAgbWFyZ2luOiAwIDEycHggMCAwOwogICAgcGFkZGluZzogMCAxNHB4OwogICAg
Ym9yZGVyOiAwOwogICAgYm9yZGVyLXJpZ2h0OiAxcHggc29saWQgdmFyKC0tY29sb3ItYm9yZGVy
KTsKICAgIGJhY2tncm91bmQtY29sb3I6ICNmOGZhZmM7CiAgICBjb2xvcjogaW5oZXJpdDsKICAg
IGZvbnQ6IGluaGVyaXQ7CiAgICBsaW5lLWhlaWdodDogNDJweDsKICAgIHZlcnRpY2FsLWFsaWdu
OiBtaWRkbGU7Cn0KCg==
IMPE_TIPOGRAFIA
 [[ "$(hash "$TMP/block.css")" == '749aeda63e6dcc7ed533b55be38f5a9b80d7a9b1e973b08868f3080a841154c0' ]] || fail 'Bloque visual alterado.'
 php -r '$s=file_get_contents($argv[1]);$n=".profile-photo-viewer-dialog {";if(substr_count($s,$n)!==1)exit(2);file_put_contents($argv[3],str_replace($n,file_get_contents($argv[2]).$n,$s));' "$TMP/normal.css" "$TMP/block.css" "$TMP/stage.css"
 [[ "$(hash "$TMP/stage.css")" == "$EXPECTED" ]] || fail 'Resultado distinto al revisado.'
 if [[ "$EOL" == CRLF ]];then php -r '$p=$argv[1];file_put_contents($p,str_replace("\n","\r\n",file_get_contents($p)));' "$TMP/stage.css";fi
 chmod --reference="$TMP/original.css" "$TMP/stage.css"
fi
no_links "$LIVE/$FILE";[[ "$(hash "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'CSS cambió durante la revisión.'
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. 1 archivo CSS. SITIO INTACTO. Sin SQL ni envíos.';exit 0;fi
AFTER="$(hash "$TMP/stage.css")"
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/perfil-avatar-visual-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
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
