<?php
declare(strict_types=1);
set_error_handler(function($n,$s,$f,$l){if(error_reporting()&$n)throw new ErrorException($s,0,$n,$f,$l);return false;});
define('MYSQLI_ASSOC',1);
function check($v,$m){if(!$v)throw new RuntimeException($m);}
class Rows {
 public int $num_rows; private array $data;
 function __construct(array $d){$this->data=$d;$this->num_rows=count($d);}
 function fetch_assoc(){return array_shift($this->data) ?: null;}
 function fetch_all($mode){return $this->data;}
}
class mysqli_stmt {
 private array $values=[];private array $rows=[];
 function __construct(private mysqli $db,private string $sql){}
 function bind_param($types,&...$params){check(strlen($types)===count($params),'binding count');$this->values=$params;return true;}
 function execute(){ $this->db->queries[]=[$this->sql,$this->values];$s=$this->db->pdo->prepare($this->sql);$s->execute($this->values);$this->rows=$s->fetchAll(PDO::FETCH_ASSOC);return true; }
 function get_result(){return new Rows($this->rows);}
}
class mysqli {
 public PDO $pdo;public array $queries=[];
 function __construct(){
  $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $this->pdo->sqliteCreateFunction('CONCAT_WS',fn($sep,...$v)=>implode($sep,array_filter($v,fn($x)=>$x!==null)));
 }
 function prepare($sql){return new mysqli_stmt($this,$sql);}
 function query($sql){
  if(preg_match("/SHOW TABLES LIKE '([^']+)'/",$sql,$m)){
   $s=$this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$m[1]]);return new Rows($s->fetchAll(PDO::FETCH_ASSOC));
  }
  $this->queries[]=[$sql,[]];return new Rows($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
 }
}
$db=new mysqli();
foreach([
 'CREATE TABLE estados(id INTEGER PRIMARY KEY,nombre TEXT)',
 'CREATE TABLE municipios(id INTEGER PRIMARY KEY,nombre TEXT)',
 'CREATE TABLE usuarios(id INTEGER PRIMARY KEY,nombre TEXT,apellidos TEXT)',
 'CREATE TABLE seguimientos_vinculacion(id INTEGER PRIMARY KEY,estado_id INTEGER,municipio_id INTEGER,nombre_entidad TEXT,tipo_entidad TEXT,contacto_nombre TEXT,contacto_cargo TEXT,telefono_fuente TEXT,telefono_verificado TEXT,whatsapp_verificado TEXT,correo_verificado TEXT,correo_fuente TEXT,analista_id INTEGER,activo INTEGER)',
 'CREATE TABLE seguimientos_vinculacion_post_envio(seguimiento_id INTEGER,convenio_fecha TEXT,convenio_notas TEXT,convenio_formalizado_at TEXT)',
 'CREATE TABLE aliados_asignaciones(id INTEGER,seguimiento_id INTEGER,cuenta_clave_usuario_id INTEGER,activo INTEGER)',
 'CREATE TABLE aliados_convocatorias_envios(id INTEGER,seguimiento_id INTEGER,convocatoria_titulo TEXT,canal TEXT,enviado_at TEXT,estado_envio TEXT)',
 'CREATE TABLE aliados_manuales(id INTEGER PRIMARY KEY,estado_id INTEGER,municipio_id INTEGER,nombre_entidad TEXT,correo_contacto TEXT,relacion_fecha TEXT,notas TEXT,activo INTEGER)',
 'CREATE TABLE formulario_aliado_registro(manual_id INTEGER,seguimiento_id INTEGER,publicado INTEGER,habilitado INTEGER,aliado_id INTEGER)',
 'CREATE TABLE formulario_aliado_programas(aliado_id INTEGER,programa_codigo TEXT)',
 'CREATE TABLE formulario_programas(codigo TEXT,nombre TEXT,activo INTEGER)',
] as $sql)$db->pdo->exec($sql);
$db->pdo->exec("INSERT INTO estados VALUES(2,'Baja California'),(8,'Chihuahua'),(9,'No autorizado'); INSERT INTO municipios VALUES(1,'Municipio uno'),(2,'Municipio dos'); INSERT INTO usuarios VALUES(1,'Analista','Prueba'),(7,'Cuenta','Prueba');
 INSERT INTO seguimientos_vinculacion VALUES(1,2,1,'Formalizado',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,1);
 INSERT INTO seguimientos_vinculacion_post_envio VALUES(1,'2026-01-01',NULL,'2026-01-01'); INSERT INTO aliados_asignaciones VALUES(1,1,7,1);");
$stmt=$db->pdo->prepare('INSERT INTO aliados_manuales VALUES(?,?,?,?,?,?,?,?)');
for($i=1;$i<=14;$i++)$stmt->execute([$i,8,($i%2)+1,'Manual '.$i,null,'2026-01-02',null,1]);
$stmt->execute([15,9,1,'Fuera de alcance',null,null,null,1]);
$stmt->execute([16,8,1,'Inactivo',null,null,null,0]);
require __DIR__.'/app/services/ReporteAliadosPanoramaService.php';
class ModeloPrueba extends AliadoModel {
 public array $received=[];
 public function __construct($db){(new ReflectionProperty(AliadoModel::class,'connection'))->setValue($this,$db); }
 public function obtenerDifusionesReporte($ids,$desde=null,$hasta=null){$this->received[]=$ids;return in_array(1,$ids,true)&&(!$desde||$desde<='2026-02-01')&&(!$hasta||$hasta>='2026-02-01')?[['seguimiento_id'=>1,'convocatoria_titulo'=>'Solo institucional','enviado_at'=>'2026-02-01','canal'=>'CORREO']]:[];}
 public function obtenerEventosSeguimientoReporte($ids,$desde=null,$hasta=null){$this->received[]=$ids;return [];}
}
$model=new ModeloPrueba($db);
$service=(new ReflectionClass(ReporteAliadosPanoramaService::class))->newInstanceWithoutConstructor();
$property=new ReflectionProperty($service,'modelo');$property->setValue($service,$model);
function runReport($service,$filters=[],$admin=false){return $service->prepararDatos(7,$admin,$filters+['estado_ids_autorizados'=>[2,8],'situacion'=>'todos','periodo'=>'historico']);}
$fixed=($argv[1]??'')==='after';
$all=runReport($service);
check($all['resumen']['total']===($fixed?15:1),'general total');
$state=runReport($service,['estado_id'=>8]);check($state['resumen']['total']===14,'selected state count');
$municipality=runReport($service,['estado_id'=>8,'municipio_id'=>1]);check($municipality['resumen']['total']===7,'municipality count');
$noScope=runReport($service,['estado_ids_autorizados'=>[]]);check($noScope['resumen']['total']===1,'fail closed for manual scope');
$other=runReport($service,[],true);check($other['resumen']['total']===($fixed?15:1),'admin still respects supplied report territories');
$diffusion=runReport($service,['situacion'=>'con_difusion']);check($diffusion['resumen']['total']===0,'no invented diffusion');
if($fixed){
 check(count($all['detalle'])===15,'detail parity');
 check($all['resumen']['estados']===2 && $all['resumen']['municipios']===2,'coverage');
 check($all['actividad_periodo']['nuevos_aliados']===1,'manual registration is not formalization');
 check($all['actividad_periodo']['difusiones']===1,'institutional activity unchanged');
 $keys=[];
 foreach($all['detalle'] as $row){
  $key=$row['origen_tipo'].':'.$row['origen_id'];check(!isset($keys[$key]),'unique origin identity');$keys[$key]=true;
  if($row['origen_tipo']==='manual')check($row['actividad_periodo']===[] && $row['metricas_periodo']['difusiones']===0,'manual cannot inherit institutional events');
 }
 foreach($model->received as $ids)check($ids===[] || $ids===[1],'only positive institutional ids enter institutional event queries');
 // Una consulta manual por reporte, no una por cada estado, ni conexiones nuevas.
 $manualQueries=array_filter($db->queries,fn($q)=>str_contains($q[0],'FROM aliados_manuales a JOIN estados'));
 foreach($manualQueries as [$sql,$params])check(str_contains($sql,'a.estado_id=') || str_contains($sql,'a.estado_id IN ('),'SQL scoped before fetching');
 $normal=$model->obtenerListado(7,false);check(count($normal)===1,'other module general listing unchanged');
 $onlyMunicipality=runReport($service,['municipio_id'=>2]);check($onlyMunicipality['resumen']['total']===7,'manual municipality without explicit state');
 $oldPeriod=runReport($service,['periodo'=>'personalizado','fecha_desde'=>'2020-01-01','fecha_hasta'=>'2020-01-31']);
 check($oldPeriod['resumen']['total']===15 && $oldPeriod['actividad_periodo']['difusiones']===0 && $oldPeriod['actividad_periodo']['nuevos_aliados']===0,'snapshot stable and activity bounded by period');
}
// Ambas salidas consumen el mismo conjunto; no se genera PDF ni se cargan recursos remotos.
define('BASE_URL','/test/');$reporteAliados=$all;$generarReporte=true;
ob_start();require __DIR__.'/app/views/reportes/aliados.php';$html=ob_get_clean();
require __DIR__.'/app/services/ReporteAliadosPdfService.php';
$pdf=(new ReflectionMethod(ReporteAliadosPdfService::class,'construirHtml'))->invoke(new ReporteAliadosPdfService(),$all);
foreach([$html,$pdf] as $output){
 check(str_contains($output,'Formalizado') && !str_contains($output,'Fuera de alcance') && !str_contains($output,'Inactivo'),'render respects scope');
 for($i=1;$i<=14;$i++)check(str_contains($output,'Manual '.$i) === $fixed,'manual rows in HTML and PDF template');
}
foreach($db->queries as [$sql,$params])check(preg_match('/^\s*(SELECT|SHOW)\b/i',$sql)===1,'application executed no writes');
check(Database::$opens===0,'no new connection in report path');
$reader=new ReporteAliadosManualService($db);
$queries=count($db->queries);
check($reader->obtener([],0,0)===[] && $reader->obtener([2,8],9,0)===[] && count($db->queries)===$queries,'fail closed before reading manual data');
$db->pdo->exec("CREATE TABLE aliados_manuales_difusiones(id INTEGER,aliado_id INTEGER,realizado_at TEXT,canal TEXT);
 INSERT INTO aliados_manuales_difusiones VALUES(1,1,'2026-02-01','CORREO'),(2,1,'2026-02-01','WHATSAPP'),(3,15,'2026-02-02','CORREO')");
$withHistory=$reader->obtener([2,8],0,0);
$first=array_values(array_filter($withHistory,fn($row)=>(int)$row['origen_id']===1))[0];
check((int)$first['ultimo_envio_id']===2 && $first['ultimo_envio_canal']==='WHATSAPP','latest manual event tie is resolved by id');
check(runReport($service)['resumen']['con_difusion']===1,'manual snapshot uses actual history');
$db->pdo->exec('DROP TABLE aliados_manuales');
check(runReport($service)['resumen']['total']===1,'installation without optional manual table stays functional');
echo json_encode(['status'=>'PASS','profile'=>$fixed?'corrected':'baseline','total'=>$all['resumen']['total'],'state_total'=>$state['resumen']['total'],'municipality_total'=>$municipality['resumen']['total'],'new_connections'=>Database::$opens]);
