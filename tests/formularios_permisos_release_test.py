"""Réplica de archivos Hostinger; no conecta con una base de datos."""
import base64,gzip,hashlib,json,os,re,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
package=json.loads((root/'tools/formularios_permisos_release.json').read_text(encoding='utf-8'))
fixtures=json.loads((root/'tests/fixtures/formularios_rbac_hostinger.json').read_text(encoding='utf-8'))
decode=lambda row:gzip.decompress(base64.b64decode(row['content']))
sha=lambda data:hashlib.sha256(data).hexdigest()
# Capture, dates, aliases and contact schedules retain the installed implementation.
before=decode(fixtures['app/controllers/FormularioController.php']).decode()
after=(root/'app/controllers/FormularioController.php').read_text(encoding='utf-8')
for method in ['normalizarRegistro','validarRegistro','perfilesInteres','normalizarInscripcion','validarInscripcion']:
 pattern=r'    (?:public|private) function '+method+r'\([^\n]*\)\s*\n    \{.*?\n    \}'
 a,b=re.search(pattern,before,re.S),re.search(pattern,after,re.S)
 assert bool(a)==bool(b),method
 if a:assert a.group()==b.group(),method+' cambió fuera de autorización'
assert len(package['files'])==11
assert not any('telefonia' in r['path'] or 'aliado' in r['path'].lower() for r in package['files'])
with tempfile.TemporaryDirectory(prefix='impe-formularios-release-') as temp:
 site=Path(temp)/'public_html';backup=Path(temp)/'backups';site.mkdir();(site/'index.php').write_text('<?php // replica')
 originals={}
 for row in package['files']:
  name=row['path'];variant=row['variants'][0]
  assert sha(gzip.decompress(base64.b64decode(variant['target'])))==variant['after']
  assert (root/name).read_bytes().replace(b'\r\n',b'\n')==gzip.decompress(base64.b64decode(variant['target']))
  if variant['before'] is not None:
   raw=decode(fixtures[name]);assert sha(raw)==variant['before']
   path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw);originals[name]=raw
 for name,expected in package['dependencies'].items():
  if name.endswith('FormularioRegistroModel.php'):rev='c58f76f79177e7112a3af3ab9e9804f40d0a94df'
  elif name.endswith('RegistroEnlaceService.php'):rev='0fc18e0cbcb9a9bf6f99b219237d4b24dab06083'
  else:rev=None
  raw=subprocess.check_output(['git','show',rev+':'+name],cwd=str(root)) if rev else (root/name).read_bytes().replace(b'\r\n',b'\n')
  assert sha(raw)==expected,name
  path=site/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw)
 def snapshot():return {str(p.relative_to(site)):p.read_bytes() for p in site.rglob('*') if p.is_file()}
 initial=snapshot()
 def run(*args,**kwargs):
  proc=subprocess.run([sys.executable,str(root/'tools/publicar_formularios_permisos.py'),'--site',str(site),'--backup-root',str(backup)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  output=(proc.stdout+proc.stderr).decode('utf-8','replace');assert (proc.returncode==0)==kwargs.get('ok',True),output
  return output
 assert 'CHECK OK' in run('--check');assert snapshot()==initial and not backup.exists()
 assert 'APLICADO' in run('--apply')
 for row in package['files']:assert sha((site/row['path']).read_bytes())==row['variants'][0]['after']
 assert 'YA INSTALADO' in run('--apply')
 folder=next(p for p in backup.iterdir() if p.is_dir());assert 'RESTAURADO' in run('--restore',str(folder));assert snapshot()==initial
 target=site/'app/views/layout/sidebar.php';target.write_bytes(target.read_bytes()+b'\n// customization')
 modified=snapshot();assert 'DIFERENTE' in run('--check',ok=False);assert snapshot()==modified
 print('PASS: 11 archivos exactos; dependencias intactas; CHECK sin escrituras; APPLY, idempotencia, RESTORE y rechazo de personalizaciones desconocidas.')
