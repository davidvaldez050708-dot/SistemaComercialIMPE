from pathlib import Path
import base64,gzip,hashlib,json,re,subprocess
R=Path(__file__).resolve().parents[1]
def blob(ref,p):return subprocess.check_output(['git','show',ref+':'+p],cwd=str(R))
def lf(p):return (R/p).read_bytes().replace(b'\r\n',b'\n')
def sha(b):return hashlib.sha256(b).hexdigest()
def pack(b):
 z=bytearray(gzip.compress(b,mtime=0));z[9]=255;return base64.b64encode(z).decode()
paths=['app/services/VerificacionInstitucionalService.php','app/services/InteraccionRutaService.php','app/controllers/SeguimientoVinculacionController.php','public/javascript/seguimiento_interacciones.js','public/javascript/seguimiento_llamada_zadarma.js']
oldgate=b"$canalFormulario === 'LLAMADA' &&\n            $resultadoAdmiteVerificacion\n"
newgate=b"$canalFormulario === 'LLAMADA' &&\n            $resultadoAdmiteVerificacion &&\n            (int)($seguimiento['datos_verificados'] ?? 0) !== 1\n"
previous=json.loads(blob('b38a95a7','tools/denue_estabilidad_release.json'))
rows=[]
for p in paths:
 originals=[blob('b38a95a7',p)]
 if p.endswith('seguimiento_llamada_zadarma.js'):originals.append(blob('6db736ac1c88cef50b5ffabdebf1b6708b6878cc',p))
 if p.endswith('InteraccionRutaService.php'):originals.append(blob('ebf34d1cb6c672ca4f828403ab065a86332731fd',p))
 if p.endswith('SeguimientoVinculacionController.php'):
  row=next(r for r in previous['files'] if r['path']==p);originals.append(gzip.decompress(base64.b64decode(row['variants'][1]['target'])))
 variants=[]
 for old in originals:
  target=lf(p)
  if p.endswith('SeguimientoVinculacionController.php'):
   assert old.count(oldgate)==1
   target=old.replace(oldgate,newgate,1)
  if p.endswith('InteraccionRutaService.php'):
   a=b"if ($canalFormulario === 'LLAMADA' && $resultadoAdmiteVerificacion) {"
   b=b"if ($canalFormulario === 'LLAMADA' && $resultadoAdmiteVerificacion &&\n            (int)($seguimiento['datos_verificados'] ?? 0) !== 1) {"
   c=b'seguimientos.estado_seguimiento,\n';d=c+b'                    seguimientos.datos_verificados,\n'
   assert old.count(a)==1 and old.count(c)==1
   target=old.replace(a,b,1).replace(c,d,1)
  if old==originals[0]:assert target==lf(p),'Cambios ajenos: '+p
  variants.append({'before':sha(old),'base':pack(old),'after':sha(target),'target':pack(target)})
 rows.append({'path':p,'variants':variants})
raw=(json.dumps({'schema':1,'package':'verificacion-datos-vigentes','files':rows},indent=2)+'\n').encode()
(R/'tools/verificacion_vigente_release.json').write_bytes(raw)
source=blob('b38a95a7','tools/publicar_denue_estabilidad.py').decode()
source=re.sub(r"MANIFEST_SHA256 = '[a-f0-9]{64}'", "MANIFEST_SHA256 = '"+sha(raw)+"'",source)
start=source.index('PATHS = ');end=source.index('\n\n',start)
source=source[:start]+'PATHS = '+repr(paths)+source[end:]
source=source.replace('denue_estabilidad_release.json','verificacion_vigente_release.json').replace('impe-denue','impe-verificacion-vigente').replace('.publicacion-denue.lock','.publicacion-verificacion-vigente.lock')
source=source.replace("file['path'] == 'public/javascript/seguimiento_candidatos_paginacion.js'", "file['path'].startswith('public/javascript/')")
source=source.replace("function_exists('curl_multi_init') && function_exists('openssl_x509_parse')", "function_exists('mb_strtolower')")
source=source.replace('con cURL y OpenSSL','con mbstring').replace('de DENUE','de verificación vigente').replace('conexión segura y recuperación de candidatos DENUE','control de verificaciones vigentes')
(R/'tools/publicar_verificacion_vigente.py').write_text(source,encoding='utf8',newline='\n')
print('Manifiesto:',sha(raw))
