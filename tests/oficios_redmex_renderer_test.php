<?php
// Pruebas de oficios sobre copias ficticias. Sin BD, red, procesos ni Word.
require_once __DIR__ . '/../app/services/OficioDocxPdfService.php';
require_once __DIR__ . '/../app/services/OficioRedmexPdfRenderer.php';

function expectRedmex($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
function changeRedmex($path, callable $change) {
    $zip=new ZipArchive(); expectRedmex($zip->open($path)===true,'open fixture');
    $doc=new DOMDocument(); expectRedmex($doc->loadXML($zip->getFromName('word/document.xml'),LIBXML_NONET),'xml fixture');
    $xp=new DOMXPath($doc); $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $change($doc,$xp,$zip);
    $zip->addFromString('word/document.xml',$doc->saveXML()); $zip->close();
}
function cleanRedmex($dir) {
    foreach (scandir($dir) as $name) {
        if ($name==='.'||$name==='..') { continue; }
        $p=$dir.'/'.$name; if (is_dir($p) && !is_link($p)) { cleanRedmex($p); } else { unlink($p); }
    }
    rmdir($dir);
}
$root=dirname(__DIR__); $out=$root.'/output/pdf/oficios-redmex';
if (!is_dir($out)) { mkdir($out,0775,true); }
$source=$root.'/storage/templates/oficio_general_redmex.docx'; $hash=hash_file('sha256',$source);
$custom=$root.'/storage/templates/oficios_personalizados'; if (!is_dir($custom)) { mkdir($custom,0775,true); }
$id=bin2hex(random_bytes(6));
$catalog=$custom.'/oficio_20261020_120000_'.$id.'.docx';
$processed=$custom.'/generacion_20261020_120000_'.$id.'.docx';
$incompatible=$custom.'/generacion_20261020_120001_'.$id.'.docx';
$work=$root.'/tmp/redmex-suite-'.$id; mkdir($work,0775,true);
$vista=['folio'=>'PRUEBA/0000/20-10/26','lugar_fecha'=>'Cuernavaca, Morelos, a 20 de octubre de 2026',
    'fecha'=>'20 de octubre de 2026','destinatario_nombre'=>'PERSONA DESTINATARIA DE PRUEBA','destinatario_cargo'=>'DIRECCIÓN',
    'institucion'=>'INSTITUCIÓN FICTICIA DE PRUEBA','municipio'=>'Cuernavaca','estado'=>'MORELOS','ubicacion'=>'Cuernavaca / Morelos',
    'analista_nombre'=>'Analista de Prueba','analista_correo'=>'prueba@example.test','analista_telefono'=>'0000000000'];
$service=new OficioDocxPdfService();
$temporaryBefore=glob($root.'/storage/tmp/oficios/*',GLOB_ONLYDIR) ?: [];
try {
    $doc=$service->generarDocx($vista); expectRedmex($doc['ok'],'general docx');
    // Las muestras públicas no incluyen metadatos ni comentarios de personas reales.
    file_put_contents($out.'/general.docx',$doc['contenido_docx']);
    $z=new ZipArchive(); $z->open($out.'/general.docx');
    foreach (['docProps/core.xml','word/comments.xml','word/commentsExtended.xml','word/people.xml'] as $part) {
        $xml=$z->getFromName($part); if (!is_string($xml)) { continue; }
        $d=new DOMDocument(); $d->loadXML($xml,LIBXML_NONET);
        foreach ($d->getElementsByTagName('*') as $el) {
            foreach (['author','initials','userId','providerId','name'] as $attr) {
                foreach (iterator_to_array($el->attributes) as $attribute) {
                    if ($attribute->localName===$attr) { $attribute->value='PRUEBA'; }
                }
            }
            if (in_array($el->localName,['creator','lastModifiedBy','t'],true)) { $el->nodeValue='PRUEBA'; }
        }
        $z->addFromString($part,$d->saveXML());
    }
    $z->close();
    $result=$service->generarPdf($vista); expectRedmex($result['ok'],'general pdf: '.($result['mensaje_tecnico']??''));
    expectRedmex($result['conversor']==='REDMEX_DOMPDF','direct renderer'); file_put_contents($out.'/general.pdf',$result['contenido_pdf']);

    copy($out.'/general.docx',$catalog);
    changeRedmex($catalog,static function($d,$xp,$zip) {
        $p=$xp->query('/w:document/w:body/w:p')->item(13);
        while ($p->firstChild) { $p->removeChild($p->firstChild); }
        $r=$d->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w:r');
        $t=$d->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w:t');
        $t->appendChild($d->createTextNode('CUERPO PERSONALIZADO: reunión para ${institucion}. Este texto procede del DOCX seleccionado.'));
        $r->appendChild($t); $p->appendChild($r);
        $table=$d->createDocumentFragment();
        $table->appendXML('<w:tbl xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:tblPr><w:tblW w:w="10440" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="0" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid><w:gridCol w:w="5220"/><w:gridCol w:w="5220"/></w:tblGrid><w:tr><w:tc><w:tcPr><w:tcW w:w="5220" w:type="dxa"/></w:tcPr><w:p><w:r><w:t>TABLA: Programa ficticio</w:t></w:r></w:p></w:tc><w:tc><w:tcPr><w:tcW w:w="5220" w:type="dxa"/></w:tcPr><w:p><w:r><w:t>MODALIDAD: Institucional</w:t></w:r></w:p></w:tc></w:tr></w:tbl>');
        $p->parentNode->insertBefore($table,$p->nextSibling);
    });
    $catalogHash=hash_file('sha256',$catalog);
    $customVista=$vista+['archivo_plantilla'=>'storage/templates/oficios_personalizados/'.basename($catalog)];
    $prepared=$service->generarDocx($customVista,true); expectRedmex($prepared['ok'],'prepare custom');
    file_put_contents($out.'/personalizado.docx',$prepared['contenido_docx']);
    $result=$service->generarPdf($customVista); expectRedmex($result['ok'],'custom pdf: '.($result['mensaje_tecnico']??''));
    file_put_contents($out.'/personalizado.pdf',$result['contenido_pdf']);

    file_put_contents($processed,$prepared['contenido_docx']);
    changeRedmex($processed,static function($d,$xp,$zip) {
        foreach ($xp->query('//w:t') as $t) {
            $t->nodeValue=str_replace('CUERPO PERSONALIZADO:', 'EDICIÓN MANUAL CONSERVADA:', $t->textContent);
        }
    });
    $processedHash=hash_file('sha256',$processed);
    $processedVista=array_merge($vista,['archivo_plantilla'=>'storage/templates/oficios_personalizados/'.basename($processed),
        'analista_nombre'=>'NO DEBE REEMPLAZAR LA EDICIÓN','institucion'=>'NO DEBE SUSTITUIR EL CUERPO']);
    $doc=$service->generarDocx($processedVista); expectRedmex($doc['ok']&&hash('sha256',$doc['contenido_docx'])===$processedHash,'processed DOCX immutable');
    file_put_contents($out.'/preparado.docx',$doc['contenido_docx']);
    $result=$service->generarPdf($processedVista); expectRedmex($result['ok'],'processed pdf'); file_put_contents($out.'/preparado.pdf',$result['contenido_pdf']);

    $mutations=[
        'page'=>static function($d,$xp,$zip){ $xp->query('//w:pgSz')->item(0)->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w:w','11906'); },
        'columns'=>static function($d,$xp,$zip){ $xp->query('//w:cols')->item(0)->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w:num','2'); },
        'hidden'=>static function($d,$xp,$zip){ $r=$xp->query('//w:rPr')->item(0); $r->appendChild($d->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w:vanish')); },
        'graphic'=>static function($d,$xp,$zip){ $zip->addFromString('word/media/image6.png','not an image'); },
        'external'=>static function($d,$xp,$zip){ $xml=$zip->getFromName('word/_rels/document.xml.rels'); $zip->addFromString('word/_rels/document.xml.rels',str_replace('Target="media/image6.png"','TargetMode="External" Target="https://example.test/private.png"',$xml)); },
    ];
    foreach ($mutations as $label=>$mutation) {
        copy($processed,$incompatible); changeRedmex($incompatible,$mutation);
        $bad=array_merge($vista,['archivo_plantilla'=>'storage/templates/oficios_personalizados/'.basename($incompatible)]);
        $before=hash_file('sha256',$incompatible); $result=$service->generarPdf($bad);
        expectRedmex(!$result['ok']&&!isset($result['contenido_pdf']), 'reject '.$label);
        expectRedmex(hash_file('sha256',$incompatible)===$before,'immutable incompatible '.$label);
    }
    $bad=array_merge($vista,['archivo_plantilla'=>'storage/templates/missing.docx']);
    expectRedmex(!$service->generarPdf($bad)['ok'],'no silent general substitution');
    $available=$custom.'/oficio_20260911_115147_e2e9fb4003a1.docx';
    if (is_file($available)) {
        $availableHash=hash_file('sha256',$available);
        $result=(new OficioRedmexPdfRenderer())->convertir($available,$work);
        expectRedmex(!$result['ok'] && hash_file('sha256',$available)===$availableHash,'real alternate format safely rejected');
    }
    // Contenido extenso: debe paginar completo, con cabecera y pie en cada página.
    copy($processed,$incompatible);
    changeRedmex($incompatible,static function($d,$xp,$zip){
        $p=$xp->query('/w:document/w:body/w:p')->item(13);
        for ($i=1;$i<=65;$i++) {
            $copy=$p->cloneNode(true);
            foreach ($xp->query('.//w:t',$copy) as $text) { $text->nodeValue='PÁRRAFO EXTENSO '.$i.': contenido de prueba que debe conservarse completo en las páginas siguientes.'; }
            $p->parentNode->insertBefore($copy,$p);
        }
    });
    $long=array_merge($vista,['archivo_plantilla'=>'storage/templates/oficios_personalizados/'.basename($incompatible)]);
    $result=$service->generarPdf($long); expectRedmex($result['ok'],'long pagination: '.($result['mensaje_tecnico']??'')); file_put_contents($out.'/paginacion.pdf',$result['contenido_pdf']);
    expectRedmex(hash_file('sha256',$source)===$hash && hash_file('sha256',$catalog)===$catalogHash
        && hash_file('sha256',$processed)===$processedHash,'originals unchanged');
    expectRedmex(!array_diff(glob($root.'/storage/tmp/oficios/*',GLOB_ONLYDIR) ?: [],$temporaryBefore),'temporary directories removed on success and rejection');
    echo "OK: general, personalizada con tabla, preparada, incompatibles, paginación y originales intactos.\n";
} finally {
    foreach ([$catalog,$processed,$incompatible] as $file) { if (is_file($file)) { unlink($file); } }
    cleanRedmex($work);
}
