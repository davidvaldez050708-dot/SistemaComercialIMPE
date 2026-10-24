"""Réplica de sólo los archivos de interfaz, sin credenciales ni conexiones de BD."""
import argparse
import importlib.util
from pathlib import Path
import tempfile

ROOT=Path(__file__).resolve().parents[1]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,str(path));module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
def main():
    p=argparse.ArgumentParser();p.add_argument('--output',required=True);args=p.parse_args();out=Path(args.output).resolve()
    if out.exists(): raise RuntimeError('Usar una ruta nueva para la réplica.')
    release=load('release',ROOT/'tools/preparar_convenios_tabla_release.py');core=load('core',ROOT/'tools/publicar_aliados_registro.py');reviewed=release.reviewed()
    # Sólo vistas, helpers y assets de prueba; no se copia config ni uploads.
    import shutil
    for relative in ['app/views/convocatorias','app/helpers','public/css','public/javascript','public/vendor/qrcode-generator']:
        shutil.copytree(str(ROOT/relative),str(out/relative))
    with tempfile.TemporaryDirectory() as tmp:
        for name in release.FILES:
            if name in reviewed:
                data=release.candidate(core,name,reviewed[name],(ROOT/name).read_bytes().replace(b'\r\n',b'\n'),Path(tmp));(out/name).write_bytes(data)
    print(out)
if __name__=='__main__': main()
