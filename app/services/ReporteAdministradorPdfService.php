<?php

class ReporteAdministradorPdfService
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
    private const COLOR_PRIMARIO = '273A8A';
    private const COLOR_TEXTO = '16223B';
    private const COLOR_SECUNDARIO = '6D7480';
    private const COLOR_BORDE = 'E5E9EF';
    private const COLOR_FONDO = 'F8FAFC';
    private const COLOR_FONDO_PRIMARIO = 'EDF2FA';

    private $rootPath;
    private $templatePath;

    public function __construct()
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->templatePath = $this->rootPath . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR .
            'reportes' . DIRECTORY_SEPARATOR . 'Administrador' . DIRECTORY_SEPARATOR .
            'Plantilla Reporte Admin.docx';
    }

    public function generar(array $datosReporte)
    {
        if (!file_exists($this->templatePath) || !is_file($this->templatePath) || !is_readable($this->templatePath)) {
            return $this->error(
                'No fue posible localizar la plantilla del reporte administrativo.',
                'La plantilla no existe, no es un archivo o no tiene permiso de lectura: ' . $this->templatePath
            );
        }

        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            return $this->error(
                'No fue posible preparar el reporte administrativo.',
                'Se requieren ZipArchive y DOMDocument para procesar la copia temporal del DOCX.'
            );
        }

        if (!function_exists('proc_open')) {
            return $this->error(
                'No fue posible convertir el reporte administrativo a PDF.',
                'proc_open no está disponible en este entorno.'
            );
        }

        $nombreBase = 'Reporte_Administrativo_Usuarios_' . date('Y-m-d');
        $directorioTemporal = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' .
            DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'reportes' .
            DIRECTORY_SEPARATOR . 'administrador' . DIRECTORY_SEPARATOR .
            'reporte_' . bin2hex(random_bytes(6));

        if (!mkdir($directorioTemporal, 0775, true) && !is_dir($directorioTemporal)) {
            return $this->error(
                'No fue posible preparar el reporte administrativo.',
                'No se pudo crear el directorio temporal: ' . $directorioTemporal
            );
        }

        $rutaDocx = $directorioTemporal . DIRECTORY_SEPARATOR . $nombreBase . '.docx';

        try {
            if (!copy($this->templatePath, $rutaDocx)) {
                return $this->error(
                    'No fue posible preparar la plantilla del reporte administrativo.',
                    'copy() falló al crear la copia temporal.'
                );
            }

            $insertado = $this->insertarContenido($rutaDocx, $datosReporte);
            if (!($insertado['ok'] ?? false)) {
                return $insertado;
            }

            $conversion = $this->convertirAPdf($rutaDocx, $directorioTemporal);
            if (!($conversion['ok'] ?? false)) {
                return $conversion;
            }

            $rutaPdf = (string)($conversion['ruta_pdf'] ?? '');
            $contenidoPdf = $rutaPdf !== '' && is_file($rutaPdf)
                ? file_get_contents($rutaPdf)
                : false;

            if ($contenidoPdf === false || $contenidoPdf === '') {
                return $this->error(
                    'No fue posible obtener el PDF administrativo generado.',
                    'El archivo PDF temporal no existe, está vacío o no pudo leerse.'
                );
            }

            return [
                'ok' => true,
                'contenido_pdf' => $contenidoPdf,
                'nombre_archivo' => $nombreBase . '.pdf',
                'conversor' => (string)($conversion['conversor'] ?? '')
            ];
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible generar el reporte administrativo.',
                $error->getMessage()
            );
        } finally {
            $this->eliminarDirectorio($directorioTemporal);
        }
    }

    private function insertarContenido($rutaDocx, array $datosReporte)
    {
        $zip = new ZipArchive();
        $abierto = $zip->open($rutaDocx);

        if ($abierto !== true) {
            return $this->error(
                'No fue posible abrir la plantilla del reporte administrativo.',
                'ZipArchive::open devolvió el código: ' . (string)$abierto
            );
        }

        try {
            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false || trim((string)$xml) === '') {
                return $this->error(
                    'La plantilla del reporte administrativo no contiene un documento válido.',
                    'No se encontró word/document.xml dentro de la copia temporal.'
                );
            }

            $documento = new DOMDocument('1.0', 'UTF-8');
            $documento->preserveWhiteSpace = true;
            $documento->formatOutput = false;

            if (!@$documento->loadXML($xml)) {
                return $this->error(
                    'La plantilla del reporte administrativo no contiene un documento válido.',
                    'word/document.xml no pudo interpretarse como XML.'
                );
            }

            $xpath = new DOMXPath($documento);
            $xpath->registerNamespace('w', self::W_NS);
            $cuerpo = $xpath->query('/w:document/w:body')->item(0);

            if (!$cuerpo instanceof DOMElement) {
                return $this->error(
                    'La plantilla del reporte administrativo no tiene una estructura compatible.',
                    'No se encontró /w:document/w:body.'
                );
            }

            $sectPr = $xpath->query('./w:sectPr', $cuerpo)->item(0);
            $anchoUtil = $this->obtenerAnchoUtil($xpath, $cuerpo);
            $this->aplicarEncabezadoInstitucional($zip, $datosReporte, $anchoUtil);
            $this->aplicarPieInstitucional($zip, $anchoUtil);
            $elementos = $this->construirContenido($documento, $datosReporte, $anchoUtil);

            foreach ($elementos as $elemento) {
                if ($sectPr instanceof DOMNode) {
                    $cuerpo->insertBefore($elemento, $sectPr);
                } else {
                    $cuerpo->appendChild($elemento);
                }
            }

            $xmlFinal = $documento->saveXML();
            if ($xmlFinal === false || !$zip->addFromString('word/document.xml', $xmlFinal)) {
                return $this->error(
                    'No fue posible incorporar la información al reporte administrativo.',
                    'No se pudo actualizar word/document.xml en la copia temporal.'
                );
            }

            return ['ok' => true];
        } finally {
            $zip->close();
        }
    }

    private function construirContenido(DOMDocument $documento, array $datosReporte, $anchoUtil)
    {
        $resumen = $datosReporte['resumen'] ?? [];
        $usuarios = $datosReporte['usuarios'] ?? [];
        $pendientes = $datosReporte['pendientes'] ?? [];
        $fechaGeneracion = trim((string)($datosReporte['fecha_generacion'] ?? ''));
        $elementos = [];

        $usuariosPorRol = [];
        $cargaPorUsuario = [];
        $usuariosSinAcceso = 0;
        $mayorCarga = 0;

        foreach ($usuarios as $usuario) {
            $rol = trim((string)($usuario['rol'] ?? ''));
            $rol = $rol !== '' ? $rol : 'Sin rol';
            $usuariosPorRol[$rol] = ($usuariosPorRol[$rol] ?? 0) + 1;

            $nombreCompleto = trim(
                (string)($usuario['nombre'] ?? '') . ' ' .
                (string)($usuario['apellidos'] ?? '')
            );
            $etiquetaCarga = $nombreCompleto !== ''
                ? $nombreCompleto
                : (string)($usuario['usuario'] ?? 'Usuario');
            $totalUsuario = (int)($usuario['total_seguimientos'] ?? 0);
            $cargaPorUsuario[] = [
                'etiqueta' => $etiquetaCarga,
                'valor' => $totalUsuario
            ];
            $mayorCarga = max($mayorCarga, $totalUsuario);

            if (trim((string)($usuario['ultimo_acceso'] ?? '')) === '') {
                $usuariosSinAcceso++;
            }
        }

        arsort($usuariosPorRol);
        usort($cargaPorUsuario, static function ($a, $b) {
            return ((int)($b['valor'] ?? 0)) <=> ((int)($a['valor'] ?? 0));
        });

        $datosRoles = [];
        foreach ($usuariosPorRol as $rol => $total) {
            $datosRoles[] = ['etiqueta' => (string)$rol, 'valor' => (int)$total];
        }

        $elementos[] = $this->crearTituloSeccion($documento, 'RESUMEN EJECUTIVO');
        $elementos[] = $this->crearTarjetasIndicadores(
            $documento,
            [
                ['etiqueta' => 'Usuarios', 'valor' => (int)($resumen['usuarios_registrados'] ?? 0)],
                ['etiqueta' => 'Activos', 'valor' => (int)($resumen['usuarios_activos'] ?? 0)],
                ['etiqueta' => 'Inactivos', 'valor' => (int)($resumen['usuarios_inactivos'] ?? 0)],
                ['etiqueta' => 'Roles', 'valor' => (int)($resumen['roles_registrados'] ?? 0)],
                ['etiqueta' => 'Seguimientos', 'valor' => (int)($resumen['total_seguimientos'] ?? 0)],
                ['etiqueta' => 'Pendientes', 'valor' => (int)($resumen['acciones_pendientes'] ?? 0)],
                ['etiqueta' => 'Vencidas', 'valor' => (int)($resumen['acciones_vencidas'] ?? 0)],
                ['etiqueta' => 'Requieren atención', 'valor' => (int)($resumen['requieren_atencion'] ?? 0)]
            ],
            $anchoUtil,
            4
        );
        $elementos[] = $this->crearEspaciador($documento, 120);

        $elementos[] = $this->crearTituloSeccion($documento, 'PERSONAS Y ACCESO');
        $elementos[] = $this->crearSubtitulo($documento, 'Usuarios por rol');
        $elementos[] = $this->crearGraficaBarras($documento, $datosRoles, $anchoUtil);
        $elementos[] = $this->crearEspaciador($documento, 80);

        $elementos[] = $this->crearSubtitulo($documento, 'Estado de usuarios');
        $elementos[] = $this->crearGraficaBarras(
            $documento,
            [
                ['etiqueta' => 'Activos', 'valor' => (int)($resumen['usuarios_activos'] ?? 0)],
                ['etiqueta' => 'Inactivos', 'valor' => (int)($resumen['usuarios_inactivos'] ?? 0)]
            ],
            $anchoUtil
        );
        $elementos[] = $this->crearEspaciador($documento, 80);

        $elementos[] = $this->crearSubtitulo($documento, 'Usuarios del sistema');
        $filasUsuarios = [];
        foreach ($usuarios as $usuario) {
            $filasUsuarios[] = [
                (string)($usuario['usuario'] ?? '—'),
                trim((string)($usuario['nombre'] ?? '') . ' ' . (string)($usuario['apellidos'] ?? '')),
                (string)($usuario['rol'] ?? '—'),
                (int)($usuario['estado'] ?? 0) === 1 ? 'Activo' : 'Inactivo',
                $this->formatearFechaHora($usuario['ultimo_acceso'] ?? null, 'Sin acceso registrado')
            ];
        }
        $elementos[] = $this->crearTablaDetalle(
            $documento,
            ['Usuario', 'Nombre', 'Rol', 'Estado', 'Último acceso'],
            $filasUsuarios,
            $this->anchos($anchoUtil, [16, 27, 19, 12, 26])
        );
        $elementos[] = $this->crearEspaciador($documento, 120);

        $filasCarga = [];
        foreach ($usuarios as $usuario) {
            $filasCarga[] = [
                (string)($usuario['usuario'] ?? '—'),
                (string)($usuario['rol'] ?? '—'),
                (string)((int)($usuario['total_seguimientos'] ?? 0)),
                (string)((int)($usuario['acciones_pendientes'] ?? 0)),
                (string)((int)($usuario['acciones_vencidas'] ?? 0)),
                (string)((int)($usuario['sin_actividad'] ?? 0)),
                (string)((int)($usuario['mas_7_dias'] ?? 0))
            ];
        }

        $elementos[] = $this->crearTituloSeccion($documento, 'ATENCIÓN REQUERIDA');
        $elementos[] = $this->crearTarjetasIndicadores(
            $documento,
            [
                ['etiqueta' => 'Acciones vencidas', 'valor' => (int)($resumen['acciones_vencidas'] ?? 0)],
                ['etiqueta' => 'Requieren atención', 'valor' => (int)($resumen['requieren_atencion'] ?? 0)]
            ],
            $anchoUtil,
            2
        );
        $elementos[] = $this->crearEspaciador($documento, 80);

        $elementos[] = $this->crearSubtitulo(
            $documento,
            'Pendientes y seguimientos que requieren atención'
        );
        if (empty($pendientes)) {
            $elementos[] = $this->crearParrafo(
                $documento,
                'No se encontraron seguimientos con acciones programadas, sin actividad o con más de 7 días sin movimiento.',
                ['tamano' => 16, 'color' => self::COLOR_SECUNDARIO, 'despues' => 80]
            );
        } else {
            $filasPendientes = [];
            foreach ($pendientes as $pendiente) {
                $filasPendientes[] = [
                    trim((string)($pendiente['responsable_nombre'] ?? '') . ' ' . (string)($pendiente['responsable_apellidos'] ?? '')),
                    (string)($pendiente['nombre_entidad'] ?? '—'),
                    (string)($pendiente['estado_nombre'] ?? '—'),
                    (string)($pendiente['estado_label'] ?? $pendiente['estado_seguimiento'] ?? '—'),
                    $this->formatearFechaHora($pendiente['ultima_interaccion_at'] ?? null, 'Sin actividad'),
                    $pendiente['dias_sin_actividad'] === null
                        ? '—'
                        : (string)((int)$pendiente['dias_sin_actividad']),
                    $this->formatearFechaHora($pendiente['proxima_accion_at'] ?? null, 'Sin fecha')
                ];
            }

            $elementos[] = $this->crearTablaDetalle(
                $documento,
                ['Responsable', 'Institución', 'Estado', 'Estatus', 'Última actividad', 'Días', 'Próxima acción'],
                $filasPendientes,
                $this->anchos($anchoUtil, [17, 23, 13, 14, 14, 7, 12])
            );
        }

        $elementos[] = $this->crearEspaciador($documento, 100);
        $elementos[] = $this->crearTituloSeccion($documento, 'HALLAZGOS ADMINISTRATIVOS');

        $accionesVencidas = (int)($resumen['acciones_vencidas'] ?? 0);
        $requierenAtencion = (int)($resumen['requieren_atencion'] ?? 0);
        $hallazgos = [
            $accionesVencidas > 0
                ? 'Existen ' . $accionesVencidas . ' acciones vencidas.'
                : 'No se registran acciones vencidas.',
            $requierenAtencion > 0
                ? $requierenAtencion . ' seguimientos requieren atención.'
                : 'No se registran seguimientos que requieran atención.',
            $usuariosSinAcceso > 0
                ? $usuariosSinAcceso . ' usuarios no tienen acceso registrado.'
                : 'Todos los usuarios incluidos tienen acceso registrado.',
            $mayorCarga > 0
                ? 'La mayor carga registrada por un usuario es de ' . $mayorCarga . ' seguimientos.'
                : 'No se registran seguimientos asignados a usuarios.'
        ];

        foreach ($hallazgos as $hallazgo) {
            $elementos[] = $this->crearParrafo(
                $documento,
                '• ' . $hallazgo,
                ['tamano' => 15, 'color' => self::COLOR_TEXTO, 'despues' => 35]
            );
        }

        return $elementos;
    }


    private function aplicarEncabezadoInstitucional(ZipArchive $zip, array $datosReporte, $anchoUtil)
    {
        if ($zip->locateName('word/header1.xml') === false) {
            return;
        }

        $fecha = trim((string)($datosReporte['fecha_generacion'] ?? ''));
        $fecha = $fecha !== '' ? $fecha : date('d/m/Y H:i');
        $generadoPor = trim((string)($datosReporte['generado_por'] ?? ''));
        $rol = trim((string)($datosReporte['generado_por_rol'] ?? ''));

        $anchoTotal = max(7200, (int)$anchoUtil);
        $anchoLogo = (int)round($anchoTotal * 0.34);
        $anchoTexto = max(1, $anchoTotal - $anchoLogo);

        $anchoLogoPuntos = 110.0;
        $altoLogoPuntos = 42.0;
        $contenidoLogo = $zip->getFromName('word/media/image1.png');

        if (is_string($contenidoLogo) && $contenidoLogo !== '') {
            $dimensiones = @getimagesizefromstring($contenidoLogo);
            if (
                is_array($dimensiones) &&
                (int)($dimensiones[0] ?? 0) > 0 &&
                (int)($dimensiones[1] ?? 0) > 0
            ) {
                $altoLogoPuntos = $anchoLogoPuntos *
                    ((int)$dimensiones[1] / (int)$dimensiones[0]);
                $altoLogoPuntos = max(24.0, min(58.0, $altoLogoPuntos));
            }

            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
                '<Relationship Id="rIdLogo" ' .
                'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" ' .
                'Target="media/image1.png"/>' .
                '</Relationships>';
            $zip->addFromString('word/_rels/header1.xml.rels', $rels);
        }

        $logoXml = '';
        if (is_string($contenidoLogo) && $contenidoLogo !== '') {
            $logoXml =
                '<w:p><w:pPr><w:jc w:val="left"/><w:spacing w:before="0" w:after="0"/></w:pPr>' .
                '<w:r><w:pict><v:rect stroked="f" style="width:' .
                number_format($anchoLogoPuntos, 1, '.', '') . 'pt;height:' .
                number_format($altoLogoPuntos, 1, '.', '') . 'pt">' .
                '<v:imagedata r:id="rIdLogo" o:title="Grupo Porcayo"/>' .
                '</v:rect></w:pict></w:r></w:p>';
        }

        $meta = '';
        if ($generadoPor !== '') {
            $meta .= $this->parrafoHeaderXml('Generado por: ' . $generadoPor, 12, self::COLOR_SECUNDARIO, false);
        }
        if ($rol !== '') {
            $meta .= $this->parrafoHeaderXml('Rol: ' . $rol, 12, self::COLOR_SECUNDARIO, false);
        }
        $meta .= $this->parrafoHeaderXml('Fecha: ' . $fecha, 12, self::COLOR_SECUNDARIO, false);

        $header =
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:hdr xmlns:w="' . self::W_NS . '" ' .
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" ' .
            'xmlns:v="urn:schemas-microsoft-com:vml" ' .
            'xmlns:o="urn:schemas-microsoft-com:office:office">' .
            '<w:tbl><w:tblPr><w:tblW w:w="' . $anchoTotal . '" w:type="dxa"/>' .
            '<w:tblLayout w:type="fixed"/><w:tblBorders>' .
            '<w:bottom w:val="single" w:sz="16" w:space="7" w:color="' . self::COLOR_PRIMARIO . '"/>' .
            '</w:tblBorders></w:tblPr>' .
            '<w:tblGrid><w:gridCol w:w="' . $anchoLogo . '"/><w:gridCol w:w="' . $anchoTexto . '"/></w:tblGrid>' .
            '<w:tr><w:trPr><w:cantSplit/></w:trPr>' .
            '<w:tc><w:tcPr><w:tcW w:w="' . $anchoLogo . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' .
            $logoXml . '</w:tc>' .
            '<w:tc><w:tcPr><w:tcW w:w="' . $anchoTexto . '" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>' .
            $this->parrafoHeaderXml('Sistema de Gestión Comercial', 13, self::COLOR_PRIMARIO, true) .
            $this->parrafoHeaderXml('Reporte Administrativo de Usuarios', 23, self::COLOR_TEXTO, true) .
            $this->parrafoHeaderXml('Usuarios, carga de seguimiento y pendientes operativos', 14, self::COLOR_SECUNDARIO, false) .
            $meta .
            '</w:tc></w:tr></w:tbl>' .
            '</w:hdr>';

        $zip->addFromString('word/header1.xml', $header);
    }

    private function parrafoHeaderXml($texto, $tamano, $color, $negrita)
    {
        return '<w:p><w:pPr><w:jc w:val="right"/>' .
            '<w:spacing w:before="0" w:after="25"/></w:pPr><w:r><w:rPr>' .
            ($negrita ? '<w:b/>' : '') .
            '<w:color w:val="' . $color . '"/>' .
            '<w:sz w:val="' . (int)$tamano . '"/><w:szCs w:val="' . (int)$tamano . '"/>' .
            '</w:rPr><w:t xml:space="preserve">' . $this->xmlTexto($texto) . '</w:t></w:r></w:p>';
    }

    private function aplicarPieInstitucional(ZipArchive $zip, $anchoUtil)
    {
        $anchoTotal = max(7200, (int)$anchoUtil);
        $anchoIzquierdo = (int)round($anchoTotal * 0.68);
        $anchoDerecho = max(1, $anchoTotal - $anchoIzquierdo);

        $pie =
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<w:ftr xmlns:w="' . self::W_NS . '">' .
            '<w:tbl><w:tblPr><w:tblW w:w="' . $anchoTotal . '" w:type="dxa"/>' .
            '<w:tblLayout w:type="fixed"/><w:tblBorders>' .
            '<w:top w:val="single" w:sz="4" w:space="6" w:color="' . self::COLOR_BORDE . '"/>' .
            '</w:tblBorders></w:tblPr>' .
            '<w:tblGrid><w:gridCol w:w="' . $anchoIzquierdo . '"/><w:gridCol w:w="' . $anchoDerecho . '"/></w:tblGrid>' .
            '<w:tr><w:trPr><w:cantSplit/></w:trPr>' .
            '<w:tc><w:tcPr><w:tcW w:w="' . $anchoIzquierdo . '" w:type="dxa"/></w:tcPr>' .
            '<w:p><w:pPr><w:jc w:val="left"/><w:spacing w:before="0" w:after="0"/></w:pPr>' .
            '<w:r><w:rPr><w:b/><w:color w:val="' . self::COLOR_PRIMARIO . '"/>' .
            '<w:sz w:val="13"/><w:szCs w:val="13"/></w:rPr>' .
            '<w:t>Grupo Porcayo · Sistema de Gestión Comercial</w:t></w:r></w:p></w:tc>' .
            '<w:tc><w:tcPr><w:tcW w:w="' . $anchoDerecho . '" w:type="dxa"/></w:tcPr>' .
            '<w:p><w:pPr><w:jc w:val="right"/><w:spacing w:before="0" w:after="0"/></w:pPr>' .
            '<w:r><w:rPr><w:color w:val="' . self::COLOR_SECUNDARIO . '"/><w:sz w:val="13"/><w:szCs w:val="13"/></w:rPr><w:t xml:space="preserve">Página </w:t></w:r>' .
            $this->campoPieXml('PAGE') .
            '<w:r><w:rPr><w:color w:val="' . self::COLOR_SECUNDARIO . '"/><w:sz w:val="13"/><w:szCs w:val="13"/></w:rPr><w:t xml:space="preserve"> de </w:t></w:r>' .
            $this->campoPieXml('NUMPAGES') .
            '</w:p></w:tc></w:tr></w:tbl></w:ftr>';

        for ($indice = 0; $indice < $zip->numFiles; $indice++) {
            $estadisticas = $zip->statIndex($indice);
            $nombre = is_array($estadisticas) ? (string)($estadisticas['name'] ?? '') : '';
            if (preg_match('#^word/footer\d*\.xml$#', $nombre) === 1) {
                $zip->addFromString($nombre, $pie);
            }
        }
    }

    private function campoPieXml($campo)
    {
        $campo = strtoupper(trim((string)$campo));
        return '<w:r><w:fldChar w:fldCharType="begin"/></w:r>' .
            '<w:r><w:instrText xml:space="preserve"> ' . $campo . ' </w:instrText></w:r>' .
            '<w:r><w:fldChar w:fldCharType="separate"/></w:r>' .
            '<w:r><w:rPr><w:color w:val="' . self::COLOR_SECUNDARIO . '"/>' .
            '<w:sz w:val="13"/><w:szCs w:val="13"/></w:rPr><w:t>1</w:t></w:r>' .
            '<w:r><w:fldChar w:fldCharType="end"/></w:r>';
    }

    private function xmlTexto($texto)
    {
        return htmlspecialchars((string)$texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function crearTituloSeccion(DOMDocument $documento, $texto)
    {
        return $this->crearParrafo(
            $documento,
            (string)$texto,
            [
                'tamano' => 20,
                'negrita' => true,
                'color' => self::COLOR_TEXTO,
                'antes' => 80,
                'despues' => 70,
                'mantener_siguiente' => true,
                'borde_izquierdo' => true
            ]
        );
    }

    private function crearSubtitulo(DOMDocument $documento, $texto)
    {
        return $this->crearParrafo(
            $documento,
            (string)$texto,
            [
                'tamano' => 17,
                'negrita' => true,
                'color' => self::COLOR_TEXTO,
                'antes' => 50,
                'despues' => 50,
                'mantener_siguiente' => true
            ]
        );
    }

    private function crearEspaciador(DOMDocument $documento, $despues)
    {
        return $this->crearParrafo(
            $documento,
            '',
            ['tamano' => 4, 'despues' => (int)$despues]
        );
    }

    private function crearTarjetasIndicadores(
        DOMDocument $documento,
        array $indicadores,
        $anchoUtil,
        $columnas
    ) {
        $columnas = max(1, (int)$columnas);
        $anchoBase = (int)floor($anchoUtil / $columnas);
        $anchos = array_fill(0, $columnas, $anchoBase);
        $anchos[$columnas - 1] += $anchoUtil - array_sum($anchos);
        $tabla = $this->crearTablaBase($documento, $anchoUtil, $anchos, true);

        foreach (array_chunk($indicadores, $columnas) as $grupo) {
            $fila = $this->crearFila($documento, false);
            foreach ($anchos as $indice => $ancho) {
                $indicador = $grupo[$indice] ?? ['etiqueta' => '', 'valor' => ''];
                $fila->appendChild($this->crearCeldaIndicador(
                    $documento,
                    (string)($indicador['valor'] ?? ''),
                    (string)($indicador['etiqueta'] ?? ''),
                    $ancho
                ));
            }
            $tabla->appendChild($fila);
        }

        return $tabla;
    }

    private function crearCeldaIndicador(DOMDocument $documento, $valor, $etiqueta, $ancho)
    {
        $celda = $this->w($documento, 'tc');
        $propiedades = $this->w($documento, 'tcPr');
        $anchoNodo = $this->w($documento, 'tcW');
        $this->attr($anchoNodo, 'w', 'w', 'w', (string)$ancho);
        $this->attr($anchoNodo, 'w', 'w', 'type', 'dxa');
        $propiedades->appendChild($anchoNodo);

        $relleno = $this->w($documento, 'shd');
        $this->attr($relleno, 'w', 'w', 'val', 'clear');
        $this->attr($relleno, 'w', 'w', 'fill', self::COLOR_FONDO);
        $propiedades->appendChild($relleno);

        $vertical = $this->w($documento, 'vAlign');
        $this->attr($vertical, 'w', 'w', 'val', 'center');
        $propiedades->appendChild($vertical);
        $celda->appendChild($propiedades);

        $celda->appendChild($this->crearParrafo(
            $documento,
            (string)$valor,
            [
                'tamano' => 22,
                'negrita' => true,
                'color' => self::COLOR_TEXTO,
                'alineacion' => 'center',
                'antes' => 45,
                'despues' => 20
            ]
        ));
        $celda->appendChild($this->crearParrafo(
            $documento,
            (string)$etiqueta,
            [
                'tamano' => 13,
                'color' => self::COLOR_SECUNDARIO,
                'alineacion' => 'center',
                'antes' => 0,
                'despues' => 45
            ]
        ));

        return $celda;
    }

    private function crearGraficaBarras(DOMDocument $documento, array $datos, $anchoUtil)
    {
        if (empty($datos)) {
            return $this->crearParrafo(
                $documento,
                'No hay información disponible.',
                ['tamano' => 15, 'color' => self::COLOR_SECUNDARIO, 'despues' => 60]
            );
        }

        $valores = array_map(static function ($dato) {
            return max(0, (int)($dato['valor'] ?? 0));
        }, $datos);
        $maximo = max(1, max($valores));
        $anchos = $this->anchos($anchoUtil, [32, 56, 12]);
        $tabla = $this->crearTablaBase($documento, $anchoUtil, $anchos, false);

        foreach ($datos as $dato) {
            $etiqueta = (string)($dato['etiqueta'] ?? '—');
            $valor = max(0, (int)($dato['valor'] ?? 0));
            $fila = $this->crearFila($documento, false);
            $fila->appendChild($this->crearCelda(
                $documento,
                $etiqueta,
                $anchos[0],
                ['tamano' => 14, 'color' => self::COLOR_TEXTO]
            ));

            $celdaBarra = $this->crearCelda(
                $documento,
                '',
                $anchos[1],
                ['tamano' => 4, 'color' => self::COLOR_TEXTO]
            );
            $parrafoExistente = $celdaBarra->getElementsByTagNameNS(self::W_NS, 'p')->item(0);
            if ($parrafoExistente instanceof DOMNode) {
                $celdaBarra->removeChild($parrafoExistente);
            }

            if ($valor <= 0) {
                $barra = $this->crearTablaBase($documento, $anchos[1], [$anchos[1]], false);
                $filaBarra = $this->crearFila($documento, false);
                $filaBarra->appendChild($this->crearCeldaBarra(
                    $documento,
                    $anchos[1],
                    self::COLOR_FONDO_PRIMARIO
                ));
                $barra->appendChild($filaBarra);
            } elseif ($valor >= $maximo) {
                $barra = $this->crearTablaBase($documento, $anchos[1], [$anchos[1]], false);
                $filaBarra = $this->crearFila($documento, false);
                $filaBarra->appendChild($this->crearCeldaBarra(
                    $documento,
                    $anchos[1],
                    self::COLOR_PRIMARIO
                ));
                $barra->appendChild($filaBarra);
            } else {
                $relleno = max(1, (int)round($anchos[1] * ($valor / $maximo)));
                $vacio = max(1, $anchos[1] - $relleno);
                $barra = $this->crearTablaBase(
                    $documento,
                    $anchos[1],
                    [$relleno, $vacio],
                    false
                );
                $filaBarra = $this->crearFila($documento, false);
                $filaBarra->appendChild($this->crearCeldaBarra(
                    $documento,
                    $relleno,
                    self::COLOR_PRIMARIO
                ));
                $filaBarra->appendChild($this->crearCeldaBarra(
                    $documento,
                    $vacio,
                    self::COLOR_FONDO_PRIMARIO
                ));
                $barra->appendChild($filaBarra);
            }

            $celdaBarra->appendChild($barra);
            $celdaBarra->appendChild($this->crearParrafo(
                $documento,
                '',
                ['tamano' => 4, 'despues' => 0]
            ));
            $fila->appendChild($celdaBarra);
            $fila->appendChild($this->crearCelda(
                $documento,
                (string)$valor,
                $anchos[2],
                [
                    'tamano' => 14,
                    'negrita' => true,
                    'color' => self::COLOR_TEXTO,
                    'alineacion' => 'right'
                ]
            ));
            $tabla->appendChild($fila);
        }

        return $tabla;
    }

    private function crearCeldaBarra(DOMDocument $documento, $ancho, $color)
    {
        $celda = $this->w($documento, 'tc');
        $propiedades = $this->w($documento, 'tcPr');
        $anchoNodo = $this->w($documento, 'tcW');
        $this->attr($anchoNodo, 'w', 'w', 'w', (string)$ancho);
        $this->attr($anchoNodo, 'w', 'w', 'type', 'dxa');
        $propiedades->appendChild($anchoNodo);

        $relleno = $this->w($documento, 'shd');
        $this->attr($relleno, 'w', 'w', 'val', 'clear');
        $this->attr($relleno, 'w', 'w', 'fill', (string)$color);
        $propiedades->appendChild($relleno);
        $celda->appendChild($propiedades);
        $celda->appendChild($this->crearParrafo(
            $documento,
            ' ',
            ['tamano' => 4, 'antes' => 0, 'despues' => 0]
        ));

        return $celda;
    }

    private function crearTablaSimple(DOMDocument $documento, array $filas, array $anchos)
    {
        $tabla = $this->crearTablaBase($documento, array_sum($anchos), $anchos);

        foreach ($filas as $indice => $valores) {
            $fila = $this->crearFila($documento, false);
            $rellenoFila = $indice % 2 === 0 ? 'FFFFFF' : self::COLOR_FONDO;

            foreach ($anchos as $columna => $ancho) {
                $fila->appendChild($this->crearCelda(
                    $documento,
                    (string)($valores[$columna] ?? ''),
                    $ancho,
                    [
                        'tamano' => 16,
                        'negrita' => $columna === 0,
                        'color' => $columna === 0 ? self::COLOR_PRIMARIO : self::COLOR_TEXTO,
                        'relleno' => $columna === 0 ? self::COLOR_FONDO_PRIMARIO : $rellenoFila
                    ]
                ));
            }

            $tabla->appendChild($fila);
        }

        return $tabla;
    }

    private function crearTablaDetalle(DOMDocument $documento, array $encabezados, array $filas, array $anchos)
    {
        $tabla = $this->crearTablaBase($documento, array_sum($anchos), $anchos);
        $filaCabecera = $this->crearFila($documento, true);

        foreach ($encabezados as $indice => $encabezado) {
            $filaCabecera->appendChild($this->crearCelda(
                $documento,
                (string)$encabezado,
                $anchos[$indice] ?? 900,
                [
                    'tamano' => 14,
                    'negrita' => true,
                    'color' => 'FFFFFF',
                    'relleno' => self::COLOR_PRIMARIO,
                    'alineacion' => 'center'
                ]
            ));
        }
        $tabla->appendChild($filaCabecera);

        foreach ($filas as $indiceFila => $valores) {
            $fila = $this->crearFila($documento, false);
            $rellenoFila = $indiceFila % 2 === 0 ? 'FFFFFF' : self::COLOR_FONDO;

            foreach ($anchos as $indice => $ancho) {
                $fila->appendChild($this->crearCelda(
                    $documento,
                    (string)($valores[$indice] ?? ''),
                    $ancho,
                    [
                        'tamano' => 13,
                        'color' => self::COLOR_TEXTO,
                        'relleno' => $rellenoFila
                    ]
                ));
            }
            $tabla->appendChild($fila);
        }

        return $tabla;
    }

    private function crearTablaBase(DOMDocument $documento, $anchoTotal, array $anchos, $bordes = true)
    {
        $tabla = $this->w($documento, 'tbl');
        $propiedades = $this->w($documento, 'tblPr');
        $ancho = $this->w($documento, 'tblW');
        $this->attr($ancho, 'w', 'w', 'type', 'dxa');
        $this->attr($ancho, 'w', 'w', 'w', (string)$anchoTotal);
        $propiedades->appendChild($ancho);

        $layout = $this->w($documento, 'tblLayout');
        $this->attr($layout, 'w', 'w', 'type', 'fixed');
        $propiedades->appendChild($layout);

        if ($bordes) {
            $bordesNodo = $this->w($documento, 'tblBorders');
            foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $lado) {
                $borde = $this->w($documento, $lado);
                $this->attr($borde, 'w', 'w', 'val', 'single');
                $this->attr($borde, 'w', 'w', 'sz', '2');
                $this->attr($borde, 'w', 'w', 'color', self::COLOR_BORDE);
                $bordesNodo->appendChild($borde);
            }
            $propiedades->appendChild($bordesNodo);
        }
        $tabla->appendChild($propiedades);

        $grid = $this->w($documento, 'tblGrid');
        foreach ($anchos as $anchoColumna) {
            $columna = $this->w($documento, 'gridCol');
            $this->attr($columna, 'w', 'w', 'w', (string)$anchoColumna);
            $grid->appendChild($columna);
        }
        $tabla->appendChild($grid);

        return $tabla;
    }

    private function crearFila(DOMDocument $documento, $encabezado)
    {
        $fila = $this->w($documento, 'tr');
        $propiedades = $this->w($documento, 'trPr');
        $propiedades->appendChild($this->w($documento, 'cantSplit'));

        if ($encabezado) {
            $repetir = $this->w($documento, 'tblHeader');
            $this->attr($repetir, 'w', 'w', 'val', '1');
            $propiedades->appendChild($repetir);
        }

        $fila->appendChild($propiedades);
        return $fila;
    }

    private function crearCelda(DOMDocument $documento, $texto, $ancho, array $opciones = [])
    {
        $celda = $this->w($documento, 'tc');
        $propiedades = $this->w($documento, 'tcPr');
        $anchoNodo = $this->w($documento, 'tcW');
        $this->attr($anchoNodo, 'w', 'w', 'w', (string)$ancho);
        $this->attr($anchoNodo, 'w', 'w', 'type', 'dxa');
        $propiedades->appendChild($anchoNodo);

        if (!empty($opciones['relleno'])) {
            $relleno = $this->w($documento, 'shd');
            $this->attr($relleno, 'w', 'w', 'val', 'clear');
            $this->attr($relleno, 'w', 'w', 'fill', (string)$opciones['relleno']);
            $propiedades->appendChild($relleno);
        }

        $vertical = $this->w($documento, 'vAlign');
        $this->attr($vertical, 'w', 'w', 'val', 'center');
        $propiedades->appendChild($vertical);
        $celda->appendChild($propiedades);

        $celda->appendChild($this->crearParrafo(
            $documento,
            $texto,
            [
                'tamano' => $opciones['tamano'] ?? 15,
                'negrita' => $opciones['negrita'] ?? false,
                'color' => $opciones['color'] ?? self::COLOR_TEXTO,
                'alineacion' => $opciones['alineacion'] ?? 'left',
                'antes' => 25,
                'despues' => 25
            ]
        ));

        return $celda;
    }

    private function crearParrafo(DOMDocument $documento, $texto, array $opciones = [])
    {
        $parrafo = $this->w($documento, 'p');
        $propiedades = $this->w($documento, 'pPr');
        $espaciado = $this->w($documento, 'spacing');
        $this->attr($espaciado, 'w', 'w', 'before', (string)($opciones['antes'] ?? 0));
        $this->attr($espaciado, 'w', 'w', 'after', (string)($opciones['despues'] ?? 0));
        $this->attr($espaciado, 'w', 'w', 'line', '240');
        $this->attr($espaciado, 'w', 'w', 'lineRule', 'auto');
        $propiedades->appendChild($espaciado);

        if (!empty($opciones['borde_izquierdo'])) {
            $bordes = $this->w($documento, 'pBdr');
            $bordeIzquierdo = $this->w($documento, 'left');
            $this->attr($bordeIzquierdo, 'w', 'w', 'val', 'single');
            $this->attr($bordeIzquierdo, 'w', 'w', 'sz', '18');
            $this->attr($bordeIzquierdo, 'w', 'w', 'space', '8');
            $this->attr($bordeIzquierdo, 'w', 'w', 'color', self::COLOR_PRIMARIO);
            $bordes->appendChild($bordeIzquierdo);
            $propiedades->appendChild($bordes);
        }

        if (!empty($opciones['alineacion'])) {
            $alineacion = $this->w($documento, 'jc');
            $this->attr($alineacion, 'w', 'w', 'val', (string)$opciones['alineacion']);
            $propiedades->appendChild($alineacion);
        }

        if (!empty($opciones['mantener_siguiente'])) {
            $propiedades->appendChild($this->w($documento, 'keepNext'));
        }

        $parrafo->appendChild($propiedades);
        $run = $this->w($documento, 'r');
        $runPropiedades = $this->w($documento, 'rPr');

        if (!empty($opciones['negrita'])) {
            $runPropiedades->appendChild($this->w($documento, 'b'));
        }

        $color = $this->w($documento, 'color');
        $this->attr($color, 'w', 'w', 'val', (string)($opciones['color'] ?? self::COLOR_TEXTO));
        $runPropiedades->appendChild($color);

        $tamano = (int)($opciones['tamano'] ?? 18);
        $sz = $this->w($documento, 'sz');
        $this->attr($sz, 'w', 'w', 'val', (string)$tamano);
        $runPropiedades->appendChild($sz);
        $szCs = $this->w($documento, 'szCs');
        $this->attr($szCs, 'w', 'w', 'val', (string)$tamano);
        $runPropiedades->appendChild($szCs);
        $run->appendChild($runPropiedades);

        $textoNodo = $this->w($documento, 't');
        $textoNodo->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');
        $textoNodo->nodeValue = $this->textoXml($texto);
        $run->appendChild($textoNodo);
        $parrafo->appendChild($run);

        return $parrafo;
    }

    private function anchos($anchoTotal, array $porcentajes)
    {
        $anchos = [];
        $acumulado = 0;
        $ultimo = count($porcentajes) - 1;

        foreach ($porcentajes as $indice => $porcentaje) {
            if ($indice === $ultimo) {
                $ancho = max(1, $anchoTotal - $acumulado);
            } else {
                $ancho = max(1, (int)round($anchoTotal * ((float)$porcentaje / 100)));
                $acumulado += $ancho;
            }
            $anchos[] = $ancho;
        }

        return $anchos;
    }

    private function obtenerAnchoUtil(DOMXPath $xpath, DOMElement $cuerpo)
    {
        $sectPr = $xpath->query('./w:sectPr', $cuerpo)->item(0);
        if (!$sectPr instanceof DOMElement) {
            $sectPr = $xpath->query('.//w:sectPr[last()]', $cuerpo)->item(0);
        }

        $anchoPagina = 12240;
        $margenIzquierdo = 1440;
        $margenDerecho = 1440;

        if ($sectPr instanceof DOMElement) {
            $pgSz = $xpath->query('./w:pgSz', $sectPr)->item(0);
            $pgMar = $xpath->query('./w:pgMar', $sectPr)->item(0);

            if ($pgSz instanceof DOMElement) {
                $valor = (int)$pgSz->getAttributeNS(self::W_NS, 'w');
                if ($valor > 0) {
                    $anchoPagina = $valor;
                }
            }

            if ($pgMar instanceof DOMElement) {
                $izquierdo = (int)$pgMar->getAttributeNS(self::W_NS, 'left');
                $derecho = (int)$pgMar->getAttributeNS(self::W_NS, 'right');
                $margenIzquierdo = $izquierdo >= 0 ? $izquierdo : $margenIzquierdo;
                $margenDerecho = $derecho >= 0 ? $derecho : $margenDerecho;
            }
        }

        return max(7200, $anchoPagina - $margenIzquierdo - $margenDerecho);
    }

    private function formatearFechaHora($valor, $vacio)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return (string)$vacio;
        }

        try {
            $fecha = new DateTimeImmutable($valor);
            return $fecha->format('d/m/Y H:i');
        } catch (Exception $error) {
            return (string)$vacio;
        }
    }

    private function w(DOMDocument $documento, $nombre)
    {
        return $documento->createElementNS(self::W_NS, 'w:' . $nombre);
    }

    private function attr(DOMElement $elemento, $prefijo, $nsClave, $nombre, $valor)
    {
        $namespace = $nsClave === 'w' ? self::W_NS : self::XML_NS;
        $elemento->setAttributeNS($namespace, $prefijo . ':' . $nombre, (string)$valor);
    }

    private function textoXml($texto)
    {
        $texto = (string)$texto;
        $limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto);
        return $limpio === null ? '' : $limpio;
    }

    private function convertirAPdf($rutaDocx, $directorioSalida)
    {
        $conversor = $this->detectarConversor();
        if (!($conversor['ok'] ?? false)) {
            return $this->error(
                'No se encontró un conversor DOCX a PDF disponible.',
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
            $windows = getenv('SystemRoot');
            $rutaPowerShell = ($windows ? rtrim($windows, '\\/') : 'C:\\Windows') .
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
                'No fue posible convertir el reporte administrativo a PDF con LibreOffice.',
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
                'No fue posible preparar la conversión del reporte administrativo.',
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
                'No fue posible convertir el reporte administrativo a PDF con Microsoft Word.',
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
            return ['codigo' => 1, 'salida' => 'proc_open no pudo iniciar el proceso.'];
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
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'mensaje_tecnico' => (string)$detalle
        ];
    }
}
