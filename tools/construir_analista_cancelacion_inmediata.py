"""Dos JavaScript; base Git y variantes públicas comprobadas, sin cambios de diseño."""
import base64,gzip,hashlib,json,pathlib,re,subprocess
R=pathlib.Path(__file__).resolve().parents[1]
BASE='1f82798e3c4b6ec7362f8858af768a69d483fc61'
FILES=['public/javascript/seguimiento_llamada_registro_obligatorio.js','public/javascript/seguimiento_llamada_zadarma.js']
def sha(b):return hashlib.sha256(b).hexdigest()
def pack(b):
 z=bytearray(gzip.compress(b,mtime=0));z[9]=255;return base64.b64encode(z).decode()
def blob(p):return subprocess.check_output(['git','show',BASE+':'+p],cwd=str(R))
host=json.loads((R/'tests/fixtures/analista_cancelacion_hostinger.json').read_text(encoding='utf8'))
rows=[]
for p in FILES:
 target=(R/p).read_bytes().replace(b'\r\n',b'\n');originals=[blob(p)]
 local=gzip.decompress(base64.b64decode(host[p]['base']));assert sha(local)==host[p]['sha256'];originals.append(local)
 variants=[]
 for old in originals:
  if any(v['before']==sha(old) for v in variants):continue
  variants.append({'before':sha(old),'base':pack(old),'after':sha(target),'target':pack(target)})
 rows.append({'path':p,'variants':variants})
raw=(json.dumps({'schema':1,'package':'analista-cancelacion-inmediata','files':rows},indent=2)+'\n').encode()
(R/'tools/analista_cancelacion_inmediata_release.json').write_bytes(raw)
s=blob('tools/publicar_analista_registro_obligatorio.py').decode()
s=re.sub(r"MANIFEST_SHA256 = '[a-f0-9]{64}'","MANIFEST_SHA256 = '"+sha(raw)+"'",s)
s=s.replace('analista_registro_obligatorio_release.json','analista_cancelacion_inmediata_release.json').replace('analista-registro-obligatorio','analista-cancelacion-inmediata').replace('del registro obligatorio del Analista','del registro de cancelaciones del Analista')
s=s.replace('def apply(site, root, package):\n    with lock(root):','def apply(site, root, package):\n    prepare(site, package)\n    with lock(root):')
s=s.replace('APLICADO: registro obligatorio del Analista.', 'APLICADO: resultado guardado sin bloqueo por vinculación pendiente.')
(R/'tools/publicar_analista_cancelacion_inmediata.py').write_bytes(s.encode())
print('Manifest:',sha(raw))
