<?php

require_once __DIR__ . '/config/api_keys.php';

$actividad = '0';       // Todas las actividades
$areaGeografica = '0';  // Todo México
$estrato = '0';         // Todos los tamaños

$url = DENUE_BASE_URL
    . '/Cuantificar/'
    . $actividad
    . '/'
    . $areaGeografica
    . '/'
    . $estrato
    . '/'
    . rawurlencode(DENUE_TOKEN);

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ]
]);

$respuesta = curl_exec($ch);

$codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$errorCurl = curl_error($ch);

curl_close($ch);

header('Content-Type: text/html; charset=utf-8');

echo '<h2>Prueba DENUE - Nacional</h2>';

echo '<p><strong>Área:</strong> Todo México</p>';

echo '<p><strong>Código HTTP:</strong> '
    . htmlspecialchars((string) $codigoHttp)
    . '</p>';

if ($respuesta === false || $errorCurl !== '') {
    echo '<p><strong>Error cURL:</strong> '
        . htmlspecialchars($errorCurl)
        . '</p>';
    exit;
}

$datos = json_decode($respuesta, true);

if ($datos === null) {
    echo '<p>No fue posible interpretar la respuesta como JSON.</p>';

    echo '<pre>';
    echo htmlspecialchars($respuesta);
    echo '</pre>';
    exit;
}

$nombresSectores = [
    '11' => 'Agricultura, cría y explotación de animales, aprovechamiento forestal, pesca y caza',
    '21' => 'Minería',
    '22' => 'Generación, transmisión, distribución y comercialización de energía eléctrica, suministro de agua y gas natural',
    '23' => 'Construcción',
    '31-33' => 'Industrias manufactureras',
    '43' => 'Comercio al por mayor',
    '46' => 'Comercio al por menor',
    '48-49' => 'Transportes, correos y almacenamiento',
    '51' => 'Información en medios masivos',
    '52' => 'Servicios financieros y de seguros',
    '53' => 'Servicios inmobiliarios y de alquiler de bienes muebles e intangibles',
    '54' => 'Servicios profesionales, científicos y técnicos',
    '55' => 'Dirección y administración de grupos empresariales o corporativos',
    '56' => 'Servicios de apoyo a los negocios y manejo de residuos, y servicios de remediación',
    '61' => 'Servicios educativos',
    '62' => 'Servicios de salud y de asistencia social',
    '71' => 'Servicios de esparcimiento, culturales y deportivos, y otros servicios recreativos',
    '72' => 'Servicios de alojamiento temporal y de preparación de alimentos y bebidas',
    '81' => 'Otros servicios excepto actividades gubernamentales',
    '93' => 'Actividades legislativas, gubernamentales, de impartición de justicia y de organismos internacionales y extraterritoriales'
];

$totalesPorCodigo = [];
$entidadesEncontradas = [];

/*
|--------------------------------------------------------------------------
| Recorrer respuesta nacional
|--------------------------------------------------------------------------
|
| DENUE devuelve los datos separados por entidad federativa.
| Solo utilizaremos registros de nivel sector: códigos AE de 2 dígitos.
|
*/

foreach ($datos as $registro) {

    $claveActividad = (string) ($registro['AE'] ?? '');
    $claveArea = (string) ($registro['AG'] ?? '');

    if (strlen($claveActividad) !== 2) {
        continue;
    }

    if (!preg_match('/^\d{2}$/', $claveArea)) {
        continue;
    }

    $total = isset($registro['Total'])
        ? (int) $registro['Total']
        : 0;

    $entidadesEncontradas[$claveArea] = true;

    if (!isset($totalesPorCodigo[$claveActividad])) {
        $totalesPorCodigo[$claveActividad] = 0;
    }

    $totalesPorCodigo[$claveActividad] += $total;
}

/*
|--------------------------------------------------------------------------
| Agrupar sectores SCIAN
|--------------------------------------------------------------------------
*/

$establecimientosPorSector = [
    '11' => $totalesPorCodigo['11'] ?? 0,
    '21' => $totalesPorCodigo['21'] ?? 0,
    '22' => $totalesPorCodigo['22'] ?? 0,
    '23' => $totalesPorCodigo['23'] ?? 0,

    '31-33' =>
        ($totalesPorCodigo['31'] ?? 0)
        + ($totalesPorCodigo['32'] ?? 0)
        + ($totalesPorCodigo['33'] ?? 0),

    '43' => $totalesPorCodigo['43'] ?? 0,
    '46' => $totalesPorCodigo['46'] ?? 0,

    '48-49' =>
        ($totalesPorCodigo['48'] ?? 0)
        + ($totalesPorCodigo['49'] ?? 0),

    '51' => $totalesPorCodigo['51'] ?? 0,
    '52' => $totalesPorCodigo['52'] ?? 0,
    '53' => $totalesPorCodigo['53'] ?? 0,
    '54' => $totalesPorCodigo['54'] ?? 0,
    '55' => $totalesPorCodigo['55'] ?? 0,
    '56' => $totalesPorCodigo['56'] ?? 0,
    '61' => $totalesPorCodigo['61'] ?? 0,
    '62' => $totalesPorCodigo['62'] ?? 0,
    '71' => $totalesPorCodigo['71'] ?? 0,
    '72' => $totalesPorCodigo['72'] ?? 0,
    '81' => $totalesPorCodigo['81'] ?? 0,
    '93' => $totalesPorCodigo['93'] ?? 0
];

$totalNacional = array_sum($establecimientosPorSector);

$sectoresNacionales = [];

foreach ($establecimientosPorSector as $clave => $establecimientos) {

    $porcentaje = $totalNacional > 0
        ? round(($establecimientos / $totalNacional) * 100, 2)
        : 0;

    $sectoresNacionales[] = [
        'sector' => $clave,
        'nombre' => $nombresSectores[$clave] ?? 'Sector no identificado',
        'establecimientos' => $establecimientos,
        'porcentaje_nacional' => $porcentaje
    ];
}

usort($sectoresNacionales, function ($a, $b) {
    return $b['establecimientos'] <=> $a['establecimientos'];
});

ksort($entidadesEncontradas);

echo '<h3>Distribución nacional por sector</h3>';

echo '<p><strong>Entidades encontradas:</strong> '
    . count($entidadesEncontradas)
    . '</p>';

echo '<p><strong>Claves geográficas:</strong> '
    . htmlspecialchars(implode(', ', array_keys($entidadesEncontradas)))
    . '</p>';

echo '<pre>';
print_r($sectoresNacionales);
echo '</pre>';

echo '<p><strong>Total nacional calculado:</strong> '
    . number_format($totalNacional)
    . ' establecimientos</p>';

echo '<p><strong>Suma de porcentajes:</strong> '
    . number_format(
        array_sum(array_column($sectoresNacionales, 'porcentaje_nacional')),
        2
    )
    . '%</p>';