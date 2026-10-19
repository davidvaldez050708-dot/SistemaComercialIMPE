#!/usr/bin/env python3
"""Publicación segura del sonido en copia aislada, con variante personalizada."""
from pathlib import Path
import os,subprocess,tempfile

root=Path(__file__).resolve().parents[1]
script=root/'tools/publicar_sonido_disponibilidad_hostinger.sh'
name='public/javascript/telefonia_disponibilidad.js'
base='042b89e65711ed8dcf9577a0f8163a86669c86c4'
target='554b1f566886b1e30c775728b4a399f00fb6d766'
def blob(ref):
 return subprocess.check_output(['git','show',ref+':'+name],cwd=root)
with tempfile.TemporaryDirectory(prefix='aviso-disponibilidad-hostinger-') as dir:
 work=Path(dir)
 mirror=work/'mirror';mirror.mkdir()
 (mirror/'base.js').write_bytes(blob(base))
 (mirror/'target.js').write_bytes(blob(target))
 live=work/'public_html';live.mkdir()
 (live/'index.php').write_text('<?php')
 path=live/name;path.parent.mkdir(parents=True,exist_ok=True)
 original=blob(base)+b'\n// CUSTOMIZACION_HOSTINGER_PRESERVADA\n'
 path.write_bytes(original)
 backup=work/'respaldos'
 env=dict(os.environ,IMPE_SOUND_MIRROR=str(mirror),IMPE_SOUND_LIVE=str(live),IMPE_SOUND_BACKUPS=str(backup))
 def run(*arguments,success=True):
  p=subprocess.run(['bash',str(script),*arguments],cwd=root,env=env,capture_output=True,text=True)
  assert (p.returncode==0)==success,(arguments,p.stdout,p.stderr)
  return p.stdout+p.stderr
 assert 'SITIO INTACTO' in run('--check')
 assert path.read_bytes()==original and not backup.exists(),'check no toca producción'
 assert 'OK: --apply' in run('--apply')
 content=path.read_bytes()
 assert blob(target) in content and b'CUSTOMIZACION_HOSTINGER_PRESERVADA' in content
 assert 'Ya publicado.' in run('--check')
 assert 'ya instalado' in run('--apply')
 saved=list(backup.iterdir())
 assert len(saved)==1
 assert 'restaurado' in run('--restore',str(saved[0]))
 assert path.read_bytes()==original
 assert 'CONFLICTO' in run('--restore',str(saved[0]),success=False)
 path.write_bytes(b'// JS Hostinger desconocido\n')
 assert 'CONFLICTO' in run('--check',success=False)
 assert path.read_bytes()==b'// JS Hostinger desconocido\n'
print('Publicación sonido: check, apply, customizaciones, idempotencia, rollback y conflicto OK')
