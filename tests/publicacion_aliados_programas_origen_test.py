"""Compatibilidad y reversibilidad en un sitio temporal sin configuración ni SQL."""
import base64,gzip,hashlib,json,os,subprocess,sys,tempfile
from pathlib import Path
R=Path(__file__).resolve().parents[1]
P=R/'tools/publicar_aliados_programas_origen.py';M=R/'tools/aliados_programas_origen_release.json'
spec=json.loads(M.read_text(encoding='utf8'))
def sha(b):return hashlib.sha256(b).hexdigest()
def git(p):return subprocess.check_output(['git','show','d1a08f56:'+p],cwd=R)
with tempfile.TemporaryDirectory(prefix='impe-test-supervision-package-') as d:
 root=Path(d);site=root/'site';site.mkdir();(site/'index.php').write_text('<?php // fixture')
 originals={}
 for row in spec['files']:
  p=row['path'];before=git(p);assert sha(before)==row['variants'][0]['before']
  target=gzip.decompress(base64.b64decode(row['variants'][0]['target']))
  assert target==(R/p).read_bytes().replace(b'\r\n',b'\n')
  originals[p]=before;dest=site/p;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(before)
 for p,h in spec['dependencies'].items():
  b=git(p);assert sha(b)==h;dest=site/p;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(b)
 def run(*args,ok=True):
  r=subprocess.run([sys.executable,str(P),'--site',str(site),'--backup-root',str(root/'backup')]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=dict(os.environ,PYTHONIOENCODING="utf-8"))
  assert (r.returncode==0)==ok,(r.stdout,r.stderr)
  return (r.stdout+r.stderr).decode('utf8')
 def snapshot():return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
 initial=snapshot();assert 'CHECK OK' in run('--check');assert snapshot()==initial;assert not (root/'backup').exists()
 # Unknown version and unknown dependency must stop before any backup/write.
 for p in [spec['files'][0]['path'],spec['files'][0]['path']]:
  dest=site/p;b=dest.read_bytes();dest.write_bytes(b+b'\n// variante desconocida');snap=snapshot()
  run('--apply',ok=False);assert snapshot()==snap;assert not (root/'backup').exists();dest.write_bytes(b)
 tampered=root/'tampered.json';tampered.write_bytes(M.read_bytes()+b' ')
 run('--manifest',str(tampered),'--apply',ok=False);assert snapshot()==initial
 assert 'APLICADO' in run('--apply');installed=snapshot()
 for row in spec['files']:
  assert sha((site/row['path']).read_bytes())==row['variants'][0]['after']
  if os.name=='posix' and row['path'].startswith('public/'):assert (site/row['path']).stat().st_mode&0o777==0o644
 for p in spec['dependencies']:assert installed[p]==initial[p]
 assert 'YA INSTALADO' in run('--apply');assert snapshot()==installed
 backups=[p for p in (root/'backup').iterdir() if p.is_dir()];assert len(backups)==1
 p=site/spec['files'][0]['path'];b=p.read_bytes();p.write_bytes(b+b'\n// edicion posterior')
 run('--restore',str(backups[0]),ok=False);p.write_bytes(b)
 assert 'RESTAURADO' in run('--restore',str(backups[0]));assert snapshot()==initial
print('PASS paquete: check sin escrituras, un archivo, dependencias intactas, versiones desconocidas, integridad, idempotencia y restauración protegida; Python '+sys.version.split()[0])
