<?php
/** Sin BD: Word terminado se dirige a conversor independiente, nunca a REDMEX. */
require_once dirname(__DIR__) . '/app/services/OficioDocxPdfService.php';

function checkFinalWord($ok, $text) {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $text); }
}
$service = new OficioDocxPdfService();
$invalid = $service->generarPdf([
    'plantilla'=>'Oficio adjuntado: terminado.docx',
    'archivo_plantilla'=>'../../documento_externo.docx'
]);
checkFinalWord(empty($invalid['ok']), 'ruta ajena rechazada');
checkFinalWord(($invalid['mensaje_tecnico'] ?? '') === 'WORD_FINAL_SOURCE',
    'no consultar REDMEX para ruta inválida');

$dir = dirname(__DIR__) . '/storage/templates/oficios_personalizados';
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
$name = 'generacion_20261105_090000_' . bin2hex(random_bytes(6)) . '.docx';
$file = $dir . '/' . $name;
$zip = new ZipArchive();
checkFinalWord($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true,
    'crear Word de prueba');
$zip->addFromString('word/document.xml',
    '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    .'<w:body><w:p><w:r><w:t>Texto ya redactado de prueba</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
$before = hash_file('sha256', $file);
putenv('IMPE_OFICIO_FINAL_LIBREOFFICE=/ruta-que-no-existe/soffice');
try {
    $result = $service->generarPdf([
        'plantilla'=>'Oficio adjuntado: documento terminado',
        'archivo_plantilla'=>'storage/templates/oficios_personalizados/' . $name,
        'folio'=>'PRUEBA/0001'
    ]);
    checkFinalWord(empty($result['ok']), 'motor faltante no genera PDF falso');
    checkFinalWord(($result['mensaje_tecnico'] ?? '') === 'WORD_FINAL_ENGINE_MISSING',
        'Word terminado se separa de las validaciones REDMEX');
    checkFinalWord(hash_file('sha256',$file) === $before, 'Word original intacto');
    echo "PASS Word terminado: sin REDMEX, original intacto y motor requerido\n";
} finally {
    @unlink($file);
    putenv('IMPE_OFICIO_FINAL_LIBREOFFICE');
}
