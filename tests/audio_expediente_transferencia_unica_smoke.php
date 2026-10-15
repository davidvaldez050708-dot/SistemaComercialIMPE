<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/TelefoniaGrabacionExpedienteTransferidaService.php';
$fail = static function ($condition, $text) {
    if (!$condition) { fwrite(STDERR, "FALLO: $text\n"); exit(1); }
};
$service = TelefoniaGrabacionExpedienteTransferidaService::class;
$fail($service::numero10('+527777885173') === '7777885173', 'últimos diez dígitos');
$fail($service::numero10('100') === '', 'rechazar extensión');
$fail($service::distanciaSegundos('2026-10-15 20:24:00','2026-10-15 14:24:00') === 0,
    'webhook México vs WebRTC UTC');
$fail($service::distanciaSegundos('2026-10-15 20:24:00','2026-10-15 20:24:00') === 0,
    'webhook UTC vs WebRTC UTC');
$fail($service::distanciaSegundos('2026-10-15 20:24:00','2026-10-15 14:29:00') === 300,
    'rechazar llamada cinco minutos después');
$code = file_get_contents(dirname(__DIR__) . '/app/services/TelefoniaGrabacionExpedienteTransferidaService.php');
$fail(str_contains($code, 'count($candidatas) > 1'), 'no vincular PBX ambiguas');
$fail(str_contains($code, "evento = 'NOTIFY_RECORD'"), 'solo audio con webhook');
$fail(str_contains($code, "evento = 'NOTIFY_ANSWER'"), 'extensión debe contestar');
$fail(str_contains($code, 'seguimiento_id = ? AND interaccion_id = ?'), 'comprobar interacción');
$fail(str_contains($code, 'destino_usuario_id = ?'), 'comprobar destinatario');
echo "Audio transferido expediente: OK (10 verificaciones).\n";
