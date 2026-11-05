"""Prueba aislada: publicación del renderer y restauración."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PUBLISHER = ROOT / 'tools/publicar_oficios_word_final_hostinger.py'
SOURCE = ROOT / 'app/services/OficioRedmexPdfRenderer.php'
BASE_BLOB = 'ae18cc52a8316f1698340252550897bf3311a4df'
BASE_HASH = '9c82890dcc0fd519634ad60f328da86e588f8d1ebd55a4a133cd30e4d376aee3'

def original():
    cmd = ['git', 'cat-file', '-p', BASE_BLOB]
    p = subprocess.run(cmd, cwd=str(ROOT), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if p.returncode:
        fetch = subprocess.run(
            ['git', 'fetch', '--quiet', 'origin', 'codex/oficios-personalizados-conversion'],
            cwd=str(ROOT), stdout=subprocess.PIPE, stderr=subprocess.PIPE
        )
        assert fetch.returncode == 0, 'No se pudo recuperar la versión anterior'
        p = subprocess.run(cmd, cwd=str(ROOT), stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    assert p.returncode == 0
    assert hashlib.sha256(p.stdout).hexdigest() == BASE_HASH
    return p.stdout

def call(site, backups, *flags, success=True):
    p = subprocess.run(
        [sys.executable, str(PUBLISHER), '--site', str(site),
         '--source', str(SOURCE), '--backup-root', str(backups)] + list(flags),
        stdout=subprocess.PIPE, stderr=subprocess.PIPE
    )
    output = (p.stdout + p.stderr).decode('utf-8', 'replace')
    assert (p.returncode == 0) == success, output
    return output

base = original()
with tempfile.TemporaryDirectory(prefix='impe-oficios-word-final-') as d:
    root = Path(d)
    site = root / 'public_html'
    dest = site / 'app/services/OficioRedmexPdfRenderer.php'
    backups = root / 'private_backups'
    dest.parent.mkdir(parents=True)
    (site / 'index.php').write_text('<?php // fixture', encoding='utf-8')
    (dest.parent / 'OficioDocxPdfService.php').write_text(
        '<?php // OficioRedmexPdfRenderer', encoding='utf-8'
    )
    dest.write_bytes(base)
    assert 'CHECK OK' in call(site, backups, '--check')
    assert dest.read_bytes() == base and not backups.exists()
    assert 'APLICADO' in call(site, backups, '--apply')
    new = SOURCE.read_bytes()
    assert dest.read_bytes() == new
    assert 'YA INSTALADO' in call(site, backups, '--check')
    assert 'YA INSTALADO' in call(site, backups, '--apply')
    folders = [p for p in backups.iterdir() if p.is_dir()]
    assert len(folders) == 1
    metadata = json.loads((folders[0] / 'respaldo.json').read_text(encoding='utf-8'))
    assert metadata['path'] == 'app/services/OficioRedmexPdfRenderer.php'
    assert 'RESTAURADO' in call(site, backups, '--restore', str(folders[0]))
    assert dest.read_bytes() == base
    dest.write_bytes(base + b'\n// local custom')
    altered = dest.read_bytes()
    assert 'CONFLICTO' in call(site, backups, '--check', success=False)
    assert 'CONFLICTO' in call(site, backups, '--apply', success=False)
    assert dest.read_bytes() == altered
    dest.write_bytes(base)
    assert 'APLICADO' in call(site, backups, '--apply')
    newest = sorted(p for p in backups.iterdir() if p.is_dir())[-1]
    dest.write_bytes(dest.read_bytes() + b'\n// later edit')
    assert 'CONFLICTO' in call(site, backups, '--restore', str(newest), success=False)
print('PASS oficio Word final: check, apply, restore, idempotencia y conflictos')
