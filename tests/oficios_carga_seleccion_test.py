"""Multipart HTTP real sobre auxiliares del controlador; modelo de prueba, sin BD."""
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.request
import urllib.error
import uuid

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('IMPE_TEST_PHP', r'C:\xampp\php\php.exe' if os.name == 'nt' else 'php')


def main():
    with tempfile.TemporaryDirectory(prefix='oficios-carga-', dir=ROOT / 'tmp') as name:
        work = Path(name)
        registry = work / 'registry.json'
        registry.write_text('{}')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = dict(os.environ, IMPE_OFICIOS_TEST_WORK=str(work))
        with (work / 'server.log').open('w') as log:
            process = subprocess.Popen([PHP, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=25M',
                                        '-S', f'127.0.0.1:{port}', str(ROOT / 'tests/fixtures/oficios_carga_router.php')],
                                       cwd=ROOT, env=env, stdout=log, stderr=log)
            created = []
            try:
                for _ in range(60):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.2):
                            break
                    except OSError:
                        time.sleep(.1)

                def request(mode, fields=None, file=None):
                    boundary = 'test' + uuid.uuid4().hex
                    parts = []
                    for key, value in (fields or {}).items():
                        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
                    if file:
                        filename, data = file
                        parts += [f'--{boundary}\r\nContent-Disposition: form-data; name="archivo"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode(), data, b'\r\n']
                    parts.append(f'--{boundary}--\r\n'.encode())
                    req = urllib.request.Request(f'http://127.0.0.1:{port}/?mode={mode}', data=b''.join(parts),
                                                 headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})
                    try:
                        response = urllib.request.urlopen(req, timeout=30)
                    except urllib.error.HTTPError as error:
                        response = error
                    with response:
                        return response.status, json.load(response)

                data = (ROOT / 'output/pdf/oficios-redmex/general.docx').read_bytes()
                status, library = request('library', file=('formato.docx', data))
                assert status == 200 and library['ok'], library
                created.append(Path(library['ruta_absoluta']))
                assert created[-1].read_bytes() == data and created[-1].name.startswith('oficio_')
                status, saved = request('saved', {'id': library['plantilla_id']})
                assert status == 200 and saved['ok'], saved
                created.append(Path(saved['ruta_absoluta']))
                assert created[-1].name.startswith('generacion_') and created[-1].read_bytes() == data
                status, upload = request('upload', file=('oficio-editado.docx', data))
                assert status == 200 and upload['ok'], upload
                created += [Path(upload['ruta_absoluta']), Path(upload['ruta_original'])]
                assert all(path.read_bytes() == data for path in created[-2:])
                created[-2].write_bytes(b'COPIA PROCESADA DE PRUEBA')
                assert created[-1].read_bytes() == data, 'original upload overwritten'
                assert request('saved', {'id': upload['plantilla_id']})[0] == 422, 'processed exposed as library'
                assert request('saved', {'id': 9999999})[0] == 422, 'invalid selection accepted'
                for filename, payload in [('malicioso.php', data), ('falso.docx', b'<html>NO DOCX</html>'),
                                          ('vacio.docx', b''), ('grande.docx', b'x' * (20 * 1024 * 1024 + 1))]:
                    assert request('library', file=(filename, payload))[0] == 422, filename
                assert request('unauth')[0] == 401
                assert request('guard&role=admin')[0] == 403
                assert request('guard&role=cuenta')[0] == 403
                assert request('guard&role=analista', {'tipo_plantilla': 'invalida', 'seguimiento_id': 1})[0] == 422
                print('OK: carga multipart, biblioteca, selección, original intacto, archivos inválidos y controles de acceso.')
            finally:
                process.terminate()
                process.wait(timeout=10)
                rows = json.loads(registry.read_text())
                paths = set(created) | {ROOT / item['archivo_docx'] for item in rows.values()}
                for path in paths:
                    if path.is_file():
                        path.unlink()


if __name__ == '__main__':
    main()
