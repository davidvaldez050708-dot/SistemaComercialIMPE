import ast,importlib.util,json,subprocess,sys
from pathlib import Path
root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publisher',root/'tools/publicar_whatsapp_ventas.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
release=json.loads((root/'tools/whatsapp_ventas_release.json').read_text(encoding='utf-8'))
for rev in ['626a7f4f4530c8a59747e8801066ccf13d169ca5','46742dbec625e02c050012fca366628bd418ba1b']:
 for row in release['files']:
  old=subprocess.run(['git','show',rev+':'+row['path']],cwd=str(root),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
  before=None if old.returncode else old.stdout
  try:after=module.candidate(before,row)
  except RuntimeError as e:raise AssertionError(rev+' '+str(e))
  if rev.startswith('626'):assert after.replace(b'\r\n',b'\n')==(root/row['path']).read_bytes().replace(b'\r\n',b'\n'),row['path']
  assert module.candidate(after,row)==after,'rerun not idempotent: '+row['path']
  if before is not None:
   custom=before.replace(b'\r\n',b'\n')+b'\n/* custom unchanged */\n'
   assert module.candidate(custom,row).endswith(b'/* custom unchanged */\n')
   crlf=before.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n')
   assert module.candidate(crlf,row).replace(b'\r\n',b'\n')==after.replace(b'\r\n',b'\n')
  if rev.startswith('467') and row['path']=='app/services/WhatsAppContextoService.php':assert b'permiteResponderSeguimiento' in after
  module.lint(after,row['path'])
for name in ['publicar_whatsapp_ventas.py','preparar_whatsapp_ventas_release.py']:
 ast.parse((root/'tools'/name).read_text(encoding='utf-8'),feature_version=(3,6))
assert 'capture_output' not in (root/'tools/publicar_whatsapp_ventas.py').read_text()
row=next(r for r in release['files'] if r['path']=='app/models/WhatsAppModel.php')
try:module.candidate(b'<?php customized incompatible',row);raise AssertionError('unexpected overwrite')
except RuntimeError:pass
print('PASS release: dos bases conocidas, bloques exactos, personalizaciones, LF/CRLF, idempotencia, sintaxis y compatibilidad Python 3.6.')
