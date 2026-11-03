"""Publicador contra copias temporales: no usa credenciales ni ejecuta SQL."""
import importlib.util
import base64
import gzip
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('release', ROOT / 'tools/preparar_prospectos_central_release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
manifest = json.loads((ROOT / 'tools/prospectos_central_release.json').read_text(encoding='utf-8'))


def git(ref, name):
    return subprocess.check_output(['git', 'show', ref + ':' + name], cwd=ROOT)


def main():
    # Baselines from production-reconciled tracked files / committed ancestry.
    # A caller may supply the reviewed baseline directory for a working tree run.
    baseline = Path(os.environ['IMPE_PROSPECTOS_BASELINES']) if os.environ.get('IMPE_PROSPECTOS_BASELINES') else None
    with tempfile.TemporaryDirectory(prefix='publicacion-prospectos-test-') as temp:
        site = Path(temp) / 'site'
        site.mkdir()
        (site / 'index.php').write_text('<?php', encoding='utf-8')
        for name in release.BASES:
            if baseline:
                content = (baseline / name).read_bytes()
            else:
                content = gzip.decompress(base64.b64decode(next(r for r in manifest['files'] if r['path']==name)['baseline']))
            target = site / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
        for dep in manifest['dependencies']:
            target = site / dep['path']
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes((ROOT / dep['path']).read_bytes().replace(b'\r\n', b'\n'))
        backup = Path(temp) / 'private'
        command = [sys.executable, str(ROOT / 'tools/publicar_prospectos_central.py'), '--site', str(site), '--backup-root', str(backup)]
        def call(*args, valid=True):
            r = subprocess.run(command + list(args), capture_output=True, text=True)
            assert (r.returncode == 0) == valid, r.stdout + r.stderr
            return r
        def snapshot():
            return {p.relative_to(site).as_posix(): p.read_bytes() for p in site.rglob('*') if p.is_file()}
        before = snapshot()
        call('--check')
        assert snapshot() == before and not backup.exists(), 'check escribió'
        dep = site / manifest['dependencies'][0]['path']
        dep.write_bytes(dep.read_bytes() + b'\n/*personalizacion*/\n')
        changed = snapshot()
        call('--apply', valid=False)
        assert snapshot() == changed and not backup.exists(), 'no bloqueó dependencia'
        dep.write_bytes(before[dep.relative_to(site).as_posix()])
        candidate = site / next(iter(release.BASES))
        candidate.write_bytes(candidate.read_bytes() + b'\n/*variante desconocida*/\n')
        changed = snapshot()
        call('--apply', valid=False)
        assert snapshot() == changed and not backup.exists(), 'no bloqueó personalización'
        candidate.write_bytes(before[candidate.relative_to(site).as_posix()])
        call('--apply')
        installed = snapshot()
        assert len(installed) > len(before)
        call('--apply')
        assert snapshot() == installed, 'segunda instalación no idempotente'
        saved = next(backup.iterdir())
        changed_file = site / 'public/css/prospectos_central.css'
        changed_file.write_bytes(changed_file.read_bytes() + b'\n/*cambio posterior*/\n')
        changed = snapshot()
        call('--restore', str(saved), valid=False)
        assert snapshot() == changed, 'restauró por encima de cambio posterior'
        changed_file.write_bytes(installed['public/css/prospectos_central.css'])
        call('--restore', str(saved))
        assert snapshot() == before, 'restauración no exacta'
        print('PASS publicación: check sin escrituras, dependencias/variantes desconocidas bloqueadas, instalación repetible y reversión exacta sin SQL.')


if __name__ == '__main__':
    main()
