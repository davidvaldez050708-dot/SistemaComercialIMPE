<?php
require __DIR__.'/../app/helpers/TelefoniaAsignacionFechaHelper.php';
function verificar($condicion, $mensaje) { if (!$condicion) throw new RuntimeException($mensaje); }

// La conversión no depende de la zona predeterminada del proceso PHP.
foreach (['UTC', 'America/Mexico_City', 'Europe/Madrid'] as $zona) {
    date_default_timezone_set($zona);
    verificar(TelefoniaAsignacionFechaHelper::aMexico('2026-11-24 17:24:46') === '2026-11-24 11:24:46', 'Asignación del mismo día');
    verificar(TelefoniaAsignacionFechaHelper::aMexico('2026-11-24 03:00:00') === '2026-11-23 21:00:00', 'Cambio de fecha');
}
date_default_timezone_set('America/Mexico_City');
$desde = strtotime(TelefoniaAsignacionFechaHelper::aMexico('2026-11-24 17:24:46'));
verificar(strtotime('2026-11-24 13:35:08') >= $desde, 'Reconoce llamada posterior a asignación');
verificar(strtotime('2026-11-24 11:24:45') < $desde, 'Excluye llamada del propietario anterior');
verificar(strtotime('2026-11-24 11:24:46') === $desde, 'Conserva límite inclusivo');
foreach (['', '2026-02-30 12:00:00', '2026-11-24', 'ayer', '2026-11-24 25:00:00'] as $invalida) {
    try { TelefoniaAsignacionFechaHelper::aMexico($invalida); throw new RuntimeException('Fecha inválida aceptada'); }
    catch (DomainException $esperada) {}
}
echo "OK: UTC a México; llamadas posteriores incluidas, anteriores excluidas; fechas inválidas rechazadas.\n";
