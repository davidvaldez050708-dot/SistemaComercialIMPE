<?php
// Vista y plantilla reales; sin usuarios, credenciales ni conexiones externas.
if(($argv[1]??'')==='xlsx'){
    require __DIR__.'/../../app/services/SeguimientoExcelPlantillaService.php';
    $bytes=SeguimientoExcelPlantillaService::archivo([['id'=>3,'nombre'=>'Baja California Sur']]);
    $temp=tempnam(sys_get_temp_dir(),'impe_excel_test_');file_put_contents($temp,$bytes);
    $zip=new ZipArchive();$zip->open($temp);
    $values=['','Institución de prueba','INSTITUCION','Baja California Sur','La Paz','Educación','Avenida Uno 1',
        '5550000000','5550000000','prueba@example.test','https://example.test','Contacto de prueba','Dirección','00 | Sin historial verificable','','','','',''];
    $row='<row r="2">';foreach($values as $i=>$value){$col=chr(65+$i);$row.='<c r="'.$col.'2" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</t></is></c>';}$row.='</row>';
    $xml=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->addFromString('xl/worksheets/sheet1.xml',str_replace('</sheetData>',$row.'</sheetData>',$xml));
    $zip->close();echo file_get_contents($temp);unlink($temp);exit;
}
define('ROOT_PATH',dirname(__DIR__,2));define('BASE_URL','http://excel.test/');
require ROOT_PATH.'/app/helpers/PermissionHelper.php';
$modoSeguimiento=in_array($argv[1]??'',['analista','supervisor','administrador'],true)?$argv[1]:'analista';
$_SESSION=['usuario_id'=>501,'rol_id'=>$modoSeguimiento==='analista'?4:($modoSeguimiento==='supervisor'?6:2),
    'csrf_token'=>'fixture-excel-csrf','permisos'=>['seguimientos_vinculacion.ver','seguimientos_vinculacion.crear']];
$territorios=[['id'=>3,'nombre'=>'Baja California Sur','nombre_corto'=>'BCS'],['id'=>17,'nombre'=>'Morelos','nombre_corto'=>'Mor.']];
require ROOT_PATH.'/app/views/seguimiento_vinculacion/index.php';
