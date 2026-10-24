<?php
require __DIR__.'/../app/helpers/HorarioContacto.php';
$n=0;
function checkHorario($ok,$label) { global $n; if (!$ok) throw new RuntimeException($label); $n++; echo "OK $label\n"; }
checkHorario(HorarioContacto::desdeEntrada([])===null,'Formulario abierto antes de actualizar sigue siendo válido');
checkHorario(HorarioContacto::desdeEntrada(['contacto_disponibilidad'=>'sin_preferencia','contacto_dias'=>['invalid']])==='Sin preferencia','Sin preferencia no conserva campos ocultos anteriores');
$custom=['contacto_disponibilidad'=>'personalizado','contacto_dias'=>['vie','lun','mie','lun'],'contacto_desde'=>'18:00','contacto_hasta'=>'20:30'];
checkHorario(HorarioContacto::desdeEntrada($custom)==='Lun, Mié, Vie · 18:00–20:30 (hora local)','Días ordenados sin duplicados y hora local legible');
foreach ([['contacto_disponibilidad'=>''],['contacto_disponibilidad'=>[]],['contacto_dias'=>['lun']],['contacto_disponibilidad'=>'otro'],array_replace($custom,['contacto_dias'=>[]]),array_replace($custom,['contacto_dias'=>'lun']),array_replace($custom,['contacto_dias'=>['<script>']]),array_replace($custom,['contacto_dias'=>[[]]]),array_replace($custom,['contacto_desde'=>'24:00']),array_replace($custom,['contacto_hasta'=>'18:00']),array_replace($custom,['contacto_hasta'=>'06:00']),array_replace($custom,['contacto_hasta'=>'20:60']),array_replace($custom,['contacto_desde'=>[]])] as $bad) {
    try { HorarioContacto::desdeEntrada($bad); throw new RuntimeException('Aceptó horario inválido'); }
    catch (DomainException $e) { checkHorario(true,'Entrada inválida rechazada sin avisos PHP'); }
}
foreach ([[],str_repeat('a',256),"x\0y","\xFF"] as $bad) {
    try { HorarioContacto::paraGuardar($bad); throw new RuntimeException('Aceptó almacenamiento inválido'); }
    catch (DomainException $e) { checkHorario(true,'Almacenamiento inválido rechazado'); }
}
checkHorario(HorarioContacto::paraGuardar(null)===null && HorarioContacto::paraGuardar('  ')===null,'Horario ausente se conserva NULL');
echo "PASS $n comprobaciones de horario\n";
