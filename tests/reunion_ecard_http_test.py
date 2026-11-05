"""Multipart real; controlador/servicio reales con transporte y repositorio simulados."""
import base64,json,os,re,socket,subprocess,tempfile,time,urllib.request,urllib.error
from pathlib import Path
R=Path(__file__).resolve().parents[1]
php=os.getenv('PHP_BIN','php')
png=subprocess.check_output([php,'-r','$i=imagecreatetruecolor(40,60);imagepng($i);'])
jpg=subprocess.check_output([php,'-r','$i=imagecreatetruecolor(40,60);imagejpeg($i);'])
wide=subprocess.check_output([php,'-r','$i=imagecreatetruecolor(6001,1);imagepng($i);'])
large=subprocess.check_output([php,'-r','$i=imagecreatetruecolor(4000,3001);imagepng($i);'])
with tempfile.TemporaryDirectory(prefix='impe-test-ecard-') as d:
 p=Path(d);(p/'app/services').mkdir(parents=True)
 for name in ['CorreoFirmadoService','CorreoFirmadoController']:
  source=R/('app/services/' if name.endswith('Service') else 'app/controllers/')/(name+'.php')
  (p/(name+'.php')).write_text(re.sub(r'^require_once .*;\s*$', '', source.read_text(encoding='utf8'),flags=re.M),encoding='utf8')
 (p/'app/services/ReunionEcardImagen.php').write_bytes((R/'app/services/ReunionEcardImagen.php').read_bytes())
 (p/'index.php').write_bytes((R/'tests/fixtures/reunion_ecard_http.php').read_bytes())
 (p/'default.png').write_bytes(png)
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 base='http://127.0.0.1:'+str(port)+'/'
 php=os.getenv('PHP_BIN','php')
 server=subprocess.Popen([php,'-d','upload_max_filesize=8M','-d','post_max_size=12M','-S','127.0.0.1:'+str(port),'-t',str(p)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=0x08000000 if os.name=='nt' else 0)
 try:
  for _ in range(60):
   try:urllib.request.urlopen(base,timeout=1);break
   except urllib.error.HTTPError:break
   except OSError:time.sleep(.1)
  def request(file=None,query='',expected='0',extra=None):
   state=p/'estado.json'
   if state.exists():state.unlink()
   fields={'reunion_id':'1','asunto':'Prueba sintética','cuerpo':'Mensaje de prueba','ecard_template':'MANUEL','ecard_personalizada_esperada':expected}
   fields.update(extra or {});boundary='ImpeEcardBoundary';body=b''
   for key,value in fields.items():body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+key+'"\r\n\r\n'+value+'\r\n').encode()
   if file is not None:
    body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="ecard_imagen"; filename="imagen.png"\r\nContent-Type: image/png\r\n\r\n').encode()+file+b'\r\n'
   body+=('--'+boundary+'--\r\n').encode()
   req=urllib.request.Request(base+query,data=body,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
   try:r=urllib.request.urlopen(req);code=r.status;response=r.read()
   except urllib.error.HTTPError as e:code=e.code;response=e.read()
   result=json.loads(response);saved=json.loads(state.read_text()) if state.exists() else {}
   return code,result,saved
  code,result,s=request();assert code==200 and result['ok'] and s['generada'] and s['registrado'],(code,result,s);assert s['envio']['cantidad']==1
  for data,extra in [(png,{}),(jpg,{}),(png,{'reprogramacion':'1'})]:
   code,result,s=request(data,expected='1',extra=extra)
   assert code==200 and result['ok'] and result['ecard_template']=='personalizada',(code,result,s)
   assert 'generada' not in s and s['envio']['cantidad']==1 and s['envio']['imagen_valida']
   assert s['envio']['cid']=='ecard-reunion' and 'cid:ecard-reunion' in s['envio']['html']
   assert not Path(s['envio']['ruta']).exists(),'Eliminar imagen temporal tras enviar'
   assert s['reprogramada' if extra else 'registrado']
  for file,expected in [(b'<?php echo "x";','1'),(b'<svg xmlns="http://www.w3.org/2000/svg"/>','1'),(b'','1'),(b'x'*(5*1024*1024+1),'1'),(None,'1'),(wide,'1'),(large,'1')]:
   code,result,s=request(file,expected=expected);assert code==422 and not result['ok'] and not s,(code,result,s)
  code,result,s=request(png+b'<?php echo 1; ?>',expected='1');assert result['ok'] and s['envio']['sha']!=__import__('hashlib').sha256(png+b'<?php echo 1; ?>').hexdigest()
  code,result,s=request(query='?fallo_default=1');assert code==500 and not result['ok'] and 'envio' not in s
  for q in ['?fallo_envio=1','?excepcion=1']:
   code,result,s=request(png,q,expected='1');assert not result['ok'] and 'registrado' not in s
   assert not Path(s['envio']['ruta']).exists()
  for q,expected_code in [('?denegar=1',403),('?invalida=1',409)]:
   code,result,s=request(png,q,expected='1');assert code==expected_code and not result['ok'] and not s
  assert not list((p/'storage/mail/ecards/temporales').glob('*'))
  print('PASS: Ecard obligatoria, reemplazo único, reprogramación, multipart, permisos, JPG/PNG inválido, tamaño, fallo de generación/transporte y limpieza; sin SQL ni correos reales.')
 finally:server.terminate();server.wait(timeout=10)
