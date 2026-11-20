"""Publicación en sitio ficticio y base efímera creada por whatsapp_asignacion_db.php."""
import ast
import base64
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

if os.environ.get('IMPE_WA_ASSIGN_TEST')!='YES':raise SystemExit('Sólo entorno de pruebas')
ROOT=Path(__file__).resolve().parents[1]
database=sys.argv[1]
if not database.startswith('impe_wa_queue_test_'):raise SystemExit('Base no autorizada')
script=ROOT/'tools/publicar_whatsapp_asignacion.py'
ast.parse(script.read_text(encoding='utf-8'),feature_version=(3,6))
spec=json.loads((ROOT/'tools/whatsapp_asignacion_release.json').read_text(encoding='utf-8'))
for crlf in (False,True):
 with tempfile.TemporaryDirectory(prefix='wa-queue-deploy-') as directory:
  private=Path(directory);site=private/'public_html';site.mkdir();(site/'index.php').write_text('<?php',encoding='utf-8')
  (site/'config').mkdir()
  config=site/'config/db_connection.php'
  config.write_text("<?php class Database {public function connect($persistent=false){return new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'"+database+"',(int)getenv('MYSQL_PORT'));}}",encoding='utf-8')
  sentinel=config.read_bytes();old={};new={}
  for row in spec['files']:
   name=row['path'];p=site/name;p.parent.mkdir(parents=True,exist_ok=True)
   after=(ROOT/name).read_bytes().replace(b'\r\n',b'\n')
   assert hashlib.sha256(after).hexdigest()==row['after_sha']
   before=None if row['new_file'] else subprocess.check_output(['git','show','626a7f4:'+name],cwd=ROOT).replace(b'\r\n',b'\n')
   if before is not None:
    assert hashlib.sha256(before).hexdigest()==row['before_sha']
    before=before.replace(b'<?php\n',b'<?php\n// Personalizacion preservada\n',1)
    after=after.replace(b'<?php\n',b'<?php\n// Personalizacion preservada\n',1)
    if crlf:before=before.replace(b'\n',b'\r\n');after=after.replace(b'\n',b'\r\n')
    p.write_bytes(before)
   old[name]=before;new[name]=after
  command=[sys.executable,str(script),'--site',str(site),'--backup-root',str(private/'backups')]
  def run(*args,ok=True):
   r=subprocess.run(command+list(args),stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True,encoding='utf-8',env=dict(os.environ,PYTHONIOENCODING='utf-8'))
   assert (r.returncode==0)==ok,r.stdout+r.stderr
   return r.stdout+r.stderr
  def matches(expected):
   for name,content in expected.items():assert ((site/name).read_bytes() if (site/name).exists() else None)==content,name
   assert config.read_bytes()==sentinel
  assert 'COMPROBACION_OK' in run('--check');matches(old)
  p=site/'app/models/WhatsAppModel.php'
  p.write_bytes(p.read_bytes().replace(b'public function vincularEntradaProspecto',b'public function vincularEntradaPersonalizada',1))
  snapshot={name:(site/name).read_bytes() if (site/name).exists() else None for name in old}
  assert 'BLOQUEADO' in run('--apply',ok=False);matches(snapshot)
  p.write_bytes(old['app/models/WhatsAppModel.php'])
  output=run('--apply');assert 'PUBLICACION_OK' in output;matches(new)
  backup=next(line[len('RESPALDO: '):] for line in output.splitlines() if line.startswith('RESPALDO: '))
  assert 'SIN_CAMBIOS' in run('--apply')
  assert 'RESTAURACION_OK' in run('--restore',backup);matches(old)
  assert 'RESTAURACION_OK: 0' in run('--restore',backup)
print('PASS: publicación, esquema, bloques exactos, personalizaciones, LF/CRLF, idempotencia, bloqueo y restauración.')
