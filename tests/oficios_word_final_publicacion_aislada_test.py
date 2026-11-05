"""Publicación Word final: bloqueo sin motor, check, apply, restauración."""
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools/publicar_oficio_final_aislado_hostinger.py'
SRC = ROOT / 'app/services/OficioDocumentoFinalPdfService.php'
ORIGINAL_BLOB = 'e35bab15324634e9fd98c67b9448ac73b8b0d654'
p = subprocess.run(['git','cat-file','-p',ORIGINAL_BLOB],
                   cwd=str(ROOT),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
assert p.returncode == 0, p.stderr.decode('utf-8','replace')
old = p.stdout

with tempfile.TemporaryDirectory(prefix='impe-final-aislado-') as temp:
    base = Path(temp)
    site = base / 'public_html'
    existing = site / 'app/services/OficioDocxPdfService.php'
    added = site / 'app/services/OficioDocumentoFinalPdfService.php'
    backup = base / 'backups'
    existing.parent.mkdir(parents=True)
    (site/'index.php').write_text('<?php // test')
    existing.write_bytes(old)
    def run(*flags, ok=True, env=None):
        args = [sys.executable,str(SCRIPT),'--site',str(site),
                '--source',str(SRC),'--backup-root',str(backup)] + list(flags)
        p = subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env)
        output = (p.stdout+p.stderr).decode('utf-8','replace')
        assert (p.returncode == 0) == ok, output
        return output

    disabled = dict(os.environ, IMPE_OFICIO_FINAL_LIBREOFFICE='/nonexistent/soffice')
    assert 'BLOQUEADO' in run('--check',ok=False,env=disabled)
    assert existing.read_bytes() == old and not added.exists()
    assert not backup.exists()

    fake = base / 'soffice-test'
    fake.write_text('#!/bin/sh\nexit 0\n')
    fake.chmod(0o700)
    enabled = dict(os.environ, IMPE_OFICIO_FINAL_LIBREOFFICE=str(fake))
    assert 'CHECK OK' in run('--check',env=enabled)
    assert 'APLICADO' in run('--apply',env=enabled)
    assert added.read_bytes() == SRC.read_bytes()
    assert b'OficioDocumentoFinalPdfService' in existing.read_bytes()
    assert 'YA INSTALADO' in run('--apply',env=enabled)
    backups = [x for x in backup.iterdir() if x.is_dir()]
    assert len(backups) == 1
    assert 'RESTAURADO' in run('--restore',str(backups[0]),env=enabled)
    assert existing.read_bytes() == old and not added.exists()
print('PASS Word final: preflight, motor ausente, apply y restore')
