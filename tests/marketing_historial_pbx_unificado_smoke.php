<?php
/** Pruebas puras de entrantes/salientes; no hay BD ni credenciales. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/TelefoniaRecepcionMarketingService.php';
$evt = static function (
    string $id, string $type, string $internal,
    string $number = '', string $disposition = '',
    int $duration = 0, string $transferFrom = '',
    bool $recorded = false
): array {
    return [
        'pbx_call_id' => $id,
        'evento' => $type,
        'internal' => $internal,
        'caller_id' => $type === 'NOTIFY_INTERNAL' ? $number : '',
        'destination' => $type === 'NOTIFY_OUT_START' ? $number : '',
        'received_at' => '2026-10-12 15:00:00',
        'duration' => $duration,
        'disposition' => $disposition,
        'is_recorded' => $recorded ? 1 : 0,
        'call_id_with_rec' => '',
        'payload_json' => $transferFrom !== ''
            ? json_encode(['transfer_from' => $transferFrom])
            : '{}'
    ];
};
$rows = [
    $evt('inA', 'NOTIFY_ANSWER', '100'),
    $evt('inA', 'NOTIFY_INTERNAL', '100', '+527777885173'),
    $evt('inA', 'NOTIFY_END', '', '', '', 18, '', true),
    $evt('inB', 'NOTIFY_INTERNAL', '100', '+521234567890'),
    $evt('inB', 'NOTIFY_END', '', '', '', 0),
    $evt('outA', 'NOTIFY_OUT_START', '100', '7777885173'),
    $evt('outA', 'NOTIFY_OUT_END', '100', '', 'answered', 33, '', true),
    $evt('outB', 'NOTIFY_OUT_START', '100', '3312345678'),
    $evt('outB', 'NOTIFY_OUT_END', '100', '', 'no-answer', 0),
    $evt('outC', 'NOTIFY_OUT_START', '100', '2212345678'),
    $evt('outC', 'NOTIFY_OUT_END', '100', '', 'busy', 0),
    $evt('outD', 'NOTIFY_OUT_START', '100', '5512345678'),
    $evt('inC', 'NOTIFY_INTERNAL', '100', '+529991234567'),
    $evt('inC', 'NOTIFY_ANSWER', '100'),
    $evt('inC', 'NOTIFY_INTERNAL', '101', '', '', 0, '100'),
    $evt('inC', 'NOTIFY_END', '', '', '', 44, '', true),
    // Eventos de la extensión ajena, aunque comparta una misma PBX.
    $evt('outside', 'NOTIFY_OUT_START', '101', '5544444444'),
    $evt('outside', 'NOTIFY_OUT_END', '101', '', 'answered', 22),
    $evt('outside', 'NOTIFY_RECORD', '', '', '', 22, '', true),
    $evt('unknown', 'NOTIFY_OUT_END', '', '', 'answered', 100),
];
$fail = static function (bool $ok, string $label): void {
    if (!$ok) { fwrite(STDERR, "FALLO: $label\n"); exit(1); }
};
$all = TelefoniaRecepcionMarketingService::agruparEventos($rows,'100',true);
$byId = [];
foreach ($all as $call) $byId[$call['pbx_call_id']] = $call;
$fail(count($all) === 7, 'sin duplicados ni otras extensiones');
$fail($byId['inA']['tipo'] === 'Entrante' &&
    $byId['inA']['estado'] === 'Atendida' &&
    $byId['inA']['tiene_grabacion'], 'entrante conservada');
$fail($byId['inB']['estado'] === 'Perdida', 'entrante perdida');
$fail($byId['outA']['tipo'] === 'Saliente' &&
    $byId['outA']['estado'] === 'Completada' &&
    $byId['outA']['numero'] === '7777885173' &&
    $byId['outA']['segundos'] === 33, 'saliente contestada');
$fail($byId['outA']['tiene_grabacion'] === false &&
    $byId['outA']['grabacion_salida_pendiente'] === true,
    'audio saliente no expuesto sin autorización');
$fail($byId['outB']['estado'] === 'Sin respuesta' &&
    $byId['outC']['estado'] === 'Ocupada' &&
    $byId['outD']['estado'] === 'Marcando',
    'salientes no respondidas y marcando');
$fail($byId['inC']['estado'] === 'Transferida' &&
    $byId['inC']['destino_transferencia'] === '101' &&
    $byId['inC']['tiene_grabacion'] === false,
    'transferencias solo confirmadas sin audio ajeno');
$incomingOnly = TelefoniaRecepcionMarketingService::agruparEventos($rows,'100',false);
$fail(count($incomingOnly) === 3, 'sin permiso saliente se muestran solo entrantes');
echo 'Marketing · Historial PBX unificado: OK (8 comprobaciones)\n';
