"""Genera exclusivamente para pruebas el modelo histórico PR114 conciliado con V3."""
import argparse
import importlib.util
import subprocess
import tempfile
from pathlib import Path

root=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publisher',root/'tools/publicar_ajustes_aliados_v3.py')
publisher=importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output',required=True)
args=parser.parse_args()
out=Path(args.output).resolve()
if out.exists():raise RuntimeError('Se requiere un directorio de pruebas nuevo')
name='app/models/ConvocatoriaModel.php'
def git(ref):return subprocess.check_output(['git','show',ref+':'+name],cwd=str(root))
candidate=publisher.overlay_convocatoria_model(git('20ec4fb64f211b06cde114da76d6c84e65a96db5'),git(publisher.BASE),git(publisher.SOURCE))
out.mkdir(parents=True)
file=out/name;file.parent.mkdir(parents=True);file.write_bytes(candidate)
config=out/'config/db_connection.php';config.parent.mkdir()
source=str(root/'config/db_connection.php').replace('\\','/').replace("'","\\'")
config.write_text("<?php require_once '"+source+"';\n",encoding='utf-8')
with tempfile.TemporaryDirectory(dir=str(out)) as folder:
    publisher.validate(name,candidate,Path(folder),None)
print('MODELO DE PRUEBA VALIDADO: '+str(file))
