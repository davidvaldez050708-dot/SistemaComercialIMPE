"""Paquete visual del teléfono; prueba en directorio temporal sin DB ni PBX."""
import base64,gzip,hashlib,json,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/telefono_flotante_compat_release.json').read_text(encoding='utf8'))
assert [r['path'] for r in spec['files']]==['public/css/telefonia_teclado.css','public/javascript/telefonia_teclado.js','public/css/telefonia_shell.css']
unpack=lambda s:gzip.decompress(base64.b64decode(s))
jsrow=next(r for r in spec['files'] if r['path'].endswith('.js'))['variants'][0]
oldjs,newjs=(unpack(jsrow[k]).decode() for k in ('before','after'))
for start,end in [('    function normalizar(', '    function create('),('        async function dial(', '        function sync('),('        function sync(', "        qs('[data-dialer-form]')"),('        async function transferCall(', "        qs('[data-dialer-transfer-direct]')")]:
 assert oldjs[oldjs.index(start):oldjs.index(end,oldjs.index(start))]==newjs[newjs.index(start):newjs.index(end,newjs.index(start))],start
for crlf in (False,True):
 with tempfile.TemporaryDirectory(prefix='telefono-compat-') as folder:
  private=pathlib.Path(folder);site=private/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
  before={};after={}
  for row in spec['files']:
   v=row['variants'][0];old=unpack(v['before']);new=unpack(v['after'])
   assert hashlib.sha256(old).hexdigest()==v['before_sha'];assert hashlib.sha256(new).hexdigest()==v['after_sha']
   assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==new
   if row['path'].endswith('.css'):assert new.startswith(old),'Preservar CSS instalado literalmente'
   before[row['path']]=old.replace(b'\n',b'\r\n') if crlf else old;after[row['path']]=new.replace(b'\n',b'\r\n') if crlf else new
   p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(before[row['path']])
  protected={}
  for name in ['public/javascript/telefonia_host.js','public/javascript/telefonia_shell.js','public/javascript/telefonia_entrantes_bridge.js','public/javascript/telefonia_sdk_entrantes_observador.js','public/javascript/telefonia_persistente.js','app/views/telefonia/shell.php','app/controllers/TelefoniaController.php','config/db_connection.php','storage/grabaciones/audio.ogg']:
   p=site/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'no modificar');protected[name]=p.read_bytes()
  backups=private/'backups';command=[sys.executable,str(R/'tools/publicar_telefono_flotante_compat.py'),'--site',str(site),'--backup-root',str(backups)]
  def run(*args,ok=True):
   r=subprocess.run(command+list(args),capture_output=True,text=True,encoding='utf8');assert (r.returncode==0)==ok,r.stdout+r.stderr
   assert all((site/p).read_bytes()==b for p,b in protected.items());return r.stdout
  def untouched():assert all((site/p).read_bytes()==b for p,b in before.items())
  run('--check');untouched();assert not backups.exists()
  target=site/spec['files'][-1]['path'];original=target.read_bytes();target.write_bytes(original+b'\n/* cambio local */')
  run('--apply',ok=False);target.write_bytes(original);untouched();assert not backups.exists()
  run('--apply');assert all((site/p).read_bytes()==b for p,b in after.items())
  backup=next(backups.iterdir());run('--apply');assert len(list(backups.iterdir()))==1;assert run('--check').count('ACTUALIZADO:')==3
  current=target.read_bytes();target.write_bytes(current+b'\n/* cambio posterior */');run('--restore',str(backup),ok=False);target.write_bytes(current)
  run('--restore',str(backup));untouched();run('--restore',str(backup));untouched()
print('PASS: tres archivos visuales exactos, CSS previo conservado, funciones de marcación/sync/transferencia idénticas, LF/CRLF, check sin escrituras, conflictos, idempotencia y respaldo/restauración. Motor y datos intactos.')
