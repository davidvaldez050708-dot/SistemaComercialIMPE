"""Entrega de Registro amigable sobre las variantes vigentes de Hostinger."""
import argparse,base64,gzip,importlib.util,json,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];BASE='32b287c7631d64f94185637d03e90bf82dc81211'
# La vista internacional fue retirada por el usuario en Hostinger. No forma
# parte de esta entrega ni debe recrearse, incluso si permanece en el historial Git.
FILES=['app/services/RegistroEnlaceService.php','registro/el-primer-paso-hacia-tu-meta-comienza-aqui/index.php','registro/el-primer-paso-hacia-tu-meta-comienza-aqui/.htaccess','tools/migrar_enlaces_registro_amigables.php','public/javascript/registro_enlaces_amigables.js','public/javascript/convocatoria_crear_compartido.js','app/controllers/FormularioController.php','app/services/AliadoRegistroService.php','app/models/ConvocatoriaModel.php','app/controllers/ConvocatoriaController.php','app/models/AliadoModel.php','app/views/layout/dashboard_head.php']
def git(ref,path):return subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT)
def pack(data):return None if data is None else base64.b64encode(gzip.compress(data,mtime=0)).decode()
def main():
    p=argparse.ArgumentParser();p.add_argument('--source',required=True);args=p.parse_args()
    spec=importlib.util.spec_from_file_location('core',ROOT/'tools/publicar_aliados_registro.py');core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    reviewed={}
    for manifest in ['convocatorias_gestion_v5_release.json','aliado_enlaces_cuenta_clave_release.json']:
        for row in json.loads((ROOT/'tools'/manifest).read_text(encoding='utf-8'))['files']:
            if row.get('variants'):reviewed[row['path']]=core.unpack(row['variants'][0]['target'])
    rows=[]
    with tempfile.TemporaryDirectory() as temp:
        for name in FILES:
            ref='bdab4900' if name=='public/javascript/convocatoria_crear_compartido.js' else BASE
            found=subprocess.run(['git','cat-file','-e',ref+':'+name],cwd=ROOT,stderr=subprocess.DEVNULL).returncode==0
            old=git(ref,name) if found else None;new=git(args.source,name)
            row={'path':name,'base':pack(old),'target':pack(new),'sha256':core.sha(new)}
            if name in reviewed:
                candidate=core.merge(reviewed[name],old,new,Path(temp));row['variants']=[{'ref':'hostinger-confirmado-20261023','base':pack(reviewed[name]),'target':pack(candidate),'sha256':core.sha(candidate)}]
            rows.append(row)
    data={'base':BASE,'source':args.source,'dependencies':[],'files':rows}
    (ROOT/'tools/registro_enlaces_release.json').write_bytes((json.dumps(data,indent=2)+'\n').encode())
    print('ENTREGA REGISTRO: {} archivos. SQL sólo mediante migración explícita.'.format(len(rows)))
if __name__=='__main__':main()
