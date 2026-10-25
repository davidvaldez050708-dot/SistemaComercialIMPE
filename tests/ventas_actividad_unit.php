<?php
require __DIR__.'/../app/services/VentasActividadMetricas.php';
require __DIR__.'/../app/helpers/VentasActividadAccess.php';
function va(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$hoy=new DateTimeImmutable('2026-10-25',new DateTimeZone('America/Mexico_City'));
$p=VentasActividadMetricas::periodo(['periodo'=>'semana'],$hoy);
va($p['fecha_desde']==='2026-10-19' && $p['fecha_hasta']==='2026-10-25','Semana incluye lunes y domingo');
foreach ([['periodo'=>'personalizado','fecha_desde'=>'2026-02-30','fecha_hasta'=>'2026-10-25'],['periodo'=>'personalizado','fecha_desde'=>'2026-10-26','fecha_hasta'=>'2026-10-27'],['periodo'=>'personalizado','fecha_desde'=>'2024-01-01','fecha_hasta'=>'2026-10-25']] as $f) {
    try { VentasActividadMetricas::periodo($f,$hoy);throw new LogicException('Fecha inválida aceptada'); } catch (InvalidArgumentException $e) {}
}
$base=['id'=>1,'usuario_id'=>3,'extension'=>'150','pbx_call_id'=>'call-unique-001','fecha'=>'2026-10-25 10:00:00','direccion'=>'Saliente',
    'registro'=>['resultado'=>'INTERESADO'],'segundos'=>13,'conectada'=>true,'finalizada'=>true];
$rows=[$base,$base,array_replace($base,['id'=>2,'pbx_call_id'=>'call-unique-002','segundos'=>null]),array_replace($base,['id'=>3,'pbx_call_id'=>'call-unique-003','conectada'=>false,'segundos'=>0,'registro'=>['resultado'=>'BUZON_VOZ']]),
    array_replace($base,['id'=>'intento-4','pbx_call_id'=>'','segundos'=>null,'registro'=>null]),array_replace($base,['id'=>5,'usuario_id'=>99]),array_replace($base,['id'=>6,'pbx_call_id'=>'call-unique-006','fecha'=>'2026-10-18 23:59:59'])];
$data=VentasActividadMetricas::calcular($rows,[['id'=>3,'nombre_completo'=>'Asesor de prueba']],$p);$s=$data['resumen'];
va($s['llamadas']===3 && $s['intentos']===1 && $s['conversaciones']===2,'Deduplicar, excluir personas/fechas ajenas y distinguir buzón');
va($s['segundos']===13 && $s['promedio_segundos']===13 && $s['duraciones_pendientes']===1,'No convertir pendiente en cero ni sumar otros tramos');
va(count($data['dias'])===7 && $data['dias'][0]['llamadas']===0,'Incluir días sin actividad');
foreach ([3=>'propio',6=>'equipo',2=>'global',1=>'global'] as $role=>$scope) {
    $_SESSION=['usuario_id'=>10,'rol_id'=>$role,'permisos'=>['reportes.ver','reportes.ventas','reportes.ventas.'.$scope,'desempeno.ver','desempeno.ver_'.$scope]];
    va(VentasActividadAccess::alcance()===$scope && VentasActividadAccess::alcance(true)===$scope,'Alcance por rol');
    $_SESSION['permisos']=['reportes.ver','reportes.ventas','reportes.ventas.global','desempeno.ver','desempeno.ver_global'];
    if (in_array($role,[3,6],true)) va(VentasActividadAccess::alcance()==='' && VentasActividadAccess::alcance(true)==='','Permiso global no amplía rol territorial');
    $_SESSION['permisos']=[];va(VentasActividadAccess::alcance()==='' && VentasActividadAccess::alcance(true)==='','Revocación inmediata');
}
echo "Métricas: deduplicación, fechas, buzón, duración pendiente, promedio y alcances/revocación OK\n";
