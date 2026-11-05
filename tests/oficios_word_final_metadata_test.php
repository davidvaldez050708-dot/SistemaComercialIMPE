<?php
/** Regresión de metadatos Word permitidos en oficios ya redactados, sin BD ni SQL. */
require_once dirname(__DIR__) . '/app/services/OficioRedmexPdfRenderer.php';

function verifyWordFinal($ok, $label) {
    if (!$ok) { throw new RuntimeException('FAIL ' . $label); }
}

function mutateWordFinal($source, $target, $kind) {
    verifyWordFinal(copy($source, $target), 'copy original fixture');
    $zip = new ZipArchive();
    verifyWordFinal($zip->open($target) === true, 'open target DOCX');
    try {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
        $office = 'http://schemas.microsoft.com/office/drawing/2010/main';

        $doc = new DOMDocument();
        verifyWordFinal($doc->loadXML($zip->getFromName('word/document.xml'), LIBXML_NONET), 'main XML');
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('w', $w);
        $xp->registerNamespace('a', $a);
        $section = $xp->query('//w:sectPr')->item(0);
        verifyWordFinal($section instanceof DOMElement, 'sectPr present');
        $grid = $doc->createElementNS($w, 'w:docGrid');
        $grid->setAttributeNS($w, 'w:linePitch', '360');
        if ($kind === 'grid-incompatible') { $grid->setAttributeNS($w, 'w:type', 'lines'); }
        $section->appendChild($grid);

        $blips = $xp->query('//a:blip');
        verifyWordFinal($blips->length > 0, 'image blip present');
        $count = min(2, $blips->length);
        for ($i = 0; $i < $count; ++$i) {
            $blip = $blips->item($i);
            $extLst = $doc->createElementNS($a, 'a:extLst');
            $ext = $doc->createElementNS($a, 'a:ext');
            $ext->setAttribute('uri', '{28A0092B-C50C-407E-A947-70E740481C1C}');
            $meta = $doc->createElementNS($office, 'a14:useLocalDpi');
            $meta->setAttribute('val', '0');
            if ($kind === 'extension-incompatible') {
                $meta = $doc->createElementNS($office, 'a14:unknownElement');
            }
            $ext->appendChild($meta);
            $extLst->appendChild($ext);
            $blip->appendChild($extLst);
        }
        verifyWordFinal($zip->addFromString('word/document.xml', $doc->saveXML()), 'save main XML');

        $styles = new DOMDocument();
        verifyWordFinal($styles->loadXML($zip->getFromName('word/styles.xml'), LIBXML_NONET), 'styles XML');
        $sx = new DOMXPath($styles); $sx->registerNamespace('w', $w);
        $default = $sx->query('//w:docDefaults/w:pPrDefault/w:pPr')->item(0);
        if (!$default) {
            $parent = $sx->query('//w:docDefaults')->item(0);
            verifyWordFinal($parent instanceof DOMElement, 'docDefaults present');
            $wrap = $styles->createElementNS($w, 'w:pPrDefault');
            $parent->appendChild($wrap);
            $default = $styles->createElementNS($w, 'w:pPr');
            $wrap->appendChild($default);
        }
        $spacing = $styles->createElementNS($w, 'w:spacing');
        $spacing->setAttributeNS($w, 'w:before', '0');
        $spacing->setAttributeNS($w, 'w:after', '0');
        $spacing->setAttributeNS($w, 'w:line', '240');
        $spacing->setAttributeNS($w, 'w:lineRule', 'auto');
        if ($kind === 'spacing-incompatible') { $spacing->setAttributeNS($w, 'w:afterAutospacing', '1'); }
        $default->appendChild($spacing);
        verifyWordFinal($zip->addFromString('word/styles.xml', $styles->saveXML()), 'save styles XML');
    } finally {
        $zip->close();
    }
}

$source = dirname(__DIR__) . '/storage/templates/oficio_general_redmex.docx';
verifyWordFinal(is_file($source), 'fixture general installed');
$originalHash = hash_file('sha256', $source);
$folder = sys_get_temp_dir() . '/impe-word-final-' . bin2hex(random_bytes(7));
verifyWordFinal(mkdir($folder, 0700), 'create temporary sandbox');
try {
    $success = $folder . '/with-metadata.docx';
    mutateWordFinal($source, $success, 'normal');

    $inspector = new OficioRedmexPdfRenderer();
    $open = new ReflectionMethod(OficioRedmexPdfRenderer::class, 'open');
    $close = new ReflectionMethod(OficioRedmexPdfRenderer::class, 'close');
    $spacingProperty = new ReflectionProperty(OficioRedmexPdfRenderer::class, 'defaultParagraphSpacing');
    try {
        $open->invoke($inspector, $success);
        $settings = $spacingProperty->getValue($inspector);
        verifyWordFinal(($settings['line'] ?? '') === '240', 'default spacing preserved');
    } finally {
        $close->invoke($inspector);
    }

    $renderDir = $folder . '/valid';
    verifyWordFinal(mkdir($renderDir, 0700), 'render directory');
    $result = (new OficioRedmexPdfRenderer())->convertir($success, $renderDir);
    verifyWordFinal(($result['ok'] ?? false) === true,
        'DOCX with metadata converts: ' . ($result['mensaje_tecnico'] ?? 'unknown'));
    $pdf = file_get_contents($result['ruta_pdf']);
    verifyWordFinal(strpos($pdf, '%PDF-') === 0 && strlen($pdf) > 1000, 'PDF valid');

    foreach ([
        'grid-incompatible' => 'REDMEX_DOC_GRID',
        'extension-incompatible' => 'REDMEX_PICTURE_EXTENSION',
        'spacing-incompatible' => 'REDMEX_DEFAULT_SPACING',
    ] as $kind => $expected) {
        $fixture = $folder . '/' . $kind . '.docx';
        mutateWordFinal($source, $fixture, $kind);
        $dir = $folder . '/' . $kind;
        verifyWordFinal(mkdir($dir, 0700), 'negative render dir');
        $failed = (new OficioRedmexPdfRenderer())->convertir($fixture, $dir);
        verifyWordFinal(($failed['ok'] ?? true) === false
            && ($failed['mensaje_tecnico'] ?? '') === $expected, 'reject ' . $kind);
    }
    verifyWordFinal(hash_file('sha256', $source) === $originalHash, 'original DOCX unchanged');
    echo "PASS oficios final: docGrid default, default spacing, a14 local DPI and safe rejections\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($folder);
}
