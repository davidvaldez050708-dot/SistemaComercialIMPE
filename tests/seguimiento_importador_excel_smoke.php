<?php
/**
 * Pruebas sin escritura SQL: genera la plantilla y valida su contenido,
 * los pasos históricos y las utilidades de normalización.
 */
require_once __DIR__.'/../app/services/SeguimientoExcelPlantillaService.php';
require_once __DIR__.'/../app/services/SeguimientoHistorialImportadoService.php';

$assert=static function(bool $ok,string $message): void {
    if(!$ok)throw new RuntimeException('ERROR: '.$message);
};
$bytes=SeguimientoExcelPlantillaService::archivo([
    ['id'=>17,'nombre'=>'Morelos'],['id'=>21,'nombre'=>'Puebla']
]);
$assert(strlen($bytes)>1000,'Plantilla XLSX vacía');
$temp=tempnam(sys_get_temp_dir(),'impe_smoke_');
file_put_contents($temp,$bytes);
$zip=new ZipArchive();
$assert($zip->open($temp)===true,'ZIP no abre');
try {
    $book=$zip->getFromName('xl/workbook.xml');
    $main=$zip->getFromName('xl/worksheets/sheet1.xml');
    $catalog=$zip->getFromName('xl/worksheets/sheet3.xml');
    $assert(is_string($book)&&is_string($main)&&is_string($catalog),'Hojas incompletas');
    $assert(strpos($book,'name="Instituciones"')!==false,'Nombre de hoja');
    $assert(strpos($book,'TiposEntidad')!==false,'Falta catálogo de tipos');
    $assert(strpos($book,'EstadosTerritoriales')!==false,'Falta catálogo territorial');
    $assert(strpos($main,'sqref="N2:N251"')!==false,'Validación de etapas');
    $assert(strpos($main,'Nombre de institución *')!==false,'Encabezado obligatorio');
    $assert(strpos($catalog,'Morelos')!==false,'Estado autorizado');
    $assert(strpos($catalog,'Baja California')===false,'Estado fuera de alcance');
    $assert(strpos($catalog,'Sin historial verificable')!==false,'Falta paso 00');
    $assert(strpos($catalog,'Convenio (etapa reportada)')!==false,'Falta paso 13');
    $assert((new DOMDocument())->loadXML($main,LIBXML_NONET)!==false,'XML de hoja inválido');
}finally{$zip->close();unlink($temp);}
$service=new SeguimientoHistorialImportadoService();
for($step=0;$step<=13;$step++){
    $record=['clave_origen'=>'XLSX:'.str_repeat('a',64),
        'observaciones'=>"Importación Excel V3\nIMPE_ETAPA_REPORTADA_EXCEL_V1=".
            str_pad((string)$step,2,'0',STR_PAD_LEFT)];
    $resolved=$service->resolverEtapa($record);
    if($step===0){
        $assert($resolved===null,'El paso 00 no debe mover la ruta');
    }else{
        $assert((int)($resolved['paso']??0)===$step,'No se recuperó el paso declarado '.$step);
        $assert(strpos((string)$resolved['etapa'],'histórico reportado')!==false,
            'La etapa debe identificarse como histórica');
        if($step===13)$assert($service->esConvenioHistorico($record)===false,
            'Un paso 13 declarado no autoriza eludir requisitos del convenio');
    }
}
$reflection=new ReflectionClass(SeguimientoExcelImportService::class);
$norm=$reflection->getMethod('norm');
$assert($norm->invoke(null,'  Institución ÁRBOL  ')==='institucion arbol',
    'Normalización de acentos');
$date=$reflection->getMethod('fechaHistorica');
$assert($date->invoke(null,'')==='', 'La fecha histórica debe ser opcional');
$assert($date->invoke(null,'26/10/2026')==='2026-10-26','Fecha histórica formateada');
echo "PASS: plantilla oficial, estados asignados, validación XLSX y pasos 00–13.\n";
