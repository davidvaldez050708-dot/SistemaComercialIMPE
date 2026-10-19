#!/usr/bin/env python3
"""Reproduce una publicación Hostinger local sin afectar producción."""
from pathlib import Path
import os
import subprocess
import tempfile

root = Path(__file__).resolve().parent.parent
base = 'b8b96e51e46d56a705c2900379b83ccb75355450'
target = '94d24dca8b47e2383938b531e139711a8a8b7f96'
publisher = root / 'tools/publicar_transferencia_responsive_hostinger.sh'
files = {'js': 'public/javascript/telefonia_enrutamiento.js',
         'css': 'public/css/telefonia_enrutamiento.css'}

def show(commit, filename):
    return subprocess.check_output(['git', 'show', commit + ':' + filename], cwd=root)

with tempfile.TemporaryDirectory(prefix='impe-sidecar-smoke-') as folder:
    tmp = Path(folder)
    mirror = tmp / 'mirror'
    mirror.mkdir()
    live = tmp / 'public_html'
    backup = tmp / 'respaldos'
    live.mkdir()
    (live / 'index.php').write_text('<?php // entorno de prueba')
    original = {}
    for tag, filename in files.items():
        (mirror / ('base-' + tag)).write_bytes(show(base, filename))
        (mirror / ('target-' + tag)).write_bytes(show(target, filename))
        path = live / filename
        path.parent.mkdir(parents=True, exist_ok=True)
        content = show(base, filename) + b'\n/* PERSONALIZACION_HOSTINGER_INTACTA */\n'
        path.write_bytes(content)
        original[filename] = content
    env = dict(os.environ, IMPE_ROUTING_MIRROR_DIR=str(mirror),
               IMPE_ROUTING_LIVE=str(live), IMPE_ROUTING_BACKUPS=str(backup))
    def run(*args, success=True):
        result = subprocess.run(['bash', str(publisher), *args],
                                env=env, capture_output=True, text=True, cwd=root)
        assert (result.returncode == 0) == success, (args, result.stdout, result.stderr)
        return result.stdout + result.stderr

    assert 'SITIO INTACTO' in run('--check')
    assert not backup.exists(), 'El modo check no crea respaldo'
    for file, content in original.items():
        assert (live / file).read_bytes() == content

    assert '--apply terminado' in run('--apply')
    for tag, file in files.items():
        installed = (live / file).read_bytes()
        assert b'PERSONALIZACION_HOSTINGER_INTACTA' in installed
        assert (mirror / ('target-' + tag)).read_bytes() in installed
    assert '0 archivo(s) por publicar' in run('--check')
    saved = list(backup.iterdir())
    assert len(saved) == 1
    assert 'respaldo restaurado' in run('--restore', str(saved[0]))
    for file, content in original.items():
        assert (live / file).read_bytes() == content
    # El archivo modificado después de la publicación nunca debe sobrescribirse.
    run('--restore', str(saved[0]), success=False)
    cssfile = live / files['css']
    cssfile.write_text('/* local modificado incompatible */')
    assert 'CONFLICTO' in run('--check', success=False)
    assert cssfile.read_text() == '/* local modificado incompatible */'

print('Publicación responsive: --check, --apply, personalización, idempotencia, rollback y conflicto: OK')
