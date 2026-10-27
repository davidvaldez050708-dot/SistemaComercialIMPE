<?php
// Se ejecuta dentro del fixture PBX de ventas y su base desechable.
$code=TelefoniaResultadoVentasService::INFORMACION_WHATSAPP;
vcheck(TelefoniaResultadoVentasService::opciones()[$code]==='Información enviada por WhatsApp','Etiqueta compartida correcta');
vcheck(TelefoniaResultadoVentasService::esConversacion($code),'WhatsApp posterior a conversación cuenta como contacto');
$event('pbx-whatsapp-sin-contacto','NOTIFY_OUT_START');
$event('pbx-whatsapp-sin-contacto','NOTIFY_OUT_END','150',0,['disposition'=>'no answer']);
vreject(fn()=>$results->guardar(8,'pbx-whatsapp-sin-contacto',$code),'No acredita contacto sin conexión PBX');
$event('pbx-cancelada-aviso','NOTIFY_OUT_START');
$event('pbx-cancelada-aviso','NOTIFY_OUT_END','150',0,['disposition'=>'cancel']);
$cancelada=$svc->obtener(8,'pbx-cancelada-aviso');
try {
 $results->guardar(8,'pbx-cancelada-aviso',$code);
 throw new RuntimeException('Aceptó conversación en llamada cancelada');
}catch(DomainException $e){
 vcheck(str_contains($e->getMessage(),'«Cancelada»')&&!str_contains($e->getMessage(),'aún no confirma'),'Explica el cierre cancelado en lugar de prometer una espera');
 vcheck(str_contains($e->getMessage(),date('d/m/Y',strtotime($cancelada['fecha'])))&&str_contains($e->getMessage(),substr($cancelada['fecha'],11,5)),'Identifica fecha y hora de la llamada seleccionada');
}
$event('pbx-whatsapp-conversacion','NOTIFY_OUT_START');
$event('pbx-whatsapp-conversacion','NOTIFY_ANSWER');
$event('pbx-whatsapp-conversacion','NOTIFY_OUT_END','150',45,['disposition'=>'answered']);
$task=$db->query("SELECT id,version FROM telefonia_disponibilidad WHERE destino_usuario=8 LIMIT 1")->fetch_assoc();
$taskId=(int)$task['id'];$taskVersion=(int)$task['version'];
$db->query("UPDATE telefonia_disponibilidad SET devolucion='PENDIENTE' WHERE id=$taskId");
$db->query("INSERT INTO telefonia_ventas_devolucion_intentos(usuario_id,extension,pbx_call_id,estado,solicitud_id,version,numero,desde_evento_id,created_at,updated_at) VALUES(8,'150','pbx-whatsapp-conversacion','EN_CURSO',$taskId,$taskVersion,'+525551112233',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$future=date('Y-m-d H:i:s',time()+86400);
$saved=$results->guardar(8,'pbx-whatsapp-conversacion',$code,['prospecto_nombre'=>'Prospecto Prueba','observacion'=>'Se compartió el folleto solicitado después de conversar.','proxima_accion_at'=>$future]);
vcheck($saved['resultado']===$code&&$saved['contacto_real']===true,'Se guarda la nueva clasificación');
$call=$svc->obtener(8,'pbx-whatsapp-conversacion');
vcheck($call['registro']['resultado']===$code&&$call['registro']['proxima_accion_at']===$future,'Historial y próxima llamada conservan sus datos');
vcheck($db->query("SELECT devolucion FROM telefonia_disponibilidad WHERE id=$taskId")->fetch_assoc()['devolucion']==='ATENDIDA','Devolución queda atendida con el resultado nuevo');
vreject(fn()=>$results->guardar(8,'pbx-whatsapp-conversacion','BUZON_VOZ'),'Resultado sigue siendo definitivo');
vreject(fn()=>$results->guardar(9,'pbx-whatsapp-conversacion',$code),'No permite resultados sobre llamadas ajenas');
require dirname(__DIR__).'/app/services/VentasActividadMetricas.php';
$day=substr($call['fecha'],0,10);
$metricas=VentasActividadMetricas::calcular([$call,$call,$svc->obtener(8,'pbx-whatsapp-sin-contacto')],[['id'=>8,'nombre_completo'=>'Asesor Uno']],['fecha_desde'=>$day,'fecha_hasta'=>$day]);
vcheck($metricas['resumen']['llamadas']===2&&$metricas['resumen']['conversaciones_confirmadas']===1&&$metricas['resumen']['tasa_contacto']===50.0,'Reportes y desempeño cuentan el contacto sin duplicar llamadas');
vcheck($metricas['resultados'][$code]===1,'La distribución distingue Información enviada por WhatsApp');
$_SESSION['csrf_token']='prueba';$contactosVentas=[];
ob_start();require dirname(__DIR__).'/app/views/telefonia/resultado_ventas_form.php';$html=ob_get_clean();
vcheck(str_contains($html,'value="'.TelefoniaResultadoVentasService::INFORMACION_WHATSAPP.'">Información enviada por WhatsApp</option>'),'El selector existente muestra la opción con su diseño habitual');
vcheck(str_contains($html,'class="form-select"')&&str_contains($html,'El resultado guardado es definitivo.'),'Conserva los controles y estilos del formulario');
echo "WhatsApp: guardado real, conexión PBX exigida, propietario validado, resultado definitivo, seguimiento opcional, devolución atendida y selector verificados.\n";
