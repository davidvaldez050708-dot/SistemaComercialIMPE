#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Corrección reversible: un modal compartido y borrador separado del registro en Seguimiento.
No cambia el SDK, Hostinger SQL, transferencias, recepción, grabaciones ni otros módulos.
"""
from __future__ import print_function
import argparse
import contextlib
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

REGISTRY = 'public/javascript/seguimiento_llamada_registro_obligatorio.js'
REGISTRY_BEFORE_SHA256 = '69e64923a4a8c3db216f733e5d631bacf3846fc738c8a7b1e3b605385829fca6'
REGISTRY_TARGET_GIT_BLOB = '475f62468bcf903e83baecfe0628a0a8d1b36f5c'
PATCHES = [{"path":"public/javascript/telefonia_shell.js","changes":[{"before":"        // Sólo llamadas manuales usan este resumen visual; Seguimiento\n        // mantiene su propia pantalla para registrar el resultado.\n        const manualOutgoing = isManualOutgoing(shownCall);\n        const shownOutgoing = Boolean(shownCall?.direction === 'outgoing' &&\n            (manualOutgoing || preview || outgoingAttempt));\n","after":"        // El mismo modal del shell sirve para salientes libres y de Seguimiento.\n        // Sólo el borrador vive aquí; el resultado definitivo sigue en el expediente.\n        const manualOutgoing = isManualOutgoing(shownCall);\n        const seguimientoOutgoing = Boolean(shownCall?.direction === 'outgoing' &&\n            String(shownCall?.context?.type || '').toUpperCase() === 'VINCULACION');\n        const shownOutgoing = Boolean(shownCall?.direction === 'outgoing' &&\n            (manualOutgoing || seguimientoOutgoing || preview || outgoingAttempt));\n"},{"before":"        // Sólo se hace visible la interfaz oficial si la llamada es entrante.\n        // Para salientes, el panel del CRM sigue teniendo sus controles.\n","after":"        // Este modal único atiende también las salientes desde Seguimiento.\n        // El Panel de trabajo se reserva para registrar la interacción final.\n"}]},{"path":"public/javascript/telefonia_persistente.js","changes":[{"before":"        const manualOutgoingInShell = embedded &&\n            state?.direction === 'outgoing' &&\n            ['TELEPHONY_MANUAL','MARKETING_CALLBACK','DIALER'].includes(\n                String(state?.context?.type || '').toUpperCase()\n            ) && (Boolean(state?.active) || state?.phase === 'finished');\n        panel.hidden = !show || managedByShell ||\n            incomingInShell || manualOutgoingInShell || callbackInShell;\n\n        if (!show || managedByShell || incomingInShell ||\n            manualOutgoingInShell || callbackInShell) {\n            return;\n        }\n\n","after":"        const manualOutgoingInShell = embedded &&\n            state?.direction === 'outgoing' &&\n            ['TELEPHONY_MANUAL','MARKETING_CALLBACK','DIALER'].includes(\n                String(state?.context?.type || '').toUpperCase()\n            ) && (Boolean(state?.active) || state?.phase === 'finished');\n        // El shell ya presenta el teléfono de Seguimiento: evitar un duplicado.\n        const seguimientoInShell = embedded &&\n            state?.direction === 'outgoing' &&\n            String(state?.context?.type || '').toUpperCase() === 'VINCULACION' &&\n            (Boolean(state?.active) || state?.phase === 'finished');\n        panel.hidden = !show || managedByShell ||\n            incomingInShell || manualOutgoingInShell || seguimientoInShell || callbackInShell;\n\n        if (!show || managedByShell || incomingInShell ||\n            manualOutgoingInShell || seguimientoInShell || callbackInShell) {\n            return;\n        }\n\n"}]}]
HOSTINGER_PERSISTENT_SHA256 = '60a6e0e858fd035f0c3c802bccada78df13c95094444db6e5c980d764b37563d'
HOSTINGER_PERSISTENT_BEFORE = "        panel.hidden = !show || managedByShell || incomingInShell || manualOutgoingInShell || callbackInShell;\n\n        if (!show || managedByShell || manualOutgoingInShell || callbackInShell) {\n            return;\n        }\n"
HOSTINGER_PERSISTENT_AFTER = "        // En Seguimiento la llamada vive en el modal telefónico compartido.\n        // La interacción definitiva continúa en el Panel de trabajo.\n        const seguimientoInShell =\n            getHostMode() === 'embedded' &&\n            state?.direction === 'outgoing' &&\n            String(state?.context?.type || '').toUpperCase() === 'VINCULACION' &&\n            (Boolean(state?.active) || state?.phase === 'finished');\n        panel.hidden = !show || managedByShell || incomingInShell ||\n            manualOutgoingInShell || seguimientoInShell || callbackInShell;\n\n        if (!show || managedByShell || manualOutgoingInShell ||\n                seguimientoInShell || callbackInShell) {\n            return;\n        }\n"
PATHS = [REGISTRY] + [p['path'] for p in PATCHES]

def sha(data):
    return None if data is None else hashlib.sha256(data).hexdigest()

def git_blob(data):
    return hashlib.sha1(('blob ' + str(len(data)) + '\0').encode('ascii') + data).hexdigest()

def read(path):
    return path.read_bytes() if path.is_file() else None

def safe(root, name):
    p = Path(name)
    if p.is_absolute() or '..' in p.parts or '\\' in name:
        raise RuntimeError('Ruta inválida: ' + name)
    out = root / p
    if root not in out.resolve().parents:
        raise RuntimeError('Ruta fuera del sitio: ' + name)
    cursor = out
    while cursor != root:
        if cursor.is_symlink():
            raise RuntimeError('Ruta simbólica no permitida: ' + name)
        cursor = cursor.parent
    return out

def patched(source, changes, name):
    # Variante REAL de Hostinger verificada mediante SHA-256, no un parche
    # flexible que pueda alterar customizaciones de otro módulo.
    if name == 'public/javascript/telefonia_persistente.js':
        before = HOSTINGER_PERSISTENT_BEFORE.encode('utf-8')
        after = HOSTINGER_PERSISTENT_AFTER.encode('utf-8')
        if sha(source) == HOSTINGER_PERSISTENT_SHA256:
            if source.count(before) != 1 or source.count(after) != 0:
                raise RuntimeError('CONFLICTO: variante de Hostinger alterada. Sitio intacto.')
            return source.replace(before, after)
        if source.count(after) == 1 and source.count(before) == 0:
            # Parche ya presente. No reescribir ni reinterpretar la variante.
            return source
    text = source
    for patch in changes:
        before, after = patch['before'].encode('utf-8'), patch['after'].encode('utf-8')
        if b'\r\n' in text and b'\n' in before:
            before, after = before.replace(b'\n', b'\r\n'), after.replace(b'\n', b'\r\n')
        count_before = text.count(before)
        count_after = text.count(after)
        if count_before == 1 and count_after == 0:
            text = text.replace(before, after)
        elif count_before == 0 and count_after == 1:
            pass
        else:
            raise RuntimeError('CONFLICTO: personalización no reconocida en ' + name + '. Sitio intacto.')
    return text

def prepare(site, target_path):
    target = read(target_path)
    if not target or git_blob(target) != REGISTRY_TARGET_GIT_BLOB:
        raise RuntimeError('Paquete de registro alterado o incompleto. Sitio intacto.')
    updates = []
    for name in PATHS:
        dest = safe(site, name)
        before = read(dest)
        if before is None:
            raise RuntimeError('ARCHIVO AUSENTE: ' + name + '. Sitio intacto.')
        if name == REGISTRY:
            if sha(before) != REGISTRY_BEFORE_SHA256 and git_blob(before) != REGISTRY_TARGET_GIT_BLOB:
                raise RuntimeError('VERSIÓN DIFERENTE: ' + name + '. Sitio intacto.')
            after = target
        else:
            patch = next(p for p in PATCHES if p['path'] == name)
            after = patched(before, patch['changes'], name)
        mode = dest.stat().st_mode & 0o777
        updates.append({'name': name, 'dest': dest, 'before': before, 'after': after,
                        'mode': mode, 'after_mode': 0o644})
        changed = before != after or mode != 0o644
        print(('COMPATIBLE: ' if changed else 'YA INSTALADO: ') + name)
    node = shutil.which('node')
    if node:
        for row in updates:
            with tempfile.TemporaryDirectory(prefix='impe-modal-compartido-lint-') as directory:
                f = Path(directory) / 'archivo.js'
                f.write_bytes(row['after'])
                p = subprocess.run([node, '--check', str(f)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if p.returncode:
                    raise RuntimeError('Sintaxis JS inválida: ' + row['name'])
    else:
        print('AVISO: node no disponible; la sintaxis JS está validada en GitHub Actions.')
    return updates

def atomic(dest, content, mode):
    fd, temp = tempfile.mkstemp(prefix='.impe-modal-compartido-', dir=str(dest.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp, mode)
        os.replace(temp, str(dest))
    finally:
        if os.path.exists(temp):
            os.unlink(temp)

@contextlib.contextmanager
def lock(folder):
    folder.mkdir(mode=0o700, parents=True, exist_ok=True)
    lock_path = folder / '.impe-modal-compartido.lock'
    fd = os.open(str(lock_path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        os.write(fd, str(os.getpid()).encode('ascii'))
        os.close(fd)
        yield
    finally:
        lock_path.unlink()

def apply(site, backups, target):
    with lock(backups):
        rows = prepare(site, target)
        changed = [x for x in rows if x['before'] != x['after'] or x['mode'] != 0o644]
        if not changed:
            print('YA INSTALADO: sin cambios.')
            return
        backup = Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'), dir=str(backups)))
        os.chmod(str(backup), 0o700)
        meta = {'site': str(site), 'files': []}
        for x in changed:
            original = safe(backup, 'original/' + x['name'])
            original.parent.mkdir(parents=True, exist_ok=True)
            original.write_bytes(x['before'])
            meta['files'].append({'name': x['name'], 'before': sha(x['before']),
                                  'after': sha(x['after']), 'mode': x['mode'], 'after_mode': 0o644})
        (backup / 'respaldo.json').write_text(json.dumps(meta, indent=2), encoding='utf-8')
        for x in changed:
            if read(x['dest']) != x['before'] or x['dest'].stat().st_mode & 0o777 != x['mode']:
                raise RuntimeError('Archivo modificado después del preflight: ' + x['name'])
        written = []
        try:
            for x in changed:
                if read(x['dest']) != x['before']:
                    raise RuntimeError('Cambio concurrente: ' + x['name'])
                atomic(x['dest'], x['after'], x['after_mode'])
                written.append(x)
                if read(x['dest']) != x['after']:
                    raise RuntimeError('Fallo al comprobar instalación: ' + x['name'])
        except BaseException:
            for x in reversed(written):
                if read(x['dest']) == x['after']:
                    atomic(x['dest'], x['before'], x['mode'])
                else:
                    print('REVISAR: no restaurar un cambio ajeno en ' + x['name'], file=sys.stderr)
            raise
        print('APLICADO: modal compartido, borrador separado y registro en Panel de trabajo.')
        print('RESPALDO: ' + str(backup) + ' (restaurable con --restore). Sin SQL.')

def restore(site, backups, restore_path):
    folder = Path(restore_path).resolve()
    if backups not in folder.parents or site == folder or site in folder.parents:
        raise RuntimeError('Respaldo fuera del directorio privado.')
    with lock(backups):
        data = json.loads((folder/'respaldo.json').read_text(encoding='utf-8'))
        if data.get('site') != str(site) or not data.get('files'):
            raise RuntimeError('Respaldo ajeno.')
        rows = []
        for item in data['files']:
            if item['name'] not in PATHS:
                raise RuntimeError('Ruta fuera del paquete.')
            dest = safe(site, item['name'])
            old = read(safe(folder, 'original/' + item['name']))
            if sha(old) != item['before'] or sha(read(dest)) != item['after']:
                raise RuntimeError('Respaldo o versión cambiada: ' + item['name'])
            if dest.stat().st_mode & 0o777 != item.get('after_mode', 0o644):
                raise RuntimeError('Permisos cambiados: ' + item['name'])
            rows.append((dest, old, item['mode']))
        for dest, content, mode in reversed(rows):
            atomic(dest, content, mode)
        print('RESTAURADO: versión anterior de los tres archivos. Sin SQL.')

def main():
    if sys.version_info < (3, 6):
        raise RuntimeError('Python 3.6 o posterior requerido.')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', required=True)
    parser.add_argument('--target', default=str(Path(__file__).parent / 'seguimiento_llamada_registro_obligatorio.js'))
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--check', action='store_true')
    group.add_argument('--apply', action='store_true')
    group.add_argument('--restore')
    args = parser.parse_args()
    site, backups = Path(args.site).resolve(), Path(args.backup_root).resolve()
    if not (site/'index.php').is_file():
        raise RuntimeError('El sitio no contiene index.php.')
    if site == backups or site in backups.parents:
        raise RuntimeError('Los respaldos deben estar fuera del sitio.')
    if args.restore:
        restore(site, backups, args.restore)
    elif args.apply:
        apply(site, backups, Path(args.target))
    else:
        prepare(site, Path(args.target))
        print('CHECK OK: compatibilidad y sintaxis, sin modificar el sitio ni consultar SQL.')

if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print('ERROR: ' + str(exc), file=sys.stderr)
        sys.exit(1)
