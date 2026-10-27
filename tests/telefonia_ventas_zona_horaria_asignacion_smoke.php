<?php
/** Regresión de zona horaria: nunca atribuir llamadas previas a una asignación. */
declare(strict_types=1);
require_once __DIR__.'/../app/services/TelefoniaVentasLlamadasService.php';
$cases=[
    ['2026-10-27 15:29:57','2026-10-27 09:29:57'],
    ['2026-10-27 17:05:00','2026-10-27 11:05:00'],
    ['2026-01-03 04:15:00','2026-01-02 22:15:00']
];
foreach($cases as [$utc,$expected]){
    $actual=TelefoniaVentasLlamadasService::asignacionLocal($utc);
    if($actual!==$expected)throw new RuntimeException("Conversión incorrecta: $utc => $actual");
}
$local=TelefoniaVentasLlamadasService::asignacionLocal('2026-10-27 15:29:57');
if(!('2026-10-27 11:11:36'>=$local))
    throw new RuntimeException('Llamada válida descartada por UTC.');
if(!('2026-10-27 08:00:00'<$local))
    throw new RuntimeException('Se atribuiría llamada anterior a la asignación.');
$source=file_get_contents(__DIR__.'/../app/services/TelefoniaVentasLlamadasService.php');
if(substr_count($source,"CONVERT_TZ(GREATEST(t.created_at,t.updated_at),'+00:00','-06:00')")!==2)
    throw new RuntimeException('Captura/backfill no concilian las fechas.');
if(strpos($source,"AND NOT EXISTS(SELECT 1 FROM telefonia_extensiones other")===false)
    throw new RuntimeException('Se eliminó la exclusión de múltiples propietarios.');
echo "PASS: UTC a Ciudad de México, asignaciones anteriores y guardas de propietario.\n";
