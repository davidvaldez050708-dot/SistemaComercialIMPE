<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/services/TelefoniaGrabacionExpedienteTransferidaService.php';
$service = TelefoniaGrabacionExpedienteTransferidaService::class;
function verify(bool $yes, string $name): void {
    if (!$yes) { fwrite(STDERR, "FALLO: " . $name . "\n"); exit(1); }
}
verify($service::numero10('+52 777 788 5173') === '7777885173', 'Número mexicano');
verify($service::distanciaSegundos('2026-10-15 21:49:00',
    '2026-10-15 15:49:00') === 0, 'UTC y CDMX');
verify($service::distanciaSegundos('2026-10-15 21:49:00',
    '2026-10-15 15:49:39') === 39, '39 segundos no excluyen transferencia');
$source = file_get_contents(dirname(__DIR__) .
    '/app/services/TelefoniaGrabacionExpedienteTransferidaService.php');
verify(str_contains($source, 'if (count($transferencias) === 0)'), 'Sólo alternativa sin contexto');
verify(str_contains($source, "internal = '100'"), 'Origen recepción PBX');
verify(str_contains($source, "evento = 'NOTIFY_ANSWER' AND internal = ?"), 'Respuesta destinatario');
verify(str_contains($source, "evento = 'NOTIFY_RECORD'"), 'Grabación firmada');
verify(str_contains($source, "count($candidatas) > 1"), 'Rechaza múltiples grupos');
verify(str_contains($source, 'count($iniciosRecepcion) >= 150'), 'Rechaza ventanas truncadas');
verify(str_contains($source, "if ($contextosPbx)"), 'No elude el contexto si existe');
verify(str_contains($source, 'count($sesiones) !== 1'), 'Sesión única del expediente');
verify(!str_contains($source, "duracion_segundos"), 'No comparar duración PBX/WebRTC');
echo "Vínculo PBX sin contexto: OK (12 comprobaciones).\n";
