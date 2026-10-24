"""Endpoint y autorización reales; sólo se habilita contra la BD efímera de CI."""
import http.cookiejar
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
if os.environ.get('APP_ENV') != 'testing' or not os.environ.get('IMPE_DB_NAME', '').startswith('impe_test_handly_aliados_historial_'):
    raise SystemExit('Requiere el entorno sintético de CI.')
with socket.socket() as sock:
    sock.bind(('127.0.0.1', 0))
    port = sock.getsockname()[1]
base = 'http://127.0.0.1:%d' % port
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
checks = 0

def request(path):
    try:
        response = client.open(base + path, timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.read().decode('utf-8'), response.headers

def check(value, message):
    global checks
    assert value, message
    checks += 1
    print('OK ' + message)

with tempfile.TemporaryFile() as log:
    env = dict(os.environ, IMPE_TEST_ALLOW_ROUTER='1')
    server = subprocess.Popen([os.environ.get('PHP_BINARY', 'php'), '-S', '127.0.0.1:%d' % port, '-t', str(ROOT), str(ROOT/'tests/aliados_historial_router.php')], cwd=ROOT, env=env, stdout=log, stderr=log)
    try:
        for attempt in range(100):
            try:
                request('/_session')
                break
            except OSError:
                time.sleep(.1)
        else:
            raise RuntimeError('No arrancó el router aislado.')
        before = json.loads(request('/_snapshot')[1])
        endpoint = '/index.php?controller=aliado&action=historial&'
        for role in ['cc', 'marketing']:
            request('/_session?role=' + role)
            for origin, identifier, expected in [('manual',before['manual_id'],2), ('institucional',10,1)]:
                status, raw, headers = request(endpoint + 'origen_tipo=%s&id=%d' % (origin,identifier))
                data = json.loads(raw)
                check(status == 200 and data['ok'] and len(data['historial']) == expected and data['origen_tipo'] == origin, '%s consulta %s por controlador HTTP' % (role,origin))
                check('no-store' in headers.get('Cache-Control', ''), 'historial privado sin caché %s/%s' % (role,origin))
        request('/_session?role=outside')
        for origin, identifier in [('manual',before['manual_id']), ('institucional',10)]:
            status, raw, _ = request(endpoint + 'origen_tipo=%s&id=%d' % (origin,identifier))
            check(status == 403 and not json.loads(raw)['ok'], 'Cuenta Clave ajena rechazada por HTTP ' + origin)
        for role, expected in [('nohistory',403), ('guest',401)]:
            request('/_session?role=' + role)
            check(request(endpoint + 'origen_tipo=manual&id=%d' % before['manual_id'])[0] == expected, 'permiso/sesión en controlador ' + role)
        request('/_session')
        check(request(endpoint + 'origen_tipo=desconocido&id=10')[0] == 422, 'origen inválido por HTTP')
        check(json.loads(request('/_snapshot')[1]) == before, 'consultas HTTP no insertan ni envían')
        print('PASS %d comprobaciones HTTP reales' % checks)
    finally:
        server.terminate()
        server.wait(timeout=15)
        log.seek(0)
        output = log.read().decode('utf-8', errors='replace')
        if 'Fatal error' in output or 'Warning:' in output:
            raise AssertionError(output)
