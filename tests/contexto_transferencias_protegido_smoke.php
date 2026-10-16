<?php
declare(strict_types=1);
$controller = file_get_contents(dirname(__DIR__) . '/app/controllers/TelefoniaController.php');
$ui = file_get_contents(dirname(__DIR__) . '/public/javascript/telefonia_enrutamiento.js');
$receiver = file_get_contents(dirname(__DIR__) . '/public/javascript/telefonia_analista_historial_pasivo.js');
$history = file_get_contents(dirname(__DIR__) . '/app/services/TelefoniaEntrantesAnalistaService.php');
if (substr_count($controller, "No fue posible conservar la institución confirmada.") !== 2 ||
    strpos($controller, "'ok'=>false") === false ||
    !str_contains($ui, "message.startsWith('No fue posible conservar la institución confirmada.')") ||
    !str_contains($receiver, 'attempt < 16') ||
    !str_contains($history, 'conciliarHistorialPbxWebrtc($llamadas, $sesiones)')) {
    throw new RuntimeException('FALLO: falta una protección en el flujo de transferencias.');
}
echo "Contexto de transferencias protegido: OK\n";
