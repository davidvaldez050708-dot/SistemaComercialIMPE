<?php
require __DIR__.'/../app/helpers/VentasPendientesPolicy.php';
function pendienteCheck(bool $ok,string $mensaje): void { if(!$ok)throw new RuntimeException($mensaje); }
$mx=new DateTimeZone('America/Mexico_City');
$now=new DateTimeImmutable('2026-10-26 12:00:00',$mx);
pendienteCheck(!VentasPendientesPolicy::programada('2026-10-26 12:16:00',$now)['avisar'],'No avisar con más de 15 minutos');
pendienteCheck(VentasPendientesPolicy::programada('2026-10-26 12:15:00',$now)['avisar'],'Aviso anticipado 15 minutos');
pendienteCheck(!VentasPendientesPolicy::programada('2026-10-26 12:01:00',$now)['vencida'],'Futura no vencida');
pendienteCheck(VentasPendientesPolicy::programada('2026-10-26 12:00:00',$now)['vencida'],'Vence a la hora programada');
$row=['devolucion'=>'PENDIENTE','created_at'=>'2026-10-26 05:58:00','updated_at'=>'2026-10-28 23:00:00'];
$p=VentasPendientesPolicy::devolucion($row,$now);
pendienteCheck($p['limite']==='2026-10-25 23:59:59'&&$p['vencida'],'UTC convertido antes de elegir fin de día; updated_at no prorroga');
$row['ruta_json']=json_encode(['devolucion_solicitada_at'=>'2026-10-26 06:02:00']);
$p=VentasPendientesPolicy::devolucion($row,$now);
pendienteCheck($p['limite']==='2026-10-26 23:59:59'&&!$p['vencida']&&$p['fecha_original'],'La fecha explícita de devolución prevalece');
pendienteCheck(!VentasPendientesPolicy::devolucion($row,new DateTimeImmutable('2026-10-26 23:59:59',$mx))['vencida'],'No vencer antes del fin del día');
pendienteCheck(VentasPendientesPolicy::devolucion($row,new DateTimeImmutable('2026-10-27 00:00:00',$mx))['vencida'],'Vence al cambiar el día');
$row['devolucion']='ATENDIDA';
pendienteCheck(!VentasPendientesPolicy::devolucion($row,new DateTimeImmutable('2026-10-28 00:00:00',$mx))['vencida'],'Atendida no pendiente');
pendienteCheck(VentasPendientesPolicy::devolucion(['devolucion'=>'PENDIENTE'],$now)['limite']===null,'Sin fecha no inventar vencimiento');
pendienteCheck(str_ends_with($p['fecha_iso'],'-06:00'),'Cliente recibe zona explícita');
echo "OK: programación, anticipación, medianoche México/UTC, lectura, legado y cierre.\n";
