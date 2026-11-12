#!/usr/bin/env bash
set -euo pipefail
umask 077

SITIO="$HOME/domains/fundacionrededumex.org/public_html"
ORIG="$SITIO/app/services/EmbudoComercialV3AccesoControlado.php"
PRIVADO="$(dirname "$SITIO")/private-impe"
FLAG="$PRIVADO/ventas_v3_asesores.json"
DIR="$HOME/entregas-impe/ventas-v3-acceso-privado"
STAGE="$DIR/EmbudoComercialV3AccesoControlado.php"
RESP="$HOME/respaldos-impe/ventas-v3-asesores"
REV="30f9dcccbc5c709891e72ebb692afcd48c32e4e6"
TMP=""
FLAG_CREADO=0
PUBLICADO=0
CFG_ESPERADA='{"version":1,"habilitado":false,"audiencia":"asesores_rol_3_con_permiso"}'

limpiar() {
  if [[ -n "$TMP" && -d "$TMP" ]]; then
    rm -f -- "$TMP/comprobar.php"
    rmdir -- "$TMP" || true
  fi
  if [[ "$FLAG_CREADO" == 1 && "$PUBLICADO" == 0 && -f "$FLAG" && ! -L "$FLAG" ]]; then
    if [[ "$(cat "$FLAG")" == "$CFG_ESPERADA" ]]; then
      rm -f -- "$FLAG"
      echo 'Configuración de prueba desactivada retirada.'
    fi
  fi
}
trap limpiar EXIT

echo '=== PREPARANDO ACCESO PRIVADO V3 ==='
test -f "$SITIO/config/db_connection.php"
test -f "$ORIG"
test ! -L "$ORIG"
test ! -L "$PRIVADO"
[[ ! -e "$FLAG" && ! -L "$FLAG" ]] || { echo 'BLOQUEADO: ya existe configuración privada'; exit 2; }
mkdir -p "$DIR" "$RESP" "$PRIVADO"
chmod 700 "$RESP" "$PRIVADO"

curl -fLsS --max-time 30 \
  "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/$REV/app/services/EmbudoComercialV3AccesoControlado.php" \
  -o "$STAGE"

python3 - "$ORIG" "$STAGE" <<'PY'
import hashlib,sys

def blob(p):
    with open(p,'rb') as f: b=f.read()
    return hashlib.sha1(('blob %d\0' % len(b)).encode()+b).hexdigest()

actual=blob(sys.argv[1]); nuevo=blob(sys.argv[2])
if actual!='b6d88f2651739c7ff62a3d3be390330552160ddb':
    raise SystemExit('BLOQUEADO: versión de acceso en Hostinger diferente')
if nuevo!='480ce33e3622eb44116c3aed578c0a753378eaa9':
    raise SystemExit('BLOQUEADO: archivo nuevo con integridad incorrecta')
print('BASE_OK | acceso existente')
print('INTEGRIDAD_OK | acceso privado nuevo')
PY
php -l "$STAGE"

printf '%s\n' "$CFG_ESPERADA" > "$FLAG"
FLAG_CREADO=1
chmod 600 "$FLAG"

echo '=== VERIFICACIÓN PHP-FPM DE CONFIGURACIÓN PRIVADA ==='
TMP="$(mktemp -d "$SITIO/__impe_v3_priv_XXXXXXXX")"
chmod 755 "$TMP"
cat > "$TMP/comprobar.php" <<'PHP'
<?php
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
$ruta=dirname(__DIR__,2).'/private-impe/ventas_v3_asesores.json';
$datos=@file_get_contents($ruta);
$obj=is_string($datos)?json_decode($datos,true):null;
echo is_array($obj) && ($obj['version']??null)===1 &&
    ($obj['habilitado']??null)===false &&
    ($obj['audiencia']??null)==='asesores_rol_3_con_permiso'
    ? 'PRIVADO_LEIBLE_DESACTIVADO' : 'PRIVADO_NO_LEIBLE';
PHP
chmod 644 "$TMP/comprobar.php"
RESULTADO="$(curl -fLsS --max-time 20 \
  "https://fundacionrededumex.org/$(basename "$TMP")/comprobar.php" 2>&1)" || {
  echo 'BLOQUEADO: no se pudo validar HTTPS; el embudo sigue desactivado.'
  exit 3
}
if [[ "$RESULTADO" != 'PRIVADO_LEIBLE_DESACTIVADO' ]]; then
  echo "BLOQUEADO: PHP no puede leer la configuración privada ($RESULTADO)"
  exit 3
fi
printf 'PHP-FPM: %s\n' "$RESULTADO"

echo '=== PUBLICANDO SOLO CONTROL DE ACCESO (V3 APAGADA) ==='
BACKUP="$RESP/acceso_v3_antes_privado_$(date +%Y%m%d_%H%M%S)_$$.php"
python3 - "$ORIG" "$STAGE" "$BACKUP" <<'PY'
import hashlib,os,shutil,stat,sys,tempfile
oldpath,newpath,backup=sys.argv[1:]
def blob(path):
    with open(path,'rb') as f:b=f.read()
    return hashlib.sha1(('blob %d\0' % len(b)).encode()+b).hexdigest()
old='b6d88f2651739c7ff62a3d3be390330552160ddb'
new='480ce33e3622eb44116c3aed578c0a753378eaa9'
if blob(oldpath)!=old or blob(newpath)!=new or os.path.lexists(backup):
    raise SystemExit('BLOQUEADO: archivos cambiaron antes de publicar')
shutil.copy2(oldpath,backup)
os.chmod(backup,0o600)
if blob(backup)!=old:
    raise SystemExit('BLOQUEADO: respaldo no verificado')
modo=stat.S_IMODE(os.stat(oldpath).st_mode)
fd,tmp=tempfile.mkstemp(prefix='.impe-v3-priv-',dir=os.path.dirname(oldpath))
try:
    with os.fdopen(fd,'wb') as out,open(newpath,'rb') as inp:
        shutil.copyfileobj(inp,out)
        out.flush();os.fsync(out.fileno())
    os.chmod(tmp,modo)
    if blob(oldpath)!=old:
        raise SystemExit('BLOQUEADO: base cambió durante publicación')
    os.replace(tmp,oldpath)
    if blob(oldpath)!=new:
        raise SystemExit('BLOQUEADO: integridad posterior incorrecta; revisar respaldo')
finally:
    if os.path.exists(tmp):os.unlink(tmp)
print('PUBLICADO: exclusivamente EmbudoComercialV3AccesoControlado.php')
print('RESPALDO:',backup)
PY
php -l "$ORIG"
PUBLICADO=1
echo '=== V3 INSTALADA, TODAVÍA DESACTIVADA ==='
echo 'Siguiente fase: habilitar archivo privado tras comprobar operación.'
