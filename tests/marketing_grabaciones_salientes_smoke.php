<?php
/** Pruebas puras de control de acceso por eventos, sin acceso a Zadarma. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/TelefoniaGrabacionSalienteMarketingService.php';

$evt = static function (string $tipo, string $interno = '100',
    string $disposition = '', bool $recorded = false,
    string $transferFrom = ''): array {
    return [
        'event' => $tipo, 'internal' => $interno,
        'disposition' => $disposition,
        'is_recorded' => $recorded ? '1' : '0',
        'transfer_from' => $transferFrom
    ];
};
$call = [
    $evt('NOTIFY_OUT_START'),
    $evt('NOTIFY_ANSWER'),
    $evt('NOTIFY_OUT_END', '100', 'answered', true)
];
$check = static function (bool $condition, string $why): void {
    if (!$condition) { fwrite(STDERR, "FALLO: $why\n"); exit(1); }
};
$verify = static fn(array $rows): bool =>
    TelefoniaGrabacionSalienteMarketingService::eventosAutorizados($rows, '100');
$check($verify($call), 'saliente finalizada y grabada');
$check(!$verify([$evt('NOTIFY_OUT_START'), $evt('NOTIFY_ANSWER')]),
    'saliente aún activa');
$check(!$verify([$evt('NOTIFY_OUT_START'),
    $evt('NOTIFY_OUT_END', '100', 'answered')]), 'sin indicio de audio');
$check(!$verify([$evt('NOTIFY_OUT_END', '100', 'busy', true)]),
    'la llamada ocupada no fue contestada');
$check(!$verify([$evt('NOTIFY_OUT_END', '101', 'answered', true)]),
    'finalización de extensión ajena');
$transferencia = array_merge($call, [$evt('NOTIFY_INTERNAL', '101', '', false, '100')]);
$check(!$verify($transferencia), 'transferencia con audio de otra extensión');
$check(!$verify([$evt('NOTIFY_ANSWER', '101'),
    $evt('NOTIFY_OUT_END', '100', 'busy', true)]),
    'respuesta de otra extensión');
$check($verify([$evt('NOTIFY_OUT_END', '100', 'answered', true)]),
    'evento final firmado reportó conversación y grabación');
echo "Grabaciones salientes Marketing: OK (8 verificaciones)\n";
