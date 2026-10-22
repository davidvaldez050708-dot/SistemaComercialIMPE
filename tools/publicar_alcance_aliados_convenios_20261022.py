#!/usr/bin/env python3
"""Entrega selectiva de permisos Aliados/Convenios; NO ejecuta SQL. Python 3.6."""
import argparse
import datetime
import difflib
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from urllib.request import urlopen

RAW = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/"

def digest(data):
    return hashlib.sha256(data).hexdigest()

def blob_sha(data):
    return hashlib.sha1(b'blob ' + str(len(data)).encode('ascii') + b'\0' + data).hexdigest()

def normalized(data):
    return data.replace(b'\r\n', b'\n')

def within(child, parent):
    return child == parent or parent in child.parents

def safe_file(site, name):
    rel = Path(name)
    if rel.is_absolute() or '..' in rel.parts:
        raise RuntimeError('Ruta fuera del sitio: ' + name)
    file_path = site / rel
    if file_path.is_symlink() or not within(file_path.resolve(), site):
        raise RuntimeError('Archivo externo o enlace simbólico: ' + name)
    return file_path

def fetch(ref, name, wanted):
    with urlopen(RAW + ref + '/' + name, timeout=30) as response:
        data = response.read()
    if blob_sha(data) != wanted:
        raise RuntimeError('Hash de GitHub no coincide: ' + name)
    return data

def overlay_crear_convocatoria_controller(current, base, desired):
    """Apply only the reviewed territorial change inside index(), preserving custom code.

    Requires recognizable existing anchors. Unknown or partial modifications fail
    closed rather than replacing the production controller.
    """
    name = 'app/controllers/CrearConvocatoriaController.php'
    current_lf = normalized(current)
    base_lf = normalized(base)
    desired_lf = normalized(desired)
    old_require = b"require_once __DIR__ . '/../models/CrearConvocatoriaModel.php';"
    new_require = b"require_once __DIR__ . '/../models/TerritorioModel.php';"
    old_call = b'$modelo->listarConveniosRecibidos()'
    new_call = b'$modelo->listarConveniosRecibidos($estadoIds)'
    old_states = b'        $estados = $modelo->listarEstados();'
    start = b'        $modelo = new CrearConvocatoriaModel();'
    stop = b"        $tituloPagina = 'Crear convocatoria';"
    expected = '''        // Marketing/Admin: cobertura nacional; Cuenta Clave: sólo asignaciones
        // vigentes. Un permiso de lectura no amplía el alcance territorial.
        $esMarketing = strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0;
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $estados = $modelo->listarEstados();
        $estadoIds = null;
        if (!$esMarketing && !$esAdministrador) {
            $tipo = (int)($_SESSION['rol_id'] ?? 0) === 6 ? 'CUENTA_CLAVE'
                : ((int)($_SESSION['rol_id'] ?? 0) === 4 ? 'ANALISTA_DATOS'
                : ((int)($_SESSION['rol_id'] ?? 0) === 3 ? 'ASESOR' : null));
            $estados = $tipo === null ? [] : (new TerritorioModel())->obtenerEstadosAsignadosUsuario(
                (int)($_SESSION['usuario_id'] ?? 0), $tipo
            );
            $estadoIds = array_map('intval', array_column($estados, 'id'));
        }
'''.encode('utf-8')
    footer = b'        $puedeCrearDesdeConvenio = puedeCrearConvocatoriaDesdeConvenio();'
    # Verify GitHub BASE->TARGET modifies only exactly these validated fragments.
    if base_lf.count(old_require) != 1 or base_lf.count(old_call) != 1 or base_lf.count(old_states) != 1:
        raise RuntimeError(name + ': BASE incompatible con revisión puntual')
    reconstructed = base_lf.replace(old_require, old_require + b'\n' + new_require, 1)
    reconstructed = reconstructed.replace(old_states, b'', 1)
    reconstructed = reconstructed.replace(old_call, new_call, 1)
    reconstructed = reconstructed.replace(b'        $convenios = $estructuraDisponible', expected + b'        $convenios = $estructuraDisponible', 1)
    reconstructed = reconstructed.replace(stop, footer + b'\n\n' + stop, 1)
    if reconstructed.replace(b'\n\n\n', b'\n\n') != desired_lf.replace(b'\n\n\n', b'\n\n'):
        raise RuntimeError(name + ': SOURCE cambia otros fragmentos; conservar sitio intacto')

    # Already installed? Report unchanged only if every structural marker exists.
    if new_call in current_lf or b'$estadoIds = null;' in current_lf:
        markers = (new_require, new_call, expected, footer)
        if all(current_lf.count(marker) == 1 for marker in markers):
            print('CONSERVADO: ' + name + ' (alcance territorial ya instalado)')
            return current
        raise RuntimeError(name + ': integración parcial o personalizada; sitio intacto')

    # Operate only inside index() and strictly before its title; never alter
    # any user-defined helper, view, or access-validation code.
    index_marker = b'    public function index()'
    if current_lf.count(index_marker) != 1 or current_lf.count(stop) != 1:
        raise RuntimeError(name + ': index() o título no únicos; sitio intacto')
    i = current_lf.index(index_marker)
    z = current_lf.index(stop, i)
    if z <= i:
        raise RuntimeError(name + ': estructura de index() no reconocida')
    section = current_lf[i:z]
    if section.count(start) != 1 or section.count(old_call) != 1 or section.count(old_states) != 1:
        raise RuntimeError(name + ': personalización de carga de convenios no reconocida; sitio intacto')
    if current_lf.count(old_require) != 1 or new_require in current_lf:
        raise RuntimeError(name + ': include de TerritorioModel personalizado; sitio intacto')
    if b'\r' in current_lf:
        raise RuntimeError(name + ': saltos de línea no reconocidos; sitio intacto')
    call_at = section.index(old_call)
    states_at = section.index(old_states)
    if states_at <= call_at:
        raise RuntimeError(name + ': orden diferente de carga de estados; sitio intacto')
    # The documented controller loads $convenios conditionally. Do not replace
    # other calls in a customized class, or skip a custom data enrichment.
    if section.count(b'        $convenios = $estructuraDisponible') != 1:
        raise RuntimeError(name + ': asignación de convenios distinta; sitio intacto')
    begin = section.index(b'        $convenios = $estructuraDisponible')
    if not begin < call_at < states_at:
        raise RuntimeError(name + ': secuencia de datos de convenios desconocida; sitio intacto')
    revised = current_lf.replace(old_require, old_require + b'\n' + new_require, 1)
    # Insert BEFORE data loading; remove ONLY previous unfiltered states load.
    section_new = section.replace(old_states, b'', 1)
    section_new = section_new.replace(old_call, new_call, 1)
    section_new = section_new.replace(b'        $convenios = $estructuraDisponible',
                                      expected + b'        $convenios = $estructuraDisponible', 1)
    revised = revised.replace(section, section_new, 1)
    revised = revised.replace(stop, footer + b'\n\n' + stop, 1)
    # Exact reversibility guarantees every unrelated production byte is kept.
    backward = revised.replace(footer + b'\n\n' + stop, stop, 1)
    backward = backward.replace(section_new, section, 1)
    backward = backward.replace(old_require + b'\n' + new_require, old_require, 1)
    if backward != current_lf:
        raise RuntimeError(name + ': reversibilidad no demostrada; sitio intacto')
    print('CONCILIACIÓN PREPARADA: ' + name + ' (únicamente alcance territorial de index(); personalizaciones conservadas)')
    return revised.replace(b'\n', b'\r\n') if b'\r\n' in current else revised


def candidate(current, base, target, stage, name):
    if normalized(current) == normalized(target):
        return current
    if normalized(current) == normalized(base):
        result = normalized(target)
    else:
        for entry, data in [('local', current), ('base', base), ('destino', target)]:
            (stage / entry).write_bytes(normalized(data))
        run = subprocess.run(
            ['git', 'merge-file', '-p', str(stage / 'local'), str(stage / 'base'), str(stage / 'destino')],
            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if run.returncode != 0:
            if name == 'app/controllers/CrearConvocatoriaController.php':
                return overlay_crear_convocatoria_controller(current, base, target)
            raise RuntimeError('CONFLICTO en ' + name + ': personalización no conciliada; sitio intacto')
        result = run.stdout
    return result.replace(b'\n', b'\r\n') if b'\r\n' in current else result

def atomic(path, data, mode):
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp = tempfile.mkstemp(prefix='.alcance-convenios-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(path))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)

def restore(site, location):
    meta = json.loads((location / 'respaldo.json').read_text(encoding='utf-8'))
    if meta['site'] != str(site):
        raise RuntimeError('El respaldo corresponde a otro sitio')
    for row in meta['files']:
        p = safe_file(site, row['path'])
        original = location / 'original' / row['path']
        if not p.is_file() or digest(p.read_bytes()) != row['installed']:
            raise RuntimeError('Se detectaron modificaciones posteriores: ' + row['path'])
        if not original.is_file() or digest(original.read_bytes()) != row['original']:
            raise RuntimeError('Respaldo incompleto: ' + row['path'])
    for row in reversed(meta['files']):
        p = safe_file(site, row['path'])
        atomic(p, (location / 'original' / row['path']).read_bytes(), row['mode'])
    print('RESTAURADO: ocho archivos originales como máximo. Base de datos intacta.')

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--check', action='store_true')
    action.add_argument('--apply', action='store_true')
    action.add_argument('--restore', metavar='RUTA')
    parser.add_argument('--site', required=True)
    parser.add_argument('--manifest', required=True)
    parser.add_argument('--backup-root', default=str(Path.home() / 'respaldos-impe-alcance'))
    args = parser.parse_args()
    site = Path(args.site).resolve()
    private = Path(args.backup_root).resolve()
    if not (site / 'index.php').is_file():
        raise RuntimeError('Sitio no válido: falta index.php')
    if within(private, site):
        raise RuntimeError('El respaldo debe quedar fuera de public_html')
    if args.restore:
        location = Path(args.restore).resolve()
        if within(location, site) or not within(location, private):
            raise RuntimeError('Respaldo fuera de ubicación permitida')
        restore(site, location)
        return
    if not shutil.which('git') or not shutil.which('php'):
        raise RuntimeError('Se requieren git y php para validar el despliegue')
    manifest = json.loads(Path(args.manifest).read_text(encoding='utf-8'))
    if len(manifest['files']) != 8:
        raise RuntimeError('El manifiesto no tiene los ocho archivos esperados')
    private.mkdir(parents=True, exist_ok=True)
    os.chmod(str(private), 0o700)
    planned = []
    with tempfile.TemporaryDirectory(prefix='alcance-revision-', dir=str(private)) as name_temp:
        stage = Path(name_temp)
        for row in manifest['files']:
            name = row['path']
            destination = safe_file(site, name)
            if not destination.is_file():
                raise RuntimeError('Falta archivo: ' + name)
            actual = destination.read_bytes()
            original = fetch(manifest['base'], name, row['base_sha'])
            desired = fetch(manifest['source'], name, row['target_sha'])
            reviewed = candidate(actual, original, desired, stage, name)
            if reviewed == actual:
                print('SIN CAMBIOS: ' + name)
                continue
            validation = stage / 'validar.php'
            validation.write_bytes(reviewed)
            check = subprocess.run(['php', '-l', str(validation)],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if check.returncode != 0:
                raise RuntimeError('PHP inválido: ' + name + ': ' +
                                   (check.stderr or check.stdout).decode('utf-8', 'replace'))
            planned.append((name, destination, actual, reviewed, destination.stat().st_mode & 0o777))
            print('PREPARADO: ' + name)
            if args.check:
                diff = difflib.unified_diff(
                    normalized(actual).decode('utf-8', 'replace').splitlines(),
                    normalized(reviewed).decode('utf-8', 'replace').splitlines(),
                    fromfile='Hostinger/' + name, tofile='Revisión/' + name, lineterm='')
                print('\n'.join(diff))
        print('REVISIÓN COMPATIBLE: {} archivos. SQL NO ejecutado.'.format(len(planned)))
        if args.check or not planned:
            print('SITIO INTACTO' if args.check else 'Entrega ya aplicada')
            return
        for name, dest, actual, reviewed, mode in planned:
            if dest.read_bytes() != actual:
                raise RuntimeError('Archivo modificado durante revisión: ' + name)
        backup = private / ('alcance-' + datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-%f'))
        backup.mkdir(mode=0o700)
        meta = {'site': str(site), 'release': manifest['source'], 'files': []}
        for name, dest, actual, reviewed, mode in planned:
            saved = backup / 'original' / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(actual)
            meta['files'].append({'path': name, 'original': digest(actual),
                                  'installed': digest(reviewed), 'mode': mode})
        (backup / 'respaldo.json').write_text(json.dumps(meta, indent=2), encoding='utf-8')
        done = []
        try:
            for name, dest, actual, reviewed, mode in planned:
                if dest.read_bytes() != actual:
                    raise RuntimeError('Archivo modificado concurrentemente: ' + name)
                atomic(dest, reviewed, mode)
                done.append((dest, actual, mode))
                if digest(dest.read_bytes()) != digest(reviewed):
                    raise RuntimeError('Hash posterior inválido: ' + name)
        except Exception:
            for dest, original, mode in reversed(done):
                atomic(dest, original, mode)
            raise
        print('PUBLICADO. Respaldo: ' + str(backup))
        print('Base de datos sin cambios. No se alteraron asignaciones ni permisos.')

if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
