"""Publicación en réplica temporal. No usa SQL ni credenciales del sitio."""
import base64,gzip,hashlib,json,os,re,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
package=json.loads((root/'tools/inscripcion_completa_release.json').read_text(encoding='utf-8'))
php=os.environ.get('PHP_BINARY','php')
# Un cambio de Inscripción no puede reescribir Registro, alias, horarios o autorización.
baseline=(root/'tests/fixtures/inscripcion_controller_hostinger.php.txt').read_text(encoding='utf-8')
current=(root/'app/controllers/FormularioController.php').read_text(encoding='utf-8')
for method in ['registro','municipios','generarEnlaceRegistro','acortarRegistro','guardarRegistro','generarEnlaceInscripcion','guardarInscripcion','normalizarRegistro','validarRegistro','validarAccesoMarketing']:
 pattern=r'    (?:public|private) function '+method+r'\([^\n]*\)\s*\n    \{.*?\n    \}'
 a,b=re.search(pattern,baseline,re.S),re.search(pattern,current,re.S)
 assert a and b and a.group()==b.group(),method+' cambió fuera del alcance'

def blob(rev,name):return subprocess.check_output(['git','show',rev+':'+name],cwd=str(root))
with tempfile.TemporaryDirectory(prefix='impe-inscripcion-release-') as temp:
 site=Path(temp)/'public_html';backup=Path(temp)/'backups';site.mkdir();(site/'index.php').write_text('<?php // réplica')
 originals={}
 for i,row in enumerate(package['files'][:3]):
  name=row['path'];data=baseline.encode() if i==0 else blob('8b9630b5',name)
  assert hashlib.sha256(data).hexdigest()==row['variants'][0]['before']
  target=site/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(data);originals[name]=data
 for name,sha in package['dependencies'].items():
  rev='c58f76f79177e7112a3af3ab9e9804f40d0a94df' if name.endswith('FormularioRegistroModel.php') else '0fc18e0cbcb9a9bf6f99b219237d4b24dab06083' if name.endswith('RegistroEnlaceService.php') else '8b9630b5'
  data=blob(rev,name);assert hashlib.sha256(data).hexdigest()==sha
  dest=site/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
 def run(*args,ok=True):
  p=subprocess.run([sys.executable,str(root/'tools/publicar_inscripcion_completa.py'),'--site',str(site),'--backup-root',str(backup)]+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  text=(p.stdout+p.stderr).decode('utf-8','replace');assert (p.returncode==0)==ok,text
  return text
 assert 'CHECK OK' in run('--check');assert not backup.exists()
 assert 'APLICADO' in run('--apply')
 for row in package['files']:
  target=site/row['path'];assert hashlib.sha256(target.read_bytes()).hexdigest()==row['variants'][0]['after']
  assert target.read_bytes()==(root/row['path']).read_bytes(),'manifiesto sin regenerar: '+row['path']
 assert 'YA INSTALADO' in run('--apply')
 folder=next(backup.iterdir());assert 'RESTAURADO' in run('--restore',str(folder))
 for row in package['files']:
  path=site/row['path'];assert path.exists()==(row['path'] in originals)
  if path.exists():assert path.read_bytes()==originals[row['path']]
 changed=site/package['files'][0]['path'];changed.write_bytes(changed.read_bytes()+b'\n// LOCAL')
 assert 'DIFERENTE' in run('--check',ok=False)
 assert not (site/'ya-estas-inscrito/index.php').exists()
 print('PASS: versión Hostinger exacta, Registro/alias/horarios intactos, 15 archivos, CHECK sin escrituras, APPLY, idempotencia, RESTORE y rechazo de variantes desconocidas.')
