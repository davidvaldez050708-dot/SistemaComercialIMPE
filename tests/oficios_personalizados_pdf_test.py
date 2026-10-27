"""Conversión real de las plantillas guardadas, sin BD ni modificación de originales."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from xml.etree import ElementTree as ET
from zipfile import ZipFile

import pdfplumber

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('IMPE_TEST_PHP', r'C:\xampp\php\php.exe' if os.name == 'nt' else 'php')
W = '{http://schemas.openxmlformats.org/wordprocessingml/2006/main}'


def compact(text):
    return re.sub(r'\s', '', text)


def main():
    catalog = ROOT / 'storage/templates/oficios_personalizados'
    files = sorted(catalog.glob('oficio_*.docx'))
    prepared = catalog / 'generacion_20260911_151956_16700d8c2a46.docx'
    if prepared.is_file():
        files.append(prepared)
    assert files
    with tempfile.TemporaryDirectory(prefix='oficios-personalizados-', dir=ROOT / 'tmp') as temp:
        work = Path(temp)
        runner = work / 'render.php'
        runner.write_text('''<?php
require $argv[1].'/app/services/OficioRedmexPdfRenderer.php';
$r=(new OficioRedmexPdfRenderer())->convertir($argv[2],$argv[3]);
echo json_encode(['ok'=>$r['ok'],'reason'=>$r['mensaje_tecnico']??'']);
exit($r['ok']?0:1);
''', encoding='utf-8')
        for index, source in enumerate(files):
            before = hashlib.sha256(source.read_bytes()).digest()
            out = work / str(index)
            out.mkdir()
            result = subprocess.run([PHP, '-d', 'memory_limit=128M', '-d',
                'disable_functions=proc_open,exec,shell_exec,system,passthru,popen',
                str(runner), str(ROOT), str(source), str(out)], capture_output=True, timeout=60)
            assert result.returncode == 0, (source.name, result.stdout.decode(errors='replace'), result.stderr.decode(errors='replace'))
            assert json.loads(result.stdout)['ok']
            assert hashlib.sha256(source.read_bytes()).digest() == before
            with ZipFile(source) as docx:
                body = ET.fromstring(docx.read('word/document.xml')).find(W + 'body')
                expected = ''.join(node.text or '' for node in body.iter(W + 't'))
            with pdfplumber.open(out / 'oficio_redmex.pdf') as pdf:
                assert all((p.width, p.height) == (612, 792) for p in pdf.pages)
                actual = ''.join(p.extract_text(x_tolerance=2) or '' for p in pdf.pages)
                assert compact(expected) == compact(actual), (source.name, 'contenido incompleto o cambiado')
                if before.hex() == 'f227897c24b7fa08145a6f38b529e208c5c383011647af2a7f9999f0751bc45f':
                    assert len(pdf.pages) == 1
                    assert len(pdf.pages[0].images) >= 6, 'Faltan cabecera, pie, firma o sello'
            print('OK: original intacto, texto completo y PDF carta:', source.name, flush=True)
    print('OK:', len(files), 'plantillas y documentos preparados; sin BD ni procesos externos en PHP.')


if __name__ == '__main__':
    main()
