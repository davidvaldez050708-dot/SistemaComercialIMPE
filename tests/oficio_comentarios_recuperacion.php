<?php
// Pruebas dirigidas: ejecutar también con proc_open deshabilitado. Sin BD/red.
class OficioReaderPrueba
{
    public static $calls = 0;
    public static $mode = 'second';
    public static function load($file, $format) {
        self::$calls++;
        if (self::$mode === 'invalid-pdf') { return new stdClass(); }
        throw new InvalidArgumentException(self::$mode === 'other' ? 'PRIVADO: ruta y contenido no publicables' : "Comment with id 0 isn't referenced in document");
    }
    public static function createWriter($document, $type) {
        return new class { public function save($file) { file_put_contents($file, '<html>No es PDF</html>'); } };
    }
}
$mock = ($argv[1] ?? '') === '--mock';
if ($mock) { class_alias(OficioReaderPrueba::class, 'PhpOffice\\PhpWord\\IOFactory'); }
require_once __DIR__ . '/../app/services/OficioDocxPdfService.php';
require_once __DIR__ . '/../app/services/OficioDocxComentarios.php';

function check($ok, $label) { if (!$ok) { throw new RuntimeException('FALLO: ' . $label); } }
function makeDocx($path) {
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $r = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
    $zip = new ZipArchive();
    check($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) === true, 'crear fixture');
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$r.'officeDocument" Target="word/document.xml"/></Relationships>');
    $zip->addFromString('word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.$r.'comments" Target="anotaciones/notas.xml"/><Relationship Id="rId2" Type="'.$r.'header" Target="header1.xml"/><Relationship Id="rId3" Type="'.$r.'footer" Target="footer1.xml"/></Relationships>');
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="'.$w.'" xmlns:r="'.$r.'"><w:body><w:p><w:commentRangeStart w:id="0"/><w:r><w:t>CONTENIDO CONSERVADO ${numero_oficio}</w:t></w:r></w:p><w:p><w:r><w:t>Comentario puntual valido</w:t><w:commentReference w:id="1"/></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>TABLA CONSERVADA</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:sectPr><w:headerReference w:type="default" r:id="rId2"/><w:footerReference w:type="default" r:id="rId3"/><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="720" w:bottom="720" w:left="720" w:right="720"/></w:sectPr></w:body></w:document>');
    $zip->addFromString('word/anotaciones/notas.xml', '<w:comments xmlns:w="'.$w.'"><w:comment w:id="0" w:author="Prueba" w:date="2026-10-20T00:00:00Z"><w:p><w:r><w:t>Nota huerfana</w:t></w:r></w:p></w:comment><w:comment w:id="1" w:author="Prueba" w:date="2026-10-20T00:00:00Z"><w:p><w:r><w:t>Nota valida</w:t></w:r></w:p></w:comment></w:comments>');
    foreach (['header1' => 'ENCABEZADO CONSERVADO', 'footer1' => 'PIE CONSERVADO'] as $part => $text) {
        $tag = $part === 'header1' ? 'hdr' : 'ftr';
        $zip->addFromString('word/'.$part.'.xml', '<w:'.$tag.' xmlns:w="'.$w.'"><w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p></w:'.$tag.'>');
    }
    $zip->addFromString('word/styles.xml', '<w:styles xmlns:w="'.$w.'"/>');
    $zip->addFromString('word/media/prueba.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1cAAAAASUVORK5CYII='));
    $zip->close();
}
function removeOwned($dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}
$root = dirname(__DIR__);
$dir = $root.'/tmp/oficio-comentarios-test-'.bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
mkdir($dir.'/storage/templates/oficios_personalizados', 0700, true);
mkdir($dir.'/vendor/dompdf/dompdf', 0700, true);
$source = $dir.'/storage/templates/oficio_general_redmex.docx';
makeDocx($source);
$hash = hash_file('sha256', $source);
try {
    $fixed = OficioDocxComentarios::recuperar($source, $dir, '0');
    check(hash_file('sha256', $source) === $hash, 'origen intacto');
    $old = new ZipArchive(); $old->open($source);
    $new = new ZipArchive(); $new->open($fixed['ruta']);
    check($new->numFiles === $old->numFiles, 'ninguna parte eliminada');
    for ($i = 0; $i < $old->numFiles; $i++) {
        $name = $old->getNameIndex($i);
        if (!in_array($name, ['word/document.xml', 'word/anotaciones/notas.xml'], true)) {
            check($new->getFromName($name) === $old->getFromName($name), 'parte intacta '.$name);
        }
    }
    $commentXml = $new->getFromName('word/anotaciones/notas.xml');
    check(str_contains($commentXml, 'Nota valida') && !str_contains($commentXml, 'Nota huerfana'), 'comentario valido conservado');
    $body = $new->getFromName('word/document.xml');
    check(str_contains($body, 'TABLA CONSERVADA') && str_contains($body, 'CONTENIDO CONSERVADO') && str_contains($body, 'w:id="1"'), 'texto tabla y referencia valida');
    $old->close(); $new->close(); unlink($fixed['ruta']);
    try { OficioDocxComentarios::recuperar($source, $dir, '1'); throw new LogicException('no rechazo id consistente'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'COMMENTS_INCONSISTENCY_NOT_CONFIRMED', 'no sanear comentario valido'); }
    foreach (['prefix', 'path', 'doctype', 'malformed'] as $case) {
        $variant = $dir.'/'.$case.'.docx'; copy($source, $variant);
        $z = new ZipArchive(); $z->open($variant);
        if ($case === 'prefix') {
            $xml = $z->getFromName('word/anotaciones/notas.xml');
            $z->addFromString('word/anotaciones/notas.xml', str_replace(['xmlns:w=', '<w:', '</w:', ' w:'], ['xmlns:a=', '<a:', '</a:', ' a:'], $xml));
        } elseif ($case === 'path') {
            $z->addFromString('word/_rels/document.xml.rels', str_replace('anotaciones/notas.xml', '../../../fuera.xml', $z->getFromName('word/_rels/document.xml.rels')));
        } elseif ($case === 'doctype') {
            $z->addFromString('word/anotaciones/notas.xml', '<!DOCTYPE comments SYSTEM "file:///inexistente"><comments/>');
        } else {
            $z->addFromString('word/anotaciones/notas.xml', '<xml-incompleto');
        }
        $z->close(); $variantHash = hash_file('sha256', $variant);
        if ($case === 'prefix') {
            $recovered = OficioDocxComentarios::recuperar($variant, $dir, '0'); unlink($recovered['ruta']);
        } else {
            try { OficioDocxComentarios::recuperar($variant, $dir, '0'); throw new LogicException('XML inseguro aceptado'); }
            catch (RuntimeException $e) { check(str_starts_with($e->getMessage(), 'COMMENTS_'), 'rechazo seguro '.$case); }
        }
        check(hash_file('sha256', $variant) === $variantHash && !glob($dir.'/comentarios_*'), 'variante intacta '.$case);
    }
    $service = new OficioDocxPdfService();
    (new ReflectionProperty($service, 'rootPath'))->setValue($service, $dir);
    (new ReflectionProperty($service, 'templatePath'))->setValue($service, $source);
    $method = new ReflectionMethod($service, 'convertirConPhpWordDompdf');
    if ($mock) {
        foreach (['second' => 2, 'other' => 1, 'invalid-pdf' => 1] as $mode => $expectedCalls) {
            OficioReaderPrueba::$mode = $mode; OficioReaderPrueba::$calls = 0;
            $r = $method->invoke($service, $source, $dir, true);
            check(!$r['ok'] && OficioReaderPrueba::$calls === $expectedCalls, 'un solo reintento '.$mode);
            check(!str_contains(json_encode($r), 'PRIVADO'), 'detalle sensible oculto');
            check(!glob($dir.'/comentarios_*') && !glob($dir.'/*.pdf'), 'temporales limpiados');
        }
        OficioReaderPrueba::$mode = 'second'; OficioReaderPrueba::$calls = 0;
        $r = $method->invoke($service, $source, $dir, false);
        check(!$r['ok'] && OficioReaderPrueba::$calls === 1, 'consumidores compartidos sin recuperacion');
    } else {
        $view = ['folio' => 'PRUEBA/0000/20-10/26'];
        $r = $service->generarPdf($view);
        check($r['ok'] ?? false, 'flujo general real '.json_encode($r));
        $valid = new ReflectionMethod($service, 'contenidoPdfValido');
        check($valid->invoke($service, $r['contenido_pdf']), 'pdf valido');
        $catalog = $dir.'/storage/templates/oficios_personalizados/oficio_prueba.docx';
        copy($source, $catalog); $catalogHash = hash_file('sha256', $catalog);
        $view['archivo_plantilla'] = 'storage/templates/oficios_personalizados/oficio_prueba.docx';
        check($service->generarPdf($view)['ok'] ?? false, 'catalogo personalizado real');
        $prepared = $service->generarDocx($view);
        check($prepared['ok'] ?? false, 'preparacion procesada');
        $processedName = 'generacion_20261020_000000_'.bin2hex(random_bytes(6)).'.docx';
        $processed = dirname($catalog).'/'.$processedName;
        file_put_contents($processed, $prepared['contenido_docx']); $processedHash = hash_file('sha256', $processed);
        $view['archivo_plantilla'] = 'storage/templates/oficios_personalizados/'.$processedName;
        check($service->generarPdf($view)['ok'] ?? false, 'documento procesado real sin nuevos marcadores');
        check(hash_file('sha256', $processed) === $processedHash && hash_file('sha256', $catalog) === $catalogHash && hash_file('sha256', $source) === $hash, 'todos los originales intactos');
        check($view['folio'] === 'PRUEBA/0000/20-10/26', 'folio conservado');
        check(!glob($dir.'/storage/tmp/oficios/*'), 'generacion sin temporales sobrantes');
        check(!$valid->invoke($service, '%PDF-1.7 basura %%EOF') && !$valid->invoke($service, '<html>error</html>'), 'rechazo de PDF falso');
    }
    echo 'OK: '.($mock ? 'reintento unico, otros errores, PDF falso, limpieza y aislamiento compartido.' : 'general, catalogo, procesada, folio, partes XML y originales conservados.').' Sin BD/red.',PHP_EOL;
} finally {
    // Objetivo absoluto generado dentro del workspace; nunca borrar una ruta recibida del usuario.
    check(str_starts_with(realpath($dir), realpath($root.'/tmp').DIRECTORY_SEPARATOR.'oficio-comentarios-test-'), 'carpeta exclusiva de pruebas');
    removeOwned($dir);
}
