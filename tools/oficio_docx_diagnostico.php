<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/app/services/OficioDocxPdfService.php';

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$linea('Diagnóstico de plantilla DOCX para oficios');
$linea(str_repeat('=', 44));

$servicio = new OficioDocxPdfService();
$resultado = $servicio->diagnosticar();

$linea('Plantilla: ' . (($resultado['template_existe'] ?? false) ? '[OK]' : '[FALTA]'));
$linea('Soporte ZIP: ' . (($resultado['zip_disponible'] ?? false) ? '[OK]' : '[FALTA]'));
$linea('Ejecución de procesos: ' . (($resultado['proc_open_disponible'] ?? false) ? '[OK]' : '[FALTA]'));

$conversor = is_array($resultado['conversor'] ?? null)
    ? $resultado['conversor']
    : [];

if ($conversor['ok'] ?? false) {
    $linea('Conversor: [OK] ' . strtoupper((string)($conversor['tipo'] ?? '')));
    $linea('Detalle: ' . (string)($conversor['detalle'] ?? 'Disponible.'));
} else {
    $linea('Conversor: [FALTA]');
    $linea('Detalle: ' . (string)($conversor['detalle'] ?? 'No disponible.'));
}

$linea();

if ($resultado['ok'] ?? false) {
    $linea('[LISTO] El sistema puede rellenar el DOCX y convertirlo a PDF.');
    exit(0);
}

$linea('[PENDIENTE] Falta al menos un requisito para generar oficios desde DOCX.');
exit(1);
