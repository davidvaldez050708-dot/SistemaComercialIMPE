<?php
declare(strict_types=1);
// Sólo base efímera local, sin cargar credenciales ni registros del CRM.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli('127.0.0.1', 'root', '', '', 33319);
$database = 'impe_cartera_test_' . bin2hex(random_bytes(5));
$server->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$db = new mysqli('127.0.0.1','root','',$database,33319);
$db->set_charset('utf8mb4');putenv('CARTERA_TEST_DATABASE='.$database);$GLOBALS['cartera_test_db']=$db;
$root=getenv('CARTERA_TEST_PROJECT');$source=getenv('CARTERA_TEST_SOURCE');
define('BASE_URL','https://example.test/');define('ROOT_PATH',$root);
ini_set('error_log',$root.'/test-errors.log');
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function callPrivate($object,$method,...$args){$m=new ReflectionMethod($object,$method);$m->setAccessible(true);return $m->invoke($object,...$args);}
function seed($table,array $values): void {
 global $db;
 foreach($db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC) as $col){
  $name=$col['Field'];if(array_key_exists($name,$values)||$col['Null']==='YES'||$col['Default']!==null||str_contains($col['Extra'],'auto_increment'))continue;
  $type=$col['Type'];
  if(str_starts_with($type,'enum(')){preg_match("/enum\\('([^']*)'/",$type,$m);$values[$name]=$m[1];}
  elseif(preg_match('/int|decimal|float|double/',$type))$values[$name]=1;
  elseif(str_starts_with($type,'date'))$values[$name]=date(str_starts_with($type,'datetime')?'Y-m-d H:i:s':'Y-m-d');
  else $values[$name]=$name.'_'.($values['id']??uniqid());
 }
 $cols='`'.implode('`,`',array_keys($values)).'`';$q=$db->prepare("INSERT INTO `$table` ($cols) VALUES (".implode(',',array_fill(0,count($values),'?')).')');$params=array_values($values);$q->bind_param(str_repeat('s',count($params)),...$params);$q->execute();
}
function sessionAs(int $id,int $role): void {$_SESSION=['usuario_id'=>$id,'rol_id'=>$role,'rol'=>$role===4?'Analista de Datos':($role===6?'Cuenta Clave':'Director de Operaciones'),'permisos'=>['seguimientos_vinculacion.ver','seguimientos_vinculacion.operar_propios','reuniones.solicitar','reuniones.gestionar','oficios.generar','oficios.enviar']];}
try {
 // Sólo DDL del esquema versionado: no se importan INSERT con datos reales.
 $schema=file_get_contents($source.'/database/sistema_comercial_impe.sql');
 preg_match_all('/^CREATE TABLE .*?;/ms',$schema,$creates);
 foreach($creates[0] as $sql)$db->query($sql);
 preg_match_all('/^ALTER TABLE .*?;/ms',$schema,$alters);
 foreach($alters[0] as $sql)if(!str_contains($sql,'FOREIGN KEY'))$db->query($sql);
 // Las migraciones aportan columnas operativas; sólo se ejecuta DDL en esta base efímera.
 foreach(glob($source.'/database/migrations/*.sql') as $migration){
  $text=file_get_contents($migration);
  preg_match_all('/^(?:CREATE TABLE(?: IF NOT EXISTS)?|ALTER TABLE) (?:`?)(seguimientos_vinculacion[^`\s(]*|reuniones_vinculacion[^`\s(]*|interacciones_vinculacion[^`\s(]*|oficios_vinculacion[^`\s(]*|observaciones_seguimiento[^`\s(]*|recordatorios_vinculacion[^`\s(]*|correos_oficio_vinculacion[^`\s(]*)(?:`?).*?;/ms',$text,$ddl);
  foreach($ddl[0] as $sql){try{$db->query($sql);}catch(mysqli_sql_exception $e){if(!in_array($e->getCode(),[1050,1060,1061,1091,1826],true))throw $e;}}
 }
 $db->query('CREATE TABLE IF NOT EXISTS observaciones_seguimiento (id INT AUTO_INCREMENT PRIMARY KEY,seguimiento_id INT NOT NULL,autor_id INT NOT NULL,destinatario_id INT NOT NULL,observacion TEXT NOT NULL,leida TINYINT DEFAULT 0,leida_at DATETIME NULL,activo TINYINT DEFAULT 1,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
 foreach([1=>'Administrador',2=>'Director de Operaciones',4=>'Analista de Datos',6=>'Cuenta Clave'] as $id=>$name)seed('roles',['id'=>$id,'nombre'=>$name,'estado'=>1]);
 foreach([1=>1,2=>2,5=>4,7=>6,8=>4,9=>6,10=>4,11=>4,12=>4,13=>4] as $id=>$role)seed('usuarios',['id'=>$id,'rol_id'=>$role,'nombre'=>'Persona '.$id,'apellidos'=>'Prueba','correo'=>"persona$id@example.test",'estado'=>$id===13?0:1]);
 foreach([11=>'Guanajuato',17=>'Morelos',19=>'Nuevo León'] as $id=>$name)seed('estados',['id'=>$id,'nombre'=>$name,'nombre_corto'=>$name,'estado'=>1]);
 seed('municipios',['id'=>101,'estado_id'=>11,'nombre'=>'León','estado'=>1]);
 seed('asignaciones_territorio',['id'=>166,'estado_id'=>11,'usuario_id'=>7,'tipo_asignacion'=>'CUENTA_CLAVE','activo'=>1,'fecha_inicio'=>date('Y-m-d',strtotime('-3 days'))]);
 foreach([168=>11,169=>10] as $id=>$uid)seed('asignaciones_territorio',['id'=>$id,'estado_id'=>11,'usuario_id'=>$uid,'tipo_asignacion'=>'ANALISTA_DATOS','cuenta_clave_asignacion_id'=>166,'activo'=>1,'fecha_inicio'=>date('Y-m-d',strtotime('-3 days'))]);
 seed('asignaciones_territorio',['id'=>160,'estado_id'=>11,'usuario_id'=>5,'tipo_asignacion'=>'ANALISTA_DATOS','activo'=>0,'fecha_fin'=>date('Y-m-d',strtotime('-4 days'))]);
 seed('asignaciones_territorio',['id'=>170,'estado_id'=>17,'usuario_id'=>5,'tipo_asignacion'=>'ANALISTA_DATOS','activo'=>1]);
 seed('asignaciones_territorio',['id'=>171,'estado_id'=>11,'usuario_id'=>12,'tipo_asignacion'=>'ANALISTA_DATOS','activo'=>1,'fecha_inicio'=>date('Y-m-d',strtotime('+1 day'))]);
 seed('asignaciones_territorio',['id'=>172,'estado_id'=>11,'usuario_id'=>13,'tipo_asignacion'=>'ANALISTA_DATOS','activo'=>1]);
 for($i=1;$i<=214;$i++)seed('seguimientos_vinculacion',['id'=>$i,'estado_id'=>11,'municipio_id'=>101,'analista_id'=>5,'nombre_entidad'=>'Institución '.$i,'activo'=>1,'estado_seguimiento'=>'NUEVO','fecha_inicio'=>date('Y-m-d H:i:s'),'datos_verificados'=>0]);
 seed('seguimientos_vinculacion',['id'=>300,'estado_id'=>19,'analista_id'=>8,'nombre_entidad'=>'Fuera del equipo','activo'=>1]);
 seed('seguimientos_vinculacion',['id'=>301,'estado_id'=>17,'analista_id'=>5,'nombre_entidad'=>'Cartera actual Diego','activo'=>1]);
 seed('interacciones_vinculacion',['id'=>1,'seguimiento_id'=>1,'usuario_id'=>5,'canal'=>'LLAMADA_IP','resultado'=>'CONTACTADO','fecha_inicio'=>date('Y-m-d H:i:s'),'notas'=>'Actividad original [VERIFICACION_EFECTIVA]','proveedor_externo'=>'ZADARMA','id_externo'=>'audio-prueba-inalterado','duracion_segundos'=>33]);
 require_once $root.'/app/helpers/PermissionHelper.php';
 require_once $root.'/app/helpers/SupervisionRolePolicy.php';
 require_once $root.'/app/models/SeguimientoVinculacionModel.php';
 require_once $root.'/app/models/AnalistaDashboardModel.php';
 require_once $root.'/app/services/AgendaReunionRepository.php';
 sessionAs(11,4);
 check(SeguimientoTerritorialScope::puedeTrabajar(['id'=>1],11,$db),'Alan no puede trabajar');
 check(SeguimientoTerritorialScope::puedeTrabajar(['id'=>1],10,$db),'Denisse no puede trabajar');
 foreach([5,8,12,13] as $uid)check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>1],$uid,$db),'Acceso indebido '.$uid);
 check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>300],11,$db),'Acceso a otro territorio');
 check(SeguimientoTerritorialScope::puedeSupervisar(['id'=>1],7,$db),'Cuenta Clave sin acceso');
 check(!SeguimientoTerritorialScope::puedeSupervisar(['id'=>1],9,$db),'Otra Cuenta Clave con acceso');
 $model=new SeguimientoVinculacionModel();

 foreach([11,10,5] as $uid){sessionAs($uid,4);$dash=(new AnalistaDashboardModel())->obtenerTablero($uid);check($dash['resumen']['en_seguimiento']===($uid===5?1:214),'Total Inicio incorrecto '.$uid.':'.json_encode($dash['resumen']));}

 $before=$db->query('SELECT * FROM interacciones_vinculacion ORDER BY id')->fetch_all(MYSQLI_ASSOC);
 foreach([11,10] as $uid){
  sessionAs($uid,4);
  $rows=$model->obtenerSeguimientosAnalistaEstado($uid,11);
  check(count($rows)===214,'Lista duplicada o incompleta '.$uid.':'.count($rows));
  check((int)$rows[0]['analista_id']===5,'Se cambió el autor');
  $summary=$model->obtenerResumenSeguimientosAnalistaEstado($uid,11);
  check((int)$summary['en_seguimiento']===214,'Resumen incorrecto');
  $states=$model->obtenerEstadosAsignadosAnalista($uid);
  check((int)$states[0]['total_seguimientos']===214,'Tarjeta territorial incorrecta:'.json_encode($states[0]));
 }
 sessionAs(7,6);
 check(count($model->obtenerSeguimientosSupervisorEstado(7,11))===214,'El KAM duplica la cartera');
 check((int)$model->obtenerResumenSeguimientosSupervisorEstado(7,11)['en_seguimiento']===214,'Resumen KAM duplica');
 check(count($model->obtenerSeguimientosSupervisorEstado(9,11))===0,'Otro KAM accede');
 sessionAs(11,4);
 require_once $root.'/app/models/OficioVinculacionModel.php';
 check((bool)(new OficioVinculacionModel())->obtenerEstadoSeguimiento(1,11),'Oficio no autoriza al nuevo equipo');
 require_once $root.'/app/services/SeguimientoFlujoService.php';
 $flow=(new SeguimientoFlujoService())->obtenerEstado(1,11);
 check(!empty($flow['ok']),'Ruta no permite a Alan:'.json_encode($flow));
 check(!(new SeguimientoFlujoService())->obtenerEstado(1,5)['ok'],'Diego conserva acceso operativo');
 require_once $root.'/app/services/SeguimientoRutaOperativaService.php';
 $route=(new SeguimientoRutaOperativaService())->resolver(1,5,$model->obtenerSeguimientoAnalista(11,1));
 check(!empty($route['ok']),'Ruta de lectura falla con autor anterior:'.json_encode($route));
 $agenda=new AgendaReunionRepository();
 check($agenda->cuentaClaveParaSeguimiento(1,11)===7,'KAM del nuevo operador incorrecto');
 seed('reuniones_vinculacion',['id'=>1,'seguimiento_id'=>1,'analista_id'=>5,'cuenta_clave_id'=>7,'estado'=>'CAMBIO_SOLICITADO','fecha_propuesta'=>date('Y-m-d H:i:s',strtotime('+2 days')),'duracion_minutos'=>60,'modalidad'=>'VIRTUAL','objetivo'=>'Reunión heredada']);
 check((bool)$agenda->ultimaReunionSeguimiento(1,10),'Denisse no ve reunión heredada');
 check((bool)$agenda->reunion(1,11,4),'Alan no abre reunión heredada');
 check(!$agenda->reunion(1,5,4),'Diego abre reunión heredada fuera de cartera');
 check($agenda->reprogramar(1,10,date('Y-m-d H:i:s',strtotime('+3 days')),60,'VIRTUAL','Propuesta del equipo','Notas nuevas'),'Reprogramación heredada bloqueada');
 check((int)$db->query('SELECT analista_id FROM reuniones_vinculacion WHERE id=1')->fetch_assoc()['analista_id']===5,'Se cambió el solicitante histórico');
 $db->query("UPDATE reuniones_vinculacion SET estado='CONFIRMADA' WHERE id=1");
 check($agenda->marcarCorreo(1,11,'Asunto prueba','Cuerpo prueba'),'No se guarda confirmación por nuevo analista');
 check((int)$db->query('SELECT correo_confirmacion_por FROM reuniones_vinculacion WHERE id=1')->fetch_assoc()['correo_confirmacion_por']===11,'Correo atribuido al anterior');
 require_once $root.'/app/services/ReunionFechaGuardService.php';
 $db->query("UPDATE reuniones_vinculacion SET fecha_propuesta=DATE_SUB(NOW(),INTERVAL 2 HOUR) WHERE id=1");
 (new ReunionFechaGuardService())->marcarRealizada(1,10,['reunion_resultado'=>'REQUIERE_SEGUIMIENTO','reunion_resultado_notas'=>'Pendiente nuevo']);
 check((int)$db->query('SELECT realizada_por FROM reuniones_vinculacion WHERE id=1')->fetch_assoc()['realizada_por']===10,'La reunión no acredita al actor actual');
 check($before===$db->query('SELECT * FROM interacciones_vinculacion ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Se alteró el historial telefónico');

 // Inicio completo y recordatorios de la cartera actual.
 require_once $root.'/app/services/AnalistaDashboardReunionService.php';
 require_once $root.'/app/services/AnalistaDashboardIntegrityService.php';
 require_once $root.'/app/helpers/ReminderHelper.php';
 require_once $root.'/app/services/ReminderDirectLinkService.php';
 require_once $root.'/app/services/ReminderObservacionService.php';
 require_once $root.'/app/services/ReminderAgendaFilterService.php';
 require_once $root.'/app/services/ReminderReunionFollowupService.php';
 $db->query("UPDATE seguimientos_vinculacion SET proxima_accion_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE id=2");
 seed('interacciones_vinculacion',['id'=>2,'seguimiento_id'=>2,'usuario_id'=>7,'canal'=>'SISTEMA','resultado'=>'OTRO','fecha_inicio'=>date('Y-m-d H:i:s'),'notas'=>'Próxima acción: Volver a llamar']);
 foreach([11,10,5] as $uid){
  sessionAs($uid,4);
  $board=(new AnalistaDashboardModel())->obtenerTablero($uid);
  check((int)$board['avance_mes']['interacciones']===($uid===5?1:0),'Crédito histórico de llamadas transferido');
  check((int)$board['avance_mes']['reuniones_realizadas']===($uid===10?1:0),'Crédito de reunión incorrecto');
  $board=(new AnalistaDashboardReunionService())->ajustar($board,$uid);
  $board=(new AnalistaDashboardIntegrityService())->ajustar($board,$uid);
  check((int)$board['resumen']['en_seguimiento']===($uid===5?1:214),'Ajuste Inicio modifica cartera');
  $items=obtenerRecordatoriosSeguimientoAnalista($uid,50);
  $ids=array_map('intval',array_column($items,'id'));
  check(in_array(2,$ids,true)===($uid!==5),'Recordatorio no sigue al territorio '.$uid.':'.json_encode($items));
  $direct=(new ReminderDirectLinkService())->aplicar([['seguimiento_id'=>2]],$uid);
  check(isset($direct[0]['url'])===($uid!==5),'Enlace de recordatorio incorrecto');
  (new ReminderAgendaFilterService())->filtrarRecordatoriosSeguimiento($items,$uid);
  (new ReminderReunionFollowupService())->obtener($uid,10);
 }
 sessionAs(11,4);
 seed('observaciones_seguimiento',['id'=>1,'seguimiento_id'=>2,'autor_id'=>7,'destinatario_id'=>5,'observacion'=>'Pendiente de la cartera anterior','leida'=>0,'activo'=>1]);
 check($model->contarObservacionesNoLeidas(2,11)===1,'Alan no recibe observación pendiente');
 check($model->contarObservacionesNoLeidas(2,10)===1,'Denisse no recibe observación pendiente');
 check($model->contarObservacionesNoLeidas(2,5)===0,'Anterior analista recibe pendiente ajeno');
 $notes=(new ReminderObservacionService())->obtener(10);
 check(count($notes['recordatorios'])===1,'Falta notificación del expediente compartido');
 $model->marcarObservacionesLeidas(2,11);
 check($model->contarObservacionesNoLeidas(2,10)===0,'La cola compartida no reconoce lectura');
 check((int)$db->query('SELECT destinatario_id FROM observaciones_seguimiento WHERE id=1')->fetch_assoc()['destinatario_id']===5,'Se reescribió el destinatario histórico');
 check($model->crearObservacion(2,7,5,'Nueva observación del equipo'),'No se guarda nueva observación');
 $notaNueva=$db->query('SELECT * FROM observaciones_seguimiento ORDER BY id DESC LIMIT 1')->fetch_assoc();
 check(in_array((int)$notaNueva['destinatario_id'],[10,11],true),'Nueva observación dirigida al responsable anterior');
 // Los formularios y DTO de oficios habilitan al equipo sin cambiar el registro original.
 require_once $root.'/app/controllers/SeguimientoVinculacionController.php';
 $controller=(new ReflectionClass('SeguimientoVinculacionController'))->newInstanceWithoutConstructor();
 check(callPrivate($controller,'puedeOperarSeguimiento',['id'=>2,'analista_id'=>5],11,'analista'),'Formulario bloqueado para Alan');
 check(!callPrivate($controller,'puedeOperarSeguimiento',['id'=>2,'analista_id'=>5],5,'analista'),'Formulario permite al anterior');
 require_once $root.'/app/controllers/OficioCorreoController.php';
 $mailController=(new ReflectionClass('OficioCorreoController'))->newInstanceWithoutConstructor();
 $perms=callPrivate($mailController,'completarPermisosCorreo',['seguimiento_id'=>2,'analista_id'=>5,'enviado'=>false,'guardado'=>true],11);
 check($perms['puede_editar']&&$perms['puede_enviar'],'DTO de correo bloqueado');
 // Selectores de correo/expediente: la identidad nueva es la del operador.
 foreach(['OficioCorreoService','OficioPreviewService','OficioProgramacionService'] as $class){
  require_once $root.'/app/services/'.$class.'.php';
  $dto=callPrivate(new $class(),'obtenerSeguimientoAutorizado',2,11,'analista');
  check((bool)$dto,'Selector de oficio bloqueado '.$class);
  if(isset($dto['analista_correo']))check($dto['analista_correo']==='persona11@example.test','Remitente anterior '.$class);
 }
 require_once $root.'/app/services/SeguimientoCorreoContactoService.php';
 $contact=callPrivate(new SeguimientoCorreoContactoService(),'obtenerSeguimientoAnalista',2,10);
 check($contact['analista_correo']==='persona10@example.test','Correo de contacto usa identidad anterior');
 require_once $root.'/app/services/SeguimientoExpedienteService.php';
 check((new SeguimientoExpedienteService())->obtener(2,11,4)['ok'],'Expediente compartido no disponible');
 check(!(new SeguimientoExpedienteService())->obtener(2,5,4)['ok'],'Expediente del anterior sigue permitido');
 sessionAs(2,2);
 check((new SeguimientoExpedienteService())->obtener(2,2,2)['ok'],'Director pierde consulta nacional');
 $db->query('UPDATE asignaciones_territorio SET activo=0 WHERE id IN (168,169)');
 check(!$model->obtenerSeguimientoAnalista(11,2),'Revocación territorial no inmediata');
 check(!$model->obtenerSeguimientoSupervisor(7,2),'El supervisor conserva un equipo inactivo');
 $noTeamRoute=(new SeguimientoRutaOperativaService())->resolver(2,5,$model->obtenerSeguimientoAdministrador(2));
 check($noTeamRoute['ok'],'Director no puede leer ruta sin equipo asignado');
 $db->query('UPDATE asignaciones_territorio SET activo=1 WHERE id IN (168,169)');
 // Cambiar/restituir fechas o usuario inactivo se refleja en la siguiente consulta.
 $db->query('UPDATE usuarios SET estado=0 WHERE id=11');
 check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>2],11,$db),'Usuario inactivo conserva acceso');
 $db->query('UPDATE usuarios SET estado=1 WHERE id=11');
 sessionAs(11,4);
 require_once $root.'/app/services/TelefoniaVerificacionEvidenciaService.php';
 $data=['canal'=>'NOTA','resultado'=>'OTRO','fecha_inicio'=>date('Y-m-d H:i:s'),'notas'=>'Gestión nueva del equipo','preservar_estado'=>true];
 check($model->registrarInteraccionManual(2,11,$data),'No registra gestión compartida:'.$model->obtenerUltimoErrorInteraccion());
 check($model->registrarInteraccionManual(2,10,$data),'No registra gestión de Denisse');
 check(!$model->registrarInteraccionManual(2,5,$data),'El anterior registra gestión ajena');
 $actors=array_column($db->query("SELECT usuario_id FROM interacciones_vinculacion WHERE seguimiento_id=2 AND canal='NOTA' ORDER BY id")->fetch_all(MYSQLI_ASSOC),'usuario_id');
 check(array_map('intval',$actors)===[11,10],'Autoría de gestiones nuevas incorrecta');
 check((int)$db->query('SELECT COUNT(*) AS n FROM seguimientos_vinculacion WHERE estado_id=11')->fetch_assoc()['n']===214,'Se duplicaron instituciones');
 check($before===$db->query('SELECT * FROM interacciones_vinculacion WHERE id=1')->fetch_all(MYSQLI_ASSOC),'Se alteró una llamada anterior');
 // Ranking histórico: conservar el crédito por autor aunque ya no tenga ese estado.
 require_once $root.'/app/models/DesempenoModel.php';
 $ranking=(new DesempenoModel())->obtenerMetricasAnalistas([['id'=>5],['id'=>10],['id'=>11]],date('Y-m-01 00:00:00'),date('Y-m-t 23:59:59'));
 $rankById=array_column($ranking,null,'id');
 check((int)$rankById[5]['llamadas_realizadas']===1,'Diego pierde autoría en ranking');
 check((int)$rankById[10]['llamadas_realizadas']===0&&(int)$rankById[11]['llamadas_realizadas']===0,'El nuevo equipo recibe llamadas históricas');

 // Reportes: el filtro de responsable representa a la cartera vigente.
 require_once $root.'/app/controllers/SeguimientoVinculacionReporteController.php';
 $reportController=(new ReflectionClass('SeguimientoVinculacionReporteController'))->newInstanceWithoutConstructor();
 $people=callPrivate($reportController,'obtenerResponsablesDisponibles',[['id'=>1,'estado_id'=>11,'analista_id'=>5,'analista_nombre'=>'Original']]);
 check(isset($people[10])&&isset($people[11])&&!isset($people[5]),'Filtro de responsable conserva importador');
 $reportFlow=callPrivate($reportController,'construirFlujoOperativoIndividual',[$model->obtenerSeguimientoAnalista(11,1)]);
 check(!empty($reportFlow),'Reporte individual pierde ruta heredada');
 require_once $root.'/app/services/SeguimientoReporteAnaliticaService.php';
 $reportService=new SeguimientoReporteAnaliticaService();
 check(callPrivate($reportService,'filtrarIdsAutorizados',[1,2,300],11,'analista')===[1,2],'Alcance del reporte incorrecto');
 check(callPrivate($reportService,'filtrarIdsAutorizados',[1,2,300],7,'supervisor')===[1,2],'Equipo del reporte incorrecto');
 check(callPrivate($reportService,'filtrarIdsAutorizados',[1,2,300],9,'supervisor')===[],'Reporte expone otro equipo');
 // Recepción encuentra el mismo expediente con los dos destinos actuales.
 seed('roles',['id'=>3,'nombre'=>'Marketing','estado'=>1]);
 seed('usuarios',['id'=>20,'rol_id'=>3,'nombre'=>'Recepción','apellidos'=>'Prueba','correo'=>'recepcion@example.test','estado'=>1]);
 foreach([81=>'telefonia.usar',82=>'telefonia.recibir',83=>'telefonia.transferir',84=>'telefonia.salientes'] as $pid=>$code){
  seed('permisos',['id'=>$pid,'codigo'=>$code,'estado'=>1]);
  foreach([3,4] as $role)seed('rol_permisos',['rol_id'=>$role,'permiso_id'=>$pid]);
 }
 require_once $root.'/app/services/TelefoniaEnrutamientoService.php';
 (new TelefoniaExtensionService())->asegurarEstructura();
 foreach([20=>'100',10=>'151',11=>'152'] as $uid=>$ext)seed('telefonia_extensiones',['usuario_id'=>$uid,'extension'=>$ext,'proveedor'=>'ZADARMA','activo'=>1,'permite_entrantes'=>1,'permite_salientes'=>1]);
 $db->query("UPDATE seguimientos_vinculacion SET nombre_entidad='Institución compartida única',telefono_fuente='5551234567' WHERE id=1");
 $routing=new TelefoniaEnrutamientoService($db);
 $choices=$routing->instituciones(20,'Marketing',11,'compartida única');
 check(count($choices)===2,'Recepción no encuentra ambos destinos actuales:'.json_encode($choices));
 check(array_unique(array_column($choices,'seguimiento_id'))===[1],'Se inventó otro expediente');
 check(array_values(array_unique(array_column($choices,'responsable_id'))) === [10,11],'Recepción conserva el anterior responsable');
 check(count($routing->identificarOrigen(20,'Marketing','+525551234567'))===2,'Identificación telefónica no reconoce cartera compartida');
 if(is_file($root.'/test-errors.log')){
  $errors=file_get_contents($root.'/test-errors.log');
  foreach(array_filter(explode("\n",$errors)) as $line)check(str_contains($line,'Error registrarInteraccionManual (guardar la interacción): El expediente ya no está disponible'),'Error inesperado:'.$line);
 }


 // Ruta avanzada heredada: lectura autorizada del flujo y nueva identidad de correo.
 sessionAs(11,4);
 seed('oficios_vinculacion',['id'=>1,'seguimiento_id'=>3,'estado_oficio'=>'ENVIADO','enviado_por'=>5,'fecha_envio'=>date('Y-m-d H:i:s')]);
 seed('seguimientos_vinculacion_post_envio',['seguimiento_id'=>3,'respuesta_tipo'=>'INTERESADO','respuesta_at'=>date('Y-m-d H:i:s'),'coordinacion_reunion_habilitada_at'=>date('Y-m-d H:i:s')]);
 $post=(new SeguimientoPostEnvioService())->obtenerFlujoSiAplica(3,5,true);
 check(!empty($post['aplica']),'La consulta de ruta avanzada no reconoce el oficio anterior');
 $advanced=(new SeguimientoRutaOperativaService())->resolver(3,5,$model->obtenerSeguimientoAnalista(11,3));
 check($advanced['ok']&&(int)$advanced['flujo']['paso_actual']===11,'No se conserva coordinación heredada:'.json_encode($advanced));
 $sender=callPrivate(new SeguimientoCorreoService(),'obtenerSeguimientoAnalista',3,11);
 check($sender['analista_correo']==='persona11@example.test','Seguimiento por correo conserva al remitente anterior');
 $_SESSION['permisos']=['seguimientos_vinculacion.ver'];
 check(!callPrivate($controller,'puedeOperarSeguimiento',['id'=>3,'analista_id'=>5],11,'analista'),'La cartera concede permisos desactivados');
 sessionAs(11,4);
 $db->query('UPDATE roles SET estado=0 WHERE id=4');
 check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>2],11,$db),'Rol inactivo conserva acceso');
 check(!SeguimientoTerritorialScope::puedeSupervisar(['id'=>2],7,$db),'Equipo con rol deshabilitado conserva alcance');
 $db->query('UPDATE roles SET estado=1 WHERE id=4');
 // El comprobador de producción sólo lee en una transacción READ ONLY.
 $process=proc_open([PHP_BINARY,$source.'/tools/verificar_cartera_territorial.php','--site',$root,'--estado','11'],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $verification=stream_get_contents($pipes[1]);$verificationError=stream_get_contents($pipes[2]);
 fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
 check($exit===0,'Falló comprobación de instalación:'.$verificationError);
 $verified=json_decode($verification,true,512,JSON_THROW_ON_ERROR);
 check($verified['expedientes_unicos']===214&&count($verified['equipo_actual'])===2,'Comprobador no verifica la cartera');
 $original=$db->query('SELECT COUNT(*) AS n FROM seguimientos_vinculacion WHERE estado_id=11 AND analista_id=5')->fetch_assoc();
 check((int)$original['n']===214,'Instalación alteró autoría');
 echo "PASS: cartera compartida, supervisor sin duplicación, ruta, oficio, reunión heredada y autoría preservada.\n";
 echo "PASS: accesos territoriales y totales compartidos 214/214/1.\n";
} finally {
 $db->close();$server->query("DROP DATABASE `$database`");$server->close();
}
