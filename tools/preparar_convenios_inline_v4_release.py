"""Entrega V4; bases productivas verificadas por diff y SHA256, sin SQL automático."""
import argparse
import base64
import gzip
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
def module(name,path):
    spec=importlib.util.spec_from_file_location(name,str(path));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
host=module('hostinger_v4',ROOT/'tests/preparar_convenios_v4_hostinger.py')
core=module('publicador_v4_core',ROOT/'tools/publicar_aliados_registro.py')
def git(ref,name):return subprocess.check_output(['git','show',ref+':'+name],cwd=str(ROOT))
def pack(data):return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')
def base(ref,name):
    if subprocess.run(['git','cat-file','-e',ref+':'+name],cwd=str(ROOT),stdout=subprocess.PIPE,stderr=subprocess.PIPE).returncode:return None
    return git(ref,name)
def main():
    p=argparse.ArgumentParser();p.add_argument('--source',required=True);p.add_argument('--output',default=str(ROOT/'tools/convenios_inline_v4_release.json'));args=p.parse_args()
    reviewed=host.bases();rows=[]
    names=subprocess.check_output(['git','diff','--name-only',host.BASE,args.source],cwd=str(ROOT)).decode().splitlines()
    names=[name for name in names if name.startswith(('app/','public/')) or name in ['tools/migrar_convocatoria_borradores_v4.php','database/migrations/2026_10_23_convocatoria_borradores_v4.sql']]
    with tempfile.TemporaryDirectory() as tmp:
        for name in names:
            old=base(host.BASE,name);new=git(args.source,name)
            row={'path':name,'base':pack(old) if old is not None else None,'target':pack(new),'sha256':core.sha(new)}
            if name in reviewed:
                original=reviewed[name]
                try:candidate=core.merge(original,old,new,Path(tmp))
                except RuntimeError:
                    # La única adición del modelo es el acceso a la conexión del lote.
                    if name!='app/models/ConvocatoriaModel.php':raise
                    anchor=b'    public function iniciarLote(): void { $this->connection->begin_transaction(); }\n'
                    if original.count(anchor)!=1:raise
                    candidate=original.replace(anchor,anchor+b'    public function conexionLote(): mysqli { return $this->connection; }\n',1)
                row['variants']=[{'ref':'hostinger-confirmado-20261023','base':pack(original),'target':pack(candidate),'sha256':core.sha(candidate)}]
            rows.append(row)
    dependencies=[]
    files={
      'app/services/AliadoRegistroService.php':git(host.BASE,'app/services/AliadoRegistroService.php'),
      'public/javascript/crear_convocatoria_v2_extension.js':git(host.BASE,'public/javascript/crear_convocatoria_v2_extension.js'),
      'public/javascript/convocatorias_aliado_modular.js':(ROOT/'tests/fixtures/v3_hostinger/convocatorias_aliado_modular.js').read_bytes(),
      'app/views/convocatorias/partials/aliado_programas_modular.php':(ROOT/'tests/fixtures/v4_hostinger/aliado_programas_modular.php').read_bytes(),
    }
    previous=json.loads((ROOT/'tools/convenios_detalle_etapa3_release.json').read_text(encoding='utf-8'))
    row=next(r for r in previous['files'] if r['path']=='public/javascript/crear_convocatoria.js')
    files[row['path']]=core.unpack(next(v for v in row['variants'] if v['ref']=='hostinger-internacionales-20261022')['target'])
    expected=['3c087ddda6dccc97b59ba933a72ba005fe473a72b812d8ff303d9c7f710cc6a7','a6cf848b19d8b3e8e0883225bf32e04007111e57a314e82eee1d8f4f6cc3f409','a5793ad75306759ee95e8d3c35f8431e34eb21929fde400743d55935dbae005e','f26daaaf3606428dbe01a899637d28632f784c4a98ffb7937a563853be9295a7','092665e65ea8a453ef19e9c9e3c2e03ed2215611480ebe33852a1c8936263672']
    for (name,data),sha in zip(files.items(),expected):
        data=core.lf(data)
        if core.sha(data)!=sha:raise RuntimeError('La dependencia no coincide con el hash comunicado: '+name)
        dependencies.append({'path':name,'sha256':sha,'content':pack(data)})
    # El enlace del directorio se habilita al final, con todos los recursos ya instalados.
    rank=lambda row:(5 if row['path']=='app/views/convocatorias/crear_desde_convenio.php' else 0 if row['path']=='app/models/ConvocatoriaModel.php' else 1 if row['base'] is None else 3,row['path'])
    rows.sort(key=rank)
    Path(args.output).write_text(json.dumps({'base':subprocess.check_output(['git','rev-parse',host.BASE],cwd=str(ROOT)).decode().strip(),'source':args.source,'files':rows,'dependencies':dependencies},indent=2)+'\n',encoding='utf-8')
    print('ENTREGA V4: {} archivos, {} dependencias conservadas. SQL explícito separado.'.format(len(rows),len(dependencies)))
if __name__=='__main__':main()
