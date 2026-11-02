"""Verifica el paquete exclusivamente en carpetas temporales, sin conexión externa."""
import base64,gzip,hashlib,json,os,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/microfonos_piloto_release.json').read_text(encoding='utf8'))
unpack=lambda s:gzip.decompress(base64.b64decode(s)) if s is not None else None
norm=lambda b:b.replace(b'\r\n',b'\n')
sha=lambda b:hashlib.sha256(b).hexdigest()
hostrow=spec['files'][-1]['variants'][0]
old,new=(unpack(hostrow[k]).decode() for k in ('before','after'))
a=new.index('    // Piloto de entrada:');b=new.index('    const fetchWebRtcSession',a)
assert new[:a]+new[b:]==old,'Sólo añadir el hook, conservar el runtime completo (mute, SDK, transferencia y resultados)'
assert sha(unpack(spec['files'][-2]['variants'][0]['before']))=='169372bcffee66f2a1f7adb77a6cfd1f8bf0fbe03c3df3f67dea602fa577cab6'
assert sha(unpack(hostrow['before']))=='d313d210070a9c9bb8c6f5560916974a36512eecc7367d27ea317c2a70b4dfa8'
assert spec['enabled_by_default'] is False
for crlf in (False,True):
 with tempfile.TemporaryDirectory(prefix='impe-mic-package-') as folder:
  root=pathlib.Path(folder);site=root/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
  originals={};after={};protected={}
  for row in spec['files']:
   p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);v=row['variants'][0]
   old=unpack(v['before']);new=unpack(v['after']);assert sha(new)==v['after_sha']
   assert norm((R/row['path']).read_bytes())==new
   originals[row['path']]=old.replace(b'\n',b'\r\n') if old is not None and crlf else old
   after[row['path']]=new.replace(b'\n',b'\r\n') if old is not None and crlf else new
   if old is not None:p.write_bytes(originals[row['path']])
  for dep in spec['dependencies']:
   data=(R/dep['path']).read_bytes();assert sha(norm(data))==dep['sha']
   p=site/dep['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(data);protected[dep['path']]=data
  for path in ['app/views/telefonia/shell.php','public/javascript/telefonia_shell.js','config/telefonia_audio.local.php','storage/grabaciones/audio.ogg','config/db_connection.php']:
   p=site/path;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'conservar');protected[path]=p.read_bytes()
  backups=root/'backups';cmd=[sys.executable,str(R/'tools/publicar_microfonos_piloto.py'),'--site',str(site),'--backup-root',str(backups)]
  def run(*args,ok=True):
   r=subprocess.run(cmd+list(args),capture_output=True,text=True,encoding='utf8')
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   assert all((site/p).read_bytes()==data for p,data in protected.items())
   return r.stdout
  def untouched():
   for path,data in originals.items():
    p=site/path;assert (not p.exists()) if data is None else p.read_bytes()==data
  run('--check');untouched();assert not backups.exists()
  target=site/spec['files'][-1]['path'];previous=target.read_bytes();target.write_bytes(previous+b'\n/* personalizado */')
  run('--apply',ok=False);target.write_bytes(previous);untouched();assert not backups.exists()
  run('--apply');assert all((site/p).read_bytes()==data for p,data in after.items())
  backup=next(backups.iterdir());run('--apply');assert len(list(backups.iterdir()))==1
  assert run('--check').count('ACTUALIZADO:')==5
  target.write_bytes(after[spec['files'][-1]['path']]+b'\n/* posterior */')
  run('--restore',str(backup),ok=False);target.write_bytes(after[spec['files'][-1]['path']])
  run('--restore',str(backup));untouched();run('--restore',str(backup));untouched()
print('PASS paquete piloto: cinco archivos exactos, runtime previo intacto, LF/CRLF, check sin escrituras, conflictos, idempotencia y restauración. Configuración privada, SQL, shell y audio preservados.')

# Actualización desde la versión e511b05c que el usuario ya instaló: sólo cuatro
# archivos deben cambiar. El hook y el motor del host permanecen byte por byte.
with tempfile.TemporaryDirectory(prefix='impe-mic-upgrade-') as folder:
 root=pathlib.Path(folder);site=root/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
 before={};after={}
 for row in spec['files']:
  v=row['variants'][-1]
  old=unpack(v['before']) if len(row['variants'])>1 else unpack(v['after'])
  p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(old)
  before[row['path']]=old;after[row['path']]=unpack(v['after'])
 for dep in spec['dependencies']:
  p=site/dep['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((R/dep['path']).read_bytes())
 private=site/'config/telefonia_audio.local.php';private.write_bytes(b'<?php return ["enabled"=>true];')
 backups=root/'backups';cmd=[sys.executable,str(R/'tools/publicar_microfonos_piloto.py'),'--site',str(site),'--backup-root',str(backups)]
 def update(*args):
  r=subprocess.run(cmd+list(args),capture_output=True,text=True,encoding='utf8');assert r.returncode==0,r.stdout+r.stderr
  assert private.read_bytes()==b'<?php return ["enabled"=>true];';return r.stdout
 assert update('--check').count('COMPATIBLE:')==4
 assert not backups.exists()
 update('--apply');assert all((site/p).read_bytes()==b for p,b in after.items())
 backup=next(backups.iterdir());record=json.loads((backup/'manifest.json').read_text())
 assert len(record['files'])==4
 assert not any(f['path']=='public/javascript/telefonia_host.js' for f in record['files'])
 update('--restore',str(backup));assert all((site/p).read_bytes()==b for p,b in before.items())
print('PASS actualización e511b05c: cuatro archivos, configuración habilitada intacta, host.js sin escritura y restore exacto al piloto anterior.')
