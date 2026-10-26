<?php
require __DIR__.'/supervision_llamadas_db.php';
require __DIR__.'/../app/services/DirectorOperacionesDashboardService.php';
$db->query('ALTER TABLE estados ADD nombre_corto VARCHAR(80)');
$db->query('UPDATE estados SET nombre_corto=nombre');
for($i=3;$i<=32;$i++)$db->query("INSERT INTO estados(id,nombre,nombre_corto,estado) VALUES($i,'Estado $i','Estado $i',1)");
$db->query("INSERT INTO estados(id,nombre,nombre_corto,estado) VALUES(33,'Estado inactivo','Inactivo',0)");
$db->query('CREATE TABLE municipios(id INT PRIMARY KEY,nombre VARCHAR(100))');
$db->query("INSERT INTO municipios VALUES(1,'Municipio de prueba')");
$db->query('CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,estado_id INT,municipio_id INT,analista_id INT,nombre_entidad VARCHAR(250),tipo_entidad VARCHAR(40),estado_seguimiento VARCHAR(40),proxima_accion_at DATETIME NULL,ultima_interaccion_at DATETIME NULL,fecha_inicio DATETIME,activo INT)');
$db->query('CREATE TABLE seguimientos_vinculacion_post_envio(seguimiento_id INT PRIMARY KEY,convenio_formalizado_at DATETIME NULL)');
$db->query('CREATE TABLE reuniones_vinculacion(id INT PRIMARY KEY,seguimiento_id INT,analista_id INT,fecha_propuesta DATETIME,estado VARCHAR(40),duracion_minutos INT,cambio_solicitado_at DATETIME NULL)');
$db->query('CREATE TABLE convocatorias(id INT PRIMARY KEY,fecha_inicio DATE NULL,fecha_termino DATE NULL,estado INT)');
$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
$future=(new DateTimeImmutable($today))->modify('+2 days')->format('Y-m-d');
foreach([1=>[401,1,'EN_PROCESO',1],2=>[402,2,'EN_PROCESO',1],3=>[401,1,'EN_PROCESO',1],4=>[401,1,'EN_PROCESO',1],5=>[401,1,'DESCARTADO',1],6=>[401,1,'EN_PROCESO',0]] as $id=>[$analyst,$state,$status,$active])
 $db->query("INSERT INTO seguimientos_vinculacion VALUES($id,$state,1,$analyst,'Institución $id <script>hostil</script>','PUBLICA','$status','$yesterday 08:00:00','$today 08:00:00','$yesterday 08:00:00',$active)");
$db->query("INSERT INTO seguimientos_vinculacion_post_envio VALUES(3,'$today 08:00:00')");
$db->query("INSERT INTO reuniones_vinculacion VALUES(1,4,401,'$future 12:00:00','SOLICITADA',60,NULL),(2,1,401,'$future 12:00:00','CANCELADA',60,NULL),(3,2,402,'$future 12:00:00','REALIZADA',60,NULL)");
$db->query("INSERT INTO convocatorias VALUES(1,'$yesterday','$future',1),(2,'$yesterday','$yesterday',1),(3,'$yesterday','$future',0),(4,'$future','$future',1)");
$codes=['usuarios.ver','territorios.ver','seguimientos_vinculacion.ver','reuniones.ver','convenios.ver','convocatorias.ver','aliados.ver','prospectos.ver_todos'];
foreach($codes as $code){$s=$db->prepare("INSERT IGNORE INTO permisos(modulo,codigo,nombre,estado) VALUES('Inicio',?,?,1)");$s->bind_param('ss',$code,$code);$s->execute();$db->query("INSERT IGNORE INTO rol_permisos SELECT 2,id FROM permisos WHERE codigo='$code'");}
$before=[];foreach($db->query('SHOW TABLES')->fetch_all() as [$t])$before[$t]=$db->query("SELECT * FROM `$t`")->fetch_all(MYSQLI_ASSOC);
sessionFor($db,2,20);$svc=new DirectorOperacionesDashboardService($db);$dashboard=$svc->obtener(20);
qcheck(!$dashboard['avisos'],'Dashboard sin fallos parciales: '.json_encode($dashboard['avisos']));
qcheck($dashboard['resumen']['territorios']===32&&count($dashboard['cobertura'])===32,'32 estados sin asignar territorio al Director');
qcheck($dashboard['resumen']['equipo']===[3=>2,4=>2,6=>2],'Equipo nacional activo sin duplicar asignaciones');
qcheck($dashboard['resumen']['seguimientos']===3&&$dashboard['resumen']['convenios']===1,'Cartera sin descartados, inactivos ni aliados');
qcheck($dashboard['resumen']['convocatorias']===1,'Vigencia real sin activar o desactivar publicaciones');
qcheck($dashboard['resumen']['atenciones']===2,'Misma atención de Analista, con alcance nacional');
qcheck(in_array(402,array_map('intval',array_column($dashboard['atenciones'],'analista_id')),true),'Incluye analista del otro equipo');
qcheck(count($dashboard['agenda'])===1&&$dashboard['agenda'][0]['estado']==='SOLICITADA','Agenda futura sin canceladas o realizadas');
qcheck($dashboard['ventas']['resumen']['llamadas']===5,'Mismos datos de actividad telefónica de asesores');
qcheck(!isset($dashboard['ventas']['llamadas']),'El Inicio no transporta el historial completo');
foreach([10,301,401,601] as $uid){try{$svc->obtener($uid);throw new LogicException('Otro rol accedió');}catch(DomainException $e){}}
$db->query("UPDATE permisos SET estado=0 WHERE codigo='convocatorias.ver'");$limited=$svc->obtener(20);
qcheck(!$limited['permisos']['convocatorias']&&!isset($limited['resumen']['convocatorias']),'Desactivación de permiso quita datos');
$db->query("UPDATE permisos SET estado=1 WHERE codigo='convocatorias.ver'");
$db->query('RENAME TABLE convocatorias TO convocatorias_test_no_disponible');$failure=$svc->obtener(20);
qcheck(count($failure['avisos'])===1&&!isset($failure['resumen']['convocatorias'])&&$failure['resumen']['territorios']===32,'Un fallo no se muestra como cero ni derriba el Inicio');
$db->query('RENAME TABLE convocatorias_test_no_disponible TO convocatorias');
$grants=$db->query('SELECT * FROM rol_permisos WHERE rol_id=2')->fetch_all(MYSQLI_ASSOC);
$db->query('DELETE FROM rol_permisos WHERE rol_id=2');$empty=$svc->obtener(20);
qcheck(!array_filter($empty['permisos'])&&!$empty['resumen']&&!$empty['avisos'],'Sin permisos no consulta ni revela módulos');
foreach($grants as $g)$db->query('INSERT INTO rol_permisos VALUES(2,'.(int)$g['permiso_id'].')');
foreach($before as $t=>$rows){$actual=$db->query("SELECT * FROM `$t`")->fetch_all(MYSQLI_ASSOC);$sort=static function(&$r){usort($r,static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));};$sort($rows);$sort($actual);qcheck($actual===$rows,'Dashboard modificó '.$t);}
echo "Inicio Dirección: alcance nacional, 32 estados, permisos vigentes, prioridades compartidas, agenda, telefonía y sólo lectura OK\n";
