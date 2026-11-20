"""Valida los deltas contra las siete huellas exactas proporcionadas por el usuario."""
from pathlib import Path
import hashlib
import json
import os
import subprocess
import tempfile
from build import ROOT, build, FILES, BASELINE
from conciliar_hostinger import extend

if not (ROOT / 'tools/estabilidad_403_503/additional_candidates/app/controllers/ReminderController.php').exists():
    print('SKIP: prueba privada de huellas Hostinger; las copias de producción no se distribuyen en Git.')
    raise SystemExit(0)

out = build()
changes = json.loads((out / 'cambios.json').read_text(encoding='utf-8'))
sources, candidates = extend(ROOT, changes)
expected = {
    'app/controllers/ReminderController.php': 'f8e5978449c398f1c63628e8282f5f9c2973ce6c3b24b2034412a03fa06f24a1',
    'app/helpers/ReminderHelper.php': 'e7fcf5a409dcafb952b06e946a0925635b315c47bf7af00b19b122bf56fb4684',
    'app/models/AliadoModel.php': 'a1ca14b1366d224d8fbe4471c4ef14b8f72e3c131ad94301c41ed15ec869b53d',
    'app/services/ReminderAgendaFilterService.php': 'f932d3ad0e14dec183ff6ecec031ec1f5002cdade6fe632eed1bf70a2851c3b8',
    'app/services/ReminderReunionFollowupService.php': '61b69455fbe536bca2498af97739cd28968e6eaa4b44f00f49b282cb2f5510e9',
    'app/services/AliadoSeguimientoReminderService.php': '206947c67d36ff0be5446c9bda084b54fd45a6be8956d370fc1f2874f60f34b1',
    'public/javascript/recordatorios.js': '9eb431bfc823c61029d89f6b83ca7a3c49c8a9443eb9517e8f07b424cd3c7556',
}
for file, digest in expected.items():
    assert hashlib.sha256(sources[file].encode()).hexdigest() == digest, file

def run(*args, env=None):
    p = subprocess.run(list(map(str, args)), capture_output=True, text=True, encoding='utf-8', env=env)
    assert p.returncode == 0, p.stdout + p.stderr
    return p.stdout

with tempfile.TemporaryDirectory(prefix='hostinger-anticipos-', dir=ROOT / 'tmp') as temp:
    live = Path(temp) / 'public_html'
    live.mkdir()
    (live / 'index.php').write_text('<?php // fixture\n')
    for file in FILES:
        p = live / file
        p.parent.mkdir(parents=True, exist_ok=True)
        text = sources.get(file)
        if text is None:
            text = subprocess.check_output(['git', 'show', f'{BASELINE}:{file}'], cwd=ROOT).decode()
            start = text.index('$mostrarCentroAvisos =')
            end = text.index('$agendaDisponible =', start)
            text = text[:start] + "$mostrarCentroAvisos = !empty($_SESSION['usuario_id']);\nif ($esMarketingTopbar) { $mostrarCentroAvisos = false; }\n" + text[end:]
        p.write_text(text, encoding='utf-8', newline='\n')
    topbar = (live / 'app/views/layout/topbar.php').read_bytes()
    original = {f: (live / f).read_bytes() for f in FILES}
    assert 'CHECK OK' in run('php', out / 'publicar.php', '--check', live)
    assert original == {f: (live / f).read_bytes() for f in FILES}
    log = run('php', out / 'publicar.php', '--apply', live)
    backup = next(line.split('=', 1)[1] for line in log.splitlines() if line.startswith('RESPALDO='))
    for file, expected_content in candidates.items():
        assert (live / file).read_text(encoding='utf-8') == expected_content, file
    assert (live / 'app/views/layout/topbar.php').read_bytes() == topbar
    assert 'Sin cambios pendientes' in run('php', out / 'publicar.php', '--apply', live)
    env = dict(os.environ, IMPE_REMINDER_TEST_SOURCE_ROOT=str(live))
    print(run('php', ROOT / 'tests/recordatorios_entrega_test.php', env=env).strip())
    env = dict(os.environ, IMPE_REMINDER_TEST_SOURCE=str(live / 'public/javascript/recordatorios.js'), IMPE_REMINDER_TEST_DURATION='8000')
    print(run('node', ROOT / 'tests/recordatorios_anticipados_browser.cjs', env=env).strip())
    run('php', out / 'publicar.php', '--restore', live, backup)
    assert original == {f: (live / f).read_bytes() for f in FILES}
    # Repetir sobre la versión 3d63ebb5 que el usuario ya instaló.
    prior = Path(temp) / 'instalador-previo'
    prior.mkdir()
    for name in ['publicar.php', 'cambios.json']:
        data = subprocess.check_output(['git', 'show', f'3d63ebb598c016b8865607e07b37a120c177130a:tools/recordatorios_hostinger_20261120/{name}'], cwd=ROOT)
        (prior / name).write_bytes(data)
    run('php', prior / 'publicar.php', '--apply', live)
    assert 'CHECK OK' in run('php', out / 'publicar.php', '--check', live)
    run('php', out / 'publicar.php', '--apply', live)
    for file, expected_content in candidates.items():
        assert (live / file).read_text(encoding='utf-8') == expected_content, file
print('OK Hostinger: 7 SHA exactos, topbar sin cambios, instalación/restauración, SQL territorial, permisos y toast/audio/campana con el presentador real de producción.')
