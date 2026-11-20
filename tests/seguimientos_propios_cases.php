<?php
// Fixtures y conexión efímera proporcionadas por cartera_territorial_mysql.php.
$db->query('UPDATE seguimientos_vinculacion SET analista_id=11 WHERE id=1');
$db->query('UPDATE seguimientos_vinculacion SET analista_id=10 WHERE id=2');
$model = new SeguimientoVinculacionModel();
foreach ([11=>1,10=>2] as $uid=>$own) {
    sessionAs($uid,4);
    $other=$own===1?2:1;
    check(count($model->obtenerSeguimientosAnalistaEstado($uid,11))===214,'La consulta territorial dejó de ser compartida');
    check((int)$model->obtenerResumenSeguimientosAnalistaEstado($uid,11)['en_seguimiento']===214,'Resumen territorial inconsistente');
    check(count($model->obtenerSeguimientosAnalistaEstado($uid,11,['analista_id'=>$uid]))===1,'El filtro de Analista no distingue responsables');
    check((bool)$model->obtenerSeguimientoAnalista($uid,$other),'No puede consultar el expediente ajeno');
    check(SeguimientoTerritorialScope::puedeConsultar(['id'=>$other],$uid,$db),'Consulta compartida bloqueada');
    check(SeguimientoTerritorialScope::puedeTrabajar(['id'=>$own],$uid,$db),'No puede trabajar su expediente');
    // El ID y el responsable recibidos del navegador nunca sustituyen al dato real.
    check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>$other,'analista_id'=>$uid],$uid,$db),'Suplantación de propietario permitida');
    check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>3],$uid,$db),'Se apropió de un expediente histórico');
    check(!SeguimientoTerritorialScope::puedeConsultar(['id'=>300],$uid,$db),'Consulta fuera del territorio');
    check(SeguimientoTerritorialScope::estadosAnalista($uid,$db)===[11],'Se perdió el catálogo territorial');
    check(count(SeguimientoTerritorialScope::analistasVigentes($db,11))===2,'Equipo territorial incorrecto');
    $before=$db->query("SELECT * FROM seguimientos_vinculacion WHERE id=$other")->fetch_assoc();
    check(!$model->actualizarContactoSeguimiento($other,$uid,['contacto_nombre'=>'Cambio ajeno'],false),'Se editó contacto ajeno');
    check(!$model->marcarDatosVerificadosSeguimiento($other,$uid),'Se verificó expediente ajeno');
    check(!$model->descartarSeguimientoTrabajo($other,'Descarte ajeno',$uid),'Se descartó expediente ajeno');
    check(!$model->reactivarSeguimientoTrabajo($other,$uid,'Otro','Cambio ajeno'),'Se reactivó expediente ajeno');
    check($before===$db->query("SELECT * FROM seguimientos_vinculacion WHERE id=$other")->fetch_assoc(),'Una operación denegada alteró el expediente');
    check($model->actualizarContactoSeguimiento($own,$uid,['contacto_nombre'=>'Responsable '.$uid],false),'No puede editar su contacto');
}
foreach([5,8,12,13] as $uid) {
    check(!SeguimientoTerritorialScope::puedeConsultar(['id'=>1],$uid,$db),'Territorio vencido, ajeno o cuenta inactiva con acceso');
    check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>1],$uid,$db),'Operación sin territorio vigente');
}
check(SeguimientoTerritorialScope::puedeSupervisar(['id'=>1],7,$db),'Se perdió la supervisión de Cuenta Clave');
check(!SeguimientoTerritorialScope::puedeSupervisar(['id'=>1],9,$db),'Supervisor ajeno con acceso');
sessionAs(11,4);
require_once $root.'/app/services/SeguimientoFlujoService.php';
require_once $root.'/app/services/SeguimientoRutaOperativaService.php';
require_once $root.'/app/services/SeguimientoExpedienteService.php';
require_once $root.'/app/services/InteraccionRutaService.php';
check((new SeguimientoFlujoService())->obtenerEstado(1,11)['ok'],'Flujo propio bloqueado');
check(!(new SeguimientoFlujoService())->obtenerEstado(2,11)['ok'],'Flujo operativo de otro analista permitido');
check((new SeguimientoRutaOperativaService())->resolver(2,10,$model->obtenerSeguimientoAnalista(11,2))['ok'],'Ruta ajena en modo consulta bloqueada');
check((new SeguimientoExpedienteService())->obtener(2,11,4)['ok'],'Expediente ajeno en modo consulta bloqueado');
check((new InteraccionRutaService())->registrarInformativa(2,11,[])['codigo_http']===403,'Interacción avanzada ajena permitida');
require_once $root.'/app/controllers/SeguimientoVinculacionController.php';
$controller=(new ReflectionClass('SeguimientoVinculacionController'))->newInstanceWithoutConstructor();
check(callPrivate($controller,'puedeOperarSeguimiento',['id'=>1],11,'analista'),'UI propia bloqueada');
check(!callPrivate($controller,'puedeOperarSeguimiento',['id'=>2],11,'analista'),'UI ajena habilitada');
// Ejecutar las acciones reales en un proceso independiente: responderJson usa exit.
// Se utiliza exclusivamente la configuración efímera construida por el runner.
$endpointFile=$root.'/endpoint-propios-test.php';
file_put_contents($endpointFile, '<?php
define("BASE_URL","https://example.test/");
define("ROOT_PATH",__DIR__);
$_SESSION=["usuario_id"=>11,"rol_id"=>4,"rol"=>"Analista de Datos","permisos"=>["seguimientos_vinculacion.ver","seguimientos_vinculacion.operar_propios"]];
$_SERVER["REQUEST_METHOD"]="POST";
$_POST=["seguimiento_id"=>2,"motivo_descarte"=>"Cambio no autorizado"];
register_shutdown_function(static function(){file_put_contents("php://stderr","HTTP=".http_response_code());});
require __DIR__."/app/controllers/SeguimientoVinculacionController.php";
$controller=(new ReflectionClass("SeguimientoVinculacionController"))->newInstanceWithoutConstructor();
$controller->{$argv[1]}();');
foreach(['actualizarContactoTrabajo','marcarContactoVerificadoTrabajo','registrarInteraccionTrabajo','descartarSeguimientoTrabajo','reactivarSeguimientoTrabajo'] as $action) {
    $process=proc_open([PHP_BINARY,$endpointFile,$action],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $response=stream_get_contents($pipes[1]);$status=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    check($code===0&&$status==='HTTP=403','La acción no denegó el acceso:'.$action.':'.$status.':'.$response);
    check(json_decode($response,true,512,JSON_THROW_ON_ERROR)['ok']===false,'Respuesta inválida al denegar '.$action);
}
$_SESSION['permisos']=['seguimientos_vinculacion.ver'];
check(!callPrivate($controller,'puedeOperarSeguimiento',['id'=>1],11,'analista'),'Propiedad sustituyó al permiso revocado');
sessionAs(11,4);
require_once $root.'/app/models/OficioVinculacionModel.php';
check((bool)(new OficioVinculacionModel())->obtenerEstadoSeguimiento(2,11),'Se ocultó el oficio en la consulta ajena');
require_once $root.'/app/controllers/OficioCorreoController.php';
$mailController=(new ReflectionClass('OficioCorreoController'))->newInstanceWithoutConstructor();
$mail=['seguimiento_id'=>2,'analista_id'=>10,'enviado'=>false,'guardado'=>true];
$perms=callPrivate($mailController,'completarPermisosCorreo',$mail,11);
check(!$perms['puede_editar']&&!$perms['puede_enviar'],'Correo ajeno habilitado');
$perms=callPrivate($mailController,'completarPermisosCorreo',array_replace($mail,['seguimiento_id'=>1,'analista_id'=>11]),11);
check($perms['puede_editar']&&$perms['puede_enviar'],'Correo propio bloqueado');

// Reuniones: se pueden consultar dentro de la ruta; sólo el dueño puede operarlas.
$agenda=new AgendaReunionRepository();
seed('reuniones_vinculacion',['id'=>1,'seguimiento_id'=>2,'analista_id'=>10,'cuenta_clave_id'=>7,'estado'=>'CAMBIO_SOLICITADO','fecha_propuesta'=>date('Y-m-d H:i:s',strtotime('+2 days')),'duracion_minutos'=>60,'modalidad'=>'VIRTUAL','objetivo'=>'Reunión de Denisse']);
check((bool)$agenda->ultimaReunionSeguimiento(2,11,true),'Consulta de reunión ajena bloqueada');
check(!$agenda->ultimaReunionSeguimiento(2,11),'Reunión ajena habilitada para operar');
check(!$agenda->reprogramar(1,11,date('Y-m-d H:i:s',strtotime('+3 days')),60,'VIRTUAL','Cambio ajeno',''),'Se reprogramó reunión ajena');
check($agenda->reprogramar(1,10,date('Y-m-d H:i:s',strtotime('+3 days')),60,'VIRTUAL','Cambio propio',''),'No puede reprogramar su reunión');

// Los avisos y las lecturas de observaciones pertenecen al responsable.
$db->query("UPDATE seguimientos_vinculacion SET proxima_accion_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE id IN(1,2)");
foreach([2=>1,3=>2] as $interaction=>$follow) {
    seed('interacciones_vinculacion',['id'=>$interaction,'seguimiento_id'=>$follow,'usuario_id'=>$follow===1?11:10,'canal'=>'SISTEMA','resultado'=>'OTRO','fecha_inicio'=>date('Y-m-d H:i:s'),'notas'=>'Próxima acción: Volver a llamar']);
}
require_once $root.'/app/helpers/ReminderHelper.php';
foreach([11=>1,10=>2] as $uid=>$own) {
    sessionAs($uid,4);
    $ids=array_map('intval',array_column(obtenerRecordatoriosSeguimientoAnalista($uid,50),'id'));
    check($ids===[$own],'Recordatorios compartidos indebidamente');
}
check($model->crearObservacion(2,7,11,'Observación para la responsable real'),'No se creó observación');
check((int)$db->query('SELECT destinatario_id FROM observaciones_seguimiento ORDER BY id DESC LIMIT 1')->fetch_assoc()['destinatario_id']===10,'Observación enviada a otro analista');
check($model->contarObservacionesNoLeidas(2,11)===0,'Aviso ajeno visible');
check($model->contarObservacionesNoLeidas(2,10)===1,'Falta aviso propio');
$model->marcarObservacionesLeidas(2,11);
check($model->contarObservacionesNoLeidas(2,10)===1,'Lectura ajena eliminó aviso propio');

// Manual y candidato mantienen una sola institución compartida, sin cambiar dueño.
sessionAs(11,4);
$payload=['estado_id'=>11,'municipio_id'=>101,'analista_id'=>11,'clave_origen'=>'MANUAL:propios-test','tipo_entidad'=>'EMPRESA','nombre'=>'Institución manual de Alan','datos_verificados'=>0,'estado_seguimiento'=>'NUEVO'];
$manual=$model->crearSeguimientoManual($payload);
check(SeguimientoTerritorialScope::puedeTrabajar(['id'=>$manual],11,$db),'Creación manual sin propiedad');
check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>$manual],10,$db),'Creación manual compartió propiedad');
check((int)$model->obtenerSeguimientoManualExacto(11,10,'EMPRESA',$payload['nombre'])['id']===$manual,'Otro analista puede duplicar institución manual exacta');
check((int)$model->obtenerSeguimientoPorClaveOrigen(11,$payload['clave_origen'])['id']===$manual,'No se detecta el candidato existente');
check((int)$db->query("SELECT analista_id FROM seguimientos_vinculacion WHERE id=$manual")->fetch_assoc()['analista_id']===11,'La detección de duplicado cambió el responsable');
require_once $root.'/app/services/SeguimientoExcelImportService.php';
$importer=new SeguimientoExcelImportService($db);
$scope=[['id'=>11,'nombre'=>'Guanajuato']];
$cells=['REF-EXCEL','Institución importada de Denisse','EMPRESA','Guanajuato','León','Servicios','Calle Prueba 100','4771234567','4771234567','institucion@example.test','https://example.test','Contacto Prueba','Dirección','00','','','','',''];
$preview=$importer->previsualizar([['fila'=>2,'celdas'=>$cells]],$scope,10,10);
check($preview['filas'][0]['estado']==='nueva','Fila Excel no validada:'.json_encode($preview));
$saved=$importer->confirmar($preview['filas'],$scope,10,10);
$imported=(int)$saved['ids'][0];
check(SeguimientoTerritorialScope::puedeTrabajar(['id'=>$imported],10,$db),'Importación sin propiedad');
check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>$imported],11,$db),'Importación compartió propiedad');
check((bool)$model->obtenerSeguimientoAnalista(11,$imported),'Importación oculta a su compañero');
$again=$importer->previsualizar([['fila'=>2,'celdas'=>$cells]],$scope,11,11);
check($again['filas'][0]['estado']==='existente','Excel permitió duplicar el expediente de otro analista');
$db->query('UPDATE roles SET estado=0 WHERE id=4');
check(!SeguimientoTerritorialScope::puedeTrabajar(['id'=>1],11,$db),'Rol inactivo conserva operación');
check(!SeguimientoTerritorialScope::puedeConsultar(['id'=>2],11,$db),'Rol inactivo conserva consulta');
$db->query('UPDATE roles SET estado=1 WHERE id=4');
echo "PASS: consulta compartida y operación exclusiva, servidor/UI, contacto, correo, ruta, reuniones, avisos y duplicados.\n";
