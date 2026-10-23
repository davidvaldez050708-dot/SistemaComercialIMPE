#!/usr/bin/env bash
# Sólo estilos de Indicadores Clave de Marketing. Sin BD, red ni credenciales.
set -euo pipefail
MODE="${1:---check}"
[[ ( "$#" -le 1 && ( "$MODE" == --check || "$MODE" == --apply ) ) || ( "$MODE" == --restore && "$#" == 2 ) ]] || { echo 'Uso: --check | --apply | --restore RUTA' >&2;exit 2; }
VERSION='marketing-estilo-analista-v1'
FILE='public/css/convocatorias.css'
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
TMP="$(mktemp -d "$SCRATCH/impe-marketing-estilo.XXXXXX")"
LOCK="$(dirname "$LIVE")/.impe-correos-deploy.lock";LOCKED=0;WRITING=0;INSTALL_CREATED=0
INSTALL="$LIVE/$FILE.impe-marketing-estilo-$$"
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
    3f19c7058b89dc471f13db3d69d4e349c0869ed6233b13d7aaa3d6b785dd2d67) EXPECTED='36c0b608532a684a393f856f3c4c8f141bd0962d3dce347ae24ab04833c82b45';;
    36c0b608532a684a393f856f3c4c8f141bd0962d3dce347ae24ab04833c82b45) echo 'OK: ya aplicado. Ningún archivo reemplazado.';exit 0;;
    *) fail 'CSS personalizado o incompatible. SITIO INTACTO.';;
 esac
 base64 --decode > "$TMP/block.css" <<'IMPE_MARKETING_ESTILO'
LyogSW5kaWNhZG9yZXMgZGUgTWFya2V0aW5nOiBkZWNsYXJhY2lvbmVzIHZpc3VhbGVzIGRlIGRh
c2hib2FyZF9hbmFsaXN0YV9ib2NldG8uY3NzCiAgIHkgZGFzaGJvYXJkX2FuYWxpc3RhX2JvY2V0
b19hanVzdGVzLmNzcy4gU8OzbG8gc2UgYWRhcHRhIGxhIHJlamlsbGEgYSBjaW5jbyBtw6l0cmlj
YXMuICovCi5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgewogICAgbWluLXdpZHRoOiAwOwogICAg
bWF4LXdpZHRoOiAxMDAlOwogICAgbWluLWhlaWdodDogMTMwcHg7CiAgICBwYWRkaW5nOiAxOXB4
IDIwcHg7CiAgICBkaXNwbGF5OiBncmlkOwogICAgZ3JpZC10ZW1wbGF0ZS1jb2x1bW5zOiAxODVw
eCByZXBlYXQoNSwgbWlubWF4KDAsIDFmcikpIDE4MHB4OwogICAgYWxpZ24taXRlbXM6IGNlbnRl
cjsKICAgIGdhcDogMDsKICAgIGJvcmRlcjogMXB4IHNvbGlkICNkZmU2ZWY7CiAgICBib3JkZXIt
cmFkaXVzOiAxNHB4OwogICAgYmFja2dyb3VuZDogI2ZmZjsKICAgIGJveC1zaGFkb3c6IDAgOHB4
IDIycHggcmdiYSgyOCwgNDYsIDc0LCAuMDM1KTsKfQoubWFya2V0aW5nLWtleS1pbmRpY2F0b3Jz
ID4gKiB7IG1pbi13aWR0aDogMDsgfQoubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRp
bmcta2V5LWludHJvIHsgcGFkZGluZy1yaWdodDogMThweDsgfQoubWFya2V0aW5nLWtleS1pbmRp
Y2F0b3JzIGgyIHsKICAgIG1hcmdpbjogMCAwIDRweDsKICAgIGNvbG9yOiAjMTgzZjc2OwogICAg
Zm9udC1zaXplOiAxM3B4OwogICAgZm9udC13ZWlnaHQ6IDgwMDsKICAgIGxldHRlci1zcGFjaW5n
OiAuMDRlbTsKICAgIGxpbmUtaGVpZ2h0OiAxLjI7Cn0KLm1hcmtldGluZy1rZXktaW5kaWNhdG9y
cyAubWFya2V0aW5nLWtleS1pbnRybyBwIHsKICAgIG1hcmdpbjogMDsKICAgIGNvbG9yOiAjNzQ4
MTk4OwogICAgZm9udC1zaXplOiAxMXB4OwogICAgZm9udC13ZWlnaHQ6IDYwMDsKICAgIGxpbmUt
aGVpZ2h0OiAxLjU7Cn0KLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFya2V0aW5nLWtleS1p
dGVtIHsKICAgIG1pbi1oZWlnaHQ6IDgycHg7CiAgICBhbGlnbi1zZWxmOiBzdHJldGNoOwogICAg
cGFkZGluZzogNXB4IDE5cHggMDsKICAgIGJvcmRlci1sZWZ0OiAxcHggc29saWQgI2UyZTdlZjsK
fQoubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRpbmcta2V5LW1haW4geyBkaXNwbGF5
OiBibG9jazsgfQovKiBFbCBBbmFsaXN0YSB0YW1iacOpbiBvY3VsdGEgc3VzIGljb25vcyBkZWNv
cmF0aXZvcyBwYXJhIGFsaW5lYXIgbGFzIGNpZnJhcy4gKi8KLm1hcmtldGluZy1rZXktaW5kaWNh
dG9ycyAubWFya2V0aW5nLWtleS1pY29uIHsgZGlzcGxheTogbm9uZTsgfQoubWFya2V0aW5nLWtl
eS1pbmRpY2F0b3JzIHN0cm9uZyB7CiAgICBkaXNwbGF5OiBibG9jazsKICAgIG1hcmdpbjogMCAw
IDJweDsKICAgIGZvbnQtc2l6ZTogMjRweDsKICAgIGxpbmUtaGVpZ2h0OiAxOwogICAgZm9udC13
ZWlnaHQ6IDgwMDsKICAgIGNvbG9yOiAjMGUxNzI2OwogICAgd2hpdGUtc3BhY2U6IG5vd3JhcDsK
fQoubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIGgzIHsKICAgIG1hcmdpbjogMDsKICAgIGNvbG9y
OiAjNTg2ODdmOwogICAgZm9udC1zaXplOiAxMXB4OwogICAgZm9udC13ZWlnaHQ6IDcwMDsKICAg
IGxpbmUtaGVpZ2h0OiAxLjU7Cn0KLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFya2V0aW5n
LWtleS1iYXIgewogICAgd2lkdGg6IDEwMCU7CiAgICBoZWlnaHQ6IDdweDsKICAgIG1hcmdpbi10
b3A6IDlweDsKICAgIG92ZXJmbG93OiBoaWRkZW47CiAgICBib3JkZXItcmFkaXVzOiA5OTlweDsK
ICAgIGJhY2tncm91bmQ6ICNlNmViZjI7Cn0KLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFy
a2V0aW5nLWtleS1iYXIgPiBzcGFuIHsKICAgIGRpc3BsYXk6IGJsb2NrOwogICAgaGVpZ2h0OiAx
MDAlOwogICAgbWluLXdpZHRoOiAwOwogICAgYm9yZGVyLXJhZGl1czogaW5oZXJpdDsKICAgIGJh
Y2tncm91bmQ6ICM0YjhmZDc7Cn0KLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFya2V0aW5n
LWtleS1pdGVtID4gcCB7CiAgICBtYXJnaW46IDZweCAwIDA7CiAgICBjb2xvcjogIzhhOTZhODsK
ICAgIGZvbnQtc2l6ZTogMTBweDsKICAgIGZvbnQtd2VpZ2h0OiA2MDA7CiAgICBsaW5lLWhlaWdo
dDogMS41Owp9Ci5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXktYWN0aW9u
IHsKICAgIG1pbi1oZWlnaHQ6IDgycHg7CiAgICBwYWRkaW5nLWxlZnQ6IDIwcHg7CiAgICBkaXNw
bGF5OiBmbGV4OwogICAgYWxpZ24taXRlbXM6IGNlbnRlcjsKICAgIGp1c3RpZnktY29udGVudDog
ZmxleC1lbmQ7CiAgICBib3JkZXItbGVmdDogMXB4IHNvbGlkICNlMmU3ZWY7Cn0KLm1hcmtldGlu
Zy1rZXktaW5kaWNhdG9ycyAubWFya2V0aW5nLWtleS1kZXRhaWxzIHsKICAgIG1pbi1oZWlnaHQ6
IDUycHg7CiAgICBwYWRkaW5nOiAwIDE0cHg7CiAgICBkaXNwbGF5OiBpbmxpbmUtZmxleDsKICAg
IGFsaWduLWl0ZW1zOiBjZW50ZXI7CiAgICBnYXA6IDEwcHg7CiAgICBib3JkZXI6IDFweCBzb2xp
ZCAjZDllNGYyOwogICAgYm9yZGVyLXJhZGl1czogMTBweDsKICAgIGJhY2tncm91bmQ6ICNmOGZi
ZmY7CiAgICBjb2xvcjogIzE3NDc4YzsKICAgIGZvbnQtc2l6ZTogMTFweDsKICAgIGZvbnQtd2Vp
Z2h0OiA4MDA7CiAgICBsaW5lLWhlaWdodDogMS4zOwogICAgdGV4dC1kZWNvcmF0aW9uOiBub25l
Owp9Ci5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXktZGV0YWlscyA+IGk6
Zmlyc3QtY2hpbGQgewogICAgd2lkdGg6IDMwcHg7CiAgICBoZWlnaHQ6IDMwcHg7CiAgICBmbGV4
OiAwIDAgMzBweDsKICAgIGRpc3BsYXk6IGdyaWQ7CiAgICBwbGFjZS1pdGVtczogY2VudGVyOwog
ICAgYm9yZGVyLXJhZGl1czogOHB4OwogICAgYmFja2dyb3VuZDogI2VkZjRmYzsKfQoubWFya2V0
aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRpbmcta2V5LWRldGFpbHM6aG92ZXIgeyBiYWNrZ3Jv
dW5kOiAjZjFmNmZkOyB9Ci5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXkt
ZGV0YWlsczpmb2N1cy12aXNpYmxlIHsKICAgIG91dGxpbmU6IDJweCBzb2xpZCAjMTc0NzhjOwog
ICAgb3V0bGluZS1vZmZzZXQ6IDNweDsKfQpAbWVkaWEgKG1pbi13aWR0aDogMTQwMHB4KSBhbmQg
KG1heC13aWR0aDogMTU5OS45OHB4KSB7CiAgICAubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIHsg
Z3JpZC10ZW1wbGF0ZS1jb2x1bW5zOiAxNjVweCByZXBlYXQoNSwgbWlubWF4KDAsIDFmcikpIDE1
NXB4OyB9CiAgICAubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRpbmcta2V5LWl0ZW0g
eyBwYWRkaW5nLWxlZnQ6IDEycHg7IHBhZGRpbmctcmlnaHQ6IDEycHg7IH0KfQovKiBDaW5jbyBt
w6l0cmljYXMgcmVxdWllcmVuIGNvbHVtbmFzIG3DoXMgZXN0cmVjaGFzOyBsYSBlc2NhbGEgdGlw
b2dyw6FmaWNhIG5vIGNhbWJpYS4gKi8KQG1lZGlhIChtaW4td2lkdGg6IDEyMDBweCkgYW5kICht
YXgtd2lkdGg6IDEzOTkuOThweCkgewogICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyB7CiAg
ICAgICAgZ3JpZC10ZW1wbGF0ZS1jb2x1bW5zOiAxMjVweCByZXBlYXQoMywgbWlubWF4KDAsIDFm
cikpIG1pbm1heCgxMjJweCwgMS4xNWZyKSBtaW5tYXgoMCwgMWZyKSAxMzVweDsKICAgIH0KICAg
IC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXktaXRlbSB7IHBhZGRpbmct
bGVmdDogOHB4OyBwYWRkaW5nLXJpZ2h0OiA4cHg7IH0KICAgIC5tYXJrZXRpbmcta2V5LWluZGlj
YXRvcnMgLm1hcmtldGluZy1rZXktYWN0aW9uIHsgcGFkZGluZy1sZWZ0OiA4cHg7IH0KICAgIC5t
YXJrZXRpbmcta2V5LWluZGljYXRvcnMgaDMgeyBtaW4taGVpZ2h0OiAzM3B4OyB9Cn0KQG1lZGlh
IChtYXgtd2lkdGg6IDExOTkuOThweCkgewogICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyB7
IGdyaWQtdGVtcGxhdGUtY29sdW1uczogcmVwZWF0KDMsIG1pbm1heCgwLCAxZnIpKTsgZ2FwOiAx
MnB4IDA7IH0KICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXktaW50
cm8geyBncmlkLWNvbHVtbjogMSAvIC0xOyBwYWRkaW5nOiAwIDAgOHB4OyB9CiAgICAubWFya2V0
aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRpbmcta2V5LWFjdGlvbiB7CiAgICAgICAgZ3JpZC1j
b2x1bW46IDEgLyAtMTsKICAgICAgICBtaW4taGVpZ2h0OiBhdXRvOwogICAgICAgIHBhZGRpbmc6
IDlweCAwIDA7CiAgICAgICAganVzdGlmeS1jb250ZW50OiBmbGV4LXN0YXJ0OwogICAgICAgIGJv
cmRlci1sZWZ0OiAwOwogICAgICAgIGJvcmRlci10b3A6IDFweCBzb2xpZCAjZTdlYmYxOwogICAg
fQogICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAubWFya2V0aW5nLWtleS1pdGVtOm50aC1v
Zi10eXBlKDNuICsgMSkgeyBib3JkZXItbGVmdDogMDsgfQp9CkBtZWRpYSAobWF4LXdpZHRoOiA3
NjcuOThweCkgewogICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyB7IGdyaWQtdGVtcGxhdGUt
Y29sdW1uczogcmVwZWF0KDIsIG1pbm1heCgwLCAxZnIpKTsgbWluLWhlaWdodDogMTE4cHg7IHBh
ZGRpbmc6IDE0cHg7IH0KICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgaDIgeyBmb250LXNp
emU6IDEycHg7IH0KICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXkt
aW50cm8gcCB7IGZvbnQtc2l6ZTogMTBweDsgfQogICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9y
cyAubWFya2V0aW5nLWtleS1pdGVtOm50aC1vZi10eXBlKG4pIHsgbWluLWhlaWdodDogNzJweDsg
cGFkZGluZzogM3B4IDE4cHggMDsgYm9yZGVyLWxlZnQ6IDFweCBzb2xpZCAjZTJlN2VmOyB9CiAg
ICAubWFya2V0aW5nLWtleS1pbmRpY2F0b3JzIC5tYXJrZXRpbmcta2V5LWl0ZW06bnRoLW9mLXR5
cGUob2RkKSB7IGJvcmRlci1sZWZ0OiAwOyB9CiAgICAubWFya2V0aW5nLWtleS1pbmRpY2F0b3Jz
IHN0cm9uZyB7IGZvbnQtc2l6ZTogMjFweDsgfQogICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9y
cyBoMyB7IGZvbnQtc2l6ZTogMTBweDsgfQogICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyAu
bWFya2V0aW5nLWtleS1pdGVtID4gcCB7IG1hcmdpbi10b3A6IDVweDsgZm9udC1zaXplOiA5cHg7
IH0KICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXktZGV0YWlscyB7
IG1pbi1oZWlnaHQ6IDQ4cHg7IGZvbnQtc2l6ZTogMTBweDsgfQp9CkBtZWRpYSAobWF4LXdpZHRo
OiAzODBweCkgewogICAgLm1hcmtldGluZy1rZXktaW5kaWNhdG9ycyB7IHBhZGRpbmc6IDEycHg7
IH0KICAgIC5tYXJrZXRpbmcta2V5LWluZGljYXRvcnMgLm1hcmtldGluZy1rZXktaXRlbTpudGgt
b2YtdHlwZShuKSB7IHBhZGRpbmctbGVmdDogOXB4OyBwYWRkaW5nLXJpZ2h0OiA5cHg7IH0KfQoK
IMPE_MARKETING_ESTILO
 [[ "$(hash "$TMP/block.css")" == '35ac0a793e4603c7210e7440b7eb21af0a40c597e2990a7643c48084f0a37034' ]] || fail 'Bloque visual alterado.'
 php -r '$s=file_get_contents($argv[1]);$a=strpos($s,"/* Panel único de indicadores:");$b=$a===false?false:strpos($s,".marketing-welcome-shell {",$a);if($a===false||$b===false)exit(2);file_put_contents($argv[3],substr($s,0,$a).file_get_contents($argv[2]).substr($s,$b));' "$TMP/normal.css" "$TMP/block.css" "$TMP/stage.css"
 [[ "$(hash "$TMP/stage.css")" == "$EXPECTED" ]] || fail 'Resultado distinto al revisado.'
 if [[ "$EOL" == CRLF ]];then php -r '$p=$argv[1];file_put_contents($p,str_replace("\n","\r\n",file_get_contents($p)));' "$TMP/stage.css";fi
 chmod --reference="$TMP/original.css" "$TMP/stage.css"
fi
no_links "$LIVE/$FILE";[[ "$(hash "$LIVE/$FILE")" == "$BEFORE" ]] || fail 'CSS cambió durante la revisión.'
if [[ "$MODE" == --check ]];then echo 'OK: revisión compatible. 1 archivo CSS. SITIO INTACTO. Sin SQL ni envíos.';exit 0;fi
AFTER="$(hash "$TMP/stage.css")"
if [[ "$MODE" == --apply ]];then
 mkdir -p "$BACKUPS";BACKUP="$BACKUPS/marketing-estilo-$(date +%Y%m%d-%H%M%S)-$$";(umask 077;mkdir "$BACKUP")
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
