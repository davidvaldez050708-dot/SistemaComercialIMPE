"""Entrega selectiva de la vista del historial contrastados con Hostinger."""
import base64,gzip,hashlib,json,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='b56ca6ae406ba68d45daf2338f95926194e7c19c'
FILES={'app/views/telefonia/ventas_llamadas.php':'4759a27515922777c33d7c3c33ad9604ec3a7151de8776f3c754ff91bb37630d'}

def blob(ref,path):return subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT).replace(b'\r\n',b'\n')
def sha(data):return hashlib.sha256(data).hexdigest()
def packed(data):return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')
def main():
 target=sys.argv[1];rows=[]
 for path,expected in FILES.items():
  before=blob(BASE,path);after=blob(target,path)
  assert sha(before)==expected,(path,sha(before))
  rows.append({'path':path,'verified_hostinger_sha':expected,'variants':[
   {'before':packed(before),'before_sha':sha(before),'after':packed(after),'after_sha':sha(after)}]})
 (ROOT/'tools/boton_grabaciones_release.json').write_text(json.dumps(
  {'version':1,'base':BASE,'target':target,'files':rows},ensure_ascii=False,indent=2)+'\n',encoding='utf8')
 print('Una vista; sin cambios a grabaciones, servicios, JavaScript ni SQL.')
if __name__=='__main__':main()
