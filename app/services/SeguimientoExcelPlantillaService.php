<?php
/**
 * Plantilla XLSX oficial compatible con el importador V3 (OOXML sin Composer).
 * Solo contiene información de campos y catálogos permitidos al usuario.
 */
require_once __DIR__.'/SeguimientoExcelImportService.php';
final class SeguimientoExcelPlantillaService
{
    private static function x($value): string
    {
        return htmlspecialchars((string)$value,ENT_QUOTES|ENT_XML1,'UTF-8');
    }
    private static function cell($ref,$text,$style=0): string
    {
        return '<c r="'.self::x($ref).'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.
            self::x($text).'</t></is></c>';
    }
    private static function row($number,array $cells, int $height=22): string
    {
        return '<row r="'.$number.'" ht="'.$height.'" customHeight="1">'.implode('',$cells).'</row>';
    }
    private static function sheet(string $rows,string $validations='',bool $freeze=false): string
    {
        $cols='<cols>';
        $widths=[22,36,20,23,24,27,32,18,18,27,24,26,23,36,22,39,33,23,42];
        foreach($widths as $i=>$w){$n=$i+1;$cols.='<col min="'.$n.'" max="'.$n.'" width="'.$w.'" customWidth="1"/>';}
        $cols.='</cols>';
        $views=$freeze
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
            $views.'<sheetFormatPr defaultRowHeight="20"/>'.$cols.'<sheetData>'.$rows.'</sheetData>'.
            $validations.'</worksheet>';
    }
    public static function archivo(array $estados): string
    {
        if(!class_exists('ZipArchive'))throw new RuntimeException('Extensión ZIP de PHP no instalada.');
        $tmp=tempnam(sys_get_temp_dir(),'impe_xlsx_');
        if(!$tmp)throw new RuntimeException('No se pudo crear la plantilla.');
        $zip=new ZipArchive();
        if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){
            @unlink($tmp);throw new RuntimeException('No se pudo preparar el XLSX.');
        }
        try{
            $headers=[];
            foreach(SeguimientoExcelImportService::HEADERS as $i=>$text){
                $style=($i>=1&&$i<=13)?2:1;
                $headers[]=self::cell(self::column($i+1).'1',$text,$style);
            }
            $rows=self::row(1,$headers,52);
            $validation='<dataValidations count="3">'.
                '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="C2:C251">'.
                '<formula1>TiposEntidad</formula1></dataValidation>'.
                '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="D2:D251">'.
                '<formula1>EstadosTerritoriales</formula1></dataValidation>'.
                '<dataValidation type="list" allowBlank="1" showErrorMessage="1" sqref="N2:N251">'.
                '<formula1>PasosHistoricos</formula1></dataValidation></dataValidations>';
            $sheet1=self::sheet($rows,$validation,true);
            $guide=[
                'PLANTILLA OFICIAL IMPE · IMPORTACIÓN DE INSTITUCIONES V3',
                'Captura una institución por fila, sin cambiar el orden ni el nombre de las 19 columnas.',
                'Obligatorios: B–N (13). Opcionales: A y O–S (6).',
                'Selecciona tipo, estado y paso desde los desplegables.',
                'Las instituciones del archivo deben pertenecer al territorio seleccionado en el CRM.',
                'El municipio debe existir y estar activo dentro del estado indicado.',
                'Usa teléfonos reales, correo válido y dirección web institucional.',
                'El paso 00 significa: sin historial verificable. La institución empezará como NUEVO.',
                'Los pasos 01–13 son antecedentes reportados, no operaciones realizadas por el CRM.',
                'Fechas, evidencia, próxima acción y observaciones pueden permanecer vacías.',
                'Si hay duplicados o coincidencias, el sistema los separará para revisión.',
                'Nada se inserta antes de confirmar los registros nuevos.'
            ];
            $guideRows='';
            foreach($guide as $i=>$str)$guideRows.=self::row($i+1,[self::cell('A'.($i+1),$str,$i===0?1:0)],$i===0?35:28);
            $types=['EMPRESA','ORGANIZACION','INSTITUCION','SECRETARIA','MUNICIPIO','OTRO'];
            $steps=[
                '00 | Sin historial verificable','01 | Seguimiento iniciado','02 | Investigación de datos',
                '03 | Contacto y validación','04 | Verificación de datos','05 | Preparación de oficio',
                '06 | Generación de PDF','07 | Envío de oficio o correo','08 | Esperando respuesta',
                '09 | Respuesta recibida','10 | Seguimiento por correo','11 | Reunión agendada',
                '12 | Reunión realizada','13 | Convenio (etapa reportada)'
            ];
            $catalogRows=self::row(1,[self::cell('A1','Tipos de entidad',1),
                self::cell('B1','Estados disponibles',1),self::cell('C1','Pasos históricos',1)]);
            $estados=array_values(array_unique(array_filter(array_map(static function($estado){
                return (string)($estado['nombre']??'');
            },$estados))));
            for($i=0;$i<max(count($types),count($estados),count($steps));$i++){
                $cells=[];
                if(isset($types[$i]))$cells[]=self::cell('A'.($i+2),$types[$i]);
                if(isset($estados[$i]))$cells[]=self::cell('B'.($i+2),$estados[$i]);
                if(isset($steps[$i]))$cells[]=self::cell('C'.($i+2),$steps[$i]);
                $catalogRows.=self::row($i+2,$cells);
            }
            $z=[
                '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?>'.
                    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
                    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
                    '<Default Extension="xml" ContentType="application/xml"/>'.
                    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
                    '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
                    '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
                    '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
                    '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
                    '</Types>',
                '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?>'.
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
                    '</Relationships>',
                'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?>'.
                    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.
                    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'.
                    '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'.
                    '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.
                    '</Relationships>',
                'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?>'.
                    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
                    'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
                    '<sheets><sheet name="Instituciones" sheetId="1" r:id="rId1"/>'.
                    '<sheet name="Guía de llenado" sheetId="2" r:id="rId2"/>'.
                    '<sheet name="Catalogos" sheetId="3" r:id="rId3"/></sheets>'.
                    '<definedNames>'.
                    '<definedName name="TiposEntidad">Catalogos!$A$2:$A$7</definedName>'.
                    '<definedName name="EstadosTerritoriales">Catalogos!$B$2:$B$'.(count($estados)+1).'</definedName>'.
                    '<definedName name="PasosHistoricos">Catalogos!$C$2:$C$15</definedName>'.
                    '</definedNames></workbook>',
                'xl/styles.xml'=>'<?xml version="1.0" encoding="UTF-8"?>'.
                    '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
                    '<fonts count="2"><font><sz val="11"/><name val="Aptos"/></font>'.
                    '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Aptos"/></font></fonts>'.
                    '<fills count="4"><fill><patternFill patternType="none"/></fill>'.
                    '<fill><patternFill patternType="gray125"/></fill>'.
                    '<fill><patternFill patternType="solid"><fgColor rgb="FF28436F"/><bgColor indexed="64"/></patternFill></fill>'.
                    '<fill><patternFill patternType="solid"><fgColor rgb="FF126258"/><bgColor indexed="64"/></patternFill></fill></fills>'.
                    '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'.
                    '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
                    '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'.
                    '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1">'.
                    '<alignment wrapText="1" vertical="center"/></xf>'.
                    '<xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyAlignment="1">'.
                    '<alignment wrapText="1" vertical="center"/></xf></cellXfs>'.
                    '</styleSheet>',
                'xl/worksheets/sheet1.xml'=>$sheet1,
                'xl/worksheets/sheet2.xml'=>self::sheet($guideRows),
                'xl/worksheets/sheet3.xml'=>self::sheet($catalogRows)
            ];
            foreach($z as $name=>$body) {
                if(!$zip->addFromString($name,$body))throw new RuntimeException('No se pudo crear la hoja '.$name);
            }
        }finally{$zip->close();}
        $bytes=file_get_contents($tmp);@unlink($tmp);
        if(!$bytes)throw new RuntimeException('No se generó el XLSX.');
        return $bytes;
    }
    private static function column(int $num): string
    {
        $value='';while($num>0){$num--;$value=chr($num%26+65).$value;$num=intdiv($num,26);}
        return $value;
    }
}
