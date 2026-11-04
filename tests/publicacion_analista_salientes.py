"""Publicación aislada: no lee configuración real ni se conecta a ningún servidor."""
import base64,gzip,hashlib,json,os,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/analista_salientes_release.json').read_text(encoding='utf8'))
unpack=lambda s:None if s is None else gzip.decompress(base64.b64decode(s))
service=next(row for row in spec['files'] if row['path']=='app/services/TelefoniaEntrantesAnalistaService.php')
assert any(v['before']=='d8dd441e0b13f836d08f7f285bf563dc9c3e083bc09591b797cb21e3b7239b34' for v in service['variants']),'Falta el servicio territorial instalado en Hostinger'
# Estos archivos son parte de una entrega ya publicada; sus contenidos son
# inmutables aunque la fuente actual tenga una corrección visual posterior.
published={'public/javascript/telefonia_shell.js':'84c14d94564ca8093269e2c2c6b1cfc29001a21f9a4eefb8e601db66d15b2c64','public/javascript/seguimiento_llamada_registro_obligatorio.js':'69e64923a4a8c3db216f733e5d631bacf3846fc738c8a7b1e3b605385829fca6'}
later_changes={'app/services/TelefoniaSalienteAnalistaEstado.php',
               'app/services/TelefoniaEntrantesAnalistaService.php',
               'app/views/seguimiento_vinculacion/llamadas_entrantes.php',
               'public/javascript/telefonia_analista_historial_pasivo.js'}
for row in spec['files']:
 target=unpack(row['variants'][0]['target'])
 if row['path'] in published:
  assert hashlib.sha256(target).hexdigest()==published[row['path']],'Paquete publicado alterado: '+row['path']
 elif row['path'] in later_changes:
  archived=subprocess.check_output(['git','show','d5e70163c2999cdb68d73396079f73ef3056e2aa:'+row['path']],cwd=str(R))
  assert target==archived.replace(b'\r\n',b'\n'),'Paquete histórico alterado: '+row['path']
 else:
  assert target==(R/row['path']).read_bytes().replace(b'\r\n',b'\n'),'Paquete desactualizado: '+row['path']
previous=json.loads(subprocess.check_output(['git','show','f1656f829a7f3b124f96d292853d28c6caa10155:tools/analista_salientes_release.json'],cwd=str(R)))
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
   assert changed=={'public/javascript/seguimiento_llamada_registro_obligatorio.js'},changed
  if os.name=='posix':assert all((site/p).stat().st_mode&0o777==0o644 for p in targets if p.startswith('public/'))
  saved=next(x for x in backups.iterdir() if x.is_dir());run('--apply');assert len(list(backups.iterdir()))==1
  changed_name=next(p for p,b in targets.items() if b!=originals[p])
  changed_file=site/changed_name
  changed_file.write_bytes(changed_file.read_bytes()+b'// edicion posterior');run('--restore',str(saved),ok=False)
  changed_file.write_bytes(targets[changed_name]);run('--restore',str(saved));assert snapshot()==before
print('PASS publicacion salientes: diez archivos, variantes locales/Hostinger, dependencias, check sin cambios, bloqueo, idempotencia, permisos y restauracion')
