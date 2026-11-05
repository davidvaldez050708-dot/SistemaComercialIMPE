<?php
/** Prueba sin base de datos: fecha de emisión local y reemplazo del encabezado DOCX general. */
require_once dirname(__DIR__) . '/app/services/OficioPreviewService.php';
require_once dirname(__DIR__) . '/app/services/OficioDocxPdfService.php';

function comprobarOficio(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        fwrite(STDERR, 'FAIL: ' . $mensaje . PHP_EOL);
        exit(1);
    }
}

$preview = (new ReflectionClass(OficioPreviewService::class))->newInstanceWithoutConstructor();
$fecha = new ReflectionMethod(OficioPreviewService::class, 'formatearFechaOficio');
comprobarOficio(
    $fecha->invoke($preview, '2026-11-05 04:59:53') === '4 de noviembre de 2026',
    '2026-11-05 04:59 UTC debe ser el 4 de noviembre en Ciudad de México'
);
comprobarOficio(
    $fecha->invoke($preview, '2026-11-05 06:00:00') === '5 de noviembre de 2026',
    '2026-11-05 06:00 UTC debe ser el 5 de noviembre en Ciudad de México'
);

$docx = (new ReflectionClass(OficioDocxPdfService::class))->newInstanceWithoutConstructor();
$reemplazos = (new ReflectionMethod(OficioDocxPdfService::class, 'construirReemplazos'))->invoke($docx, [
    'folio' => 'REDMEX/0276/04-11/26',
    'fecha' => '4 de noviembre de 2026',
    'lugar_fecha' => 'Ciudad de México, a 4 de noviembre de 2026',
    'institucion' => 'AFIPSA',
    'estado' => 'Chiapas',
    'municipio' => 'Tuxtla Gutiérrez',
    'ubicacion' => 'Tuxtla Gutiérrez / Chiapas',
]);
comprobarOficio(
    ($reemplazos['Cuernavaca, Morelos, a {{FECHA}}'] ?? '') === 'Ciudad de México, a 4 de noviembre de 2026',
    'el encabezado general debe usar el valor lugar_fecha completo'
);
comprobarOficio(
    ($reemplazos['{{estado}}'] ?? '') === 'CHIAPAS',
    'el estado del destinatario se conserva'
);

$xml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body><w:p><w:r><w:t>Cuernavaca, Morelos, a </w:t></w:r>'
    . '<w:r><w:t>{{FECHA}}</w:t></w:r></w:p>'
    . '<w:p><w:r><w:t>CHIAPAS</w:t></w:r></w:p></w:body></w:document>';
$salida = (new ReflectionMethod(OficioDocxPdfService::class, 'reemplazarTokensXml'))
    ->invoke($docx, $xml, $reemplazos);
comprobarOficio(is_string($salida), 'el XML del DOCX debe procesarse');
comprobarOficio(
    strpos($salida, 'Ciudad de México, a 4 de noviembre de 2026') !== false,
    'la sustitución debe funcionar incluso con el marcador dividido en varios nodos'
);
comprobarOficio(strpos($salida, 'Cuernavaca') === false, 'no debe conservar el lugar anterior');
comprobarOficio(strpos($salida, 'CHIAPAS') !== false, 'debe conservar la ubicación del destinatario');
echo "PASS oficio general: CDMX, fecha UTC→México y datos de destinatario intactos\n";
