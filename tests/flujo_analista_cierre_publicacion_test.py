"""Publicación sobre copia instalada: conserva variantes y revierte sin sobrescribir."""
import ast
import importlib.util
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools/publicar_flujo_analista_cierre.py'
spec = importlib.util.spec_from_file_location('publisher', str(SCRIPT))
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)
ast.parse(SCRIPT.read_text(encoding='utf-8'), feature_version=(3, 6))
fixture_root = ROOT / 'tests/fixtures/flujo_analista_cierre_20261125'

for newline in [b'\n', b'\r\n']:
    with tempfile.TemporaryDirectory() as tmp:
        base = Path(tmp)
        site, backups = base / 'public_html', base / 'private'
        (site / 'index.php').parent.mkdir(parents=True)
        (site / 'index.php').write_text('<?php', encoding='utf-8')
        originals={}
        for name in publisher.FILES:
            target=site/name;target.parent.mkdir(parents=True,exist_ok=True)
            original=((fixture_root/name).read_bytes().replace(b'\r\n',b'\n')+b'\n// Personalizacion instalada que debe conservarse.\n').replace(b'\n',newline)
            target.write_bytes(original);originals[name]=original
        target=site/publisher.FILES[0];original=originals[publisher.FILES[0]]
        command = [sys.executable, str(SCRIPT), '--site', str(site), '--backup-root', str(backups)]

        def run(*args, ok=True):
            result = subprocess.run(command + list(args), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            assert (result.returncode == 0) == ok, (result.stdout, result.stderr)
            return (result.stdout + result.stderr).decode('utf-8')

        assert 'COMPROBACION_OK: 3' in run('--check')
        assert target.read_bytes() == original and not backups.exists()
        assert 'PUBLICACION_OK: 3' in run('--apply')
        updated = target.read_bytes()
        assert b'const fetchBounded' in updated
        assert b'Personalizacion instalada' in updated
        assert (b'\r\n' in updated) == (newline == b'\r\n')
        assert 'SIN_CAMBIOS' in run('--apply')
        backup = next(backups.iterdir())
        # Una restauración nunca debe retirar modificaciones posteriores.
        target.write_bytes(updated + b'// Cambio posterior\n')
        assert 'BLOQUEADO' in run('--restore', str(backup), ok=False)
        target.write_bytes(updated)
        assert 'RESTAURACION_OK' in run('--restore', str(backup))
        assert target.read_bytes() == original
        conflict = original.replace(b'let outgoingReady=!preparingResult,', b'let outgoingReady=true,')
        target.write_bytes(conflict)
        assert 'BLOQUEADO' in run('--apply', ok=False)
        assert target.read_bytes() == conflict
        for name in publisher.FILES[1:]:assert (site/name).read_bytes()==originals[name],name
        assert len(list(backups.iterdir())) == 1

print('PASS: Python 3.6; copia instalada LF/CRLF; personalizaciones; comprobacion sin escritura; aplicacion idempotente; respaldo/restauracion; conflictos intactos.')
