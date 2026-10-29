<?php
// Datos sintéticos: mismos hechos y tipos que devuelve el repositorio real.
$m=VentasActividadMetricas::class;
$row=static function(string $id,string $fecha,string $direction,array $codes,?string $ref=null)use($entry):array {
    return array_replace($entry,['id'=>$id,'pbx_call_id'=>$ref??('horario-'.$id),'fecha'=>$fecha,'direccion'=>$direction,
        'registro'=>['resultado'=>$codes[0],'resultados_seleccionados'=>$codes],'conectada'=>true,'finalizada'=>true,'segundos'=>15]);
};
$a=$row('1','2026-10-26 00:00:00','Entrante',['BUZON_VOZ','WHATSAPP']);
$b=$row('2','2026-10-26 23:59:59','Saliente',['PROSPECTO_POTENCIAL','WHATSAPP']);
$c=$row('3','2026-10-28 10:11:12','Saliente',['NUMERO_INEXISTENTE']);
$attempt=$row('intento-4','2026-10-28 00:00:01','Saliente',['BUZON_VOZ'],'');
$transfer=$row('transferencia-5','2026-10-28 23:59:59','Entrante',['BUZON_VOZ'],'');
$outside=$row('6','2026-10-29 00:00:00','Saliente',['BUZON_VOZ']);
$other=array_replace($a,['usuario_id'=>9]);
$base=$m::calcular([$c,$b,$attempt,$outside,$a,$a,$transfer,$other],[['id'=>8,'nombre_completo'=>'Asesor Uno']],['fecha_desde'=>'2026-10-26','fecha_hasta'=>'2026-10-28']);
vcheck($base['resumen']['llamadas']===3&&$base['resumen']['buzones']===1,'Buzón con WhatsApp y llamada duplicada se cuentan una vez; intentos y transferencias aparte');
vcheck($base['resumen']['conectadas']===3&&$base['resumen']['conversaciones_confirmadas']===1,'La métrica de buzones no modifica los hechos PBX ni acredita contacto humano');
vcheck($base['dias'][0]['primera_llamada']==='2026-10-26 00:00:00'&&$base['dias'][0]['ultima_llamada']==='2026-10-26 23:59:59','Entrantes y salientes, sin depender del orden y respetando límites del día');
vcheck($base['dias'][1]['llamadas']===0&&$base['dias'][1]['primera_llamada']===null&&$base['dias'][1]['ultima_llamada']===null,'Días sin llamadas conservados, sin inventar hora cero');
vcheck($base['dias'][2]['primera_llamada']===$base['dias'][2]['ultima_llamada']&&$m::horaInicio($base['dias'][2]['primera_llamada'])==='10:11:12','Una sola llamada tiene el mismo primer y último inicio');
vcheck($base['dias'][2]['llamadas']===1,'Intentos sin referencia, transferencias, otro usuario y otro día no desplazan el horario');
$zone=date_default_timezone_get();date_default_timezone_set('UTC');
vcheck($m::horaInicio('2026-10-26 14:00:00')==='14:00:00','No convertir de nuevo una fecha que el repositorio ya presenta en México');
date_default_timezone_set($zone);
vcheck($m::horaInicio(null)==='—'&&$m::horaInicio('2026-02-30 12:00:00')==='—','Ausencia e información inválida no producen horas inventadas');
$own=$base+['vista'=>'propio','persona_id'=>8];$chosen=$base+['vista'=>'global','persona_id'=>8];
vcheck($m::mostrarHorarioIndividual($own)&&$m::mostrarHorarioIndividual($chosen),'Horario sólo para actividad propia o asesor seleccionado');
vcheck(!$m::mostrarHorarioIndividual($base+['vista'=>'global','persona_id'=>0]),'No atribuir el horario de todo el equipo a una sola persona');
$two=$chosen;$two['personas_resumen'][]=['id'=>9];vcheck(!$m::mostrarHorarioIndividual($two),'Con dos personas no se muestra un horario individual');
$before=$db->query('SELECT * FROM telefonia_ventas_resultados ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$_SESSION=['usuario_id'=>1,'rol_id'=>1,'nombre'=>'Supervisor','rol'=>'Administrador'];
foreach(['BUZON_VOZ','PROSPECTO_POTENCIAL','WHATSAPP',''] as $code){
    $data=$service->preparar(array_replace($filters,['resultado'=>$code]),false);
    $starts=array_column(array_values(array_filter($data['llamadas'],static fn($r)=>$r['pbx_call_id']!=='')),'fecha');sort($starts);
    vcheck($data['dias'][0]['primera_llamada']===($starts[0]??null)&&$data['dias'][0]['ultima_llamada']===($starts?end($starts):null),'Servicio real respeta los filtros al calcular los extremos: '.$code);
    vcheck($data['resumen']['buzones']===($data['resultados']['BUZON_VOZ']??0),'Tarjeta y distribución coinciden con filtro: '.$code);
}
vcheck($before===$db->query('SELECT * FROM telefonia_ventas_resultados ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Horarios y buzones se consultan sin modificar resultados');
$data=$service->preparar($filters,false);$pdf=new VentasActividadPdfView();
$today=$data;$today['periodo']['clave']='hoy';$today['periodo']['label']='Hoy';
$html=$pdf->html($today,'','');
vcheck(str_contains($html,'Buzones de voz')&&!str_contains($html,'Conectadas</span>'),'PDF reemplaza la tarjeta confusa y conserva las métricas técnicas internas');
vcheck(str_contains($html,'Primera llamada')&&str_contains($html,'Última llamada')&&str_contains($html,'Hora de México'),'PDF de Hoy incluye ambas tarjetas y zona horaria');
$all=$data;$all['persona_id']=0;vcheck(!str_contains($pdf->html($all,'',''),'Horario de llamadas'),'PDF de equipo no muestra un horario individual');
$range=array_replace($data,$base);$range['resultado']='';$range['direccion']='';$range['periodo']=['clave'=>'personalizado','label'=>'Personalizado','fecha_desde'=>'2026-10-26','fecha_hasta'=>'2026-10-28'];
$html=$pdf->html($range,'','');vcheck(str_contains($html,'2026-10-27')&&str_contains($html,'23:59:59'),'PDF de rango muestra cada día y las horas completas');
if($out=getenv('IMPE_TEST_HORARIOS_OUT')){
    if(!is_dir($out))mkdir($out,0700,true);
    $yesterday=$today;$yesterday['periodo']['clave']='ayer';$yesterday['periodo']['label']='Ayer';
    $empty=$today;$emptyDay=$today['periodo']['fecha_desde'];$empty=array_replace($empty,$m::calcular([],$today['personas_resumen'],['fecha_desde'=>$emptyDay,'fecha_hasta'=>$emptyDay]));
    $week=$range;$week['periodo']['clave']='semana';$week['periodo']['label']='Esta semana';
    file_put_contents($out.'/datos.json',json_encode(['hoy'=>$today,'ayer'=>$yesterday,'semana'=>$week,'rango'=>$range,'vacio'=>$empty,'equipo'=>$all],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}
echo "Reportes: buzones únicos, horarios de inicio en México, filtros, medianoche, días vacíos y PDF coherentes.\n";
