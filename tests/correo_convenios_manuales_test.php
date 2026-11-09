<?php
// Simulación dirigida; las consultas del modelo sólo pueden ser SELECT.
require __DIR__.'/correo_convocatorias_test.php';
class CvManualDb extends CvDb
{
    public $tablas=['aliados_manuales','marketing_convenio_convocatorias','marketing_aliado_manual_convocatorias','marketing_aliado_convocatorias_origen'];
    public $queries=[];
    public function query($sql) {
        cvCheck(str_starts_with($sql,'SELECT'),'Sin escrituras');$this->queries[]=$sql;
        if(str_contains($sql,'information_schema')) {
            preg_match("/table_name = '([^']+)'/",$sql,$m);
            return new MarketingRows([['total'=>in_array($m[1],$this->tablas,true)?1:0]]);
        }
        if(str_contains($sql,'FROM aliados_manuales a')) {
            cvCheck(str_contains($sql,'a.activo=1') && str_contains($sql,'e.id=a.estado_id'),'Mismos criterios territoriales y activos que Convenios');
            return new MarketingRows([
                ['id'=>90,'nombre'=>'Convenio Alfa','estado_id'=>11,'referencia'=>'M-90'],
                ['id'=>1,'nombre'=>'Manual Sinaloa','estado_id'=>25,'referencia'=>''],
                ['id'=>2,'nombre'=>'Manual sin convocatoria','estado_id'=>11,'referencia'=>'']]);
        }
        if(str_contains($sql,'FROM marketing_aliado_manual_convocatorias')) {
            cvCheck(str_contains($sql,'a.id=mc.manual_id') && str_contains($sql,'a.activo=1'),'Relación manual por ID activo');
            return self::linkedRows($sql,[['convocatoria_id'=>3,'id'=>'manual:90','nombre'=>'Convenio Alfa','referencia'=>'M-90']]);
        }
        if(str_contains($sql,'FROM marketing_aliado_convocatorias_origen')) {
            cvCheck(str_contains($sql,'mc.origen_id') && str_contains($sql,'mc.origen_tipo='),'Relación con origen explícito');
            if(str_contains($sql,"mc.origen_tipo='manual'")) {
                return self::linkedRows($sql,[['convocatoria_id'=>3,'id'=>'manual:90','nombre'=>'Convenio Alfa','referencia'=>'M-90']]);
            }
            return self::linkedRows($sql,[['convocatoria_id'=>1,'id'=>90,'nombre'=>'Convenio Alfa','referencia'=>'F-90']]);
        }
        return parent::query($sql);
    }
}
$db=new CvManualDb();$db->rows=[['id'=>1,'titulo'=>'Institucional','estado'=>1,'imagen'=>''],['id'=>2,'titulo'=>'Sinaloa','estado'=>1,'imagen'=>''],['id'=>3,'titulo'=>'Manual','estado'=>1,'imagen'=>'']];
$model=new CorreoConvocatoriaConsultaModel($db);$catalog=$model->catalogo();
cvCheck(count($catalog['convenios'])===6,'Conservar ambos orígenes incluso con nombre e ID coincidentes');
$byId=array_column($catalog['convenios'],null,'id');
cvCheck(isset($byId[90],$byId['manual:90']) && $byId['manual:90']['origen_id']===90,'Identidad real sin inventar ID ni colisionar con seguimiento');
cvCheck(count($catalog['convocatorias'][0]['convenios'])===1 && count($catalog['convocatorias'][2]['convenios'])===1,'Deduplicación entre relaciones antigua y nueva por origen e ID');
$composition=new CorreoConvocatoriaComposicion($model);
$selection=$composition->seleccionar(['convocatoria_id'=>3,'convocatoria_estado_id'=>11,'convocatoria_convenio_id'=>'manual:90']);
cvCheck($selection['filtro_convenio']==='manual:90' && $selection['id']===3,'Selección manual validada por relación real, compatible con metadatos actuales');
$private=sys_get_temp_dir().'/impe-manual-filter-'.bin2hex(random_bytes(5));mkdir($private,0700,true);
putenv('IMPE_MAIL_STATE_DIR='.$private);
try {
    $composition->registrar(7,777,$selection);
    cvCheck($composition->leer(7,777)['filtro_convenio']==='manual:90','La persistencia existente conserva la clave manual sin cambiar borradores');
    cvCheck($composition->leer(8,777)===[],'Selección manual mantiene aislamiento del propietario');
} finally {
    foreach(glob($private.'/*') as $file) { unlink($file); }rmdir($private);putenv('IMPE_MAIL_STATE_DIR');
}
cvCheck($composition->seleccionar(['convocatoria_id'=>1,'convocatoria_convenio_id'=>90])['id']===1,'Vinculación conserva IDs y funcionamiento');
foreach(['manual:2',90,'manual:1'] as $wrong) {
    try { $composition->seleccionar(['convocatoria_id'=>3,'convocatoria_convenio_id'=>$wrong]);throw new LogicException('Asociación ajena admitida'); }
    catch(RuntimeException $e) { cvCheck($e->getMessage()==='CONVOCATORIA_FILTROS','No asociar por nombre, ID de otra tabla o manual ajeno'); }
}
foreach(['manual:0','manual:01','manual:2147483648','manual:1 OR 1=1',[],true,'institucional:90'] as $bad) {
    try { CorreoConvocatoriaConsultaModel::convenioId($bad);throw new LogicException('Clave inválida admitida'); }
    catch(RuntimeException $e) { cvCheck($e->getMessage()==='CONVOCATORIA_ID','Entrada inválida rechazada'); }
}
foreach([['marketing_aliado_manual_convocatorias'],['marketing_aliado_convocatorias_origen'],['marketing_convenio_convocatorias']] as $available) {
    $db->tablas=array_merge(['aliados_manuales'],$available);$c=(new CorreoConvocatoriaConsultaModel($db))->catalogo();
    cvCheck(count($c['convenios'])===6,'Tabla opcional ausente no oculta aliados');
    cvCheck(count($c['convocatorias'][2]['convenios'])===($available[0]==='marketing_convenio_convocatorias'?0:1),'Relaciones sólo si existen en cada versión');
    if($available[0]==='marketing_aliado_convocatorias_origen') { cvCheck($c['convocatorias'][0]['convenios'][0]['id']===90,'Vinculación moderna funciona sin relación antigua'); }
}
$db->tablas=[];$c=(new CorreoConvocatoriaConsultaModel($db))->catalogo();
cvCheck(count($c['convenios'])===3,'Instalación sin módulo manual conserva catálogo institucional');
echo "OK: manuales y vinculación, IDs/nombres coincidentes, estado, sin convocatoria, dos relaciones reales sin duplicados, claves manipuladas y tablas opcionales. SQL/API simulados; sin producción.\n";
