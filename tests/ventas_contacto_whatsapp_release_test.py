"""Réplica exacta de Hostinger para la actualización visual. No conecta a BD."""
import base64,gzip,hashlib,json,os,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
package=json.loads((root/'tools/ventas_contacto_whatsapp_release.json').read_text(encoding='utf-8'))
base=json.loads((root/'tests/fixtures/ventas_contacto_whatsapp_hostinger.json').read_text(encoding='utf-8'))
sha=lambda b:hashlib.sha256(b).hexdigest()
assert len(package['files'])==4
with tempfile.TemporaryDirectory(prefix='impe-ventas-contacto-whatsapp-') as temp:
 site=Path(temp)/'public_html';backup=Path(temp)/'backup';site.mkdir();(site/'index.php').write_text('<?php // replica')
 for row in package['files']:
  name=row['path'];v=row['variants'][0];old=gzip.decompress(base64.b64decode(base[name]['content']))
  assert sha(old)==v['before'];target=gzip.decompress(base64.b64decode(v['target']));assert sha(target)==v['after']
  assert target==(root/name).read_bytes().replace(b'\r\n',b'\n'),'manifiesto desactualizado: '+name
  path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(old)
 for name,expected in package['dependencies'].items():
  data=gzip.decompress(base64.b64decode(base[name]['content']));assert sha(data)==expected
  path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
 def snapshot():return {str(p.relative_to(site)):p.read_bytes() for p in site.rglob('*') if p.is_file()}
 initial=snapshot()
 def run(*args,**kwargs):
  p=subprocess.run([sys.executable,str(root/'tools/publicar_ventas_contacto_whatsapp.py'),'--site',str(site),'--backup-root',str(backup)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  out=(p.stdout+p.stderr).decode('utf-8','replace');assert (p.returncode==0)==kwargs.get('ok',True),out
  return out
 assert 'CHECK OK' in run('--check');assert snapshot()==initial and not backup.exists()
 assert 'APLICADO' in run('--apply')
 for row in package['files']:
  p=site/row['path'];assert sha(p.read_bytes())==row['variants'][0]['after']
  if os.name=='posix' and row['path'].startswith('public/'):assert p.stat().st_mode&0o777==0o644
 for name in package['dependencies']:assert (site/name).read_bytes()==initial[str(Path(name))],'dependencia modificada'
 assert 'YA INSTALADO' in run('--apply')
 saved=next(p for p in backup.iterdir() if p.is_dir());assert 'RESTAURADO' in run('--restore',str(saved));assert snapshot()==initial
 p=site/'app/views/telefonia/resultado_ventas_form.php';p.write_bytes(p.read_bytes()+b'\n// personalizacion')
 current=snapshot();assert 'DIFERENTE' in run('--check',ok=False);assert snapshot()==current
 print('PASS: cuatro archivos Hostinger exactos, dependencias de reportes intactas, check sin escrituras, apply, idempotencia, restore y rechazo de variantes. Sin SQL.')
