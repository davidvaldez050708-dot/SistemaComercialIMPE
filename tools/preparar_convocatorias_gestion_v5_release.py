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
    parser=argparse.ArgumentParser();parser.add_argument('--source',required=True);mode=parser.add_mutually_exclusive_group();mode.add_argument('--correcciones',action='store_true');mode.add_argument('--qr',action='store_true');mode.add_argument('--enlaces-cc',action='store_true');args=parser.parse_args();reviewed=host.bases();rows=[]
    base=host.BASE;names=FILES
    if args.correcciones:
        base='722d3125d6a8e0e3e337c90dac18e36111715cb1'
        previous=json.loads((ROOT/'tools/convocatorias_gestion_v5_release.json').read_text(encoding='utf-8'))
        reviewed={r['path']:core.unpack(r['variants'][0]['target']) for r in previous['files'] if r.get('variants')}
        names=['public/css/convocatorias_aliado_multiprograma_v3.css','public/css/convocatorias_gestion_v5.css','public/javascript/convocatorias_gestion_v5.js','public/javascript/crear_convocatoria_v2_extension.js','app/views/convocatorias/index.php']
    if args.qr:
        base='a7b49dfdf95ef3bc684624a561bd3643411e4f5c'
        previous=json.loads((ROOT/'tools/convocatorias_gestion_v5_ui_release.json').read_text(encoding='utf-8'))
        reviewed={r['path']:core.unpack(r['variants'][0]['target']) for r in previous['files'] if r.get('variants')}
        names=['public/css/convocatorias_gestion_v5.css','public/javascript/convocatorias_gestion_v5.js','app/views/convocatorias/index.php']
    if args.enlaces_cc:
        base='ebe671564cd9a33c268156f2cb1a5e6fdae4170a'
        previous=json.loads((ROOT/'tools/convocatorias_gestion_v5_release.json').read_text(encoding='utf-8'))
        reviewed={r['path']:core.unpack(r['variants'][0]['target']) for r in previous['files'] if r.get('variants')}
        names=['app/services/AliadoRegistroService.php','app/controllers/ConvocatoriaController.php']
    with tempfile.TemporaryDirectory() as temp:
        for name in names:
            old=None if not (args.correcciones or args.qr or args.enlaces_cc) and name in FILES[:3] else host.git(base,name)
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
    output='convocatorias_gestion_v5_ui_release.json' if args.correcciones else 'convocatorias_gestion_v5_release.json'
    if args.qr:output='convocatorias_gestion_v5_qr_release.json'
    if args.enlaces_cc:output='aliado_enlaces_cuenta_clave_release.json';dependencies=[]
    (ROOT/'tools'/output).write_text(json.dumps({'base':base,'source':args.source,'files':rows,'dependencies':dependencies},indent=2)+'\n',encoding='utf-8')
    print('ENTREGA: {} archivos, {} dependencias comprobadas. Sin SQL.'.format(len(rows),len(dependencies)))
if __name__=='__main__':main()
