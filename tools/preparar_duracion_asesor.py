"""Entrega selectiva de duración propia del asesor contrastados con Hostinger."""
import base64,gzip,hashlib,json,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='41ae0d6cdbb0c574b41b1c4e90e2697b7415e80d'
FILES={'app/services/TelefoniaPbxSegmentosService.php': 'eab5e92e9ed62cab0b75cf44f5a010d47d8446ff67d98f266da57d71fab24946', 'app/services/TelefoniaGrabacionesTransferenciaVentasService.php': '2a376315f2cd750374dfa59bb6a6f9a5936c4bea1fdc541d2c6fd4587f5e7525', 'app/services/TelefoniaVentasEvidenciaService.php': '2c36e459150cf48587943530bf9dda8a02bf5a5128462700f48e59c518887ee7', 'public/javascript/telefonia_ventas_resultados.js': '0f6ac785843fd96161c927fb375540cd0d28848f0b26171b69d30a0ef55cbc12'}

def blob(ref,path):return subprocess.check_output(['git','show',ref+':'+path],cwd=ROOT).replace(b'\r\n',b'\n')
def sha(data):return hashlib.sha256(data).hexdigest()
def packed(data):return base64.b64encode(gzip.compress(data,mtime=0)).decode('ascii')
def main():
 target=sys.argv[1];rows=[]
 for path,expected in FILES.items():
  before=blob(BASE,path);after=blob(target,path)
  assert sha(before)==expected,(path,sha(before))
  rows.append({'path':path,'baseline_sha':expected,'variants':[
   {'before':packed(before),'before_sha':sha(before),'after':packed(after),'after_sha':sha(after)}]})
 (ROOT/'tools/duracion_asesor_release.json').write_text(json.dumps(
  {'version':1,'base':BASE,'target':target,'files':rows},ensure_ascii=False,indent=2)+'\n',encoding='utf8')
 print('Cuatro archivos; conserva la vista, resultados y archivos de grabación.')
if __name__=='__main__':main()
