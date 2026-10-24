"""Paquete incremental de avance; preserva el controlador publicado en Historial."""
import argparse,base64,gzip,hashlib,importlib.util,json,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='7e85a62284951bef78b55599cba982fe8ce0a576'
FILES=['app/services/AliadoAvanceService.php','public/css/aliados_avance.css','public/javascript/aliados_avance.js','app/views/aliados/partials/avance_difusion.php','database/migrations/2026_10_23_aliados_respuestas.sql','tools/migrar_aliados_respuestas.php','app/services/AliadoHistorialService.php','app/models/AliadoModel.php','app/services/AliadoCompartirService.php','app/controllers/AliadoController.php','public/javascript/aliados.js','app/views/aliados/estado.php']
EXISTING=set(FILES[6:])
def git(ref,name):return subprocess.check_output(['git','show',ref+':'+name],cwd=str(ROOT))
def pack(data):return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')
def unpack(data):return gzip.decompress(base64.b64decode(data))
def main():
 p=argparse.ArgumentParser();p.add_argument('--source',required=True);p.add_argument('--output',default=str(ROOT/'tools/aliados_avance_release.json'));args=p.parse_args()
 args.source=subprocess.check_output(['git','rev-parse',args.source],cwd=str(ROOT)).decode().strip()
 spec=importlib.util.spec_from_file_location('core',str(ROOT/'tools/publicar_aliados_registro.py'));core=importlib.util.module_from_spec(spec);spec.loader.exec_module(core)
 previous=json.loads(git(BASE,'tools/aliados_historial_release.json'));controller=next(r for r in previous['files'] if r['path']=='app/controllers/AliadoController.php')
 host=unpack(controller['variants'][0]['target']);assert core.sha(host)==controller['variants'][0]['sha256']
 rows=[]
 with tempfile.TemporaryDirectory() as temp:
  for name in FILES:
   old=git(BASE,name) if name in EXISTING else None;new=git(args.source,name)
   row={'path':name,'base':pack(old) if old is not None else None,'target':pack(new),'sha256':core.sha(new)}
   if name=='app/controllers/AliadoController.php':
    merged=core.merge(host,old,new,Path(temp));row['variants']=[{'ref':'hostinger-historial-publicado-20261023','base':pack(host),'target':pack(merged),'sha256':core.sha(merged)}]
   rows.append(row)
 Path(args.output).write_text(json.dumps({'base':BASE,'source':args.source,'files':rows},indent=2)+'\n',encoding='utf-8')
 print('ENTREGA: 12 archivos. Instalación SQL aditiva mediante herramienta explícita.')
if __name__=='__main__':main()
