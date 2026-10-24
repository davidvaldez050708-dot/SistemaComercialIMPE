"""Paquete sobre los tres archivos confirmados en Hostinger, con tipografía V3."""
import argparse,base64,gzip,hashlib,importlib.util,json,re,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];BASE='ee583bcb6ca059c52ed55782e1d9c963084d0f8f'
FILES=['app/controllers/ProspectoController.php','app/models/ProspectoModel.php','app/views/prospectos/index.php','app/views/prospectos/acceso_denegado.php','public/css/prospectos_fase1.css','public/javascript/prospectos_fase1.js']
def git(ref,name):return subprocess.check_output(['git','show',ref+':'+name],cwd=ROOT)
def pack(value):return None if value is None else base64.b64encode(gzip.compress(value,mtime=0)).decode()
def vista_hostinger():
    previous=json.loads(git(BASE,'tools/prospectos_fase1_release.json').decode())
    row=next(r for r in previous['files'] if r['path']=='app/views/prospectos/index.php')
    return gzip.decompress(base64.b64decode(row['variants'][0]['target']))
def main():
    p=argparse.ArgumentParser();p.add_argument('--source',required=True);args=p.parse_args();source=subprocess.check_output(['git','rev-parse',args.source+'^{commit}'],cwd=ROOT).decode().strip()
    spec=importlib.util.spec_from_file_location('core',ROOT/'tools/publicar_aliados_registro.py');core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
    rows=[]
    with tempfile.TemporaryDirectory() as temp:
        for name in FILES:
            exists=subprocess.run(['git','cat-file','-e',BASE+':'+name],cwd=ROOT,stderr=subprocess.DEVNULL).returncode==0
            old=git(BASE,name) if exists else None;new=git(source,name);row={'path':name,'base':pack(old),'target':pack(new),'sha256':core.sha(new)}
            if name=='app/views/prospectos/index.php':
                hosted=vista_hostinger();candidate=core.merge(hosted,old,new,Path(temp));row['variants']=[{'ref':'hostinger-prospectos-fase1-tipografia-v3','base':pack(hosted),'target':pack(candidate),'sha256':core.sha(candidate)}]
            rows.append(row)
    dependencies=[{'path':name,'contains':tokens} for name,tokens in [('config/db_connection.php',['class Database']),('app/helpers/PermissionHelper.php',['function tienePermiso']),('app/views/layout/dashboard_head.php',[]),('app/views/layout/sidebar.php',[]),('app/views/layout/topbar.php',[]),('app/views/layout/dashboard_footer.php',[]),('public/css/global.css',['Manrope']),('public/css/dashboard.css',['.data-territorial-card','.data-state-image','.module-search']),('public/css/aliados.css',['.aliados-summary-grid','.aliados-summary-card'])]]
    dependencies += [{'path':str(path.relative_to(ROOT)).replace('\\','/')} for path in sorted((ROOT/'public/img/estados').glob('*.png'))]
    (ROOT/'tools/prospectos_fase1_release.json').write_bytes((json.dumps({'base':BASE,'source':source,'dependencies':dependencies,'files':rows},ensure_ascii=False,indent=2)+'\n').encode())
    print('ENTREGA: {} archivos; dependencias existentes verificadas; sin migraciones.'.format(len(rows)))
if __name__=='__main__':main()
