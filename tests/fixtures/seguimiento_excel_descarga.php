<?php
// Controlador/generador reales con catálogo simulado. Nunca conecta a una base.
$_SESSION=['usuario_id'=>501,'rol_id'=>4,'rol'=>'Analista de Datos'];
define('BASE_URL','http://fixture.test/');
function tienePermiso($p){return ($GLOBALS['argv'][1]??'')!=='denegado'&&in_array($p,['seguimientos_vinculacion.ver','seguimientos_vinculacion.crear'],true);}
function esRolSupervisionNacional(){return false;}
class SeguimientoVinculacionModel {
    function obtenerEstadosAsignadosAnalista($user){if($user!==501)throw new RuntimeException('Usuario incorrecto');return [['id'=>17,'nombre'=>'Morelos'],['id'=>16,'nombre'=>'Michoacán']];}
}
class Database {
    function connect($persistent=true){if($persistent!==false)throw new RuntimeException('Conexión de catálogo no explícita');return new ExcelCatalogFixture;}
}
class ExcelCatalogFixture {
    function prepare($sql){if(!str_contains($sql,'WHERE estado=1 AND estado_id IN (?,?)'))throw new RuntimeException('Consulta no restringida');$GLOBALS['queries']=($GLOBALS['queries']??0)+1;return new ExcelCatalogStatement;}
}
class ExcelCatalogStatement {
    function bind_param($types,&...$ids){if($types!=='ii'||$ids!==[17,16])throw new RuntimeException('Territorios no autorizados');}
    function execute(){}
    function get_result(){return $this;}
    function fetch_all($type){return [['id'=>1,'estado_id'=>17,'nombre'=>'Cuernavaca'],['id'=>2,'estado_id'=>16,'nombre'=>'Morelia']];}
    function close(){}
}
foreach(['app/services/SeguimientoExcelImportService.php','app/services/SeguimientoExcelPlantillaService.php','app/controllers/SeguimientoVinculacionController.php'] as $file){
    $source=file_get_contents(dirname(__DIR__,2).'/'.$file);
    eval('?>'.preg_replace('/^\h*require_once[^\r\n]+;\h*$/m','',$source));
}
register_shutdown_function(function(){if(($GLOBALS['argv'][1]??'')!=='denegado'&&($GLOBALS['queries']??0)!==1)throw new RuntimeException('Debe consultar el catálogo en un solo lote');});
(new SeguimientoVinculacionController())->descargarPlantillaSeguimientoExcel();
