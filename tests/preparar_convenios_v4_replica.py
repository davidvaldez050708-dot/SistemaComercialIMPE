"""Aplica la entrega a una réplica del Hostinger confirmado; nunca al sitio real."""
import argparse
import base64
import gzip
import io
import json
from pathlib import Path
import subprocess
import sys
import zipfile

ROOT=Path(__file__).resolve().parents[1]
def main():
    p=argparse.ArgumentParser();p.add_argument('--output',required=True);args=p.parse_args();out=Path(args.output).resolve()
    if out.exists():raise RuntimeError('La réplica debe usar una ruta nueva.')
    manifest=json.loads((ROOT/'tools/convenios_inline_v4_release.json').read_text(encoding='utf-8'))
    archive=subprocess.check_output(['git','archive','--format=zip',manifest['base'],'app','config','public','index.php'],cwd=str(ROOT))
    out.mkdir(parents=True)
    with zipfile.ZipFile(io.BytesIO(archive)) as z:
        for entry in z.infolist():
            path=(out/entry.filename).resolve()
            if out not in path.parents:raise RuntimeError('Ruta de archivo inválida')
            if entry.filename.startswith('public/uploads/'):continue
            z.extract(entry,out)
    for row in manifest['files']:
        if not row.get('variants'):continue
        dest=out/row['path'];dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(gzip.decompress(base64.b64decode(row['variants'][0]['base'])))
    for row in manifest['dependencies']:
        dest=out/row['path'];dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(gzip.decompress(base64.b64decode(row['content'])))
    # Dependencia PR114 ya instalada en Hostinger y deliberadamente fuera de la entrega.
    name='app/services/AliadoInternacionalService.php';dest=out/name
    dest.write_bytes(subprocess.check_output(['git','show','20ec4fb64f211b06cde114da76d6c84e65a96db5:'+name],cwd=str(ROOT)))
    css=out/'public/css/convocatorias_aliado_modular.css';css.parent.mkdir(parents=True,exist_ok=True);css.write_bytes((ROOT/'tests/fixtures/v3_hostinger/convocatorias_aliado_modular.css').read_bytes())
    cmd=[sys.executable,'-B',str(ROOT/'tools/publicar_convenios_inline_v4.py'),'--site',str(out),'--manifest',str(ROOT/'tools/convenios_inline_v4_release.json'),'--backup-root',str(out.parent/(out.name+'-respaldos'))]
    subprocess.check_call(cmd+['--check'],cwd=str(ROOT),stdout=subprocess.DEVNULL)
    subprocess.check_call(cmd+['--apply'],cwd=str(ROOT));print('REPLICA PRODUCTIVA V4 PREPARADA: '+str(out))
if __name__=='__main__':main()
