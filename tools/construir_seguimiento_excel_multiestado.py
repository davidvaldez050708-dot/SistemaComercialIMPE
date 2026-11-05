"""Regenera sólo payloads y sello del paquete; conserva las versiones aprobadas."""
import base64,gzip,hashlib,json,re
from pathlib import Path
R=Path(__file__).resolve().parents[1]
p=R/'tools/seguimiento_excel_multiestado_release.json';spec=json.loads(p.read_text(encoding='utf8'))
for row in spec['files']:
 data=(R/row['path']).read_bytes().replace(b'\r\n',b'\n')
 for variant in row['variants']:
  variant['after']=hashlib.sha256(data).hexdigest()
  variant['target']=base64.b64encode(gzip.compress(data,mtime=0)).decode()
raw=(json.dumps(spec,ensure_ascii=False,indent=2)+'\n').encode();p.write_bytes(raw)
publisher=R/'tools/publicar_seguimiento_excel_multiestado.py'
src=publisher.read_text(encoding='utf8');src=re.sub(r"MANIFEST_SHA256 = '[^']+'",'MANIFEST_SHA256 = '+repr(hashlib.sha256(raw).hexdigest()),src)
publisher.write_text(src,encoding='utf8',newline='\n')
