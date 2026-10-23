"""Paquete selectivo V5, con variantes productivas confirmadas por SHA256."""
import argparse,base64,gzip,hashlib,importlib.util,json,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,str(path));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
host=module('host_v5',ROOT/'tests/preparar_convocatorias_v5_hostinger.py')
core=module('core_v5',ROOT/'tools/publicar_aliados_registro.py')
FILES=['app/services/ConvocatoriaOrigenService.php','public/javascript/convocatorias_gestion_v5.js','public/css/convocatorias_gestion_v5.css','app/models/ConvocatoriaModel.php','app/controllers/ConvocatoriaController.php','public/javascript/crear_convocatoria_v2_extension.js','app/views/convocatorias/index.php']
def pack(data):return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--source',required=True);args=parser.parse_args();reviewed=host.bases();rows=[]
    with tempfile.TemporaryDirectory() as temp:
        for name in FILES:
            old=None if name in FILES[:3] else host.git(host.BASE,name)
            new=host.git(args.source,name)
            row={'path':name,'base':pack(old) if old is not None else None,'target':pack(new),'sha256':core.sha(new)}
            if name in reviewed:
                candidate=core.merge(reviewed[name],old,new,Path(temp))
                row['variants']=[{'ref':'hostinger-confirmado-20261023','base':pack(reviewed[name]),'target':pack(candidate),'sha256':core.sha(candidate)}]
            rows.append(row)
    dependencies=[]
    for name,sha in {
        'app/views/convocatorias/partials/crear_modal.php':'8ba37951f26d474bd1412aa930af44cb1e07e7700711778289c95afb29d542ae',
        'public/javascript/convocatoria_crear_compartido.js':'68a387335921fe7c0d56d5abf0b5ad9a218ec9c7aa6a632b0a67fdde0dc37391'
    }.items():
        data=host.git('bdab4900',name)
        if hashlib.sha256(data).hexdigest()!=sha:raise RuntimeError('Componente distinto del hash confirmado')
        dependencies.append({'path':name,'sha256':sha,'content':pack(data)})
    (ROOT/'tools/convocatorias_gestion_v5_release.json').write_text(json.dumps({'base':host.BASE,'source':args.source,'files':rows,'dependencies':dependencies},indent=2)+'\n',encoding='utf-8')
    print('ENTREGA V5: 7 archivos, 2 componentes modulares conservados. Sin SQL.')
if __name__=='__main__':main()
