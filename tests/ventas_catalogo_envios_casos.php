<?php
$catalogo=TelefoniaResultadoVentasCatalogo::resultados();
vcheck(count($catalogo)===11&&!isset($catalogo['INFORMACION_ENVIADA_WHATSAPP']),'Once resultados principales; WhatsApp/SMS son acciones independientes');
$datos=['catalogo_version'=>'2','envio_informacion'=>'WHATSAPP','observacion'=>'Datos de prueba'];
$make=function(string $ref,bool $connected=true)use($event){
    $event($ref,'NOTIFY_OUT_START');if($connected)$event($ref,'NOTIFY_ANSWER');
    $event($ref,'NOTIFY_OUT_END','150',$connected?20:0,['disposition'=>$connected?'answered':'no answer']);
};
foreach($catalogo as $code=>$label){
    $ref='pbx-catalogo-'.strtolower($code);$make($ref);
    $payload=$datos;if($code==='COMUNICACION_INTERRUMPIDA')$payload['contacto_antes_corte']='SI';
    $saved=$results->guardar(8,$ref,$code,$payload);$r=$svc->obtener(8,$ref)['registro'];
    vcheck($r['resultado_principal']===$code&&$r['envio_informacion']==='WHATSAPP'&&$r['catalogo_version']==='2','Resultado y envío propios recuperables: '.$code);
    vcheck(TelefoniaResultadoVentasService::esConversacion($r['resultado'])===!in_array($code,['BUZON_VOZ','NUMERO_INEXISTENTE','SIN_RESPUESTA'],true),'Contacto humano declarado según resultado, sin inferirlo de WhatsApp: '.$code);
    vreject(fn()=>$results->guardar(8,$ref,$code,$payload),'No editar resultado ni acción guardados: '.$code);
}
$ref='pbx-catalogo-sin-respuesta-wa';$make($ref,false);
$results->guardar(8,$ref,'SIN_RESPUESTA',$datos);
$r=$svc->obtener(8,$ref)['registro'];
vcheck($r['envio_informacion']==='WHATSAPP'&&!TelefoniaResultadoVentasService::esConversacion($r['resultado']),'Enviar WhatsApp tras no contestar no acredita conversación');
$ref='pbx-catalogo-sms';$make($ref);
vreject(fn()=>$results->guardar(8,$ref,'PROSPECTO_POTENCIAL',array_replace($datos,['envio_informacion'=>'SMS'])),'SMS sin WhatsApp exige explicar excepción');
$results->guardar(8,$ref,'PROSPECTO_POTENCIAL',array_replace($datos,['envio_informacion'=>'SMS','motivo_sin_whatsapp'=>'El prospecto no tiene WhatsApp.']));
$r=$svc->obtener(8,$ref)['registro'];vcheck($r['envio_informacion']==='SMS'&&$r['motivo_sin_whatsapp']==='El prospecto no tiene WhatsApp.','SMS y motivo se conservan por separado de observaciones');
vcheck($r['observacion']===$datos['observacion'],'No insertar acciones ni etiquetas dentro de las notas originales');
$ref='pbx-catalogo-both';$make($ref);
$results->guardar(8,$ref,'INSCRITO',array_replace($datos,['envio_informacion'=>'WHATSAPP_SMS','motivo_sin_whatsapp'=>'Texto previo que ya no aplica']));
$r=$svc->obtener(8,$ref)['registro'];vcheck($r['envio_informacion']==='WHATSAPP_SMS'&&$r['motivo_sin_whatsapp']==='','Ambos canales, sin conservar una excepción que no aplica');
$ref='pbx-catalogo-corte';$make($ref,false);
vreject(fn()=>$results->guardar(8,$ref,'COMUNICACION_INTERRUMPIDA',$datos),'Interrupción debe aclarar si hubo conversación');
vreject(fn()=>$results->guardar(8,$ref,'COMUNICACION_INTERRUMPIDA',$datos+['contacto_antes_corte'=>'SI']),'No acreditar conversación sin respuesta propia PBX');
$results->guardar(8,$ref,'COMUNICACION_INTERRUMPIDA',$datos+['contacto_antes_corte'=>'NO']);
vcheck(!TelefoniaResultadoVentasService::esConversacion($svc->obtener(8,$ref)['registro']['resultado']),'Corte sin conversación no aumenta contactos');
vreject(fn()=>TelefoniaResultadoVentasCatalogo::normalizar('PROSPECTO_POTENCIAL',['envio_informacion'=>['WHATSAPP']]),'Rechaza campos manipulados como listas');
vreject(fn()=>TelefoniaResultadoVentasCatalogo::normalizar('INSCRITO',['envio_informacion'=>'SIN_ENVIO','motivo_sin_whatsapp'=>str_repeat('á',301)]),'Motivo limitado a 300 caracteres Unicode');
vreject(fn()=>TelefoniaResultadoVentasCatalogo::normalizar('NO_INTERESADO',['envio_informacion'=>'OTRO']),'Sólo admite los cuatro estados de envío');
vreject(fn()=>TelefoniaResultadoVentasCatalogo::normalizar('INTERESADO',$datos),'Catálogo nuevo no mezcla resultados anteriores');
$legacy=$svc->obtener(8,'pbx-prospecto-001')['registro'];
vcheck($legacy['resultado']==='SOLICITA_INFORMACION'&&!isset($legacy['envio_informacion']),'Histórico permanece con su clasificación original y sin inventar envíos');
vreject(fn()=>$svc->obtener(9,'pbx-catalogo-both'),'Otro asesor no puede consultar acciones del propietario');
$ref='pbx-catalogo-devolucion';$make($ref);
$task=$db->query('SELECT id,version FROM telefonia_disponibilidad WHERE destino_usuario=8 LIMIT 1')->fetch_assoc();
$taskId=(int)$task['id'];$taskVersion=(int)$task['version'];
$db->query("UPDATE telefonia_disponibilidad SET devolucion='PENDIENTE' WHERE id=$taskId");
$db->query("INSERT INTO telefonia_ventas_devolucion_intentos(usuario_id,extension,pbx_call_id,estado,solicitud_id,version,numero,desde_evento_id,created_at,updated_at) VALUES(8,'150','$ref','EN_CURSO',$taskId,$taskVersion,'+525551112233',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$results->guardar(8,$ref,'PROSPECTO_POTENCIAL',$datos);
vcheck($db->query("SELECT devolucion FROM telefonia_disponibilidad WHERE id=$taskId")->fetch_assoc()['devolucion']==='ATENDIDA','Resultado nuevo con contacto cierra su devolución propia atendida');
$a=$svc->obtener(8,$ref);$b=$svc->obtener(8,'pbx-catalogo-sin-respuesta-wa');$day=substr($a['fecha'],0,10);
$metricas=VentasActividadMetricas::calcular([$a,$b,$a],[['id'=>8,'nombre_completo'=>'Asesor Uno']],['fecha_desde'=>$day,'fecha_hasta'=>$day]);
vcheck($metricas['resumen']['llamadas']===2&&$metricas['resumen']['registradas']===2&&$metricas['resumen']['conversaciones_confirmadas']===1,'Dos campos y ambos canales no duplican llamadas ni contacto en métricas existentes');
// Fallar el segundo insert debe revertir todo el resultado, conservando el tramo telefónico.
$ref='pbx-catalogo-atomic';$make($ref);
$db->query("CREATE TRIGGER fallo_detalle BEFORE INSERT ON telefonia_ventas_resultado_detalles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo de prueba'");
try{$results->guardar(8,$ref,'PROSPECTO_POTENCIAL',$datos);throw new RuntimeException('Aceptó una escritura parcial');}
catch(mysqli_sql_exception $e){vcheck($e->getMessage()==='Fallo de prueba','El error del segundo insert se propaga y revierte la transacción');}
$db->query('DROP TRIGGER fallo_detalle');
vcheck($svc->obtener(8,$ref)['registro']===null,'Fallo al guardar envío no deja resultado parcial ni borra llamada');
$results->guardar(8,$ref,'PROSPECTO_POTENCIAL',$datos);
vcheck($svc->obtener(8,$ref)['registro']['envio_informacion']==='WHATSAPP','Reintento posterior al fallo guarda ambos componentes');
$ref='pbx-catalogo-borrado-local';$make($ref);$results->guardar(8,$ref,'NO_INTERESADO',$datos);
$id=(int)$svc->obtener(8,$ref)['registro']['id'];
$db->query("DELETE FROM telefonia_ventas_resultados WHERE id=$id");
vcheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_ventas_resultado_detalles WHERE resultado_id=$id")->fetch_assoc()['n']===0,'Una limpieza autorizada del resultado no deja detalles huérfanos');
echo "Catálogo V2: resultados, envíos, motivos, interrupción, históricos, autorización y transacción verificados.\n";
