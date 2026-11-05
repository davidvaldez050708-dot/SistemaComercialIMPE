<?php
/** PDF final: se conserva exactamente; sin REDMEX, LibreOffice o sustitución. */
require_once dirname(__DIR__) . '/app/controllers/OficioVinculacionController.php';

function assertFinal($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . "\n"); exit(1); }
}
function fixturePdf() {
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R >>',
        "<< /Length 0 >>\nstream\n\nendstream"
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $content) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i+1) . " 0 obj\n" . $content . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 5\n0000000000 65535 f \n";
    for ($i = 1; $i <= 4; ++$i) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    return $pdf;
}
$controller = new OficioVinculacionController();
$validate = new ReflectionMethod(OficioVinculacionController::class, 'pdfFinalValido');
$pdf = fixturePdf();
assertFinal($validate->invoke($controller, $pdf) === true, 'aceptar PDF simple');
assertFinal($validate->invoke($controller, $pdf . '/OpenAction') === false, 'rechazar acciones después del EOF');
assertFinal($validate->invoke($controller, "UNVALIDO\n" . $pdf) === false, 'rechazar cabecera falsa');
assertFinal($validate->invoke($controller, '%PDF-1.4' . str_repeat('A',200)) === false, 'rechazar PDF sin EOF');
$root = dirname(__DIR__);
$directory = $root . '/storage/templates/oficios_personalizados';
if (!is_dir($directory)) { mkdir($directory, 0775, true); }
$name = 'generacion_20261105_101500_' . bin2hex(random_bytes(6)) . '.pdf';
$file = $directory . '/' . $name;
file_put_contents($file, $pdf);
try {
    $service = new OficioDocxPdfService();
    $vista = [
        'plantilla' => 'Oficio adjuntado PDF: terminado',
        'archivo_plantilla' => 'storage/templates/oficios_personalizados/' . $name,
        'folio' => 'REDMEX/1234/05-11/26'
    ];
    $result = $service->generarPdf($vista);
    assertFinal(!empty($result['ok']), 'servir PDF existente sin conversión');
    assertFinal(($result['contenido_pdf'] ?? '') === $pdf, 'bytes del PDF idénticos');
    assertFinal(($result['conversor'] ?? '') === 'PDF_ORIGINAL', 'sin renderizador REDMEX');
    $doc = $service->generarDocx($vista);
    assertFinal(empty($doc['ok']), 'no simular DOCX para PDF terminado');
    assertFinal(hash_file('sha256',$file) === hash('sha256',$pdf), 'PDF almacenado intacto');
} finally {
    @unlink($file);
}
echo "PASS PDF terminado: validación, lectura idéntica, sin REDMEX y original intacto\n";
