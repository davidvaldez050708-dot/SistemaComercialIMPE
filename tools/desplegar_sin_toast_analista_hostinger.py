#!/usr/bin/env python3
# Despliegue de un único cambio visual del Analista sobre el JS REAL de Hostinger.
# Compatible con Python 3.6+. No modifica shell, host, PBX ni MySQL.
from __future__ import print_function
import argparse
import datetime
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

RELATIVE = 'public/javascript/telefonia_analista_historial_pasivo.js'
LEGACY = 'body.impe-sdk-result-in-modal [data-analyst-call-result]'
CURRENT = 'body [data-analyst-call-result]'
ANCHOR = '    root.document.head.appendChild(finishedCss);\n\n'
INJECTION = """    // El shell anterior persistía un aviso de esquina en sessionStorage
    // durante 24 horas. Descartarlo del estado VISUAL al abrir sesión,
    // incluso si no hay llamada activa. Los pendientes reales permanecen
    // en MySQL y siguen disponibles en Seguimiento > Llamadas recibidas.
    try {
        const legacyKey = 'impe:analyst-incoming-pending:' + userId;
        if (root.sessionStorage?.getItem(legacyKey)) {
            root.document.querySelector('[data-analyst-dismiss-call]')?.click();
            root.sessionStorage.removeItem(legacyKey);
        }
    } catch (_) { /* El navegador puede restringir sessionStorage. */ }

"""
SIGNATURE = "root.sessionStorage.removeItem(legacyKey);"


def digest(data):
    return hashlib.sha256(data).hexdigest()


def updated(original):
    if original.count(CURRENT) == 1 and original.count(SIGNATURE) == 1:
        if LEGACY in original:
            raise ValueError('Selector antiguo y nuevo simultáneos')
        return original
    if CURRENT in original or SIGNATURE in original:
        raise ValueError('Actualización parcial o distinta; se conservará producción')
    if (original.count(LEGACY) != 1 or original.count(ANCHOR) != 1 or
            'const el = selector => panel.querySelector(selector);' not in original or
            'IMPE_ANALYST_WEBRTC_JOURNAL' not in original or
            "addAction('[data-incoming-register-finished]'" not in original):
        raise ValueError('Observador personalizado incompatible; se conservará producción')
    modified = original.replace(LEGACY, CURRENT, 1)
    modified = modified.replace(ANCHOR, ANCHOR + INJECTION, 1)
    return modified


def syntax_check(content):
    if shutil.which('node') is None:
        return
    handle, name = tempfile.mkstemp(suffix='.js')
    try:
        with os.fdopen(handle, 'wb') as out:
            out.write(content)
        p = subprocess.Popen(['node', '--check', name],
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        _stdout, stderr = p.communicate()
        if p.returncode != 0:
            raise RuntimeError('JavaScript no pasó node --check: ' +
                               stderr.decode('utf-8', 'replace')[:300])
    finally:
        os.unlink(name)


def run():
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true')
    mode.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    home = Path.home()
    root = Path(os.environ.get('IMPE_TOAST_PUBLIC',
        str(home / 'domains/fundacionrededumex.org/public_html')))
    file_path = root / RELATIVE
    if not (root / 'index.php').is_file() or not file_path.is_file() or file_path.is_symlink():
        raise RuntimeError('Falta observador real o ruta inválida; producción intacta')
    original = file_path.read_bytes()
    before = digest(original)
    try:
        decoded = original.decode('utf-8')
        candidate = updated(decoded).encode('utf-8')
    except (UnicodeError, ValueError) as e:
        raise RuntimeError('CONFLICTO: ' + str(e) + '. PRODUCCIÓN INTACTA.')
    syntax_check(candidate)
    if original == candidate:
        print('OK: aviso inferior del analista ya desactivado. PRODUCCIÓN INTACTA.')
        return
    if args.check:
        print('OK: aviso inferior del analista compatible. PRODUCCIÓN INTACTA.')
        print('Único archivo: ' + RELATIVE)
        print('No afecta llamadas, transferencias ni pendientes de MySQL.')
        return
    if digest(file_path.read_bytes()) != before:
        raise RuntimeError('CONFLICTO: cambió el archivo antes de aplicar. PRODUCCIÓN INTACTA.')
    backup_root = Path(os.environ.get('IMPE_TOAST_BACKUPS',
        str(home / 'respaldos-crm')))
    backup = backup_root / ('analista-sin-toast-' +
        datetime.datetime.now().strftime('%Y%m%d-%H%M%S-%f'))
    backup.mkdir(parents=True, exist_ok=False)
    saved = backup / file_path.name
    shutil.copy2(str(file_path), str(saved))
    # Escribir primero un temporal en el mismo directorio; reemplazo atómico.
    temp = None
    try:
        fd, temp = tempfile.mkstemp(prefix='.impe-analista-', suffix='.js',
                                    dir=str(file_path.parent))
        with os.fdopen(fd, 'wb') as output:
            output.write(candidate)
            output.flush()
            os.fsync(output.fileno())
        os.chmod(temp, file_path.stat().st_mode)
        if digest(file_path.read_bytes()) != before:
            raise RuntimeError('El archivo cambió durante el respaldo')
        os.replace(temp, str(file_path))
        temp = None
        if digest(file_path.read_bytes()) != digest(candidate):
            raise RuntimeError('Verificación posterior fallida')
        print('OK: aviso inferior eliminado. Respaldo: ' + str(saved))
        print('Recarga el CRM de Diego con Ctrl+Shift+R.')
    except Exception:
        if temp and os.path.exists(temp):
            os.unlink(temp)
        if digest(file_path.read_bytes()) == digest(candidate):
            shutil.copy2(str(saved), str(file_path))
        raise


if __name__ == '__main__':
    try:
        run()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
