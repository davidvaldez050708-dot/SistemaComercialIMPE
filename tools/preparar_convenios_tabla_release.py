"""Paquete visual de cinco archivos sobre las cuatro versiones confirmadas de Hostinger."""
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
BASE='544697a162ddecd009fbc2669c4bbd8ab27f883c'
FILES=['public/css/convenios_directorio_tabla.css','public/javascript/crear_convocatoria.js','public/javascript/crear_convocatoria_v2_extension.js','public/javascript/convenios_borradores_v4.js','app/views/convocatorias/crear_desde_convenio.php']
EXPECTED={
 'app/views/convocatorias/crear_desde_convenio.php':'c28ccd2c963bb44103603c76a9c09d4ad8a6454b6fac572470cca959dc244be0',
 'public/javascript/crear_convocatoria.js':'092665e65ea8a453ef19e9c9e3c2e03ed2215611480ebe33852a1c8936263672',
 'public/javascript/crear_convocatoria_v2_extension.js':'ac2393a61367c073b8a1d012d7dd33a03d750e970c8d68fab88faf184304d760',
 'public/javascript/convenios_borradores_v4.js':'65112dd437d8106ebcde5bef41ac7e46b4b973271fead425d04fa1fe9dd23a03'
}
def pack(data): return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')
def unpack(value): return gzip.decompress(base64.b64decode(value))
def git(ref,name): return subprocess.check_output(['git','show',ref+':'+name],cwd=str(ROOT))
def reviewed():
    found={}
    for file in ROOT.joinpath('tools').glob('*release.json'):
        if file.name=='convenios_tabla_release.json': continue
        for row in json.loads(file.read_text(encoding='utf-8')).get('files',[]):
            if row['path'] not in EXPECTED: continue
            for item in [row]+row.get('variants',[]):
                for key in ['base','target']:
                    if not item.get(key): continue
                    data=unpack(item[key]).replace(b'\r\n',b'\n')
                    if hashlib.sha256(data).hexdigest()==EXPECTED[row['path']]: found[row['path']]=data
    name='public/javascript/convenios_borradores_v4.js'
    data=(ROOT/'tests/fixtures/convenios_tabla_hostinger/convenios_borradores_v4.js').read_bytes().replace(b'\r\n',b'\n')
    if hashlib.sha256(data).hexdigest()!=EXPECTED[name]: raise RuntimeError('El personalizador QR no coincide con Hostinger.')
    found[name]=data
    if set(found)!=set(EXPECTED): raise RuntimeError('Falta una base productiva revisada.')
    return found
def candidate(core,name,original,new,temp):
    if name=='public/javascript/crear_convocatoria.js':
        # Esta variante antigua delega municipio/territorios a V2. Añadir sólo
        # la agrupación tabular, conservando literalmente el resto de su código.
        if core.sha(original)!=EXPECTED[name]: raise RuntimeError('Variante JS no revisada.')
        start=new.index(b"        // No mostrar encabezados de municipio")
        end=new.index(b'\n        updateClearFilters();',start)
        anchor=b'        updateClearFilters();\n    };'
        if original.count(anchor)!=1: raise RuntimeError('Ancla de filtro ambigua.')
        return original.replace(anchor,new[start:end]+b'\n'+anchor,1)
    return core.merge(original,git(BASE,name),new,temp)
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--source',required=True);parser.add_argument('--output',default=str(ROOT/'tools/convenios_tabla_release.json'));args=parser.parse_args()
    spec=importlib.util.spec_from_file_location('core',str(ROOT/'tools/publicar_aliados_registro.py'));core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    bases=reviewed();rows=[]
    with tempfile.TemporaryDirectory() as temp:
        for name in FILES:
            old=None if name.endswith('convenios_directorio_tabla.css') else git(BASE,name)
            new=git(args.source,name)
            row={'path':name,'base':pack(old) if old is not None else None,'target':pack(new),'sha256':core.sha(new)}
            if name in bases and bases[name]!=old:
                merged=candidate(core,name,bases[name],new,Path(temp))
                row['variants']=[{'ref':'hostinger-confirmado-tabla-20261023','base':pack(bases[name]),'target':pack(merged),'sha256':core.sha(merged)}]
            rows.append(row)
    Path(args.output).write_text(json.dumps({'base':BASE,'source':args.source,'files':rows},indent=2)+'\n',encoding='utf-8')
    print('ENTREGA: cinco archivos visuales. QR personalizado preservado. Sin SQL.')
if __name__=='__main__': main()
