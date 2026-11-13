<?php
/** Punto de entrada exclusivo para la tarea PHP programada de Hostinger. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$argv = [__FILE__, '--apply'];
$argc = count($argv);
require __DIR__.'/importar_zoho_leads.php';
