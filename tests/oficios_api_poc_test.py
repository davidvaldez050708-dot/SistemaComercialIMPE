"""Ensayo local: fixture sin datos personales y rechazo de PDFs inválidos. Sin red/BD."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from xml.etree import ElementTree as ET
from zipfile import ZipFile

from pypdf import PdfWriter
from pypdf.generic import DictionaryObject, NameObject, TextStringObject

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN') or shutil.which('php') or r'C:\xampp\php\php.exe'
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('poc_compare', ROOT / 'tools/oficios_api_poc_comparar.py')
compare_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(compare_module)


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def expect_invalid(path):
    try:
        compare_module.pdf_data(path)
    except ValueError:
        return
    raise AssertionError('Se aceptó un PDF inválido.')


def run():
    source = ROOT / 'storage/templates/oficio_general_redmex.docx'
    before = digest(source)
    temporary = ROOT / 'storage/tmp/oficios'
    pending = sorted(temporary.glob('*')) if temporary.exists() else []
    result = subprocess.run([PHP, '-d', 'disable_functions=proc_open,exec,shell_exec,system,passthru,popen,curl_exec,fsockopen,stream_socket_client',
                             '-d', 'allow_url_fopen=0', str(ROOT / 'tools/oficios_api_poc_preparar.php')],
                            capture_output=True, encoding='utf-8', timeout=15,
                            creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0)
    assert result.returncode == 0, result.stderr
    info = json.loads(result.stdout)
    folder = Path(info['directory']).resolve()
    assert folder.is_relative_to((ROOT / 'tmp').resolve()) and folder.name.startswith('poc-redmex-')
    try:
        docx = folder / 'prueba-redmex.docx'
        manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
        request = json.loads((folder / 'cloudconvert-job.json').read_text(encoding='utf-8'))
        assert digest(source) == before == manifest['template_sha256']
        assert digest(docx) == info['sha256'] == manifest['input_sha256']
        assert info['network_requests'] == 0
        assert manifest['visual_fidelity'].startswith('Pendiente')
        assert request['tasks']['convert-demo']['engine'] == 'office'
        assert request['tasks']['convert-demo']['engine_version'] == '2.1'
        assert request['tasks']['convert-demo']['timeout'] == 60
        assert request['tag'] == manifest['tag']
        with ZipFile(source) as original, ZipFile(docx) as sample:
            assert set(original.namelist()) == set(sample.namelist())
            for name, expected_hash in manifest['assets_sha256'].items():
                assert hashlib.sha256(sample.read(name)).hexdigest() == expected_hash
                assert original.read(name) == sample.read(name)
            ns = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'
            old, new = [ET.fromstring(z.read('word/document.xml')) for z in [original, sample]]
            for name in ['pgSz', 'pgMar']:
                assert [dict(e.attrib) for e in old.iter(ns + name)] == [dict(e.attrib) for e in new.iter(ns + name)]
            text = ''.join(e.text or '' for e in new.iter(ns + 't'))
            assert 'PRUEBA/0000/20-10/26' in text and '0000000000' in text
            for name in ['word/comments.xml', 'word/people.xml']:
                doc = ET.fromstring(sample.read(name))
                for element in doc.iter():
                    for key, value in element.attrib.items():
                        if key.split('}')[-1] in ['author', 'initials', 'userId']:
                            assert value in ['PRUEBA', 'prueba@example.test']
            assert len(list(ET.fromstring(original.read('word/comments.xml')).iter(ns + 'comment'))) == len(list(ET.fromstring(sample.read('word/comments.xml')).iter(ns + 'comment')))
            assert not any(b'TargetMode="External"' in sample.read(name) for name in sample.namelist() if name.endswith('.rels'))
        assert sorted(temporary.glob('*')) == pending
        with tempfile.TemporaryDirectory(dir=folder) as pdf_folder:
            path = Path(pdf_folder) / 'test.pdf'
            path.write_bytes(b'Not a PDF')
            expect_invalid(path)
            for pages, size, action in [(1, (612, 792), False), (2, (612, 792), False), (1, (595, 842), False), (1, (612, 792), True)]:
                writer = PdfWriter()
                for _ in range(pages):
                    writer.add_blank_page(*size)
                if action:
                    writer._root_object[NameObject('/OpenAction')] = DictionaryObject({NameObject('/S'): NameObject('/JavaScript'), NameObject('/JS'): TextStringObject('void(0)')})
                with path.open('wb') as stream:
                    writer.write(stream)
                if pages == 1 and size == (612, 792) and not action:
                    assert compare_module.pdf_data(path)['size_points'] == [612, 792]
                else:
                    expect_invalid(path)
    finally:
        # Carpeta exclusiva recién creada y verificada dentro de tmp/ del workspace.
        assert folder.is_relative_to((ROOT / 'tmp').resolve()) and folder.name.startswith('poc-redmex-')
        shutil.rmtree(folder)
    print('OK: preparación sin procesos/red, plantilla y recursos intactos, metadatos ficticios, carta, comentarios preservados y validación PDF dirigida.')


if __name__ == '__main__':
    run()
