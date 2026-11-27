import sys, zipfile
from pathlib import Path
root=Path(__file__).resolve().parents[2]
here=Path(__file__).resolve().parent
with zipfile.ZipFile(here/'whatsapp_recepcion_v1_CHECK.zip') as z:
    z.extractall(here)
sys.path.insert(0,str(here))
from comprobar_recepcion import candidate, blob
import json
manifest=json.loads((here/'recepcion_v1.json').read_text(encoding='utf-8'))
model=root/'app/models/WhatsAppModel.php'
old=model.read_bytes().replace(b"require_once __DIR__.'/../services/WhatsAppVentasService.php';",b"require_once __DIR__.'/../services/WhatsAppVentasService.php';\nrequire_once __DIR__.'/../services/WhatsAppAsignacionService.php';")
assert blob(old)=='0ed455ba900aadb664ffc0c11872c6336391c732'
model.write_bytes(old)
(root/'app/services/WhatsAppAsignacionService.php').write_bytes((here/'fixture_AsignacionService.php').read_bytes())
for name,spec in manifest.items():
    p=root/name
    p.write_bytes(candidate(p.read_bytes(),spec))
    print('CANDIDATE',name,blob(p.read_bytes()))
    expected={'app/models/WhatsAppModel.php':'12657dd1950871b3ebe675451563311afa314dbe','app/controllers/WhatsAppWebhookController.php':'3828bc93f56f3cb92b7d26a9c5f53fe6e2518d7c'}
    assert blob(p.read_bytes())==expected[name]
with (root/'tests/whatsapp_ventas_cases.php').open('a',encoding='utf-8') as f:
    f.write("\nrequire __DIR__.'/../tools/whatsapp_recepcion_v1/test_recepcion.php';\n")
