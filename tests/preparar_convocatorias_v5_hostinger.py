"""Reconstruye exclusivamente las variantes confirmadas por SSH y sus hashes."""
import base64,gzip,hashlib,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='d1104ae4e024f6cfbeccd2a4a4bfa558faa86704'
def git(ref,name):return subprocess.check_output(['git','show',ref+':'+name],cwd=ROOT)
def bases():
    old=json.loads((ROOT/'tools/convenios_inline_v4_release.json').read_text())
    result={row['path']:gzip.decompress(base64.b64decode(row['variants'][0]['target'])) for row in old['files'] if row.get('variants') and row['path'] in ['app/controllers/ConvocatoriaController.php','app/models/ConvocatoriaModel.php']}
    name='app/views/convocatorias/index.php';index=git('bdab4900',name).decode();reference=git(BASE,name).decode()
    a=index.index('$clasificacionesConvocatoria = [');b=index.index('if ($esChihuahua)',a)
    c=reference.index('$clasificacionesConvocatoria = [');d=reference.index('if ($esChihuahua)',c)
    index=index[:a]+reference[c:d]+index[b:]
    index=index.replace("<?php require __DIR__ . '/partials/crear_modal.php'; ?>","<?php require __DIR__ . '/partials/crear_modal.php'; ?>\n<?php require __DIR__ . '/partials/aliado_programas_modular.php'; ?>")
    result[name]=index.encode()
    for name,sha in {'app/controllers/ConvocatoriaController.php':'8101532afa2de84b1016fe9c8068e10cdf6eac47e64574caa5786a4e629983fd','app/models/ConvocatoriaModel.php':'8d5e651eacfa85b8588dafc69b3c8f06b6a2f80daa3035cfdd77a779d74513ba','app/views/convocatorias/index.php':'f3062f352b1ca85fe26022cea20d2c46f90db1d065646e1409594a630657dbe4'}.items():
        if hashlib.sha256(result[name]).hexdigest()!=sha:raise RuntimeError('Variante no confirmada: '+name)
    return result
if __name__=='__main__':
    for name,data in bases().items():print(hashlib.sha256(data).hexdigest(),name)
