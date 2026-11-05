<?php
/**
 * Prueba aislada de la salida de oficios elaborados, sin BD ni correo real.
 * No llama a SMTP ni modifica folios.
 */
require_once dirname(__DIR__) . '/app/services/OficioEnvioService.php';

function assertEnvioFinal($yes, $reason) {
    if (!$yes) { throw new RuntimeException('FAIL: ' . $reason); }
}

$reflection = new ReflectionClass(OficioEnvioService::class);
$service = $reflection->newInstanceWithoutConstructor();
$classify = new ReflectionMethod(OficioEnvioService::class, 'esOficioTerminadoAdjuntado');
$available = new ReflectionMethod(OficioEnvioService::class, 'pdfTerminadoDisponible');

foreach ([
    ['Oficio adjuntado: archivo.docx', true],
    ['Oficio adjuntado PDF: archivo.pdf', true],
    ['Formato institucional REDMEX', false],
    ['Plantilla personalizada', false],
    ['Oficio adjuntado', false],
] as $case) {
    assertEnvioFinal($classify->invoke($service, ['plantilla_oficio_nombre'=>$case[0]]) === $case[1],
        'clasificar: ' . $case[0]);
}

$directory = sys_get_temp_dir() . '/impe-correo-pdf-final-' . bin2hex(random_bytes(7));
assertEnvioFinal(mkdir($directory, 0700), 'crear sandbox privado');
$storage = $directory . '/storage/oficios/2026';
assertEnvioFinal(mkdir($directory.'/storage', 0700) &&
    mkdir($directory.'/storage/oficios', 0700) &&
    mkdir($storage, 0700), 'crear storage privado');
$path = $storage . '/REDMEX_0277_05-11_26_adjunto_a1b2c3d4e5f6.pdf';
$pdf = "%PDF-1.4\n" . str_repeat("A", 140) . "\n%%EOF\n";
file_put_contents($path, $pdf);

try {
    foreach (['rootPath'=>$directory, 'storagePath'=>$directory.'/storage/oficios'] as $field=>$value) {
        $property = new ReflectionProperty(OficioEnvioService::class, $field);
        $property->setValue($service, $value);
    }
    $record = ['archivo_pdf'=>'storage/oficios/2026/' . basename($path)];
    assertEnvioFinal($available->invoke($service, $record) === true,
        'PDF adjunto válido aunque no coincida con nombre de folio');
    assertEnvioFinal(hash_file('sha256', $path) === hash('sha256', $pdf),
        'PDF original intacto');
    assertEnvioFinal(!$available->invoke($service, ['archivo_pdf'=>'storage/oficios/2026/inexistente.pdf']),
        'rechazar archivo inexistente');
    assertEnvioFinal(!$available->invoke($service, ['archivo_pdf'=>'../../fuera.pdf']),
        'rechazar ruta fuera de storage');
    file_put_contents($path, "%PDF-1.4\n" . str_repeat("A", 140));
    assertEnvioFinal(!$available->invoke($service, $record), 'rechazar PDF sin EOF');

    $file = file_get_contents(dirname(__DIR__) . '/app/services/OficioEnvioService.php');
    $atGuard = strpos($file, 'if ($this->esOficioTerminadoAdjuntado($seguimiento))');
    $atSync = strpos($file, '$ajuste = $this->sincronizarFolioConFechaActual(');
    assertEnvioFinal($atGuard !== false && $atSync !== false && $atGuard < $atSync,
        'el oficio final debe evitar la regeneración antes de entrar a SMTP');
    assertEnvioFinal(strpos($file, 'plantilla_oficio.nombre AS plantilla_oficio_nombre') !== false,
        'identificar la modalidad real desde la BD');
    assertEnvioFinal(strpos($file, 'if ($cambioFolio || !$pdfCorresponde)') !== false,
        'el flujo institucional conserva la sincronización vigente');
    echo "PASS oficio final: PDF original, rutas seguras, sin regenerar y resto intacto\n";
} finally {
    @unlink($path);
    @rmdir($storage);
    @rmdir($directory.'/storage/oficios');
    @rmdir($directory.'/storage');
    @rmdir($directory);
}
