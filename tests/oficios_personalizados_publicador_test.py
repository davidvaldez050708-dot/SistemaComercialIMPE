"""Publicador en réplicas LF/CRLF; nunca apunta al sitio real."""
from pathlib import Path
import hashlib
import os
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
BASH, PHPDIR = sys.argv[1:3]
FILE = 'app/services/OficioRedmexPdfRenderer.php'
SCRIPT = ROOT / 'tools/publicar_oficios_personalizados_hostinger.sh'
old = subprocess.check_output(['git', 'show', 'efe01ebd:' + FILE], cwd=ROOT).replace(b'\r\n', b'\n')
new = (ROOT / FILE).read_bytes().replace(b'\r\n', b'\n')

for eol in (b'\n', b'\r\n'):
    with tempfile.TemporaryDirectory(prefix='impe-oficios-publicador-') as td:
        base = Path(td)
        live = base / 'public_html'
        target = live / FILE
        target.parent.mkdir(parents=True)
        original = old.replace(b'\n', eol)
        expected = new.replace(b'\n', eol)
        target.write_bytes(original)
        (live / 'index.php').write_text('<?php // replica')
        service = live / 'app/services/OficioDocxPdfService.php'
        service.write_bytes((ROOT / 'app/services/OficioDocxPdfService.php').read_bytes())
        vendor = live / 'vendor'
        vendor.mkdir()
        (vendor / 'autoload.php').write_text('<?php return require ' + repr((ROOT / 'vendor/autoload.php').as_posix()) + ';')
        assets = ['storage/templates/oficio_general_redmex.docx',
            'storage/templates/oficios_personalizados/oficio_20260911_115147_e2e9fb4003a1.docx']
        protected = {service: hashlib.sha256(service.read_bytes()).digest()}
        for rel in assets:
            p = live / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(ROOT / rel, p)
            protected[p] = hashlib.sha256(p.read_bytes()).digest()
        config = live / 'config/db_connection.php'
        config.parent.mkdir()
        config.write_text('<?php throw new RuntimeException("BD PROHIBIDA EN ESTA PRUEBA");')
        protected[config] = hashlib.sha256(config.read_bytes()).digest()
        env = dict(os.environ, IMPE_OFICIOS_PDF_PUBLIC=live.as_posix(),
            IMPE_OFICIOS_PDF_BACKUPS=(base / 'backups').as_posix())
        env['PATH'] = PHPDIR + os.pathsep + env['PATH']

        def run(*args, ok=True):
            r = subprocess.run([BASH, SCRIPT.as_posix(), *args], env=env, cwd=ROOT,
                capture_output=True, text=True, timeout=60)
            assert (r.returncode == 0) == ok, r.stdout + r.stderr
            return r.stdout + r.stderr

        run('--probe', ok=False)
        run('--check')
        assert target.read_bytes() == original and not (base / 'backups').exists()
        result = run('--apply')
        backup = re.search(r'^Respaldo: (.+)$', result, re.M)[1]
        assert target.read_bytes() == expected
        assert 'ya aplicado' in run('--apply')
        before_tmp = set(Path(tempfile.gettempdir()).glob('impe-oficios-personalizados-probe-*'))
        assert 'ORIGINAL_INTACTO=SI' in run('--probe')
        assert set(Path(tempfile.gettempdir()).glob('impe-oficios-personalizados-probe-*')) == before_tmp
        target.write_bytes(expected + eol + b'// Edicion posterior')
        snapshot = target.read_bytes()
        run('--restore', backup, ok=False)
        assert target.read_bytes() == snapshot
        target.write_bytes(expected)
        run('--restore', backup)
        assert target.read_bytes() == original
        target.write_bytes(original + eol + b'// Personalizacion desconocida')
        snapshot = target.read_bytes()
        run('--apply', ok=False)
        assert target.read_bytes() == snapshot
        assert all(hashlib.sha256(p.read_bytes()).digest() == h for p, h in protected.items())
        print('OK:', repr(eol), 'check/apply/probe/restore, idempotencia y rechazo de cambios ajenos; solo renderer.', flush=True)
