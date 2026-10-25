"""Entrega selectiva de tres archivos contrastados con Hostinger."""
import base64,gzip,hashlib,json,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='e1ba3df114a4a7df330040a8caa3a90129cb26f9'
FILES={
 'app/services/TelefoniaGrabacionesTransferenciaVentasService.php':'dc2b9e3543cf579358680c14dcd54c4e05ec4789240ee58b9790f19303cd3c93',
 'app/views/telefonia/ventas_llamadas.php':'4473a81eb9caf62badad77dbbac2555f04460fbb41f082ee047540ab63a54367',
 'public/javascript/telefonia_ventas_resultados.js':'bd72658bf0b3d670a3cf266e7ddb2ac70be1ed02ea927b52d035ea20f053e452'}
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
 (ROOT/'tools/grabaciones_entrantes_release.json').write_text(json.dumps(
  {'version':1,'base':BASE,'target':target,'files':rows},ensure_ascii=False,indent=2)+'\n',encoding='utf8')
 print('Tres archivos verificados; sin SQL ni modificación del motor telefónico.')
if __name__=='__main__':main()
