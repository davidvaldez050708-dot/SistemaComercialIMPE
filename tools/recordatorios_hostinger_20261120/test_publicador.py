"""Prueba el publicador real sobre copias temporales, sin BD ni red."""
from pathlib import Path
import hashlib
import os
import subprocess
import tempfile
from build import ROOT, FILES, BASELINE, build

def run(script, mode, live, *args, ok=True):
    p = subprocess.run(['php', str(script), mode, str(live), *map(str, args)], capture_output=True, text=True, encoding='utf-8')
    assert (p.returncode == 0) == ok, p.stdout + p.stderr
    assert not (live.parent / '.impe-anticipos-deploy.lock').exists(), 'Quedó bloqueo'
    return p.stdout + p.stderr

def hashes(live):
    return {f: hashlib.sha256((live / f).read_bytes()).hexdigest() for f in FILES}

out = build()
script = out / 'publicar.php'
with tempfile.TemporaryDirectory(prefix='anticipos-', dir=ROOT / 'tmp') as temp:
    live = Path(temp) / 'public_html'
    live.mkdir()
    (live / 'index.php').write_text('<?php // fixture\n')
    for file in FILES:
        target = live / file
        target.parent.mkdir(parents=True, exist_ok=True)
        data = subprocess.check_output(['git', 'show', f'{BASELINE}:{file}'], cwd=ROOT)
        target.write_bytes(data)
    # Personalización fuera de los bloques del parche, como en Hostinger.
    helper = live / 'app/helpers/ReminderHelper.php'
    helper.write_bytes(helper.read_bytes() + b'\n// Personalizacion del hosting.\n')
    original = hashes(live)
    assert 'CHECK OK' in run(script, '--check', live)
    assert hashes(live) == original
    result = run(script, '--apply', live)
    backup = next(line.split('=', 1)[1] for line in result.splitlines() if line.startswith('RESPALDO='))
    published = hashes(live)
    assert published != original
    assert b'Personalizacion del hosting' in helper.read_bytes()
    assert 'Sin cambios pendientes' in run(script, '--apply', live)
    assert hashes(live) == published
    run(script, '--restore', live, backup)
    assert hashes(live) == original
    # Una variante incompatible se rechaza sin escribir ninguno de los siete archivos.
    helper.write_text('<?php // variante no reconocida\n', encoding='utf-8')
    conflicting = hashes(live)
    assert 'Variante no reconocida' in run(script, '--apply', live, ok=False)
    assert hashes(live) == conflicting
print('OK publicador: check sin escritura, apply, personalizaciones, idempotencia, restauracion y rechazo de conflictos antes de modificar el sitio.')
