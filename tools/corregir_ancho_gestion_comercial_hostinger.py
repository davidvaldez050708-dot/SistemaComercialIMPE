#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Correccion muy localizada: evitar que Gestion comercial quede mas angosto.
Solo cambia el bloque que habia insertado la fase anterior en los dos menus.
No modifica CSS global, Mi cartera, SQL, permisos, telefonia ni otros modulos.
"""
import argparse
from datetime import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

FILE = 'app/views/layout/sidebar.php'
OLD_NAV = """        <?php if ($mostrarEmbudoComercial || $mostrarRecuperacionComercial || $mostrarDescartadosComercial): ?>
            <div class="sidebar-section">
                <a href="<?= BASE_URL ?>index.php?controller=embudoComercial&amp;action=index&amp;vista=<?= $mostrarEmbudoComercial?'embudo':($mostrarRecuperacionComercial?'recuperacion':'descartados') ?>"
                   class="sidebar-link <?= $seccionComercialActiva?'active':'' ?>">
                    <i class="bi bi-kanban" aria-hidden="true"></i> Gestión comercial
                </a>
            </div>
        <?php endif; ?>

"""

NEW_NAV = """        <?php if ($mostrarEmbudoComercial || $mostrarRecuperacionComercial || $mostrarDescartadosComercial): ?>
            <a href="<?= BASE_URL ?>index.php?controller=embudoComercial&amp;action=index&amp;vista=<?= $mostrarEmbudoComercial?'embudo':($mostrarRecuperacionComercial?'recuperacion':'descartados') ?>"
               class="sidebar-link <?= $seccionComercialActiva?'active':'' ?>">
                <i class="bi bi-kanban" aria-hidden="true"></i>
                Gestión comercial
            </a>
        <?php endif; ?>

"""

class Conflicto(Exception):
    pass

def sha(data):
    return hashlib.sha256(data).hexdigest()

def preparar(site):
    target=site/FILE
    if not target.is_file() or target.is_symlink():
        raise Conflicto('sidebar.php ausente o enlace simbolico, no se cambia')
    before=target.read_bytes()
    try:
        text=before.decode('utf-8')
    except UnicodeError:
        raise Conflicto('sidebar.php no es UTF-8')
    old_count=text.count(OLD_NAV)
    new_count=text.count(NEW_NAV)
    if old_count==2 and new_count==0:
        output=text.replace(OLD_NAV,NEW_NAV).encode('utf-8')
    elif old_count==0 and new_count==2:
        output=before
    else:
        raise Conflicto('Menu personalizado o cambio parcial: original={} corregido={}; sin sobrescribir'.format(old_count,new_count))
    if output.decode('utf-8').count('Gestión comercial')!=2:
        raise Conflicto('No se conserva exactamente una entrada por cada menu')
    if b'<div class="sidebar-section">' in NEW_NAV.encode('utf-8'):
        raise Conflicto('La correccion aun contiene padding duplicado')
    # Test sintaxis sin modificar el archivo instalado.
    fd,temp=tempfile.mkstemp(suffix='.php',prefix='impe-sidebar-check-')
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(output)
        p=subprocess.run(['php','-l',temp],stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,universal_newlines=True)
        if p.returncode:
            raise Conflicto('Error de sintaxis: '+(p.stdout+p.stderr)[:350])
    finally:
        if os.path.exists(temp):
            os.unlink(temp)
    return before,output

def aplicar(site,backup_root,before,after):
    if before==after:
        print('YA APLICADO: sin escrituras')
        return
    if backup_root is None:
        raise Conflicto('--apply necesita --backup-root')
    if backup_root.resolve()==site.resolve() or site.resolve() in backup_root.resolve().parents:
        raise Conflicto('Respaldo debe quedar fuera de public_html')
    backup_root.mkdir(parents=True,exist_ok=True)
    dirname='sidebar-gestion-comercial-ancho-'+datetime.utcnow().strftime('%Y%m%d-%H%M%S')+'-'+str(os.getpid())
    folder=backup_root/dirname
    folder.mkdir(mode=0o700)
    original=folder/FILE
    original.parent.mkdir(parents=True,exist_ok=True)
    target=site/FILE
    shutil.copy2(str(target),str(original))
    (folder/'manifest.json').write_text(json.dumps({
        'archivo':FILE,
        'sha256_anterior':sha(before),
        'sha256_corregido':sha(after),
        'creado_utc':datetime.utcnow().isoformat()+'Z',
    },indent=2),encoding='utf-8')
    if target.read_bytes()!=before:
        raise Conflicto('El sidebar cambio despues del check; respaldo='+str(folder))
    applied=False
    try:
        fd,temp=tempfile.mkstemp(prefix='.impe-sidebar-',dir=str(target.parent))
        try:
            with os.fdopen(fd,'wb') as f:
                f.write(after)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(temp,target.stat().st_mode & 0o777)
            os.replace(temp,str(target))
            applied=True
        finally:
            if os.path.exists(temp):
                os.unlink(temp)
        if target.read_bytes()!=after:
            raise Conflicto('Diferencia al verificar publicacion')
        print('APLICADO: solo '+FILE)
        print('RESPALDO PRIVADO: '+str(folder))
    except Exception:
        if applied:
            shutil.copy2(str(original),str(target))
            print('REVERTIDO: archivo original restaurado',file=sys.stderr)
        raise

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--site',required=True)
    ap.add_argument('--backup-root')
    g=ap.add_mutually_exclusive_group(required=True)
    g.add_argument('--check',action='store_true')
    g.add_argument('--apply',action='store_true')
    a=ap.parse_args()
    site=Path(a.site).resolve()
    before,after=preparar(site)
    if a.check:
        print(('COMPATIBLE' if before!=after else 'APLICADO')+': '+FILE)
        print('CHECK OK: sin escrituras, solo ancho del menu comercial')
        return
    aplicar(site,Path(a.backup_root).resolve() if a.backup_root else None,before,after)
    if preparar(site)[0]!=after:
        raise Conflicto('Post-check no coincidente')
    print('POST CHECK OK: enlaces restantes, SQL y permisos intactos')

if __name__=='__main__':
    try:
        main()
    except (Conflicto, OSError, RuntimeError) as e:
        print('BLOQUEADO: '+str(e),file=sys.stderr)
        sys.exit(2)
