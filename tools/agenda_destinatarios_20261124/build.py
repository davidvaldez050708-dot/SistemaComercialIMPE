"""Entrega acotada sobre la copia de producción; nunca publica ni conecta al CRM."""
from pathlib import Path
import difflib
import hashlib
import json
import subprocess

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
OUT = ROOT / 'output/agenda_destinatarios_20261124'
OUT.mkdir(parents=True, exist_ok=True)
snapshot = HERE / 'base_hostinger'
manifest = []
instalacion = {}
patch = []
for path in sorted((HERE / 'files').rglob('*')):
    if not path.is_file(): continue
    name = path.relative_to(HERE / 'files').as_posix()
    original = snapshot / name
    before = original.read_text(encoding='utf-8') if original.exists() else ''
    after = path.read_text(encoding='utf-8')
    allowed = []
    if original.exists():
        allowed.append(hashlib.sha256(before.encode()).hexdigest())
    for revision in ['023cfc3f', 'a2e67b1d', '85b0c915', '95f8d0a6', '250cf2ec', '674da6a0']:
        result = subprocess.run(['git','show',f'{revision}:tools/agenda_destinatarios_20261124/files/{name}'],cwd=ROOT,capture_output=True)
        if result.returncode == 0:
            allowed.append(hashlib.sha256(result.stdout.decode('utf-8').replace('\r\n','\n').encode()).hexdigest())
    instalacion[name] = {'sha256':hashlib.sha256(after.encode()).hexdigest(),
                         'permitidos':sorted(set(allowed)), 'nuevo':not original.exists()}
    patch.extend(difflib.unified_diff(before.splitlines(True), after.splitlines(True),
        fromfile='a/'+name if original.exists() else '/dev/null', tofile='b/'+name))
    manifest.append({'archivo':name,'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),
                     'base_sha256':hashlib.sha256(original.read_bytes()).hexdigest() if original.exists() else None})
(OUT / 'cambio.patch').write_text(''.join(patch),encoding='utf-8',newline='\n')
(HERE / 'cambio.patch').write_text(''.join(patch),encoding='utf-8',newline='\n')
(HERE / 'instalacion.json').write_text(json.dumps(instalacion,indent=2),encoding='utf-8',newline='\n')
(OUT / 'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
print(HERE / 'instalacion.json')
