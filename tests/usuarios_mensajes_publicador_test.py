"""Prueba del paquete en un sitio temporal; no usa credenciales ni SQL."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('publisher', ROOT / 'tools/publicar_usuarios_mensajes.py')
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


def snapshot(site):
    return {name: (site / name).read_bytes() if (site / name).is_file() else None for name in publisher.FILES}


with tempfile.TemporaryDirectory(prefix='usuarios-publicador-') as temp:
    site, backup = Path(temp) / 'sitio', Path(temp) / 'privado'
    site.mkdir()
    (site / 'index.php').write_text('<?php', encoding='utf-8')
    for name, ref in [('app/controllers/UsuarioController.php', '856a14cf'),
                      ('app/views/usuarios/index.php', '856a14cf^')]:
        target = site / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(subprocess.check_output(['git', 'show', ref + ':' + name], cwd=str(ROOT)))
    original = snapshot(site)
    args = ['publicador', '--site', str(site), '--backup-root', str(backup),
            '--manifest', str(ROOT / 'tools/usuarios_mensajes_release.json')]

    def run(*flags):
        with mock.patch.object(sys, 'argv', args + list(flags)), contextlib.redirect_stdout(io.StringIO()):
            publisher.main()

    run('--check')
    assert snapshot(site) == original
    # Una variante desconocida bloquea todo antes de la primera escritura.
    view = site / 'app/views/usuarios/index.php'
    view.write_bytes(original['app/views/usuarios/index.php'] + b'\n<!-- variante -->')
    changed = snapshot(site)
    try:
        run('--apply')
        raise AssertionError('Debió bloquear la variante')
    except RuntimeError as error:
        assert 'VERSIÓN DIFERENTE' in str(error)
    assert snapshot(site) == changed
    view.write_bytes(original['app/views/usuarios/index.php'])
    run('--apply')
    installed = snapshot(site)
    assert all(installed[name] == (ROOT / name).read_bytes().replace(b'\r\n', b'\n') for name in publisher.FILES)
    run('--apply')
    assert snapshot(site) == installed
    restore = next(backup.glob('usuarios-*'))
    run('--restore', str(restore))
    assert snapshot(site) == original
    # Si una escritura falla, revierte los archivos ya instalados.
    real_atomic, writes = publisher.atomic, [0]

    def failing_atomic(*params, **kwargs):
        writes[0] += 1
        if writes[0] == 2:
            raise OSError('Fallo de escritura simulado')
        return real_atomic(*params, **kwargs)

    with mock.patch.object(publisher, 'atomic', failing_atomic):
        try:
            run('--apply')
            raise AssertionError('Debió fallar')
        except OSError:
            pass
    assert snapshot(site) == original
    # El publicador sólo permite los cinco archivos expresamente autorizados.
    try:
        publisher.target(site, '../fuera.php')
        raise AssertionError('Debió bloquear la ruta')
    except RuntimeError:
        pass
print('PASS: check sin cambios, bloqueo de variantes, instalación exacta, idempotencia, restauración, rollback y rutas limitadas.')
