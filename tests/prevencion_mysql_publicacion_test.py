"""Verifica el paquete sobre candidatos reales sin conectarse al CRM ni a Zoho."""
import importlib.util,json,os,subprocess,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
spec=json.loads((root/'tools/prevencion_mysql_release.json').read_text(encoding='utf-8'))
script=root/'tools/publicar_prevencion_mysql.py'
ms=importlib.util.spec_from_file_location('pub_prevencion',script);pub=importlib.util.module_from_spec(ms);ms.loader.exec_module(pub)
with tempfile.TemporaryDirectory(prefix='prevencion-pub-') as tmp:
 site=Path(tmp)/'site';backups=Path(tmp)/'private';site.mkdir();(site/'index.php').write_text('<?php')
 before={}
 for row in spec['files']:
  p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
  if row['new_file']:continue
  # Configuracion sintetica: no se lee ni copia ningun secreto real.
  data=b"<?php\nrequire_once dirname(__DIR__).'/app/helpers/PausaConsultasAutomaticas.php';\n// ajustes privados conservados\n" if row['path']=='config/db_connection.php' else (root/'tmp/prevencion-actual-live'/row['path']).read_bytes()
  p.write_bytes(data);before[row['path']]=data
 def run(*args,success=True):
  r=subprocess.run([os.sys.executable,str(script),'--site',str(site),'--backup-root',str(backups),*args],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,env=dict(os.environ,PYTHONIOENCODING='utf-8'))
  assert (r.returncode==0)==success,r.stdout.decode();return r.stdout.decode()
 assert len(spec['files'])==15
 assert all('zoho' not in row['path'].lower() for row in spec['files'])
 assert 'COMPROBACION_OK: 15 archivos' in run('--check')
 for name,data in before.items():assert (site/name).read_bytes()==data
 assert 'PUBLICACION_OK: 15 archivos' in run('--apply')
 assert 'SIN_CAMBIOS' in run('--apply')
 for row in spec['files']:
  assert (site/row['path']).read_bytes()==pub.candidate(before.get(row['path']),row)
 assert 'RESTAURACION_OK: 15 archivos' in run('--restore',str(next(backups.iterdir())))
 for name,data in before.items():assert (site/name).read_bytes()==data
 for row in spec['files']:
  if row['new_file']:assert not (site/row['path']).exists()
 # Conflicto tardio: ningun archivo ni auxiliar nuevo se publica.
 conflicted=site/'index.php';conflicted.write_bytes(before['index.php'].replace(b'set_exception_handler',b'personalizado_handler'))
 assert 'BLOQUEADO' in run('--apply',success=False)
 for name,data in before.items():
  if name!='index.php':assert (site/name).read_bytes()==data
 assert not (site/'app/helpers/ControlCargaMysql.php').exists()
 # Fallo durante la escritura: se revierten los archivos ya publicados.
 conflicted.write_bytes(before['index.php'])
 write_original=pub.write;llamadas=[0]
 def fallar_una_vez(*args):
  llamadas[0]+=1
  if llamadas[0]==3:raise OSError('fallo de escritura sintetico')
  return write_original(*args)
 pub.write=fallar_una_vez
 argv_original=os.sys.argv
 try:
  os.sys.argv=[str(script),'--site',str(site),'--backup-root',str(backups),'--apply']
  try:pub.main();raise AssertionError('El fallo debe propagarse')
  except OSError as e:assert str(e)=='fallo de escritura sintetico'
 finally:pub.write=write_original;os.sys.argv=argv_original
 for name,data in before.items():assert (site/name).read_bytes()==data
 for row in spec['files']:
  if row['new_file']:assert not (site/row['path']).exists()
print('OK: 15 candidatos, configuracion conservada, idempotencia, restauracion y conflicto sin escrituras; Zoho excluido.')
