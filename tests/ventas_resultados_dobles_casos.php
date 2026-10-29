<?php
$v3=static fn(array $codes,array $extra=[])=>array_replace(['catalogo_version'=>'3','resultados_seleccionados'=>json_encode($codes),'observacion'=>'Prueba de selección doble'],$extra);
vcheck(count(TelefoniaResultadoVentasCatalogo::opcionesSeleccion())===13,'Lista única con las trece opciones acordadas');
$make3=function($ref,$answer=true)use($event){$event($ref,'NOTIFY_OUT_START');if($answer)$event($ref,'NOTIFY_ANSWER');$event($ref,'NOTIFY_OUT_END','150',$answer?15:0,['disposition'=>$answer?'answered':'no answer']);};
$cases=[
 ['SIN_RESPUESTA',false,['SIN_RESPUESTA']],['SIN_RESPUESTA',false,['SIN_RESPUESTA','WHATSAPP']],
 ['BUZON_VOZ',true,['BUZON_VOZ','SMS']],['PROSPECTO_POTENCIAL',true,['PROSPECTO_POTENCIAL','WHATSAPP']],
 ['ENVIO_WHATSAPP',false,['WHATSAPP']],['ENVIO_SMS',false,['SMS']],['ENVIO_WHATSAPP_SMS',false,['WHATSAPP','SMS']],
 ['PROSPECTO_POTENCIAL',true,['PROSPECTO_POTENCIAL','FUERA_PRESUPUESTO']],
 ['SIGUIENTE_APERTURA',true,['SIGUIENTE_APERTURA','PROSPECTO_POTENCIAL']],
];
foreach($cases as $i=>[$principal,$answer,$selection]){
 $ref='pbx-doble-prueba-'.$i;$make3($ref,$answer);$results->guardar(8,$ref,'',$v3($selection));$r=$svc->obtener(8,$ref)['registro'];
 vcheck($r['resultado']===$principal&&$r['resultados_seleccionados']===$selection,'Guarda una o dos selecciones sin perder orden: '.$i);
 vcheck($r['motivo_sin_whatsapp']===''&&$r['catalogo_version']==='3','No exige ni inventa motivo de envío: '.$i);
 vcheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_ventas_resultados WHERE id=".(int)$r['id'])->fetch_assoc()['n']===1,'Dos selecciones siguen siendo una llamada: '.$i);
 vreject(fn()=>$results->guardar(8,$ref,'',$v3(['SIN_RESPUESTA'])),'Resultado y selección no se pueden reemplazar: '.$i);
}
foreach([[],['WHATSAPP','SMS','BUZON_VOZ'],['WHATSAPP','WHATSAPP'],['SIN_RESPUESTA','PROSPECTO_POTENCIAL'],['BUZON_VOZ','NO_INTERESADO'],['SIN_RESPUESTA','NUMERO_INEXISTENTE'],['NO_INTERESADO','PROSPECTO_POTENCIAL'],['INVENTADO']] as $bad)
 vreject(fn()=>TelefoniaResultadoVentasCatalogo::normalizarSeleccion($v3($bad)),'Rechaza selección inválida: '.json_encode($bad));
foreach(['{"0":"WHATSAPP"}','null','"SMS"','[["SMS"]]','[]'] as $bad)vreject(fn()=>TelefoniaResultadoVentasCatalogo::normalizarSeleccion(['resultados_seleccionados'=>$bad]),'JSON manipulado no entra al catálogo');
$ref='pbx-doble-no-answer';$make3($ref,false);vreject(fn()=>$results->guardar(8,$ref,'',$v3(['PROSPECTO_POTENCIAL','WHATSAPP'])),'WhatsApp no elimina la validación de respuesta propia');
vreject(fn()=>$results->guardar(8,$ref,'',$v3(['COMUNICACION_INTERRUMPIDA'],['contacto_antes_corte'=>'SI'])),'Corte con persona requiere respuesta propia PBX');
$results->guardar(8,$ref,'',$v3(['COMUNICACION_INTERRUMPIDA'],['contacto_antes_corte'=>'NO']));
$ref='pbx-doble-corte-humano';$make3($ref,true);$results->guardar(8,$ref,'',$v3(['COMUNICACION_INTERRUMPIDA','WHATSAPP'],['contacto_antes_corte'=>'SI']));
vcheck(TelefoniaResultadoVentasService::esConversacion($svc->obtener(8,$ref)['registro']['resultado']),'Corte después de conversar conserva clasificación humana');
vcheck(!TelefoniaResultadoVentasService::esConversacion('ENVIO_WHATSAPP')&&!TelefoniaResultadoVentasService::esConversacion('ENVIO_SMS')&&!TelefoniaResultadoVentasService::esConversacion('ENVIO_WHATSAPP_SMS'),'Enviar mensajes por sí solo no acredita conversación');
$ref='pbx-doble-atomic';$make3($ref,true);
$db->query("CREATE TRIGGER fallo_segunda_seleccion BEFORE INSERT ON telefonia_ventas_resultado_selecciones FOR EACH ROW BEGIN IF NEW.posicion=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo segunda selección'; END IF; END");
try{$results->guardar(8,$ref,'',$v3(['PROSPECTO_POTENCIAL','WHATSAPP']));throw new RuntimeException('Guardó una selección parcial');}
catch(mysqli_sql_exception $e){vcheck($e->getMessage()==='Fallo segunda selección','Fallo de la segunda selección revierte el resultado completo');}
$db->query('DROP TRIGGER fallo_segunda_seleccion');vcheck($svc->obtener(8,$ref)['registro']===null,'Sin resultado ni detalle parcial después del fallo');
$results->guardar(8,$ref,'',$v3(['PROSPECTO_POTENCIAL','WHATSAPP']));
$r=$svc->obtener(8,$ref)['registro'];$id=(int)$r['id'];$db->query("DELETE FROM telefonia_ventas_resultados WHERE id=$id");
vcheck((int)$db->query("SELECT COUNT(*) n FROM telefonia_ventas_resultado_selecciones WHERE resultado_id=$id")->fetch_assoc()['n']===0,'Limpieza autorizada conserva integridad referencial de las selecciones');
$day=substr($svc->obtener(8,'pbx-doble-prueba-3')['fecha'],0,10);
$m=VentasActividadMetricas::calcular([$svc->obtener(8,'pbx-doble-prueba-3'),$svc->obtener(8,'pbx-doble-prueba-6')],[['id'=>8,'nombre_completo'=>'Asesor']],['fecha_desde'=>$day,'fecha_hasta'=>$day]);
vcheck($m['resumen']['llamadas']===2&&$m['resumen']['conversaciones_confirmadas']===1,'Dos resultados o dos canales no inflan llamadas ni contacto');
vcheck($svc->obtener(8,'pbx-catalogo-both')['registro']['envio_informacion']==='WHATSAPP_SMS','Registros V2 conservan ambos envíos y su resultado original');
echo "Selección doble V3: lista, máximo dos, coherencia, guardado atómico, históricos y conteo sin duplicados comprobados.\n";
