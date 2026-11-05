<?php
/**
 * Regresión de la posición horizontal wp:align en header/footer REDMEX.
 * No usa base de datos, ni edita DOCX; comprueba coordenadas y rechazos.
 */
require_once dirname(__DIR__) . '/app/services/OficioRedmexPdfRenderer.php';

function checkAlign(bool $ok, string $what): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $what); }
}
function position(string $reference, string $value, bool $alignment): DOMElement {
    $ns = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    $document = new DOMDocument();
    $root = $document->createElementNS($ns, 'wp:positionH');
    $root->setAttribute('relativeFrom', $reference);
    $element = $document->createElementNS($ns, $alignment ? 'wp:align' : 'wp:posOffset');
    $element->appendChild($document->createTextNode($value));
    $root->appendChild($element);
    $document->appendChild($root);
    return $root;
}
function checkRejected(ReflectionMethod $method, OficioRedmexPdfRenderer $renderer,
                       DOMElement $position, float $width, bool $header, string $label): void {
    try {
        $method->invoke($renderer, $position, $width, $header);
    } catch (RuntimeException $e) {
        checkAlign($e->getMessage() === 'REDMEX_ANCHOR_ALIGN', $label . ': known rejection code');
        return;
    }
    throw new RuntimeException('FAIL: expected rejection ' . $label);
}

$renderer = new OficioRedmexPdfRenderer();
$method = new ReflectionMethod(OficioRedmexPdfRenderer::class, 'horizontalPosition');
checkAlign($method->invoke($renderer, position('page','left',true), 200.0, true) === 0.0,
    'header left aligns to page origin');
checkAlign($method->invoke($renderer, position('page','right',true), 200.0, true) === 412.0,
    'footer right aligns within letter page, 612 - 200');
checkAlign($method->invoke($renderer, position('page','-450215',false), 200.0, true)
    === ((float)-450215 / 12700), 'numeric negative offset unchanged');
checkAlign($method->invoke($renderer, position('column','12700',false), 200.0, false)
    === 1.0, 'numeric body offsets preserved');
checkRejected($method,$renderer,position('page','left',true),200.0,false,
    'page alignment not supported for body');
checkRejected($method,$renderer,position('margin','left',true),200.0,true,
    'margin alignment not guessed');
checkRejected($method,$renderer,position('page','center',true),200.0,true,
    'nonvalidated center alignment');
checkRejected($method,$renderer,position('page','left',true),800.0,true,
    'image wider than page');
$both = position('page','right',true);
$ns = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
$other = $both->ownerDocument->createElementNS($ns, 'wp:posOffset', '100');
$both->appendChild($other);
checkRejected($method,$renderer,$both,200.0,true,'ambiguous align + offset');

echo "PASS: image anchors left/right page, numeric offsets and unsafe cases\n";
