"""Prueba aislada del publicador selectivo, sin conexiones al servidor."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile

repo = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('publicador', repo/'tools/publicar_agenda_doble_sonido.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

with tempfile.TemporaryDirectory(prefix='impe-doble-sonido-test-') as tmp:
    root = Path(tmp)
    site = root/'sitio'
    site.mkdir()
    (site/'index.php').write_text('<?php // copia aislada')
    for name in module.RUTAS:
        target = site/name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(module.git(repo, module.BASE, name))
    js = site/'public/javascript/recordatorios.js'
    js.write_bytes(js.read_bytes() + b'\n// Personalizacion local conservada\n')
    before = {name: module.lectura(site/name) for name in module.RUTAS}
    other = site/'personalizacion.css'
    other.write_text('/* Estilo ajeno al cambio */')

    def run(*args, ok=True):
        result = subprocess.run([sys.executable, str(repo/'tools/publicar_agenda_doble_sonido.py'),
            '--repo', str(repo), '--site', str(site)] + list(args), capture_output=True, text=True)
        assert (result.returncode == 0) == ok, result.stdout + result.stderr

    run('--check')
    assert before == {name: module.lectura(site/name) for name in module.RUTAS}
    run('--apply', '--backup-root', str(root/'respaldos'))
    assert b'Personalizacion local conservada' in js.read_bytes()
    assert 'Estilo ajeno' in other.read_text()
    backup = next((root/'respaldos').iterdir())
    manifest = json.loads((backup/'manifiesto.json').read_text())
    assert set(manifest['archivos']) == set(module.RUTAS)
    assert all(module.sha(module.lectura(site/name)) == item['despues']
               for name, item in manifest['archivos'].items())
    run('--restore', str(backup))
    assert before == {name: module.lectura(site/name) for name in module.RUTAS}
    audio_js = site/'public/javascript/agenda_alertas.js'
    audio_js.write_bytes(audio_js.read_bytes().replace(b'audio.volume = 0.7;', b'audio.volume = 0.2;'))
    conflicting = audio_js.read_bytes()
    run('--check', ok=False)
    assert audio_js.read_bytes() == conflicting
print('OK: publicador de dos archivos, check sin escrituras, personalización, respaldo, restauración y conflicto.')
