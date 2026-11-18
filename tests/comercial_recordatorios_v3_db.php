<?php
if(PHP_SAPI!=='cli' || getenv('IMPE_COMERCIAL_RECORDATORIOS_TEST')!=='YES')exit(2);
require __DIR__.'/../app/services/EmbudoComercialV3RecordatoriosService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function comprobarAviso(bool $ok,string $caso): void {if(!$ok)throw new RuntimeException($caso);}
function negarAviso(callable $fn): void {try{$fn();}catch(DomainException $e){return;}throw new RuntimeException('Acceso indebido');}
$_SESSION=['usuario_id'=>22,'rol_id'=>3,'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']];
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'',(int)getenv('MYSQL_PORT'));
$db->set_charset('utf8mb4');$nombre='impe_avisos_test_'.bin2hex(random_bytes(5));
$db->query("CREATE DATABASE $nombre CHARACTER SET utf8mb4");$db->select_db($nombre);
try {
 foreach([
  'prospectos_comerciales(id BIGINT UNSIGNED PRIMARY KEY,nombre_completo VARCHAR(255))',
  'prospecto_asignaciones(id BIGINT UNSIGNED PRIMARY KEY,prospecto_id BIGINT UNSIGNED,asesor_id INT,vigente INT)',
  'prospecto_embudo_etapas(asignacion_id BIGINT UNSIGNED PRIMARY KEY,etapa VARCHAR(40),cambiado_por INT,actualizado_at DATETIME)',
  'prospecto_embudo_movimientos(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,asignacion_id BIGINT UNSIGNED,etapa_anterior VARCHAR(40),etapa_nueva VARCHAR(40),motivo VARCHAR(190),usuario_id INT,creado_at DATETIME)',
  'prospecto_embudo_llamadas(asignacion_id BIGINT UNSIGNED,usuario_id INT,extension VARCHAR(20),pbx_call_id VARCHAR(100),creado_at DATETIME)'
 ] as $tabla)$db->query("CREATE TABLE $tabla ENGINE=InnoDB");
 foreach(['2026_11_11_ventas_subetapas_comerciales_propuesta.sql','2026_11_12_ventas_seguimiento_operativo_v3.sql','2026_11_18_ventas_tipo_actividad_v3.sql'] as $archivo){
  $sql=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/migrations/'.$archivo));
  foreach(explode(';',$sql) as $parte)if(trim($parte)!=='')$db->query($parte);
 }
 $ahora=new DateTimeImmutable('2099-02-01 18:00:00',new DateTimeZone('UTC'));
 $etapas=['NUEVO','CONTACTADO','POTENCIAL','SEGUIMIENTO','PROCESO_INSCRIPCION','INTERESADO','RECUPERACION','DESCARTADO'];
 for($id=1;$id<=100;$id++){
  $etapa=$etapas[($id-1)%6];if($id===7)$etapa='RECUPERACION';if($id===8)$etapa='DESCARTADO';
  $asesor=$id===12?23:22;$vigente=$id===10?'NULL':($id===11?'0':'1');
  $segundos=[1=>10800,2=>3600,3=>600,4=>0,5=>10801,6=>86400][$id]??3600;
  $fecha=$ahora->modify('+'.$segundos.' seconds')->format('Y-m-d H:i:s');
  $db->query("INSERT INTO prospectos_comerciales VALUES($id,'Prospecto sintético $id')");
  $db->query("INSERT INTO prospecto_asignaciones VALUES($id,$id,$asesor,$vigente)");
  $db->query("INSERT INTO prospecto_embudo_etapas VALUES($id,'$etapa',22,UTC_TIMESTAMP())");
  $db->query("INSERT INTO prospecto_embudo_actividad_v3(asignacion_id,usuario_id,etapa,accion,motivo,fecha_pendiente,tipo_actividad,detalle_actividad,creado_at)
    VALUES($id,$asesor,'$etapa','PROGRAMAR','Revisar certificado.','$fecha','DOCUMENTOS','Revisar certificado <prueba>.',UTC_TIMESTAMP())");
 }
 $db->query("INSERT INTO prospecto_embudo_subetapas VALUES(9,'NO_CONTACTAR',22,UTC_TIMESTAMP(),1)");
 $svc=new EmbudoComercialV3RecordatoriosService($db,$ahora);
 $antes=$db->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete','Com_alter_table')")->fetch_all(MYSQLI_ASSOC);
 $feed=$svc->obtener(22);$porId=array_column($feed['recordatorios'],null,'comercial_id');
 comprobarAviso(count($porId)>80,'sin truncamiento por doce avisos o veinte tarjetas');
 foreach([7,8,9,10,11,12] as $id)comprobarAviso(!isset($porId[$id]),'sin registros fuera de alcance '.$id);
 foreach([1=>'3H',2=>'1H',3=>'10M',4=>'VENCIDA',5=>'',6=>''] as $id=>$ventana){
  comprobarAviso($porId[$id]['ventana']===$ventana,'ventana heredada '.$id);
 }
 comprobarAviso($porId[1]['fecha']==='2099-02-01T21:00:00Z' && str_contains($porId[1]['fecha_texto'],'3:00 p. m.'),'UTC y México independientes del navegador');
 comprobarAviso($porId[1]['detalle']==='Revisar certificado <prueba>.','detalle conservado sin interpretar HTML');
 comprobarAviso($antes===$db->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_insert','Com_update','Com_delete','Com_alter_table')")->fetch_all(MYSQLI_ASSOC),'consultar no modifica tareas ni acuses');
 negarAviso(fn()=>$svc->obtener(23));$_SESSION['rol_id']=1;negarAviso(fn()=>$svc->obtener(22));
 $_SESSION['rol_id']=3;$_SESSION['permisos']=[];negarAviso(fn()=>$svc->obtener(22));
 $_SESSION['permisos']=['ventas.embudo.ver','ventas.embudo.mover'];
 $gestion=new EmbudoComercialV3SeguimientoService($db);
 $version=(int)$db->query('SELECT MAX(id) FROM prospecto_embudo_actividad_v3 WHERE asignacion_id=1')->fetch_row()[0];
 $ciclo=$porId[1]['actividad_ciclo'];
 $version=$gestion->registrar(22,1,'NUEVO',$version,'REGISTRAR','Nota independiente de tarea.','','','');
 $nuevo=array_column($svc->obtener(22)['recordatorios'],null,'comercial_id');
 comprobarAviso($nuevo[1]['actividad_ciclo']===$ciclo && $nuevo[1]['detalle']===$porId[1]['detalle'],'nota no reinicia anticipaciones ni sustituye tarea');
 $version=$gestion->registrar(22,1,'NUEVO',$version,'REPROGRAMAR','Se acordó otra fecha.','2099-02-02T10:00','','');
 $nuevo=array_column($svc->obtener(22)['recordatorios'],null,'comercial_id');
 comprobarAviso($nuevo[1]['actividad_ciclo']!==$ciclo && $nuevo[1]['fecha']==='2099-02-02T16:00:00Z' && $nuevo[1]['ventana']==='','reprogramar invalida ciclo y fecha anteriores');
 $gestion->registrar(22,1,'NUEVO',$version,'COMPLETAR','Certificado revisado.','','','');
 comprobarAviso(!isset(array_column($svc->obtener(22)['recordatorios'],null,'comercial_id')[1]),'completar retira aviso sin borrar historial');
 $db->query("UPDATE prospecto_embudo_etapas SET etapa='RECUPERACION' WHERE asignacion_id=2");
 comprobarAviso(!isset(array_column($svc->obtener(22)['recordatorios'],null,'comercial_id')[2]),'recuperación no mantiene recordatorio de contacto');
 $db->query("UPDATE prospecto_embudo_etapas SET etapa='DESCARTADO' WHERE asignacion_id=3");
 comprobarAviso(!isset(array_column($svc->obtener(22)['recordatorios'],null,'comercial_id')[3]),'descarte retira aviso');
 $db->query("INSERT INTO prospecto_embudo_llamadas VALUES(1,22,'157','pbx_enlazada','2000-01-01 00:00:00')");
 $filas=[['origen'=>'resultado','id'=>1,'extension'=>'157','pbx_call_id'=>'pbx_enlazada'],
  ['origen'=>'resultado','id'=>2,'extension'=>'157','pbx_call_id'=>'pbx_personal'],
  ['origen'=>'agenda','id'=>3,'extension'=>'','pbx_call_id'=>'']];
 comprobarAviso(array_column($svc->filtrarTelefonia(22,$filas),'id')===[2,3],'telefonía no duplica tarea vinculada completada; conserva agenda personal');
 // Endpoint real con sesión de asesor y sin permiso de Telefonía.
 $worker=tempnam(sys_get_temp_dir(),'impe-avisos-endpoint-');
 file_put_contents($worker,'<?php $_SESSION=["usuario_id"=>22,"rol_id"=>3,"permisos"=>["ventas.embudo.ver"]];require '.var_export(__DIR__.'/../app/controllers/ReminderController.php',true).';(new ReflectionClass(ReminderController::class))->newInstanceWithoutConstructor()->pendientes();');
 $env=getenv();$env['IMPE_DB_HOST']='127.0.0.1';$env['IMPE_DB_USER']=getenv('MYSQL_USER');$env['IMPE_DB_PASSWORD']=getenv('MYSQL_PASSWORD');$env['IMPE_DB_NAME']=$nombre;
 try{
  $proceso=proc_open([PHP_BINARY,'-d','mysqli.default_port='.getenv('MYSQL_PORT'),$worker],
   [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
  comprobarAviso(is_resource($proceso),'endpoint aislado');fclose($pipes[0]);
  $salida=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  comprobarAviso(proc_close($proceso)===0 && $error==='','endpoint: '.$error);
  $resultado=json_decode($salida,true,512,JSON_THROW_ON_ERROR);
  comprobarAviso($resultado['ok'] && $resultado['comercial_ok'] && $resultado['total']>80 && count($resultado['recordatorios'])===$resultado['total'],'campana real sin telefonía y con conteo completo');
 }finally{unlink($worker);}
 echo "CHECK_OK recordatorios comerciales: 3H/1H/10M/vencimiento, 80+ tareas, México, permisos, consulta sin escrituras, nota, reprogramación, cumplimiento, recuperación, descarte, PBX sin duplicados y endpoint real.\n";
} finally{$db->query("DROP DATABASE $nombre");$db->close();}
