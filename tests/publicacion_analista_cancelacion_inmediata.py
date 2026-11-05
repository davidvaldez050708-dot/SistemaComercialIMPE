"""Publicación en directorios desechables. No usa producción ni una base SQL."""
import base64,gzip,hashlib,json,os,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/analista_cancelacion_inmediata_release.json').read_text(encoding='utf8'))
assert [r['path'] for r in spec['files']]==[
 'public/javascript/seguimiento_llamada_registro_obligatorio.js',
 'public/javascript/seguimiento_llamada_zadarma.js']
def unpack(v):return gzip.decompress(base64.b64decode(v))
for row in spec['files']:
 for v in row['variants']:
  assert unpack(v['target'])==(R/row['path']).read_bytes().replace(b'\r\n',b'\n')
  assert hashlib.sha256(unpack(v['base'])).hexdigest()==v['before']
for variant in [0,1]:
 with tempfile.TemporaryDirectory(prefix='impe-cancelacion-test-') as folder:
  root=pathlib.Path(folder);site=root/'site';site.mkdir();(site/'index.php').write_text('<?php // fixture')
  (site/'telefonia_intacta.txt').write_text('Transport y SDK intactos')
  backup=root/'private';originals={};targets={}
  for row in spec['files']:
   v=row['variants'][min(variant,len(row['variants'])-1)];p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
   originals[row['path']]=unpack(v['base']);targets[row['path']]=unpack(v['target']);p.write_bytes(originals[row['path']]);p.chmod(0o600)
  def snapshot():return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
  def run(*args,**kw):
   result=subprocess.run([sys.executable,str(R/'tools/publicar_analista_cancelacion_inmediata.py'),
    '--site',str(site),'--backup-root',str(backup)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
   assert (result.returncode==0)==kw.get('ok',True),result.stdout+result.stderr
  before=snapshot();run('--check');assert snapshot()==before and not backup.exists()
  js=site/spec['files'][1]['path'];js.write_bytes(js.read_bytes()+b'// variante desconocida')
  modified=snapshot();run('--apply',ok=False);assert snapshot()==modified and not backup.exists()
  js.write_bytes(originals[spec['files'][1]['path']]);run('--apply')
  assert all((site/p).read_bytes()==b for p,b in targets.items())
  assert (site/'telefonia_intacta.txt').read_text()=='Transport y SDK intactos'
  if os.name=='posix':assert all((site/p).stat().st_mode&0o777==0o644 for p in targets)
  saved=next(p for p in backup.iterdir() if p.is_dir());run('--apply');assert len(list(backup.iterdir()))==1
  js.write_bytes(js.read_bytes()+b'// cambio posterior');run('--restore',str(saved),ok=False)
  js.write_bytes(targets[spec['files'][1]['path']]);run('--restore',str(saved));assert snapshot()==before
print('PASS publicación: dos variantes, check sin escrituras, rechazo antes de respaldar, sólo dos JS, 644 POSIX, idempotencia y restauración protegida; Python '+sys.version.split()[0])
