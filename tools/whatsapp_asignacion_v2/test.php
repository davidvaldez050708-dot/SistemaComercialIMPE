<?php
function asCheck(bool $ok,string $label): void {waCheck($ok,$label);echo "PASS | $label\n";}
function asRow(mysqli $db,int $id): array {return $db->query("SELECT * FROM whatsapp_conversaciones WHERE id=$id")->fetch_assoc();}
function asDenied(callable $f): void {try{$f();}catch(DomainException $e){return;}throw new RuntimeException('Acceso indebido');}
$router=new WhatsAppAsignacionService($db);
$db->query("UPDATE seguimientos_vinculacion SET analista_id=44 WHERE id=10");
$inst=$model->registrarMensajeEntrada($company,'527770000010','Institución','as-inst','TEXT','Hola',time());
asCheck((int)asRow($db,$inst)['aliado_seguimiento_id']===10 && (int)asRow($db,$inst)['responsable_usuario_id']===44,'institution routed to verified analyst');
$db->query("INSERT INTO seguimientos_vinculacion(id,estado_id,analista_id,nombre_entidad,whatsapp_verificado,activo) VALUES(30,1,44,'Synthetic collision','+527770090010',1)");
$db->query("INSERT INTO prospectos_comerciales VALUES(30,'Collision','+527770090010'),(31,'Duplicate','+527770090011'),(32,'Unassigned duplicate','+527770090011'),(33,'Manual','+527770090012'),(34,'Test channel','+527770090013')");
$db->query("INSERT INTO prospecto_asignaciones VALUES(30,30,22,1),(31,31,22,1),(33,33,22,1),(34,34,22,1)");
foreach(['527770090010','527770090011'] as $i=>$n){$cid=$model->registrarMensajeEntrada($company,$n,'Collision','as-col-'.$i,'TEXT','Hola',time());asCheck(empty(asRow($db,$cid)['responsable_usuario_id']),'ambiguous including unassigned prospect stays in queue');}
$pr=$model->registrarMensajeEntrada($trial,'527770090013','Test','as-test','TEXT','Hola',time());
asCheck(empty(asRow($db,$pr)['responsable_usuario_id']),'test channel not routed');
waSession(1,1,['whatsapp.gestionar_cuentas','whatsapp.gestionar_conversaciones']);
asDenied(fn()=>$router->asignar($unknown,22));
asCheck(empty(asRow($db,$unknown)['responsable_usuario_id']),'unknown not assigned invisibly to sales');
$raw=$model->crearORecuperarConversacion($company,'527770090012','Manual',0,0);
$model->registrarMensajeSalida($raw,1,'Synthetic',['ok'=>false,'mensaje'=>'fixture'],'TEXT');
$router->asignar($raw,22);
waSession(22);asCheck((bool)$model->obtenerConversacion($raw,22,false),'manual sales assignment creates authorized link');
waSession(23);asCheck(!$model->obtenerConversacion($raw,23,false),'other adviser denied');
waSession(1,1,['whatsapp.gestionar_cuentas','whatsapp.gestionar_conversaciones']);
asCheck(!empty($router->asignar($raw,22)['ya_asignada']),'manual replay idempotent');
asDenied(fn()=>$router->asignar($raw,23));
$db->query("UPDATE prospecto_asignaciones SET asesor_id=23 WHERE id=33");
waSession(22);asCheck(!$model->obtenerConversacion($raw,22,false),'former adviser denied after transfer');
waSession(23);asCheck((bool)$model->obtenerConversacion($raw,23,false),'new adviser sees retained conversation');
asCheck((int)$db->query("SELECT COUNT(*) FROM whatsapp_asignaciones_historial WHERE conversacion_id=$raw AND origen='MANUAL'")->fetch_row()[0]===1,'manual audit exactly once');
asCheck((int)$db->query("SELECT COUNT(*) FROM whatsapp_asignaciones_historial WHERE conversacion_id=$inst AND origen='AUTOMATICA'")->fetch_row()[0]===1,'automatic audit');
echo "ASIGNACION_V2_SQL_OK\n";
