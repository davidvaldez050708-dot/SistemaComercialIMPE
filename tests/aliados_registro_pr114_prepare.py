"""Construye una réplica aislada PR114 y aplica exclusivamente la entrega revisada."""
import argparse
import io
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

root=Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('--output',required=True)
args=parser.parse_args()
out=Path(args.output).resolve()
if out.exists():
    raise RuntimeError('La réplica debe ser un directorio nuevo')
ref='20ec4fb64f211b06cde114da76d6c84e65a96db5'
archive=subprocess.check_output(['git','archive','--format=zip',ref],cwd=str(root))
out.mkdir(parents=True)
with zipfile.ZipFile(io.BytesIO(archive)) as files:
    for entry in files.infolist():
        if entry.filename.startswith('vendor/'):
            continue
        dest=(out/entry.filename).resolve()
        if out not in dest.parents:
            raise RuntimeError('Ruta fuera de la réplica')
        files.extract(entry,out)
# La dependencia completa local/CI reemplaza el vendor parcial versionado.
shutil.copytree(str(root/'vendor'),str(out/'vendor'))
for pattern in ['aliados_registro*.php','aliados_registro*.cjs']:
    for file in (root/'tests').glob(pattern):
        shutil.copy2(str(file),str(out/'tests'/file.name))
shutil.copy2(str(root/'tests/formulario_aliados_fixture.php'),str(out/'tests/formulario_aliados_fixture.php'))
subprocess.check_call([sys.executable,str(root/'tools/publicar_aliados_registro.py'),'--apply','--site',str(out),'--backup-root',str(out.parent/(out.name+'-respaldos'))],cwd=str(root))
print('REPLICA PR114 PREPARADA: '+str(out))
