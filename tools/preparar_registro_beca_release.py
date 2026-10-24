import base64,gzip,hashlib,json,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];BASE='31049506880ecca15c495e03b3d57a8d8dc5f63d'
def git(ref,path):return subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT,stderr=subprocess.DEVNULL)
def pack(b):return base64.b64encode(gzip.compress(b,mtime=0)).decode()
source=subprocess.check_output(['git','rev-parse',sys.argv[1]],cwd=ROOT).decode().strip()
order=['registro/obtener-beca/index.php','registro/obtener-beca/.htaccess','app/services/RegistroEnlaceService.php','public/javascript/registro_enlaces_amigables.js','public/javascript/convocatorias_gestion_v5.js']
data={'base':BASE,'source':source,'files':[],'dependencies':[{'path':'registro/el-primer-paso-hacia-tu-meta-comienza-aqui/index.php','contains':['->resolver($code)']},{'path':'registro/el-primer-paso-hacia-tu-meta-comienza-aqui/.htaccess','contains':['index.php?codigo=$1']}]}
for name in order:
    target=git(source,name)
    try:base=git(BASE,name)
    except subprocess.CalledProcessError:base=None
    data['files'].append({'path':name,'base':pack(base) if base else None,'target':pack(target),'sha256':hashlib.sha256(target).hexdigest()})
(ROOT/'tools/registro_beca_release.json').write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n',encoding='utf8',newline='\n')
