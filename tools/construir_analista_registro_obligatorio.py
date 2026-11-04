"""Paquete reproducible desde las dos versiones públicas comprobadas en Hostinger."""
from pathlib import Path
import subprocess,hashlib,gzip,base64,json,re
R=Path(__file__).resolve().parents[1]
BASE='6db736ac1c88cef50b5ffabdebf1b6708b6878cc'
def blob(p):return subprocess.check_output(['git','show',BASE+':'+p],cwd=str(R))
def sha(b):return hashlib.sha256(b).hexdigest()
def pack(b):
 z=bytearray(gzip.compress(b,mtime=0));z[9]=255;return base64.b64encode(z).decode()
paths=['public/javascript/seguimiento_llamada_registro_obligatorio.js','public/javascript/seguimiento_llamada_zadarma.js']
rows=[]
for p in paths:
 old=blob(p);target=(R/p).read_bytes().replace(b'\r\n',b'\n')
 rows.append({'path':p,'variants':[{'before':sha(old),'after':sha(target),'base':pack(old),'target':pack(target)}]})
raw=(json.dumps({'schema':1,'package':'analista-registro-obligatorio','files':rows},indent=2)+'\n').encode()
(R/'tools/analista_registro_obligatorio_release.json').write_bytes(raw)
s=blob('tools/publicar_verificacion_vigente.py').decode('utf8')
s=re.sub(r"MANIFEST_SHA256 = '[a-f0-9]{64}'","MANIFEST_SHA256 = '"+sha(raw)+"'",s)
a=s.index('PATHS = ');b=s.index('\n\n',a);s=s[:a]+'PATHS = '+repr(paths)+s[b:]
s=s.replace('verificacion_vigente_release.json','analista_registro_obligatorio_release.json').replace('verificacion-vigente','analista-registro-obligatorio').replace('de verificación vigente','del registro obligatorio del Analista').replace('control de verificaciones vigentes','registro obligatorio del Analista')
(R/'tools/publicar_analista_registro_obligatorio.py').write_text(s,encoding='utf8',newline='\n')
print('Manifest:',sha(raw))
