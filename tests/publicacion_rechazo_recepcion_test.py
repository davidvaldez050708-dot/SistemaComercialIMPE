"""Sitio simulado sin conexión, llamadas ni acceso a la base de datos."""
import base64,gzip,hashlib,json,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/rechazo_recepcion_release.json').read_text(encoding='utf8'))
assert len(spec['files'])==4 and spec['dependencies']==[]
unpack=lambda b:gzip.decompress(base64.b64decode(b))
for crlf in (False,True):
 with tempfile.TemporaryDirectory(prefix='rechazo-test-') as folder:
  private=pathlib.Path(folder);site=private/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
  before={};after={}
  for row in spec['files']:
   v=row['variants'][0];old=unpack(v['before']);new=unpack(v['after'])
   assert hashlib.sha256(old).hexdigest()==v['before_sha']
   assert hashlib.sha256(new).hexdigest()==v['after_sha']
   assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==new
   before[row['path']]=old.replace(b'\n',b'\r\n') if crlf else old
   after[row['path']]=new.replace(b'\n',b'\r\n') if crlf else new
   target=site/row['path'];target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(before[row['path']])
  protected={}
  for name in ['app/controllers/TelefoniaController.php','public/javascript/telefonia_ventas_shell.js','public/javascript/telefonia_ventas_resultados.js','public/css/telefonia_shell.css','storage/grabaciones/test.ogg','config/db_connection.php']:
   p=site/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'conservar exactamente');protected[name]=p.read_bytes()
  backups=private/'respaldos';command=[sys.executable,str(R/'tools/publicar_rechazo_recepcion.py'),'--site',str(site),'--backup-root',str(backups)]
  def run(*args,ok=True):
   r=subprocess.run(command+list(args),capture_output=True,text=True,encoding='utf8')
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   assert all((site/p).read_bytes()==b for p,b in protected.items())
   return r.stdout
  def unchanged():assert all((site/p).read_bytes()==b for p,b in before.items())
  run('--check');unchanged();assert not backups.exists()
  target=site/spec['files'][-1]['path'];original=target.read_bytes();target.write_bytes(original+b'\n// personalizacion nueva')
  run('--apply',ok=False);target.write_bytes(original);unchanged();assert not backups.exists()
  run('--apply');assert all((site/p).read_bytes()==b for p,b in after.items())
  backup=next(backups.iterdir());assert run('--check').count('ACTUALIZADO:')==4
  run('--apply');assert len(list(backups.iterdir()))==1
  current=target.read_bytes();target.write_bytes(current+b'\n// cambio posterior');run('--restore',str(backup),ok=False);target.write_bytes(current)
  run('--restore',str(backup));unchanged();run('--restore',str(backup));unchanged()
# Las dos personalizaciones del motor (transferencias y reserva de transporte)
# deben ser literalmente idénticas a la versión de Hostinger conciliada.
host=next(row for row in spec['files'] if row['path'].endswith('/telefonia_host.js'))['variants'][0]
old,new=(unpack(host[k]).decode() for k in ('before','after'))
for start,end in [('        consultEnded:', '    window.setInterval(()=>void transferFlow'),('    const legacyBusy =','    setInterval(function () {'),('        const ownTransferSdk=', '    const pollIncoming =')]:
 assert old[old.index(start):old.index(end,old.index(start))]==new[new.index(start):new.index(end,new.index(start))]
print('PASS publicación: versiones exactas LF/CRLF, check sin escrituras, conflicto sin instalación parcial, backup/restauración exacta e idempotencia; transferencias, reserva, grabaciones y archivos ajenos conservados.')
