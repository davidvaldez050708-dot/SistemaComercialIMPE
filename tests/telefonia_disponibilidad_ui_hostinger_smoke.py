#!/usr/bin/env python3
"""Publicador Hostinger, ejecutado solo sobre carpetas temporales aisladas."""
from pathlib import Path
import os
import subprocess
import tempfile

root=Path(__file__).resolve().parents[1]
publisher=root/'tools/publicar_disponibilidad_ui_hostinger.sh'
base='dc27b76fcebcb35e36b50817a5b1b956bb502688'
target='582f2b0e18fe8c7471babcd64a85ec2f066e3afd'
mapping={
 'js':'public/javascript/telefonia_disponibilidad.js',
 'css':'public/css/telefonia_disponibilidad.css'
}
def blob(sha,name):
 return subprocess.check_output(['git','show',sha+':'+name],cwd=root)

with tempfile.TemporaryDirectory(prefix='impe-disponibilidad-ui-') as dirname:
 tmp=Path(dirname);mirror=tmp/'mirror';mirror.mkdir()
 live=tmp/'public_html';live.mkdir()
 backups=tmp/'backups'
 (live/'index.php').write_text('<?php')
 originals={}
 for tag,name in mapping.items():
  (mirror/('base-'+tag)).write_bytes(blob(base,name))
  (mirror/('target-'+tag)).write_bytes(blob(target,name))
  dest=live/name;dest.parent.mkdir(parents=True,exist_ok=True)
  original=blob(base,name)+b'\n/* HOSTINGER_PERSONALIZADO */\n'
  dest.write_bytes(original);originals[name]=original
 env=dict(os.environ,IMPE_AVAILABILITY_LIVE=str(live),
          IMPE_AVAILABILITY_BACKUPS=str(backups),
          IMPE_AVAILABILITY_MIRROR_DIR=str(mirror))
 def run(*args,success=True):
  cmd=subprocess.run(['bash',str(publisher),*args],cwd=root,env=env,capture_output=True,text=True)
  assert (cmd.returncode==0)==success,(args,cmd.stdout,cmd.stderr)
  return cmd.stdout+cmd.stderr
 assert 'SITIO INTACTO' in run('--check')
 assert not backups.exists(),'--check no crea respaldos'
 for name,original in originals.items():
  assert (live/name).read_bytes()==original
 assert 'OK: --apply' in run('--apply')
 for tag,name in mapping.items():
  changed=(live/name).read_bytes()
  assert b'HOSTINGER_PERSONALIZADO' in changed
  assert blob(target,name) in changed
 assert '0 archivo(s) por publicar' in run('--check')
 assert 'interfaz ya instalada' in run('--apply')
 saved=list(backups.iterdir())
 assert len(saved)==1
 assert 'respaldo restaurado' in run('--restore',str(saved[0]))
 for name,original in originals.items():
  assert (live/name).read_bytes()==original
 run('--restore',str(saved[0]),success=False)
 path=live/mapping['js']
 path.write_bytes(b'// variante desconocida\n')
 assert 'CONFLICTO' in run('--check',success=False)
 assert path.read_bytes()==b'// variante desconocida\n'
print('UI disponibilidad: preparación, personalizaciones, respaldo, idempotencia, restauración y conflicto: OK')
