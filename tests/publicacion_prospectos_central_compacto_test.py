#!/usr/bin/env python3
"""Regresión local sin red ni credenciales: paquete visual compatible y bloqueo seguro."""
import hashlib
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tools'))
import publicar_prospectos_central_compacto as publisher

for relative, (_, expected) in publisher.FILES.items():
    actual = hashlib.sha256((ROOT / relative).read_bytes()).hexdigest()
    assert actual == expected, 'Hash de código fuente distinto: ' + relative

with tempfile.TemporaryDirectory(prefix='impe-prospectos-compacto-') as temp:
    site = Path(temp) / 'site'
    site.mkdir()
    (site / 'index.php').write_text('<?php // fixture; sin BD', encoding='utf-8')
    for relative in publisher.FILES:
        file = site / relative
        file.parent.mkdir(parents=True, exist_ok=True)
        file.write_bytes((ROOT / relative).read_bytes())
    before = {relative: (site / relative).read_bytes() for relative in publisher.FILES}
    script = ROOT / 'tools/publicar_prospectos_central_compacto.py'
    for action in ['--check', '--apply']:
        output = subprocess.run([sys.executable, str(script), '--site', str(site), action],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        assert output.returncode == 0, output.stderr.decode('utf-8', 'replace')
        assert all((site / name).read_bytes() == data for name, data in before.items())
    path = site / 'public/css/prospectos_central.css'
    path.write_bytes(path.read_bytes() + b'\n/* cambio no autorizado */\n')
    changed_before = {relative: (site / relative).read_bytes() for relative in publisher.FILES}
    output = subprocess.run([sys.executable, str(script), '--site', str(site), '--check'],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    assert output.returncode != 0
    assert b'VERSI' in output.stderr
    assert all((site / name).read_bytes() == data for name, data in changed_before.items())
print('PASS: hashes actuales, idempotencia y bloqueo de versión desconocida, sin red ni SQL.')
