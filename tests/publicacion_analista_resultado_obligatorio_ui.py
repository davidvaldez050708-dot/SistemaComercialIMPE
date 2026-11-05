"""Pruebas aisladas del paquete JS; sin CRM, llamadas ni bases reales."""
import base64,gzip,hashlib,json,os,pathlib,subprocess,sys,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=json.loads((R/'tools/analista_resultado_obligatorio_ui_release.json').read_text(encoding='utf8'))
unpack=lambda value:gzip.decompress(base64.b64decode(value))
paths=[row['path'] for row in spec['files']]
assert paths==['public/javascript/telefonia_analista_resultado_superpuesto.js','public/javascript/telefonia_analista_historial_pasivo.js','public/javascript/seguimiento_entrantes_analista.js']
for row in spec['files']:
 assert unpack(row['variants'][0]['target'])==(R/row['path']).read_bytes().replace(b'\r\n',b'\n')
 for v in row['variants']:
  before,after=unpack(v['base']),unpack(v['target'])
  if row['path'].endswith('telefonia_analista_historial_pasivo.js'):
   marker='/* Resultado de marcación libre del Analista.'.encode()
   assert before.split(marker)[0]==after.split(marker)[0],'Observador entrante alterado'
  if row['path'].endswith('seguimiento_entrantes_analista.js'):
   start=b'    const formatAudioTime =';end=b"    form.addEventListener('submit'"
   assert before[before.index(start):before.index(end)]==after[after.index(start):after.index(end)],'Grabaciones personalizadas alteradas'
for variant in [0,1]:
 with tempfile.TemporaryDirectory(prefix='impe-resultado-ui-') as folder:
  root=pathlib.Path(folder);site=root/'site';site.mkdir();(site/'index.php').write_text('<?php // fixture');backups=root/'backups'
  for dep in spec['dependencies']:
   dest=site/dep['path'];dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(unpack(dep['variants'][variant]['base']))
  originals={};targets={}
  for row in spec['files']:
   v=row['variants'][min(variant,len(row['variants'])-1)];name=row['path']
   dest=site/name;dest.parent.mkdir(parents=True,exist_ok=True)
   originals[name]=unpack(v['base']);targets[name]=unpack(v['target']);dest.write_bytes(originals[name])
   check=root/dest.name;check.write_bytes(targets[name]);subprocess.run(['node','--check',str(check)],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  excluded=site/'public/javascript/seguimiento_llamada_registro_obligatorio.js';excluded.write_text('// Correccion del usuario: intacta')
  def snapshot():return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}
  def run(*args,**options):
   result=subprocess.run([sys.executable,str(R/'tools/publicar_analista_resultado_obligatorio_ui.py'),'--site',str(site),'--backup-root',str(backups)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
   assert (result.returncode==0)==options.get('ok',True),result.stdout+result.stderr
  before=snapshot();run('--check');assert snapshot()==before and not backups.exists()
  dep=site/spec['dependencies'][0]['path'];dep.write_bytes(dep.read_bytes()+b'// desconocido');changed=snapshot();run('--apply',ok=False);assert snapshot()==changed
  dep.write_bytes(before[spec['dependencies'][0]['path']])
  changed_file=site/paths[-1];changed_file.write_bytes(changed_file.read_bytes()+b'// local desconocido');changed=snapshot();run('--apply',ok=False);assert snapshot()==changed
  changed_file.write_bytes(originals[paths[-1]]);run('--apply')
  assert all((site/name).read_bytes()==b for name,b in targets.items())
  assert excluded.read_bytes()==before[excluded.relative_to(site).as_posix()]
  if os.name=='posix':assert all((site/name).stat().st_mode&0o777==0o644 for name in paths)
  backup=next(p for p in backups.iterdir() if p.is_dir());run('--apply');assert len(list(backups.iterdir()))==1
  changed_file.write_bytes(changed_file.read_bytes()+b'// edicion posterior');run('--restore',str(backup),ok=False)
  changed_file.write_bytes(targets[paths[-1]]);run('--restore',str(backup));assert snapshot()==before
print('PASS paquete UI: tres JS, variantes instaladas, observador/audio intactos, Seguimiento excluido, check sin escrituras, bloqueo, permisos, idempotencia y reversion')
