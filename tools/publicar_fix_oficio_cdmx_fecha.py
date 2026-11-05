#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador reversible: fecha local y origen CDMX en oficio general. Solo dos PHP."""
from __future__ import print_function
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
import xml.etree.ElementTree as ET
import zipfile

FILES = (
    'app/services/OficioPreviewService.php',
    'app/services/OficioDocxPdfService.php',
)
OLD_DATE = """            $fechaObjeto = trim((string)$fecha) !== ''
                ? new DateTime((string)$fecha)
                : new DateTime();
        } catch (Exception $error) {
            $fechaObjeto = new DateTime();"""
NEW_DATE = """            // MySQL registra created_at en UTC; mostrar fecha local de emisión.
            $zonaMexico = new DateTimeZone('America/Mexico_City');
            $fechaObjeto = trim((string)$fecha) !== ''
                ? new DateTime((string)$fecha, new DateTimeZone('UTC'))
                : new DateTime('now', $zonaMexico);
            $fechaObjeto->setTimezone($zonaMexico);
        } catch (Exception $error) {
            $fechaObjeto = new DateTime('now', new DateTimeZone('America/Mexico_City'));"""
OLD_REPLACE = """    private function construirReemplazos(array $vista)
    {
        $reemplazos = [
            '{{FOLIO}}' => trim((string)($vista['folio'] ?? '')),"""
NEW_REPLACE = """    private function construirReemplazos(array $vista)
    {
        $reemplazos = [
            // Compatibilidad de la plantilla general REDMEX: origen fijo legado.
            // Se sustituye la línea completa ANTES de procesar {{FECHA}}.
            // El archivo DOCX original y las plantillas personales no se modifican.
            'Cuernavaca, Morelos, a {{FECHA}}' => trim((string)($vista['lugar_fecha'] ?? $vista['fecha'] ?? '')),
            '{{FOLIO}}' => trim((string)($vista['folio'] ?? '')),"""


def sha(data):
    return hashlib.sha256(data).hexdigest()


def ensure(root, name):
    path = root / name
    if not path.is_file() or path.is_symlink() or root not in path.resolve().parents:
        raise RuntimeError('Archivo no encontrado o enlace no autorizado: ' + name)
    return path


def alter(data, before, after, name):
    old, new = before.encode('utf-8'), after.encode('utf-8')
    if b'\r\n' in data:
        old, new = old.replace(b'\n', b'\r\n'), new.replace(b'\n', b'\r\n')
    if data.count(new) == 1 and data.count(old) == 0:
        print('YA INSTALADO: ' + name)
        return data
    if data.count(old) != 1 or data.count(new) != 0:
        raise RuntimeError('CONFLICTO: fragmento diferente en ' + name + '; sitio intacto')
    print('COMPATIBLE: ' + name)
    return data.replace(old, new, 1)


def preflight(site):
    original = {name: ensure(site, name).read_bytes() for name in FILES}
    preview = original[FILES[0]]
    docxservice = original[FILES[1]]
    if 'Ciudad de México, a '.encode('utf-8') not in preview:
        raise RuntimeError('No se encuentra la selección CDMX del oficio predeterminado')
    if b'$this->rellenarDocx($rutaDocx, $this->construirReemplazos($vista))' not in docxservice:
        raise RuntimeError('No se verifica el circuito del oficio general')
    template = ensure(site, 'storage/templates/oficio_general_redmex.docx')
    with zipfile.ZipFile(str(template)) as archivo:
        xml = archivo.read('word/document.xml')
    doc = ET.fromstring(xml)
    namespace = {'w': 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'}
    paragraphs = [
        ''.join((el.text or '') for el in paragraph.findall('.//w:t', namespace))
        for paragraph in doc.findall('.//w:body//w:p', namespace)
    ]
    if not any('Cuernavaca, Morelos, a {{FECHA}}' in text for text in paragraphs):
        raise RuntimeError('La plantilla general no contiene el origen esperado; no aplicar')
    changed = {
        FILES[0]: alter(preview, OLD_DATE, NEW_DATE, FILES[0]),
        FILES[1]: alter(docxservice, OLD_REPLACE, NEW_REPLACE, FILES[1]),
    }
    if not shutil.which('php'):
        raise RuntimeError('Se requiere php CLI para comprobación sintáctica')
    with tempfile.TemporaryDirectory(prefix='impe-oficio-fecha-preflight-') as temp:
        for name in FILES:
            staged = Path(temp) / Path(name).name
            staged.write_bytes(changed[name])
            proc = subprocess.run(['php', '-l', str(staged)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            if proc.returncode:
                raise RuntimeError('SINTAXIS PHP ' + name + ': ' + proc.stderr.decode('utf-8', 'replace')[:400])
    print('CHECK OK: lugar general CDMX, fecha UTC a México, sintaxis PHP y Word original intacto')
    return original, changed


def atomic(path, content, mode):
    fd, temporary = tempfile.mkstemp(prefix='.impe-fecha-', dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def apply(site, root):
    before, after = preflight(site)
    changes = [name for name in FILES if before[name] != after[name]]
    if not changes:
        print('YA INSTALADO: no se realizaron escrituras')
        return
    if site == root or site in root.parents or root in site.parents:
        raise RuntimeError('Los respaldos deben estar fuera de la carpeta pública')
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(str(root), 0o700)
    folder = Path(tempfile.mkdtemp(prefix=datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S-'), dir=str(root)))
    os.chmod(str(folder), 0o700)
    metas = []
    for name in changes:
        source = ensure(site, name)
        backup = folder / 'original' / name
        backup.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        backup.write_bytes(before[name])
        metas.append({'path': name, 'before': sha(before[name]), 'after': sha(after[name]),
                      'mode': stat.S_IMODE(source.stat().st_mode)})
    (folder / 'respaldo.json').write_text(
        json.dumps({'site': str(site), 'files': metas}, indent=2), encoding='utf-8')
    for name in changes:
        if ensure(site, name).read_bytes() != before[name]:
            raise RuntimeError('CONFLICTO CONCURRENTE antes de publicar: ' + name)
    installed = []
    try:
        for meta in metas:
            name = meta['path']
            path = site / name
            if path.read_bytes() != before[name]:
                raise RuntimeError('CONFLICTO CONCURRENTE: ' + name)
            atomic(path, after[name], meta['mode'])
            installed.append(meta)
            if path.read_bytes() != after[name]:
                raise RuntimeError('No se verificó la instalación: ' + name)
    except BaseException:
        for meta in reversed(installed):
            name = meta['path']
            path = site / name
            if path.read_bytes() == after[name]:
                atomic(path, before[name], meta['mode'])
        raise
    print('APLICADO: CDMX en oficio general y fecha local del oficio')
    print('RESPALDO: ' + str(folder))
    print('SIN CAMBIOS: DOCX, SQL, folios, PDF almacenados, plantillas personalizadas y otros módulos')


def restore(site, root, folder_name):
    folder = Path(folder_name).resolve()
    if root not in folder.parents:
        raise RuntimeError('Respaldo fuera de ubicación autorizada')
    meta = json.loads((folder / 'respaldo.json').read_text(encoding='utf-8'))
    if meta.get('site') != str(site):
        raise RuntimeError('Este respaldo no corresponde al CRM seleccionado')
    plans = []
    for item in meta['files']:
        name = item['path']
        if name not in FILES:
            raise RuntimeError('Ruta de respaldo no autorizada')
        dest = ensure(site, name)
        original = (folder / 'original' / name).read_bytes()
        if sha(original) != item['before'] or sha(dest.read_bytes()) != item['after']:
            raise RuntimeError('CONFLICTO: archivos modificados después de la aplicación: ' + name)
        plans.append((dest, original, item['mode']))
    for path, content, mode in reversed(plans):
        atomic(path, content, mode)
    print('RESTAURADO: 2 servicios PHP según respaldo; DOCX y SQL intactos')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--backup-root', required=True)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--check', action='store_true')
    group.add_argument('--apply', action='store_true')
    group.add_argument('--restore')
    args = parser.parse_args()
    site = Path(args.site).resolve()
    root = Path(args.backup_root).resolve()
    if not (site / 'index.php').is_file():
        raise RuntimeError('No se encontró index.php del CRM')
    if args.check:
        preflight(site)
    elif args.apply:
        apply(site, root)
    else:
        restore(site, root, args.restore)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR: ' + str(error), file=sys.stderr)
        sys.exit(1)
