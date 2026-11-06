"""Publicación reversible de cinco assets, sin SQL ni acceso al sitio real."""
import base64,gzip,hashlib,json,os,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
package=json.loads((root/'tools/formularios_revision_envio_release.json').read_text(encoding='utf-8'))
base=json.loads((root/'tests/fixtures/formularios_revision_hostinger.json').read_text(encoding='utf-8'))
sha=lambda b:hashlib.sha256(b).hexdigest()
assert len(package['files'])==5 and not package['dependencies']
with tempfile.TemporaryDirectory(prefix='impe-revision-release-') as temp:
 site=Path(temp)/'public_html';backup=Path(temp)/'backup';site.mkdir();(site/'index.php').write_text('<?php // replica')
 originals={}
 for row in package['files']:
  name=row['path'];v=row['variants'][0]
  assert name.startswith('public/') and not name.endswith('.php')
  target=gzip.decompress(base64.b64decode(v['target']));assert sha(target)==v['after']
  assert target==(root/name).read_bytes().replace(b'\r\n',b'\n'),'regenerar manifiesto: '+name
  if v['before'] is not None:
   data=gzip.decompress(base64.b64decode(base[name]['content']));assert sha(data)==v['before']
   path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data);os.chmod(str(path),0o600);originals[name]=data
 def snapshot():return {str(p.relative_to(site)):p.read_bytes() for p in site.rglob('*') if p.is_file()}
 initial=snapshot()
 def run(*args,**kwargs):
  p=subprocess.run([sys.executable,str(root/'tools/publicar_formularios_revision_envio.py'),'--site',str(site),'--backup-root',str(backup)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  out=(p.stdout+p.stderr).decode('utf-8','replace');assert (p.returncode==0)==kwargs.get('ok',True),out
  return out
 assert 'CHECK OK' in run('--check');assert snapshot()==initial and not backup.exists()
 assert 'APLICADO' in run('--apply')
 for row in package['files']:
  p=site/row['path'];assert sha(p.read_bytes())==row['variants'][0]['after']
  if os.name=='posix':assert p.stat().st_mode&0o777==0o644
 assert 'YA INSTALADO' in run('--apply')
 # Existing installations of PR226 only need the CSS refinement. Preserve JS.
 css='public/css/formularios_revision_envio.css'
 old=gzip.decompress(base64.b64decode(base[css]['content']))
 (site/css).write_bytes(old)
 js={str(p.relative_to(site)):p.read_bytes() for p in (site/'public/javascript').glob('*.js')}
 assert 'CHECK OK' in run('--check');assert 'APLICADO' in run('--apply')
 assert js=={str(p.relative_to(site)):p.read_bytes() for p in (site/'public/javascript').glob('*.js')}
 # Restore the original full installation, after rolling back the one-file patch.
 patches=[p for p in backup.iterdir() if p.is_dir() and len(json.loads((p/'respaldo.json').read_text())['files'])==1]
 assert len(patches)==1
 assert 'RESTAURADO' in run('--restore',str(patches[0]));assert (site/css).read_bytes()==old
 (site/css).write_bytes(gzip.decompress(base64.b64decode(next(r for r in package['files'] if r['path']==css)['variants'][0]['target'])))
 saved=next(p for p in backup.iterdir() if p.is_dir() and len(json.loads((p/'respaldo.json').read_text())['files'])==5);assert 'RESTAURADO' in run('--restore',str(saved));assert snapshot()==initial
 p=site/'public/javascript/formularios_registro.js';p.write_bytes(p.read_bytes()+b'\n// personalizacion')
 current=snapshot();assert 'DIFERENTE' in run('--check',ok=False);assert snapshot()==current
 print('PASS: cinco archivos públicos exactos, check sin escrituras, apply, idempotencia, permisos 644, restore y bloqueo de variantes; sin SQL.')
