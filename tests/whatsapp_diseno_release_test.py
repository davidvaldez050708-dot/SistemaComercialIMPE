import ast,importlib.util,json,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(root/'tools'))
from preparar_whatsapp_diseno_release import before
import publicar_whatsapp_diseno as publisher
release=json.loads((root/'tools/whatsapp_diseno_release.json').read_text(encoding='utf-8'))
for row in release['files']:
 old=before(row['path']);new=publisher.candidate(old,row)
 assert new.replace(b'\r\n',b'\n')==(root/row['path']).read_bytes().replace(b'\r\n',b'\n'),row['path']
 assert publisher.candidate(new,row)==new
 publisher.lint(new,row['path'])
 if old is not None:
  custom=old+b'\n<!-- custom block -->\n' if row['path'].endswith('.php') else old+b'\n/* custom block */\n'
  assert publisher.candidate(custom,row).endswith(custom[len(old):])
  assert publisher.candidate(old.replace(b'\n',b'\r\n'),row).replace(b'\r\n',b'\n')==new
 # The production inbox may already contain the independent Sin asignar link.
 if row['path']=='app/views/whatsapp/index.php':
  previous=subprocess.check_output(['git','show','46742db:'+row['path']],cwd=str(root))
  spec=importlib.util.spec_from_file_location('sales',root/'tools/publicar_whatsapp_ventas.py');sales=importlib.util.module_from_spec(spec);spec.loader.exec_module(sales)
  sales_row=next(r for r in json.loads((root/'tools/whatsapp_ventas_release.json').read_text(encoding='utf-8'))['files'] if r['path']==row['path'])
  combined=sales.candidate(previous,sales_row);assert b'action=asignaciones' in publisher.candidate(combined,row)
for name in ['publicar_whatsapp_diseno.py','preparar_whatsapp_diseno_release.py']:ast.parse((root/'tools'/name).read_text(encoding='utf-8'),feature_version=(3,6))
with tempfile.TemporaryDirectory(prefix='wa-design-release-') as temp:
 base=Path(temp);site=base/'site';private=base/'private';site.mkdir();(site/'index.php').write_text('<?php');private.mkdir()
 expected={}
 for row in release['files']:
  old=before(row['path']);expected[row['path']]=old;p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
  if old is not None:p.write_bytes(old)
 command=[sys.executable,str(root/'tools/publicar_whatsapp_diseno.py'),'--site',str(site),'--backup-root',str(private)]
 def run(args,success=True):
  r=subprocess.run(command+args,stdout=subprocess.PIPE,stderr=subprocess.PIPE);assert (r.returncode==0)==success,(r.stdout+r.stderr).decode(errors='replace')
 run(['--check']);assert not list(private.iterdir())
 run(['--apply']);run(['--apply']);backups=list(private.glob('whatsapp-diseno-*/manifest.json'));assert len(backups)==1
 run(['--restore',str(backups[0].parent)])
 for name,old in expected.items():assert ((site/name).read_bytes() if (site/name).exists() else None)==old
 (site/'app/views/whatsapp/chat.php').write_text('<?php /* modified incompatible */');run(['--apply'],False)
 assert not (site/'public/css/whatsapp_institucional.css').exists()
print('PASS publicación: bloques selectivos, base instalada con Sin asignar, sintaxis, Python 3.6, LF/CRLF, personalizaciones, repetición, restauración y bloqueo sin cambios parciales.')
