#!/usr/bin/env python3
"""Publicación simulada sin tocar Hostinger y conservando personalizaciones."""
import os,subprocess,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
installer=root/'tools/publicar_disponibilidad_sobria_hostinger.sh'
file='public/css/telefonia_disponibilidad.css'
base='232924d000e2690fc205546d98e7b1c9cc1a0b8c'
target='8c73a0fc8800b138cde354e806611768332d36a0'
def content(rev):
 return subprocess.check_output(['git','show',rev+':'+file],cwd=root)
with tempfile.TemporaryDirectory(prefix='disp-sobria-') as scratch:
 tmp=Path(scratch);mirror=tmp/'mirror';mirror.mkdir()
 (mirror/'base.css').write_bytes(content(base))
 (mirror/'target.css').write_bytes(content(target))
 live=tmp/'public_html';live.mkdir()
 (live/'index.php').write_text('<?php')
 css=live/file;css.parent.mkdir(parents=True,exist_ok=True)
 original=content(base)+b'\n/* Personalizacion: regla existente Hostinger */\n'
 css.write_bytes(original)
 backups=tmp/'respaldos'
 env=dict(os.environ,IMPE_UI_DISP_MIRROR=str(mirror),IMPE_UI_DISP_LIVE=str(live),IMPE_UI_DISP_BACKUPS=str(backups))
 def run(*args,success=True):
  p=subprocess.run(['bash',str(installer),*args],cwd=root,env=env,capture_output=True,text=True)
  assert (p.returncode==0)==success,(args,p.stdout,p.stderr)
  return p.stdout+p.stderr
 assert 'SITIO INTACTO' in run('--check')
 assert css.read_bytes()==original and not backups.exists()
 assert 'publicado' in run('--apply')
 installed=css.read_bytes()
 assert installed.endswith(content(target)[len(content(base)):])
 assert b'Personalizacion: regla existente Hostinger' in installed
 assert 'YA ACTUALIZADO' in run('--check')
 assert 'ya instalados' in run('--apply')
 saved=list(backups.iterdir())
 assert len(saved)==1
 assert 'restaurado' in run('--restore',str(saved[0]))
 assert css.read_bytes()==original
 assert 'CONFLICTO' in run('--restore',str(saved[0]),success=False)
 css.write_text('/* CSS distinto de Hostinger, no aplicar */')
 assert 'CONFLICTO' in run('--check',success=False)
 assert css.read_text()=='/* CSS distinto de Hostinger, no aplicar */'
print('Despliegue CSS: check, respaldo, personalizaciones, idempotencia, rollback y conflicto OK')
