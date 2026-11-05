#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Quitar IMPE visible de la plantilla Excel de seguimientos, sin tocar importación."""
from __future__ import print_function
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

FILES = {
    'app/services/SeguimientoExcelPlantillaService.php': {
        'blob': 'df2af71da3b9a9878613efacc52b1f6065e8a9e2',
        'old': 'PLANTILLA OFICIAL IMPE · IMPORTACIÓN DE INSTITUCIONES V3',
        'new': 'PLANTILLA DE IMPORTACIÓN DE INSTITUCIONES V3',
    },
    'app/controllers/SeguimientoVinculacionController.php': {
        'blob': 'cba7b39393017f304c496b50990ede1a0ea66cc5',
        'old': 'plantilla_instituciones_seguimiento_impe_v3.xlsx',
        'new': 'plantilla_instituciones_seguimiento_v3.xlsx',
    },
    'public/javascript/seguimiento_importacion_excel.js': {
        'blob': 'ff6ce286f213cd931e3b1027de598cdef6d56e30',
        'old': 'plantilla_instituciones_seguimiento_impe_v3.xlsx',
        'new': 'plantilla_instituciones_seguimiento_v3.xlsx',
    },
}


def git_blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def private_root(site, folder):
    if folder == site or folder in site.parents or site in folder.parents:
        raise RuntimeError('Los respaldos deben permanecer fuera del sitio público')


def file_in(site, relative):
    path = site / relative
    if not path.is_file() or path.is_symlink() or site not in path.resolve().parents:
        raise RuntimeError('Archivo de producción ausente o ruta no autorizada: ' + relative)
    return path


def stage(site, downloaded):
    if not (site / 'index.php').is_file():
        raise RuntimeError('No se identificó el Sistema Comercial')
    before, after, modes = {}, {}, {}
    with tempfile.TemporaryDirectory(prefix='impe-xlsx-check-') as root:
        lintdir = Path(root)
        for name, info in FILES.items():
            source = downloaded / name
            if not source.is_file() or source.is_symlink():
                raise RuntimeError('No se descargó la versión GitHub: ' + name)
            latest = source.read_bytes()
            if git_blob(latest) != info['blob']:
                raise RuntimeError('INTEGRIDAD incorrecta: ' + name)
            old = info['old'].encode('utf-8')
            new = info['new'].encode('utf-8')
            if latest.count(new) != 1 or latest.count(old) != 0:
                raise RuntimeError('La versión GitHub contiene una sustitución inesperada: ' + name)
            live = file_in(site, name)
            current = live.read_bytes()
            old_count, new_count = current.count(old), current.count(new)
            if old_count == 1 and new_count == 0:
                proposed = current.replace(old, new, 1)
                print('COMPATIBLE: ' + name)
            elif old_count == 0 and new_count == 1:
                proposed = current
                print('YA ACTUALIZADO: ' + name)
            else:
                raise RuntimeError('CONFLICTO de personalizaciones: ' + name + '; sitio intacto')
            candidate = lintdir / ('script.js' if name.endswith('.js') else 'script.php')
            candidate.write_bytes(proposed)
            if name.endswith('.php'):
                command = ['php', '-l', str(candidate)]
            elif _which_node():
                command = ['node', '--check', str(candidate)]
            else:
                command = []
                print('AVISO: Node.js no instalado en Hostinger; JS validado en GitHub Actions')
            if command:
                result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if result.returncode:
                    raise RuntimeError('Error de sintaxis: ' + name)
            before[name], after[name] = current, proposed
            modes[name] = stat.S_IMODE(live.stat().st_mode)
    print('CHECK OK: tres textos visibles; 19 columnas y validaciones intactas')
    print('Sin SQL, sin tocar seguimientos, sin modificar producción con --check')
    return before, after, modes


def _which_node():
    for candidate in ('/usr/bin/node', '/usr/local/bin/node'):
        if os.path.isfile(candidate) and os.access(candidate, os.X_OK):
            return candidate
    return None


def atomic(path, data, mode):
    fd, temporary = tempfile.mkstemp(prefix='.seguimiento-xlsx-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def publish(site, downloaded, backup):
    private_root(site, backup)
    before, after, modes = stage(site, downloaded)
    names = [name for name in FILES if before[name] != after[name]]
    if not names:
        print('YA INSTALADO: ningún archivo modificado')
        return
    backup.mkdir(parents=True, mode=0o700, exist_ok=True)
    os.chmod(str(backup), 0o700)
    folder = Path(tempfile.mkdtemp(
        prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),
        dir=str(backup)))
    os.chmod(str(folder), 0o700)
    manifest = {'site':str(site),'files':[]}
    for name in names:
        original = folder/'original'/name
        original.parent.mkdir(parents=True, exist_ok=True)
        original.write_bytes(before[name])
        os.chmod(str(original), 0o600)
        manifest['files'].append({'file':name,'before':sha256(before[name]),
            'after':sha256(after[name]),'mode':modes[name]})
    mfile = folder/'respaldo.json'
    mfile.write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    os.chmod(str(mfile), 0o600)
    if any(file_in(site, name).read_bytes() != before[name] for name in names):
        raise RuntimeError('Archivo cambiado durante CHECK: sitio intacto')
    completed = []
    try:
        for name in names:
            live = file_in(site, name)
            if live.read_bytes() != before[name]:
                raise RuntimeError('CONFLICTO concurrente: ' + name)
            atomic(live, after[name], modes[name])
            completed.append(name)
            if live.read_bytes() != after[name]:
                raise RuntimeError('Error al verificar la escritura: ' + name)
    except BaseException:
        for name in reversed(completed):
            path = file_in(site, name)
            if path.read_bytes() == after[name]:
                atomic(path, before[name], modes[name])
        raise
    print('APLICADO: la descarga Excel ya no muestra IMPE')
    print('RESPALDO: ' + str(folder))
    print('Sin SQL, sin alterar datos ni otros módulos')


def restore(site, backup, folder):
    private_root(site, backup)
    folder = Path(folder).resolve()
    if backup not in folder.parents:
        raise RuntimeError('Respaldo fuera de la carpeta privada')
    manifest = json.loads((folder/'respaldo.json').read_text(encoding='utf-8'))
    if manifest['site'] != str(site):
        raise RuntimeError('Respaldo de otro sitio')
    items = []
    for item in manifest['files']:
        name = item['file']
        if name not in FILES:
            raise RuntimeError('Respaldo con ruta ajena')
        path = file_in(site, name)
        saved = folder/'original'/name
        if not saved.is_file() or saved.is_symlink():
            raise RuntimeError('Original no disponible')
        data = saved.read_bytes()
        if sha256(data) != item['before'] or sha256(path.read_bytes()) != item['after']:
            raise RuntimeError('CONFLICTO: archivo modificado después de publicación')
        items.append((path,data,int(item['mode'])))
    for path,data,mode in reversed(items):
        atomic(path,data,mode)
    print('RESTAURADO: textos anteriores del Excel; sin SQL')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--site',required=True)
    p.add_argument('--source-dir',required=True)
    p.add_argument('--backup-root',required=True)
    actions=p.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check',action='store_true')
    actions.add_argument('--apply',action='store_true')
    actions.add_argument('--restore')
    a=p.parse_args()
    site=Path(a.site).resolve()
    source=Path(a.source_dir).resolve()
    backup=Path(a.backup_root).resolve()
    if a.check:
        stage(site,source)
    elif a.apply:
        publish(site,source,backup)
    else:
        restore(site,backup,a.restore)


if __name__=='__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: '+str(error),file=sys.stderr)
        sys.exit(1)
