"""Replica desechable, sin configuración, SQL ni correo."""
import base64,gzip,hashlib,json,os,subprocess,sys,tempfile
from pathlib import Path
R=Path(__file__).resolve().parents[1]
P=R/'tools/publicar_reunion_ecard.py';M=R/'tools/reunion_ecard_release.json'
spec=json.loads(M.read_text(encoding='utf8'))
def sha(b):return hashlib.sha256(b).hexdigest()
def git(p):return subprocess.check_output(['git','show','6d0b5faa:'+p],cwd=R)
with tempfile.TemporaryDirectory(prefix='impe-test-reunion-ecard-') as d:
 root=Path(d);site=root/'site';site.mkdir();(site/'index.php').write_text('<?php // fixture')
 for row in spec['files']:
  p=row['path'];v=row['variants'][0]
  target=gzip.decompress(base64.b64decode(v['target']))
  assert target==(R/p).read_bytes().replace(b'\r\n',b'\n')
  if v['before'] is not None:
   before=git(p);assert sha(before)==v['before']
   dest=site/p;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(before)
 for p,h in spec['dependencies'].items():
  b=git(p);assert sha(b)==h;dest=site/p;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(b)
 def run(*args,ok=True):
  r=subprocess.run([sys.executable,str(P),'--site',str(site),'--backup-root',str(root/'backup')]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=dict(os.environ,PYTHONIOENCODING='utf8'))
  assert (r.returncode==0)==ok,(r.stdout,r.stderr)
  return (r.stdout+r.stderr).decode('utf8')
 def snapshot():return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
 initial=snapshot();assert 'CHECK OK' in run('--check');assert snapshot()==initial and not (root/'backup').exists()
 for p in [spec['files'][1]['path'],next(iter(spec['dependencies']))]:
  dest=site/p;b=dest.read_bytes();dest.write_bytes(b+b'\n// variante local');snap=snapshot()
  run('--apply',ok=False);assert snapshot()==snap and not (root/'backup').exists();dest.write_bytes(b)
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
print('PASS: paquete selectivo, dependencias, integridad, nuevas rutas, versiones desconocidas, respaldo, idempotencia y restauración; Python '+sys.version.split()[0])
