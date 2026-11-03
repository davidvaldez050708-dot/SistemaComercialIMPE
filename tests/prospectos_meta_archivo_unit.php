<?php
/** Pruebas del lector de Meta sin datos personales reales ni BD. */
require __DIR__.'/../app/services/ProspectoMetaArchivo.php';
function revisar($ok,$msg){if (!$ok)throw new RuntimeException($msg);}
$columns=['id','created_time','ad_id','ad_name','adset_id','adset_name','campaign_id','campaign_name',
    'form_id','form_name','is_organic','platform','full_name','phone_number','estado','retailer_item_id'];
$values=['l:99900001','2026-11-01T17:59:45-06:00','ag:1','Anuncio de prueba','as:1','Conjunto',
    'c:123','Campaña ficticia','f:123','Formulario ficticio','false','fb','Persona Prueba','p:+525511223344','Morelos',''];
$line=static function($items,$delimiter) {
    $f=fopen('php://temp','w+');
    fputcsv($f,$items,$delimiter,'"','');
    rewind($f);$s=stream_get_contents($f);fclose($f);return $s;
};
$raw=$line($columns,"\t").$line($values,"\t").$line($values,"\t");
$file=tempnam(sys_get_temp_dir(),'meta-tests-');
try {
    file_put_contents($file,"\xff\xfe".iconv('UTF-8','UTF-16LE',$raw));
    $out=ProspectoMetaArchivo::leer($file,'archivo_meta_sin_extension');
    revisar($out['total_filas']===2,'Filas UTF-16');
    revisar($out['validas']===1 && $out['repetidas_archivo']===1,'Dedupe por ID');
    revisar($out['filas'][0]['id']==='99900001','Prefijo l:');
    revisar($out['filas'][0]['phone_number']==='+525511223344','Prefijo p:');
    revisar($out['filas'][0]['fecha_utc']==='2026-11-01 23:59:45','Fecha UTC');
    file_put_contents($file,$line($columns,',').$line($values,','));
    $out=ProspectoMetaArchivo::leer($file,'exportacion.csv');
    revisar($out['validas']===1 && $out['campanias'][0]['total']===1,'CSV UTF-8');
    $fallo=false;
    try {ProspectoMetaArchivo::leer($file,'archivo.xls');}
    catch (DomainException $e) {$fallo=true;}
    revisar($fallo,'XLS antiguo debe rechazar conversión falsa');
    if (class_exists('ZipArchive') && class_exists('DOMDocument')) {
        $zip=new ZipArchive();
        revisar($zip->open($file,ZipArchive::OVERWRITE)===true,'ZIP Excel');
        $sheet='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ([$columns,$values] as $i=>$fila) {
            $sheet.='<row r="'.($i+1).'">';
            foreach ($fila as $n=>$v) {
                $c=chr(65+$n);
                $sheet.='<c r="'.$c.($i+1).'" t="inlineStr"><is><t>'.htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';
            }
            $sheet.='</row>';
        }
        $sheet.='</sheetData></worksheet>';
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
        $zip->close();
        $out=ProspectoMetaArchivo::leer($file,'exportacion.xlsx');
        revisar($out['validas']===1 && $out['filas'][0]['id']==='99900001','Excel xlsx');
        echo "PASS CSV, Meta UTF-16 TSV, XLSX, fechas, nombres, ID y bloqueo de .xls.\n";
    } else {
        throw new RuntimeException('El CI debe habilitar ZIP y DOM para comprobar Excel.');
    }
} finally {@unlink($file);}
