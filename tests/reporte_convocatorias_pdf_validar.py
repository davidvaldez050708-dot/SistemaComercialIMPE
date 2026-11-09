"""Inspección de PDF generados por el servicio real con fixtures, sin producción."""
from pathlib import Path
import re, subprocess
from pypdf import PdfReader
import pdfplumber
root=Path(__file__).resolve().parents[1]
for case,expected_records in [('vacio',0),('datos',4),('volumen',160)]:
 p=root/'tmp/pdfs'/('convocatorias-'+case+'.pdf')
 reader=PdfReader(p,strict=True)
 texts=[page.extract_text() for page in reader.pages]
 text='\n'.join(texts)
 assert 'RESUMEN EJECUTIVO' in text and 'COBERTURA TERRITORIAL' in text
 assert 'PUBLICACIONES POR TIPO' in text and 'DETALLE DE CONVOCATORIAS' in text
 assert len(re.findall(r'Publicación:',text))==expected_records
 for i,txt in enumerate(texts,1):
  assert txt.count('Reporte de Convocatorias')==1
  assert f'Página {i} de {len(texts)}' in txt
  if 'Publicación:' in txt: assert 'Subtipo' in txt and 'Estatus' in txt,'Encabezado de tabla no repetido'
  assert len(txt)>250,'Página vacía o sin contenido'
 if expected_records:
  for i in range(1,expected_records+1):assert f'Convocatoria {i:03d}' in text
  for s in ['Educación','niñez','México','Inglés','acción','Ñandú','€','<etiqueta>','Núñez & José','Publicación: 23/10/2026','01/10/2026','31/12/2026','Guanajuato','Sinaloa','Ciudad de México','Vigentes:']:
   assert s in text,(case,s)
 else: assert 'Sin convocatorias registradas' in text
 with pdfplumber.open(p) as pdf:
  for page in pdf.pages:
   assert len(page.images)==1,'Logotipo institucional perdido'
   for w in page.extract_words():
    assert w['x0']>=70 and w['x1']<=page.width-70,(case,w)
    if w['top']>=140 and w['top']<page.height-50:
     assert w['bottom']<page.height-50,(case,'Contenido sobre el pie',w)
 print(f'OK: {case}, {len(texts)} página(s), {expected_records} registros completos; símbolos, cabeceras, logo y márgenes válidos.')
# Sólo el servicio específico y su nuevo renderer pueden cambiar en producción.
changed=subprocess.check_output(['git','diff','--name-only','adf34ab1','--','app','public','config','composer.json','composer.lock','storage/templates'],cwd=root,text=True).splitlines()
allowed={'app/services/ReporteConvocatoriaPdfService.php','app/services/ReporteConvocatoriaDompdfService.php'}
assert set(changed)<=allowed,changed
print('OK: tarjeta, controlador/permisos, datos/consultas, oficios, plantillas y otros reportes sin modificaciones.')
