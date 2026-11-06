"""Publicación de Experiencia Laboral: réplica temporal, sin SQL ni credenciales."""
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PUBLISHER = ROOT / 'tools/publicar_inscripcion_experiencia_laboral_hostinger.py'
SERVICE = 'app/services/FormularioInscripcionValidacion.php'
BASE = '573c4240a78557b809ec21d6d3090943338ddf33'

p = subprocess.run(['git','show',BASE+':'+SERVICE],cwd=str(ROOT),
                   stdout=subprocess.PIPE,stderr=subprocess.PIPE)
assert p.returncode == 0, p.stderr.decode('utf-8','replace')
old = p.stdout
new = (ROOT / SERVICE).read_bytes()
assert old != new
assert b'titulacion-experiencia-laboral' in new

with tempfile.TemporaryDirectory(prefix='impe-experiencia-legal-') as tmp:
    root = Path(tmp)
    site = root / 'public_html'
    backup = root / 'private-backups'
    dest = site / SERVICE
    dest.parent.mkdir(parents=True)
    (site / 'index.php').write_text('<?php // sitio de pruebas',encoding='utf-8')
    dest.write_bytes(old)

    def run(*args, success=True):
        command = [sys.executable, str(PUBLISHER),
                   '--site',str(site), '--source',str(ROOT/SERVICE),
                   '--backup-root',str(backup)] + list(args)
        proc = subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        output = (proc.stdout+proc.stderr).decode('utf-8','replace')
        assert (proc.returncode==0) == success, output
        return output

    assert 'CHECK OK' in run('--check')
    assert dest.read_bytes() == old
    assert not backup.exists(), 'CHECK no debe generar respaldo'
    assert 'APLICADO' in run('--apply')
    assert dest.read_bytes() == new
    assert 'YA INSTALADO' in run('--apply')
    folders = [f for f in backup.iterdir() if f.is_dir()]
    assert len(folders) == 1, 'un único respaldo'
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    assert dest.read_bytes() == old
    altered = old + b'\n// PERSONALIZACION AJENA\n'
    dest.write_bytes(altered)
    assert 'CONFLICTO' in run('--check',success=False)
    assert 'CONFLICTO' in run('--apply',success=False)
    assert dest.read_bytes() == altered
    assert len([f for f in backup.iterdir() if f.is_dir()]) == 1
    dest.write_bytes(old)
    assert 'APLICADO' in run('--apply')
    other = [f for f in backup.iterdir() if f.is_dir() and f != folders[0]]
    assert len(other) == 1
    dest.write_bytes(new + b'\n// EDICION POSTERIOR\n')
    assert 'CONFLICTO' in run('--restore',str(other[0]),success=False)
    assert dest.read_bytes() != old
    dest.write_bytes(new)
    assert 'RESTAURADO' in run('--restore',str(other[0]))
    assert dest.read_bytes() == old

print('PASS: Experiencia A286; CHECK sin escrituras, APPLY, idempotencia, RESTORE y conflictos protegidos')
