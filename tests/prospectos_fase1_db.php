<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_prospectos_fase1_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require __DIR__.'/../app/models/ProspectoModel.php';$db=(new Database())->connect(false);$count=0;
$check=static function($v,$m)use(&$count){if(!$v)throw new RuntimeException($m);$count++;echo "OK $m\n";};
$snapshot=static function()use($db){$result=[];foreach($db->query('SHOW TABLES')->fetch_all() as [$table])$result[$table]=$db->query("SELECT * FROM `$table` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($result));};$before=$snapshot();
$m=new ProspectoModel();$prop=new ReflectionProperty($m,'connection');$connection=$prop->getValue($m);$connection->query('START TRANSACTION READ ONLY');
$s=$m->resumen();$check($s['total']===62&&$s['aliados']===20&&$s['generales']===42,'Indicadores reales, sin duplicar procedencias ni contar Inscripción como Registro');
$t=$m->territorios();$check(count($t)===32,'Catálogo completo: 32 estados activos');$map=array_column($t,'total','id');$check((int)$map[17]===29&&(int)$map[23]===26&&(int)$map[1]===0,'Conteos por residencia y estado vacío');
$check(count(array_filter($t,fn($r)=>(int)$r['total']>0))===4,'Cuatro estados activos con registros');
$all=$m->listar();$check($all['total']===62&&count($all['registros'])===25&&$all['paginas']===3,'Todos incluye generales, aliados e históricos: paginación de 25');
$ids=array_map('intval',array_column($all['registros'],'id'));$check($ids===range(62,38),'Orden por fecha e identificador descendentes');
$first=$m->listar(1,25,['estado_id'=>17]);$second=$m->listar(2,25,['estado_id'=>17]);$check($first['total']===29&&$first['paginas']===2&&count($second['registros'])===4,'Total y páginas sobre el mismo estado filtrado');
$check(array_column($first['registros'],'estado')===array_fill(0,25,'Morelos'),'Residencia independiente del aliado');
$check(!array_intersect(array_column($first['registros'],'id'),array_column($second['registros'],'id')),'Sin filas duplicadas entre páginas');
$empty=$m->listar(1,25,['estado_id'=>1]);$check($empty['total']===0&&$empty['registros']===[],'Estado activo sin prospectos');
$check($m->listar(99,25,['estado_id'=>17])['pagina']===2,'Página fuera de rango ajustada al conjunto filtrado');
$check($m->listar(1,25,['estado_id'=>17,'buscar'=>'Prospecto 29'])['total']===1,'Búsqueda existente combinada con residencia');
$check($m->listar(1,25,['estado_id'=>17,'aliado_id'=>'7'])['total']===20,'Filtro de aliado existente no multiplica registros');
$check($m->listar(1,25,['estado_id'=>17,'aliado_id'=>'general'])['total']===9,'Formulario general dentro del estado');
$check($m->listar(1,25,['buscar'=>"' OR 1=1 --"])['total']===0,'Parámetros de búsqueda preparados');
foreach([0,-1,'1 OR 1=1',99,999999] as $invalid){try{$m->listar(1,25,['estado_id'=>$invalid]);throw new RuntimeException('Estado inválido aceptado');}catch(DomainException $e){$check(true,'Estado inválido/inactivo rechazado');}}
$connection->rollback();$check($snapshot()===$before,'Consultas ejecutadas en transacción READ ONLY; datos históricos y otros módulos intactos');
try{$db->query('RENAME TABLE formulario_aliado_procedencias TO pruebas_procedencias_temporal');$missing=new ProspectoModel();$s=$missing->resumen();$check($s['total']===62&&$s['aliados']===null&&$s['generales']===null,'Dependencia ausente: procedencia sin verificar, nunca cero inventado');$check($missing->listar()['total']===62,'Sin procedencias se conserva la consulta general');try{$missing->listar(1,25,['aliado_id'=>'general']);throw new RuntimeException('Filtro no verificable aceptado');}catch(DomainException $e){$check(true,'Filtro de origen no disponible se informa');}}finally{$db->query('RENAME TABLE pruebas_procedencias_temporal TO formulario_aliado_procedencias');}
$check($snapshot()===$before,'Fixture restaurada sin cambios de datos');echo "PASS $count comprobaciones DB · ".$db->server_info."\n";
