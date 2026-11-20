#!/usr/bin/env python3
"""Comprueba y suscribe la app propia a la cuenta de prueba, sin enviar mensajes."""
import argparse
import hashlib
import hmac
import json
from pathlib import Path
import re
import shutil
import sys
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, build_opener

from configurar_whatsapp_privado import NoRedirect, read_state

PERMISSIONS = ('whatsapp_business_management', 'whatsapp_business_messaging')


class Graph:
    def __init__(self, version):
        if not re.fullmatch(r'v[0-9]{1,3}\.0', version):
            raise RuntimeError('VERSION_INVALIDA: revisa graph_version en la configuración privada.')
        self.base = 'https://graph.facebook.com/'+version+'/'

    def request(self, path, auth, query=None, method='GET'):
        query = dict(query or {})
        url = self.base+path
        body = None
        if method == 'GET':
            url += '?'+urlencode(query)
        elif method == 'POST':
            body = urlencode(query).encode('ascii')
        else:
            raise RuntimeError('METODO_NO_PERMITIDO')
        request = Request(url, data=body, method=method, headers={
            'Authorization':'Bearer '+auth, 'User-Agent':'IMPE-WhatsApp-Link/1.0',
            'Content-Type':'application/x-www-form-urlencoded'})
        try:
            with build_opener(NoRedirect()).open(request, timeout=25) as response:
                status = response.status
                raw = response.read(1024*1024)
        except HTTPError as error:
            status = error.code
            raw = error.read(1024*1024)
        except (URLError, OSError, TimeoutError):
            raise RuntimeError('CONEXION_NO_CONFIRMADA: ejecuta --check antes de repetir cualquier operación.')
        try:
            data = json.loads(raw)
        except (ValueError, UnicodeError):
            raise RuntimeError('RESPUESTA_INVALIDA: no se muestra el contenido recibido.')
        if not isinstance(data, dict):
            raise RuntimeError('RESPUESTA_INVALIDA')
        if status != 200 or 'error' in data:
            error = data.get('error') if isinstance(data.get('error'), dict) else {}
            code = int(error.get('code') or 0)
            subcode = int(error.get('error_subcode') or 0)
            # No exponer URLs, tokens, appsecret_proof ni mensajes originales de Meta.
            raise RuntimeError('META_RECHAZO: HTTP '+str(status)+' codigo '+str(code)+' subcodigo '+str(subcode))
        return data


def applications(response):
    rows = response.get('data')
    if not isinstance(rows, list):
        raise RuntimeError('LISTA_APLICACIONES_INVALIDA')
    ids = set()
    for row in rows:
        if not isinstance(row, dict):
            raise RuntimeError('LISTA_APLICACIONES_INVALIDA')
        app = row.get('whatsapp_business_api_data', row)
        app_id = str(app.get('id', '')) if isinstance(app, dict) else ''
        if not re.fullmatch(r'[0-9]{1,80}', app_id):
            raise RuntimeError('ID_APLICACION_INVALIDO')
        ids.add(app_id)
    if response.get('paging', {}).get('next'):
        raise RuntimeError('LISTA_PAGINADA: revisar todas las suscripciones antes de modificar.')
    return ids


def link(config, app_id, account_id, phone_id, subscribe=False, graph=None):
    for value in (app_id, account_id, phone_id):
        if not re.fullmatch(r'[0-9]{1,80}', value):
            raise RuntimeError('IDENTIFICADOR_INVALIDO')
    token = config.get('access_token', '')
    secret = config.get('app_secret', '')
    if not isinstance(token, str) or not isinstance(secret, str) or not token or not re.fullmatch(r'[0-9a-fA-F]{32}', secret):
        raise RuntimeError('CONFIGURACION_INCOMPLETA: revisa Access Token y App Secret en el archivo privado.')
    graph = graph or Graph(config.get('graph_version', 'v25.0'))
    try:
        debug = graph.request('debug_token', app_id+'|'+secret, {'input_token':token})
    except RuntimeError as error:
        raise RuntimeError('NO_SE_PUDO_VALIDAR_EL_TOKEN_PARA_ESTA_APP. '+str(error))
    data = debug.get('data', {})
    if not isinstance(data, dict) or data.get('is_valid') is not True:
        raise RuntimeError('TOKEN_INVALIDO_O_VENCIDO: genera un token vigente para tu aplicación.')
    if str(data.get('app_id', '')) != app_id:
        raise RuntimeError('TOKEN_DE_OTRA_APLICACION: genera el token para Sistema Red Educativa. No se modificó la suscripción.')
    print('TOKEN_APP_OK: '+app_id)
    scopes = data.get('scopes') or []
    if not isinstance(scopes, list):
        raise RuntimeError('PERMISOS_NO_CONFIRMADOS: revisar el token de la aplicación.')
    for permission in PERMISSIONS:
        if permission not in scopes:
            raise RuntimeError('PERMISO_FALTANTE: '+permission+'. No se modificó la suscripción.')
    proof = hmac.new(secret.encode(), token.encode(), hashlib.sha256).hexdigest()
    numbers = graph.request(account_id+'/phone_numbers', token, {'fields':'id', 'limit':'100', 'appsecret_proof':proof})
    rows = numbers.get('data')
    if not isinstance(rows, list) or not any(isinstance(row, dict) and str(row.get('id')) == phone_id for row in rows):
        raise RuntimeError('NUMERO_NO_CONFIRMADO_EN_LA_CUENTA: revisar identificadores y permisos.')
    print('NUMERO_CUENTA_OK: '+phone_id)
    params = {'limit':'100', 'appsecret_proof':proof}
    before = applications(graph.request(account_id+'/subscribed_apps', token, params))
    print('APPS_ANTES: '+', '.join(sorted(before)))
    if app_id in before:
        print('SUSCRIPCION_OK: tu aplicación ya está vinculada; no se hicieron cambios.')
        return True
    if not subscribe:
        print('SUSCRIPCION_FALTANTE: usar --subscribe para añadir tu aplicación.')
        return False
    result = graph.request(account_id+'/subscribed_apps', token, {'appsecret_proof':proof}, method='POST')
    if result.get('success') is not True:
        raise RuntimeError('SUSCRIPCION_NO_CONFIRMADA: consulta --check antes de repetir.')
    after = applications(graph.request(account_id+'/subscribed_apps', token, params))
    print('APPS_DESPUES: '+', '.join(sorted(after)))
    if app_id not in after or not before.issubset(after):
        raise RuntimeError('SUSCRIPCION_NO_CONFIRMADA: revisar las aplicaciones suscritas; no se ejecutaron bajas.')
    print('SUSCRIPCION_OK: tu aplicación está vinculada. No se enviaron mensajes ni se retiraron otras aplicaciones.')
    return True


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--app-id', required=True)
    parser.add_argument('--account-id', required=True)
    parser.add_argument('--phone-id', required=True)
    parser.add_argument('--php', default=shutil.which('php'))
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check', action='store_true')
    action.add_argument('--subscribe', action='store_true')
    args = parser.parse_args()
    try:
        if not args.php:
            raise RuntimeError('PHP_CLI_NO_DISPONIBLE')
        state = read_state(Path(args.site).resolve(strict=True), args.php)
        config = dict(state['config'])
        config.update(state['environment'])
        return 0 if link(config, args.app_id, args.account_id, args.phone_id, args.subscribe) else 2
    except RuntimeError as error:
        print(str(error), file=sys.stderr)
        return 1
    except (Exception, KeyboardInterrupt):
        print('NO_COMPLETADO: no se muestran datos privados. Ejecuta --check para conocer el estado.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
