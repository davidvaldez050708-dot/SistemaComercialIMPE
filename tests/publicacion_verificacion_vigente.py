import base64,gzip,json,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/verificacion_vigente_release.json').read_text())
for row in spec['files']:
 # Un corte posterior puede extender un archivo sin reescribir una publicación anterior completa.
 current=row
 newer=R/'tools/analista_salientes_release.json'
 if newer.exists():
  current=next((r for r in json.loads(newer.read_text(encoding='utf8'))['files'] if r['path']==row['path']),row)
 assert gzip.decompress(base64.b64decode(current['variants'][0]['target'])) == (R/row['path']).read_bytes().replace(b'\r\n',b'\n'), 'Paquete activo desactualizado: '+row['path']
for variant in [0,1]:
 with tempfile.TemporaryDirectory(prefix='impe-verificacion-vigente-') as folder:
  d=pathlib.Path(folder);site=d/'site';site.mkdir();(site/'index.php').write_text('<?php // fixture');backups=d/'backup'
  normalized=d/'release.json';normalized.write_bytes((R/'tools/verificacion_vigente_release.json').read_bytes().replace(b'\r\n',b'\n'))
  originals={};targets={}
  for row in spec['files']:
   v=row['variants'][min(variant,len(row['variants'])-1)];p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
   originals[row['path']]=gzip.decompress(base64.b64decode(v['base']));targets[row['path']]=gzip.decompress(base64.b64decode(v['target']));p.write_bytes(originals[row['path']])
  def snapshot():return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
  def run(*args,**kw):
   r=subprocess.run([sys.executable,str(R/'tools/publicar_verificacion_vigente.py'),'--site',str(site),'--backup-root',str(backups),'--manifest',str(normalized)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
   assert (r.returncode==0)==kw.get('ok',True),r.stdout+r.stderr
  before=snapshot();run('--check');assert snapshot()==before and not backups.exists()
  js=site/'public/javascript/seguimiento_interacciones.js';js.write_bytes(js.read_bytes()+b'// personalizacion');altered=snapshot();run('--apply',ok=False);assert snapshot()==altered
  js.write_bytes(originals['public/javascript/seguimiento_interacciones.js']);run('--apply')
  assert all((site/p).read_bytes()==b for p,b in targets.items())
  saved=next(x for x in backups.iterdir() if x.is_dir());run('--apply');assert len(list(backups.iterdir()))==1
  js.write_bytes(js.read_bytes()+b'// edicion posterior');run('--restore',str(saved),ok=False)
  js.write_bytes(targets['public/javascript/seguimiento_interacciones.js']);run('--restore',str(saved));assert snapshot()==before
print('PASS publicación: variantes base/Hostinger, permisos públicos, check sin cambios, bloqueo, idempotencia y reversión')
