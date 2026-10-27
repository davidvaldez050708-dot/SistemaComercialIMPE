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
    expectRedmex($zip->addFromString('word/document.xml',$doc->saveXML()) && $zip->close(),'save fixture');
}
function cleanRedmex($dir) {
    foreach (scandir($dir) as $name) {
        if ($name==='.'||$name==='..') { continue; }
        $p=$dir.'/'.$name; if (is_dir($p) && !is_link($p)) { cleanRedmex($p); } else { unlink($p); }
    }
    rmdir($dir);
}
function institutionalContentRedmex($path) {
    $zip=new ZipArchive(); expectRedmex($zip->open($path)===true,'open institutional content');
    try {
        $doc=new DOMDocument(); expectRedmex($doc->loadXML($zip->getFromName('word/document.xml'),LIBXML_NONET),'institutional XML');
        $xp=new DOMXPath($doc); $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $content=[]; $started=false;
        foreach ($xp->query('/w:document/w:body/w:p') as $paragraph) {
            if (strpos($paragraph->textContent,'Por medio de la presente,')===0) { $started=true; }
            if ($started) { $content[]=$paragraph->C14N(true); }
            if ($started && strpos($paragraph->textContent,'Sin otro particular,')===0) { return $content; }
        }
        throw new RuntimeException('Institutional content incomplete');
    } finally { $zip->close(); }
}
function verifyPreparedContentRedmex($path, $general, $source) {
    expectRedmex(institutionalContentRedmex($path)===$general,'all institutional paragraphs and formatting unchanged: '.basename($path));
    $zip=new ZipArchive(); $zip->open($path);
    $original=new ZipArchive(); $original->open($source);
    try {
        $doc=new DOMDocument(); $doc->loadXML($zip->getFromName('word/document.xml'),LIBXML_NONET);
        $xp=new DOMXPath($doc); $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $text=''; foreach ($xp->query('//w:t') as $t) { $text.=$t->textContent; }
        foreach (['CUERPO PERSONALIZADO:', 'Este texto procede del DOCX seleccionado', 'TABLA: Programa ficticio',
            'MODALIDAD: Institucional', 'EDICIÓN MANUAL CONSERVADA:', 'NO DEBE'] as $bad) {
            expectRedmex(strpos($text,$bad)===false,'no QA content in institutional sample');
        }
        expectRedmex(strpos($text,'Dichos programas cuentan con validez oficial')!==false,'official validity paragraph');
        expectRedmex($xp->query('/w:document/w:body/w:p')->length===33 && $xp->query('//w:tbl')->length===0,'original structure without extra paragraphs or tables');
        $ref=new DOMDocument(); $ref->loadXML($original->getFromName('word/document.xml'),LIBXML_NONET);
        $rx=new DOMXPath($ref); $rx->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        expectRedmex($xp->query('//w:sectPr')->item(0)->C14N(true)===$rx->query('//w:sectPr')->item(0)->C14N(true),'page size and margins unchanged');
        for ($i=0;$i<$original->numFiles;$i++) {
            $part=$original->getNameIndex($i);
            if (preg_match('~^word/(media/|fonts/|styles[^/]*\.xml$|fontTable\.xml$|header[^/]*\.xml$|footer[^/]*\.xml$|.*\.rels$)~',$part)) {
                expectRedmex($zip->getFromName($part)===$original->getFromName($part),'original resource unchanged: '.$part);
            }
        }
    } finally { $zip->close(); $original->close(); }
}
$root=dirname(__DIR__); $out=$root.'/output/pdf/oficios-redmex';
if (!is_dir($out)) { mkdir($out,0775,true); }
$source=$root.'/storage/templates/oficio_general_redmex.docx'; $hash=hash_file('sha256',$source);
$custom=$root.'/storage/templates/oficios_personalizados'; if (!is_dir($custom)) { mkdir($custom,0775,true); }
$id=bin2hex(random_bytes(6));
$catalog=$custom.'/oficio_20261020_120000_'.$id.'.docx';
$processed=$custom.'/generacion_20261020_120000_'.$id.'.docx';
$incompatible=$custom.'/generacion_20261020_120001_'.$id.'.docx';
$fixtureFiles=[$catalog,$processed,$incompatible];
$work=$root.'/tmp/redmex-suite-'.$id; mkdir($work,0775,true);
$vista=['folio'=>'PRUEBA/0000/20-10/26','lugar_fecha'=>'Cuernavaca, Morelos, a 20 de octubre de 2026',
    'fecha'=>'20 de octubre de 2026','destinatario_nombre'=>'PERSONA DESTINATARIA DE PRUEBA','destinatario_cargo'=>'DIRECCIÓN',
    'institucion'=>'INSTITUCIÓN FICTICIA DE PRUEBA','municipio'=>'Cuernavaca','estado'=>'MORELOS','ubicacion'=>'Cuernavaca / Morelos',
    'analista_nombre'=>'Analista de Prueba','analista_correo'=>'prueba@example.test','analista_telefono'=>'0000000000'];
$service=new OficioDocxPdfService();
$preparationOnly=in_array('--solo-preparacion',$argv,true);
$samplesOnly=in_array('--solo-muestras',$argv,true);
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
    $institutionalContent=institutionalContentRedmex($source);
    verifyPreparedContentRedmex($out.'/general.docx',$institutionalContent,$source);
    if (!$preparationOnly) {
        $result=$service->generarPdf($vista); expectRedmex($result['ok'],'general pdf: '.($result['mensaje_tecnico']??''));
        expectRedmex($result['conversor']==='REDMEX_DOMPDF','direct renderer'); file_put_contents($out.'/general.pdf',$result['contenido_pdf']);
    }

    copy($out.'/general.docx',$catalog);
    changeRedmex($catalog,static function($d,$xp,$zip) use ($vista) {
        // Marcadores sólo en los campos existentes, nunca dentro del cuerpo oficial.
        $fields=[7=>[$vista['folio']=>'${numero_oficio}'],9=>[$vista['fecha']=>'${fecha}'],
            11=>[$vista['destinatario_nombre']=>'${destinatario}', $vista['destinatario_cargo']=>'${destinatario_cargo}',
                $vista['institucion']=>'${institucion}', $vista['estado']=>'${estado}'],
            29=>[$vista['analista_nombre']=>'${analista_nombre}'],31=>[$vista['analista_telefono']=>'${analista_telefono}']];
        foreach ($fields as $index=>$replacements) {
            $paragraph=$xp->query('/w:document/w:body/w:p')->item($index);
            foreach ($xp->query('.//w:t',$paragraph) as $t) { $t->nodeValue=strtr($t->textContent,$replacements); }
        }
    });
    $catalogHash=hash_file('sha256',$catalog);
    $customVista=array_merge($vista,['archivo_plantilla'=>'storage/templates/oficios_personalizados/'.basename($catalog),
        'folio'=>'PRUEBA/0001/20-10/26','destinatario_nombre'=>'DESTINATARIO DE EJEMPLO',
        'destinatario_cargo'=>'COORDINACIÓN ACADÉMICA','institucion'=>'INSTITUCIÓN EDUCATIVA DE PRUEBA',
        'analista_nombre'=>'María Ejemplo','analista_telefono'=>'0000000001']);
    $prepared=$service->generarDocx($customVista,true); expectRedmex($prepared['ok'],'prepare custom: '.($prepared['mensaje_tecnico']??''));
    file_put_contents($out.'/personalizado.docx',$prepared['contenido_docx']);
    verifyPreparedContentRedmex($out.'/personalizado.docx',$institutionalContent,$source);
    $customZip=new ZipArchive(); $customZip->open($out.'/personalizado.docx'); $customXml=$customZip->getFromName('word/document.xml'); $customZip->close();
    foreach (['PRUEBA/0001/20-10/26','DESTINATARIO DE EJEMPLO','COORDINACIÓN ACADÉMICA','INSTITUCIÓN EDUCATIVA DE PRUEBA','María Ejemplo','0000000001'] as $value) {
        expectRedmex(strpos($customXml,$value)!==false,'authorized custom field preserved');
    }
    expectRedmex(strpos($customXml,'${')===false,'authorized variables completed');
    if (!$preparationOnly) {
        $result=$service->generarPdf($customVista); expectRedmex($result['ok'],'custom pdf: '.($result['mensaje_tecnico']??''));
        file_put_contents($out.'/personalizado.pdf',$result['contenido_pdf']);
    }

    file_put_contents($processed,$prepared['contenido_docx']);
    changeRedmex($processed,static function($d,$xp,$zip) {
        foreach ($xp->query('//w:t') as $t) {
            $t->nodeValue=str_replace(['María Ejemplo','0000000001'], ['Ana Ejemplo','0000000002'], $t->textContent);
        }
    });
    $processedHash=hash_file('sha256',$processed);
    $processedVista=array_merge($vista,['archivo_plantilla'=>'storage/templates/oficios_personalizados/'.basename($processed),
        'analista_nombre'=>'NO DEBE REEMPLAZAR LA EDICIÓN','institucion'=>'NO DEBE SUSTITUIR EL CUERPO']);
    $doc=$service->generarDocx($processedVista); expectRedmex($doc['ok']&&hash('sha256',$doc['contenido_docx'])===$processedHash,'processed DOCX immutable');
    file_put_contents($out.'/preparado.docx',$doc['contenido_docx']);
    verifyPreparedContentRedmex($out.'/preparado.docx',$institutionalContent,$source);
    // Otra modalidad legítima mantiene su propia redacción, incluso estas palabras.
    copy($source,$incompatible);
    changeRedmex($incompatible,static function($d,$xp,$zip) {
        $paragraph=$xp->query('/w:document/w:body/w:p')->item(14);
        foreach ($xp->query('.//w:t',$paragraph) as $i=>$t) {
            $t->nodeValue=$i===0 ? 'Modalidad: Formación continua. TABLA y MODALIDAD forman parte de esta redacción autorizada.' : '';
        }
    });
    $otherContent=institutionalContentRedmex($incompatible); $otherHash=hash_file('sha256',$incompatible);
    $other=$service->generarDocx(array_merge($vista,['archivo_plantilla'=>'storage/templates/oficios_personalizados/'.basename($incompatible)]),true);
    expectRedmex($other['ok'],'prepare other modality'); file_put_contents($work.'/otra-modalidad.docx',$other['contenido_docx']);
    expectRedmex(institutionalContentRedmex($work.'/otra-modalidad.docx')===$otherContent && hash_file('sha256',$incompatible)===$otherHash,'no global filtering or substitution of other modalities');
    if ($preparationOnly) {
        expectRedmex(hash_file('sha256',$source)===$hash && hash_file('sha256',$catalog)===$catalogHash
            && hash_file('sha256',$processed)===$processedHash,'originals unchanged');
        expectRedmex(!array_diff(glob($root.'/storage/tmp/oficios/*',GLOB_ONLYDIR) ?: [],$temporaryBefore),'preparation temporaries removed');
        echo "OK: preparación general, personalizada y preparada; cuerpo institucional y recursos íntegros; campos autorizados e instantánea conservados. Sin conversión ni BD.\n";
        return;
    }
    $result=$service->generarPdf($processedVista); expectRedmex($result['ok'],'processed pdf'); file_put_contents($out.'/preparado.pdf',$result['contenido_pdf']);
    if ($samplesOnly) {
        expectRedmex(hash_file('sha256',$source)===$hash && hash_file('sha256',$catalog)===$catalogHash
            && hash_file('sha256',$processed)===$processedHash,'originals unchanged');
        expectRedmex(!array_diff(glob($root.'/storage/tmp/oficios/*',GLOB_ONLYDIR) ?: [],$temporaryBefore),'sample temporaries removed');
        echo "OK: tres muestras institucionales completas, personalización autorizada y PDF local válido. Sin pruebas de otros módulos.\n";
        return;
    }

    $mutations=[
        'page'=>static function($d,$xp,$zip){ $xp->query('//w:pgSz')->item(0)->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w:w','11906'); },
        'columns'=>static function($d,$xp,$zip){ $xp->query('//w:cols')->item(0)->setAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w:num','2'); },
        'hidden'=>static function($d,$xp,$zip){ $r=$xp->query('//w:rPr')->item(0); $r->appendChild($d->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main','w:vanish')); },
        'graphic'=>static function($d,$xp,$zip){ $zip->addFromString('word/media/image6.png','not an image'); },
        'external'=>static function($d,$xp,$zip){ $xml=$zip->getFromName('word/_rels/document.xml.rels'); $zip->addFromString('word/_rels/document.xml.rels',str_replace('Target="media/image6.png"','TargetMode="External" Target="https://example.test/private.png"',$xml)); },
    ];
    foreach ($mutations as $label=>$mutation) {
        $incompatible=$custom.'/generacion_20261020_120001_'.bin2hex(random_bytes(6)).'.docx'; $fixtureFiles[]=$incompatible;
        expectRedmex(copy($processed,$incompatible),'copy incompatible'); changeRedmex($incompatible,$mutation);
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
        expectRedmex($result['ok'] && hash_file('sha256',$available)===$availableHash,'real Embajadas format with separate header/footer supported without modifying source');
    }
    // Contenido extenso: debe paginar completo, con cabecera y pie en cada página.
    $incompatible=$custom.'/generacion_20261020_120001_'.bin2hex(random_bytes(6)).'.docx'; $fixtureFiles[]=$incompatible;
    expectRedmex(copy($processed,$incompatible),'copy long document');
    changeRedmex($incompatible,static function($d,$xp,$zip){
        $p=$xp->query('/w:document/w:body/w:p')->item(14);
        for ($i=1;$i<=65;$i++) {
            $copy=$p->cloneNode(true);
            foreach ($xp->query('.//w:t',$copy) as $j=>$text) { $text->nodeValue=$j===0 ? 'PÁRRAFO EXTENSO '.$i.': contenido de prueba que debe conservarse completo en las páginas siguientes.' : ''; }
            $p->parentNode->insertBefore($copy,$p);
        }
    });
    $long=array_merge($vista,['archivo_plantilla'=>'storage/templates/oficios_personalizados/'.basename($incompatible)]);
    $result=$service->generarPdf($long); expectRedmex($result['ok'],'long pagination: '.($result['mensaje_tecnico']??'')); file_put_contents($out.'/paginacion.pdf',$result['contenido_pdf']);
    expectRedmex(hash_file('sha256',$source)===$hash && hash_file('sha256',$catalog)===$catalogHash
        && hash_file('sha256',$processed)===$processedHash,'originals unchanged');
    expectRedmex(!array_diff(glob($root.'/storage/tmp/oficios/*',GLOB_ONLYDIR) ?: [],$temporaryBefore),'temporary directories removed on success and rejection');
    echo "OK: general, personalizada, preparada, cuerpo institucional y formato íntegros, incompatibles, paginación y originales intactos.\n";
} finally {
    foreach ($fixtureFiles as $file) { if (is_file($file)) { unlink($file); } }
    cleanRedmex($work);
}
