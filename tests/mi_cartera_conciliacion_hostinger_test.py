#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Tests of selective reconciliation on a disposable, personalized Hostinger copy."""
import hashlib
import json
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile

ROOT=pathlib.Path(__file__).resolve().parents[1]
PACKAGE=json.loads((ROOT/'tools/ventas_mi_cartera_release.json').read_text(encoding='utf8'))
ORIG_SCRIPT=(ROOT/'tools/conciliar_ventas_mi_cartera_hostinger.py').read_text(encoding='utf8')
OLD_HASHES={
    'index.php':'278d06fa51c5b2ee5ad18acbe696b29dbdfe5facf2aa4de86526c6cf42870948',
    'app/views/layout/sidebar.php':'5f20da7902badda3697dbaa902179f6f1aef9fcf5b0bb6f5a8f1473ba3dd5aab'
}
checks=0
def check(ok,message):
    global checks
    if not ok:
        raise AssertionError('FALLO: '+message)
    checks+=1

def sha(data):
    return hashlib.sha256(data).hexdigest()

def save(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(data)

def run(script,site,flags,success=True):
    proc=subprocess.run([sys.executable,str(script),'--site',str(site)]+flags,
        stdout=subprocess.PIPE,stderr=subprocess.STDOUT,universal_newlines=True)
    if (proc.returncode==0)!=success:
        raise AssertionError('unexpected %s: %s'%(flags,proc.stdout))
    return proc.stdout

index=(
    "<?php\n"
    "// Integración personalizada Hostinger que debe conservarse.\n"
    "$controller='prospecto';\n"
    "switch ($controller) {\n"
    "    case 'home':\n"
    "        break;\n"
    "    case 'prospecto':\n"
    "        break;\n"
    "}\n"
).encode('utf8')
sidebar=(
    "<?php\n"
    "$mostrarProspectos = tienePermiso('prospectos.ver_todos');\n"
    "$claseProspectos =\n"
    "    $opcionActiva === 'prospectos' ? 'active' : '';\n"
    "// Personalización de menú no relacionada, proteger.\n"
    "?>\n"
    "<aside>\n"
    "    <?php if ($mostrarMisLlamadas): ?>\n"
    "    <a>Mis llamadas</a>\n"
    "    <?php endif; ?>\n"
    "</aside>\n"
    "<div id='mobileSidebar'>\n"
    "            <?php if ($mostrarMisLlamadas): ?>\n"
    "            <a>Mis llamadas</a>\n"
    "            <?php endif; ?>\n"
    "</div>\n"
).encode('utf8')
check('miCartera' not in index.decode() and 'miCartera' not in sidebar.decode(),
    'fixture original no contiene nuevas rutas')
check(len(re.findall(r'if\s*\(\$mostrarMisLlamadas\)',sidebar.decode()))==2,
    'fixture contiene dos menús')

with tempfile.TemporaryDirectory(prefix='impe-concilia-cartera-') as temp:
    t=pathlib.Path(temp)
    site=t/'public_html';site.mkdir()
    folder=t/'tools';folder.mkdir()
    backup=t/'backup'
    originals={
        'index.php':index,
        'app/views/layout/sidebar.php':sidebar
    }
    fixture=json.loads(json.dumps(PACKAGE))
    for dep in fixture['dependencies']:
        data=('DEPENDENCIA_FICTICIA: '+dep['path']).encode('utf8')
        dep['sha256']=sha(data)
        save(site/dep['path'],data)
        originals[dep['path']]=data
    for item in fixture['files']:
        path=item['path']
        if path in originals:
            continue
        if item['base_sha256'] is not None:
            data=('ARCHIVO_HOSTINGER_FICTICIO '+path).encode('utf8')
            item['base_sha256']=sha(data)
            save(site/path,data)
            originals[path]=data
        elif not path.startswith('app/views/mi_cartera/'):
            (site/path).parent.mkdir(parents=True,exist_ok=True)
    save(site/'index.php',index)
    save(site/'app/views/layout/sidebar.php',sidebar)
    metadata=json.dumps(fixture,ensure_ascii=False,indent=2)+'\n'
    (folder/'ventas_mi_cartera_release.json').write_text(metadata,encoding='utf8')
    shutil.copy2(str(ROOT/'tools/publicar_ventas_mi_cartera.py'),
                 str(folder/'publicar_ventas_mi_cartera.py'))

    source=ORIG_SCRIPT
    for path,digest in OLD_HASHES.items():
        actual=sha(originals[path])
        check(digest in source,'ancla SHA del sitio '+path)
        source=source.replace(digest,actual)
    prior_hash='3d81ba3cea37fe9d09ddd88032d93c496fcf6489221373974660de117dfe84f5'
    check(prior_hash in source,'manifiesto original está sellado')
    source=source.replace(prior_hash,sha(metadata.encode('utf8')))
    script=folder/'conciliar_ventas_mi_cartera_hostinger.py'
    script.write_text(source,encoding='utf8')

    check('CHECK CONCILIADO' in run(script,site,['--check']),'preflight sintaxis y anclajes')
    check(not backup.exists(),'check no crea respaldos')
    check((site/'index.php').read_bytes()==index,'check no altera index.php')
    check((site/'app/views/layout/sidebar.php').read_bytes()==sidebar,'check no altera sidebar.php')

    result=run(script,site,['--apply','--backup-root',str(backup)])
    check('PUBLICADO: 8 archivos' in result,'publicación de ocho archivos')
    check('CONCILIACIÓN PUBLICADA' in result,'confirmación de conciliación')
    new_index=(site/'index.php').read_text(encoding='utf8')
    new_sidebar=(site/'app/views/layout/sidebar.php').read_text(encoding='utf8')
    check(new_index.count("case 'miCartera':")==1,'un solo router de cartera')
    check(new_index.count('// Integración personalizada Hostinger')==1,'se conserva personalización index')
    check(new_sidebar.count('controller=miCartera&amp;action=index')==2,
          'dos menús, uno en móvil y otro en escritorio')
    check(new_sidebar.count('Personalización de menú no relacionada')==1,
          'se conserva personalización sidebar')
    check(new_sidebar.count('class="sidebar-section"')==2,
          'no se agregan módulos inesperados en el fixture')
    check('prospectos.mi_cartera' in new_sidebar,'permiso granular')
    check('!===3' not in new_sidebar,'sin errores de sintaxis deliberados')
    check(len(list(backup.iterdir()))==1,'un solo respaldo privado')

    folders=list(backup.iterdir())
    # Los originales desconocidos vuelven exactos y las nuevas vistas se retiran.
    check('RESTORE COMPLETO' in run(script,site,['--restore',str(folders[0])]),
          'restore del paquete conciliado')
    for p,data in originals.items():
        check((site/p).read_bytes()==data,'restauración exacta '+p)
    for path in ['app/views/mi_cartera/index.php','app/views/mi_cartera/detalle.php']:
        check(not (site/path).exists(),'restauración borra solo archivo nuevo '+path)

    # Si cambia una personalización, no debe iniciar publicación parcial.
    (site/'index.php').write_bytes(index+b'\n// Cambio concurrente\n')
    output=run(script,site,['--check'],False)
    check('SHA de index.php cambió' in output,'SHA exacto impide tocar index personalizado')
    (site/'index.php').write_bytes(index)
    (site/'app/views/layout/sidebar.php').write_bytes(sidebar.replace(
        b'$mostrarProspectos =',b'$mostrarProspectosDistinto ='))
    output=run(script,site,['--check'],False)
    check('SHA de app/views/layout/sidebar.php cambió' in output,
          'SHA exacto impide publicar menú desconocido')
print('OK: %d pruebas de compatibilidad, personalización preservada y rollback.'%checks)
