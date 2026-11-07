#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Probar unificación de COMERCIAL y restauración en una instalación ficticia."""
import hashlib
import json
import pathlib
import re
import subprocess
import sys
import tempfile

SCRIPT = pathlib.Path(__file__).resolve().parents[1] / 'tools/ajustar_mi_cartera_sidebar_comercial.py'

old_group = '''<?php if ($mostrarMiCartera): ?>
<div class="sidebar-section">
    <p class="sidebar-section-title">COMERCIAL</p>
    <a href="<?= BASE_URL ?>index.php?controller=miCartera&amp;action=index"
       class="sidebar-link <?= ($opcionActiva ?? '') === 'mi_cartera' ? 'active' : '' ?>">
        <i class="bi bi-person-vcard" aria-hidden="true"></i> Mi cartera
    </a>
</div>
<?php endif; ?>
'''
preexisting = '''<div class="sidebar-section">
  <p class="sidebar-section-title">
  COMERCIAL
  </p>
  <a href="<?= BASE_URL ?>index.php?controller=formulario&action=index">Formularios</a>
  <a href="<?= BASE_URL ?>index.php?controller=inscrito&action=index">Inscritos</a>
</div>
'''
parts = ['<?php $mostrarMiCartera=true; $opcionActiva="mi_cartera"; ?>\n']
for side in ('desktop','mobile'):
    parts.extend([f'<!-- {side} -->\n',
        '<nav class="sidebar-navigation">\n',
        '<div class="sidebar-section"><p class="sidebar-section-title">INICIO</p>Inicio</div>\n',
        old_group,
        '<div class="sidebar-section"><p class="sidebar-section-title">TELEFONÍA</p>Mis llamadas</div>\n',
        '<div class="sidebar-section"><p class="sidebar-section-title">MARKETING</p>Convocatorias</div>\n',
        preexisting, '</nav>\n'])
original=''.join(parts).encode('utf8')

def digest(b): return hashlib.sha256(b).hexdigest()
def invoke(site, old, opts, expected=0):
    process=subprocess.run([sys.executable,str(SCRIPT),'--site',str(site),
          '--initial-backups',str(old)]+opts,
          stdout=subprocess.PIPE, stderr=subprocess.STDOUT,universal_newlines=True)
    if process.returncode != expected:
        raise AssertionError('Unexpected return for %s: %s' % (opts, process.stdout))
    return process.stdout

def setup(t):
    site=t/'public_html'
    target=site/'app/views/layout/sidebar.php'
    target.parent.mkdir(parents=True)
    target.write_bytes(original)
    old=t/'previous';old.mkdir()
    folder=old/'impe-mi-cartera-original';folder.mkdir()
    prev=b'previous/sidebar.php'
    (folder/'2.bak').write_bytes(prev)
    record={'schema':1,'package':'impe_ventas_mi_cartera',
        'files':[{'path':'app/views/layout/sidebar.php','present':True,
                  'backup_file':'2.bak','before_sha256':digest(prev),
                  'after_sha256':digest(original)}]}
    (folder/'respaldo.json').write_text(json.dumps(record))
    return site,target,old

with tempfile.TemporaryDirectory(prefix='test-comercial-unico-') as x:
    t=pathlib.Path(x)
    site,target,old=setup(t)
    backups=t/'new'
    output=invoke(site,old,['--check'])
    assert 'CHECK COMPATIBLE' in output, output
    assert target.read_bytes()==original
    assert not backups.exists()
    output=invoke(site,old,['--apply','--backup-root',str(backups)])
    assert 'MENÚ COMERCIAL UNIFICADO' in output, output
    new=target.read_text()
    assert new.count('controller=miCartera&amp;action=index')==2
    assert len(re.findall(r'(?s)<p class="sidebar-section-title">\s*COMERCIAL\s*</p>',new))==2
    assert new.count('Formularios')==2 and new.count('Inscritos')==2
    assert new.count('Convocatorias')==2 and new.count('Mis llamadas')==2
    for menu in new.split('<!-- ')[1:]:
        if 'Formularios' in menu:
            assert menu.index('Mi cartera') > menu.index('COMERCIAL'), menu
            assert menu.index('Mi cartera') < menu.index('Formularios'), menu
    folders=list(backups.iterdir());assert len(folders)==1
    restore=invoke(site,old,['--restore',str(folders[0])])
    assert 'RESTAURADO' in restore and target.read_bytes()==original

    # Rechazar diferencias en el sidebar tras un despliegue.
    target.write_bytes(original+b'<!-- Another concurrent edit -->\n')
    assert 'CONFLICTO' in invoke(site,old,['--check'],expected=1)
    target.write_bytes(original)
    target.write_bytes(original.replace(b'Formularios',b'NO_FORM',2))
    assert 'CONFLICTO' in invoke(site,old,['--check'],expected=1)
    target.write_bytes(original)

    # Restauración no debe sobrescribir cambios concurrentes.
    invoke(site,old,['--apply','--backup-root',str(backups)])
    folder=max(backups.iterdir(),key=lambda p:p.stat().st_mtime_ns)
    target.write_bytes(target.read_bytes()+b'<!-- another change -->\n')
    assert 'CONFLICTO' in invoke(site,old,['--restore',str(folder)],expected=1)
    assert target.read_bytes().endswith(b'<!-- another change -->\n')
print('OK: menú COMERCIAL único en escritorio/móvil, contenido preservado, backups y restore seguros')
