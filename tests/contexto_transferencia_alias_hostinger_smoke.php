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
// 'incoming' también es válido en otra guarda del archivo (identificación
// inicial). Exigir su presencia específicamente al construir la referencia,
// en lugar de forzar una única aparición en todo el módulo.
$start = strpos($src, 'const referenciaInstitucional =');
$end = $start === false ? false : strpos($src, 'const numeroOrigen =', $start);
$fragmentoReferencia = ($start !== false && $end !== false)
    ? substr($src, $start, $end - $start) : '';
if (!str_contains($fragmentoReferencia, "seguimientoConfirmado > 0") ||
    !str_contains($fragmentoReferencia, "active.direction === 'incoming'") ||
    !str_contains($fragmentoReferencia, "/^in_[a-z0-9_-]{6,95}$/i.test(identity)") ||
    !str_contains($src, "? String(active.phone || '').trim() : ''")) {
    throw new RuntimeException('La referencia institucional carece de protección.');
}
echo "Campos de referencia institucional compatibles: OK\n";
