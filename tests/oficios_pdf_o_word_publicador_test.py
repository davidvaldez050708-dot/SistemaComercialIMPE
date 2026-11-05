"""Regresión publicador PDF/DOCX: check, apply, restore y personalizaciones."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools/publicar_oficios_pdf_o_word_hostinger.py'
BASE = '07459a8cb1aa7205ec5d55f061a90a91456a2704'
FILES = [
    'app/controllers/OficioVinculacionController.php',
    'app/services/OficioDocxPdfService.php',
    'app/services/OficioPdfService.php',
    'public/javascript/oficios_vinculacion.js',
]

def from_git(path):
    blob = subprocess.run(['git','show', BASE+':'+path],cwd=str(ROOT),
                          stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert blob.returncode == 0, blob.stderr.decode('utf-8','replace')
    return blob.stdout

with tempfile.TemporaryDirectory(prefix='impe-oficios-pdf-word-') as name:
    root = Path(name)
    site = root/'public_html'
    old = root/'base'
    target = root/'target'
    backup = root/'private_backup'
    site.mkdir()
    (site/'index.php').write_text('<?php // fixture',encoding='utf-8')
    originals = {}
    for path in FILES:
        (site/path).parent.mkdir(parents=True,exist_ok=True)
        (old/path).parent.mkdir(parents=True,exist_ok=True)
        (target/path).parent.mkdir(parents=True,exist_ok=True)
        base = from_git(path)
        now = (ROOT/path).read_bytes()
        (site/path).write_bytes(base)
        (old/path).write_bytes(base)
        (target/path).write_bytes(now)
        originals[path] = base

    def run(*actions, success=True):
        r = subprocess.run([sys.executable,str(SCRIPT),'--site',str(site),
             '--base-dir',str(old),'--target-dir',str(target),'--backup-root',str(backup)] + list(actions),
             stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        msg=(r.stdout+r.stderr).decode('utf-8','replace')
        assert (r.returncode==0) == success, msg
        return msg

    assert 'CHECK OK' in run('--check')
    assert not backup.exists()
    assert 'APLICADO' in run('--apply')
    for path in FILES:
        assert (site/path).read_bytes() == (target/path).read_bytes()
    assert 'YA INSTALADO' in run('--apply')
    folders = [x for x in backup.iterdir() if x.is_dir()]
    assert len(folders) == 1
    manifest = json.loads((folders[0]/'respaldo.json').read_text(encoding='utf-8'))
    assert len(manifest['files']) == len(FILES)
    assert 'RESTAURADO' in run('--restore',str(folders[0]))
    for path in FILES:
        assert (site/path).read_bytes() == originals[path]

    # Personalización independiente ajena a los fragmentos reemplazados.
    changed = FILES[0]
    (site/changed).write_bytes(originals[changed] + b'\n// custom local trailing comment\n')
    assert 'CHECK OK' in run('--check')
    assert 'APLICADO' in run('--apply')
    assert (site/changed).read_bytes().endswith(b'// custom local trailing comment\n')
    checkCurrent=(site/changed).read_bytes()
    assert 'CHECK OK' in run('--check')
    assert (site/changed).read_bytes()==checkCurrent

    # Cambio posterior bloquea restauración, evitando pérdidas.
    folders = [x for x in backup.iterdir() if x.is_dir()]
    newest = sorted(folders)[-1]
    (site/changed).write_bytes((site/changed).read_bytes()+b'// edit posterior\n')
    assert 'CONFLICTO' in run('--restore',str(newest),success=False)
print('PASS oficio terminado PDF/DOCX: check, apply, idempotencia, conciliación y restore')
