#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador conciliado PDF/DOCX terminado: check, apply y restore. Sin SQL."""
from __future__ import print_function
import argparse
import datetime
import difflib
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile

FILES = {
    'app/controllers/OficioVinculacionController.php': (
        'b79170a9103a7970cc30ff5aeeed59c522bb88c0',
        '3f8a7f5554adf354673713cc56a49f6a4e08fb85'),
    'app/services/OficioDocxPdfService.php': (
        'c33dc457b7df1bd211fae144b52c8c9464a87acf',
        '754de3657ea1bdcde59931744568d6d83bffee6b'),
    'app/services/OficioPdfService.php': (
        '55e06ddb06d636ea7ad160397164ba74b43acf7f',
        'f0d26abf39e51dfbf261a1c3d87efc9548f019d9'),
    'public/javascript/oficios_vinculacion.js': (
        '67d47938f1d0d44e54e1ebedd1682f8e3d733563',
        '5e6abc12060d207dad0c9e7465a561895bff56f2'),
}


def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def under(base, name, must_exist=True):
    file = base / name
    if file.is_symlink() or base not in file.resolve().parents:
        raise RuntimeError('Ruta no autorizada: ' + name)
    if must_exist and not file.is_file():
        raise RuntimeError('Archivo no encontrado: ' + name)
    return file


def reconcile(current, before, after, name):
    crlf = b'\r\n' in current
    raw = current.replace(b'\r\n', b'\n')
    if raw == after:
        print('YA INSTALADO: ' + name)
        return current
    if raw == before:
        print('COMPATIBLE EXACTO: ' + name)
        return after.replace(b'\n', b'\r\n') if crlf else after
    original = before.splitlines(keepends=True)
    destination = after.splitlines(keepends=True)
    work = raw
    chunks = difflib.SequenceMatcher(None, original, destination, autojunk=False)
    groups = chunks.get_grouped_opcodes(4)
    changed = False
    for group in groups:
        b0, b1 = group[0][1], group[-1][2]
        a0, a1 = group[0][3], group[-1][4]
        old_text = b''.join(original[b0:b1])
        new_text = b''.join(destination[a0:a1])
        if not old_text or old_text == new_text:
            continue
        old_count = work.count(old_text)
        new_count = work.count(new_text)
        if old_count == 1 and new_count == 0:
            work = work.replace(old_text, new_text, 1)
            changed = True
        elif old_count == 0 and new_count == 1:
            continue
        else:
            raise RuntimeError('CONFLICTO de personalización: ' + name + '; sitio intacto')
    if not changed and work == raw:
        print('YA INSTALADO COMPATIBLE: ' + name)
    elif changed:
        print('COMPATIBLE CONCILIADO: ' + name)
    return work.replace(b'\n', b'\r\n') if crlf else work


def lint(data, name, temp):
    candidate = temp / Path(name).name
    candidate.write_bytes(data)
    if name.endswith('.php'):
        cmd = ['php', '-l', str(candidate)]
        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError('ERROR SINTAXIS PHP: ' + name + ' ' +
                               result.stderr.decode('utf-8', 'replace')[:200])
    elif shutil.which('node'):
        cmd = ['node', '--check', str(candidate)]
        result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError('ERROR SINTAXIS JS: ' + name + ' ' +
                               result.stderr.decode('utf-8', 'replace')[:200])
    else:
        print('AVISO: Node.js ausente; JS verificado previamente en GitHub Actions')


def preflight(site, base, target):
    if not (site/'index.php').is_file():
        raise RuntimeError('No se identificó la raíz del CRM')
    original, output = {}, {}
    with tempfile.TemporaryDirectory(prefix='impe-oficio-pdf-final-check-') as tempdir:
        temp = Path(tempdir)
        for name, (expected_base, expected_target) in FILES.items():
            base_data = under(base, name).read_bytes()
            target_data = under(target, name).read_bytes()
            if blob(base_data) != expected_base or blob(target_data) != expected_target:
                raise RuntimeError('INTEGRIDAD GitHub incorrecta: ' + name)
            path = under(site, name)
            source = path.read_bytes()
            patched = reconcile(source, base_data, target_data, name)
            lint(patched, name, temp)
            original[name], output[name] = source, patched
    print('CHECK OK: 4 archivos; PDF terminado sin REDMEX y DOCX conservado')
    print('Sin SQL, sin motor Word externo, sin modificar producción en --check')
    return original, output


def atomic(path, data, mode):
    fd, temp = tempfile.mkstemp(prefix='.impe-oficio-pdf-final-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def safe_private(site, backup):
    if backup == site or backup in site.parents or site in backup.parents:
        raise RuntimeError('El respaldo debe estar fuera de public_html')


def apply(site, base, target, backup):
    safe_private(site, backup)
    original, result = preflight(site, base, target)
    names = [n for n in FILES if original[n] != result[n]]
    if not names:
        print('YA INSTALADO: no se hicieron cambios')
        return
    backup.mkdir(parents=True, mode=0o700, exist_ok=True)
    os.chmod(str(backup), 0o700)
    folder = Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime(
        '%Y%m%d-%H%M%S-'), dir=str(backup)))
    os.chmod(str(folder), 0o700)
    manifest = {'site':str(site), 'files':[]}
    for name in names:
        path = under(site, name)
        b = folder / 'original' / name
        b.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
        b.write_bytes(original[name])
        manifest['files'].append({
            'path':name, 'before':sha(original[name]),
            'after':sha(result[name]), 'mode':stat.S_IMODE(path.stat().st_mode)})
    (folder/'respaldo.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
    if any(under(site,n).read_bytes() != original[n] for n in names):
        raise RuntimeError('CONFLICTO concurrente; sin publicar')
    installed = []
    try:
        for item in manifest['files']:
            name = item['path']
            path = under(site,name)
            if path.read_bytes() != original[name]:
                raise RuntimeError('CONFLICTO concurrente en ' + name)
            atomic(path, result[name], item['mode'])
            installed.append(item)
            if path.read_bytes() != result[name]:
                raise RuntimeError('Falló verificación de escritura: ' + name)
    except BaseException:
        for item in reversed(installed):
            name = item['path']
            path = under(site,name)
            if path.read_bytes() == result[name]:
                atomic(path, original[name], item['mode'])
        raise
    print('APLICADO: PDF o DOCX terminado y adjuntar PDF a folio existente')
    print('RESPALDO: ' + str(folder))
    print('Sin SQL, sin reemplazar documentos guardados ni modificar otros módulos')


def restore(site, backup, folderName):
    folder = Path(folderName).resolve()
    safe_private(site, backup)
    if backup not in folder.parents:
        raise RuntimeError('Respaldo fuera de carpeta autorizada')
    manifest = json.loads((folder/'respaldo.json').read_text(encoding='utf-8'))
    if manifest.get('site') != str(site):
        raise RuntimeError('Este respaldo corresponde a otra instalación')
    changes = []
    for item in manifest['files']:
        name = item['path']
        if name not in FILES:
            raise RuntimeError('Ruta no autorizada en el respaldo')
        path = under(site,name)
        saved = (folder/'original'/name)
        if not saved.is_file() or saved.is_symlink():
            raise RuntimeError('Archivo original no disponible')
        data = saved.read_bytes()
        if sha(data) != item['before'] or sha(path.read_bytes()) != item['after']:
            raise RuntimeError('CONFLICTO: archivos modificados desde publicación: ' + name)
        changes.append((path,data,int(item['mode'])))
    for path, data, mode in reversed(changes):
        atomic(path,data,mode)
    print('RESTAURADO: 4 archivos como máximo; sin SQL')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--base-dir',required=True)
    parser.add_argument('--target-dir',required=True)
    parser.add_argument('--backup-root',required=True)
    actions=parser.add_mutually_exclusive_group(required=True)
    actions.add_argument('--check',action='store_true')
    actions.add_argument('--apply',action='store_true')
    actions.add_argument('--restore')
    args=parser.parse_args()
    site=Path(args.site).resolve()
    base=Path(args.base_dir).resolve()
    target=Path(args.target_dir).resolve()
    backup=Path(args.backup_root).resolve()
    if args.check:
        preflight(site,base,target)
    elif args.apply:
        apply(site,base,target,backup)
    else:
        restore(site,backup,args.restore)


if __name__=='__main__':
    try:
        main()
    except Exception as exc:
        print('ERROR: ' + str(exc), file=sys.stderr)
        sys.exit(1)
