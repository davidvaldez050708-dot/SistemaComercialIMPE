#!/usr/bin/env python3
"""Ensayo de publicación selectiva con archivos personalizados de Hostinger."""
from pathlib import Path
import ast
import os
import subprocess
import tempfile

root=Path(__file__).resolve().parents[1]
publisher=root/'tools/publicar_prospecto_ventas_hostinger.py'

# El hosting ejecuta Python 3.6: un runner moderno puede ocultar
# argumentos introducidos en Python 3.7 y regresiones de sintaxis.
source=publisher.read_text(encoding='utf-8')
tree=ast.parse(source, feature_version=(3, 6))
for call in (node for node in ast.walk(tree) if isinstance(node, ast.Call)):
 if isinstance(call.func,ast.Attribute) and call.func.attr=='run' and (
  isinstance(call.func.value,ast.Name) and call.func.value.id=='subprocess'):
  unsupported={keyword.arg for keyword in call.keywords} & {'capture_output','text'}
  assert not unsupported,'subprocess.run requiere Python 3.7+: '+str(unsupported)
files={
 'js':'public/javascript/telefonia_enrutamiento.js',
 'php':'app/services/TelefoniaDisponibilidadService.php',
}
base='bd562a04b46814dcada148195f5793630e95f393'
target='2c8f51aaef007d7b4b20dd7008811a554bb19b9f'

def source(sha,path):
 return subprocess.check_output(['git','show',sha+':'+path],cwd=root)

with tempfile.TemporaryDirectory(prefix='impe-ventas-prospecto-') as scratch:
 root_tmp=Path(scratch)
 live=root_tmp/'public_html'
 backups=root_tmp/'respaldos'
 mirror=root_tmp/'mirror'
 live.mkdir()
 mirror.mkdir()
 (live/'index.php').write_text('<?php // test')
 originals={}
 for tag,path in files.items():
  (mirror/('base-'+tag)).write_bytes(source(base,path))
  (mirror/('target-'+tag)).write_bytes(source(target,path))
  dest=live/path
  dest.parent.mkdir(parents=True,exist_ok=True)
  custom=b'\n/* PERSONALIZACION_HOSTINGER_INTACTA */\n'
  contents=source(base,path)+custom
  dest.write_bytes(contents)
  originals[path]=contents
 env=dict(os.environ,IMPE_PROSPECTO_LIVE=str(live),IMPE_PROSPECTO_BACKUPS=str(backups),IMPE_PROSPECTO_MIRROR=str(mirror))
 def check(*args,success=True):
  r=subprocess.run(['python3',str(publisher),*args],cwd=root,env=env,capture_output=True,text=True)
  assert (r.returncode==0)==success,(args,r.stdout,r.stderr)
  return r.stdout+r.stderr
 assert 'SITIO INTACTO' in check('--check')
 assert not backups.exists()
 for path,content in originals.items():assert (live/path).read_bytes()==content
 assert 'OK: publicado' in check('--apply')
 for tag,path in files.items():
  content=(live/path).read_bytes()
  assert b'PERSONALIZACION_HOSTINGER_INTACTA' in content
  assert source(target,path) in content
 assert '0 archivos por publicar' in check('--check')
 assert 'ya instalado' in check('--apply')
 saved=list(backups.iterdir())
 assert len(saved)==1
 assert 'restaurados' in check('--restore',str(saved[0]))
 for path,content in originals.items():assert (live/path).read_bytes()==content
 assert 'CONFLICTO' in check('--restore',str(saved[0]),success=False)
 (live/files['js']).write_text('// JS incompatible de Hostinger')
 assert 'CONFLICTO' in check('--check',success=False)
 assert (live/files['js']).read_text()=='// JS incompatible de Hostinger'
print('Ventas: --check, --apply, personalizaciones, idempotencia, rollback y conflicto OK')
