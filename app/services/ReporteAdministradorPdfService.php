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

        $elementos[] = $this->crearParrafo(
            $documento,
            'Reporte Administrativo de Usuarios',
            ['tamano' => 28, 'negrita' => true, 'color' => self::COLOR_TEXTO, 'despues' => 100]
        );
        $elementos[] = $this->crearParrafo(
            $documento,
            'Usuarios, carga de seguimiento y pendientes operativos',
            ['tamano' => 18, 'color' => self::COLOR_SECUNDARIO, 'despues' => 40]
        );
        $elementos[] = $this->crearParrafo(
            $documento,
            'Fecha de generación: ' . ($fechaGeneracion !== '' ? $fechaGeneracion : date('d/m/Y H:i')),
            ['tamano' => 16, 'color' => self::COLOR_SECUNDARIO, 'despues' => 160]
        );

        $elementos[] = $this->crearTituloSeccion($documento, 'Resumen administrativo');
        $filasResumen = [
            ['Usuarios registrados', (string)((int)($resumen['usuarios_registrados'] ?? 0))],
            ['Usuarios activos', (string)((int)($resumen['usuarios_activos'] ?? 0))],
            ['Usuarios inactivos', (string)((int)($resumen['usuarios_inactivos'] ?? 0))],
            ['Roles registrados', (string)((int)($resumen['roles_registrados'] ?? 0))],
            ['Seguimientos activos', (string)((int)($resumen['total_seguimientos'] ?? 0))],
            ['Acciones programadas', (string)((int)($resumen['acciones_pendientes'] ?? 0))],
            ['Acciones vencidas', (string)((int)($resumen['acciones_vencidas'] ?? 0))],
            ['Seguimientos que requieren atención', (string)((int)($resumen['requieren_atencion'] ?? 0))]
        ];
        $elementos[] = $this->crearTablaSimple(
            $documento,
            $filasResumen,
            [(int)round($anchoUtil * 0.72), (int)round($anchoUtil * 0.28)]
        );
        $elementos[] = $this->crearEspaciador($documento, 120);

        $elementos[] = $this->crearTituloSeccion($documento, 'Usuarios del sistema');
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

        $elementos[] = $this->crearTituloSeccion($documento, 'Carga de seguimiento por usuario');
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
        $elementos[] = $this->crearTablaDetalle(
            $documento,
            ['Usuario', 'Rol', 'Seguimientos', 'Pendientes', 'Vencidas', 'Sin actividad', '> 7 días'],
            $filasCarga,
            $this->anchos($anchoUtil, [18, 18, 13, 13, 12, 14, 12])
        );
        $elementos[] = $this->crearEspaciador($documento, 120);

        $elementos[] = $this->crearTituloSeccion($documento, 'Pendientes y seguimientos que requieren atención');
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

        return $elementos;
    }

    private function crearTituloSeccion(DOMDocument $documento, $texto)
    {
        return $this->crearParrafo(
            $documento,
            (string)$texto,
            [
                'tamano' => 21,
                'negrita' => true,
                'color' => self::COLOR_PRIMARIO,
                'antes' => 80,
                'despues' => 80,
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
                    'color' => self::COLOR_PRIMARIO,
                    'relleno' => self::COLOR_FONDO_PRIMARIO,
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

    private function crearTablaBase(DOMDocument $documento, $anchoTotal, array $anchos)
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

        $bordesNodo = $this->w($documento, 'tblBorders');
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $lado) {
            $borde = $this->w($documento, $lado);
            $this->attr($borde, 'w', 'w', 'val', 'single');
            $this->attr($borde, 'w', 'w', 'sz', '2');
            $this->attr($borde, 'w', 'w', 'color', self::COLOR_BORDE);
            $bordesNodo->appendChild($borde);
        }
        $propiedades->appendChild($bordesNodo);
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
