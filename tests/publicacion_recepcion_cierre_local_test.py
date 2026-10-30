"""Publicación en un sitio temporal: sin DB, Zadarma ni llamadas."""
import base64,gzip,hashlib,json,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/recepcion_cierre_local_release.json').read_text(encoding='utf8'))
assert len(spec['files'])==2 and len(spec['dependencies'])==2
unpack=lambda b:gzip.decompress(base64.b64decode(b))
for crlf in (False,True):
 with tempfile.TemporaryDirectory(prefix='recepcion-cierre-test-') as folder:
  private=pathlib.Path(folder);site=private/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
  before={};after={}
  for row in spec['files']:
   v=row['variants'][0];old=unpack(v['before']);new=unpack(v['after'])
   assert hashlib.sha256(old).hexdigest()==v['before_sha']
   assert hashlib.sha256(new).hexdigest()==v['after_sha']
   assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==new
   before[row['path']]=old.replace(b'\n',b'\r\n') if crlf else old
   after[row['path']]=new.replace(b'\n',b'\r\n') if crlf else new
   p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(before[row['path']])
  protected={}
  for dependency in spec['dependencies']:
   data=unpack(dependency['content']);assert hashlib.sha256(data).hexdigest()==dependency['sha']
   assert (R/dependency['path']).read_bytes().replace(b'\r\n',b'\n')==data
   if crlf:data=data.replace(b'\n',b'\r\n')
   p=site/dependency['path'];p.write_bytes(data);protected[dependency['path']]=data
  for name in ['app/controllers/TelefoniaController.php','public/javascript/telefonia_ventas_shell.js','public/javascript/telefonia_ventas_resultados.js','public/css/telefonia_shell.css','storage/grabaciones/test.ogg','config/db_connection.php']:
   p=site/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'conservar exactamente');protected[name]=p.read_bytes()
  backups=private/'respaldos';command=[sys.executable,str(R/'tools/publicar_recepcion_cierre_local.py'),'--site',str(site),'--backup-root',str(backups)]
  def run(*args,ok=True):
   r=subprocess.run(command+list(args),capture_output=True,text=True,encoding='utf8')
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   assert all((site/p).read_bytes()==b for p,b in protected.items())
   return r.stdout
  def unchanged():assert all((site/p).read_bytes()==b for p,b in before.items())
  run('--check');unchanged();assert not backups.exists()
  target=site/spec['files'][-1]['path'];original=target.read_bytes();target.write_bytes(original+b'\n// personalizacion nueva')
  run('--apply',ok=False);target.write_bytes(original);unchanged();assert not backups.exists()
  dep=spec['dependencies'][0]['path'];p=site/dep;original_dep=protected[dep];p.write_bytes(original_dep+b'\n// dependencia cambiada');protected[dep]=p.read_bytes()
  run('--apply',ok=False);unchanged();assert not backups.exists();p.write_bytes(original_dep);protected[dep]=original_dep
  run('--apply');assert all((site/p).read_bytes()==b for p,b in after.items())
  backup=next(backups.iterdir());assert run('--check').count('ACTUALIZADO:')==2
  run('--apply');assert len(list(backups.iterdir()))==1
  current=target.read_bytes();target.write_bytes(current+b'\n// cambio posterior');run('--restore',str(backup),ok=False);target.write_bytes(current)
  run('--restore',str(backup));unchanged();run('--restore',str(backup));unchanged()
host=next(row for row in spec['files'] if row['path'].endswith('/telefonia_host.js'))['variants'][0]
old,new=(unpack(host[k]).decode() for k in ('before','after'))
for start,end in [('        consultEnded:', '    window.setInterval(()=>void transferFlow'),('    const legacyBusy =','    setInterval(function () {'),('        const ownTransferSdk=', '    const pollIncoming =')]:
 assert old[old.index(start):old.index(end,old.index(start))]==new[new.index(start):new.index(end,new.index(start))]
print('PASS publicación: dos archivos, dependencias comprobadas, LF/CRLF, conflictos sin escritura parcial, respaldo/restauración e idempotencia; shell, observador, transferencias, reserva, datos y grabaciones conservados.')
