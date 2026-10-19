#!/usr/bin/env python3
"""Prueba en sitio aislado del parche selectivo PBX (sin Hostinger real)."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
js = root / 'public/javascript/seguimiento_llamadas_desplegable.js'
deploy = root / 'tools/publicar_audio_pbx_exp_hostinger.sh'
original = js.read_bytes()
assert original.count(b"if (tarjeta.querySelector('.linkage-incoming-audio-panel')) return;") == 1
assert original.index(b"if (tarjeta.querySelector('.linkage-incoming-audio-panel')) return;") < original.index(
    b"if (tarjeta.querySelector('[data-call-recording-toggle]')) return;"
)
assert b"if (!estadoActual || !llamada.tiene_grabacion || !llamada.grabacion_url)" in original
old_head = (
    b'            // El reproductor de transferencias PBX ya tiene su boton y consulta.\n'
    b'            // No sustituirlo por el reproductor saliente ni abrir su panel vacio.\n'
)
# El JavaScript de GitHub conserva los comentarios acentuados, la prueba
# elimina el bloque por líneas sin alterar el resto del contenido.
old_lines = original.splitlines(keepends=True)
old_source = b''.join(
    line for line in old_lines
    if not (
        b'// El reproductor de transferencias PBX ya tiene su bot' in line
        or b'// No sustituirlo por el reproductor saliente' in line
        or b"if (tarjeta.querySelector('.linkage-incoming-audio-panel')) return;" in line
    )
)
assert old_source != original
assert old_source.count(b"        const convertirBoton = function (tarjeta, llamada) {\n            if (tarjeta.querySelector('[data-call-recording-toggle]')) return;") == 1

with tempfile.TemporaryDirectory(prefix='impe-pbx-regression-') as temp:
    root_test = Path(temp)
    site = root_test / 'web'
    backups = root_test / 'respaldos'
    target = site / 'public/javascript/seguimiento_llamadas_desplegable.js'
    target.parent.mkdir(parents=True)
    (site / 'index.php').write_text('<?php // fixture\n')
    customized = old_source + b'\n// CONSERVAR_PERSONALIZACION_HOSTINGER\n'
    target.write_bytes(customized)
    env = dict(os.environ, IMPE_PBX_AUDIO_LIVE=str(site), IMPE_PBX_AUDIO_BACKUPS=str(backups))

    def run(*args, ok=True):
        result = subprocess.run(['bash', str(deploy), *args], env=env, text=True, capture_output=True)
        if ok:
            assert result.returncode == 0, (result.stdout, result.stderr)
        else:
            assert result.returncode != 0, result.stdout
        return result.stdout + result.stderr

    assert 'SITIO INTACTO' in run('--check')
    assert target.read_bytes() == customized
    assert not backups.exists(), 'La comprobación no crea respaldos'
    assert '--apply terminado' in run('--apply')
    installed = target.read_bytes()
    assert installed.count(b"if (tarjeta.querySelector('.linkage-incoming-audio-panel')) return;") == 1
    assert b'CONSERVAR_PERSONALIZACION_HOSTINGER' in installed
    assert 'ya aplicada' in run('--apply')
    backup_dirs = list(backups.iterdir())
    assert len(backup_dirs) == 1
    assert 'restaurado' in run('--restore', str(backup_dirs[0]))
    assert target.read_bytes() == customized
    run('--restore', str(backup_dirs[0]), ok=False)
    target.write_bytes(b'// variante inesperada\n')
    assert 'Variante Hostinger no reconocida' in run('--check', ok=False)
    assert target.read_bytes() == b'// variante inesperada\n'
print('Audio PBX: protección, personalización, check, apply, idempotencia, rollback y conflicto: OK')
