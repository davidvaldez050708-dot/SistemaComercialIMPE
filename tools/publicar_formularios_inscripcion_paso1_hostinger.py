#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Inscripción paso 1: publicar sólo panel y selector interno. Sin BD ni enlaces públicos.

Comandos: --check | --apply | --restore RUTA_RESPALDO
Requiere PHP en PATH, Python >=3.6 y fuentes descargadas con integridad SHA Git.
"""
from __future__ import print_function
import argparse
import datetime
import difflib
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

FILES = {
    'app/controllers/FormularioController.php': (
        '908d16a6fcd026b466373597b95e1fcc416489ad',
        '797a249c63e303839b28504855605e6c416039ed'),
    'app/views/formularios/inscripcion.php': (
        '58768731ca4ea9c3deb853746b1b90e4ebfae660',
        '491358ea678291978947a37082e3bb793f523cae'),
    'public/css/formularios.css': (
        '7c7b75b0d6ab44ebb16122273711d3d7d4da94ed',
        '6801ffb25f04dae03265e07eeeccf38a6932366e'),
    'app/services/FormularioInscripcionProgramas.php': (
        None, '14ccdb453e8ad6a31287047cd9714ce3954b5b41'),
    'app/views/formularios/inscripcion_programas.php': (
        None, '40b8b19bb77e2c41aa737eeb6fd74b883d8b1a35'),
}


def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def controlled(site, name):
    path = site / name
    if site not in path.resolve().parents or path.is_symlink():
        raise RuntimeError('Ruta fuera del CRM o enlace simbólico: ' + name)
    return path


def private(site, backups):
    if backups == site or backups in site.parents or site in backups.parents:
        raise RuntimeError('Respaldo debe estar fuera de public_html')


def merge_existing(actual, old, new, name):
    crlf = b'\r\n' in actual
    clean = actual.replace(b'\r\n', b'\n')
    if clean == new:
        print('YA INSTALADO: ' + name)
        return actual
    if clean == old:
        print('COMPATIBLE EXACTO: ' + name)
        return new.replace(b'\n', b'\r\n') if crlf else new

    if name.endswith('/formularios.css'):
        if new.startswith(old):
            addition = new[len(old):]
            if b'INSCRIPCI\xc3\x93N \xc2\xb7 PASO 1: CAT\xc3\x81LOGO' in clean:
                if addition.strip() in clean:
                    print('YA INSTALADO PERSONALIZADO: ' + name)
                    return actual
                raise RuntimeError('CONFLICTO de estilos del catálogo: ' + name)
            result = clean.rstrip(b'\n') + b'\n' + addition
            print('COMPATIBLE CSS PERSONALIZADO: ' + name)
            return result.replace(b'\n', b'\r\n') if crlf else result

    b_lines = old.splitlines(keepends=True)
    t_lines = new.splitlines(keepends=True)
    groups = list(difflib.SequenceMatcher(
        None, b_lines, t_lines, autojunk=False).get_grouped_opcodes(3))
    result = clean
    for group in groups:
        b0, b1 = group[0][1], group[-1][2]
        t0, t1 = group[0][3], group[-1][4]
        old_segment = b''.join(b_lines[b0:b1])
        new_segment = b''.join(t_lines[t0:t1])
        if not old_segment or old_segment == new_segment:
            continue
        if result.count(old_segment) == 1 and result.count(new_segment) == 0:
            result = result.replace(old_segment, new_segment, 1)
        elif result.count(old_segment) == 0 and result.count(new_segment) == 1:
            continue
        else:
            raise RuntimeError('CONFLICTO de personalización en ' + name + '; sitio intacto')
    if result == clean:
        print('YA INSTALADO PERSONALIZADO: ' + name)
    else:
        print('COMPATIBLE CONCILIADO: ' + name)
    return result.replace(b'\n', b'\r\n') if crlf else result


def check(site, base, target):
    if not (site / 'index.php').is_file():
        raise RuntimeError('No se identificó el CRM en --site')
    before = {}
    after = {}
    modes = {}
    with tempfile.TemporaryDirectory(prefix='impe-inscripcion-check-') as tmp:
        for index, (name, blobs) in enumerate(FILES.items()):
            origin = controlled(site, name)
            candidate = controlled(target, name)
            if not candidate.is_file():
                raise RuntimeError('Descarga destino ausente: ' + name)
            newer = candidate.read_bytes()
            if blob(newer) != blobs[1]:
                raise RuntimeError('Integridad GitHub destino incorrecta: ' + name)
            if blobs[0] is None:
                if origin.exists():
                    if not origin.is_file() or origin.read_bytes() != newer:
                        raise RuntimeError('CONFLICTO: archivo nuevo ya personalizado: ' + name)
                    old_data = newer
                    patched = newer
                    print('YA INSTALADO NUEVO: ' + name)
                else:
                    old_data = None
                    patched = newer
                    print('ARCHIVO NUEVO COMPATIBLE: ' + name)
                modes[name] = 0o644
            else:
                previous = controlled(base, name)
                if not previous.is_file() or blob(previous.read_bytes()) != blobs[0]:
                    raise RuntimeError('Integridad GitHub base incorrecta: ' + name)
                if not origin.is_file():
                    raise RuntimeError('Archivo Hostinger ausente: ' + name)
                old_data = origin.read_bytes()
                patched = merge_existing(old_data, previous.read_bytes(), newer, name)
                modes[name] = stat.S_IMODE(origin.stat().st_mode)
            if name.endswith('.php'):
                lint_path = Path(tmp) / ('check_%d.php' % index)
                lint_path.write_bytes(patched)
                lint = subprocess.run(['php', '-l', str(lint_path)],
                                      stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if lint.returncode != 0:
                    raise RuntimeError('Error sintaxis PHP: ' + name)
            before[name], after[name] = old_data, patched
    print('CHECK OK: panel de cinco programas y captura interna; 5 archivos')
    print('Sin SQL, archivos de usuario, correos ni enlaces públicos activados')
    return before, after, modes


def atomic(path, data, mode):
    fd, tmp = tempfile.mkstemp(prefix='.inscripcion-paso1-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, str(path))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def apply(site, base, target, backups):
    private(site, backups)
    before, after, modes = check(site, base, target)
    changed = [name for name in FILES if before[name] != after[name]]
    if not changed:
        print('YA INSTALADO: no se realizaron escrituras')
        return
    backups.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(str(backups), 0o700)
    folder = Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime(
        '%Y%m%d-%H%M%S-'), dir=str(backups)))
    os.chmod(str(folder), 0o700)
    manifest = {'site': str(site), 'changes': []}
    for name in changed:
        item = {'name':name, 'new':before[name] is None,
                'before': digest(before[name]) if before[name] is not None else None,
                'after': digest(after[name]), 'mode':modes[name]}
        if before[name] is not None:
            saved = folder / 'original' / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(before[name])
            os.chmod(str(saved), 0o600)
        manifest['changes'].append(item)
    meta = folder / 'respaldo.json'
    meta.write_text(json.dumps(manifest,indent=2),encoding='utf-8')
    os.chmod(str(meta),0o600)
    for name in changed:
        live = controlled(site,name)
        expected = before[name]
        if (expected is None and live.exists()) or \
           (expected is not None and (not live.is_file() or live.read_bytes() != expected)):
            raise RuntimeError('CONFLICTO concurrente antes de publicar: ' + name)
    written = []
    try:
        for name in changed:
            live = controlled(site,name)
            live.parent.mkdir(parents=True, exist_ok=True)
            atomic(live,after[name],modes[name])
            written.append(name)
            if live.read_bytes() != after[name]:
                raise RuntimeError('Error al verificar escritura: ' + name)
    except BaseException:
        for name in reversed(written):
            live = controlled(site,name)
            if live.read_bytes() == after[name]:
                if before[name] is None:
                    live.unlink()
                else:
                    atomic(live,before[name],modes[name])
        raise
    print('APLICADO: paso 1, panel de cinco programas de inscripción')
    print('RESPALDO: ' + str(folder))


def restore(site, backups, path):
    private(site, backups)
    folder = Path(path).resolve()
    if backups not in folder.parents:
        raise RuntimeError('Respaldo fuera de carpeta autorizada')
    manifest = json.loads((folder/'respaldo.json').read_text(encoding='utf-8'))
    if manifest.get('site') != str(site):
        raise RuntimeError('El respaldo corresponde a otro sitio')
    planned = []
    for item in manifest['changes']:
        name = item['name']
        if name not in FILES:
            raise RuntimeError('Respaldo contiene ruta no autorizada')
        live = controlled(site,name)
        if not live.is_file() or digest(live.read_bytes()) != item['after']:
            raise RuntimeError('CONFLICTO: hubo cambios posteriores: ' + name)
        if item['new']:
            restored = None
        else:
            saved = folder/'original'/name
            if not saved.is_file() or saved.is_symlink():
                raise RuntimeError('Original de respaldo no encontrado: ' + name)
            restored = saved.read_bytes()
            if digest(restored) != item['before']:
                raise RuntimeError('Integridad del respaldo incorrecta: ' + name)
        planned.append((live,restored,int(item['mode'])))
    for live,data,mode in reversed(planned):
        if data is None:
            live.unlink()
        else:
            atomic(live,data,mode)
    print('RESTAURADO: panel de inscripción, sin SQL')


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--site',required=True)
    p.add_argument('--base-dir',required=True)
    p.add_argument('--target-dir',required=True)
    p.add_argument('--backup-root',required=True)
    action=p.add_mutually_exclusive_group(required=True)
    action.add_argument('--check',action='store_true')
    action.add_argument('--apply',action='store_true')
    action.add_argument('--restore')
    args=p.parse_args()
    site=Path(args.site).resolve()
    base=Path(args.base_dir).resolve()
    target=Path(args.target_dir).resolve()
    backups=Path(args.backup_root).resolve()
    if args.check:
        check(site,base,target)
    elif args.apply:
        apply(site,base,target,backups)
    else:
        restore(site,backups,args.restore)


if __name__=='__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: '+str(error),file=sys.stderr)
        sys.exit(1)
