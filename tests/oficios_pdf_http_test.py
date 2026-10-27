"""Endpoints PHP reales en réplica aislada con sesión real y BD simulada; sin producción."""
from copy import deepcopy
import http.cookiejar
import io
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from zipfile import ZipFile

import pdfplumber

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('IMPE_TEST_PHP', r'C:\xampp\php\php.exe' if os.name == 'nt' else 'php')


def main():
    with tempfile.TemporaryDirectory(prefix='oficios-http-', dir=ROOT / 'tmp') as temp:
        work = Path(temp)
        for relative in [
            'app/services/OficioDocxPdfService.php', 'app/services/OficioRedmexPdfRenderer.php',
            'app/services/OficioPreviewService.php', 'app/services/OficioPdfService.php',
            'app/controllers/OficioVinculacionController.php', 'app/helpers/PermissionHelper.php',
            'app/helpers/AdminRolePolicy.php', 'public/oficio_vista_previa_documento.php', 'public/oficio_pdf_documento.php',
            'storage/templates/oficio_general_redmex.docx',
        ]:
            target = work / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / relative, target)
        (work / 'vendor').mkdir()
        (work / 'vendor/autoload.php').write_text('<?php require ' + json.dumps((ROOT / 'vendor/autoload.php').as_posix()) + ';')
        (work / 'config').mkdir()
        shutil.copyfile(ROOT / 'tests/fixtures/oficios_pdf_fake_db.php', work / 'config/db_connection.php')
        (work / 'app/models').mkdir()
        (work / 'app/models/OficioVinculacionModel.php').write_text('''<?php
require_once __DIR__.'/../../config/db_connection.php';
class OficioVinculacionModel {
 public function obtenerEstadoSeguimiento($id,$user,$mode) { return OficiosFixtureData::row($id,$user); }
}
''')
        shutil.copyfile(ROOT / 'tests/fixtures/oficios_pdf_http_router.php', work / 'router.php')
        (work / 'sessions').mkdir()
        (work / 'storage/tmp/oficios').mkdir(parents=True)
        custom = work / 'storage/templates/oficios_personalizados'
        custom.mkdir()
        good_name = 'generacion_20261020_120000_aabbccddeeff.docx'
        bad_name = 'generacion_20261020_120001_aabbccddeeff.docx'
        shutil.copyfile(ROOT / 'output/pdf/oficios-redmex/preparado.docx', custom / good_name)
        embassy_name = 'generacion_20261026_120000_aabbccddeeff.docx'
        embassy_source = ROOT / 'storage/templates/oficios_personalizados/oficio_20260911_115147_e2e9fb4003a1.docx'
        shutil.copyfile(embassy_source, custom / embassy_name)
        with ZipFile(custom / good_name) as original, ZipFile(custom / bad_name, 'w') as bad:
            for entry in original.infolist():
                data = original.read(entry.filename)
                if entry.filename == 'word/document.xml':
                    data = data.replace(b'w:w="12240"', b'w:w="11906"')
                bad.writestr(entry, data)
        row = dict(analista_id=9, folio='PRUEBA/HTTP/0001', oficio_id=101, plantilla_oficio_id=11,
                   destinatario_nombre='DATO_PRIVADO_QA', destinatario_cargo='DIRECCIÓN', destinatario_correo='prueba@example.test',
                   nombre_entidad='INSTITUCIÓN DE PRUEBA', municipio_nombre='Cuernavaca', estado_nombre='MORELOS',
                   analista_nombre='Analista', analista_apellidos='de Prueba', analista_correo='prueba@example.test',
                   analista_telefono='0000000000', oficio_created_at='2026-10-20 12:00:00', estado_oficio='BORRADOR',
                   archivo_pdf='', fecha_generacion='')
        rows = {str(i): dict(row, id=i, oficio_id=100+i, folio='PRUEBA/HTTP/000'+str(i), plantilla_oficio_id=11 if i==1 else (13 if i==3 else 12)) for i in range(1,5)}
        rows['4']['analista_id'] = 10
        templates = {str(i): dict(id=i, nombre='Modelo institucional', asunto='Programa de Profesionalización', contenido='',
                                archivo_docx='' if i==11 else 'storage/templates/oficios_personalizados/'+(bad_name if i==13 else good_name)) for i in range(11,14)}
        rows['5'] = dict(row, id=5, oficio_id=105, plantilla_oficio_id=14)
        templates['14'] = dict(id=14, nombre='Embajadas', asunto='Solicitud de audiencia', contenido='',
            archivo_docx='storage/templates/oficios_personalizados/' + embassy_name)
        state_path = work / 'state.json'
        state_path.write_text(json.dumps(dict(rows=rows, templates=templates, updates=0)))
        token = secrets.token_hex(24)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = dict(os.environ, IMPE_OFICIOS_HTTP_STATE=str(state_path), IMPE_OFICIOS_HTTP_TOKEN=token)
        with (work / 'server.log').open('w') as log:
            process = subprocess.Popen([PHP, '-d', 'memory_limit=128M', '-d', 'disable_functions=proc_open,exec,shell_exec,system,passthru,popen',
                                        '-d', 'session.save_path='+str(work / 'sessions'), '-S', f'127.0.0.1:{port}', str(work / 'router.php')],
                                       cwd=work, env=env, stdout=log, stderr=log,
                                       creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
            try:
                for _ in range(60):
                    try:
                        with socket.create_connection(('127.0.0.1', port), timeout=.2): break
                    except OSError: time.sleep(.1)
                client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

                def request(path, data=None):
                    req = urllib.request.Request(f'http://127.0.0.1:{port}'+path,
                        data=urllib.parse.urlencode(data).encode() if data is not None else None)
                    try: response = client.open(req, timeout=60)
                    except urllib.error.HTTPError as error: response = error
                    with response: return response.status, response.headers, response.read()

                def pdf(body):
                    assert body.startswith(b'%PDF-') and len(body)>1000
                    with pdfplumber.open(io.BytesIO(body)) as document:
                        assert len(document.pages)==1 and [document.pages[0].width,document.pages[0].height]==[612,792]
                        text = document.pages[0].extract_text()
                    assert 'Dichos programas cuentan con' in text and 'validez oficial' in text
                    assert all(bad not in text for bad in ['CUERPO PERSONALIZADO:', 'Programa ficticio', 'EDICIÓN MANUAL CONSERVADA:'])
                    return text

                assert request('/preview?seguimiento_id=1')[0]==401
                assert request('/__test_login', {'token':token})[0]==200
                assert request('/download?seguimiento_id=1')[0]==404
                for i in (1,2):
                    before=json.loads(state_path.read_text())
                    status, headers, body=request('/preview?seguimiento_id='+str(i))
                    assert status==200, body.decode(errors='replace')[:400]
                    assert headers.get_content_type()=='application/pdf' and headers['Content-Disposition'].startswith('inline;')
                    text=pdf(body)
                    assert ('DATO_PRIVADO_QA' in text) if i==1 else ('Ana Ejemplo' in text and '0000000002' in text)
                    assert json.loads(state_path.read_text())==before, 'preview changed state/folio'
                    status, headers, docx=request('/docx?seguimiento_id='+str(i))
                    assert status==200 and ZipFile(io.BytesIO(docx)).testzip() is None
                    status, _, body=request('/generate', {'seguimiento_id':i})
                    result=json.loads(body)
                    assert status==200 and result['ok'] and result['conversor']=='REDMEX_DOMPDF', result
                    after=json.loads(state_path.read_text())
                    assert after['rows'][str(i)]['folio']==before['rows'][str(i)]['folio']
                    assert after['rows'][str(i)]['estado_oficio']=='GENERADO' and after['updates']==i
                    status, headers, body=request('/download?descargar=1&seguimiento_id='+str(i))
                    assert status==200 and headers['Content-Disposition'].startswith('attachment;')
                    pdf(body)
                    assert request('/generate', {'seguimiento_id':i})[0]==200
                    assert json.loads(state_path.read_text())==after, 'second generation duplicated state update'
                    print('OK: sesión, preview, DOCX, generación, descarga e idempotencia:', i, flush=True)
                before = json.loads(state_path.read_text())
                status, headers, body = request('/preview?seguimiento_id=5')
                assert status == 200 and headers.get_content_type() == 'application/pdf'
                with pdfplumber.open(io.BytesIO(body)) as document:
                    assert len(document.pages) == 1
                    assert (document.pages[0].width, document.pages[0].height) == (612, 792)
                    assert 'GiraInternacional' in ''.join(document.pages[0].extract_text().split())
                    assert len(document.pages[0].images) >= 6
                assert json.loads(state_path.read_text()) == before
                status, _, docx = request('/docx?seguimiento_id=5')
                assert status == 200 and docx == embassy_source.read_bytes()
                status, _, response = request('/generate', {'seguimiento_id': 5})
                assert status == 200 and json.loads(response)['ok']
                after = json.loads(state_path.read_text())
                assert after['updates'] == 3 and after['rows']['5']['folio'] == before['rows']['5']['folio']
                assert request('/download?descargar=1&seguimiento_id=5')[0] == 200
                print('OK: plantilla Embajadas por los endpoints reales; DOCX exacto, PDF con seis imágenes y mismo folio.', flush=True)
                before=json.loads(state_path.read_text())
                status, _, body=request('/generate', {'seguimiento_id':3})
                assert status==500 and not json.loads(body)['ok']
                assert request('/preview?seguimiento_id=3')[0]==500
                assert json.loads(state_path.read_text())==before, 'failed conversion changed state or folio'
                assert request('/preview?seguimiento_id=4')[0]==403
                assert request('/generate', {'seguimiento_id':4})[0]==403
                assert request('/generate')[0]==405
                # Error real de persistencia simulado: no registrar GENERADO ni dejar el PDF nuevo.
                before['rows']['3']['plantilla_oficio_id']=12
                before['fail_update']=True
                state_path.write_text(json.dumps(before))
                stored_before={p.relative_to(work).as_posix():p.read_bytes() for p in (work/'storage/oficios').rglob('*.pdf')}
                assert request('/generate', {'seguimiento_id':3})[0]==500
                assert json.loads(state_path.read_text())==before
                assert {p.relative_to(work).as_posix():p.read_bytes() for p in (work/'storage/oficios').rglob('*.pdf')}==stored_before
                # Una ruta temporal inaccesible falla con referencia, sin revelar su ruta.
                temporary=work/'storage/tmp/oficios'
                assert not list(temporary.iterdir())
                temporary.rmdir()
                temporary.write_text('BLOQUEO LOCAL DE PRUEBA')
                status, _, body=request('/preview?seguimiento_id=1')
                assert status==500 and b'Referencia:' in body and str(work).encode() not in body
                temporary.unlink()
                temporary.mkdir()
                assert json.loads(state_path.read_text())==before
                assert not list((work / 'storage/tmp/oficios').iterdir()), 'temporary files remain'
                print('OK: fallos sin PDF, sin transición de estado ni folios duplicados; controles de acceso.', flush=True)
            except Exception:
                log.flush()
                print((work / 'server.log').read_text(errors='replace')[-3500:])
                raise
            finally:
                process.terminate()
                process.wait(timeout=10)
        logs=(work / 'server.log').read_text(errors='replace')
        assert 'DATO_PRIVADO_QA' not in logs and 'prueba@example.test' not in logs
        assert '"engine":"REDMEX_DOMPDF"' in logs and '"sapi":"cli-server"' in logs
        assert '"reason":"REDMEX_PAGE_SIZE"' in logs and '"exception":"RuntimeException"' in logs
        assert '"reason":"OFICIO_PDF_TEMP_CREATE"' in logs
        print('OK: registros seguros de fase, motor, excepción y temporales; PHP web local sin procesos.', flush=True)


if __name__ == '__main__':
    main()
