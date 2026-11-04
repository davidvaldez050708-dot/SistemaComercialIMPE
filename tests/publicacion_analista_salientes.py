"""Publicación aislada: no lee configuración real ni se conecta a ningún servidor."""
import base64,gzip,hashlib,json,os,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/analista_salientes_release.json').read_text(encoding='utf8'))
unpack=lambda s:None if s is None else gzip.decompress(base64.b64decode(s))
service=next(row for row in spec['files'] if row['path']=='app/services/TelefoniaEntrantesAnalistaService.php')
assert any(v['before']=='d8dd441e0b13f836d08f7f285bf563dc9c3e083bc09591b797cb21e3b7239b34' for v in service['variants']),'Falta el servicio territorial instalado en Hostinger'
for row in spec['files']:
 assert unpack(row['variants'][0]['target'])==(R/row['path']).read_bytes().replace(b'\r\n',b'\n'),'Paquete desactualizado: '+row['path']
previous=json.loads(subprocess.check_output(['git','show','110248ab367d9b6de55e3d91f2bf6a35b691f02e:tools/analista_salientes_release.json'],cwd=str(R)))
installed={row['path']:unpack(row['variants'][-1]['target']) for row in previous['files']}
for variant in list(range(max(len(row['variants']) for row in spec['files'])))+['installed']:
 with tempfile.TemporaryDirectory(prefix='impe-salientes-publicacion-') as folder:
  d=pathlib.Path(folder);site=d/'site';site.mkdir();(site/'index.php').write_text('<?php // fixture');backups=d/'backup'
  originals={};targets={}
  for dep in spec['dependencies']:
   dest=site/dep['path'];dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(unpack(dep['base']))
  for row in spec['files']:
   if variant=='installed':
    original=installed[row['path']];h=hashlib.sha256(original).hexdigest()
    v=next(v for v in row['variants'] if h in [v['before'],v['after']])
   else:
    v=row['variants'][min(variant,len(row['variants'])-1)];original=unpack(v['base'])
   p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
   originals[row['path']]=original;targets[row['path']]=unpack(v['target'])
   if originals[row['path']] is not None:p.write_bytes(originals[row['path']])
   if row['path'].endswith('.js'):
    candidate=d/('syntax-'+p.name);candidate.write_bytes(targets[row['path']]);subprocess.run(['node','--check',str(candidate)],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  untouched=site/'public/javascript/telefonia_transferencia_runtime.js';untouched.write_text('// Intacto: motor de transferencias')
  def snapshot():return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
  def run(*args,**kw):
   r=subprocess.run([sys.executable,str(R/'tools/publicar_analista_salientes.py'),'--site',str(site),'--backup-root',str(backups)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
   assert (r.returncode==0)==kw.get('ok',True),r.stdout+r.stderr
  before=snapshot();run('--check');assert snapshot()==before and not backups.exists()
  dep=site/spec['dependencies'][0]['path'];dep.write_bytes(dep.read_bytes()+b'// variante desconocida');altered=snapshot();run('--apply',ok=False);assert snapshot()==altered
  dep.write_bytes(before[spec['dependencies'][0]['path']])
  js=site/'public/javascript/telefonia_host.js';js.write_bytes(js.read_bytes()+b'// personalizacion');altered=snapshot();run('--apply',ok=False);assert snapshot()==altered
  js.write_bytes(originals['public/javascript/telefonia_host.js']);run('--apply')
  assert all((site/p).read_bytes()==b for p,b in targets.items())
  if variant=='installed':
   changed={p for p,b in targets.items() if b!=originals[p]}
   assert changed=={'public/javascript/telefonia_analista_historial_pasivo.js'},changed
  if os.name=='posix':assert all((site/p).stat().st_mode&0o777==0o644 for p in targets if p.startswith('public/'))
  saved=next(x for x in backups.iterdir() if x.is_dir());run('--apply');assert len(list(backups.iterdir()))==1
  changed_name=next(p for p,b in targets.items() if b!=originals[p])
  changed_file=site/changed_name
  changed_file.write_bytes(changed_file.read_bytes()+b'// edicion posterior');run('--restore',str(saved),ok=False)
  changed_file.write_bytes(targets[changed_name]);run('--restore',str(saved));assert snapshot()==before
print('PASS publicacion salientes: diez archivos, variantes locales/Hostinger, dependencias, check sin cambios, bloqueo, idempotencia, permisos y restauracion')
