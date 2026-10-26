<?php
require __DIR__.'/../app/services/VentasDesempenoService.php';
function vd(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$people=[['id'=>1,'nombre_completo'=>'Zulema'],['id'=>2,'nombre_completo'=>'Ana'],['id'=>3,'nombre_completo'=>'Beatriz']];
$period=['fecha_desde'=>'2026-10-25','fecha_hasta'=>'2026-10-25','clave'=>'hoy','label'=>'Hoy'];
$rows=[];
foreach ([1=>4,2=>2] as $uid=>$total) for ($i=0;$i<$total;$i++) $rows[]=[
    'id'=>count($rows)+1,'usuario_id'=>$uid,'extension'=>'150','pbx_call_id'=>'call-'.$uid.'-'.$i,'fecha'=>'2026-10-25 10:00:00',
    'direccion'=>'Saliente','registro'=>['resultado'=>$uid===2&&$i===1?'BUZON_VOZ':'INTERESADO'],'segundos'=>null,'conectada'=>true,'finalizada'=>true];
$data=VentasActividadMetricas::calcular($rows,$people,$period)+['vista'=>'global','personas'=>$people];
$d=VentasDesempenoService::presentar($data);
vd(array_column($d['ranking'],'id')===[1,2,3],'Ranking por actividad, no por nombre');
vd(array_column($d['ranking'],'indice')===[100.0,48.8,0.0],'Ponderaciones visibles y ausencia de actividad');
vd($d['ranking'][2]['tasa_contacto']===null,'Sin denominador no se inventa una tasa');
vd($d['resumen']===$data['resumen'],'Desempeño conserva cifras del reporte');
vd(array_column($d['ranking'],'posicion')===[1,2,3],'Posiciones consecutivas');
vd(count($d['reconocimientos'])===3 && $d['reconocimientos'][0]['nombre']==='Zulema','Destacados por hechos confirmados');
vd($d['tendencia'][0]['principal']===6 && $d['tendencia'][0]['secundario']===5,'Evolución con los mismos hechos');
$rowsWithDuration=array_map(static fn($r)=>array_replace($r,['segundos'=>30]),$rows);
$with=VentasDesempenoService::presentar(VentasActividadMetricas::calcular($rowsWithDuration,$people,$period)+['vista'=>'global','personas'=>$people]);
vd(array_column($with['ranking'],'indice')===array_column($d['ranking'],'indice'),'La falta de duración no penaliza el ranking');
$single=VentasDesempenoService::presentar(VentasActividadMetricas::calcular($rows,[$people[0]],$period)+['vista'=>'propio','personas'=>[$people[0]]]);
vd($single['ranking'][0]['indice']===null && !$single['reconocimientos'],'Vista personal sin competición artificial');
$tieRows=array_merge($rows,array_map(static fn($r)=>array_replace($r,['usuario_id'=>3]),array_slice($rows,0,4)));
$ties=VentasDesempenoService::presentar(VentasActividadMetricas::calcular($tieRows,$people,$period)+['vista'=>'global','personas'=>$people]);
vd(str_contains($ties['reconocimientos'][0]['titulo'],'Empate') && str_contains($ties['reconocimientos'][0]['nombre'],'Beatriz') && str_contains($ties['reconocimientos'][0]['nombre'],'Zulema'),'Reconocimientos compartidos en empates');
$zero=VentasDesempenoService::presentar(VentasActividadMetricas::calcular([],$people,$period)+['vista'=>'global','personas'=>$people]);
vd(!$zero['reconocimientos'] && array_column($zero['ranking'],'indice')===[0.0,0.0,0.0],'Grupo vacío sin destacados ficticios');
echo "Desempeño: cálculo del índice, orden, empates, alcance personal, ceros, duración pendiente y paridad del reporte OK\n";
