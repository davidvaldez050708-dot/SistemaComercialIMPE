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
// Medidas de sección: solamente atributos numéricos, sin contenido del oficio.
$docXml = $zip->getFromName('word/document.xml');
if (!is_string($docXml) || strlen($docXml) > 8 * 1024 * 1024 ||
    preg_match('/<!DOCTYPE|<!ENTITY/i', $docXml)) {
    fwrite(STDERR, "ERROR: sección XML no segura\n"); exit(1);
}
$sectionDoc = new DOMDocument();
$prior = libxml_use_internal_errors(true);
$loaded = $sectionDoc->loadXML($docXml, LIBXML_NONET);
libxml_clear_errors(); libxml_use_internal_errors($prior);
if (!$loaded) { fwrite(STDERR, "ERROR: sección XML inválida\n"); exit(1); }
$sectionXp = new DOMXPath($sectionDoc);
$sectionXp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
$section = $sectionXp->query('//w:sectPr')->item(0);
if (!$section) { fwrite(STDERR, "ERROR: sección no encontrada\n"); exit(1); }
$sizeNode = $sectionXp->query('./w:pgSz', $section)->item(0);
$marginNode = $sectionXp->query('./w:pgMar', $section)->item(0);
$wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
$pageWidth = $sizeNode ? (float)$sizeNode->getAttributeNS($wNs,'w')/20 : 612.0;
$pageHeight = $sizeNode ? (float)$sizeNode->getAttributeNS($wNs,'h')/20 : 792.0;
$margins = [];
foreach (['top','bottom','left','right','header','footer'] as $side) {
    $margins[$side] = $marginNode ? (float)$marginNode->getAttributeNS($wNs,$side)/20 : 0.0;
}
echo json_encode(['pagina_pt'=>['ancho'=>$pageWidth,'alto'=>$pageHeight],
    'margenes_pt'=>$margins], JSON_UNESCAPED_UNICODE) . "\n";
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
            $validAlign = $axis === 'H' && $reference === 'page' && $alignNode &&
                in_array(trim((string)$alignNode->textContent), ['left', 'right'], true);
            if (!$offsetNode && !$validAlign) { $errors[] = $axis . ': sin posOffset ni alineación admitida'; }
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
        // Reproduce la geometría inicial del renderer, antes de acumular
        // interlineado de párrafos previos del encabezado o pie.
        $extent = $xpath->query('./wp:extent', $anchor)->item(0);
        $width = $extent ? (float)$extent->getAttribute('cx')/12700 : 0.0;
        $height = $extent ? (float)$extent->getAttribute('cy')/12700 : 0.0;
        $x = null;
        if ($positions['H']['relativeFrom'] === 'page') {
            if ($positions['H']['align'] === 'left') { $x = 0.0; }
            elseif ($positions['H']['align'] === 'right') { $x = $pageWidth - $width; }
            elseif ($positions['H']['posOffset'] !== null) {
                $x = (float)$positions['H']['posOffset']/12700;
            }
        }
        $kind = strpos($part, '/header') !== false ? 'header' :
            (strpos($part, '/footer') !== false ? 'footer' : 'body');
        $origin = $kind === 'header' ? $margins['header'] :
            ($kind === 'footer' ? $pageHeight - $margins['footer'] : 0.0);
        $initialY = $positions['V']['posOffset'] === null ? null :
            (float)$positions['V']['posOffset']/12700 +
            ($positions['V']['relativeFrom'] === 'page' ? 0.0 : $origin);
        // Este cálculo no añade el avance vertical de párrafos anteriores.
        // Un resultado fuera de página identifica una causa; uno válido
        // no descarta el avance de párrafos dentro de fixedPart().
        $bounds = ['x'=>$x, 'y_sin_avance_parrafos'=>$initialY,
            'ancho'=>$width, 'alto'=>$height,
            'y_inferior_sin_avance'=>($initialY === null ? null : $initialY+$height)];
        if ($x !== null && $x <= -1500) { $errors[] = 'X fuera de límite'; }
        if ($initialY !== null && ($initialY < 0 || $initialY > $pageHeight)) {
            $errors[] = 'Y fuera de límite antes del avance de párrafos';
        }
        if ($errors) { ++$flagged; }
        echo json_encode([
            'parte' => $part === 'word/document.xml' ? 'cuerpo' : (
                strpos($part, '/header') !== false ? 'encabezado' : 'pie'
            ),
            'ancla' => $count,
            'horizontal' => $positions['H'],
            'vertical' => $positions['V'],
            'rectangulo_pt' => $bounds,
            'problemas_potenciales' => $errors,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
}
$zip->close();
echo "TOTAL ANCLAS: " . $count . ", CON POSIBLES INCOMPATIBILIDADES: " . $flagged . "\n";
echo "SOLO LECTURA: sin cambios en DOCX, CRM, SQL ni PDF.\n";
