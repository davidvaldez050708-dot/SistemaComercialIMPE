<?php

require_once __DIR__ . '/app/services/InegiService.php';

$inegi = new InegiService();
$resultado = $inegi->obtenerPoblacionEstado('02');

header('Content-Type: text/html; charset=utf-8');

echo '<pre>';
print_r($resultado);
echo '</pre>';
