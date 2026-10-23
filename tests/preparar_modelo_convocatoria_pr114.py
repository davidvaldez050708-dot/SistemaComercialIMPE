"""Genera exclusivamente para pruebas el modelo histórico PR114 conciliado con V3."""
import argparse
import base64
import gzip
import hashlib
import json
import tempfile
from pathlib import Path

root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output',required=True)
args=parser.parse_args()
out=Path(args.output).resolve()
if out.exists():raise RuntimeError('Se requiere un directorio de pruebas nuevo')
name='app/models/ConvocatoriaModel.php'
# Test the exact reviewed variant shipped for customized PR114 production,
# rather than a historical V3-only model that predates identity unification.
manifest=json.loads((root/'tools/bachillerato_unificacion_release.json').read_text(encoding='utf-8'))
row=next(r for r in manifest['files'] if r['path']==name)
variant=next(v for v in row.get('variants',[]) if v['ref']=='modelo-internacional-pr114-v3')
candidate=gzip.decompress(base64.b64decode(variant['target']))
if hashlib.sha256(candidate).hexdigest()!=variant['sha256']:
    raise RuntimeError('SHA-256 de la variante PR114 no coincide')
if b'ProgramaEducativo::entrada($datos)' not in candidate:
    raise RuntimeError('La variante PR114 no normaliza identidad educativa')
out.mkdir(parents=True)
file=out/name;file.parent.mkdir(parents=True);file.write_bytes(candidate)
helper=out/'app/helpers/ProgramaEducativo.php'
helper.parent.mkdir(parents=True,exist_ok=True)
helper.write_bytes((root/'app/helpers/ProgramaEducativo.php').read_bytes())
config=out/'config/db_connection.php';config.parent.mkdir()
source=str(root/'config/db_connection.php').replace('\\','/').replace("'","\\'")
config.write_text("<?php require_once '"+source+"';\n",encoding='utf-8')
with tempfile.TemporaryDirectory(dir=str(out)) as folder:
    import subprocess
    for item in (file, helper):
        result=subprocess.run(['php','-l',str(item)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError('PHP inválido '+str(item)+': '+result.stderr.decode('utf-8','replace'))
print('MODELO DE PRUEBA VALIDADO: '+str(file))
