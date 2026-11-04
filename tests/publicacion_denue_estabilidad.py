"""Publicación en carpetas efímeras: variantes, rechazo, instalación y reversión."""
from pathlib import Path
import base64, gzip, hashlib, json, os, shutil, subprocess, sys, tempfile

ROOT = Path(__file__).resolve().parents[1]
manifest = json.loads((ROOT/'tools/denue_estabilidad_release.json').read_text())
installer = ROOT/'tools/publicar_denue_estabilidad.py'
controller = 'app/controllers/SeguimientoVinculacionController.php'
sha = lambda data: hashlib.sha256(data).hexdigest()

def run(site, backup, *args, ok=True):
    proc = subprocess.run([sys.executable, str(installer), '--site', str(site), '--backup-root', str(backup), *args],
                          cwd=ROOT, capture_output=True, text=True)
    assert (proc.returncode == 0) == ok, proc.stdout+proc.stderr
    return proc.stdout

def snapshot(site):
    return {p.relative_to(site).as_posix(): p.read_bytes() for p in site.rglob('*') if p.is_file()}

with tempfile.TemporaryDirectory(prefix='impe-denue-release-') as directory:
    root = Path(directory)
    for variant in range(2):
        site, backup = root/f'site{variant}', root/f'backup{variant}'
        site.mkdir()
        (site/'index.php').write_text('<?php // fixture')
        for row in manifest['files']:
            v = row['variants'][variant if row['path'] == controller else 0]
            if v['before'] is not None:
                data = gzip.decompress(base64.b64decode(v['base']))
                assert sha(data) == v['before']
                p = site/row['path']; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(data)
        before = snapshot(site)
        assert 'CHECK OK' in run(site, backup, '--check')
        assert snapshot(site) == before and not backup.exists(), 'CHECK no escribe'
        p = site/controller
        p.write_bytes(p.read_bytes()+b'\n// variante desconocida')
        altered = snapshot(site)
        run(site, backup, '--apply', ok=False)
        assert snapshot(site) == altered, 'Bloqueo sin instalación parcial'
        p.write_bytes(before[controller])
        run(site, backup, '--apply')
        installed = snapshot(site)
        for row in manifest['files']:
            v = row['variants'][variant if row['path'] == controller else 0]
            assert sha((site/row['path']).read_bytes()) == v['after']
        run(site, backup, '--apply')
        assert snapshot(site) == installed, 'Instalación idempotente'
        directory_backup = next(x for x in backup.iterdir() if x.is_dir())
        p.write_bytes(p.read_bytes()+b'\n// edicion posterior')
        altered = snapshot(site)
        run(site, backup, '--restore', str(directory_backup), ok=False)
        assert snapshot(site) == altered, 'No pisar ediciones posteriores'
        p.write_bytes(installed[controller])
        run(site, backup, '--restore', str(directory_backup))
        assert snapshot(site) == before, 'Reversión exacta'
    assert manifest['files'][-1]['variants'][1]['before'] == 'cd319b9bdcf1d75a27ff9b3244e7478303b9e51d5eceebe776cff31b24ad414f'
print('Publicación DENUE: dos variantes, sólo lectura, bloqueo, integridad, idempotencia y reversión OK')
