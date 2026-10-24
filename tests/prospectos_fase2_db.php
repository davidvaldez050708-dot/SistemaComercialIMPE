<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_prospectos_fase1_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require __DIR__.'/../app/models/ProspectoModel.php';$db=(new Database())->connect(false);$count=0;
$check=static function($v,$m)use(&$count){if(!$v)throw new RuntimeException($m);$count++;echo "OK $m\n";};
$snapshot=static function()use($db){$result=[];foreach($db->query('SHOW TABLES')->fetch_all() as [$table])$result[$table]=$db->query("SELECT * FROM `$table` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($result));};$before=$snapshot();
$m=new ProspectoModel();$connection=(new ReflectionProperty($m,'connection'))->getValue($m);$connection->query('START TRANSACTION READ ONLY');
$key=static fn($tipo,$valor)=>ProspectoModel::clavePrograma($tipo,$valor);
$general=$key('a','bachillerato-general');$dos=$key('a','bachillerato-2-anos');$ingles=$key('a','ingles');
$gGeneral=$key('g','Bachillerato General');$gMinuscula=$key('g','Bachillerato general');$gIngles=$key('g','Inglés');
$all=$m->listar();$check($all['total']===62&&$m->resumen()['generales']===22,'Todos y registros generales se conservan');
$state=$m->listar(1,25,['estado_id'=>11]);$check(array_map('intval',array_column($state['aliados'],'id'))===[7,8],'Catálogo de aliados del territorio, por ID real');
$check($state['aliados'][0]['nombre']===$state['aliados'][1]['nombre'],'Nombres duplicados no se usan como identidad');
$one=$m->listar(1,25,['estado_id'=>11,'aliado_id'=>'7']);$two=$m->listar(1,25,['estado_id'=>11,'aliado_id'=>'8']);
$check($one['total']===20&&$two['total']===9,'Mismo nombre, conjuntos independientes de aliados');
$check(array_column($one['programas'],'valor')===[$general],'Programas desde prospectos, aunque hayan sido retirados de la oferta');
$check(count($two['programas'])===2&&in_array($dos,array_column($two['programas'],'valor'),true),'Programas del otro aliado actualizados con datos reales');
$check(!in_array($key('a','sin-prospectos'),array_column($all['programas'],'valor'),true),'Un programa autorizado sin prospectos no aparece en las opciones');
$check($m->listar(1,25,['aliado_id'=>'9','programa'=>$ingles])['total']===6,'Programa inactivo conserva prospectos históricos');
$check($m->listar(1,25,['programa'=>$general])['total']===31,'Programa del aliado usa procedencia, sin confundir perfil general del mismo nombre');
$check($m->listar(1,25,['programa'=>$gGeneral])['total']===8,'Perfil general se identifica por su valor real');
$check($m->listar(1,25,['programa'=>$gMinuscula])['total']===8,'Perfiles distintos se mantienen distintos, sin equivalencias inventadas');
$check($m->listar(1,25,['aliado_id'=>'general','programa'=>$gIngles])['total']===5,'Registro general filtra su perfil sin atribuir códigos de aliado');
$check($m->listar(1,25,['aliado_id'=>'general'])['total']===22,'Inscripción no cuenta como procedencia de Registro');
$match=$m->listar(1,25,['estado_id'=>11,'aliado_id'=>'8','programa'=>$dos,'buscar'=>'prueba29@example.test']);
$check($match['total']===1&&(int)$match['registros'][0]['id']===29,'Combinación de estado, ID, código y correo');
$check($m->listar(1,25,['estado_id'=>11,'aliado_id'=>'8','programa'=>$general,'buscar'=>'prueba29@example.test'])['total']===0,'Una búsqueda no atraviesa el programa elegido');
$check($m->listar(1,25,['aliado_id'=>'7','programa'=>$general,'buscar'=>'Prospecto 2'])['total']===2,'Nombre combinado con aliado y programa');
$check($m->listar(1,25,['aliado_id'=>'7','programa'=>$general,'buscar'=>'7771234567'])['total']===20,'Teléfono combinado con aliado y programa');
$first=$m->listar(1,25,['estado_id'=>11,'programa'=>$general]);$second=$m->listar(2,25,['estado_id'=>11,'programa'=>$general]);
$check($first['total']===26&&$first['paginas']===2&&count($second['registros'])===1,'Conteo y páginas comparten los cuatro filtros');
$check(!array_intersect(array_column($first['registros'],'id'),array_column($second['registros'],'id')),'Procedencias repetidas no duplican páginas');
$check(array_map('intval',array_column($first['registros'],'id'))===range(26,2),'Orden por fecha y ID conservado con filtros');
$stale=$m->listar(1,25,['aliado_id'=>'9','programa'=>$dos]);$check($stale['programa_aplicado']===''&&$stale['total']===6,'Cambiar aliado restablece un programa incompatible');
$check($m->listar(1,25,['estado_id'=>11,'aliado_id'=>'9'])['total']===0,'Una URL con aliado de otro estado no amplía territorio');
$check($m->listar(1,25,['estado_id'=>11,'aliado_id'=>'general'])['total']===0,'Generales no se trasladan a un estado por su residencia');
foreach([['aliado_id'=>'7 OR 1=1'],['aliado_id'=>0],['aliado_id'=>['7']],['programa'=>['x']],['programa'=>"a:' OR 1=1"],['buscar'=>['x']],['aliado_id'=>'999999']] as $invalid){try{$m->listar(1,25,$invalid);throw new RuntimeException('Filtro inválido aceptado');}catch(DomainException $e){$check(true,'Parámetro inválido controlado');}}
$connection->rollback();$check($snapshot()===$before,'Integridad de todos los registros y otros módulos; consultas READ ONLY');
try{
 $db->query('RENAME TABLE formulario_programas TO programas_temporal');$legacy=new ProspectoModel();$row=$legacy->listar(1,25,['aliado_id'=>'9','programa'=>$ingles]);
 $check($row['total']===6&&str_contains($row['programas'][0]['nombre'],'Código: ingles'),'Sin catálogo, el código histórico sigue siendo inequívoco');
}finally{$db->query('RENAME TABLE programas_temporal TO formulario_programas');}
try{
 $db->query('ALTER TABLE formulario_programas CHANGE nombre nombre_temporal VARCHAR(180)');$bad=new ProspectoModel();$row=$bad->listar();
 $check($row['total']===62&&$row['error_programas']==='No fue posible cargar los programas.','Error del catálogo controlado: no pierde registros ni inventa opciones');
 try{$bad->listar(1,25,['programa'=>$general]);throw new RuntimeException('Filtro no verificable descartado silenciosamente');}catch(DomainException $e){$check(true,'Fallo de programas no descarta silenciosamente un filtro activo');}
}finally{$db->query('ALTER TABLE formulario_programas CHANGE nombre_temporal nombre VARCHAR(180)');}
$check($snapshot()===$before,'Esquema sintético restaurado; registros históricos intactos');
echo "PASS $count comprobaciones Fase 2 DB · ".$db->server_info."\n";
