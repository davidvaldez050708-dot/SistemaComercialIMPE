<?php
declare(strict_types=1);

/**
 * Prueba SIN conexión a MySQL para la reconciliación del contexto Hostinger v2.
 * Las llamadas y su audio se comprueban por separado, nunca aquí.
 */
$root = dirname(__DIR__);
// No incluir el servicio: su cadena de require_once carga configuración
// de producción que no está disponible en un worktree de pruebas.
$source = file_get_contents($root . '/app/services/TelefoniaTransferenciaContextoService.php');
if ($source === false) {
    throw new RuntimeException('No se pudo leer el servicio de contexto v2.');
}
if (!preg_match('/public function registrar\\s*\\((.*?)\\)\\s*:\\s*bool\\s*\\{/s', $source, $firma)) {
    throw new RuntimeException('Falta el método registrar(): bool.');
}
preg_match_all('/\\$[A-Za-z_][A-Za-z0-9_]*/', $firma[1], $params);
$esperados = [
    '$origenId', '$rol', '$origenExtension',
    '$destinoId', '$destinoExtension', '$estadoId',
    '$seguimientoId', '$pbxCallId', '$numeroOrigen',
];
if ($params[0] !== $esperados) {
    throw new RuntimeException('La firma del servicio v2 no tiene los nueve parámetros esperados.');
}
$controller = file_get_contents($root . '/app/controllers/TelefoniaController.php');
$checks = [
    'tabla v2' => str_contains($source, 'CREATE TABLE IF NOT EXISTS telefonia_transferencia_contextos_v2'),
    'lectura v2' => str_contains($source, 'FROM telefonia_transferencia_contextos_v2 c'),
    'clave referencia' => str_contains($source, 'UNIQUE KEY uk_tr_usu_llamada (origen_usuario_id,referencia_llamada)'),
    'pbx firmado' => str_contains($source, "FROM telefonia_zadarma_eventos") &&
        str_contains($source, "evento = 'NOTIFY_INTERNAL'"),
    'propiedad expediente' => str_contains($source, 'analista_id = ? AND estado_id = ?'),
    'numero PBX' => str_contains($source, "($started['caller_id'] ?? '')"),
    'numero navegador validado' => str_contains($source, '$numeroDeclarado !== $telefono10'),
    'vigencia v2' => str_contains($source, 'INTERVAL 90 SECOND'),
    'sesion con alias' => str_contains($controller, "'referencia_llamada'=>$pbxCallId") &&
        str_contains($controller, "'numero_origen'=>$numeroOrigen"),
    'referencias no ambiguas' => str_contains($controller, '!hash_equals($pbxCallId, $referencia)'),
    'nueve parametros' => str_contains($controller, "(string)($data['numero_origen'] ?? '')"),
    'bloqueo sin contexto' => substr_count(
        $controller, 'No fue posible conservar la institución confirmada.'
    ) === 2,
];
foreach ($checks as $name => $valid) {
    if (!$valid) throw new RuntimeException('Fallo en contexto v2: ' . $name);
}
echo "Contexto de transferencias Hostinger v2: OK (sin MySQL)\n";
