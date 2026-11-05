<?php
/**
 * Prueba aislada: oficio Word terminado, sin DB, sin SMTP ni convertir PDF.
 * Comprueba que el adjunto no se rellena ni reescribe.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/controllers/OficioVinculacionController.php';

function exigir($condicion, $mensaje) {
    if (!$condicion) {
        fwrite(STDERR, 'FALLO: ' . $mensaje . PHP_EOL);
        exit(1);
    }
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "FALLO: ZipArchive no está disponible en el entorno de pruebas.\n");
    exit(1);
}
class OficioModeloAislado extends OficioVinculacionModel {
    public $ultimaRuta = '';
    public $ultimoNombre = '';
    public function __construct() {}
    public function registrarPlantillaOficioDocx($nombre, $ruta, $usuarioId) {
        $this->ultimoNombre = (string)$nombre;
        $this->ultimaRuta = (string)$ruta;
        return 7001;
    }
}
$temporal = tempnam(sys_get_temp_dir(), 'impe-oficio-');
$zip = new ZipArchive();
exigir($zip->open($temporal, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'No se pudo crear DOCX aislado');
$zip->addFromString('[Content_Types].xml',
    '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
$zip->addFromString('word/document.xml',
    '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>OFICIO YA ELABORADO POR LA INSTITUCION</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
$bytesOriginales = file_get_contents($temporal);
$modelo = new OficioModeloAislado();
$controller = new OficioVinculacionController();
$metodo = new ReflectionMethod(OficioVinculacionController::class, 'guardarPlantillaParaGeneracion');
$metodo->setAccessible(true);
$datos = ['tmp_name'=>$temporal,'name'=>'oficio terminado.docx','error'=>UPLOAD_ERR_OK];
try {
    $final = $metodo->invoke($controller, $datos, 11, $modelo, null, true, true);
    exigir(!empty($final['ok']), 'El Word terminado válido fue rechazado: ' . ($final['mensaje'] ?? ''));
    exigir(strpos($modelo->ultimoNombre, 'Oficio adjuntado:') === 0, 'No distingue documento final de plantilla');
    exigir((int)$final['plantilla_id'] === 7001, 'No registra el documento para el folio');
    $guardado = (string)$final['ruta_absoluta'];
    exigir(file_get_contents($guardado) === $bytesOriginales, 'Modificó el Word redactado');
    exigir(preg_match('~^storage/templates/oficios_personalizados/generacion_[0-9]{8}_[0-9]{6}_[a-f0-9]{12}\.docx$~D',
        $modelo->ultimaRuta)===1, 'No reutiliza la ruta segura del generador PDF');
    $convertidor = new OficioDocxPdfService();
    $descarga = $convertidor->generarDocx([
        'archivo_plantilla'=>$modelo->ultimaRuta,
        'folio'=>'IMPE-PRUEBA-1'
    ]);
    exigir(!empty($descarga['ok']) && $descarga['contenido_docx'] === $bytesOriginales,
        'La descarga volvió a procesar o alteró el Word terminado');
    $invalido = $metodo->invoke($controller,
        ['tmp_name'=>$temporal,'name'=>'oficio.doc','error'=>UPLOAD_ERR_OK],
        11,$modelo,null,true,true);
    exigir(empty($invalido['ok']), 'Aceptó un archivo sin extensión DOCX');
    echo "PASS Word terminado: validación ZIP, límite de formato, folio y bytes conservados\n";
} finally {
    if (isset($guardado) && is_file($guardado)) @unlink($guardado);
    if (is_file($temporal)) @unlink($temporal);
}
