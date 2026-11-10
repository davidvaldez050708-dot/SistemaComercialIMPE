#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Publicador controlado EXCLUSIVAMENTE para el puente comercial PBX México.

--check: lee y valida una sola ruta, sin escrituras en public_html ni BD.
--apply: verifica versión previa y autorización explícita (incluso con
         asesores activos), crea respaldo privado y reemplaza atómicamente
         ese único archivo PHP.
--rollback: restaura la versión anterior solo desde un respaldo íntegro,
            siempre que el sitio conserve exactamente la versión publicada.

No ejecuta SQL, no reinicia servicios, no limpia OPcache, no toca Telefonía
(ni WebRTC, ni Zadarma, ni grabaciones, ni resultados existentes).
La bandera --confirm es una DECLARACIÓN MANUAL. Si hay llamadas activas,
se acepta un token distinto y explícito de publicación con riesgo conocido.
No se verifica el estado de las llamadas en tiempo real.
Compatible con Python 3.6 y PHP CLI.
"""
from __future__ import print_function

import argparse
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile

REL = 'app/services/EmbudoComercialResultadoBridge.php'
BEFORE = '4a60948e006879995b279fab331a1cb822359c2d'
AFTER = 'de7371eb9077948e503939f59e60a84137e47052'
CONFIRM = 'VENTANA_SIN_LLAMADAS_AUTORIZADA_157'
CONFIRM_ACTIVE = 'PUBLICAR_CON_ASESORES_ACTIVOS_ACEPTO_RIESGO_157'

class Blocked(RuntimeError):
    pass

def blob(data):
    return hashlib.sha1(('blob %d\0' % len(data)).encode('ascii') + data).hexdigest()

def require_regular(path, allow_missing=False):
    if path.is_symlink():
        raise Blocked('Enlace simbólico rechazado: {}'.format(path))
    if not path.exists():
        if allow_missing:
            return
        raise Blocked('No existe el archivo esperado: {}'.format(path))
    if not path.is_file():
        raise Blocked('Ruta no es archivo regular: {}'.format(path))

def site_target(site):
    site = site.resolve(strict=True)
    if not site.is_dir() or site.name != 'public_html':
        raise Blocked('Carpeta del sitio inválida; se requiere public_html')
    p = site
    for part in REL.split('/'):
        p = p/part
        if p.is_symlink():
            raise Blocked('Ruta simbólica bloqueada: {}'.format(p))
    require_regular(p)
    return site,p

def source_bytes(path):
    require_regular(path)
    data = path.read_bytes()
    actual = blob(data)
    if actual != AFTER:
        raise Blocked('Fuente no coincide con GitHub aprobado: {}'.format(actual))
    return data

def php_lint(file):
    try:
        completed = subprocess.run(
            ['php', '-l', str(file)],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            universal_newlines=True,
            timeout=35
        )
    except (OSError, subprocess.TimeoutExpired):
        raise Blocked('PHP CLI no disponible para comprobar sintaxis')
    if completed.returncode != 0:
        raise Blocked('PHP rechazó sintaxis: {}'.format(file))

def baseline(site, target, approved):
    current = target.read_bytes()
    actual = blob(current)
    if actual not in (BEFORE, AFTER):
        raise Blocked('CONFLICTO: código personalizado detectado ({}); sitio intacto'.format(actual))
    php_lint(approved)
    print('CHECK SITIO: {}'.format(actual))
    print('CHECK FUENTE: {}'.format(AFTER))
    print('SITUACIÓN: {}'.format(
        'ya actualizado' if actual == AFTER else 'versión previa compatible'
    ))
    print('ALCANCE: un archivo del puente comercial, sin Telefonía ni SQL')
    return current

def backup_root(site, root):
    # No se permiten backups dentro de public_html.
    root = root.resolve()
    if root == site or site in root.parents:
        raise Blocked('Respaldo privado obligatorio fuera de public_html')
    if root.exists() and not root.is_dir():
        raise Blocked('La ruta de respaldos no es un directorio')
    return root

def atomic_write(path, data, mode):
    fd, tmp = tempfile.mkstemp(prefix='.impe-bridge-157-', suffix='.php',
                                dir=str(path.parent))
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, str(path))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def backup_before(site, target, root, before):
    root = backup_root(site, root)
    root.mkdir(mode=0o700, parents=True, exist_ok=True)
    folder = root/('puente-pbx-mx-'+datetime.utcnow().strftime('%Y%m%d-%H%M%S')+
                   '-'+str(os.getpid()))
    folder.mkdir(mode=0o700)
    original = folder/'puente_original.php'
    original.write_bytes(before)
    os.chmod(str(original),0o600)
    if blob(original.read_bytes()) != BEFORE:
        raise Blocked('No se pudo comprobar respaldo original')
    manifest = {
        'proposito': 'Puente comercial PBX México; sin cambios en telefonía',
        'archivo': REL,
        'fecha_utc': datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ'),
        'antes_git_blob': BEFORE,
        'despues_git_blob': AFTER,
        'original': 'puente_original.php',
        'no_rollback_de_datos': 'No se alteró ninguna tabla.'
    }
    filename = folder/'manifest.json'
    filename.write_text(json.dumps(manifest, ensure_ascii=False, indent=2),
                        encoding='utf-8')
    os.chmod(str(filename),0o600)
    print('RESPALDO CREADO: {}'.format(folder))
    return folder

def apply(site, target, source, root, confirm):
    if confirm not in (CONFIRM, CONFIRM_ACTIVE):
        raise Blocked('Confirmación explícita ausente; no se publica')
    if confirm == CONFIRM_ACTIVE:
        print('AUTORIZADO CON ASESORES ACTIVOS: riesgo de versiones PHP concurrentes aceptado')
    before = baseline(site, target, source)
    if blob(before) == AFTER:
        print('SIN CAMBIOS: la versión compatible ya está publicada.')
        return
    if blob(before) != BEFORE:
        raise Blocked('La versión anterior no coincide; sitio intacto')
    if root is None:
        raise Blocked('Falta respaldo privado (--backup-root)')
    saved = backup_before(site, target, root, before)
    current = target.read_bytes()
    if current != before:
        raise Blocked('Archivo cambiado entre check y publicación')
    mode = stat.S_IMODE(target.stat().st_mode)
    replacement = source.read_bytes()
    if blob(replacement) != AFTER:
        raise Blocked('Fuente cambiada durante verificación')
    atomic_write(target, replacement, mode)
    try:
        if blob(target.read_bytes()) != AFTER:
            raise Blocked('El archivo publicado no coincide')
        php_lint(target)
    except Exception:
        if target.is_file() and blob(target.read_bytes()) == AFTER:
            atomic_write(target, before, mode)
            print('RESTAURADO: cambio revertido desde la memoria y copia privada')
        raise
    print('PUBLICADO: {}'.format(REL))
    print('RESPALDO PARA REVERTIR: {}'.format(saved))
    print('POST-CHECK OK: un PHP actualizado; sin reinicios, SQL ni cambios PBX')

def rollback(site, target, folder, confirm):
    if confirm not in (CONFIRM, CONFIRM_ACTIVE):
        raise Blocked('Confirmación explícita ausente; no se restaura')
    if confirm == CONFIRM_ACTIVE:
        print('RESTAURACIÓN CON ASESORES ACTIVOS: riesgo de versiones PHP concurrentes aceptado')
    if folder is None:
        raise Blocked('Falta --backup-folder')
    folder = folder.resolve(strict=True)
    if folder == site or site in folder.parents:
        raise Blocked('El respaldo debe estar fuera de public_html')
    manifest_file = folder/'manifest.json'
    original_file = folder/'puente_original.php'
    require_regular(manifest_file)
    require_regular(original_file)
    try:
        manifest = json.loads(manifest_file.read_text(encoding='utf-8'))
    except (ValueError, OSError):
        raise Blocked('Manifiesto de respaldo no válido')
    if (manifest.get('archivo') != REL or
        manifest.get('original') != 'puente_original.php' or
        manifest.get('antes_git_blob') != BEFORE or
        manifest.get('despues_git_blob') != AFTER):
        raise Blocked('Respaldo corresponde a otra operación')
    original = original_file.read_bytes()
    if blob(original) != BEFORE:
        raise Blocked('Respaldo alterado, no se restauró')
    actual = blob(target.read_bytes())
    if actual == BEFORE:
        print('SIN CAMBIOS: versión original ya restaurada')
        return
    if actual != AFTER:
        raise Blocked('CONFLICTO: archivo posterior personalizado; no restaurar')
    mode = stat.S_IMODE(target.stat().st_mode)
    atomic_write(target, original, mode)
    if blob(target.read_bytes()) != BEFORE:
        raise Blocked('La versión restaurada no coincide')
    php_lint(target)
    print('RESTAURADO: {}'.format(REL))
    print('CHECK OK: vuelta a la versión original sin tocar llamadas ni BD')

def main():
    parser = argparse.ArgumentParser(
        description='Solo puente comercial PBX México, nunca archivos telefónicos')
    parser.add_argument('--site', required=True)
    parser.add_argument('--source')
    parser.add_argument('--backup-root')
    parser.add_argument('--backup-folder')
    parser.add_argument('--confirm')
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--check', action='store_true')
    group.add_argument('--apply', action='store_true')
    group.add_argument('--rollback', action='store_true')
    o = parser.parse_args()

    site, target = site_target(Path(o.site))
    if o.rollback:
        rollback(site, target,
                 Path(o.backup_folder) if o.backup_folder else None,
                 o.confirm)
        return
    if not o.source:
        raise Blocked('Falta --source para comparar el archivo aprobado')
    source = Path(o.source)
    source_bytes(source)
    if o.check:
        baseline(site, target, source)
        print('CHECK COMPLETO: CERO ESCRITURAS, publicación no realizada')
        return
    apply(site, target, source, Path(o.backup_root) if o.backup_root else None,
          o.confirm)

if __name__ == '__main__':
    try:
        main()
    except (Blocked, OSError) as e:
        print('BLOQUEADO: {}'.format(e), file=sys.stderr)
        sys.exit(2)
