#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Inscripción Ejecutivas: publicador seguro de vista previa (2 archivos + 5 nuevos)."""
import argparse
import datetime
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
    'app/controllers/FormularioController.php': ('797a249c63e303839b28504855605e6c416039ed', '09b3e310ba6e2b3dffbea8d2d63bdc51b3ba2a56'),
    'app/views/formularios/inscripcion_programas.php': ('40b8b19bb77e2c41aa737eeb6fd74b883d8b1a35', '2f058e7b7eedd4fb59878f31cc1d930d2c8ee047'),
    'app/services/FormularioEjecutivasPerfiles.php': (None, 'd56e160ff5ceb466900c2f200f11dbc2852bd0a1'),
    'app/services/FormularioEjecutivasValidacion.php': (None, 'ced9d772aaf1c35429fb4f2eda5b45de7f2274ad'),
    'app/views/formularios/inscripcion_ejecutivas_previa.php': (None, 'e0b4b85cf53722d27bf0c2c20d73d00f33b2e39c'),
    'public/css/formularios_ejecutivas_piloto.css': (None, '742dce2b57fae8f5e0eef8f1c54d7dcbaa35d99b'),
    'public/javascript/formularios_ejecutivas_piloto.js': (None, 'a8db47a94ee564802000ecf31aa01dcd0c9826d9'),
}


def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def sha(data):
    return hashlib.sha256(data).hexdigest()


def file_under(root, relative):
    file = root / relative
    if root not in file.resolve().parents or file.is_symlink():
        raise RuntimeError('Ruta no autorizada: ' + relative)
    return file


def only_once(data, before, after, label):
    if data.count(after) == 1:
        return data
    if data.count(before) != 1 or data.count(after) != 0:
        raise RuntimeError('CONFLICTO de personalización: ' + label)
    return data.replace(before, after, 1)


def patch_controller(current, old, new):
    marker = b"require_once __DIR__ . '/../services/FormularioInscripcionProgramas.php';"
    extra = (
        marker + b"\nrequire_once __DIR__ . '/../services/FormularioEjecutivasPerfiles.php';"
        b"\nrequire_once __DIR__ . '/../services/FormularioEjecutivasValidacion.php';"
    )
    method_marker = b'    public function generarEnlaceInscripcion()\n'
    inserted_marker = b'    /**\n     * VISTA DE PRUEBA INTERNA:'
    if old.count(marker) != 1 or new.count(extra) != 1 or old.count(method_marker) != 1:
        raise RuntimeError('INTEGRIDAD: controlador base no reconocido')
    if new.count(inserted_marker) != 1:
        raise RuntimeError('INTEGRIDAD: faltan acciones en controlador nuevo')
    methods = inserted_marker + new.split(inserted_marker, 1)[1].split(method_marker, 1)[0]
    if b'previsualizarEjecutivas()' not in methods or b'validarEjecutivas()' not in methods or \
       b"hash_equals($esperado, $enviado)" not in methods:
        raise RuntimeError('INTEGRIDAD: vistas o seguridad de acciones incompletas')

    def apply(data):
        result = only_once(data, marker, extra, 'requires formulario Ejecutivas')
        result = only_once(result, method_marker, methods + method_marker, 'acciones internas')
        return result

    if apply(old) != new:
        raise RuntimeError('INTEGRIDAD: cambios de controlador distintos a los esperados')
    if (b'previsualizarEjecutivas()' in current or b'validarEjecutivas()' in current) and \
       current.count(methods) != 1:
        raise RuntimeError('CONFLICTO: existen acciones Ejecutivas personalizadas')
    return apply(current)


def patch_view(current, old, new):
    old_phrase = b'<p>Accede al formulario interno de este programa.</p>'
    new_phrase = b"<p><?= $programa['slug'] === 'titulacion-ejecutivas'" + \
        new.split(b"<p><?= $programa['slug'] === 'titulacion-ejecutivas'", 1)[1].split(b'</p>',1)[0] + b'</p>'
    start = b'                    <div class="formularios-inscripcion-programa-action">\n'
    end = b'                </article>'
    if old.count(old_phrase) != 1 or new.count(new_phrase) != 1 or \
       old.count(start) != 1 or new.count(start) != 1:
        raise RuntimeError('INTEGRIDAD: selector original distinto')
    old_block = start + old.split(start, 1)[1].split(end, 1)[0]
    new_block = start + new.split(start, 1)[1].split(end, 1)[0]
    if b'action=previsualizarEjecutivas' not in new_block or \
       b'action=inscripcion&amp;programa=' not in new_block:
        raise RuntimeError('INTEGRIDAD: no se conserva captura anterior')

    def apply(data):
        result = only_once(data, old_phrase, new_phrase, 'texto tarjeta Ejecutivas')
        return only_once(result, old_block, new_block, 'acción tarjeta Ejecutivas')

    if apply(old) != new:
        raise RuntimeError('INTEGRIDAD: cambios de la vista distintos a los esperados')
    return apply(current)


def check(site, base, target):
    if not (site/'index.php').is_file():
        raise RuntimeError('No se reconoce el directorio del CRM')
    before, after, modes = {}, {}, {}
    with tempfile.TemporaryDirectory(prefix='impe-ejecutivas-check-') as tmp:
        for index,(name,(old_blob,new_blob)) in enumerate(FILES.items()):
            dest = file_under(site,name)
            dest_source = file_under(target,name)
            if not dest_source.is_file() or blob(dest_source.read_bytes()) != new_blob:
                raise RuntimeError('INTEGRIDAD GitHub destino incorrecta: ' + name)
            new_data = dest_source.read_bytes()
            if old_blob is None:
                if dest.exists():
                    if not dest.is_file() or dest.read_bytes() != new_data:
                        raise RuntimeError('CONFLICTO: archivo nuevo ya existe distinto: ' + name)
                    source = dest.read_bytes()
                    patched = source
                    print('YA INSTALADO NUEVO: ' + name)
                else:
                    source = None
                    patched = new_data
                    print('ARCHIVO NUEVO COMPATIBLE: ' + name)
                modes[name] = 0o644
            else:
                old_source = file_under(base,name)
                if not old_source.is_file() or blob(old_source.read_bytes()) != old_blob:
                    raise RuntimeError('INTEGRIDAD GitHub base incorrecta: ' + name)
                if not dest.is_file():
                    raise RuntimeError('Archivo esperado ausente en Hostinger: ' + name)
                source = dest.read_bytes()
                crlf = b'\r\n' in source
                data = source.replace(b'\r\n', b'\n')
                old_data = old_source.read_bytes()
                patched = (
                    patch_controller(data,old_data,new_data)
                    if name == 'app/controllers/FormularioController.php'
                    else patch_view(data,old_data,new_data)
                )
                if crlf:
                    patched = patched.replace(b'\n', b'\r\n')
                modes[name] = stat.S_IMODE(dest.stat().st_mode)
                print(('YA INSTALADO PERSONALIZADO: ' if patched == source else
                       'COMPATIBLE PERSONALIZADO: ') + name)
            if name.endswith('.php'):
                lint_file = Path(tmp) / ('file_%d.php' % index)
                lint_file.write_bytes(patched)
                lint = subprocess.run(['php','-l',str(lint_file)],
                                      stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                if lint.returncode:
                    raise RuntimeError('SINTAXIS PHP inválida: ' + name)
            elif name.endswith('.js') and shutil.which('node'):
                lint_file = Path(tmp) / 'ejecutivas.js'
                lint_file.write_bytes(patched)
                lint = subprocess.run(['node','--check',str(lint_file)],
                                      stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                if lint.returncode:
                    raise RuntimeError('SINTAXIS JS inválida: ' + name)
            before[name],after[name] = source,patched
    print('CHECK OK: piloto Ejecutivas con diez perfiles y validación CURP; 7 archivos')
    print('Sin SQL, sin datos de usuario, sin activar enlace público ni guardar comprobantes')
    return before,after,modes


def private(site,backup):
    if site == backup or site in backup.parents or backup in site.parents:
        raise RuntimeError('El respaldo debe estar fuera de public_html')


def atomic(path,data,mode):
    fd,temp=tempfile.mkstemp(prefix='.ejecutivas-piloto-',dir=str(path.parent))
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(temp,mode)
        os.replace(temp,str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def apply(site,base,target,backup):
    private(site,backup)
    before,after,modes=check(site,base,target)
    names=[name for name in FILES if before[name]!=after[name]]
    if not names:
        print('YA INSTALADO: ninguna escritura')
        return
    backup.mkdir(parents=True,exist_ok=True,mode=0o700)
    os.chmod(str(backup),0o700)
    folder=Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime(
        '%Y%m%d-%H%M%S-'),dir=str(backup)))
    os.chmod(str(folder),0o700)
    manifest={'site':str(site),'files':[]}
    for name in names:
        item={'path':name,'new':before[name] is None,
              'before':sha(before[name]) if before[name] is not None else None,
              'after':sha(after[name]),'mode':modes[name]}
        if before[name] is not None:
            saved=folder/'original'/name
            saved.parent.mkdir(parents=True,exist_ok=True)
            saved.write_bytes(before[name])
            os.chmod(str(saved),0o600)
        manifest['files'].append(item)
    m=folder/'respaldo.json'
    m.write_text(json.dumps(manifest,indent=2),encoding='utf-8')
    os.chmod(str(m),0o600)
    for name in names:
        file=file_under(site,name)
        if (before[name] is None and file.exists()) or \
           (before[name] is not None and (not file.is_file() or file.read_bytes()!=before[name])):
            raise RuntimeError('CONFLICTO concurrente: ' + name)
    written=[]
    try:
        for name in names:
            path=file_under(site,name)
            atomic(path,after[name],modes[name])
            written.append(name)
            if path.read_bytes()!=after[name]:
                raise RuntimeError('Error de integridad tras publicación: '+name)
    except BaseException:
        for name in reversed(written):
            file=file_under(site,name)
            if file.is_file() and file.read_bytes()==after[name]:
                if before[name] is None:
                    file.unlink()
                else:
                    atomic(file,before[name],modes[name])
        raise
    print('APLICADO: piloto Ejecutivas y validación sin guardado')
    print('RESPALDO: '+str(folder))


def restore(site,backup,folder):
    private(site,backup)
    folder=Path(folder).resolve()
    if backup not in folder.parents:
        raise RuntimeError('Respaldo fuera de ubicación privada')
    manifest=json.loads((folder/'respaldo.json').read_text(encoding='utf-8'))
    if manifest.get('site')!=str(site):
        raise RuntimeError('Respaldo de otra instalación')
    planned=[]
    for item in manifest['files']:
        name=item['path']
        if name not in FILES:
            raise RuntimeError('Ruta del respaldo no autorizada')
        file=file_under(site,name)
        if not file.is_file() or sha(file.read_bytes())!=item['after']:
            raise RuntimeError('CONFLICTO: archivo modificado desde el APPLY: '+name)
        if item['new']:
            saved=None
        else:
            source=folder/'original'/name
            if not source.is_file() or source.is_symlink():
                raise RuntimeError('Original no encontrado en respaldo')
            saved=source.read_bytes()
            if sha(saved)!=item['before']:
                raise RuntimeError('Respaldo alterado')
        planned.append((file,saved,int(item['mode'])))
    for file,saved,mode in reversed(planned):
        if saved is None:
            file.unlink()
        else:
            atomic(file,saved,mode)
    print('RESTAURADO: archivos del piloto; sin SQL')


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
    backup=Path(args.backup_root).resolve()
    if args.check:
        check(site,base,target)
    elif args.apply:
        apply(site,base,target,backup)
    else:
        restore(site,backup,args.restore)


if __name__=='__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: '+str(error),file=sys.stderr)
        sys.exit(1)
