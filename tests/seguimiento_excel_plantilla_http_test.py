"""Comprueba por multipart la plantilla adjunta (vacía) y una copia sintética de tres estados."""
import io,json,os,re,socket,subprocess,tempfile,time,urllib.request,urllib.error,zipfile
from pathlib import Path
R=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='impe-xlsx-reader-') as d:
 p=Path(d);source=(R/'app/services/SeguimientoExcelImportService.php').read_text(encoding='utf8')
 (p/'service.php').write_text(re.sub(r'^require_once .*;\s*$','',source,flags=re.M),encoding='utf8')
 (p/'index.php').write_text('''<?php require __DIR__.'/service.php'; try{$r=(new ReflectionClass('SeguimientoExcelImportService'))->newInstanceWithoutConstructor()->leerXlsx($_FILES['archivo']??[]);echo json_encode(['filas'=>count($r),'estados'=>array_map(fn($v)=>$v['celdas'][3],$r)]);}catch(DomainException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}''',encoding='utf8')
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 base='http://127.0.0.1:'+str(port)+'/'
 server=subprocess.Popen([os.getenv('PHP_BIN','php'),'-S','127.0.0.1:'+str(port),'-t',str(p)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=0x08000000 if os.name=='nt' else 0)
 try:
  for _ in range(40):
   try:urllib.request.urlopen(base,timeout=1);break
   except urllib.error.HTTPError:break
   except OSError:time.sleep(.1)
  def upload(data):
   b=b'--ImpeXlsx\r\nContent-Disposition: form-data; name="archivo"; filename="plantilla.xlsx"\r\nContent-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n'+data+b'\r\n--ImpeXlsx--\r\n'
   req=urllib.request.Request(base,data=b,headers={'Content-Type':'multipart/form-data; boundary=ImpeXlsx'})
   try:r=urllib.request.urlopen(req);return r.status,json.loads(r.read())
   except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
  original=(R/'tests/fixtures/seguimiento_plantilla_v3_anterior.xlsx').read_bytes()
  code,result=upload(original);assert code==422 and 'no contiene instituciones' in result['error'],result
  output=io.BytesIO()
  with zipfile.ZipFile(io.BytesIO(original)) as source,zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as dest:
   for entry in source.infolist():
    data=source.read(entry.filename)
    if entry.filename=='xl/worksheets/sheet1.xml':
     # Datos sintéticos sólo para ejercitar el lector. Nunca edita el archivo del usuario.
     rows=''.join('<row r="'+str(i)+'"><c r="B'+str(i)+'" t="inlineStr"><is><t>Institución sintética</t></is></c><c r="D'+str(i)+'" t="inlineStr"><is><t>'+state+'</t></is></c></row>' for i,state in enumerate(['Morelos','Michoacán','Estado de México'],2))
     data=data.replace(b'</sheetData>',rows.encode()+b'</sheetData>')
    dest.writestr(entry,data)
  code,result=upload(output.getvalue());assert code==200 and result['estados']==['Morelos','Michoacán','Estado de México'],result
  print('PASS: plantilla adjunta V3 compatible; vacía rechazada correctamente; lectura multipart de tres estados sin DB ni inserciones.')
 finally:server.terminate();server.wait(timeout=10)
