#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicación selectiva del convertidor Word final: --check/--apply/--restore."""
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

SERVICE = 'app/services/OficioDocxPdfService.php'
NEW = 'app/services/OficioDocumentoFinalPdfService.php'
NEW_BLOB = '2e426c23186bd01951ce3795ea29c307e6d010a5'
MARKER = b'    public function generarPdf(array $vista)\n    {\n'
ROUTE = r"""        // Un DOCX terminado no necesita marcadores ni plantilla REDMEX.
        if (strpos(trim((string)($vista['plantilla'] ?? '')), 'Oficio adjuntado: ') === 0) {
            $word = str_replace('\\', '/', trim((string)($vista['archivo_plantilla'] ?? '')));
            if (!preg_match('~^storage/templates/oficios_personalizados/generacion_[0-9]{8}_[0-9]{6}_[a-f0-9]{12}\.docx$~D', $word)) {
                return $this->error('No se encontró el oficio Word terminado.', 'WORD_FINAL_SOURCE');
            }
            require_once __DIR__ . '/OficioDocumentoFinalPdfService.php';
            return (new OficioDocumentoFinalPdfService())->convertir($this->resolverRutaPlantilla($vista));
        }
""".encode('utf-8')


def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()


def hash256(data):
    return hashlib.sha256(data).hexdigest()


def atomic(path, data, mode):
    fd, temp = tempfile.mkstemp(prefix='.impe-wordfinal-', dir=str(path.parent))
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


def php_ok(data, name):
    with tempfile.TemporaryDirectory(prefix='impe-final-php-') as temp:
        path = Path(temp) / name
        path.write_bytes(data)
        p = subprocess.run(['php', '-l', str(path)],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if p.returncode:
            raise RuntimeError('PHP inválido: ' + name)


def engine_ok():
    configured = os.environ.get('IMPE_OFICIO_FINAL_LIBREOFFICE', '').strip()
    choices = [configured] if configured else [
        '/usr/bin/libreoffice', '/usr/bin/soffice', '/usr/local/bin/soffice']
    exe = next((v for v in choices if Path(v).is_file() and os.access(v, os.X_OK)
                and not Path(v).is_symlink()), None)
    proc = subprocess.run(['php','-r','echo function_exists("proc_open") ? "yes" : "no";'],
                          stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    enabled = proc.returncode == 0 and proc.stdout.strip() == b'yes'
    print('Motor LibreOffice: ' + ('DETECTADO' if exe else 'NO DISPONIBLE'))
    print('proc_open PHP CLI: ' + ('HABILITADO' if enabled else 'NO DISPONIBLE'))
    if not exe or not enabled:
        raise RuntimeError(
            'BLOQUEADO: Hostinger no tiene el motor Word→PDF requerido. '
            'No se modifica producción ni se instala una conversión incompleta.'
        )


def inspect(site, src):
    if not (site / 'index.php').is_file():
        raise RuntimeError('CRM no encontrado')
    target = site / SERVICE
    if not target.is_file() or target.is_symlink():
        raise RuntimeError('Servicio del CRM ausente o enlace simbólico')
    original = target.read_bytes()
    if b'OficioRedmexPdfRenderer' not in original:
        raise RuntimeError('CONFLICTO: la instalación no usa el flujo REDMEX conocido')
    raw = src.read_bytes() if src.is_file() and not src.is_symlink() else b''
    if blob(raw) != NEW_BLOB:
        raise RuntimeError('INTEGRIDAD: archivo nuevo no coincide con GitHub')
    marker, insert = MARKER, ROUTE
    if b'\r\n' in original:
        marker = marker.replace(b'\n', b'\r\n')
        insert = insert.replace(b'\n', b'\r\n')
    newFile = site / NEW
    if newFile.exists() and (newFile.is_symlink() or newFile.read_bytes() != raw):
        raise RuntimeError('CONFLICTO: convertidor Word final ya personalizado')
    if original.count(marker + insert) == 1:
        updated = original
    elif original.count(marker) == 1 and b'OficioDocumentoFinalPdfService' not in original:
        updated = original.replace(marker, marker + insert, 1)
    else:
        raise RuntimeError('CONFLICTO: generarPdf personalizado; sitio intacto')
    php_ok(updated, 'OficioDocxPdfService.php')
    php_ok(raw, 'OficioDocumentoFinalPdfService.php')
    print('COMPATIBLE: ruta aislada Word final, sin alterar el formato general')
    engine_ok()
    print('CHECK OK: 2 servicios PHP, sin SQL ni cambios a documentos')
    return target, original, updated, newFile, raw


def restore(site, backups, folder):
    folder = Path(folder).resolve()
    if backups not in folder.parents:
        raise RuntimeError('Respaldo fuera de carpeta autorizada')
    metadata = json.loads((folder / 'respaldo.json').read_text(encoding='utf-8'))
    if metadata['site'] != str(site):
        raise RuntimeError('Respaldo de otra instalación')
    service = site / SERVICE
    previous = (folder / 'original_php').read_bytes()
    if hash256(previous) != metadata['before'] or hash256(service.read_bytes()) != metadata['after']:
        raise RuntimeError('CONFLICTO: servicio modificado después de publicación')
    new = site / NEW
    if not new.is_file() or hash256(new.read_bytes()) != metadata['added']:
        raise RuntimeError('CONFLICTO: convertidor modificado después de publicación')
    atomic(service, previous, metadata['mode'])
    new.unlink()
    print('RESTAURADO: servicios originales; sin SQL')


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--site', required=True)
    p.add_argument('--source', required=True)
    p.add_argument('--backup-root', required=True)
    modes = p.add_mutually_exclusive_group(required=True)
    modes.add_argument('--check', action='store_true')
    modes.add_argument('--apply', action='store_true')
    modes.add_argument('--restore')
    a = p.parse_args()
    site, src, backups = [Path(v).resolve() for v in (a.site,a.source,a.backup_root)]
    if backups == site or site in backups.parents or backups in site.parents:
        raise RuntimeError('Respaldo fuera de public_html obligatorio')
    if a.restore:
        restore(site, backups, a.restore)
        return
    service, before, after, newFile, newBytes = inspect(site, src)
    if a.check:
        return
    if before == after and newFile.is_file():
        print('YA INSTALADO, sin escrituras')
        return
    backups.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(str(backups), 0o700)
    folder = Path(tempfile.mkdtemp(
        prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'),
        dir=str(backups)))
    mode = stat.S_IMODE(service.stat().st_mode)
    (folder / 'original_php').write_bytes(before)
    (folder / 'respaldo.json').write_text(json.dumps({
        'site': str(site), 'before':hash256(before), 'after':hash256(after),
        'added':hash256(newBytes), 'mode':mode}, indent=2), encoding='utf-8')
    if service.read_bytes() != before or newFile.exists():
        raise RuntimeError('CONFLICTO: cambios concurrentes, sitio intacto')
    newFile.write_bytes(newBytes)
    try:
        atomic(service, after, mode)
        if service.read_bytes() != after:
            raise RuntimeError('Aplicación no verificada')
    except Exception:
        if service.read_bytes() == after:
            atomic(service, before, mode)
        if newFile.read_bytes() == newBytes:
            newFile.unlink()
        raise
    print('APLICADO: Word final independiente del renderizador REDMEX')
    print('RESPALDO: ' + str(folder))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
