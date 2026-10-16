<?php
declare(strict_types=1);

/**
 * Regression estática del caso de Caller ID tardío.
 * No crea sesión WebRTC, no abre MySQL y no utiliza datos de llamadas.
 */
$source = file_get_contents(dirname(__DIR__) .
    '/public/javascript/telefonia_analista_historial_pasivo.js');
if ($source === false) {
    throw new RuntimeException('No se pudo leer el observador.');
}
$checks = [
    'sesion SDK identificada' => str_contains($source, "validId(state.callToken)"),
    'número recibido en estados posteriores' =>
        str_contains($source, 'const reportedNumber = String(state.destination ||'),
    'numero nacional verificable' =>
        str_contains($source, 'const usableCallerNumber = value =>') &&
        str_contains($source, 'digits.length >= 10 && digits.length <= 14'),
    'sin consulta con número provisional' =>
        str_contains($source, 'if (usableCallerNumber(active.number)) {'),
    'recuperación por la misma llamada' =>
        str_contains($source, 'if (active?.id === id &&') &&
        str_contains($source, '!usableCallerNumber(active.number) &&') &&
        str_contains($source, 'usableCallerNumber(reportedNumber)'),
    'número tardío aplicado' =>
        str_contains($source, 'active.number = reportedNumber;') &&
        substr_count($source, 'void fetchReceptionContext(id, active.number);') === 2,
    'evitar resultado de otra sesión' =>
        substr_count($source, 'if (active?.id !== id) return;') >= 1,
];
foreach ($checks as $check => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO Caller ID diferido: {$check}\n");
        exit(1);
    }
}
echo "Recuperación de Caller ID diferido: OK (estática)\n";
