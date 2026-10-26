<?php
// Sólo CLI: imagen ficticia en el temporal del sistema; sin BD ni archivos del CRM.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath($argv[1] ?? dirname(__DIR__));
$temporal = false;
$salida = 0;
try {
    if (!$root || !is_file($root . '/app/services/EcardReunionService.php')) {
        throw new RuntimeException('No se encontró el generador de Ecard.');
    }
    require_once $root . '/app/services/EcardReunionService.php';
    echo 'VERSION_ECARD=', EcardReunionService::VERSION, PHP_EOL;
    if (EcardReunionService::VERSION !== '20261026-tipografia-1') {
        throw new RuntimeException('Sigue instalado el generador anterior. Aplica la actualización de Ecard.');
    }
    foreach (['imagettftext','imagettfbbox','imagecreatefromjpeg','imagejpeg'] as $funcion) {
        if (!function_exists($funcion)) {
            throw new RuntimeException('GD/FreeType incompleto para generar la Ecard.');
        }
    }
    $ref = new ReflectionClass(EcardReunionService::class);
    // El render de la plantilla aprobada no necesita abrir una conexión de BD.
    $service = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('rootPath')->setValue($service, $root);
    $fuentes = $ref->getMethod('resolverFuente');
    foreach ([false, true] as $bold) {
        $fuente = $fuentes->invoke($service, $bold);
        if ($fuente === '' || !is_file($fuente)) {
            throw new RuntimeException('No se encontró una fuente TrueType válida.');
        }
        echo $bold ? 'FUENTE_NEGRITA=' : 'FUENTE_NORMAL=', basename($fuente), PHP_EOL;
    }
    $temporal = tempnam(sys_get_temp_dir(), 'impe-ecard-tipo-');
    if ($temporal === false) {
        throw new RuntimeException('No se pudo preparar la prueba temporal.');
    }
    $resultado = $ref->getMethod('crearImagenManuelPlantilla')->invoke(
        $service, $temporal, new DateTime('2026-10-26 12:37:00'),
        'PRUEBA 2', 'En línea', 'Virtual', ''
    );
    if (!($resultado['ok'] ?? false)) {
        throw new RuntimeException('No se pudo renderizar la plantilla aprobada.');
    }
    $imagen = imagecreatefromjpeg($temporal);
    if (!$imagen || imagesx($imagen) !== 1200 || imagesy($imagen) !== 1212) {
        throw new RuntimeException('La imagen de prueba tiene una resolución incorrecta.');
    }
    // Medir los píxeles de tinta real en fecha/hora, no sólo el tamaño configurado.
    foreach (['FECHA' => [700, 780, 25], 'HORA' => [790, 850, 20]] as $campo => [$inicio,$fin,$minimo]) {
        $superior = $fin; $inferior = $inicio;
        for ($y = $inicio; $y <= $fin; $y++) {
            for ($x = 120; $x < 1080; $x++) {
                $color = imagecolorat($imagen, $x, $y);
                if ((($color >> 16) & 255) < 35 && (($color >> 8) & 255) < 95 && ($color & 255) < 135) {
                    $superior = min($superior, $y); $inferior = max($inferior, $y);
                }
            }
        }
        $alto = max(0, $inferior - $superior + 1);
        echo 'ALTURA_', $campo, '_PX=', $alto, PHP_EOL;
        if ($alto < $minimo) {
            throw new RuntimeException('La prueba sigue mostrando letra demasiado pequeña.');
        }
    }
    imagedestroy($imagen);
    echo "OK: Ecard 1200x1212 con tipografía legible. Prueba ficticia, sin BD ni envíos. Temporal retirado.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    $salida = 1;
} finally {
    if ($temporal !== false && is_file($temporal)) {
        unlink($temporal);
    }
}
exit($salida);
