<?php
/** Diagnóstico de etiquetas XML (no muestra texto, rutas, nombres ni datos del DOCX). */
ini_set('display_errors', '0');
error_reporting(E_ALL);
$site = getenv('IMPE_SITE');
if (!$site || !is_file($site . '/config/db_connection.php')) {
    fwrite(STDERR, "ERROR: ruta del CRM no encontrada\n"); exit(1);
}
require $site . '/config/db_connection.php';
$db = (new Database())->connect(false);
$stmt = $db->prepare("SELECT p.archivo_docx FROM oficios_vinculacion o
 INNER JOIN plantillas_vinculacion p ON p.id = o.plantilla_oficio_id
 WHERE p.nombre LIKE 'Oficio adjuntado:%'
   AND p.archivo_docx LIKE 'storage/templates/oficios_personalizados/generacion_%'
 ORDER BY o.id DESC LIMIT 1");
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) { echo "No se encontró un oficio Word final adjuntado.\n"; exit(2); }
$rel = (string)$row['archivo_docx'];
if (!preg_match('~^storage/templates/oficios_personalizados/generacion_[0-9]{8}_[0-9]{6}_[a-f0-9]{12}\.docx$~D', $rel)) {
    fwrite(STDERR, "ERROR: formato de ruta no reconocido\n"); exit(1);
}
$folder = realpath($site . '/storage/templates/oficios_personalizados');
$file = realpath($site . '/' . $rel);
if (!$folder || !$file || strpos($file, $folder . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    fwrite(STDERR, "ERROR: DOCX no encontrado dentro del almacén autorizado\n"); exit(1);
}
$zip = new ZipArchive();
if ($zip->open($file, ZipArchive::RDONLY) !== true) { fwrite(STDERR,"ERROR: DOCX ilegible\n"); exit(1); }
$namespaces = [
    'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
    'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
    'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
    'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
    'wpg' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingGroup',
];
$rules = [
    '//w:sectPr' => 'w:pgSz w:pgMar w:pgNumType w:cols w:headerReference w:footerReference',
    '//w:body//w:p' => 'w:pPr w:r w:bookmarkStart w:bookmarkEnd w:proofErr w:commentRangeStart w:commentRangeEnd',
    '//w:body//w:p/w:pPr | //w:body//w:tc/w:pPr' => 'w:pBdr w:rPr w:spacing w:ind w:jc w:widowControl w:keepNext w:keepLines w:pageBreakBefore w:pStyle w:shd',
    '//w:body//w:r' => 'w:rPr w:t w:br w:cr w:tab w:drawing w:commentReference w:lastRenderedPageBreak',
    '//w:body//w:rPr' => 'w:rFonts w:b w:bCs w:i w:iCs w:sz w:szCs w:color w:noProof w:lang w:u w:rtl w:highlight w:shd w:smallCaps w:strike w:vertAlign w:rStyle',
    '//w:body//w:tbl' => 'w:tblPr w:tblGrid w:tr',
    '//w:body//w:tblPr' => 'w:tblW w:tblLayout w:tblCellMar',
    '//w:body//w:tr' => 'w:trPr w:tc',
    '//w:body//w:tc' => 'w:tcPr w:p',
    '//w:body//w:tcPr' => 'w:tcW',
    '//pic:spPr | //wps:spPr' => 'a:xfrm a:prstGeom a:noFill a:ln',
    '//pic:blipFill' => 'a:blip a:srcRect a:stretch',
    '//wpg:grpSpPr' => 'a:xfrm',
    '//a:ln' => 'a:noFill',
    '//a:blip' => 'a:alphaModFix',
];
$names = ['word/document.xml', 'word/styles.xml'];
for ($i=0; $i < $zip->numFiles && $i < 1000; ++$i) {
    $name = $zip->getNameIndex($i);
    if (preg_match('~^word/(?:header|footer)[0-9]+\.xml$~D', $name)) { $names[] = $name; }
}
$findings = [];
foreach ($names as $name) {
    $index = $zip->locateName($name);
    if ($index === false) { continue; }
    $stat = $zip->statIndex($index);
    if (!$stat || $stat['size'] > 8*1024*1024) { echo "AVISO: parte XML demasiado grande para inspeccionar\n"; continue; }
    $xml = $zip->getFromIndex($index);
    if (!is_string($xml) || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) { echo "AVISO: XML incompatible\n"; continue; }
    $dom = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($old);
    if (!$ok) { echo "AVISO: XML inválido\n"; continue; }
    $xp = new DOMXPath($dom);
    foreach ($namespaces as $prefix => $uri) { $xp->registerNamespace($prefix,$uri); }
    $active = $name === 'word/styles.xml' ? [
        '//w:docDefaults/w:pPrDefault/w:pPr' => 'w:widowControl',
        '//w:docDefaults/w:rPrDefault/w:rPr' => $rules['//w:body//w:rPr'],
    ] : $rules;
    foreach ($active as $query => $list) {
        $allowed = array_fill_keys(explode(' ', $list), true);
        $nodes = $xp->query($query);
        if ($nodes === false) { continue; }
        foreach ($nodes as $node) {
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement || isset($allowed[$child->nodeName])) { continue; }
                $key = $name . ' | ' . $node->nodeName . ' > ' . $child->nodeName;
                $findings[$key] = ($findings[$key] ?? 0) + 1;
            }
        }
    }
}
$zip->close();
arsort($findings);
echo "=== ETIQUETAS NO ADMITIDAS EN EL ÚLTIMO WORD TERMINADO ===\n";
if (!$findings) { echo "No detectadas por el análisis estático; revisar gráficos y tipos de documento.\n"; }
foreach (array_slice($findings,0,35,true) as $name => $count) { echo $name . ' | apariciones=' . $count . "\n"; }
echo "Total de tipos distintos: " . count($findings) . "\n";
echo "SOLO LECTURA: sin contenido del documento, sin modificaciones.\n";
