import json, os, subprocess, tempfile
from pathlib import Path

root=Path(__file__).resolve().parents[1]
spec=json.loads((root/'tools/validacion_casillas_release.json').read_text(encoding='utf-8'))
script=root/'tools/publicar_validacion_casillas.py'
with tempfile.TemporaryDirectory(prefix='contacto-release-test-') as tmp:
 site=Path(tmp)/'site';backups=Path(tmp)/'private';site.mkdir();(site/'index.php').write_text('<?php')
 before={}
 for row in spec['files']:
  p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
  if not row['new_file']:
   before[row['path']]=(root/'tmp/validacion-casillas-live'/row['path']).read_bytes();p.write_bytes(before[row['path']])
 def run(*args,success=True):
  r=subprocess.run([os.sys.executable,str(script),'--site',str(site),'--backup-root',str(backups),*args],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,env=dict(os.environ,PYTHONIOENCODING='utf-8'))
  assert (r.returncode==0)==success,r.stdout.decode();return r.stdout.decode()
 assert 'COMPROBACION_OK: 1 archivos' in run('--check')
 for name,data in before.items():assert (site/name).read_bytes()==data
 assert (site/spec['files'][0]['path']).read_bytes()==before[spec['files'][0]['path']]
 assert 'PUBLICACION_OK: 1 archivos' in run('--apply')
 assert 'SIN_CAMBIOS' in run('--apply')
 for row in spec['files']:
  if not row['new_file']:
   expected=(root/'tmp/validacion-casillas-stage'/row['path']).read_text(encoding='utf-8')
   assert (site/row['path']).read_text(encoding='utf-8')==expected,row['path']
 assert 'RESTAURACION_OK: 1 archivos' in run('--restore',str(next(backups.iterdir())))
 for name,data in before.items():assert (site/name).read_bytes()==data
 last=site/spec['files'][-1]['path'];last.write_text('<?php // personalizacion incompatible')
 assert 'BLOQUEADO' in run('--apply',success=False)
 assert last.read_text()=='<?php // personalizacion incompatible'
 for name,data in before.items():
  if (site/name)!=last:assert (site/name).read_bytes()==data
print('OK: un candidato verificados, cambios instalados conservados, idempotencia, restauracion y rechazo sin escrituras ante conflicto.')
