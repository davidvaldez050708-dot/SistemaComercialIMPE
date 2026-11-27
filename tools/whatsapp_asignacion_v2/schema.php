<?php
if(PHP_SAPI!=='cli' || count($argv)!==2)exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
try{
require $argv[1].'/config/db_connection.php';
$db=(new Database())->connect(false);$db->query('START TRANSACTION READ ONLY');
$checks=[
'whatsapp_asignaciones_historial'=>['conversacion_id','responsable_anterior_id','responsable_nuevo_id','actor_usuario_id','origen'],
'whatsapp_conversaciones'=>['id','cuenta_id','telefono_normalizado','responsable_usuario_id','aliado_seguimiento_id'],
'whatsapp_conversacion_prospectos'=>['conversacion_id','prospecto_id'],
'whatsapp_cuentas'=>['id','tipo','activo'],
'whatsapp_mensajes'=>['conversacion_id'],
'prospectos_comerciales'=>['id','telefono_normalizado'],
'prospecto_asignaciones'=>['prospecto_id','asesor_id','vigente'],
'seguimientos_vinculacion'=>['id','estado_id','analista_id','activo','telefono_fuente','telefono_verificado','whatsapp_verificado'],
'asignaciones_territorio'=>['estado_id','usuario_id','tipo_asignacion','activo','fecha_inicio','fecha_fin'],
'estados'=>['id','estado'],'usuarios'=>['id','rol_id','estado'],'roles'=>['id','estado'],
'rol_permisos'=>['rol_id','permiso_id'],'permisos'=>['id','codigo','estado']
];
foreach($checks as $table=>$columns){
$r=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'")->fetch_row();
if(!$r || strtoupper($r[0])!=='INNODB')throw new RuntimeException('Tabla no transaccional: '.$table);
$cols=array_column($db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC),'Field');
if(array_diff($columns,$cols))throw new RuntimeException('Columnas faltantes: '.$table);
echo "ESQUEMA_OK | $table\n";
}
$db->rollback();$db->close();
}catch(Throwable $e){fwrite(STDERR,"ESQUEMA_BLOQUEADO | revisar estructura de asignaciones\n");exit(2);}
