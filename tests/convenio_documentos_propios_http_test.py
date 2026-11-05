import io,json,os,re,shutil,socket,subprocess,tempfile,time,urllib.request,urllib.error,zipfile,hashlib
from pathlib import Path
R=Path(__file__).resolve().parents[1];pdf=b'%PDF-1.4\nDocumento propio de prueba\n%%EOF'
b=io.BytesIO()
with zipfile.ZipFile(b,'w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('[Content_Types].xml','<Types/>');z.writestr('word/document.xml','<document/>')
docx=b.getvalue()
with tempfile.TemporaryDirectory(prefix='impe-test-convenio-propio-') as d:
 p=Path(d);source=(R/'app/services/ConvenioDocumentosService.php').read_text(encoding='utf8')
 (p/'service.php').write_text(re.sub(r'^require_once .*;\s*$','',source,flags=re.M),encoding='utf8')
 (p/'index.php').write_bytes((R/'tests/fixtures/convenio_documentos_propios_http.php').read_bytes())
 shutil.copyfile(R/'storage/templates/convenios/carta_propuesta_colaboracion.docx',p/'carta.docx');shutil.copyfile(R/'storage/templates/convenios/convenio_colaboracion_2026.docx',p/'convenio.docx')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 base='http://127.0.0.1:'+str(port)+'/'
 server=subprocess.Popen([os.getenv('PHP_BIN','php'),'-d','upload_max_filesize=10M','-d','post_max_size=20M','-S','127.0.0.1:'+str(port),'-t',str(p)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=0x08000000 if os.name=='nt' else 0)
 try:
  for _ in range(40):
   try:urllib.request.urlopen(base,timeout=1);break
   except urllib.error.HTTPError:break
   except OSError:time.sleep(.1)
  def request(carta=None,convenio=None,fields=None,query=''):
   state=p/'estado.json'
   if state.exists():state.unlink()
   values={'carta_fecha':'2026-11-05','asunto':'Documentación de prueba','cuerpo':'Mensaje sintético','carta_origen':'propio' if carta else 'sistema','convenio_origen':'propio' if convenio else 'sistema'};values.update(fields or {})
   boundary='ImpeConvenioBoundary';body=b''
   for k,v in values.items():body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"\r\n\r\n'+v+'\r\n').encode()
   for k,item in [('carta_archivo',carta),('convenio_archivo',convenio)]:
    if item is None:continue
    name,data=item;body+=('--'+boundary+'\r\nContent-Disposition: form-data; name="'+k+'"; filename="'+name+'"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+data+b'\r\n'
   body+=('--'+boundary+'--\r\n').encode();req=urllib.request.Request(base+query,data=body,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
   try:r=urllib.request.urlopen(req);code=r.status;result=r.read()
   except urllib.error.HTTPError as e:code=e.code;result=e.read()
   try:out=json.loads(result)
   except Exception:raise AssertionError(result)
   return code,out,json.loads(state.read_text()) if state.exists() else {}
  paths=[]
  for carta,convenio in [(None,None),(('Carta.pdf',pdf),None),(None,('Convenio.docx',docx)),(('Carta.pdf',pdf),('Convenio.pdf',pdf))]:
   code,out,state=request(carta,convenio,{'carta_fecha':''} if carta else None);assert code==200 and out['ok'],(code,out,state)
   assert len(state['envio'])==2 and state['sql'];a,c=state['envio']
   assert a['mime']=='application/pdf';assert c['mime']==('application/pdf' if convenio and convenio[0].endswith('.pdf') else 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
   assert bool(state.get('convertida'))==(carta is None)
   for item,sent in [(carta,a),(convenio,c)]:
    if item:assert sent['sha']==hashlib.sha256(item[1]).hexdigest() and sent['nombre']==item[0]
    assert Path(sent['ruta']).is_file()
   paths.append(a['ruta']);post=next(row for row in state['sql'] if 'UPDATE seguimientos_vinculacion_post_envio' in row['sql'])
   assert post['params'][1]==(None if carta else '2026-11-05')
   assert c['mime'] in str(state['sql']), 'MIME conservado en historial'
  assert len(set(paths))==4 and all(Path(x).is_file() for x in paths),'No sobrescribir archivos anteriores'
  code,out,state=request(('Carta.pdf',pdf),('Convenio.docx',docx),query='?sin_plantillas=1');assert out['ok'] and not state.get('convertida')
  for carta,convenio,fields in [(('no.docx',docx),None,None),(('mal.pdf',b'HTML'),None,None),(None,('mal.docx',b'not zip'),None),(None,None,{'carta_origen':'propio'}),(None,None,{'carta_fecha':'invalida'}),(('Carta.pdf',pdf),None,{'carta_origen':'sistema'}),(('Grande.pdf',pdf+b'x'*(8*1024*1024)),None,None)]:
   code,out,state=request(carta,convenio,fields);assert code==422 and not out['ok'] and not state,(code,out,state)
  code,out,state=request(('Carta.pdf',pdf),('Convenio.pdf',pdf),query='?fallo_envio=1');assert not out['ok'] and not state.get('sql') and all(not Path(a['ruta']).exists() for a in state['envio'])
  for q,code_expected in [('?sin_acceso=1',403),('?sin_reunion=1',409)]:
   code,out,state=request(('Carta.pdf',pdf),('Convenio.pdf',pdf),query=q);assert code==code_expected and not state,(code,out,state)
  print('PASS: cuatro combinaciones, multipart real, bytes intactos, MIME/nombres/historial, fecha condicional, etapas/acceso, archivos inválidos/límites, sin plantillas y limpieza en fallo; sin SQL ni correos reales.')
 finally:server.terminate();server.wait(timeout=10)
