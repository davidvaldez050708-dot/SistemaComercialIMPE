<?php
require_once __DIR__.'/WhatsAppAvisosService.php';
function avCheck(bool $ok,string $why):void {waCheck($ok,$why);echo "PASS | $why\n";}
$db->query("INSERT IGNORE INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo IN ('whatsapp.ver','whatsapp.enviar')");
waSession(23);
$summary=WhatsAppAvisosService::resumen($db,23);
$ids=array_column($summary['conversaciones'],'id');
avCheck(in_array($other,$ids,true)&&!in_array($unknown,$ids,true),'own business unread only');
$count=$db->query("SELECT no_leidos FROM whatsapp_conversaciones WHERE id=$other")->fetch_row()[0];
avCheck((int)$count>0,'summary does not mark read');
$model->registrarMensajeSalida($other,23,'outgoing synthetic',['ok'=>false,'mensaje'=>'fixture']);
$summary=WhatsAppAvisosService::resumen($db,23);
$matching=array_values(array_filter($summary['conversaciones'],fn($r)=>$r['id']===$other))[0];
avCheck($matching['mensaje']==='Hola' && ctype_digit($matching['entrada_id']),'latest incoming preview, not outgoing');
$db->query("UPDATE prospecto_asignaciones SET asesor_id=22 WHERE id=2");
avCheck(!in_array($other,array_column(WhatsAppAvisosService::resumen($db,23)['conversaciones'],'id'),true),'transfer denies previous adviser');
waSession(22);avCheck(in_array($other,array_column(WhatsAppAvisosService::resumen($db,22)['conversaciones'],'id'),true),'transfer grants new adviser');
$db->query("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=3 AND p.codigo='whatsapp.ver'");
waDeny(fn()=>WhatsAppAvisosService::resumen($db,22));
echo "AVISOS_SQL_OK\n";
