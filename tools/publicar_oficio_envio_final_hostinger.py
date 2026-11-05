#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicar solo el arreglo de envío PDF terminado, preservando Hostinger.

--check: solo lectura; --apply: respaldo privado y reemplazo atómico;
--restore: exige que la versión publicada siga intacta antes de restaurar.
"""
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

FILE = 'app/services/OficioEnvioService.php'
BASE_BLOB = '6082bb2b8bb6127b778920f3c1f98e2ea557c711'
TARGET_BLOB = 'a1674434dc0646636581bff324495549a69f832c'


def git_blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def digest(data):
    return hashlib.sha256(data).hexdigest()


def allowed(site, relative):
    path = site / relative
    if not path.is_file() or path.is_symlink() or site not in path.resolve().parents:
        raise RuntimeError('Ruta no autorizada o servicio ausente: ' + relative)
    return path


def private(site, folder):
    if folder == site or folder in site.parents or site in folder.parents:
        raise RuntimeError('El respaldo debe permanecer fuera de public_html')


def replacement(source, old, new, label):
    if source.count(old) != 1:
        raise RuntimeError('CONFLICTO de personalización en ' + label + '; sitio intacto')
    return source.replace(old, new, 1)


def make_patch(original, base, target):
    """Funde 4 cambios conocidos; conserva las otras personalizaciones."""
    if original == target:
        return original

    sent = b'''        if ($this->correoYaEnviado($seguimiento)) {
            return $correoService->enviarAhora($seguimientoId, $usuarioId);
        }

'''
    folio = b'        $ajuste = $this->sincronizarFolioConFechaActual('
    helper_start = b'    private function esOficioTerminadoAdjuntado($seguimiento)'
    helper_end = b'    private function listoParaAjustarDocumento($seguimiento)\n'
    original_sql = b'''                    oficio.estado_oficio,
                    oficio.fecha_envio
                FROM seguimientos_vinculacion seguimientos'''
    new_sql = b'''                    oficio.estado_oficio,
                    oficio.fecha_envio,
                    plantilla_oficio.nombre AS plantilla_oficio_nombre
                FROM seguimientos_vinculacion seguimientos'''
    original_join = b'''                    )
                WHERE seguimientos.id = ?'''
    new_join = b'''                    )
                LEFT JOIN plantillas_vinculacion plantilla_oficio
                    ON plantilla_oficio.id = oficio.plantilla_oficio_id
                WHERE seguimientos.id = ?'''

    if target.count(sent) != 1 or target.count(folio) != 1 or \
       target.count(helper_start) != 1 or target.count(helper_end) != 1:
        raise RuntimeError('INTEGRIDAD: estructura GitHub de destino inesperada')
    guard = target.split(sent, 1)[1].split(folio, 1)[0]
    helper = helper_start + target.split(helper_start, 1)[1].split(helper_end, 1)[0]
    if b'esOficioTerminadoAdjuntado($seguimiento)' not in guard or \
       b'pdfTerminadoDisponible($seguimiento)' not in guard or \
       b'pdfTerminadoDisponible($seguimiento)' not in helper or \
       b'regenerarPdfDefinitivo' in guard:
        raise RuntimeError('INTEGRIDAD: la guarda de PDF terminado no es la esperada')

    # Comprobar que estos son TODOS los cambios de la versión nueva.
    def perform(data):
        changed = replacement(data, sent, sent + guard, 'enviarAhora')
        changed = replacement(changed, helper_end, helper + helper_end, 'métodos de validación')
        changed = replacement(changed, original_sql, new_sql, 'SELECT del oficio')
        changed = replacement(changed, original_join, new_join, 'JOIN plantilla')
        return changed

    if perform(base) != target:
        raise RuntimeError('INTEGRIDAD: cambios de GitHub no coinciden con 4 parches selectivos')

    if any(token in original for token in [
        helper_start, b'plantilla_oficio.nombre AS plantilla_oficio_nombre',
        b'if ($this->esOficioTerminadoAdjuntado($seguimiento))',
    ]):
        raise RuntimeError('CONFLICTO: ya existe un ajuste parcial o personalizado de envío')

    return perform(original)


def candidate(site, base_file, target_file):
    if not (site / 'index.php').is_file():
        raise RuntimeError('No se identificó la raíz del CRM')
    path = allowed(site, FILE)
    base, target = base_file.read_bytes(), target_file.read_bytes()
    if git_blob(base) != BASE_BLOB or git_blob(target) != TARGET_BLOB:
        raise RuntimeError('INTEGRIDAD: archivos base o destino de GitHub incorrectos')
    before = path.read_bytes()
    crlf = b'\r\n' in before
    current = before.replace(b'\r\n', b'\n')
    result = make_patch(current, base, target)
    after = result.replace(b'\n', b'\r\n') if crlf else result
    with tempfile.TemporaryDirectory(prefix='impe-oficios-envio-check-') as temp:
        check = Path(temp) / 'OficioEnvioService.php'
        check.write_bytes(after)
        proc = subprocess.run(['php', '-l', str(check)],
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if proc.returncode:
            raise RuntimeError('ERROR de sintaxis PHP en conciliación: ' +
                               proc.stderr.decode('utf-8', 'replace')[:250])
    print(('YA INSTALADO' if before == after else
           'COMPATIBLE PERSONALIZADO') + ': ' + FILE)
    print('CHECK OK: envío usa PDF terminado sin regenerar; folio original conservado')
    print('Sin SQL de migración, SMTP de prueba, archivos PDF modificados ni publicaciones en --check')
    return path, before, after, stat.S_IMODE(path.stat().st_mode)


def atomic(path, data, mode):
    fd, tmp = tempfile.mkstemp(prefix='.impe-oficios-envio-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, str(path))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def publish(site, backup, base, target):
    private(site, backup)
    path, before, after, mode = candidate(site, base, target)
    if before == after:
        print('YA INSTALADO: no se escribió en el sitio')
        return
    backup.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(str(backup), 0o700)
    folder = Path(tempfile.mkdtemp(
        prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),
        dir=str(backup)))
    os.chmod(str(folder), 0o700)
    saved = folder / 'OficioEnvioService.php'
    saved.write_bytes(before)
    os.chmod(str(saved), 0o600)
    manifest = {'site': str(site), 'path': FILE, 'before': digest(before),
                'after': digest(after), 'mode': mode}
    (folder / 'respaldo.json').write_text(json.dumps(manifest, indent=2),encoding='utf-8')
    os.chmod(str(folder/'respaldo.json'), 0o600)
    if path.read_bytes() != before:
        raise RuntimeError('CONFLICTO: archivo modificado durante el CHECK')
    atomic(path, after, mode)
    if path.read_bytes() != after:
        raise RuntimeError('ERROR: publicación no verificada; revisar respaldo privado')
    print('APLICADO: solo envío de oficio terminado sin regeneración REDMEX')
    print('RESPALDO: ' + str(folder))
    print('No se ejecutó correo, no se tocó BD ni se cambiaron documentos')


def restore(site, backup, folder):
    private(site, backup)
    folder = Path(folder).resolve()
    if backup not in folder.parents:
        raise RuntimeError('Respaldo fuera de carpeta privada')
    manifest = json.loads((folder / 'respaldo.json').read_text(encoding='utf-8'))
    if manifest.get('site') != str(site) or manifest.get('path') != FILE:
        raise RuntimeError('El respaldo corresponde a otra instalación')
    path = allowed(site, FILE)
    saved = folder / 'OficioEnvioService.php'
    if not saved.is_file() or saved.is_symlink():
        raise RuntimeError('No se localiza el original')
    data = saved.read_bytes()
    if digest(data) != manifest['before'] or digest(path.read_bytes()) != manifest['after']:
        raise RuntimeError('CONFLICTO: se modificó el servicio después de publicar')
    atomic(path, data, int(manifest['mode']))
    print('RESTAURADO: solo el servicio de envío del oficio')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--base',required=True)
    parser.add_argument('--target',required=True)
    parser.add_argument('--backup-root',required=True)
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true')
    mode.add_argument('--apply',action='store_true')
    mode.add_argument('--restore')
    a=parser.parse_args()
    site=Path(a.site).resolve()
    backup=Path(a.backup_root).resolve()
    if a.check:
        candidate(site, Path(a.base).resolve(), Path(a.target).resolve())
    elif a.apply:
        publish(site, backup, Path(a.base).resolve(), Path(a.target).resolve())
    else:
        restore(site, backup, a.restore)


if __name__=='__main__':
    try:
        main()
    except Exception as exc:
        print('ERROR: ' + str(exc),file=sys.stderr)
        sys.exit(1)
