"""Compatibilidad y reversión sobre copias temporales: sin SQL ni sitio real."""
import base64,gzip,hashlib,json,os,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/analista_proxima_accion_release.json').read_text(encoding='utf8'))
def unpack(s):return None if s is None else gzip.decompress(base64.b64decode(s))
for row in spec['files']:
 assert unpack(row['variants'][0]['target'])==(R/row['path']).read_bytes().replace(b'\r\n',b'\n')
 for v in row['variants']:
  if v['base']:
   before=unpack(v['base']);after=unpack(v['target'])
   assert hashlib.sha256(before).hexdigest()==v['before']
   # Ni una condición territorial se pierde en la variante personalizada.
   for method in [b'analista(',b'lectura(',b'supervisor(']:
    marker=b'SeguimientoTerritorialScope::'+method
    assert before.count(marker)==after.count(marker),(row['path'],marker)
for variant in [0,1]:
 with tempfile.TemporaryDirectory(prefix='impe-proxima-publicacion-') as folder:
  root=pathlib.Path(folder);site=root/'site';site.mkdir();(site/'index.php').write_text('<?php // fixture')
  backup=root/'private';originals={};targets={}
  for row in spec['files']:
   v=row['variants'][min(variant,len(row['variants'])-1)];p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
   originals[row['path']]=unpack(v['base']);targets[row['path']]=unpack(v['target'])
   if originals[row['path']] is not None:p.write_bytes(originals[row['path']])
  def snapshot():return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
  def run(*args,**kw):
   r=subprocess.run([sys.executable,str(R/'tools/publicar_analista_proxima_accion.py'),'--site',str(site),'--backup-root',str(backup)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
   assert (r.returncode==0)==kw.get('ok',True),r.stdout+r.stderr
  before=snapshot();run('--check');assert snapshot()==before and not backup.exists()
  p=site/'app/services/InteraccionRutaService.php';p.write_bytes(p.read_bytes()+b'// desconocida')
  modified=snapshot();run('--apply',ok=False);assert snapshot()==modified and not backup.exists()
  p.write_bytes(originals['app/services/InteraccionRutaService.php']);run('--apply')
  assert all((site/p).read_bytes()==b for p,b in targets.items())
  if os.name=='posix':assert (site/'public/javascript/seguimiento_interacciones.js').stat().st_mode&0o777==0o644
  saved=next(p for p in backup.iterdir() if p.is_dir());run('--apply');assert len(list(backup.iterdir()))==1
  p.write_bytes(p.read_bytes()+b'// posterior');run('--restore',str(saved),ok=False)
  p.write_bytes(targets['app/services/InteraccionRutaService.php']);run('--restore',str(saved));assert snapshot()==before
print('PASS publicación: seis archivos, variantes exactas con territorio intacto, lint PHP, check sin escrituras, idempotencia, 644 y reversión; Python '+sys.version.split()[0])
