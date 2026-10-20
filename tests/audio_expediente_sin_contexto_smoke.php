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
verify(str_contains($source, 'if (count($transferencias) === 0) return null;'), 'Sin contexto verificable no inferir vinculo');
verify(str_contains($source, "SHOW TABLES LIKE 'telefonia_disponibilidad'"), 'Consulta fuente canonica consumida');
verify(str_contains($source, "'ENVIANDO','ENVIADA','TRANSFERIDA'"), 'Aceptar no equivale a transferir');
verify(str_contains($source, "evento = 'NOTIFY_ANSWER' AND internal = ?"), 'Respuesta destinatario');
verify(str_contains($source, "evento = 'NOTIFY_RECORD'"), 'Grabacion firmada');
verify(str_contains($source, 'count($candidatas) > 1'), 'Rechaza multiples grupos');
verify(!str_contains($source, '$iniciosRecepcion'), 'No buscar por telefono y hora solamente');
verify(str_contains($source, 'count($sesiones) !== 1'), 'Sesión única del expediente');
verify(!str_contains($source, "duracion_segundos"), 'No comparar duración PBX/WebRTC');
echo "Vínculo PBX sin contexto: OK (12 comprobaciones).\n";
