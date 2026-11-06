"""Réplica de archivos exactos de Hostinger; sin BD ni documentos reales."""
import base64,gzip,hashlib,json,os,subprocess,sys,tempfile,shutil
from pathlib import Path
R=Path(__file__).resolve().parents[1]
package=json.loads((R/'tools/reporte_usuarios_pdf_release.json').read_text(encoding='utf8'))
base=json.loads((R/'tests/fixtures/reporte_usuarios_pdf_hostinger.json').read_text(encoding='utf8'))
sha=lambda b:hashlib.sha256(b).hexdigest()
assert len(package['files'])==5
with tempfile.TemporaryDirectory(prefix='impe-users-release-') as d:
 site=Path(d)/'site';backup=Path(d)/'backup';site.mkdir();(site/'index.php').write_text('<?php')
 for name,item in base.items():
  if item['content'] is None:continue
  data=gzip.decompress(base64.b64decode(item['content']));assert sha(data)==item['sha256']
  path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
 for row in package['files']:
  v=row['variants'][0];data=gzip.decompress(base64.b64decode(v['target']))
  assert sha(data)==v['after'] and data==(R/row['path']).read_bytes().replace(b'\r\n',b'\n')
 template='storage/templates/reportes/Administrador/Plantilla Reporte Admin.docx'
 dest=site/template;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(R/template,dest)
 (site/'vendor').mkdir();autoload=Path(os.environ.get('IMPE_TEST_VENDOR',str(R/'vendor')))/'autoload.php'
 (site/'vendor/autoload.php').write_text('<?php require '+json.dumps(str(autoload).replace('\\','/'))+';',encoding='utf8')
 def snapshot():return {str(p.relative_to(site)):p.read_bytes() for p in site.rglob('*') if p.is_file()}
 initial=snapshot()
 def run(*args,**kwargs):
  p=subprocess.run([sys.executable,str(R/'tools/publicar_reporte_usuarios_pdf.py'),'--site',str(site),'--backup-root',str(backup)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  out=(p.stdout+p.stderr).decode('utf8','replace');assert (p.returncode==0)==kwargs.get('ok',True),out
  return out
 assert 'CHECK OK' in run('--check') and snapshot()==initial and not backup.exists()
 assert 'APLICADO' in run('--apply')
 for row in package['files']:
  p=site/row['path'];assert sha(p.read_bytes())==row['variants'][0]['after']
  if os.name=='posix':assert p.stat().st_mode&0o777==0o644
 for name in list(package['dependencies'])+[template,'vendor/autoload.php']:assert (site/name).read_bytes()==initial[str(Path(name))]
 assert 'YA INSTALADO' in run('--apply');folder=next(backup.iterdir())
 assert 'RESTAURADO' in run('--restore',str(folder)) and snapshot()==initial
 p=site/'app/controllers/ReporteAdministradorController.php';p.write_bytes(p.read_bytes()+b'\n// personalizacion')
 before=snapshot();assert 'DIFERENTE' in run('--check',ok=False) and snapshot()==before
 print('PASS: cinco archivos exactos, plantilla/dependencias intactas, check, respaldo, apply, idempotencia, restore y rechazo de variantes. Sin SQL.')
