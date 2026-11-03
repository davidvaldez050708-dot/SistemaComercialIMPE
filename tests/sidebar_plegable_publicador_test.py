"""Ejercita instalación/restauración sólo en directorios temporales, sin SQL."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


publisher = module('publisher', 'tools/publicar_sidebar_plegable.py')
builder = module('builder', 'tools/preparar_sidebar_plegable_release.py')
manifest = json.loads((ROOT / 'tools/sidebar_plegable_release.json').read_text(encoding='utf-8'))


def decoded_release(value):
    # gzip incluye el sistema operativo del generador: Windows y Linux pueden
    # producir bytes comprimidos distintos para las mismas fuentes exactas.
    value = json.loads(json.dumps(value))
    for row in value['files']:
        for field in ['base', 'target']:
            row[field] = publisher.unpack(row[field])
    return value


assert decoded_release(manifest) == decoded_release(builder.build()), 'El manifiesto debe corresponder a las fuentes'
assert {row['path']: row['sha256'] for row in manifest['dependencies']} == builder.DEPENDENCIES
assert publisher.sha(publisher.unpack(manifest['files'][-1]['base'])) == builder.BASE_SHA

with tempfile.TemporaryDirectory(prefix='sidebar-publicador-') as temp:
    site, backup = Path(temp) / 'sitio', Path(temp) / 'privado'
    site.mkdir()
    (site / 'index.php').write_text('<?php', encoding='utf-8')
    top = site / builder.TOPBAR
    top.parent.mkdir(parents=True)
    top.write_bytes(publisher.unpack(manifest['files'][-1]['base']))
    # Estas tres dependencias nunca se escriben. En el sitio temporal usamos
    # sus fuentes del repositorio y hashes de prueba; el manifiesto publicado
    # mantiene exactamente los tres hashes proporcionados por Hostinger.
    test_manifest = json.loads(json.dumps(manifest))
    for row in test_manifest['dependencies']:
        data = (ROOT / row['path']).read_bytes().replace(b'\r\n', b'\n')
        path = site / row['path']
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        row['sha256'] = publisher.sha(data)
    manifest_path = Path(temp) / 'manifest-pruebas.json'
    manifest_path.write_text(json.dumps(test_manifest), encoding='utf-8')
    args = ['publicador', '--site', str(site), '--backup-root', str(backup), '--manifest', str(manifest_path)]

    def run(*flags):
        with mock.patch.object(sys, 'argv', args + list(flags)), contextlib.redirect_stdout(io.StringIO()):
            publisher.main()

    def snapshot():
        return {p.relative_to(site).as_posix(): p.read_bytes() for p in site.rglob('*') if p.is_file()}

    original = snapshot()
    run('--check')
    assert snapshot() == original
    top.write_bytes(top.read_bytes() + b'\n<!-- cambio local -->')
    changed = snapshot()
    try:
        run('--apply')
        raise AssertionError('Debió bloquear la cabecera desconocida')
    except RuntimeError as error:
        assert 'VERSIÓN DIFERENTE' in str(error)
    assert snapshot() == changed
    top.write_bytes(original[builder.TOPBAR])
    dependency = site / publisher.DEPENDENCIES[0]
    dependency.write_bytes(dependency.read_bytes() + b'\n<!-- variante -->')
    changed = snapshot()
    try:
        run('--apply')
        raise AssertionError('Debió bloquear la dependencia desconocida')
    except RuntimeError as error:
        assert 'DEPENDENCIA DIFERENTE' in str(error)
    assert snapshot() == changed
    dependency.write_bytes(original[publisher.DEPENDENCIES[0]])
    run('--apply')
    installed = snapshot()
    for name in publisher.FILES:
        assert (site / name).read_bytes() == (ROOT / name).read_bytes().replace(b'\r\n', b'\n')
    assert all(installed[name] == original[name] for name in publisher.DEPENDENCIES)
    run('--apply')
    assert snapshot() == installed
    saved = sorted(backup.glob('sidebar-*'))[-1]
    top.write_bytes(top.read_bytes() + b'\n<!-- posterior -->')
    changed = snapshot()
    try:
        run('--restore', str(saved))
        raise AssertionError('No debe sobrescribir un cambio posterior')
    except RuntimeError as error:
        assert 'modificado' in str(error)
    assert snapshot() == changed
    top.write_bytes(installed[builder.TOPBAR])
    run('--restore', str(saved))
    assert snapshot() == original
    real_atomic, writes = publisher.atomic, [0]

    def failing_atomic(*params, **kwargs):
        writes[0] += 1
        if writes[0] == 2:
            raise OSError('Fallo de escritura simulado')
        return real_atomic(*params, **kwargs)

    with mock.patch.object(publisher, 'atomic', failing_atomic):
        try:
            run('--apply')
            raise AssertionError('Debió fallar y revertir')
        except OSError:
            pass
    assert snapshot() == original
    for name in ['../fuera.php', 'app/controllers/TelefoniaController.php']:
        try:
            publisher.target(site, name)
            raise AssertionError('Debió bloquear la ruta')
        except RuntimeError:
            pass
print('PASS: manifiesto, check sin cambios, bloqueo de variantes/dependencias, instalación exacta, idempotencia, restauración protegida y rollback. Sin SQL.')
