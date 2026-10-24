"""Entrega visual de tres archivos, sin SQL ni cambios de permisos."""
import argparse,base64,gzip,hashlib,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='43b38b48a7d8e4c85bc739302e04cac3f6656520'
FILES=['public/css/aliados_avance.css','public/javascript/aliados_avance.js','app/views/aliados/estado.php']
def git(ref,name):return subprocess.check_output(['git','show',ref+':'+name],cwd=str(ROOT))
def pack(data):return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')
def main():
 p=argparse.ArgumentParser();p.add_argument('--source',required=True);p.add_argument('--output',default=str(ROOT/'tools/aliados_iconos_release.json'));args=p.parse_args()
 source=subprocess.check_output(['git','rev-parse',args.source],cwd=str(ROOT)).decode().strip()
 rows=[]
 for name in FILES:
  old,new=git(BASE,name),git(source,name);rows.append({'path':name,'base':pack(old),'target':pack(new),'sha256':hashlib.sha256(new).hexdigest()})
 Path(args.output).write_text(json.dumps({'base':BASE,'source':source,'files':rows},indent=2)+'\n',encoding='utf-8')
 print('ENTREGA: tres archivos visuales; sin SQL.')
if __name__=='__main__':main()
