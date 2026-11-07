#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Reorganiza SOLO el enlace de Mi cartera dentro del COMERCIAL existente.

--check: lectura, simulación y lint PHP; no escribe el sitio ni respaldos.
--apply: requiere SHA conocido por el respaldo de la instalación de Mi cartera,
         crea un respaldo independiente y escribe solo sidebar.php.
--restore RUTA: restaura bytes exactos solo si el sidebar sigue intacto.
Compatible con Python 3.6+. No SQL, PHP de negocio, telefonía o asignaciones.
"""
import argparse
import datetime
import hashlib
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
import textwrap

SIDE = 'app/views/layout/sidebar.php'
PACKAGE = 'impe_ventas_mi_cartera'
SENTINEL = 'IMPE_MI_CARTERA_SECCION_COMERCIAL_UNIFICADA'


def abort(message):
    raise RuntimeError(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def bytes_from(path):
    with open(path, 'rb') as handle:
        return handle.read()


def ensure_outside(root, site):
    root = os.path.realpath(root)
    if root == site or root.startswith(site + os.sep):
        abort('Los respaldos no pueden estar dentro de public_html.')
    return root


def site_path(site):
    result = os.path.join(site, SIDE)
    if os.path.islink(result) or not os.path.isfile(result):
        abort('No se admite un sidebar ausente o simbólico.')
    if not os.path.isdir(os.path.dirname(result)) or os.path.islink(os.path.dirname(result)):
        abort('Carpeta sidebar insegura.')
    return result


def valid_original_backup(site, backup_root, actual):
    """El SHA vivo debe estar reflejado en la entrega Mi cartera ya instalada."""
    root = ensure_outside(backup_root, site)
    if not os.path.isdir(root):
        abort('No se encontró la carpeta de respaldos de la instalación de Mi cartera.')
    matches = []
    for entry in sorted(os.listdir(root)):
        folder = os.path.join(root, entry)
        metadata = os.path.join(folder, 'respaldo.json')
        if os.path.islink(folder) or not os.path.isfile(metadata):
            continue
        try:
            with open(metadata, 'r', encoding='utf-8') as file:
                record = json.load(file)
            if record.get('schema') != 1 or record.get('package') != PACKAGE:
                continue
            rows = [item for item in record.get('files', []) if item.get('path') == SIDE]
            if len(rows) != 1:
                continue
            row = rows[0]
            if row.get('after_sha256') == actual:
                if row.get('present'):
                    saved = bytes_from(os.path.join(folder, row['backup_file']))
                    if digest(saved) != row['before_sha256']:
                        abort('Respaldo original dañado: ' + folder)
                matches.append(folder)
        except (OSError, ValueError, KeyError, TypeError) as e:
            abort('No se pudo verificar el respaldo original: ' + str(e))
    if len(matches) == 0:
        abort('CONFLICTO: el sidebar actual ya cambió o no corresponde a la publicación verificada de Mi cartera.')
    return matches[-1]


def expected_link_count(content, expected):
    number = content.count('controller=miCartera&amp;action=index')
    if number != expected:
        abort('CONFLICTO: enlaces Mi cartera detectados %s, se esperaban %s.' % (number, expected))


def rewrite(source):
    if SENTINEL.encode('utf-8') in source:
        abort('La unificación ya existe: no se publicará dos veces.')
    try:
        text = source.decode('utf-8')
    except UnicodeDecodeError:
        abort('El sidebar no tiene codificación UTF-8 estándar.')
    newline = '\r\n' if '\r\n' in text else '\n'
    expected_link_count(text, 2)

    # Estos dos bloques fueron añadidos por la versión inicial de Mi cartera.
    start = re.compile(r'(?m)^(?P<indent>[ \t]*)<\?php\s+if\s*\(\s*\$mostrarMiCartera\s*\)\s*:\s*\?>[ \t]*\r?\n')
    blocks = []
    for match in start.finditer(text):
        end = re.search(r'(?m)^[ \t]*<\?php\s+endif\s*;\s*\?>[ \t]*(?:\r?\n|$)', text[match.end():])
        if not end:
            abort('CONFLICTO: sección Mi cartera sin cierre inequívoco.')
        final = match.end() + end.end()
        section = text[match.end():match.end() + end.start()]
        # Rehusar cualquier contenedor no esperado: no borrar menús ajenos.
        block_pattern = re.compile(
            r'\s*<div\s+class="sidebar-section"\s*>\s*'
            r'<p\s+class="sidebar-section-title"\s*>\s*COMERCIAL\s*</p>\s*'
            r'(?P<link><a\s+href="<\?=\s*BASE_URL\s*\?>index\.php\?controller=miCartera&amp;action=index".*?</a>)\s*'
            r'</div>\s*', re.S)
        inner = block_pattern.fullmatch(section)
        if not inner:
            abort('CONFLICTO: el bloque COMERCIAL nuevo contiene personalizaciones desconocidas.')
        if section.count('<a ') != 1 or section.count('</a>') != 1:
            abort('CONFLICTO: Mi cartera no contiene un único enlace.')
        if 'Mi cartera' not in inner.group('link') or SENTINEL in section:
            abort('CONFLICTO: enlace Mi cartera desconocido.')
        blocks.append((match.start(), final, inner.group('link')))
    if len(blocks) != 2:
        abort('CONFLICTO: se esperaban dos grupos nuevos Mi cartera (escritorio/móvil), encontrados %s.' % len(blocks))
    if blocks[0][1] >= blocks[1][0]:
        abort('CONFLICTO: bloques superpuestos.')

    # Quitar únicamente esos dos grupos, preservando todo lo demás.
    cleaned = text
    for first, last, _ in reversed(blocks):
        cleaned = cleaned[:first] + cleaned[last:]
    expected_link_count(cleaned, 0)

    # El menú preexistente puede ser personalizado. Buscar sus cabeceras
    # COMERCIAL sin requerir la sintaxis de una variable PHP determinada.
    heading = re.compile(
        r'(?im)^([ \t]*)<p\s+class="sidebar-section-title"\s*>\s*COMERCIAL\s*</p>')
    headings = list(heading.finditer(cleaned))
    if len(headings) != 2:
        abort('CONFLICTO: se esperaban exactamente dos cabeceras COMERCIAL existentes, encontradas %s.' % len(headings))
    for h in headings:
        following = cleaned[h.end():h.end()+6500]
        if not any(word in following for word in ('Formularios','Inscritos')):
            abort('CONFLICTO: la sección COMERCIAL existente no contiene Formularios o Inscritos cerca de la cabecera.')

    # La posición de escritorio/móvil se conserva: corresponden en orden.
    proposals = []
    for h, block in zip(headings, blocks):
        indent = h.group(1)
        # Mantener la clase dinámica y los iconos del enlace instalado.
        link = textwrap.dedent(block[2]).strip('\r\n')
        lines = [line.rstrip() for line in link.splitlines()]
        link = (newline + indent + '    ').join(lines)
        part = (
            newline + indent + '<?php if ($mostrarMiCartera): ?>' + newline +
            indent + '    <!-- '+SENTINEL+' -->' + newline +
            indent + '    ' + link + newline +
            indent + '<?php endif; ?>'
        )
        proposals.append((h.end(), part))
    result = cleaned
    for end, part in reversed(proposals):
        result = result[:end] + part + result[end:]
    expected_link_count(result, 2)
    if result.count(SENTINEL) != 2:
        abort('CONFLICTO: marcadores de unificación incompletos.')
    if len(list(heading.finditer(result))) != 2:
        abort('CONFLICTO: encabezados duplicados en salida.')
    if result.count('Mis llamadas') != text.count('Mis llamadas') or \
       result.count('Formularios') != text.count('Formularios') or \
       result.count('Inscritos') != text.count('Inscritos') or \
       result.count('Convocatorias') != text.count('Convocatorias'):
        abort('CONFLICTO: una sección existente no se conservó.')
    return result.encode('utf-8')


def php_lint(data):
    with tempfile.NamedTemporaryFile(mode='wb', suffix='.php', prefix='mi-cartera-menu-') as file:
        file.write(data)
        file.flush()
        process = subprocess.run(['php', '-l', file.name], stdout=subprocess.PIPE,
                                 stderr=subprocess.STDOUT, universal_newlines=True)
        if process.returncode:
            abort('CONFLICTO: PHP lint falló:\n' + process.stdout)
    print('PHP OK: sidebar.php conciliado')


def atomic_write(dest, data, mode):
    fd, tmp = tempfile.mkstemp(prefix='.impe-menu-cartera-', dir=os.path.dirname(dest))
    try:
        with os.fdopen(fd, 'wb') as file:
            file.write(data)
            file.flush()
            os.fsync(file.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, dest)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def backup_new(root, site, current, target, content, mode):
    root = ensure_outside(root, site)
    if os.path.islink(root):
        abort('No usar carpeta de respaldo simbólica.')
    if not os.path.isdir(root):
        os.makedirs(root, mode=0o700)
    os.chmod(root, 0o700)
    stamp = datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S')
    folder = tempfile.mkdtemp(prefix='mi-cartera-sidebar-' + stamp + '-', dir=root)
    os.chmod(folder, 0o700)
    bpath = os.path.join(folder, 'sidebar.php.bak')
    with open(bpath, 'wb') as file:
        file.write(content)
    os.chmod(bpath, 0o600)
    record = {'schema': 1, 'module': 'mi_cartera_sidebar_unico',
              'path': SIDE, 'before_sha256': current, 'after_sha256': target,
              'backup_file': 'sidebar.php.bak', 'mode': mode}
    metadata = os.path.join(folder, 'respaldo.json')
    with open(metadata, 'w', encoding='utf-8') as file:
        json.dump(record, file, indent=2)
    os.chmod(metadata, 0o600)
    return folder


def restore(dest, site, folder):
    folder = ensure_outside(folder, site)
    record = json.loads(bytes_from(os.path.join(folder, 'respaldo.json')).decode('utf-8'))
    if record.get('module') != 'mi_cartera_sidebar_unico' or record.get('path') != SIDE:
        abort('Respaldo ajeno a la corrección de menú.')
    original = bytes_from(os.path.join(folder, 'sidebar.php.bak'))
    if digest(original) != record['before_sha256']:
        abort('El respaldo está dañado.')
    current = digest(bytes_from(dest))
    if current != record['after_sha256']:
        abort('CONFLICTO: otro cambio modificó sidebar.php; no se restaurará automáticamente.')
    atomic_write(dest, original, int(record['mode']))
    if digest(bytes_from(dest)) != record['before_sha256']:
        abort('ERROR: restauración no coincide con el respaldo.')
    print('RESTAURADO: sidebar.php exacto. Sin SQL ni otros archivos.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site', required=True)
    parser.add_argument('--initial-backups', required=False,
                        help='Carpeta privada del respaldo previo de Mi cartera')
    parser.add_argument('--backup-root')
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--check', action='store_true')
    group.add_argument('--apply', action='store_true')
    group.add_argument('--restore')
    args = parser.parse_args()
    if os.path.islink(args.site):
        abort('No utilizar sitio simbólico.')
    site = os.path.realpath(args.site)
    if not os.path.isdir(site):
        abort('No existe el sitio.')
    dest = site_path(site)
    if args.restore:
        restore(dest, site, args.restore)
        return
    initial = args.initial_backups or os.path.join(os.path.expanduser('~'), 'respaldos-impe-mi-cartera')
    content = bytes_from(dest)
    current = digest(content)
    valid_original_backup(site, initial, current)
    print('ORIGEN VERIFICADO: Mi cartera publicada; respaldo previo encontrado.')
    proposal = rewrite(content)
    php_lint(proposal)
    target = digest(proposal)
    print('ACTUAL: sidebar.php', current)
    print('PROPUESTO: sidebar.php', target)
    print('ESTRUCTURA: 1 COMERCIAL por menú (escritorio y móvil); enlaces existentes preservados.')
    if args.check:
        print('CHECK COMPATIBLE: sin escrituras, SQL ni respaldo nuevo.')
        return
    if not args.backup_root:
        abort('Para publicar indicar --backup-root fuera de public_html.')
    if digest(bytes_from(dest)) != current:
        abort('CONFLICTO: sidebar cambió mientras se validaba.')
    mode = stat.S_IMODE(os.stat(dest).st_mode)
    folder = backup_new(args.backup_root, site, current, target, content, mode)
    print('RESPALDO PRIVADO:', folder)
    atomic_write(dest, proposal, mode)
    if digest(bytes_from(dest)) != target:
        abort('Error de integridad después de escribir sidebar.php.')
    print('PUBLICADO: solo app/views/layout/sidebar.php; 0 SQL; telefonía y permisos intactos.')
    print('MENÚ COMERCIAL UNIFICADO.')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('ERROR:', str(error), file=sys.stderr)
        sys.exit(1)
