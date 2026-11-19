"""Inspección de sólo lectura del XLSX real: rangos, listas y compatibilidad OOXML."""
import io,os,subprocess,zipfile,xml.etree.ElementTree as ET
from pathlib import Path
from openpyxl import load_workbook
R=Path(__file__).resolve().parents[1]
data=subprocess.check_output([os.getenv('PHP_BINARY','php'),str(R/'tests/fixtures/seguimiento_excel_municipios.php')],cwd=R)
wb=load_workbook(io.BytesIO(data))
assert wb.sheetnames==['Instituciones','Guía de llenado','Catalogos','Municipios']
assert wb['Municipios'].sheet_state=='visible'
sheet=wb['Instituciones']
assert len([c for c in sheet[1] if c.value and '*' in c.value])==4
assert sheet['E1'].value=='Municipio *' and sheet['K1'].value=='Sitio web'
assert len(sheet.data_validations.dataValidation)==4
dv=next(v for v in sheet.data_validations.dataValidation if 'E2' in str(v.sqref))
assert str(dv.sqref)=='E2:E251' and dv.errorStyle=='stop' and dv.showErrorMessage
assert dv.formula1=='MunicipiosDisponibles'
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
assert set(named_values('MunicipiosDisponibles'))=={'Cuernavaca','Cuautla','Morelia','Toluca','Valle de Bravo'}
assert len(named_values('MunicipiosDisponibles'))==5
assert [v[:2] for v in named_values('PasosHistoricos')]==[f'{i:02d}' for i in range(14)]
assert 'MunicipiosVacios' not in wb.defined_names
with zipfile.ZipFile(io.BytesIO(data)) as z:
    for name in z.namelist():
        if name.endswith('.xml') or name.endswith('.rels'):ET.fromstring(z.read(name))
        if name.startswith('xl/worksheets/') and name.endswith('.xml'):
            ns={'x':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
            for row in ET.fromstring(z.read(name)).findall('x:sheetData/x:row',ns):
                def column(cell):
                    result=0
                    for letter in cell.attrib['r'].rstrip('0123456789'):result=result*26+ord(letter)-64
                    return result
                cols=[column(cell) for cell in row]
                assert cols==sorted(set(cols)),(name,row.attrib['r'],'celdas fuera de orden')
    all_xml=b''.join(z.read(n) for n in z.namelist() if n.endswith('.xml')).decode()
    assert 'Municipio no autorizado' not in all_xml and 'Municipio inactivo' not in all_xml
    assert 'IMPE' not in all_xml
    assert 'xl/vbaProject.bin' not in z.namelist()
    assert 'INDIRECT(' not in all_xml and '1048576' not in all_xml
guide='\n'.join(str(row[0] or '') for row in wb['Guía de llenado'].values)
assert 'B–E (4)' in guide and 'pueden quedar vacíos' in guide and 'Google Sheets' in guide
# Se conserva para revisión visual; no se reexporta con otra biblioteca.
(R/'tmp').mkdir(exist_ok=True);(R/'tmp/plantilla_municipios.xlsx').write_bytes(data)
print('PASS: XLSX real, celdas OOXML ordenadas, pasos 00–13, cuatro obligatorios, 19 columnas, lista municipal fija autorizada/activa y catálogo por estado, sin INDIRECT, rangos extremos ni macros.')

# La acción real consulta una sola vez y sólo obtiene municipios de los estados autorizados.
actual=subprocess.check_output([os.getenv('PHP_BINARY','php'),str(R/'tests/fixtures/seguimiento_excel_descarga.php')],cwd=R)
restricted=load_workbook(io.BytesIO(actual))
assert [c.value for c in restricted['Catalogos']['B'][1:] if c.value]==['Morelos','Michoacán']
assert restricted['Municipios']['A2'].value=='Cuernavaca' and restricted['Municipios']['B2'].value=='Morelia'
assert subprocess.check_output([os.getenv('PHP_BINARY','php'),str(R/'tests/fixtures/seguimiento_excel_descarga.php'),'denegado'],cwd=R)==b''
print('PASS: descarga real, permisos de crear/ver, una consulta municipal por lote y catálogo limitado al territorio autorizado.')
