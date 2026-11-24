"""Valida el paquete sobre el repositorio y, si existe, la lectura instalada."""
import ast
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT/'tools/publicar_resultado_analista.py'
ast.parse(SCRIPT.read_text(encoding='utf-8'),feature_version=(3,6))
loader = importlib.util.spec_from_file_location('release',str(SCRIPT))
release = importlib.util.module_from_spec(loader)
loader.loader.exec_module(release)
spec = json.loads(SCRIPT.with_name('resultado_analista_release.json').read_text(encoding='utf-8'))
if Path('C:/xampp/php/php.exe').exists():
    os.environ['PATH'] = 'C:/xampp/php'+os.pathsep+os.environ['PATH']
sources = {}
for row in spec['files']:
    if row['new_file']: continue
    sources[row['path']] = subprocess.check_output(['git','show','0caac61697e0349dbb9dd32a23c55550db3b0645:'+row['path']],cwd=str(ROOT)).replace(b'\r\n',b'\n')
variants = [('repositorio',sources)]
live = ROOT/'tmp/analista-resultados-live'
if (live/'controller-completo.php').exists():
    installed = dict(sources)
    for name in sources:
        filename = live/'controller-completo.php' if name.endswith('SeguimientoVinculacionController.php') else live/name
        installed[name] = filename.read_bytes().replace(b'\r\n',b'\n')
    variants.append(('instalado',installed))

for label, files in variants:
    for newline in [b'\n',b'\r\n']:
        with tempfile.TemporaryDirectory() as tmp:
            site,backups = Path(tmp)/'public_html',Path(tmp)/'private'
            site.mkdir(); (site/'index.php').write_text('<?php',encoding='utf-8')
            (site/'prueba_telefonia/api').mkdir(parents=True)
            before = {}
            for name,data in files.items():
                p = site/name; p.parent.mkdir(parents=True,exist_ok=True)
                before[name] = (data+b'\n// Personalizacion institucional conservada.\n').replace(b'\n',newline)
                p.write_bytes(before[name])
            command = [sys.executable,str(SCRIPT),'--site',str(site),'--backup-root',str(backups)]

            def run(*args,ok=True):
                result = subprocess.run(command+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                assert (result.returncode==0)==ok,(result.stdout,result.stderr)
                return (result.stdout+result.stderr).decode('utf-8',errors='replace')

            assert 'COMPROBACION_OK: 5' in run('--check')
            assert not backups.exists()
            for name,data in before.items(): assert (site/name).read_bytes()==data
            assert 'PUBLICACION_OK: 5' in run('--apply')
            for name in before:
                updated=(site/name).read_bytes()
                assert b'Personalizacion institucional conservada.' in updated
                assert (b'\r\n' in updated)==(newline==b'\r\n')
            assert 'SIN_CAMBIOS' in run('--apply')
            backup=next(backups.iterdir())
            assert 'RESTAURACION_OK' in run('--restore',str(backup))
            for name,data in before.items(): assert (site/name).read_bytes()==data
            for row in spec['files']:
                if row['new_file']: assert not (site/row['path']).exists()
            # Un bloque cambiado impide publicar TODO el lote, incluidos archivos nuevos.
            target=site/'public/javascript/seguimiento_llamada_zadarma.js'
            row=next(row for row in spec['files'] if row['path'].endswith('.js'))
            old=target.read_bytes(); first=row['hunks'][-1]['before'].encode().replace(b'\n',newline)
            assert old.count(first)==1
            target.write_bytes(old.replace(first,b'// bloque modificado\n',1))
            conflict=target.read_bytes()
            assert 'BLOQUEADO' in run('--apply',ok=False)
            assert target.read_bytes()==conflict
            for row in spec['files']:
                if row['new_file']: assert not (site/row['path']).exists()
            assert len(list(backups.iterdir()))==1
        print('PASS: '+label+' '+repr(newline)+'; sintaxis PHP/JS, lectura sin cambios, lote selectivo, idempotencia, respaldo/restauracion y conflicto sin publicar.')
