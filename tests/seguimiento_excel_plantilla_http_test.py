"""Comprueba por multipart la plantilla adjunta (vacía) y una copia sintética de tres estados."""
import io,json,os,re,socket,subprocess,tempfile,time,urllib.request,urllib.error,zipfile
import xml.etree.ElementTree as ET
from xml.sax.saxutils import escape
from pathlib import Path
R=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='impe-xlsx-reader-') as d:
 p=Path(d);source=(R/'app/services/SeguimientoExcelImportService.php').read_text(encoding='utf8')
 (p/'service.php').write_text(re.sub(r'^require_once .*;\s*$','',source,flags=re.M),encoding='utf8')
 (p/'index.php').write_text('''<?php require __DIR__.'/service.php'; try{$r=(new ReflectionClass('SeguimientoExcelImportService'))->newInstanceWithoutConstructor()->leerXlsx($_FILES['archivo']??[]);echo json_encode(['registros'=>$r,'filas'=>count($r),'estados'=>array_map(fn($v)=>$v['celdas'][3],$r)]);}catch(DomainException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}''',encoding='utf8')
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
  generated=subprocess.check_output([os.getenv('PHP_BIN','php'),str(R/'tests/fixtures/seguimiento_excel_municipios.php')])
  filled=io.BytesIO();ns={'x':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
  with zipfile.ZipFile(io.BytesIO(generated)) as source,zipfile.ZipFile(filled,'w',zipfile.ZIP_DEFLATED) as dest:
   catalog=ET.fromstring(source.read('xl/worksheets/sheet3.xml'))
   steps=[''.join(c.itertext()) for row in catalog.findall('x:sheetData/x:row',ns)[1:] for c in row if re.fullmatch(r'C[0-9]+',c.attrib['r'])]
   assert len(steps)==14 and [x[:2] for x in steps]==[f'{i:02d}' for i in range(14)]
   rows=''
   for i,step in enumerate(steps):
    state,city=[('Morelos','Cuernavaca'),('Michoacán','Morelia'),('Estado de México','Toluca')][i%3]
    values=['','Institución sintética paso '+str(i),'INSTITUCION',state,city,'','','','','','','','',step,'','','','','']
    rows+='<row r="'+str(i+2)+'">'+''.join('<c r="'+chr(65+j)+str(i+2)+'" t="inlineStr"><is><t>'+escape(v)+'</t></is></c>' for j,v in enumerate(values))+'</row>'
   for entry in source.infolist():
    data=source.read(entry.filename)
    if entry.filename=='xl/worksheets/sheet1.xml':data=data.replace(b'</sheetData>',rows.encode()+b'</sheetData>')
    dest.writestr(entry,data)
  code,result=upload(filled.getvalue());assert code==200 and result['filas']==14,result
  assert [row['celdas'][13] for row in result['registros']]==steps
  # Otra representación OOXML usada por editores externos: cadenas compartidas.
  shared=io.BytesIO();texts=[];namespace='http://schemas.openxmlformats.org/spreadsheetml/2006/main'
  with zipfile.ZipFile(io.BytesIO(filled.getvalue())) as source,zipfile.ZipFile(shared,'w',zipfile.ZIP_DEFLATED) as dest:
   for entry in source.infolist():
    data=source.read(entry.filename)
    if entry.filename=='xl/worksheets/sheet1.xml':
     root=ET.fromstring(data)
     for cell in root.findall('x:sheetData/x:row/x:c',ns):
      if cell.attrib.get('t')!='inlineStr':continue
      text=''.join(cell.find('x:is',ns).itertext());index=len(texts);texts.append(text)
      cell.remove(cell.find('x:is',ns));cell.set('t','s');ET.SubElement(cell,'{'+namespace+'}v').text=str(index)
     data=ET.tostring(root,encoding='utf8',xml_declaration=True)
    dest.writestr(entry,data)
   dest.writestr('xl/sharedStrings.xml',('<sst xmlns="'+namespace+'" count="'+str(len(texts))+'" uniqueCount="'+str(len(texts))+'">'+''.join('<si><t xml:space="preserve">'+escape(t)+'</t></si>' for t in texts)+'</sst>').encode())
  shared_code,shared_result=upload(shared.getvalue())
  assert shared_code==200 and shared_result['registros']==result['registros'],'el lector conserva valores al cambiar la representación de texto'
  if os.getenv('IMPE_TEST_MYSQL_PORT'):
   payload=p/'filas.json';payload.write_text(json.dumps(result['registros']),encoding='utf8')
   subprocess.run([os.getenv('PHP_BIN','php'),str(R/'tests/seguimiento_excel_multiestado_db.php')],env=dict(os.environ,IMPE_TEST_EXCEL_ROWS=str(payload)),check=True)
  print('PASS: XLSX vacío rechazado, carga multipart de tres estados y lectura exacta de los catorce pasos del archivo recién generado.')
 finally:server.terminate();server.wait(timeout=10)
