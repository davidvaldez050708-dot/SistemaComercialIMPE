<?php
/** Preparación local de un ensayo; nunca envía archivos ni invoca un conversor. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 1) {
    fwrite(STDERR, "Uso: php tools/oficios_api_poc_preparar.php\n");
    exit(1);
}
require_once dirname(__DIR__) . '/app/services/OficioDocxPdfService.php';

function pocSanearMetadatos($name, $xml)
{
    if (!str_starts_with($name, 'docProps/') && !str_starts_with($name, 'customXml/')
        && $name !== 'word/comments.xml' && $name !== 'word/people.xml') {
        return $xml;
    }
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    if (!$dom->loadXML($xml, LIBXML_NONET)) {
        throw new RuntimeException('Parte XML inválida.');
    }
    foreach ($dom->getElementsByTagName('*') as $element) {
        foreach ($element->attributes as $attribute) {
            if (in_array($attribute->localName, ['author', 'initials', 'userId'], true)) {
                $attribute->value = $attribute->localName === 'userId' ? 'prueba@example.test' : 'PRUEBA';
            }
        }
        if ($element->childNodes->length !== 1 || $element->firstChild->nodeType !== XML_TEXT_NODE) {
            continue;
        }
        $local = $element->localName;
        $replacement = null;
        if ($name === 'word/comments.xml' && $local === 't') {
            $replacement = 'Comentario ficticio de prueba.';
        } elseif (str_starts_with($name, 'customXml/') && !str_ends_with($name, '.rels')) {
            $replacement = 'PRUEBA-SIN-DATOS-PERSONALES';
        } elseif ($name === 'docProps/core.xml') {
            $replacement = in_array($local, ['created', 'modified'], true) ? '2026-10-20T00:00:00Z'
                : ($local === 'revision' ? '1' : 'PRUEBA');
        } elseif ($name === 'docProps/custom.xml') {
            $replacement = $local === 'filetime' ? '2026-10-20T00:00:00Z' : 'PRUEBA';
        } elseif ($name === 'docProps/app.xml' && in_array($local, ['Template', 'Company', 'Manager'], true)) {
            $replacement = 'PRUEBA';
        }
        if ($replacement !== null) {
            $element->firstChild->nodeValue = $replacement;
        }
    }
    return $dom->saveXML();
}

function pocAssets(ZipArchive $zip)
{
    $assets = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (str_starts_with($name, 'word/media/') || str_starts_with($name, 'word/fonts/')) {
            $assets[$name] = hash('sha256', $zip->getFromName($name));
        }
    }
    ksort($assets);
    return $assets;
}

$root = dirname(__DIR__);
$template = $root . '/storage/templates/oficio_general_redmex.docx';
$before = hash_file('sha256', $template);
$original = new ZipArchive();
$zip = new ZipArchive();
try {
    if ($original->open($template) !== true) {
        throw new RuntimeException('No se pudo abrir la plantilla.');
    }
    $assets = pocAssets($original);
    $original->close();
    $view = [
        'folio' => 'PRUEBA/0000/20-10/26', 'fecha' => '20 de octubre de 2026',
        'lugar_fecha' => 'Cuernavaca, Morelos, a 20 de octubre de 2026',
        'destinatario_nombre' => 'PERSONA DESTINATARIA DE PRUEBA', 'destinatario_cargo' => 'DIRECCIÓN',
        'institucion' => 'INSTITUCIÓN FICTICIA DE PRUEBA', 'estado' => 'Morelos', 'municipio' => 'Cuernavaca',
        'ubicacion' => 'Cuernavaca / Morelos', 'analista_nombre' => 'Analista de Prueba',
        'analista_correo' => 'prueba@example.test', 'analista_telefono' => '0000000000',
    ];
    $result = (new OficioDocxPdfService())->generarDocx($view);
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('No se pudo preparar el DOCX ficticio con el servicio existente.');
    }
    $tag = 'poc-redmex-' . bin2hex(random_bytes(6));
    $dir = $root . '/tmp/' . $tag;
    if (!mkdir($dir, 0700, true)) {
        throw new RuntimeException('No se pudo crear la carpeta aislada.');
    }
    $path = $dir . '/prueba-redmex.docx';
    if (file_put_contents($path, $result['contenido_docx'], LOCK_EX) === false || $zip->open($path) !== true) {
        throw new RuntimeException('No se pudo guardar la prueba.');
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    foreach ($names as $name) {
        if (str_ends_with($name, '.rels') && str_contains($zip->getFromName($name), 'TargetMode="External"')) {
            throw new RuntimeException('Relación externa detectada: no se autoriza enviar este ensayo.');
        }
        if (str_ends_with($name, '.xml')) {
            $xml = $zip->getFromName($name);
            $clean = pocSanearMetadatos($name, $xml);
            if ($clean !== $xml) {
                $zip->addFromString($name, $clean);
            }
        }
    }
    $zip->close();
    if ($zip->open($path) !== true || pocAssets($zip) !== $assets || hash_file('sha256', $template) !== $before) {
        throw new RuntimeException('Falló la conservación de plantilla, imágenes o fuentes.');
    }
    $zip->close();
    $manifest = [
        'purpose' => 'Ensayo ficticio; no es un oficio emitido ni un registro del CRM.',
        'tag' => $tag, 'input_sha256' => hash_file('sha256', $path), 'input_bytes' => filesize($path),
        'template_sha256' => $before, 'assets_sha256' => $assets,
        'expected_pdf' => ['pages' => 1, 'width_points' => 612, 'height_points' => 792],
        'original_template_modified' => false, 'network_requests' => 0,
        'privacy' => 'Destinatario, institución, folio y contacto ficticios; metadatos y comentarios anonimizados sólo en esta copia. Logos, sello y pie institucional conservados. Revisión visual previa obligatoria.',
        'cloudconvert_api_status' => 'Pendiente de cuenta, credencial y ejecución real.',
        'graph_status' => filesize($path) > 2000000 ? 'Supera el límite documentado de 2 MB de conversión.' : 'Pendiente de cuenta y validación.',
        'visual_fidelity' => 'Pendiente: no existe todavía PDF del proveedor.',
    ];
    $job = ['tag' => $tag, 'tasks' => [
        'upload-demo' => ['operation' => 'import/upload', 'timeout' => 60],
        'convert-demo' => ['operation' => 'convert', 'input' => 'upload-demo', 'input_format' => 'docx',
            'output_format' => 'pdf', 'engine' => 'office', 'engine_version' => '2.1',
            'filename' => 'prueba-redmex.pdf', 'timeout' => 60],
        'export-demo' => ['operation' => 'export/url', 'input' => 'convert-demo', 'inline' => false, 'archive' => false],
    ]];
    foreach (['manifest.json' => $manifest, 'cloudconvert-job.json' => $job] as $file => $value) {
        if (file_put_contents($dir . '/' . $file, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('No se pudo guardar el manifiesto del ensayo.');
        }
    }
    echo json_encode(['ok' => true, 'directory' => $dir, 'sha256' => $manifest['input_sha256'],
        'input_bytes' => $manifest['input_bytes'], 'network_requests' => 0], JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Ensayo no preparado: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
