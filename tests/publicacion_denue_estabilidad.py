"""Publicación en carpetas efímeras: variantes, rechazo, instalación y reversión."""
from pathlib import Path
import base64, gzip, hashlib, json, os, shutil, subprocess, sys, tempfile

ROOT = Path(__file__).resolve().parents[1]
manifest = json.loads((ROOT/'tools/denue_estabilidad_release.json').read_text())
installer = ROOT/'tools/publicar_denue_estabilidad.py'
controller = 'app/controllers/SeguimientoVinculacionController.php'
sha = lambda data: hashlib.sha256(data).hexdigest()

def run(site, backup, *args, ok=True):
    proc = subprocess.run([sys.executable, str(installer), '--site', str(site), '--backup-root', str(backup), *args],
                          cwd=str(ROOT), stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True)
    assert (proc.returncode == 0) == ok, proc.stdout+proc.stderr
    return proc.stdout

def snapshot(site):
    return {p.relative_to(site).as_posix(): p.read_bytes() for p in site.rglob('*') if p.is_file()}

with tempfile.TemporaryDirectory(prefix='impe-denue-release-') as directory:
    root = Path(directory)
    controller_variants = next(r for r in manifest['files'] if r['path'] == controller)['variants']
    for variant in range(len(controller_variants)):
        site, backup = root/f'site{variant}', root/f'backup{variant}'
        site.mkdir()
        (site/'index.php').write_text('<?php // fixture')
        for row in manifest['files']:
            v = row['variants'][variant if row['path'] == controller else (-1 if variant >= 2 else 0)]
            if v['before'] is not None:
                data = gzip.decompress(base64.b64decode(v['base']))
                assert sha(data) == v['before']
                p = site/row['path']; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(data)
        before = snapshot(site)
        assert 'CHECK OK' in run(site, backup, '--check')
        assert snapshot(site) == before and not backup.exists(), 'CHECK no escribe'
        p = site/controller
        p.write_bytes(p.read_bytes()+b'\n// variante desconocida')
        altered = snapshot(site)
        run(site, backup, '--apply', ok=False)
        assert snapshot(site) == altered, 'Bloqueo sin instalación parcial'
        p.write_bytes(before[controller])
        run(site, backup, '--apply')
        installed = snapshot(site)
        for row in manifest['files']:
            v = row['variants'][variant if row['path'] == controller else (-1 if variant >= 2 else 0)]
            assert sha((site/row['path']).read_bytes()) == v['after']
        run(site, backup, '--apply')
        assert snapshot(site) == installed, 'Instalación idempotente'
        directory_backup = next(x for x in backup.iterdir() if x.is_dir())
        p.write_bytes(p.read_bytes()+b'\n// edicion posterior')
        altered = snapshot(site)
        run(site, backup, '--restore', str(directory_backup), ok=False)
        assert snapshot(site) == altered, 'No pisar ediciones posteriores'
        p.write_bytes(installed[controller])
        run(site, backup, '--restore', str(directory_backup))
        assert snapshot(site) == before, 'Reversión exacta'
    assert controller_variants[1]['before'] == 'cd319b9bdcf1d75a27ff9b3244e7478303b9e51d5eceebe776cff31b24ad414f'
    # Sitio con la paginación 27e6c244 ya instalada: sólo debe actualizar la vista.
    site, backup = root/'site-carga-vista', root/'backup-carga-vista'
    site.mkdir()
    (site/'index.php').write_text('<?php // fixture')
    view = 'app/views/seguimiento_vinculacion/estado.php'
    for row in manifest['files']:
        v = row['variants'][3 if row['path'] == controller else -1]
        data = gzip.decompress(base64.b64decode(v['base' if row['path'] == view else 'target']))
        p = site/row['path']; p.parent.mkdir(parents=True, exist_ok=True); p.write_bytes(data)
    before = snapshot(site)
    output = run(site, backup, '--check')
    assert output.count('COMPATIBLE: ') == 1 and 'COMPATIBLE: '+view in output, output
    assert snapshot(site) == before and not backup.exists(), 'CHECK del parche sin escrituras'
    run(site, backup, '--apply')
    after = snapshot(site)
    assert [p for p in after if after[p] != before.get(p)] == [view], 'Sólo cambia la vista; los otros siete archivos quedan intactos'
    directory_backup = next(x for x in backup.iterdir() if x.is_dir())
    run(site, backup, '--restore', str(directory_backup))
    assert snapshot(site) == before, 'Reversión exacta del parche de carga'
    if os.name == 'posix':
        # Hash correcto no basta: un archivo público 600 devuelve 403 en Hostinger.
        site, backup = root/'site-permisos', root/'backup-permisos'
        site.mkdir()
        (site/'index.php').write_text('<?php // fixture')
        for row in manifest['files']:
            v = row['variants'][3 if row['path'] == controller else -1]
            p = site/row['path']; p.parent.mkdir(parents=True, exist_ok=True)
            p.write_bytes(gzip.decompress(base64.b64decode(v['target'])))
            p.chmod(0o600)
        js = site/'public/javascript/seguimiento_candidatos_paginacion.js'
        before = snapshot(site)
        modes = {p: (site/p).stat().st_mode & 0o777 for p in before}
        output = run(site, backup, '--check')
        assert '600 -> 644' in output and output.count('COMPATIBLE: ') == 1, output
        assert snapshot(site) == before and js.stat().st_mode & 0o777 == 0o600 and not backup.exists(), 'CHECK detecta permisos sin cambiarlos'
        run(site, backup, '--apply')
        assert snapshot(site) == before, 'Reparar permisos sin cambiar un byte del paquete'
        assert js.stat().st_mode & 0o777 == 0o644, 'JavaScript legible por HTTP'
        assert all((site/p).stat().st_mode & 0o777 == mode for p, mode in modes.items() if site/p != js), 'No cambiar permisos de archivos privados'
        run(site, backup, '--apply')
        directories = [x for x in backup.iterdir() if x.is_dir()]
        assert len(directories) == 1, 'Reparación idempotente'
        js.chmod(0o640)
        run(site, backup, '--restore', str(directories[0]), ok=False)
        assert js.stat().st_mode & 0o777 == 0o640, 'No pisar cambios posteriores de permisos'
        js.chmod(0o644)
        run(site, backup, '--restore', str(directories[0]))
        assert snapshot(site) == before and js.stat().st_mode & 0o777 == 0o600, 'Reversión exacta del contenido y permiso original'
        print('Permisos POSIX: detectar 600, reparar sólo JS a 644, preservar privados y revertir OK')
print('Publicación DENUE: cuatro variantes y parche de vista, sólo lectura, bloqueo, integridad, idempotencia y reversión OK')
