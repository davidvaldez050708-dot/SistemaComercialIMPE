import base64,gzip,hashlib,json,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/analista_registro_obligatorio_release.json').read_text())
for row in spec['files']:
 archived=gzip.decompress(base64.b64decode(row['variants'][0]['target']))
 if row['path']=='public/javascript/seguimiento_llamada_registro_obligatorio.js':
  # Este publicador archivado conserva el corte #202. La UI actual se valida en
  # publicacion_analista_salientes.py; no reescribir un paquete ya distribuido.
  assert hashlib.sha256(archived).hexdigest()=='6b06c267afd6843dc6eb7d313f08c1a9460c0e0df270a91309be11915be3086f','Corte archivado alterado'
 else:
  # El checkout superficial de CI no incluye el commit anterior.
  assert hashlib.sha256(archived).hexdigest()=='52a1b037b31f77e3161dee13adb3e1693b6bf93b1bf9347e8f24ee5c99e0afc5', 'Corte archivado alterado: '+row['path']
for variant in [0,1]:
 with tempfile.TemporaryDirectory(prefix='impe-verificacion-vigente-') as folder:
  d=pathlib.Path(folder);site=d/'site';site.mkdir();(site/'index.php').write_text('<?php // fixture');backups=d/'backup'
  normalized=d/'release.json';normalized.write_bytes((R/'tools/analista_registro_obligatorio_release.json').read_bytes().replace(b'\r\n',b'\n'))
  originals={};targets={}
  for row in spec['files']:
   v=row['variants'][min(variant,len(row['variants'])-1)];p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
   originals[row['path']]=gzip.decompress(base64.b64decode(v['base']));targets[row['path']]=gzip.decompress(base64.b64decode(v['target']));p.write_bytes(originals[row['path']])
  def snapshot():return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
  def run(*args,**kw):
   r=subprocess.run([sys.executable,str(R/'tools/publicar_analista_registro_obligatorio.py'),'--site',str(site),'--backup-root',str(backups),'--manifest',str(normalized)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
   assert (r.returncode==0)==kw.get('ok',True),r.stdout+r.stderr
  before=snapshot();run('--check');assert snapshot()==before and not backups.exists()
  js=site/'public/javascript/seguimiento_llamada_registro_obligatorio.js';js.write_bytes(js.read_bytes()+b'// personalizacion');altered=snapshot();run('--apply',ok=False);assert snapshot()==altered
  js.write_bytes(originals['public/javascript/seguimiento_llamada_registro_obligatorio.js']);run('--apply')
  assert all((site/p).read_bytes()==b for p,b in targets.items())
  saved=next(x for x in backups.iterdir() if x.is_dir());run('--apply');assert len(list(backups.iterdir()))==1
  js.write_bytes(js.read_bytes()+b'// edicion posterior');run('--restore',str(saved),ok=False)
  js.write_bytes(targets['public/javascript/seguimiento_llamada_registro_obligatorio.js']);run('--restore',str(saved));assert snapshot()==before
print('PASS publicaciÃ³n: archivos exactos Hostinger, permisos pÃºblicos, check sin cambios, bloqueo, idempotencia y reversiÃ³n')
