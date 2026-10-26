<?php
// Sólo consultas de resumen existentes y SELECT del panel. No sincroniza fechas.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
try {
    require_once __DIR__ . '/../app/models/ConvocatoriaModel.php';
    require_once __DIR__ . '/../app/models/MarketingIndicadoresModel.php';
    $modelo = new ConvocatoriaModel();
    $resumen = $modelo->obtenerResumenDashboard();
    $resumen['proximas_finalizar'] = count($modelo->obtenerProximasFinalizarDashboard());
    $cobertura = $modelo->obtenerCoberturaTerritorialDashboard();
    $datos = (new MarketingIndicadoresModel())->obtener($resumen, $cobertura);
    echo json_encode(['version' => 'marketing-indicadores-v1', 'indicadores' => $datos], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    echo "SOLO LECTURA. Sin sincronización, escrituras, contactos, credenciales ni envíos.\n";
    exit($datos['convenios'] === null || $datos['aliados'] === null ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'MARKETING_INDICADORES_DIAGNOSTICO clase=' . get_class($error) . ' codigo=' . (int)$error->getCode() . ". Sin datos privados.\n");
    exit(2);
}
