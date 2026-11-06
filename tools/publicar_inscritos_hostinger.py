#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicación reversible de Inscripciones recibidas (10 archivos, 0 SQL).

--check SOLO LECTURA. --apply con respaldos privados; --restore idempotente.
Preserva personalizaciones de Hostinger fuera de los bloques que cambian.
Requiere Python 3.6+, PHP 8+ y la extensión ZIP para descargar Excel.
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
    'index.php': (
        'b6ba7781166437e2e4fda7504ca516271dedc3c5',
        '54c9ca1811bc0a8bf2956d16846c364e9f2399de'),
    'app/models/RolModel.php': (
        '6063488771d28cb10feb716e295f73bae90c30bf',
        'a05f9325d1326c507009ab59a8ee35785d390820'),
    'app/views/layout/sidebar.php': (
        '7b122360c741e8df189f109b9dcd0ead8797105f',
        'cff892456f130b3802044f5dff92f151932a755d'),
    'app/controllers/InscritosController.php': (
        None, 'ec4cd1fd60b42f1564c420aebc5af75555646c61'),
    'app/models/InscritosModel.php': (
        None, '4fc62e4f2ce150c20c78cb6101bc61df5cb7f21f'),
    'app/helpers/InscritosPermisos.php': (
        None, '52a738347a4ffb02b599c42b453fdf7db4b192d3'),
    'app/services/InscritosExcelService.php': (
        None, '1a7b47d7e3f071dab4373f1f2d8d7912ef020730'),
    'app/views/inscritos/index.php': (
        None, '7a89b09f871cc994bf6eaefc58276f3529302580'),
    'public/css/inscritos.css': (
        None, '3ca712be6ec272db13231d79827e23f1046d1d82'),
    'public/javascript/inscritos.js': (
        None, '38c8f06fb1c481c5e26f808bfda418aa523b9b73')
}
DEPENDENCIES = [
    'app/services/FormularioInscripcionRepository.php',
    'app/services/FormularioInscripcionProgramas.php',
    'app/helpers/FormularioPermisos.php',
    'app/services/FormularioInscripcionPantalla.php'
]


def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def path_under(root, relative):
    path = root / relative
    if path.is_symlink() or root not in path.resolve().parents:
        raise RuntimeError('Ruta no permitida: ' + relative)
    return path


def private_backup(site, backup):
    if backup == site or backup in site.parents or site in backup.parents:
        raise RuntimeError('Carpeta de respaldo debe estar fuera de public_html')


def merge(base, target, current, name):
    """Conciliación de cambios mínimos; nunca sustituye un archivo completo.

    1. Comprueba que los parches sobre la BASE produzcan exactamente TARGET.
    2. Reaplica cada edición local sobre CURRENT, que puede tener otras rutas,
       permisos, clases y bloques personalizados.
    3. Si falta el fragmento preciso, falla SIN escrituras.
    """
    crlf = b'\r\n' in current
    original = current
    old = base.replace(b'\r\n', b'\n')
    new = target.replace(b'\r\n', b'\n')
    current = current.replace(b'\r\n', b'\n')
    if current == new:
        return original

    # El router normalmente recibe nuevos casos de otros módulos en Hostinger.
    # Insertamos SOLO el caso de Inscritos delante de la ruta de Formularios,
    # usando un ancla pequeña y única; nunca comparamos vecinos ajenos.
    if name == 'index.php':
        anchor = b"    case 'formulario':"
        marker = b"    case 'inscritos':"
        if old.count(anchor) != 1 or new.count(marker) != 1 or new.count(anchor) != 1:
            raise RuntimeError('INTEGRIDAD: router no identificable')
        start = new.index(marker)
        stop = new.index(anchor, start)
        addition = new[start:stop]
        if old.replace(anchor, addition + anchor, 1) != new:
            raise RuntimeError('INTEGRIDAD: cambio inesperado en router GitHub')
        if current.count(marker) == 1:
            if current.count(addition) == 1:
                return original
            raise RuntimeError('CONFLICTO: ruta Inscritos ya personalizada; sitio intacto')
        if current.count(anchor) != 1:
            raise RuntimeError('CONFLICTO: ancla Formularios no única en index.php; sitio intacto')
        result = current.replace(anchor, addition + anchor, 1)
        return result.replace(b'\n', b'\r\n') if crlf else result

    # Para Roles y Sidebar usamos opcodes SIN agrupar con seis líneas de
    # contexto. El publicador anterior fallaba cuando un módulo distinto
    # cambiaba alguna línea próxima a las nuevas.
    oldlines = old.splitlines(keepends=True)
    newlines = new.splitlines(keepends=True)
    changes = [item for item in difflib.SequenceMatcher(
        a=oldlines, b=newlines, autojunk=False
    ).get_opcodes() if item[0] != 'equal']

    def patch(data):
        for tag, a1, a2, b1, b2 in changes:
            before = b''.join(oldlines[a1:a2])
            after = b''.join(newlines[b1:b2])
            if before:
                # Sidebar tiene dos copias idénticas del guard de Marketing
                # (desktop/móvil). Ambas deben cambiar y ninguna otra.
                if (name == 'app/views/layout/sidebar.php'
                        and b'$mostrarConvocatorias || $mostrarConvenios' in before
                        and old.count(before) == 2 and new.count(after) == 2):
                    pendientes = data.count(before)
                    aplicadas = data.count(after)
                    if pendientes + aplicadas != 2:
                        raise RuntimeError('CONFLICTO: guard de sidebar duplicado o alterado')
                    data = data.replace(before, after)
                    continue
                if data.count(before) == 1:
                    data = data.replace(before, after, 1)
                    continue
                if after and data.count(after) == 1:
                    continue  # Edición ya aplicada en Hostinger.
                raise RuntimeError('CONFLICTO: edición existente de ' + name
                                   + ' no coincide; sitio intacto')
            # Insertar antes de una ancla inmediata, única y no modificada.
            # Se amplía SOLO lo indispensable para evitar falsos positivos.
            anchored = False
            for size in range(1, min(16, len(oldlines) - a1) + 1):
                anchor = b''.join(oldlines[a1:a1 + size])
                if not anchor or data.count(anchor) != 1:
                    continue
                if data.count(after + anchor) == 1:
                    anchored = True
                    break
                if after in data:
                    raise RuntimeError('CONFLICTO: bloque insertado distinto en '
                                       + name + '; sitio intacto')
                data = data.replace(anchor, after + anchor, 1)
                anchored = True
                break
            if not anchored:
                raise RuntimeError('CONFLICTO: no hay ancla única para inserción en '
                                   + name + '; sitio intacto')
        return data

    if patch(old) != new:
        raise RuntimeError('INTEGRIDAD: parches del archivo base no reproducen destino: '
                           + name)
    result = patch(current)
    return result.replace(b'\n', b'\r\n') if crlf else result


def check(site, base, target):
    if not (site / 'index.php').is_file():
        raise RuntimeError('No se encontró la raíz del CRM en --site')
    for name in DEPENDENCIES:
        dep = path_under(site, name)
        if not dep.is_file():
            raise RuntimeError('Dependencia del CRM ausente: ' + name)
    php_zip = subprocess.run(
        ['php', '-r', 'exit(class_exists("ZipArchive") ? 0 : 1);'],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE
    )
    if php_zip.returncode != 0:
        raise RuntimeError('PHP ZIP no disponible: XLSX requiere ZipArchive')

    before, after, modes = {}, {}, {}
    for name, (oldhash, newhash) in FILES.items():
        candidate = path_under(target, name)
        if not candidate.is_file() or blob(candidate.read_bytes()) != newhash:
            raise RuntimeError('INTEGRIDAD destino GitHub incorrecta: ' + name)
        patched = candidate.read_bytes()
        live = path_under(site, name)
        if oldhash is None:
            if live.exists():
                if not live.is_file() or live.read_bytes() != patched:
                    raise RuntimeError('CONFLICTO: archivo nuevo existente: ' + name)
                original = live.read_bytes()
                print('YA INSTALADO: ' + name)
            else:
                original = None
                print('ARCHIVO NUEVO COMPATIBLE: ' + name)
            mode = stat.S_IMODE(live.stat().st_mode) if original is not None else 0o644
        else:
            source = path_under(base, name)
            if not source.is_file() or blob(source.read_bytes()) != oldhash:
                raise RuntimeError('INTEGRIDAD base GitHub incorrecta: ' + name)
            if not live.is_file():
                raise RuntimeError('Archivo del CRM ausente: ' + name)
            original = live.read_bytes()
            patched = merge(source.read_bytes(), patched, original, name)
            mode = stat.S_IMODE(live.stat().st_mode)
            if patched == original:
                print('YA INSTALADO COMPATIBLE: ' + name)
            else:
                print('COMPATIBLE CONCILIADO: ' + name)
        if name.endswith('.php'):
            fd, temp = tempfile.mkstemp(prefix='impe-inscritos-lint-', suffix='.php')
            try:
                with os.fdopen(fd, 'wb') as out:
                    out.write(patched)
                result = subprocess.run(['php', '-l', temp],
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if result.returncode:
                    raise RuntimeError('SINTAXIS PHP: ' + name)
            finally:
                os.unlink(temp)
        elif name.endswith('.js') and subprocess.run(
            ['php', '-r', 'exit(0);'], stdout=subprocess.PIPE,
            stderr=subprocess.PIPE
        ).returncode == 0:
            # JS linter opcional; Node.js puede no estar instalado en Hostinger.
            import shutil
            if shutil.which('node'):
                result = subprocess.run(['node','--check',str(path_under(target,name))],
                                        stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                if result.returncode:
                    raise RuntimeError('SINTAXIS JS: ' + name)
        before[name], after[name], modes[name] = original, patched, mode

    print('CHECK OK: Inscripciones recibidas, filtros, fichas y Excel; 10 archivos')
    print('Sin SQL, prospectos, formularios, llamadas ni datos de usuario modificados')
    return before, after, modes


def atomic(path, data, mode):
    fd, temp = tempfile.mkstemp(prefix='.inscritos-seguro-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def apply(site, base, target, backup):
    private_backup(site, backup)
    before, after, modes = check(site, base, target)
    changes = [p for p in FILES if before[p] != after[p]]
    if not changes:
        print('YA INSTALADO: ninguna escritura')
        return
    backup.mkdir(parents=True, mode=0o700, exist_ok=True)
    os.chmod(str(backup), 0o700)
    folder = Path(tempfile.mkdtemp(
        prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),
        dir=str(backup)))
    os.chmod(str(folder), 0o700)
    meta = {'site': str(site), 'files': []}
    for name in changes:
        row = {'path': name, 'new': before[name] is None,
               'old_sha256': digest(before[name]) if before[name] is not None else None,
               'new_sha256': digest(after[name]), 'mode': modes[name]}
        if before[name] is not None:
            saved = folder / 'original' / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(before[name])
            os.chmod(str(saved), 0o600)
        meta['files'].append(row)
    manifest = folder / 'respaldo.json'
    manifest.write_text(json.dumps(meta, indent=2), encoding='utf-8')
    os.chmod(str(manifest), 0o600)

    # Validar los 10 archivos antes de aplicar el primero.
    for name in changes:
        dest = path_under(site,name)
        old = before[name]
        if (old is None and dest.exists()) or (
            old is not None and (not dest.is_file() or dest.read_bytes() != old)):
            raise RuntimeError('CONFLICTO concurrente: ' + name)

    written = []
    try:
        for name in changes:
            dest = path_under(site,name)
            dest.parent.mkdir(parents=True, exist_ok=True)
            atomic(dest,after[name],modes[name])
            written.append(name)
            if dest.read_bytes() != after[name]:
                raise RuntimeError('Fallo de integridad tras publicar: ' + name)
    except BaseException:
        for name in reversed(written):
            dest=path_under(site,name)
            if dest.is_file() and dest.read_bytes()==after[name]:
                if before[name] is None:
                    dest.unlink()
                else:
                    atomic(dest,before[name],modes[name])
        raise
    print('APLICADO: Inscripciones recibidas con filtros, ficha y Excel')
    print('RESPALDO: ' + str(folder))


def restore(site, backup, folder):
    private_backup(site, backup)
    folder=Path(folder).resolve()
    if backup not in folder.parents or not folder.is_dir() or folder.is_symlink():
        raise RuntimeError('Ruta de respaldo no autorizada')
    manifest=folder/'respaldo.json'
    if not manifest.is_file() or manifest.is_symlink():
        raise RuntimeError('No existe manifiesto de respaldo')
    meta=json.loads(manifest.read_text(encoding='utf-8'))
    if meta.get('site')!=str(site):
        raise RuntimeError('Respaldo de otra instalación')
    rows=[]
    for entry in meta['files']:
        name=entry['path']
        if name not in FILES:
            raise RuntimeError('Ruta no autorizada en respaldo')
        dest=path_under(site,name)
        if not dest.is_file() or digest(dest.read_bytes()) != entry['new_sha256']:
            raise RuntimeError('CONFLICTO: el archivo cambió desde publicación: ' + name)
        old=None
        if not entry['new']:
            source=folder/'original'/name
            if not source.is_file() or source.is_symlink():
                raise RuntimeError('Original ausente: ' + name)
            old=source.read_bytes()
            if digest(old)!=entry['old_sha256']:
                raise RuntimeError('Original de respaldo no íntegro: ' + name)
        rows.append((dest,old,int(entry['mode'])))
    for dest,old,mode in reversed(rows):
        if old is None:
            dest.unlink()
        else:
            atomic(dest,old,mode)
    print('RESTAURADO: archivos de Inscritos; sin modificaciones de la BD')


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
    a=p.parse_args()
    site=Path(a.site).resolve()
    base=Path(a.base_dir).resolve()
    target=Path(a.target_dir).resolve()
    backup=Path(a.backup_root).resolve()
    if a.check:
        check(site,base,target)
    elif a.apply:
        apply(site,base,target,backup)
    else:
        restore(site,backup,a.restore)


if __name__=='__main__':
    try:
        main()
    except Exception as e:
        print('ERROR: '+str(e),file=sys.stderr)
        sys.exit(1)
