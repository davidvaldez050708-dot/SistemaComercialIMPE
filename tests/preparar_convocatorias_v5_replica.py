"""Construye réplica con las variantes V4 confirmadas, aplica sólo el diff V5."""
import argparse,importlib.util,subprocess,sys,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,path);m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
host=module('host_v5',ROOT/'tests/preparar_convocatorias_v5_hostinger.py')
core=module('core_v5',ROOT/'tools/publicar_aliados_registro.py')
FILES=['app/controllers/ConvocatoriaController.php','app/models/ConvocatoriaModel.php','app/views/convocatorias/index.php','public/javascript/crear_convocatoria_v2_extension.js','app/services/ConvocatoriaOrigenService.php','public/javascript/convocatorias_gestion_v5.js','public/css/convocatorias_gestion_v5.css']
def prepare(out,refresh=False):
    if not refresh:subprocess.check_call([sys.executable,'-B',str(ROOT/'tests/preparar_convenios_v4_replica.py'),'--output',str(out)],stdout=subprocess.DEVNULL)
    elif out.name!='v5-hostinger-replica' or out.parent!=ROOT.parent:raise RuntimeError('Actualizar sólo la réplica local de pruebas.')
    reviewed=host.bases()
    with tempfile.TemporaryDirectory() as temp:
        for name in FILES:
            new=(ROOT/name).read_bytes()
            if name in reviewed:
                new=core.merge(reviewed[name],host.git(host.BASE,name),new,Path(temp))
            dest=out/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(new)
    for name in ['app/views/convocatorias/partials/crear_modal.php','public/javascript/convocatoria_crear_compartido.js']:
        dest=out/name;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(host.git('bdab4900',name))
    return out
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--output',required=True);p.add_argument('--refresh',action='store_true');args=p.parse_args();print(prepare(Path(args.output).resolve(),args.refresh))
