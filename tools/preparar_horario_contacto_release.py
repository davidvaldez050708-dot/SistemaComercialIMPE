"""Construye el manifiesto reproducible con bytes Git y variantes ya auditadas."""
import base64,gzip,hashlib,json,subprocess,sys
from pathlib import Path
from horario_contacto_transform import controller,model
from publicar_horario_contacto import FILES
ROOT=Path(__file__).resolve().parents[1]
BASE='f5670703f2fe670d0ff065bfe3eb5800a2b516b4'
def git(ref,path):return subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT,stderr=subprocess.DEVNULL)
def pack(b):return base64.b64encode(gzip.compress(b,mtime=0)).decode()
def sha(b):return hashlib.sha256(b).hexdigest()
def make(source):
    source=subprocess.check_output(['git','rev-parse',source],cwd=ROOT).decode().strip()
    data={'base':BASE,'source':source,'files':[], 'dependencies':[
        {'path':'public/javascript/formularios_registro_publico.js','contains':['data-formulario-registro-publico','new FormData(form)']},
        {'path':'public/javascript/formularios_registro.js','contains':['data-formulario-registro','new FormData(form)']},
        {'path':'public/css/formularios_publico.css','contains':['formulario-publico-field']},
        {'path':'public/css/formularios.css','contains':['formularios-registration']},
        {'path':'public/javascript/prospectos_fase1.js','contains':['data-prospectos-filtros']},
    ]}
    previous=json.loads(git(BASE,'tools/bachillerato_unificacion_release.json'))
    phase2=json.loads(git(BASE,'tools/prospectos_fase2_release.json'))
    for path in sorted(FILES):
        target=git(source,path)
        try:base=git(BASE,path)
        except subprocess.CalledProcessError:base=None
        row={'path':path,'sha256':sha(target),'base':pack(base) if base is not None else None,'target':pack(target)}
        if path in ['app/controllers/FormularioPublicoController.php','app/models/FormularioRegistroModel.php']:
            old=next(r for r in previous['files'] if r['path']==path)
            variant=next(v for v in old['variants'] if v['ref']=='formularios-internacionales-pr114-registro')
            hosted=gzip.decompress(base64.b64decode(variant['target']))
            reviewed=(controller if 'Controller' in path else model)(hosted.decode()).encode()
            row['variants']=[{'ref':'Hostinger-2026-10-24-PR114-conservado','base':pack(hosted),'target':pack(reviewed),'sha256':sha(reviewed)}]
        if path=='app/views/prospectos/index.php':
            old=next(r for r in phase2['files'] if r['path']==path)
            hosted=gzip.decompress(base64.b64decode(old['variants'][0]['target']))
            assert sha(hosted)=='f53c9757e416dae3cab64c39c7ec8b1c0b035d796bf2fc03926f5d685697ae7b'
            # Reconciliación de la tipografía V3 sin tocar las celdas nuevas.
            import tempfile
            with tempfile.TemporaryDirectory() as d:
                for name,b in [('local',hosted),('base',base),('target',target)]:Path(d,name).write_bytes(b)
                reviewed=subprocess.check_output(['git','merge-file','-p',str(Path(d,'local')),str(Path(d,'base')),str(Path(d,'target'))])
            row['variants']=[{'ref':'Hostinger-Fase2-tipografia-V3','base':pack(hosted),'target':pack(reviewed),'sha256':sha(reviewed)}]
        data['files'].append(row)
    Path(ROOT,'tools/horario_contacto_release.json').write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n',encoding='utf-8',newline='\n')
if __name__=='__main__':make(sys.argv[1])
