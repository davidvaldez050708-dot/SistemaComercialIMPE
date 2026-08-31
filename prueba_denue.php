<?php

require_once __DIR__ . '/config/api_keys.php';

$url = DENUE_BASE_URL
    . '/BuscarAreaActEstr/17/0/0/0/0/93/0/0/0/0/1/100/0/0/'
    . DENUE_TOKEN;

$respuesta = file_get_contents($url);

if ($respuesta === false) {
    die("No se pudo consultar la API de DENUE.");
}

$datos = json_decode($respuesta, true);

$organizacionesPriorizadas = [];

foreach ($datos as $registro) {

    $nombre = strtoupper($registro['Nombre'] ?? '');
    $estrato = $registro['Estrato'] ?? '';
    $telefono = trim($registro['Telefono'] ?? '');
    $correo = trim($registro['Correo_e'] ?? '');
    $sitio = trim($registro['Sitio_internet'] ?? '');

    $puntuacion = 0;

    // Organizaciones que parecen tener mayor potencial institucional
    $palabrasPrioritarias = [
        'SECRETARIA',
        'AYUNTAMIENTO',
        'INSTITUTO',
        'TRIBUNAL',
        'FISCALIA',
        'GOBIERNO',
        'DIF',
        'COMISION',
        'DELEGACION',
        'DIRECCION',
        'UNIVERSIDAD',
        'COORDINACION'
    ];

    foreach ($palabrasPrioritarias as $palabra) {
        if (strpos($nombre, $palabra) !== false) {
            $puntuacion += 30;
            break;
        }
    }

    // Unidades que normalmente tendrían menor prioridad para convenio
    $palabrasBajaPrioridad = [
        'ARCHIVO',
        'AUDITORIO',
        'ALMACEN',
        'AYUDANTIA'
    ];

    foreach ($palabrasBajaPrioridad as $palabra) {
        if (strpos($nombre, $palabra) !== false) {
            $puntuacion -= 30;
            break;
        }
    }

    // Tamaño
    if (
        strpos($estrato, '31 a 50') !== false ||
        strpos($estrato, '51 a 100') !== false ||
        strpos($estrato, '101 a 250') !== false ||
        strpos($estrato, '251 y más') !== false
    ) {
        $puntuacion += 20;
    }

    // Datos disponibles
    if ($telefono !== '') {
        $puntuacion += 10;
    }

    if ($correo !== '') {
        $puntuacion += 20;
    }

    if ($sitio !== '') {
        $puntuacion += 10;
    }

    $registro['Puntuacion'] = $puntuacion;

    $organizacionesPriorizadas[] = $registro;
}

// Ordenar de mayor a menor
usort($organizacionesPriorizadas, function ($a, $b) {
    return $b['Puntuacion'] <=> $a['Puntuacion'];
});

$organizacionesFiltradas = [];

foreach ($datos as $registro) {

    $estrato = $registro['Estrato'] ?? '';
    $actividad = strtolower($registro['Clase_actividad'] ?? '');

    $empresaMedianaGrande =
        strpos($estrato, '31 a 50') !== false ||
        strpos($estrato, '51 a 100') !== false ||
        strpos($estrato, '101 a 250') !== false ||
        strpos($estrato, '251 y más') !== false;

    $actividadPrioritaria =
        strpos($actividad, 'administración pública') !== false ||
        strpos($actividad, 'gobierno') !== false ||
        strpos($actividad, 'educación') !== false ||
        strpos($actividad, 'educativo') !== false ||
        strpos($actividad, 'salud') !== false ||
        strpos($actividad, 'hospital') !== false;

    if ($empresaMedianaGrande || $actividadPrioritaria) {
        $organizacionesFiltradas[] = $registro;
    }
}

if (!is_array($datos)) {
    die("La respuesta de DENUE no pudo ser procesada.");
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Prueba DENUE</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
        }

        th {
            background: #eee;
        }
    </style>
</head>

<body>

<h2>Organizaciones encontradas en Morelos</h2>

<p>
    Organizaciones potenciales encontradas:
    <strong><?= count($organizacionesPriorizadas) ?></strong>
</p>

<table>

    <thead>
        <tr>
            <th>Nombre</th>
            <th>Actividad</th>
            <th>Tamaño</th>
            <th>Ubicación</th>
            <th>Teléfono</th>
            <th>Correo</th>
            <th>Sitio web</th>
            <th>Prioridad</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($organizacionesPriorizadas as $registro): ?>

        <tr>
            <td><?= htmlspecialchars($registro['Nombre'] ?? '') ?></td>

            <td><?= htmlspecialchars($registro['Clase_actividad'] ?? '') ?></td>

            <td><?= htmlspecialchars($registro['Estrato'] ?? '') ?></td>

            <td><?= htmlspecialchars($registro['Ubicacion'] ?? '') ?></td>

            <td><?= htmlspecialchars($registro['Telefono'] ?? 'Sin teléfono') ?></td>

            <td><?= htmlspecialchars($registro['Correo_e'] ?? 'Sin correo') ?></td>

            <td><?= htmlspecialchars($registro['Sitio_internet'] ?? 'Sin sitio web') ?></td>

            <td><?= $registro['Puntuacion'] ?></td>
        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

</body>
</html>