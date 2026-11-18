"""Prueba local del publicador; nunca usa Hostinger ni MySQL."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile

repo = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('publisher', repo/'tools/publicar_agenda_alertas.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

with tempfile.TemporaryDirectory(prefix='impe-agenda-publicador-') as tmp:
    root = Path(tmp)
    site = root/'sitio'
    site.mkdir()
    (site/'index.php').write_text('<?php // replica aislada')
    for name in module.RUTAS:
        data = module.git(repo, 'HEAD', module.BASES_INSTALADAS[name]) if name in module.BASES_INSTALADAS else module.git(repo, module.BASE, name)
        if data is not None:
            target = site/name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
    controller = site/'app/controllers/ReminderController.php'
    controller.write_bytes(controller.read_bytes().replace(
        b'class ReminderController', b'// Personalizacion conservada\nclass ReminderController'))
    before = {n: module.lectura(site/n) for n in module.RUTAS}

    def run(*args, ok=True):
        result = subprocess.run([sys.executable, str(repo/'tools/publicar_agenda_alertas.py'),
            '--site', str(site), '--repo', str(repo)] + list(args), capture_output=True, text=True)
        assert (result.returncode == 0) == ok, result.stdout + result.stderr

    run('--check')
    assert before == {n: module.lectura(site/n) for n in module.RUTAS}
    run('--apply', '--backup-root', str(root/'respaldos'))
    assert b'Personalizacion conservada' in controller.read_bytes()
    installed_js = (site/'public/javascript/recordatorios.js').read_bytes()
    for token in (b'versionComercial', b'avisosDuranteRefresco',
                  b'impe:embudo:confirmacion-resultado', b'const DURACION_TOAST = 8000'):
        assert token in installed_js, 'Se perdió una personalización comercial'
    backup = next((root/'respaldos').iterdir())
    manifest = json.loads((backup/'manifiesto.json').read_text())
    assert all(module.sha(module.lectura(site/n)) == item['despues']
               for n, item in manifest['archivos'].items())
    run('--restore', str(backup))
    assert before == {n: module.lectura(site/n) for n in module.RUTAS}
    (site/'public/javascript/agenda_alertas.js').write_text('// personalizacion en conflicto')
    run('--check', ok=False)
print('Publicador: check, integración personalizada, integridad, restauración y rechazo de conflicto verificados.')
