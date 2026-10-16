<?php
declare(strict_types=1);
$path = dirname(__DIR__) . '/public/javascript/telefonia_enrutamiento.js';
$src = file_get_contents($path);
$required = [
    "seguimiento_id:String(seguimientoConfirmado)",
    "pbx_call_id:identity",
    "referencia_llamada:referenciaInstitucional",
    "numero_origen:numeroOrigen",
    "tipo?.value === 'INSTITUCION'",
    "active.direction === 'incoming'",
    "confirmedRecord?.callId === identity",
    "confirmedRecord?.usuarioId === Number(selected.usuario_id)",
    "confirmedRecord?.estadoId === Number(selected.estado_id)",
];
foreach ($required as $fragment) {
    if (!str_contains($src, $fragment) ||
        substr_count($src, $fragment) !== 1) {
        throw new RuntimeException('Invariante de transferencia ausente o ambiguo: ' . $fragment);
    }
}
if (!str_contains($src, "referenciaInstitucional = seguimientoConfirmado > 0") ||
    !str_contains($src, "? String(active.phone || '').trim() : ''")) {
    throw new RuntimeException('La referencia institucional carece de protección.');
}
echo "Campos de referencia institucional compatibles: OK\n";
