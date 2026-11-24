"""Entrega acotada sobre la copia de producción; nunca publica ni conecta al CRM."""
from pathlib import Path
import difflib
import hashlib
import json
import zipfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
OUT = ROOT / 'output/agenda_destinatarios_20261124'
OUT.mkdir(parents=True, exist_ok=True)
snapshot = ROOT / 'output/seguimiento_flujo_20261119/native-constructors/after'
dependency = ROOT / 'tools/estabilidad_403_503/dependency_snapshot_20261110'
manifest = []
patch = []
for path in sorted((HERE / 'files').rglob('*')):
    if not path.is_file(): continue
    name = path.relative_to(HERE / 'files').as_posix()
    original = (dependency if name.startswith('public/javascript/') else snapshot) / name
    before = original.read_text(encoding='utf-8') if original.exists() else ''
    after = path.read_text(encoding='utf-8')
    patch.extend(difflib.unified_diff(before.splitlines(True), after.splitlines(True),
        fromfile='a/'+name if original.exists() else '/dev/null', tofile='b/'+name))
    manifest.append({'archivo':name,'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),
                     'base_sha256':hashlib.sha256(original.read_bytes()).hexdigest() if original.exists() else None})
(OUT / 'cambio.patch').write_text(''.join(patch),encoding='utf-8',newline='\n')
(HERE / 'cambio.patch').write_text(''.join(patch),encoding='utf-8',newline='\n')
(OUT / 'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
with zipfile.ZipFile(OUT / 'agenda_destinatarios_multiples.zip','w',zipfile.ZIP_DEFLATED) as archive:
    for path in sorted((HERE / 'files').rglob('*')):
        if path.is_file(): archive.write(path,path.relative_to(HERE / 'files').as_posix())
    archive.write(HERE / 'LEEME.txt','LEEME.txt')
    archive.write(OUT / 'cambio.patch','cambio.patch')
    archive.write(OUT / 'manifest.json','manifest.json')
print(OUT / 'agenda_destinatarios_multiples.zip')
