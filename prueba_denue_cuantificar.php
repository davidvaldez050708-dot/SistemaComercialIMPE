<?php

require_once __DIR__ . '/app/services/DenueService.php';

$denue = new DenueService();
$resultado = $denue->obtenerSectoresEstado('02');

header('Content-Type: text/html; charset=utf-8');

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Prueba DENUE - Cuantificar</title>
</head>
<body>
    <h2>Prueba DENUE - Cuantificar</h2>
    <p><strong>Estado:</strong> Baja California (02)</p>

    <pre><?php print_r($resultado); ?></pre>
</body>
</html>
