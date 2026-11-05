<?php
/**
 * Diagnóstico SOLO LECTURA de anclas flotantes del último oficio DOCX terminado.
 * Imprime metadatos de ubicación, nunca el texto, imágenes ni rutas privadas.
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);
$site = getenv('IMPE_SITE') ?: '';
if ($site === '' || !is_file($site . '/config/db_connection.php')) {
    fwrite(STDERR, "ERROR: CRM no localizado\n");
    exit(1);
}
require_once $site . '/config/db_connection.php';
$db = (new Database())->connect(false);
$stmt = $db->prepare(
    "SELECT p.archivo_docx FROM oficios_vinculacion o
     INNER JOIN plantillas_vinculacion p ON p.id = o.plantilla_oficio_id
     WHERE p.nombre LIKE 'Oficio adjuntado:%'
       AND p.archivo_docx LIKE 'storage/templates/oficios_personalizados/generacion_%'
     ORDER BY o.id DESC LIMIT 1"
);
if (!$stmt || !$stmt->execute()) {
    fwrite(STDERR, "ERROR: no se pudo localizar el oficio adjuntado\n");
    exit(1);
}
$row = $stmt->get_result()->fetch_assoc();
$relative = (string)($row['archivo_docx'] ?? '');
if (!preg_match('~^storage/templates/oficios_personalizados/generacion_[0-9]{8}_[0-9]{6}_[a-f0-9]{12}\.docx$~D', $relative)) {
    fwrite(STDERR, "ERROR: no se localizó una instantánea de Word terminado válida\n");
    exit(1);
}
$folder = realpath($site . '/storage/templates/oficios_personalizados');
$file = realpath($site . '/' . $relative);
if (!$folder || !$file || strpos($file, $folder . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($file) || is_link($file)) {
    fwrite(STDERR, "ERROR: ruta no autorizada\n");
    exit(1);
}
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::RDONLY) !== true) {
    fwrite(STDERR, "ERROR: archivo DOCX no legible\n");
    exit(1);
}
$parts = ['word/document.xml'];
for ($index = 0; $index < $zip->numFiles && $index < 1000; $index++) {
    $part = $zip->getNameIndex($index);
    if (preg_match('~^word/(?:header|footer)[0-9]+\.xml$~D', $part)) {
        $parts[] = $part;
    }
}
$namespace = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
$count = 0;
$flagged = 0;
echo "=== ANCLAS DEL ÚLTIMO OFICIO WORD TERMINADO ===\n";
foreach ($parts as $part) {
    $info = $zip->statName($part);
    if (!$info || $info['size'] > 8 * 1024 * 1024) {
        echo "AVISO: XML omitido por tamaño\n";
        continue;
    }
    $xml = $zip->getFromName($part);
    if (!is_string($xml) || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
        echo "AVISO: XML inválido o no permitido\n";
        continue;
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $ok = $document->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$ok) { echo "AVISO: XML no procesable\n"; continue; }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('wp', $namespace);
    $anchors = $xpath->query('//wp:anchor');
    if (!$anchors) { continue; }
    foreach ($anchors as $anchor) {
        ++$count;
        $positions = [];
        $errors = [];
        foreach (['H','V'] as $axis) {
            $node = $xpath->query('./wp:position' . $axis, $anchor)->item(0);
            $offsetNode = $node ? $xpath->query('./wp:posOffset', $node)->item(0) : null;
            $alignNode = $node ? $xpath->query('./wp:align', $node)->item(0) : null;
            $reference = $node ? $node->getAttribute('relativeFrom') : '';
            $positions[$axis] = [
                'relativeFrom' => $reference,
                'posOffset' => $offsetNode ? (string)$offsetNode->textContent : null,
                'align' => $alignNode ? (string)$alignNode->textContent : null,
            ];
            if (!$offsetNode) { $errors[] = $axis . ': sin posOffset'; }
            if ($axis === 'H' && !in_array($reference, ['column','margin','page'], true)) {
                $errors[] = 'H: referencia no admitida';
            }
            if ($axis === 'V' && !in_array($reference, ['paragraph','page'], true)) {
                $errors[] = 'V: referencia no admitida';
            }
            if ($axis === 'V' && $part === 'word/document.xml' && $reference !== 'paragraph') {
                $errors[] = 'V: imagen del cuerpo no relativa al párrafo';
            }
        }
        if ($errors) { ++$flagged; }
        echo json_encode([
            'parte' => $part === 'word/document.xml' ? 'cuerpo' : (
                strpos($part, '/header') !== false ? 'encabezado' : 'pie'
            ),
            'ancla' => $count,
            'horizontal' => $positions['H'],
            'vertical' => $positions['V'],
            'problemas_potenciales' => $errors,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
}
$zip->close();
echo "TOTAL ANCLAS: " . $count . ", CON POSIBLES INCOMPATIBILIDADES: " . $flagged . "\n";
echo "SOLO LECTURA: sin cambios en DOCX, CRM, SQL ni PDF.\n";
