"""Una sola hoja de estilos en un sitio temporal; no conecta con servicios."""
import base64,gzip,hashlib,json,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/compatibilidad_shell_release.json').read_text(encoding='utf8'))
assert len(spec['files'])==1 and spec['files'][0]['path']=='public/css/telefonia_shell.css' and spec['dependencies']==[]
unpack=lambda b:gzip.decompress(base64.b64decode(b))
row=spec['files'][0];v=row['variants'][0];old=unpack(v['before']);new=unpack(v['after'])
assert hashlib.sha256(old).hexdigest()==v['before_sha']=='dbd54f7a6386a785974de30fae99a12195c84d5f326de589468af6e035bddc27'
assert hashlib.sha256(new).hexdigest()==v['after_sha']
assert (R/row['path']).read_bytes().replace(b'\r\n',b'\n')==new
assert new.startswith(old),'Conservar literalmente todos los estilos publicados antes del bloque agregado'
assert new.count(b'IMPE_SHELL_VIEWPORT_COMPAT_V1')==1
for crlf in (False,True):
 with tempfile.TemporaryDirectory(prefix='shell-compat-test-') as folder:
  private=pathlib.Path(folder);site=private/'public_html';site.mkdir();(site/'index.php').write_bytes(b'<?php')
  before=old.replace(b'\n',b'\r\n') if crlf else old
  after=new.replace(b'\n',b'\r\n') if crlf else new
  target=site/row['path'];target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(before)
  protected={}
  for name in ['app/views/telefonia/shell.php','public/javascript/telefonia_shell.js','public/javascript/telefonia_host.js','public/javascript/telefonia_entrantes_bridge.js','public/css/global.css','public/css/responsive_sistema.css','storage/grabaciones/test.ogg','config/db_connection.php']:
   p=site/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b'personalizacion que debe conservarse');protected[name]=p.read_bytes()
  backups=private/'respaldos';command=[sys.executable,str(R/'tools/publicar_compatibilidad_shell.py'),'--site',str(site),'--backup-root',str(backups)]
  def run(*args,ok=True):
   r=subprocess.run(command+list(args),capture_output=True,text=True,encoding='utf8')
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   assert all((site/p).read_bytes()==b for p,b in protected.items())
   return r.stdout
  run('--check');assert target.read_bytes()==before;assert not backups.exists()
  target.write_bytes(before+b'\n/* personalizacion posterior */');run('--apply',ok=False)
  assert target.read_bytes()==before+b'\n/* personalizacion posterior */';assert not backups.exists();target.write_bytes(before)
  run('--apply');assert target.read_bytes()==after
  backup=next(backups.iterdir());assert run('--check').count('ACTUALIZADO:')==1
  run('--apply');assert len(list(backups.iterdir()))==1
  target.write_bytes(after+b'\n/* cambio posterior */');run('--restore',str(backup),ok=False);target.write_bytes(after)
  run('--restore',str(backup));assert target.read_bytes()==before
  run('--restore',str(backup));assert target.read_bytes()==before
print('PASS publicación: solo CSS, estilos anteriores idénticos, LF/CRLF, check sin escrituras, bloqueo de versiones desconocidas, respaldo/restauración e idempotencia; PHP, JS, datos y otras hojas conservados.')
