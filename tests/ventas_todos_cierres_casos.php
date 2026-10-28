<?php
// PBX y guardado reales sobre la base desechable del fixture de Ventas.
$cases=[
 ['cancel','BUZON_VOZ','Se escuchó el aviso del buzón.'],
 ['cancel','CANCELADA_POR_ASESOR','Se marcó un número equivocado.'],
 ['busy','OCUPADO','Línea ocupada.'],
 ['no answer','SIN_RESPUESTA','No hubo respuesta.'],
 ['answered','BUZON_VOZ','Contestó una grabadora.'],
 ['failed','MENSAJE_AUTOMATICO','La operadora indica fuera de servicio.']
];
$all=[];
foreach($cases as $i=>[$technical,$outcome,$note]){
 $ref='pbx-cierre-general-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
 $event($ref,'NOTIFY_OUT_START');
 if($technical==='answered')$event($ref,'NOTIFY_ANSWER');
 $event($ref,'NOTIFY_OUT_END','150',$technical==='answered'?10:0,['disposition'=>$technical]);
 $own=$svc->obtener(8,$ref);
 vcheck(TelefoniaVentasLlamadasService::resultadoCapturable($own),'Todo cierre propio sin resultado debe permitir registro: '.$technical);
 if($outcome==='CANCELADA_POR_ASESOR')vreject(fn()=>$results->guardar(8,$ref,$outcome,['observacion'=>'']),'Cancelación del asesor requiere un motivo');
 if(!$own['conectada'])vreject(fn()=>$results->guardar(8,$ref,'INFORMACION_ENVIADA_WHATSAPP',['observacion'=>$note]),'Sin conexión no puede declarar contacto humano');
 $saved=$results->guardar(8,$ref,$outcome,['observacion'=>$note,'prospecto_nombre'=>'Prueba']);
 vcheck(!$saved['contacto_real'],'Buzón y cancelación no son contacto humano');
 $own=$svc->obtener(8,$ref);vcheck(!TelefoniaVentasLlamadasService::resultadoCapturable($own),'No volver a solicitar un resultado ya guardado');
 vcheck($own['registro']['resultado']===$outcome&&$own['registro']['observacion']===$note,'Guardar resultado y notas sin cambiar el hecho PBX');
 $all[]=$own;
}
$day=substr($all[0]['fecha'],0,10);
$stats=VentasActividadMetricas::calcular($all,[['id'=>8,'nombre_completo'=>'Asesor Prueba']],['fecha_desde'=>$day,'fecha_hasta'=>$day]);
vcheck($stats['resumen']['llamadas']===6&&$stats['resumen']['registradas']===6&&$stats['resumen']['conversaciones_confirmadas']===0,'Reportes conservan actividad sin aumentar contacto');
vcheck($stats['resumen']['por_registrar']===0&&$stats['resumen']['cumplimiento_registro']===100.0,'Pendientes y cumplimiento coinciden con resultados guardados');
vcheck(!TelefoniaVentasLlamadasService::resultadoCapturable(['pbx_call_id'=>'','finalizada'=>true,'conectada'=>false,'direccion'=>'Saliente']),'No crear resultado para un intento sin PBX');
vcheck(!TelefoniaVentasLlamadasService::resultadoCapturable(['pbx_call_id'=>'out-ongoing','finalizada'=>false,'conectada'=>true,'direccion'=>'Saliente']),'No permitir guardar saliente aún activa');
echo "Todos los cierres: guardado, motivo de cancelación, resultados definitivos y métricas comprobados sin alterar la clasificación técnica PBX.\n";
