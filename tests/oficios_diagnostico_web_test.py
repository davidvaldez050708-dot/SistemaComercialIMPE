"""Pruebas HTTP locales aisladas; no usan BD, navegador del usuario ni documentos."""
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time


ROOT = Path(__file__).resolve().parents[1]
TOOL = ROOT / 'tools/oficios_diagnostico_web.php'
PHP = os.environ.get('PHP_BIN') or shutil.which('php') or r'C:\xampp\php\php.exe'
FLAGS = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0


def php(*args, ok=True):
    result = subprocess.run([PHP, *map(str, args)], capture_output=True, text=True,
                            encoding='utf-8', timeout=10, creationflags=FLAGS)
    if ok:
        assert result.returncode == 0, result.stderr
    return result


def run():
    (ROOT / 'tmp').mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='oficios-web-qa-', dir=ROOT / 'tmp') as work:
        work = Path(work)
        crm, private, sessions = work / 'crm', work / 'private', work / 'sessions'
        for path in [crm / 'public', crm / 'app/services', private, sessions]:
            path.mkdir(parents=True, exist_ok=True)
        target = json.dumps(str(ROOT / 'app/services/OficioDocxPdfService.php'))
        (crm / 'app/services/OficioDocxPdfService.php').write_text(
            '<?php require ' + target + ';', encoding='utf-8')
        shutil.copyfile(TOOL, crm / 'public/disabled.php')
        # HTTPS simulado sólo por el router de pruebas; el endpoint no lee esta cabecera.
        router = work / 'router.php'
        router.write_text('<?php if (($_SERVER["HTTP_X_QA_TLS"]??"") === "1") '
                          '{$_SERVER["HTTPS"]="on";} return false;', encoding='utf-8')
        session_setup = work / 'session.php'
        session_setup.write_text('<?php session_id($argv[1]); session_start(); '
                                 '$_SESSION=["usuario_id"=>7,"rol_id"=>(int)$argv[2],'
                                 '"ultima_actividad"=>time()-(int)$argv[3]]; session_write_close();',
                                 encoding='utf-8')
        for sid, role, age in [('adminqa', 1, 0), ('userqa', 2, 0), ('expiredqa', 1, 1900)]:
            php('-d', f'session.save_path={sessions}', session_setup, sid, role, age)

        def activate():
            output = php(TOOL, '--activar', crm, private).stdout
            ident = re.search(r'oficios_diag_([a-f0-9]{24})\.php', output).group(1)
            token = re.search(r'no compartir\): ([a-f0-9]{64})', output).group(1)
            return ident, token

        def state_path(ident):
            return private / f'oficios-{ident}.json'

        def retire(ident):
            php(TOOL, '--retirar', crm, private, ident)
            assert not state_path(ident).exists()
            assert not (crm / 'public' / f'oficios_diag_{ident}.php').exists()

        assert php(TOOL, ok=False).returncode != 0
        assert php(TOOL, '--activar', crm, crm / 'public', ok=False).returncode != 0

        for disabled in [False, True]:
            with socket.socket() as port_socket:
                port_socket.bind(('127.0.0.1', 0))
                port = port_socket.getsockname()[1]
            command = [PHP, '-d', f'session.save_path={sessions}']
            if disabled:
                command += ['-d', 'disable_functions=proc_open']
            command += ['-S', f'127.0.0.1:{port}', '-t', str(crm), str(router)]
            server = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                      creationflags=FLAGS)
            try:
                for _ in range(100):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                            break
                    except OSError:
                        time.sleep(0.03)
                else:
                    raise AssertionError('El servidor HTTP de pruebas no inició.')

                def request(path, method='GET', body=None, sid='adminqa', tls=True):
                    conn = http.client.HTTPConnection('127.0.0.1', port, timeout=7)
                    headers = {'Content-Type': 'application/x-www-form-urlencoded'}
                    if sid:
                        headers['Cookie'] = f'PHPSESSID={sid}'
                    if tls:
                        headers['X-QA-TLS'] = '1'
                    conn.request(method, path, body, headers)
                    response = conn.getresponse()
                    result = response.status, response.read().decode('utf-8'), dict(response.getheaders())
                    conn.close()
                    return result

                ident, token = activate()
                endpoint = f'/public/oficios_diag_{ident}.php'
                assert request('/public/disabled.php')[0] == 404
                for sid in [None, 'userqa', 'expiredqa']:
                    assert request(endpoint, sid=sid)[0] == 403
                assert request(endpoint, tls=False)[0] == 403
                assert request(endpoint + '?token=' + token)[0] == 404
                status, html, headers = request(endpoint)
                assert status == 200 and 'name="token"' in html
                assert token not in html and str(crm) not in html and str(private) not in html
                assert 'no-store' in headers['Cache-Control']
                assert request(endpoint, 'POST', 'token=' + '0' * 64)[0] == 403
                assert request(endpoint, 'POST', f'token={token}&command=whoami')[0] == 422
                assert request(endpoint, 'POST', f'token={token}&probe=whoami')[0] == 422
                assert json.loads(state_path(ident).read_text())['enabled'] is True
                status, html, _ = request(endpoint, 'POST', f'token={token}&probe=1' if disabled else f'token={token}')
                assert status == 200 and 'Resultado PHP web' in html
                assert token not in html and str(crm) not in html and str(private) not in html
                assert 'cli-server' in html and 'Pendientes de prueba real' in html
                if disabled:
                    assert 'Bloqueada por PHP web' in html and 'No viable' in html
                else:
                    assert 'No solicitada; no se ejecutaron procesos.' in html
                saved = json.loads(state_path(ident).read_text())
                assert saved['enabled'] is False and saved['token_sha256'] == ''
                assert request(endpoint, 'POST', f'token={token}')[0] == 404
                retire(ident)

                if not disabled:
                    ident, token = activate()
                    endpoint = f'/public/oficios_diag_{ident}.php'
                    status, html, _ = request(endpoint, 'POST', f'token={token}&probe=1')
                    assert status == 200 and 'proceso fijo terminó con código 0.' in html, (status, html)
                    retire(ident)
                    ident, _ = activate()
                    state = json.loads(state_path(ident).read_text())
                    state['expires'] = time.time() - 1
                    state_path(ident).write_text(json.dumps(state))
                    assert request(f'/public/oficios_diag_{ident}.php')[0] == 404
                    retire(ident)
                    ident, _ = activate()
                    retire(ident)  # Retirada manual antes del primer uso.
            finally:
                server.terminate()
                server.wait(timeout=5)
        # Verificar el límite con un hijo fijo lento, sin añadir una operación HTTP.
        source = TOOL.read_text(encoding='utf-8')
        probe = source[source.index('function odProbe('):source.index('\nfunction odReport(')]
        timeout_test = work / 'timeout.php'
        timeout_test.write_text('<?php\n' + probe + '\necho odProbe([PHP_BINARY,"-n","-r","sleep(10);"],0.15);', encoding='utf-8')
        started = time.monotonic()
        assert 'Interrumpida por tiempo de espera' in php(timeout_test).stdout
        assert time.monotonic() - started < 3
        assert not list(private.iterdir())
        assert sorted(p.name for p in (crm / 'public').iterdir()) == ['disabled.php']
    print('OK: HTTP local; administrador, HTTPS, token, caducidad, uso único, retirada, '
          'proc_open bloqueado/habilitado, prueba fija y timeout. Sin BD ni conversión.')


if __name__ == '__main__':
    run()
