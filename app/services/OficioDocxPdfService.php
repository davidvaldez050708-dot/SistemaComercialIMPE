<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use PhpOffice\PhpWord\TemplateProcessor;

class OficioDocxPdfService
{
    private $rootPath;
    private $templatePath;

    public function __construct()
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->templatePath = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR .
            'oficio_general_redmex.docx';
    }

    public function diagnosticar()
    {
        $conversor = $this->detectarConversor();

        return [
            'ok' => is_file($this->templatePath) && (class_exists('ZipArchive') || class_exists('PharData')) && function_exists('proc_open') && ($conversor['ok'] ?? false),
            'template' => $this->templatePath,
            'template_existe' => is_file($this->templatePath),
            'zip_disponible' => class_exists('ZipArchive') || class_exists('PharData'),
            'proc_open_disponible' => function_exists('proc_open'),
            'conversor' => $conversor
        ];
    }

    public function generarPdf(array $vista)
    {
        $rutaPlantilla = $this->resolverRutaPlantilla($vista);
        $personalizada = trim((string)($vista['archivo_plantilla'] ?? '')) !== '';
        // Paso 5 guarda el resultado en esta misma ruta, asociada al oficio.
        // Las plantillas del catálogo (oficio_*) aún necesitan rellenarse.
        $procesada = $personalizada && preg_match(
            '~^storage/templates/oficios_personalizados/generacion_\d{8}_\d{6}_[a-f0-9]{12}\.docx$~D',
            str_replace('\\', '/', (string)$vista['archivo_plantilla'])
        ) === 1;
        if ($personalizada) {
            self::registrarTrazaTemporal('CUSTOM_PREVIEW_START', ['oficio_id' => $vista['oficio_id'] ?? 0]);
            self::registrarTrazaTemporal('CUSTOM_TEMPLATE_TYPE=personalizada');
            self::registrarTrazaTemporal('CUSTOM_PROCESSED_FILE_FOUND=' . ($procesada && is_file($rutaPlantilla) ? 'true' : 'false'));
            self::registrarTrazaTemporal('CUSTOM_PROCESSED_FILE_READABLE=' . ($procesada && is_readable($rutaPlantilla) ? 'true' : 'false'));
            self::registrarTrazaTemporal('CUSTOM_PREVIEW_SOURCE=' . ($procesada ? 'processed' : 'custom_template'));
        }

        if (!is_file($rutaPlantilla)) {
            return $this->error(
                'No se encontró la plantilla DOCX institucional del oficio.',
                'Falta storage/templates/oficio_general_redmex.docx.'
            );
        }

        if (!class_exists('ZipArchive') && !class_exists('PharData')) {
            return $this->error(
                'PHP necesita soporte para archivos ZIP para preparar el oficio.',
                'No están disponibles ZipArchive ni PharData.'
            );
        }

        if (!function_exists('proc_open')) {
            return $this->error(
                'PHP no puede ejecutar el conversor de documentos.',
                'proc_open no está disponible.'
            );
        }

        $folio = trim((string)($vista['folio'] ?? 'oficio'));
        $nombreBase = $this->nombreArchivoSeguro($folio);
        $directorioTemporal = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' .
            DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'oficios' .
            DIRECTORY_SEPARATOR . $nombreBase . '_' . bin2hex(random_bytes(4));

        if (!mkdir($directorioTemporal, 0775, true) && !is_dir($directorioTemporal)) {
            return $this->error(
                'No fue posible preparar el archivo temporal del oficio.',
                'No se pudo crear: ' . $directorioTemporal
            );
        }

        $rutaDocx = $directorioTemporal . DIRECTORY_SEPARATOR . $nombreBase . '.docx';

        try {
            if (!copy($rutaPlantilla, $rutaDocx)) {
                return $this->error(
                    'No fue posible copiar la plantilla institucional.',
                    'copy() falló para: ' . $rutaDocx
                );
            }

            $rellenado = $procesada
                ? ['ok' => true]
                : ($personalizada
                    ? $this->rellenarConTemplateProcessor($rutaDocx, $vista)
                    : $this->rellenarDocx($rutaDocx, $this->construirReemplazos($vista)));

            if (!($rellenado['ok'] ?? false)) {
                return $rellenado;
            }

            if ($personalizada) {
                self::registrarTrazaTemporal('CUSTOM_PREVIEW_CONVERSION_START');
            }
            $conversion = $this->convertirAPdf($rutaDocx, $directorioTemporal);

            if (!($conversion['ok'] ?? false)) {
                return $conversion;
            }

            $pdfTemporal = (string)$conversion['ruta_pdf'];
            $contenido = is_file($pdfTemporal) ? file_get_contents($pdfTemporal) : false;

            if ($contenido === false || $contenido === '') {
                return $this->error(
                    'El conversor no generó un PDF válido.',
                    'El PDF temporal no existe, está vacío o no se pudo leer.'
                );
            }

            if ($personalizada) {
                self::registrarTrazaTemporal('CUSTOM_PREVIEW_CONVERSION_OK');
            }
            return [
                'ok' => true,
                'contenido_pdf' => $contenido,
                'conversor' => (string)($conversion['conversor'] ?? '')
            ];
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible generar el oficio desde la plantilla institucional.',
                $error->getMessage()
            );
        } finally {
            $this->eliminarDirectorio($directorioTemporal);
        }
    }

    private function construirReemplazos(array $vista)
    {
        $reemplazos = [
            '{{FOLIO}}' => trim((string)($vista['folio'] ?? '')),
            '{{FECHA}}' => trim((string)($vista['fecha'] ?? '')),
            '{{DESTINATARIO_NOMBRE}}' => $this->mayusculas($vista['destinatario_nombre'] ?? ''),
            '{{DESTINATARIO_CARGO}}' => $this->mayusculas($vista['destinatario_cargo'] ?? ''),
            '{{INSTITUCION}}' => $this->mayusculas($vista['institucion'] ?? ''),
            '{{ESTADO}}' => $this->mayusculas($vista['estado'] ?? ''),
            '{{ANALISTA_NOMBRE}}' => trim((string)($vista['analista_nombre'] ?? '')),
            '{{ANALISTA_CARGO}}' => 'Analista de Enlace Institucional',
            '{{ANALISTA_CORREO}}' => trim((string)($vista['analista_correo'] ?? '')),
            '{{ANALISTA_TELEFONO}}' => trim((string)($vista['analista_telefono'] ?? ''))
        ];

        $personalizados = [
            '{{numero_oficio}}' => $reemplazos['{{FOLIO}}'],
            '{{lugar_fecha}}' => trim((string)($vista['lugar_fecha'] ?? $vista['fecha'] ?? '')),
            '{{destinatario}}' => $reemplazos['{{DESTINATARIO_NOMBRE}}'],
            '{{institucion}}' => $reemplazos['{{INSTITUCION}}'],
            '{{ubicacion}}' => $this->mayusculas($vista['ubicacion'] ?? ''),
            '{{municipio}}' => $this->mayusculas($vista['municipio'] ?? ''),
            '{{estado}}' => $reemplazos['{{ESTADO}}']
        ];

        foreach ($personalizados as $token => $valor) {
            $personalizados['${' . trim($token, '{}') . '}'] = $valor;
        }

        return $reemplazos + $personalizados;
    }

    public function generarDocx(array $vista)
    {
        $rutaPlantilla = $this->resolverRutaPlantilla($vista);
        if (!is_file($rutaPlantilla) || (!class_exists('ZipArchive') && !class_exists('PharData'))) {
            return $this->error('No fue posible abrir la plantilla DOCX seleccionada.', 'Plantilla o soporte ZIP no disponible.');
        }

        $directorioTemporal = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' .
            DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'oficios' .
            DIRECTORY_SEPARATOR . 'docx_' . bin2hex(random_bytes(6));
        if (!mkdir($directorioTemporal, 0775, true) && !is_dir($directorioTemporal)) {
            return $this->error('No fue posible preparar el DOCX final.', 'No se creó el directorio temporal.');
        }

        $rutaDocx = $directorioTemporal . DIRECTORY_SEPARATOR . 'oficio.docx';
        try {
            if (!copy($rutaPlantilla, $rutaDocx)) {
                return $this->error('No fue posible copiar la plantilla seleccionada.', 'copy() falló.');
            }

            $rellenado = $this->rellenarConTemplateProcessor($rutaDocx, $vista);
            if (!($rellenado['ok'] ?? false)) {
                return $rellenado;
            }

            $contenido = file_get_contents($rutaDocx);
            if ($contenido === false || $contenido === '') {
                return $this->error('No fue posible leer el DOCX generado.', 'El archivo de salida está vacío.');
            }

            return ['ok' => true, 'contenido_docx' => $contenido];
        } finally {
            $this->eliminarDirectorio($directorioTemporal);
        }
    }

    public function validarPlantillaProcesable($rutaDocx)
    {
        if (!class_exists(TemplateProcessor::class)) {
            return $this->error('No fue posible validar la plantilla DOCX.', 'PhpOffice\\PhpWord\\TemplateProcessor no está disponible.');
        }

        try {
            $variables = (new TemplateProcessor($rutaDocx))->getVariables();
            $reconocidos = $this->variablesReconocidas($variables);
            $this->registrarDiagnosticoVariables($rutaDocx, $variables, $reconocidos, 'validacion');
            if (empty($reconocidos)) {
                return $this->validarEstructuraDocx($rutaDocx);
            }

            return ['ok' => true, 'marcadores' => array_values($reconocidos)];
        } catch (Throwable $error) {
            return $this->error('El archivo DOCX está dañado o no es válido.', $error->getMessage());
        }
    }

    private function rellenarConTemplateProcessor($rutaDocx, array $vista)
    {
        try {
            $procesador = new TemplateProcessor($rutaDocx);
            $variables = $procesador->getVariables();
            $valores = $this->valoresOficiales($vista);
            $reconocidos = $this->variablesReconocidas($variables);
            $this->registrarDiagnosticoVariables($rutaDocx, $variables, $reconocidos, 'generacion');
            if (empty($reconocidos)) {
                return $this->rellenarDocxEstructural($rutaDocx, $vista);
            }

            foreach ($reconocidos as $variableOriginal => $variableOficial) {
                if (trim((string)$valores[$variableOficial]) === '') {
                    return $this->error('No es posible generar el oficio porque falta ' . str_replace('_', ' ', $variableOficial) . '.', 'El marcador ${' . $variableOficial . '} no tiene información.');
                }
                $procesador->setValue($variableOriginal, (string)$valores[$variableOficial]);
            }
            $procesador->saveAs($rutaDocx);

            return ['ok' => true];
        } catch (Throwable $error) {
            return $this->error('No fue posible procesar la plantilla DOCX seleccionada.', $error->getMessage());
        }
    }

    private function valoresOficiales(array $vista)
    {
        return [
            'numero_oficio' => trim((string)($vista['folio'] ?? '')),
            'lugar_fecha' => trim((string)($vista['lugar_fecha'] ?? $vista['fecha'] ?? '')),
            'destinatario' => $this->mayusculas($vista['destinatario_nombre'] ?? ''),
            'institucion' => $this->mayusculas($vista['institucion'] ?? ''),
            'municipio' => $this->mayusculas($vista['municipio'] ?? ''),
            'estado' => $this->mayusculas($vista['estado'] ?? ''),
            'ubicacion' => $this->mayusculas($vista['ubicacion'] ?? '')
        ];
    }

    private function variablesReconocidas(array $variables)
    {
        $soportadas = array_keys($this->valoresOficiales([]));
        $reconocidas = [];
        foreach ($variables as $variableOriginal) {
            $normalizada = trim((string)$variableOriginal);
            $normalizada = str_replace(['\\_', "\xC2\xA0", ' '], ['_', '', ''], $normalizada);
            $normalizada = trim($normalizada, '${}');
            $normalizada = function_exists('mb_strtolower')
                ? mb_strtolower($normalizada, 'UTF-8')
                : strtolower($normalizada);
            if (in_array($normalizada, $soportadas, true)) {
                $reconocidas[(string)$variableOriginal] = $normalizada;
            }
        }

        return $reconocidas;
    }

    private function registrarDiagnosticoVariables($rutaDocx, array $variables, array $reconocidas, $etapa)
    {
        error_log('[oficio_docx] ' . json_encode([
            'etapa' => $etapa,
            'archivo' => basename((string)$rutaDocx),
            'tamano' => is_file($rutaDocx) ? filesize($rutaDocx) : 0,
            'variables_encontradas' => array_values($variables),
            'variables_reconocidas' => $reconocidas
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function resolverRutaPlantilla(array $vista)
    {
        $relativa = str_replace('\\', '/', trim((string)($vista['archivo_plantilla'] ?? '')));
        $prefijo = 'storage/templates/oficios_personalizados/';

        if ($relativa === '' || strpos($relativa, $prefijo) !== 0 || strpos($relativa, '..') !== false) {
            return $this->templatePath;
        }

        $ruta = $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativa);
        $directorioPermitido = realpath($this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, rtrim($prefijo, '/')));
        $rutaReal = realpath($ruta);

        if ($directorioPermitido === false || $rutaReal === false || strpos($rutaReal, $directorioPermitido . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }

        return $rutaReal;
    }

    private function rellenarDocx($rutaDocx, array $reemplazos)
    {
        if (class_exists('ZipArchive')) {
            return $this->rellenarConZipArchive($rutaDocx, $reemplazos);
        }

        return $this->rellenarConPharData($rutaDocx, $reemplazos);
    }

    private function rellenarConZipArchive($rutaDocx, array $reemplazos)
    {
        $zip = new ZipArchive();
        $abierto = $zip->open($rutaDocx);

        if ($abierto !== true) {
            return $this->error(
                'No fue posible abrir la plantilla DOCX.',
                'ZipArchive::open devolvió: ' . (string)$abierto
            );
        }

        try {
            $xml = $zip->getFromName('word/document.xml');

            if (!is_string($xml) || $xml === '') {
                return $this->error(
                    'La plantilla DOCX no contiene el documento principal.',
                    'word/document.xml no disponible.'
                );
            }

            $xml = $this->reemplazarTokensXml($xml, $reemplazos);

            if (is_array($xml)) {
                return $xml;
            }

            if (!$zip->addFromString('word/document.xml', $xml)) {
                return $this->error(
                    'No fue posible guardar los datos dentro del DOCX.',
                    'ZipArchive::addFromString falló.'
                );
            }
        } finally {
            $zip->close();
        }

        return ['ok' => true];
    }

    private function rellenarConPharData($rutaDocx, array $reemplazos)
    {
        try {
            $archivo = new PharData($rutaDocx);

            if (!isset($archivo['word/document.xml'])) {
                return $this->error(
                    'La plantilla DOCX no contiene el documento principal.',
                    'word/document.xml no disponible mediante PharData.'
                );
            }

            $xml = $archivo['word/document.xml']->getContent();
            $xml = $this->reemplazarTokensXml($xml, $reemplazos);

            if (is_array($xml)) {
                return $xml;
            }

            $archivo['word/document.xml'] = $xml;
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible modificar la plantilla DOCX.',
                $error->getMessage()
            );
        }

        return ['ok' => true];
    }

    private function reemplazarTokensXml($xml, array $reemplazos)
    {
        $camposObligatorios = [
            '{{numero_oficio}}' => 'número de oficio',
            '{{lugar_fecha}}' => 'lugar y fecha',
            '{{destinatario}}' => 'destinatario',
            '{{institucion}}' => 'institución',
            '{{municipio}}' => 'municipio',
            '{{estado}}' => 'estado',
            '{{ubicacion}}' => 'ubicación'
        ];

        foreach (array_keys($camposObligatorios) as $token) {
            $camposObligatorios['${' . trim($token, '{}') . '}'] = $camposObligatorios[$token];
        }

        $tieneMarcadores = false;
        if (class_exists('DOMDocument')) {
            $documentoMarcadores = new DOMDocument();
            $estadoAnterior = libxml_use_internal_errors(true);
            $cargado = $documentoMarcadores->loadXML($xml, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($estadoAnterior);
            if ($cargado) {
                $xpathMarcadores = new DOMXPath($documentoMarcadores);
                $xpathMarcadores->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                foreach ($xpathMarcadores->query('//w:p') as $parrafo) {
                    $textoParrafo = '';
                    foreach ($xpathMarcadores->query('.//w:t', $parrafo) as $nodoTexto) {
                        $textoParrafo .= $nodoTexto->textContent;
                    }
                    foreach ($reemplazos as $token => $valor) {
                        if (strpos($textoParrafo, $token) === false) {
                            continue;
                        }
                        $tieneMarcadores = true;
                        if (isset($camposObligatorios[$token]) && trim((string)$valor) === '') {
                            return $this->error(
                                'No es posible generar el oficio porque falta ' . $camposObligatorios[$token] . '.',
                                'El marcador ' . $token . ' no tiene información en el seguimiento.'
                            );
                        }
                        $this->reemplazarTextoEnParrafo($xpathMarcadores, $parrafo, $token, (string)$valor);
                        $textoParrafo = str_replace($token, (string)$valor, $textoParrafo);
                    }
                }
                if ($tieneMarcadores) {
                    $xml = $documentoMarcadores->saveXML();
                }
            }
        }

        if (!$tieneMarcadores) {
            return $this->error(
                'No fue posible identificar los campos dinámicos de la plantilla. Utiliza un formato compatible o agrega los marcadores correspondientes.',
                'No se encontró ningún marcador reconocido.'
            );
        }

        if (preg_match('/(?:\{\{[A-Za-z0-9_]+\}\}|\$\{[A-Za-z0-9_]+\})/', $xml, $coincidencia)) {
            return $this->error(
                'La plantilla contiene un campo que no pudo completarse.',
                'Token pendiente: ' . (string)($coincidencia[0] ?? 'desconocido')
            );
        }

        return $xml;
    }

    private function validarEstructuraDocx($rutaDocx)
    {
        $xml = $this->leerDocumentoPrincipal($rutaDocx);
        if (!is_string($xml)) {
            return $xml;
        }
        if ($this->tieneMarcadoresLegacyReconocidos($xml)) {
            error_log('[oficio_docx] ' . json_encode([
                'etapa' => 'validacion_marcadores_legacy',
                'resultado' => 'compatible'
            ], JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'modo' => 'marcadores_legacy'];
        }
        $resultado = $this->reemplazarEstructuraOficio($xml, [
            'folio' => 'REDMEX/0001/01-01/26',
            'lugar_fecha' => 'Municipio, Estado, a 1 de enero de 2026',
            'destinatario_nombre' => 'DESTINATARIO',
            'destinatario_cargo' => 'CARGO',
            'institucion' => 'INSTITUCIÓN',
            'ubicacion' => 'MUNICIPIO / ESTADO'
        ]);

        return is_string($resultado) ? ['ok' => true, 'modo' => 'estructural'] : $resultado;
    }

    private function rellenarDocxEstructural($rutaDocx, array $vista)
    {
        $zip = new ZipArchive();
        $abierto = $zip->open($rutaDocx);
        if ($abierto !== true) {
            return $this->error('No fue posible abrir la plantilla DOCX.', 'ZipArchive::open devolvió: ' . (string)$abierto);
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
            if (!is_string($xml) || $xml === '') {
                return $this->error('La plantilla DOCX no contiene el documento principal.', 'word/document.xml no disponible.');
            }
            $procesado = $this->tieneMarcadoresLegacyReconocidos($xml)
                ? $this->reemplazarTokensXml($xml, $this->construirReemplazos($vista))
                : $this->reemplazarEstructuraOficio($xml, $vista);
            if (is_array($procesado)) {
                return $procesado;
            }
            if (!$zip->addFromString('word/document.xml', $procesado)) {
                return $this->error('No fue posible guardar los datos dentro del DOCX.', 'ZipArchive::addFromString falló.');
            }
        } finally {
            $zip->close();
        }

        return ['ok' => true];
    }

    private function tieneMarcadoresLegacyReconocidos($xml)
    {
        foreach (array_keys($this->construirReemplazos([])) as $token) {
            if (strpos((string)$xml, $token) !== false) {
                return true;
            }
        }
        return false;
    }

    private function leerDocumentoPrincipal($rutaDocx)
    {
        $zip = new ZipArchive();
        $abierto = $zip->open($rutaDocx);
        if ($abierto !== true) {
            return $this->error('El archivo DOCX está dañado o no es válido.', 'No se pudo abrir como ZIP.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        return is_string($xml) && $xml !== ''
            ? $xml
            : $this->error('El archivo DOCX no contiene un documento principal válido.', 'word/document.xml no disponible.');
    }

    private function reemplazarEstructuraOficio($xml, array $vista)
    {
        error_log('[oficio_docx] ' . json_encode([
            'etapa' => 'fallback_estructural_inicio',
            'version' => '2026-09-08.2',
            'document_xml_disponible' => is_string($xml) && $xml !== ''
        ], JSON_UNESCAPED_UNICODE));

        if (!class_exists('DOMDocument')) {
            return $this->error('No fue posible analizar la estructura de la plantilla.', 'DOCX_04: DOMDocument no está disponible.');
        }

        $documento = new DOMDocument();
        $estadoAnterior = libxml_use_internal_errors(true);
        $cargado = $documento->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($estadoAnterior);
        if (!$cargado) {
            return $this->error('No fue posible identificar los campos dinámicos de la plantilla. Utiliza un formato compatible o agrega los marcadores correspondientes.', 'DOCX_04: XML principal inválido.');
        }

        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $parrafos = [];
        foreach ($xpath->query('//w:body//w:p') as $parrafo) {
            $texto = '';
            foreach ($xpath->query('.//w:t', $parrafo) as $nodoTexto) {
                $texto .= $nodoTexto->textContent;
            }
            $parrafos[] = [
                'nodo' => $parrafo,
                'texto' => $texto,
                'lineas' => $this->extraerLineasVisuales($xpath, $parrafo)
            ];
        }

        $indicePresente = null;
        $lineaPresente = null;
        $indiceFolio = null;
        $indiceFecha = null;
        $valorFolioAnterior = null;
        $fechaEncontrada = null;
        foreach ($parrafos as $indice => $parrafo) {
            foreach ($parrafo['lineas'] as $indiceLinea => $linea) {
                $normalizado = preg_replace('/[^\p{L}]/u', '', $this->mayusculas($linea['texto']));
                if ($indicePresente === null && $normalizado === 'PRESENTE') {
                    $indicePresente = $indice;
                    $lineaPresente = $indiceLinea;
                }
            }
            if ($indiceFolio === null && preg_match('/No\.?\s*de\s*oficio\s*:\s*(.*?)\s*$/iu', $parrafo['texto'], $coincidencia)) {
                $valorEnMismoParrafo = trim((string)($coincidencia[1] ?? ''));
                if ($valorEnMismoParrafo !== '') {
                    $indiceFolio = $indice;
                    $valorFolioAnterior = $valorEnMismoParrafo;
                } else {
                    for ($siguiente = $indice + 1; $siguiente < count($parrafos); $siguiente++) {
                        if (trim($parrafos[$siguiente]['texto']) !== '') {
                            $indiceFolio = $siguiente;
                            $valorFolioAnterior = $parrafos[$siguiente]['texto'];
                            break;
                        }
                    }
                }
            }
            if ($indiceFecha === null && preg_match('/(?:[^\r\n]*?\ba\s+)?\d{1,2}\s+de\s+[\p{L}]+\s+de\s+\d{4}/iu', $parrafo['texto'], $coincidencia)) {
                $indiceFecha = $indice;
                $fechaEncontrada = $coincidencia[0];
            }
        }

        $indicesBloque = [];
        $lineasBloqueMismoParrafo = [];
        if ($indiceFecha !== null && $indicePresente !== null && $indiceFecha < $indicePresente) {
            for ($i = $indiceFecha + 1; $i < $indicePresente; $i++) {
                if (trim($parrafos[$i]['texto']) !== '') {
                    $indicesBloque[] = $i;
                }
            }
        }
        if ($indiceFecha !== null && $indicePresente !== null && $indiceFecha < $indicePresente && $lineaPresente !== null) {
            for ($i = 0; $i < $lineaPresente; $i++) {
                if (trim($parrafos[$indicePresente]['lineas'][$i]['texto']) !== '') {
                    $lineasBloqueMismoParrafo[] = $parrafos[$indicePresente]['lineas'][$i];
                }
            }
        }

        error_log('[oficio_docx] ' . json_encode([
            'etapa' => 'fallback_estructural_anclas',
            'parrafos' => count($parrafos),
            'texto_extraido' => array_slice(array_values(array_filter(array_map(static function ($parrafo) {
                $texto = preg_replace('/\s+/u', ' ', trim((string)$parrafo['texto']));
                return function_exists('mb_substr') ? mb_substr($texto, 0, 180, 'UTF-8') : substr($texto, 0, 180);
            }, $parrafos))), 0, 12),
            'numero_oficio' => $indiceFolio !== null,
            'fecha' => $indiceFecha !== null,
            'presente' => $indicePresente !== null,
            'lineas_destinatario' => count($indicesBloque) + count($lineasBloqueMismoParrafo),
            'indices' => ['folio' => $indiceFolio, 'fecha' => $indiceFecha, 'presente' => $indicePresente]
        ], JSON_UNESCAPED_UNICODE));

        if ($indiceFolio === null) {
            return $this->error(
                'No fue posible identificar los campos dinámicos de la plantilla. Utiliza un formato compatible o agrega los marcadores correspondientes.',
                'DOCX_05: no se localizó la etiqueta No. de oficio: con un valor sustituible.'
            );
        }
        if ($indicePresente === null) {
            return $this->error(
                'No fue posible identificar los campos dinámicos de la plantilla. Utiliza un formato compatible o agrega los marcadores correspondientes.',
                'DOCX_06: no se localizó P R E S E N T E en word/document.xml.'
            );
        }
        if ($indiceFecha === null || (empty($indicesBloque) && empty($lineasBloqueMismoParrafo))) {
            return $this->error(
                'No fue posible identificar los campos dinámicos de la plantilla. Utiliza un formato compatible o agrega los marcadores correspondientes.',
                'DOCX_08: no se localizaron de forma segura la fecha y el bloque anterior a P R E S E N T E.'
            );
        }

        $folioNuevo = trim((string)($vista['folio'] ?? ''));
        $fechaNueva = trim((string)($vista['lugar_fecha'] ?? $vista['fecha'] ?? ''));
        $nuevoBloque = array_values(array_filter([
            $this->mayusculas($vista['destinatario_nombre'] ?? ''),
            $this->mayusculas($vista['destinatario_cargo'] ?? ''),
            $this->mayusculas($vista['institucion'] ?? ''),
            $this->mayusculas($vista['ubicacion'] ?? '')
        ], static function ($valor) {
            return trim((string)$valor) !== '';
        }));

        if ($folioNuevo === '' || $fechaNueva === '' || count($nuevoBloque) < 3) {
            return $this->error('No es posible generar el oficio porque faltan datos del seguimiento.', 'Folio, fecha, destinatario, institución o ubicación no disponibles.');
        }

        $cambios = [
            [$indiceFolio, $valorFolioAnterior, $folioNuevo],
            [$indiceFecha, $fechaEncontrada, $fechaNueva]
        ];

        foreach ($cambios as [$indice, $anterior, $nuevo]) {
            if (trim((string)$nuevo) === '' || !$this->reemplazarTextoEnParrafo($xpath, $parrafos[$indice]['nodo'], (string)$anterior, (string)$nuevo)) {
                return $this->error('No fue posible identificar los campos dinámicos de la plantilla. Utiliza un formato compatible o agrega los marcadores correspondientes.', 'DOCX_08: un campo estructural no pudo sustituirse de forma segura.');
            }
        }

        $parrafosDinamicosProcesados = [];
        if (!empty($lineasBloqueMismoParrafo)) {
            foreach ($lineasBloqueMismoParrafo as $posicion => $linea) {
                $nuevo = $nuevoBloque[$posicion] ?? '';
                if (!$this->reemplazarTextoEnParrafo($xpath, $parrafos[$indicePresente]['nodo'], $linea['texto'], $nuevo)) {
                    return $this->error('No fue posible procesar el bloque del destinatario.', 'No se pudo sustituir una línea existente.');
                }
            }
            if (count($nuevoBloque) > count($lineasBloqueMismoParrafo)) {
                $referencia = end($lineasBloqueMismoParrafo);
                for ($i = count($lineasBloqueMismoParrafo); $i < count($nuevoBloque); $i++) {
                    if (!$this->insertarLineaAntesDePresente($documento, $referencia, $parrafos[$indicePresente]['lineas'][$lineaPresente], $nuevoBloque[$i])) {
                        return $this->error('No fue posible procesar el bloque del destinatario.', 'No se pudo agregar una línea conservando su formato.');
                    }
                }
            }
        } else {
            foreach ($indicesBloque as $posicion => $indice) {
                $nuevo = $nuevoBloque[$posicion] ?? '';
                if (!$this->reemplazarTextoEnParrafo($xpath, $parrafos[$indice]['nodo'], $parrafos[$indice]['texto'], $nuevo)) {
                    return $this->error('No fue posible procesar el bloque del destinatario.', 'No se pudo sustituir un párrafo existente.');
                }
                $parrafosDinamicosProcesados[] = $parrafos[$indice]['nodo'];
            }

            if (count($nuevoBloque) > count($indicesBloque)) {
                $referencia = $parrafos[end($indicesBloque)]['nodo'];
                $presente = $parrafos[$indicePresente]['nodo'];
                for ($i = count($indicesBloque); $i < count($nuevoBloque); $i++) {
                    $clon = $referencia->cloneNode(true);
                    $textoClon = '';
                    foreach ($xpath->query('.//w:t', $clon) as $nodoTexto) {
                        $textoClon .= $nodoTexto->textContent;
                    }
                    if (!$this->reemplazarTextoEnParrafo($xpath, $clon, $textoClon, $nuevoBloque[$i])) {
                        return $this->error('No fue posible procesar el bloque del destinatario.', 'No se pudo crear un párrafo conservando el formato.');
                    }
                    $presente->parentNode->insertBefore($clon, $presente);
                    $parrafosDinamicosProcesados[] = $clon;
                }
            }
        }

        foreach ($parrafosDinamicosProcesados as $parrafoDinamico) {
            $this->quitarSaltosFinalesVacios($xpath, $parrafoDinamico);
        }

        return $documento->saveXML();
    }

    private function quitarSaltosFinalesVacios(DOMXPath $xpath, DOMNode $parrafo)
    {
        $texto = '';
        foreach ($xpath->query('.//w:t', $parrafo) as $nodoTexto) {
            $texto .= $nodoTexto->textContent;
        }
        if (trim($texto) === '') {
            return;
        }

        // El párrafo ya separa los campos; solo se retiran saltos sin texto al final.
        $contenido = iterator_to_array($xpath->query('.//w:r/*[not(self::w:rPr)]', $parrafo));
        foreach (array_reverse($contenido) as $nodo) {
            if ($nodo->localName === 't' && trim($nodo->textContent) === '') {
                continue;
            }
            if ($nodo->localName !== 'br'
                || !in_array($nodo->getAttributeNS($nodo->namespaceURI, 'type'), ['', 'textWrapping'], true)
                || $nodo->hasAttributeNS($nodo->namespaceURI, 'clear')) {
                break;
            }
            $nodo->parentNode->removeChild($nodo);
        }
    }

    private function extraerLineasVisuales(DOMXPath $xpath, DOMNode $parrafo)
    {
        $lineas = [['texto' => '', 'nodos' => []]];
        foreach ($xpath->query('.//w:t | .//w:br | .//w:tab', $parrafo) as $nodo) {
            if ($nodo->localName === 'br') {
                $lineas[] = ['texto' => '', 'nodos' => []];
                continue;
            }
            $indice = count($lineas) - 1;
            if ($nodo->localName === 'tab') {
                $lineas[$indice]['texto'] .= "\t";
                continue;
            }
            $lineas[$indice]['texto'] .= $nodo->textContent;
            $lineas[$indice]['nodos'][] = $nodo;
        }
        return $lineas;
    }

    private function insertarLineaAntesDePresente(DOMDocument $documento, array $referencia, array $presente, $texto)
    {
        $nodoReferencia = !empty($referencia['nodos']) ? end($referencia['nodos']) : null;
        $nodoPresente = !empty($presente['nodos']) ? reset($presente['nodos']) : null;
        if (!$nodoReferencia instanceof DOMNode || !$nodoPresente instanceof DOMNode) {
            return false;
        }
        $runReferencia = $nodoReferencia->parentNode;
        $runPresente = $nodoPresente->parentNode;
        if (!$runReferencia instanceof DOMNode || !$runPresente instanceof DOMNode || !$runPresente->parentNode) {
            return false;
        }
        $runNuevo = $runReferencia->cloneNode(true);
        foreach (iterator_to_array($runNuevo->childNodes) as $hijo) {
            if ($hijo->localName !== 'rPr') {
                $runNuevo->removeChild($hijo);
            }
        }
        $runNuevo->appendChild($documento->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:br'));
        $textoNuevo = $documento->createElementNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'w:t');
        $textoNuevo->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $textoNuevo->nodeValue = (string)$texto;
        $runNuevo->appendChild($textoNuevo);
        $runPresente->parentNode->insertBefore($runNuevo, $runPresente);
        return true;
    }

    private function reemplazarTextoEnParrafo(DOMXPath $xpath, DOMNode $parrafo, $textoAnterior, $textoNuevo)
    {
        $nodos = [];
        $textoCompleto = '';
        foreach ($xpath->query('.//w:t', $parrafo) as $nodo) {
            $inicio = strlen($textoCompleto);
            $textoCompleto .= $nodo->textContent;
            $nodos[] = ['nodo' => $nodo, 'inicio' => $inicio, 'fin' => strlen($textoCompleto)];
        }
        $posicion = strpos($textoCompleto, $textoAnterior);
        if ($posicion === false) {
            return false;
        }
        $fin = $posicion + strlen($textoAnterior);
        $insertado = false;
        foreach ($nodos as $dato) {
            if ($dato['fin'] <= $posicion || $dato['inicio'] >= $fin) {
                continue;
            }
            $original = $dato['nodo']->textContent;
            $desde = max(0, $posicion - $dato['inicio']);
            $hasta = min(strlen($original), $fin - $dato['inicio']);
            $prefijo = substr($original, 0, $desde);
            $sufijo = substr($original, $hasta);
            $dato['nodo']->nodeValue = $prefijo . (!$insertado ? $textoNuevo : '') . $sufijo;
            $insertado = true;
        }
        return $insertado;
    }

    private function convertirAPdf($rutaDocx, $directorioSalida)
    {
        $conversor = $this->detectarConversor();

        if (!($conversor['ok'] ?? false)) {
            return $this->error(
                'No se encontró un conversor DOCX a PDF en este equipo.',
                (string)($conversor['detalle'] ?? 'Instala LibreOffice o Microsoft Word.')
            );
        }

        if (($conversor['tipo'] ?? '') === 'libreoffice') {
            return $this->convertirConLibreOffice(
                (string)$conversor['ruta'],
                $rutaDocx,
                $directorioSalida
            );
        }

        return $this->convertirConWord($rutaDocx, $directorioSalida);
    }

    private function detectarConversor()
    {
        $configurado = getenv('LIBREOFFICE_PATH');
        $candidatos = [];

        if ($configurado !== false && trim((string)$configurado) !== '') {
            $candidatos[] = trim((string)$configurado);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $candidatos[] = 'C:\\Program Files\\LibreOffice\\program\\soffice.exe';
            $candidatos[] = 'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe';
        } else {
            $candidatos[] = '/usr/bin/libreoffice';
            $candidatos[] = '/usr/bin/soffice';
            $candidatos[] = '/snap/bin/libreoffice';
        }

        foreach (array_unique($candidatos) as $ruta) {
            if (is_file($ruta) && is_executable($ruta)) {
                return [
                    'ok' => true,
                    'tipo' => 'libreoffice',
                    'ruta' => $ruta,
                    'detalle' => 'LibreOffice disponible.'
                ];
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $powershell = getenv('SystemRoot');
            $rutaPowerShell = ($powershell ? rtrim($powershell, '\\/') : 'C:\\Windows') .
                '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';

            if (is_file($rutaPowerShell)) {
                return [
                    'ok' => true,
                    'tipo' => 'word',
                    'ruta' => $rutaPowerShell,
                    'detalle' => 'Se intentará convertir mediante Microsoft Word.'
                ];
            }
        }

        return [
            'ok' => false,
            'tipo' => '',
            'ruta' => '',
            'detalle' => 'No se encontró LibreOffice y no hay conversor de Word disponible.'
        ];
    }

    private function convertirConLibreOffice($ejecutable, $rutaDocx, $directorioSalida)
    {
        $comando = escapeshellarg($ejecutable) .
            ' --headless --convert-to pdf --outdir ' . escapeshellarg($directorioSalida) .
            ' ' . escapeshellarg($rutaDocx);
        $resultado = $this->ejecutar($comando);
        $rutaPdf = $directorioSalida . DIRECTORY_SEPARATOR .
            pathinfo($rutaDocx, PATHINFO_FILENAME) . '.pdf';

        if (($resultado['codigo'] ?? 1) !== 0 || !is_file($rutaPdf)) {
            return $this->error(
                'LibreOffice no pudo convertir el oficio a PDF.',
                trim((string)($resultado['salida'] ?? ''))
            );
        }

        return [
            'ok' => true,
            'ruta_pdf' => $rutaPdf,
            'conversor' => 'LIBREOFFICE'
        ];
    }

    private function convertirConWord($rutaDocx, $directorioSalida)
    {
        $rutaPdf = $directorioSalida . DIRECTORY_SEPARATOR .
            pathinfo($rutaDocx, PATHINFO_FILENAME) . '.pdf';
        $script = $directorioSalida . DIRECTORY_SEPARATOR . 'convertir_word.ps1';
        $contenido = <<<'POWERSHELL'
param(
    [Parameter(Mandatory=$true)][string]$Docx,
    [Parameter(Mandatory=$true)][string]$Pdf
)
$ErrorActionPreference = 'Stop'
$word = $null
$documento = $null
try {
    $word = New-Object -ComObject Word.Application
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $documento = $word.Documents.Open($Docx, $false, $true)
    $documento.ExportAsFixedFormat($Pdf, 17)
}
finally {
    if ($documento -ne $null) { $documento.Close($false) }
    if ($word -ne $null) { $word.Quit() }
}
POWERSHELL;

        if (file_put_contents($script, $contenido) === false) {
            return $this->error(
                'No fue posible preparar el conversor de Microsoft Word.',
                'No se pudo crear el script temporal de PowerShell.'
            );
        }

        $windows = getenv('SystemRoot') ?: 'C:\\Windows';
        $powershell = rtrim($windows, '\\/') .
            '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $comando = escapeshellarg($powershell) .
            ' -NoProfile -NonInteractive -ExecutionPolicy Bypass -File ' . escapeshellarg($script) .
            ' -Docx ' . escapeshellarg($rutaDocx) .
            ' -Pdf ' . escapeshellarg($rutaPdf);
        $resultado = $this->ejecutar($comando);

        if (($resultado['codigo'] ?? 1) !== 0 || !is_file($rutaPdf)) {
            return $this->error(
                'Microsoft Word no pudo convertir el oficio a PDF.',
                trim((string)($resultado['salida'] ?? ''))
            );
        }

        return [
            'ok' => true,
            'ruta_pdf' => $rutaPdf,
            'conversor' => 'MICROSOFT_WORD'
        ];
    }

    private function ejecutar($comando)
    {
        $descriptores = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $proceso = proc_open($comando, $descriptores, $pipes);

        if (!is_resource($proceso)) {
            return [
                'codigo' => 1,
                'salida' => 'proc_open no pudo iniciar el proceso.'
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($proceso);

        return [
            'codigo' => (int)$codigo,
            'salida' => trim((string)$stdout . PHP_EOL . (string)$stderr)
        ];
    }

    private function mayusculas($valor)
    {
        $valor = trim((string)$valor);

        return function_exists('mb_strtoupper')
            ? mb_strtoupper($valor, 'UTF-8')
            : strtoupper($valor);
    }

    private function nombreArchivoSeguro($folio)
    {
        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$folio));
        $nombre = trim((string)$nombre, '_');

        return $nombre !== '' ? $nombre : 'oficio';
    }

    private function eliminarDirectorio($directorio)
    {
        if (!is_dir($directorio)) {
            return;
        }

        $elementos = scandir($directorio);

        if (!is_array($elementos)) {
            return;
        }

        foreach ($elementos as $elemento) {
            if ($elemento === '.' || $elemento === '..') {
                continue;
            }

            $ruta = $directorio . DIRECTORY_SEPARATOR . $elemento;

            if (is_dir($ruta)) {
                $this->eliminarDirectorio($ruta);
            } else {
                @unlink($ruta);
            }
        }

        @rmdir($directorio);
    }

    private function error($mensaje, $detalle)
    {
        if (strpos($mensaje, 'No fue posible identificar los campos dinámicos de la plantilla') === 0) {
            $origen = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            self::registrarTrazaTemporal('MARKER_ERROR_TRIGGERED', [
                'archivo' => $origen[0]['file'] ?? __FILE__,
                'linea' => $origen[0]['line'] ?? 0,
                'metodo' => $origen[1]['function'] ?? '',
                'condicion' => $detalle
            ]);
        }
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'mensaje_tecnico' => $detalle
        ];
    }

    public static function registrarTrazaTemporal($evento, array $contexto = [])
    {
        if (getenv('OFICIO_CUSTOM_DEBUG') === '1') {
            error_log('[oficio_docx] ' . $evento . ' ' . json_encode(
                $contexto, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ));
        }
    }
}
