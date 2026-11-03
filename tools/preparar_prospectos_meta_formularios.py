"""Genera el paquete offline desde los siete archivos revisados. Sin red ni SQL."""
import base64
import gzip
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BASE='7aad6c9b0b343dc3b2c18ca61612dbfad9a7c037'
EXPECTED={
 'app/services/ProspectoMetaArchivo.php':'62d86da78065c9506333dc9a69dff491d244527f73eb344a40be91339a329ebe',
 'app/services/ProspectoMetaImportador.php':'b18fd51353058c5dbcee91216b544a37ae01913673f9f8b821df5ace74cdd27d',
 'app/controllers/ProspectoController.php':'d50f1083062518b5b407889ff896c698ad389156d7b39acc16d4559deacbcb44',
 'app/views/prospectos/partials/importar_meta.php':'d5b2140477421bcdb71467ee5871a0e5eb13f4d714cda31f0b18225510b66ea5',
 'public/css/prospectos_importador_meta.css':'e931041a097df27650446c1f6115acf6274280e7141b5531dd58e597b74fde8a',
 'public/javascript/prospectos_importador_meta.js':'c695a86265d995832992fa4c9ccf5a28323101fe33eebcf1a1bf525e20bb52be',
}
def pack(data):return base64.b64encode(gzip.compress(data,mtime=0)).decode() if data is not None else None
def sha(data):return hashlib.sha256(data).hexdigest() if data is not None else None
spec=importlib.util.spec_from_file_location('pub',ROOT/'tools/publicar_prospectos_meta_formularios.py')
pub=importlib.util.module_from_spec(spec);spec.loader.exec_module(pub)
rows=[]
for name in pub.FILES:
    before=subprocess.check_output(['git','show',BASE+':'+name],cwd=ROOT) if name in EXPECTED else None
    assert sha(before)==EXPECTED.get(name),name
    after=(ROOT/name).read_bytes().replace(b'\r\n',b'\n')
    rows.append(dict(path=name,base_sha256=sha(before),sha256=sha(after),base=pack(before),target=pack(after)))
manifest=dict(schema=1,source='Meta: BOM y clasificación por formulario; base '+BASE,files=rows,dependencies=[])
(ROOT/'tools/prospectos_meta_formularios_release.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print('Paquete generado: siete archivos, sin cambios en Roles, Aliados o datos.')
