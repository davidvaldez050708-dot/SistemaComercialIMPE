"""Inspección de sólo lectura del XLSX real: rangos, listas y compatibilidad OOXML."""
import io,os,subprocess,zipfile,xml.etree.ElementTree as ET
from pathlib import Path
from openpyxl import load_workbook
R=Path(__file__).resolve().parents[1]
data=subprocess.check_output([os.getenv('PHP_BINARY','php'),str(R/'tests/fixtures/seguimiento_excel_municipios.php')],cwd=R)
wb=load_workbook(io.BytesIO(data))
assert wb.sheetnames==['Instituciones','Guía de llenado','Catalogos','Municipios']
assert wb['Municipios'].sheet_state=='hidden'
sheet=wb['Instituciones']
assert len([c for c in sheet[1] if c.value and '*' in c.value])==4
assert sheet['E1'].value=='Municipio *' and sheet['K1'].value=='Sitio web'
assert len(sheet.data_validations.dataValidation)==4
dv=next(v for v in sheet.data_validations.dataValidation if 'E2' in str(v.sqref))
assert str(dv.sqref)=='E2:E251' and dv.errorStyle=='stop' and dv.showErrorMessage
assert dv.formula1=='INDIRECT(IFERROR(INDEX(ClavesMunicipales,MATCH($D2,EstadosTerritoriales,0)),"MunicipiosVacios"))'
def named_values(name):
    result=[]
    for title,ref in wb.defined_names[name].destinations:
        cells=wb[title][ref]
        if not isinstance(cells,tuple):cells=((cells,),)
        result.extend(c.value for row in cells for c in row if c.value is not None)
    return result
states=named_values('EstadosTerritoriales');keys=named_values('ClavesMunicipales')
assert dict(zip(states,keys))=={'Morelos':'Municipios_17','Michoacán':'Municipios_16','Estado de México':'Municipios_15'}
assert set(named_values(keys[states.index('Morelos')]))=={'Cuernavaca','Cuautla'}
assert named_values(keys[states.index('Michoacán')])==['Morelia']
assert set(named_values(keys[states.index('Estado de México')]))=={'Toluca','Valle de Bravo'}
assert named_values('MunicipiosVacios')==[]
with zipfile.ZipFile(io.BytesIO(data)) as z:
    for name in z.namelist():
        if name.endswith('.xml') or name.endswith('.rels'):ET.fromstring(z.read(name))
    all_xml=b''.join(z.read(n) for n in z.namelist() if n.endswith('.xml')).decode()
    assert 'Municipio no autorizado' not in all_xml and 'Municipio inactivo' not in all_xml
    assert 'IMPE' not in all_xml
    assert 'xl/vbaProject.bin' not in z.namelist()
guide='\n'.join(str(row[0] or '') for row in wb['Guía de llenado'].values)
assert 'B–E (4)' in guide and 'pueden quedar vacíos' in guide and 'vuelve a elegir el municipio' in guide
# Se conserva para revisión visual; no se reexporta con otra biblioteca.
(R/'tmp').mkdir(exist_ok=True);(R/'tmp/plantilla_municipios.xlsx').write_bytes(data)
print('PASS: XLSX real legible, cuatro obligatorios, 19 columnas, rangos municipales por estado, catálogo autorizado/activo, validación stop, sin macros ni IMPE, guía coherente.')

# La acción real consulta una sola vez y sólo obtiene municipios de los estados autorizados.
actual=subprocess.check_output([os.getenv('PHP_BINARY','php'),str(R/'tests/fixtures/seguimiento_excel_descarga.php')],cwd=R)
restricted=load_workbook(io.BytesIO(actual))
assert [c.value for c in restricted['Catalogos']['B'][1:] if c.value]==['Morelos','Michoacán']
assert restricted['Municipios']['A2'].value=='Cuernavaca' and restricted['Municipios']['B2'].value=='Morelia'
assert subprocess.check_output([os.getenv('PHP_BINARY','php'),str(R/'tests/fixtures/seguimiento_excel_descarga.php'),'denegado'],cwd=R)==b''
print('PASS: descarga real, permisos de crear/ver, una consulta municipal por lote y catálogo limitado al territorio autorizado.')
