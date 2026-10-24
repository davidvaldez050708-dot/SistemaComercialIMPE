<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_prospectos_fase1_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require __DIR__.'/../app/models/ProspectoModel.php';$db=(new Database())->connect(false);$count=0;
$check=static function($v,$m)use(&$count){if(!$v)throw new RuntimeException($m);$count++;echo "OK $m\n";};
$snapshot=static function()use($db){$result=[];foreach($db->query('SHOW TABLES')->fetch_all() as [$table])$result[$table]=$db->query("SELECT * FROM `$table` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($result));};$before=$snapshot();
$m=new ProspectoModel();$prop=new ReflectionProperty($m,'connection');$connection=$prop->getValue($m);$connection->query('START TRANSACTION READ ONLY');
$s=$m->resumen();$check($s['total']===62&&$s['aliados']===40&&$s['generales']===22,'Indicadores reales, sin duplicar procedencias ni contar Inscripción como Registro');
$t=$m->territorios();$check(count($t)===32,'Catálogo completo: 32 estados activos');$map=array_column($t,'total','id');$check((int)$map[11]===29&&(int)$map[22]===6&&(int)$map[17]===0&&(int)$map[1]===0,'Estado del aliado manual, institucional y legado; nunca residencia');
$check(array_sum($map)===35&&count(array_filter($t,fn($r)=>(int)$r['total']>0))===2,'No asigna estado inventado a generales, orígenes no vinculados, ambiguos o inactivos del catálogo');
$all=$m->listar();$check($all['total']===62&&count($all['registros'])===25&&$all['paginas']===3,'Todos conserva generales y aliados sin territorio: paginación de 25');
$check($m->listar(1,100,['aliado_id'=>'general'])['total']===22,'Generales e Inscripción no se atribuyen a aliados de Registro');
$ids=array_map('intval',array_column($all['registros'],'id'));$check($ids===range(62,38),'Orden por fecha e identificador descendentes');
$first=$m->listar(1,25,['estado_id'=>11]);$second=$m->listar(2,25,['estado_id'=>11]);$check($first['total']===29&&$first['paginas']===2&&count($second['registros'])===4,'Total y páginas coinciden con tarjeta del estado del aliado');
$check(array_column($first['registros'],'estado')===array_fill(0,25,'Morelos'),'Residencia permanece visible aunque el aliado sea de Guanajuato');
$check(!array_intersect(array_column($first['registros'],'id'),array_column($second['registros'],'id')),'Sin duplicados entre páginas');
$check($m->listar(1,100,['estado_id'=>22])['total']===6,'Vinculación histórica sin perfil de Registro moderno sigue vinculada');
$check($m->listar(1,100,['aliado_id'=>'9'])['total']===6,'Una procedencia posterior no contradice el aliado mostrado');
$check($m->listar(1,25,['estado_id'=>1])['total']===0,'Estado activo sin prospectos');
$check($m->listar(99,25,['estado_id'=>11])['pagina']===2,'Página fuera de rango ajustada al conjunto filtrado');
$check($m->listar(1,25,['estado_id'=>11,'buscar'=>'Prospecto 29'])['total']===1,'Búsqueda combinada con estado del aliado');
$check($m->listar(1,25,['estado_id'=>11,'aliado_id'=>'7'])['total']===20,'Aliado manual histórico inactivo conserva sus registros');
$check($m->listar(1,25,['estado_id'=>11,'aliado_id'=>'8'])['total']===9,'Aliado institucional histórico inactivo conserva sus registros');
$check($m->listar(1,25,['estado_id'=>11,'aliado_id'=>'general'])['total']===0,'Generales sólo en Todos, nunca clasificados por residencia');
$check($m->listar(1,25,['buscar'=>"' OR 1=1 --"])['total']===0,'Parámetros de búsqueda preparados');
foreach([0,-1,'1 OR 1=1',99,999999] as $invalid){try{$m->listar(1,25,['estado_id'=>$invalid]);throw new RuntimeException('Estado inválido aceptado');}catch(DomainException $e){$check(true,'Estado inválido/inactivo rechazado');}}
$connection->rollback();$check($snapshot()===$before,'Consultas ejecutadas en transacción READ ONLY; datos históricos y otros módulos intactos');
try{$db->query('RENAME TABLE formulario_aliado_procedencias TO pruebas_procedencias_temporal');$missing=new ProspectoModel();$s=$missing->resumen();$check($s['total']===62&&$s['aliados']===null&&$s['generales']===null,'Dependencia ausente: procedencia sin verificar, nunca cero inventado');$check($missing->listar()['total']===62,'Sin procedencias se conserva la consulta general');try{$missing->territorios();throw new RuntimeException('Conteos no verificables aceptados');}catch(DomainException $e){$check(true,'Sin procedencias no se inventan conteos territoriales');}try{$missing->listar(1,25,['aliado_id'=>'general']);throw new RuntimeException('Filtro no verificable aceptado');}catch(DomainException $e){$check(true,'Filtro de origen no disponible se informa');}}finally{$db->query('RENAME TABLE pruebas_procedencias_temporal TO formulario_aliado_procedencias');}
$check($snapshot()===$before,'Fixture restaurada sin cambios de datos');echo "PASS $count comprobaciones DB · ".$db->server_info."\n";
