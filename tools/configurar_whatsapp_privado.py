#!/usr/bin/env python3
"""Configura WhatsApp por consola sin imprimir credenciales ni enviar mensajes."""
import argparse
import base64
import getpass
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import tempfile
import warnings
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener

FIELDS = ['access_token', 'verify_token', 'app_secret', 'graph_version']
PHP_READ = r'''
require $argv[1].'/config/private_config.php';
$path=impeRutaConfigPrivada('whatsapp.local.php');
$data=$path === null ? [] : require $path;
if(!is_array($data))exit(3);
$env=[];
foreach(['access_token','verify_token','app_secret','graph_version'] as $key){
 $v=getenv('WHATSAPP_'.strtoupper($key));
 if($v!==false && trim($v)!=='')$env[$key]=trim($v);
}
echo json_encode(['path'=>$path,'config'=>$data,'environment'=>$env],JSON_THROW_ON_ERROR);
'''


def read_state(site, php):
    if not (site/'index.php').is_file() or not (site/'config/private_config.php').is_file():
        raise RuntimeError('No se encontró la instalación del CRM y su lector privado.')
    result = subprocess.run([php, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r',
                             PHP_READ, str(site)], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20)
    if result.returncode or result.stderr:
        raise RuntimeError('No se pudo leer la configuración privada. No se modificó nada.')
    try:
        state = json.loads(result.stdout)
        if not isinstance(state['config'], (dict, list)):
            raise ValueError()
        if state['config'] == []:
            state['config'] = {}
        if not isinstance(state['config'], dict):
            raise ValueError()
        if any(key in state['config'] and not isinstance(state['config'][key], str) for key in FIELDS):
            raise ValueError()
        return state
    except (ValueError, KeyError):
        raise RuntimeError('La configuración privada no tiene el formato esperado.')


def outside(path, site):
    if path == site or site in path.parents:
        raise RuntimeError('La configuración debe guardarse fuera del sitio público.')


def destination(site):
    explicit = os.environ.get('IMPE_PRIVATE_CONFIG_DIR', '').strip()
    parent = Path(explicit).expanduser().resolve() if explicit else site.parent/'impe-private'
    outside(parent, site)
    dest = parent/'whatsapp.local.php'
    if dest.is_symlink() or (dest.exists() and not dest.is_file()):
        raise RuntimeError('La ruta privada es un enlace o no es un archivo regular.')
    return dest


def hidden(label, previous=''):
    with warnings.catch_warnings():
        warnings.simplefilter('error', getpass.GetPassWarning)
        value = getpass.getpass(label).strip()
    return value or previous


def configure(site, php):
    state = read_state(site, php)
    if state['environment']:
        raise RuntimeError('Hay valores WHATSAPP_* en el entorno. Actualiza esa fuente para evitar configuraciones contradictorias.')
    dest = destination(site)
    expected = dest.read_bytes() if dest.exists() else None
    config = dict(state['config'])
    print('Introduce los secretos únicamente aquí. Al pegarlos no aparecerán en pantalla.')
    config['access_token'] = hidden('Access Token de Meta (Enter conserva el actual): ', config.get('access_token', ''))
    config['app_secret'] = hidden('App Secret de Meta (Enter conserva el actual): ', config.get('app_secret', ''))
    if not re.fullmatch(r'[^\s\x00-\x1f\x7f]{20,4096}', config['access_token']):
        raise RuntimeError('El token está vacío o tiene un formato no válido.')
    if not re.fullmatch(r'[0-9a-fA-F]{32}', config['app_secret']):
        raise RuntimeError('App Secret debe tener 32 caracteres hexadecimales. No es el ID de la aplicación.')
    config['verify_token'] = config.get('verify_token') or secrets.token_urlsafe(32)
    config['graph_version'] = config.get('graph_version') or 'v25.0'
    if not re.fullmatch(r'v[0-9]{1,3}\.0', config['graph_version']):
        raise RuntimeError('La versión de Graph API de la configuración actual no es válida.')
    # No cambia la plantilla existente ni presupone que hello_world esté disponible.
    encoded = base64.b64encode(json.dumps(config, ensure_ascii=False).encode('utf-8')).decode('ascii')
    content = ("<?php\n// Configuración privada de WhatsApp. No publicar ni compartir.\n"
               "return json_decode(base64_decode('"+encoded+"'), true);\n").encode('utf-8')
    if read_state(site, php) != state:
        raise RuntimeError('La configuración cambió mientras introducías los datos; se conserva.')
    dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    backup = None
    if expected is not None:
        backup = Path(tempfile.mkdtemp(prefix='whatsapp-respaldo-', dir=str(dest.parent)))
        os.chmod(backup, 0o700)
        copy = backup/'whatsapp.local.php'
        copy.write_bytes(expected)
        os.chmod(copy, 0o600)
        if copy.read_bytes() != expected:
            raise RuntimeError('No se pudo verificar el respaldo privado.')
    current = dest.read_bytes() if dest.exists() else None
    if current != expected:
        raise RuntimeError('El archivo privado cambió durante la configuración; se conserva.')
    fd, name = tempfile.mkstemp(prefix='.whatsapp-', suffix='.php', dir=str(dest.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        lint = subprocess.run([php, '-l', name], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=20)
        if lint.returncode:
            raise RuntimeError('La comprobación PHP no pasó; se conserva la versión anterior.')
        os.chmod(name, 0o600)
        os.replace(name, dest)
    finally:
        if os.path.exists(name):
            os.unlink(name)
    try:
        loaded = read_state(site, php)
        if loaded['path'] is None or Path(loaded['path']).resolve() != dest.resolve() or loaded['config'] != config:
            raise RuntimeError('El CRM no está leyendo el archivo generado. Se restaura la configuración anterior.')
    except BaseException:
        if dest.read_bytes() == content:
            if expected is None:
                dest.unlink()
            else:
                fd, recovery = tempfile.mkstemp(prefix='.whatsapp-restaurar-', dir=str(dest.parent))
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(expected)
                    stream.flush()
                    os.fsync(stream.fileno())
                os.chmod(recovery, 0o600)
                os.replace(recovery, dest)
        raise
    print('CONFIG_OK: credenciales guardadas fuera de public_html. No se enviaron mensajes.')
    if backup:
        print('RESPALDO_PRIVADO: '+str(backup))
    print('VERIFY_TOKEN_PARA_META: '+str(config['verify_token']))
    print('Copia ese código en Meta; no compartas la salida que lo contiene.')


def status(site, php):
    state = read_state(site, php)
    config = {'graph_version': 'v26.0'}
    config.update(state['config'])
    config.update(state['environment'])
    missing = [key for key in FIELDS if not str(config.get(key, '')).strip()]
    print('CONFIG_LOCAL: '+('LISTA' if not missing else 'FALTAN '+', '.join(missing)))
    print('FUENTE: '+('ENTORNO' if state['environment'] else 'ARCHIVO_PRIVADO' if state['path'] else 'SIN_CONFIGURACION'))
    return config, missing


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def probe_webhook(config, base_url):
    url = urlsplit(base_url)
    if url.scheme != 'https' or not url.hostname or url.username or url.password or url.query or url.fragment:
        raise RuntimeError('--base-url debe ser la dirección HTTPS del CRM sin consulta ni credenciales.')
    token = str(config.get('verify_token', ''))
    if not token:
        raise RuntimeError('Falta Verify Token para comprobar el webhook.')
    challenge = 'impe-check-'+secrets.token_hex(12)
    query = urlencode({'controller':'whatsappWebhook', 'action':'webhook', 'hub.mode':'subscribe',
                       'hub.verify_token':token, 'hub.challenge':challenge})
    request = Request(base_url.rstrip('/')+'/index.php?'+query, headers={'User-Agent':'IMPE-WhatsApp-Check/1.0'})
    try:
        with build_opener(NoRedirect()).open(request, timeout=20) as response:
            body = response.read(4096)
            if response.status != 200 or body != challenge.encode('ascii'):
                raise RuntimeError('El webhook no devolvió la verificación esperada. Revisa la ruta y la configuración web.')
    except HTTPError as error:
        raise RuntimeError('El webhook respondió HTTP '+str(error.code)+'. No se mostró el token ni la respuesta.')
    except (URLError, TimeoutError, OSError):
        raise RuntimeError('No se pudo conectar al webhook por HTTPS.')
    print('WEBHOOK_GET_OK: ruta pública y código de verificación correctos. Falta verificar recepción POST real de Meta.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--php', default=shutil.which('php'))
    parser.add_argument('--base-url')
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument('--configure', action='store_true')
    modes.add_argument('--check', action='store_true')
    modes.add_argument('--probe-webhook', action='store_true')
    args = parser.parse_args()
    if not args.php:
        parser.error('PHP CLI no está disponible.')
    try:
        site = Path(args.site).resolve(strict=True)
        if args.configure:
            if not sys.stdin.isatty():
                raise RuntimeError('Ejecuta este comando directamente en una terminal SSH interactiva.')
            configure(site, args.php)
        else:
            config, missing = status(site, args.php)
            if missing:
                return 1
            if args.probe_webhook:
                if not args.base_url:
                    raise RuntimeError('--probe-webhook requiere --base-url.')
                probe_webhook(config, args.base_url)
        return 0
    except RuntimeError as error:
        print('NO_COMPLETADO: '+str(error), file=sys.stderr)
        return 1
    except (OSError, ValueError, EOFError, KeyboardInterrupt, subprocess.SubprocessError, getpass.GetPassWarning):
        # Nunca imprimir excepciones originales: podrían contener configuración o URLs privadas.
        print('NO_COMPLETADO: revisa los datos, el acceso privado y la conexión. No compartas secretos.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
