<?php
declare(strict_types=1);
// Incluido por la prueba efímera: ejecuta las clases reales sin sus constructores/configuración externa.
if(!isset($database)||!preg_match('/^impe_sp_test_[a-f0-9]{8}$/D',$database))throw new RuntimeException('Ejecutar desde supervision_personal_db.php.');
function cargarClasePrueba(string $path,string $class):void{
 $src=file_get_contents(__DIR__.'/../'.$path);$at=strpos($src,$class);
 if($at===false)throw new RuntimeException('Falta la clase de integración.');eval(substr($src,$at));
}
cargarClasePrueba('app/services/SupervisionLlamadasRepository.php','final class SupervisionLlamadasRepository');
cargarClasePrueba('app/services/VentasActividadRepository.php','final class VentasActividadRepository');
cargarClasePrueba('app/services/SeguimientoReporteAnaliticaService.php','final class SeguimientoReporteActividadFuente');
cargarClasePrueba('app/services/DesempenoService.php','class DesempenoService');
cargarClasePrueba('app/models/DesempenoModel.php','class DesempenoModel');
require_once __DIR__.'/../app/helpers/TelefoniaFechaHoraHelper.php';
require_once __DIR__.'/../app/services/SupervisionPersonalGrabacionPolicy.php';
$calls=new SupervisionLlamadasRepository($db);
$ids=static fn($rows)=>array_map('intval',array_column($rows,'id'));
check($ids($calls->personas(10,'analistas'))===[24,22],'Listado real de llamadas: sólo equipo, con históricos.');
rejected(fn()=>$calls->obtener(10,'interaccion:3',true),'Detalle/audio ajeno se rechaza antes de consultar al proveedor.');
check(SupervisionPersonalGrabacionPolicy::resolver($db,10,22,1)===true,'Enlace antiguo de audio permite al autor supervisado.');
check(SupervisionPersonalGrabacionPolicy::resolver($db,10,23,2)===false,'Enlace antiguo no filtra a otro autor del mismo expediente.');
check(SupervisionPersonalGrabacionPolicy::resolver($db,10,21,3)===false,'Enlace antiguo no concede otro territorio sin relación.');
$sales=new VentasActividadRepository($db);
check($ids($sales->personas('equipo',10))===[],'Actividad de asesores respeta la baja administrativa.');
check($ids($sales->personas('propio',20))===[20],'La actividad propia mantiene su autorización original.');
foreach(['llamadas.supervision.ver','llamadas.supervision.audio']as$code)$db->query("INSERT INTO rol_permisos SELECT 2,id FROM permisos WHERE codigo='$code'");
check(count($calls->personas(2,'analistas'))===4,'Dirección conserva alcance nacional sin asignaciones.');

$db->query("ALTER TABLE usuarios ADD usuario VARCHAR(100) DEFAULT ''");
$db->query("ALTER TABLE seguimientos_vinculacion ADD municipio_id INT NULL,ADD nombre_entidad VARCHAR(100) DEFAULT 'Entidad de prueba'");
$db->query('CREATE TABLE municipios(id INT PRIMARY KEY,nombre VARCHAR(100))');
$db->query("INSERT INTO interacciones_vinculacion VALUES(6,100,22,'CORREO',UTC_TIMESTAMP())");
$activity=new SeguimientoReporteActividadFuente($db);
$report=$activity->obtener([100,101,102],'','','CORREO',[],10,'supervisor');
check($ids($report['humanas'])===[6]&&$report['resumen']['correos']===1,'Fuente real de reportes excluye coautores e IDs ajenos manipulados.');
check((new SeguimientoReporteActividadFuente($db))->obtener([100],'','','CORREO',[23],10,'supervisor')['humanas']===[],'Filtro de autor no amplía el alcance.');

$db->begin_transaction();
try{
 $db->query("INSERT INTO supervision_personal(kam_id,colaborador_id,origen) VALUES(10,21,'PRUEBA')");
 $db->query("INSERT INTO interacciones_vinculacion VALUES(7,101,21,'CORREO',UTC_TIMESTAMP())");
 check($ids($calls->personas(10,'analistas'))===[21,24,22],'Listado incluye analista de otro estado sin cambiar territorios.');
 $cross=(new SeguimientoReporteActividadFuente($db))->obtener([100,101],'','','CORREO',[],10,'supervisor');
 check($cross['resumen']['correos']===2,'Dos supervisores del mismo autor no duplican hechos ni indicadores.');
 $model=(new ReflectionClass(DesempenoModel::class))->newInstanceWithoutConstructor();
 (new ReflectionProperty(DesempenoModel::class,'connection'))->setValue($model,$db);
 $service=new class($db) extends DesempenoService {
  public function __construct(private mysqli $testDb){}
  protected function accesoPersonal():SupervisionPersonalAcceso{return new SupervisionPersonalAcceso($this->testDb);}
 };
 (new ReflectionProperty(DesempenoService::class,'modelo'))->setValue($service,$model);
 $method=new ReflectionMethod(DesempenoService::class,'obtenerPersonas');
 check($ids($method->invoke($service,'equipo','analistas',10))===[21,22],'Desempeño real incluye otro estado y conserva su filtro de usuarios activos.');
}finally{$db->rollback();}
check((new SeguimientoReporteActividadFuente($db))->obtener([101],'','','CORREO',[],10,'supervisor')['humanas']===[],'Revocar se refleja en la siguiente petición sin caché de sesión.');
echo "PASS: integración real de listados/detalles, audio antiguo, reportes con coautores, filtros manipulados, alcance propio/nacional, desempeño entre estados y revocación.\n";
