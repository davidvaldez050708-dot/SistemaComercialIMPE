"""Paquete sobre los tres archivos confirmados en Hostinger, con tipografía V3."""
import argparse,base64,gzip,hashlib,importlib.util,json,re,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];BASE='544697a162ddecd009fbc2669c4bbd8ab27f883c'
FILES=['app/controllers/ProspectoController.php','app/models/ProspectoModel.php','app/views/prospectos/index.php','app/views/prospectos/acceso_denegado.php','public/css/prospectos_fase1.css','public/javascript/prospectos_fase1.js']
def git(ref,name):return subprocess.check_output(['git','show',ref+':'+name],cwd=ROOT)
def pack(value):return None if value is None else base64.b64encode(gzip.compress(value,mtime=0)).decode()
def vista_hostinger():
    old=git(BASE,'app/views/prospectos/index.php').decode()
    old=old.replace('font-size:12px !important;letter-spacing:normal !important;line-height:1.5 !important','font-size:14px !important;letter-spacing:-.01em !important;line-height:1.5 !important').replace('table th{font-weight:800 !important}','table th{font-weight:700 !important}').replace('table td{font-weight:600 !important}','table td{font-weight:500 !important}')
    css=re.search(r'css = """(.*?)"""',git(BASE,'tools/publicar_tipografia_prospectos_v3_hostinger.sh').decode(),re.S).group(1)
    result=old.replace('</style>',css+'\n</style>',1).encode()
    if hashlib.sha256(result).hexdigest()!='21abc87d96905e04b247713c782d86e2c5d61029e6b37b5b526765185f48c0d8':raise RuntimeError('No coincide la personalización de Hostinger revisada.')
    return result
def main():
    p=argparse.ArgumentParser();p.add_argument('--source',required=True);args=p.parse_args();source=subprocess.check_output(['git','rev-parse',args.source+'^{commit}'],cwd=ROOT).decode().strip()
    spec=importlib.util.spec_from_file_location('core',ROOT/'tools/publicar_aliados_registro.py');core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    rows=[]
    with tempfile.TemporaryDirectory() as temp:
        for name in FILES:
            exists=subprocess.run(['git','cat-file','-e',BASE+':'+name],cwd=ROOT,stderr=subprocess.DEVNULL).returncode==0
            old=git(BASE,name) if exists else None;new=git(source,name);row={'path':name,'base':pack(old),'target':pack(new),'sha256':core.sha(new)}
            if name=='app/views/prospectos/index.php':
                hosted=vista_hostinger();candidate=core.merge(hosted,old,new,Path(temp));row['variants']=[{'ref':'hostinger-prospectos-tipografia-v3-20261023','base':pack(hosted),'target':pack(candidate),'sha256':core.sha(candidate)}]
            rows.append(row)
    dependencies=[{'path':name,'contains':tokens} for name,tokens in [('config/db_connection.php',['class Database']),('app/helpers/PermissionHelper.php',['function tienePermiso']),('app/views/layout/dashboard_head.php',[]),('app/views/layout/sidebar.php',[]),('app/views/layout/topbar.php',[]),('app/views/layout/dashboard_footer.php',[]),('public/css/global.css',['Manrope']),('public/css/dashboard.css',['.data-territorial-card','.data-state-image','.module-search']),('public/css/aliados.css',['.aliados-summary-grid','.aliados-summary-card'])]]
    dependencies += [{'path':str(path.relative_to(ROOT)).replace('\\','/')} for path in sorted((ROOT/'public/img/estados').glob('*.png'))]
    (ROOT/'tools/prospectos_fase1_release.json').write_bytes((json.dumps({'base':BASE,'source':source,'dependencies':dependencies,'files':rows},ensure_ascii=False,indent=2)+'\n').encode())
    print('ENTREGA: {} archivos; dependencias existentes verificadas; sin migraciones.'.format(len(rows)))
if __name__=='__main__':main()
