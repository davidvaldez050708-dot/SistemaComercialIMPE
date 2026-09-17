<?php

class ReporteSeguimientoVinculacionPdfService
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
    private const V_NS = 'urn:schemas-microsoft-com:vml';
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
            'reportes' . DIRECTORY_SEPARATOR . 'seguimiento_vinculacion' . DIRECTORY_SEPARATOR .
            'Plantilla_Reporte_Seguimiento.docx';
    }

    public function generar(array $datosReporte)
    {
        if (!file_exists($this->templatePath) || !is_file($this->templatePath) || !is_readable($this->templatePath)) {
            return $this->error(
                'No fue posible localizar la plantilla predeterminada del reporte.',
                'La plantilla no existe, no es un archivo o no tiene permiso de lectura: ' . $this->templatePath
            );
        }

        if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
            return $this->error(
                'No fue posible preparar el documento del reporte.',
                'Se requieren ZipArchive y DOMDocument para procesar la copia temporal del DOCX.'
            );
        }

        if (!function_exists('proc_open')) {
            return $this->error(
                'No fue posible convertir el reporte a PDF.',
                'proc_open no está disponible en este entorno.'
            );
        }

        $nombreBase = $this->nombreArchivo($datosReporte);
        $directorioTemporal = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' .
            DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'reportes' .
            DIRECTORY_SEPARATOR . 'seguimiento_vinculacion' . DIRECTORY_SEPARATOR .
            'reporte_' . bin2hex(random_bytes(6));

        if (!mkdir($directorioTemporal, 0775, true) && !is_dir($directorioTemporal)) {
            return $this->error(
                'No fue posible preparar el reporte.',
                'No se pudo crear el directorio temporal: ' . $directorioTemporal
            );
        }

        $rutaDocx = $directorioTemporal . DIRECTORY_SEPARATOR . $nombreBase . '.docx';

        try {
            if (!copy($this->templatePath, $rutaDocx)) {
                return $this->error(
                    'No fue posible preparar la plantilla predeterminada del reporte.',
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
                    'No fue posible obtener el PDF generado.',
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
                'No fue posible generar el PDF del reporte.',
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
                'No fue posible abrir la plantilla predeterminada del reporte.',
                'ZipArchive::open devolvió el código: ' . (string)$abierto
            );
        }

        try {
            $xml = $zip->getFromName('word/document.xml');

            if ($xml === false || trim((string)$xml) === '') {
                return $this->error(
                    'La plantilla predeterminada del reporte no contiene un documento válido.',
                    'No se encontró word/document.xml dentro de la copia temporal.'
                );
            }

            $documento = new DOMDocument('1.0', 'UTF-8');
            $documento->preserveWhiteSpace = true;
            $documento->formatOutput = false;

            if (!@$documento->loadXML($xml)) {
                return $this->error(
                    'La plantilla predeterminada del reporte no contiene un documento válido.',
                    'word/document.xml no pudo interpretarse como XML.'
                );
            }

            $xpath = new DOMXPath($documento);
            $xpath->registerNamespace('w', self::W_NS);
            $cuerpo = $xpath->query('/w:document/w:body')->item(0);

            if (!$cuerpo instanceof DOMElement) {
                return $this->error(
                    'La plantilla predeterminada del reporte no tiene una estructura compatible.',
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
                    'No fue posible incorporar el contenido al reporte.',
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
        $resumenFiltros = $datosReporte['resumen_filtros'] ?? [];
        $resumenReporte = $datosReporte['resumen_reporte'] ?? [];
        $seguimientos = $datosReporte['seguimientos'] ?? [];
        $evolucionActividad = $datosReporte['evolucion_actividad'] ?? [];
        $etiquetasEstatus = $datosReporte['etiquetas_estatus'] ?? [];
        $fechaGeneracion = trim((string)($datosReporte['fecha_generacion'] ?? ''));
        $elementos = [];

        $elementos[] = $this->crearParrafo(
            $documento,
            'Reporte de Seguimiento de Vinculación',
            ['tamano' => 28, 'negrita' => true, 'color' => self::COLOR_TEXTO, 'despues' => 100]
        );
        $elementos[] = $this->crearParrafo(
            $documento,
            'Fecha de generación: ' . ($fechaGeneracion !== '' ? $fechaGeneracion : date('d/m/Y H:i')),
            ['tamano' => 18, 'color' => self::COLOR_SECUNDARIO, 'despues' => 160]
        );

        $elementos[] = $this->crearTituloSeccion($documento, 'Filtros utilizados');
        $filasFiltros = [];
        foreach ($resumenFiltros as $nombre => $valor) {
            $filasFiltros[] = [(string)$nombre, (string)$valor];
        }
        $elementos[] = $this->crearTablaSimple(
            $documento,
            $filasFiltros,
            [(int)round($anchoUtil * 0.30), (int)round($anchoUtil * 0.70)],
            false
        );
        $elementos[] = $this->crearEspaciador($documento, 100);

        $elementos[] = $this->crearTituloSeccion($documento, 'Resumen / Indicadores');
        $filasIndicadores = [
            ['Total de seguimientos', (string)((int)($resumenReporte['total'] ?? 0))],
            ['Sin actividad registrada', (string)((int)($resumenReporte['sin_actividad'] ?? 0))],
            ['Más de 7 días sin actividad', (string)((int)($resumenReporte['mas_7_dias'] ?? 0))]
        ];

        foreach (($resumenReporte['por_estatus'] ?? []) as $codigo => $total) {
            $filasIndicadores[] = [
                (string)($etiquetasEstatus[$codigo] ?? $codigo),
                (string)((int)$total)
            ];
        }

        $elementos[] = $this->crearTablaSimple(
            $documento,
            $filasIndicadores,
            [(int)round($anchoUtil * 0.78), (int)round($anchoUtil * 0.22)],
            false
        );
        $elementos[] = $this->crearEspaciador($documento, 100);

        $elementos[] = $this->crearTituloSeccion($documento, 'Seguimientos por estatus');
        $elementos = array_merge(
            $elementos,
            $this->crearGraficaBarras(
                $documento,
                $resumenReporte['por_estatus'] ?? [],
                $anchoUtil,
                function ($codigo) use ($etiquetasEstatus) {
                    return (string)($etiquetasEstatus[$codigo] ?? $codigo);
                }
            )
        );
        $elementos[] = $this->crearEspaciador($documento, 90);

        $elementos[] = $this->crearTituloSeccion($documento, 'Seguimientos por municipio');
        $elementos = array_merge(
            $elementos,
            $this->crearGraficaBarras(
                $documento,
                $resumenReporte['por_municipio'] ?? [],
                $anchoUtil,
                function ($municipio) {
                    return (string)$municipio;
                }
            )
        );
        $elementos[] = $this->crearEspaciador($documento, 120);

        $elementos[] = $this->crearTituloSeccion($documento, 'Evolución de la actividad');
        $elementos[] = $this->crearParrafo(
            $documento,
            'Actividades registradas durante el periodo seleccionado.',
            ['tamano' => 17, 'color' => self::COLOR_SECUNDARIO, 'despues' => 90, 'mantener_siguiente' => true]
        );

        $mayorActividad = $evolucionActividad['mayor'] ?? ['etiqueta' => '—', 'total' => 0];
        $menorActividad = $evolucionActividad['menor'] ?? ['etiqueta' => '—', 'total' => 0];
        $variacionActividad = 'Sin comparación disponible';

        if (!empty($evolucionActividad['comparacion_disponible'])) {
            $variacion = (float)($evolucionActividad['variacion'] ?? 0);
            $variacionActividad = ($variacion > 0 ? '+' : '') . number_format($variacion, 1) . '%';
        }

        $filasEvolucion = [
            ['Actividades en el periodo', (string)((int)($evolucionActividad['total'] ?? 0))],
            [
                'Mayor actividad',
                (string)($mayorActividad['etiqueta'] ?? '—') . ' · ' .
                (int)($mayorActividad['total'] ?? 0) . ' actividades'
            ],
            [
                'Menor actividad',
                (string)($menorActividad['etiqueta'] ?? '—') . ' · ' .
                (int)($menorActividad['total'] ?? 0) . ' actividades'
            ],
            ['Variación vs. periodo anterior', $variacionActividad]
        ];
        $elementos[] = $this->crearTablaSimple(
            $documento,
            $filasEvolucion,
            [(int)round($anchoUtil * 0.38), (int)round($anchoUtil * 0.62)],
            false
        );
        $elementos[] = $this->crearEspaciador($documento, 80);

        $periodosActividad = $evolucionActividad['periodos'] ?? [];
        $elementos = array_merge(
            $elementos,
            $this->crearGraficaLineaActividad($documento, $periodosActividad, $anchoUtil)
        );

        if (!empty($evolucionActividad['sin_datos'])) {
            $elementos[] = $this->crearParrafo(
                $documento,
                'No se registraron actividades durante el periodo seleccionado.',
                ['tamano' => 17, 'color' => self::COLOR_SECUNDARIO, 'despues' => 70]
            );
        }

        $elementos[] = $this->crearEspaciador($documento, 120);
        $elementos[] = $this->crearTituloSeccion($documento, 'Detalle de seguimientos');

        if (empty($seguimientos)) {
            $elementos[] = $this->crearParrafo(
                $documento,
                'No se encontraron seguimientos con los criterios seleccionados.',
                ['tamano' => 18, 'color' => self::COLOR_SECUNDARIO, 'despues' => 80]
            );
            return $elementos;
        }

        $encabezados = [
            'Institución',
            'Municipio',
            'Responsable',
            'Etapa / Estatus',
            'Última actividad',
            'Días sin actividad',
            'Próxima acción',
            'Folio'
        ];
        $filasDetalle = [];

        foreach ($seguimientos as $seguimiento) {
            $ultimaActividad = trim((string)($seguimiento['ultima_interaccion_at'] ?? '')) !== ''
                ? (string)($seguimiento['ultima_actividad_label'] ?? '—')
                : 'Sin actividad registrada';
            $dias = $seguimiento['dias_sin_actividad'] ?? null;

            $filasDetalle[] = [
                (string)($seguimiento['nombre_entidad'] ?? '—'),
                trim((string)($seguimiento['municipio'] ?? '')) !== ''
                    ? (string)$seguimiento['municipio']
                    : '—',
                trim((string)($seguimiento['responsable_nombre'] ?? '')) !== ''
                    ? (string)$seguimiento['responsable_nombre']
                    : '—',
                (string)($seguimiento['estado_label'] ?? 'Sin estado'),
                $ultimaActividad,
                $dias === null ? '—' : (string)((int)$dias),
                (string)($seguimiento['proxima_accion_label'] ?? '—'),
                trim((string)($seguimiento['folio'] ?? '')) !== ''
                    ? (string)$seguimiento['folio']
                    : '—'
            ];
        }

        $proporciones = [0.22, 0.11, 0.15, 0.11, 0.13, 0.07, 0.14, 0.07];
        $anchos = array_map(function ($proporcion) use ($anchoUtil) {
            return max(240, (int)round($anchoUtil * $proporcion));
        }, $proporciones);
        $elementos[] = $this->crearTablaDetalle($documento, $encabezados, $filasDetalle, $anchos);

        return $elementos;
    }

    private function crearTituloSeccion(DOMDocument $documento, $texto)
    {
        return $this->crearParrafo(
            $documento,
            $texto,
            [
                'tamano' => 20,
                'negrita' => true,
                'color' => self::COLOR_PRIMARIO,
                'antes' => 80,
                'despues' => 70,
                'mantener_siguiente' => true
            ]
        );
    }

    private function crearEspaciador(DOMDocument $documento, $despues)
    {
        return $this->crearParrafo($documento, '', ['tamano' => 6, 'despues' => (int)$despues]);
    }

    private function crearSaltoPagina(DOMDocument $documento)
    {
        $parrafo = $this->w($documento, 'p');
        $run = $this->w($documento, 'r');
        $salto = $this->w($documento, 'br');
        $this->attr($salto, 'w', 'w', 'type', 'page');
        $run->appendChild($salto);
        $parrafo->appendChild($run);
        return $parrafo;
    }

    private function crearGraficaBarras(
        DOMDocument $documento,
        array $datos,
        $anchoUtil,
        callable $etiquetar
    ) {
        if (empty($datos)) {
            return [
                $this->crearParrafo(
                    $documento,
                    'No hay información disponible para los criterios seleccionados.',
                    ['tamano' => 17, 'color' => self::COLOR_SECUNDARIO, 'despues' => 60]
                )
            ];
        }

        $maximo = max(1, max(array_map('intval', $datos)));
        $anchoEtiqueta = (int)round($anchoUtil * 0.34);
        $anchoBarra = (int)round($anchoUtil * 0.53);
        $anchoCantidad = max(360, $anchoUtil - $anchoEtiqueta - $anchoBarra);
        $tabla = $this->crearTablaBase(
            $documento,
            $anchoUtil,
            [$anchoEtiqueta, $anchoBarra, $anchoCantidad],
            false
        );

        foreach ($datos as $clave => $total) {
            $total = (int)$total;
            $relleno = max(1, (int)round($anchoBarra * ($total / $maximo)));
            $vacio = max(1, $anchoBarra - $relleno);
            $fila = $this->crearFila($documento, false);
            $fila->appendChild($this->crearCelda(
                $documento,
                (string)$etiquetar($clave),
                $anchoEtiqueta,
                ['tamano' => 16, 'color' => self::COLOR_TEXTO, 'borde' => false]
            ));

            $celdaBarra = $this->crearCelda($documento, '', $anchoBarra, ['borde' => false]);
            $parrafoExistente = $celdaBarra->getElementsByTagNameNS(self::W_NS, 'p')->item(0);
            if ($parrafoExistente instanceof DOMNode) {
                $celdaBarra->removeChild($parrafoExistente);
            }
            $barra = $this->crearTablaBase(
                $documento,
                $anchoBarra,
                [$relleno, $vacio],
                false
            );
            $filaBarra = $this->crearFila($documento, false);
            $filaBarra->appendChild($this->crearCelda(
                $documento,
                '',
                $relleno,
                ['borde' => false, 'relleno' => self::COLOR_PRIMARIO, 'alto' => 180]
            ));
            $filaBarra->appendChild($this->crearCelda(
                $documento,
                '',
                $vacio,
                ['borde' => false, 'relleno' => self::COLOR_FONDO_PRIMARIO, 'alto' => 180]
            ));
            $barra->appendChild($filaBarra);
            $celdaBarra->appendChild($barra);
            $celdaBarra->appendChild($this->crearParrafo($documento, '', ['tamano' => 4, 'despues' => 0]));
            $fila->appendChild($celdaBarra);
            $fila->appendChild($this->crearCelda(
                $documento,
                (string)$total,
                $anchoCantidad,
                ['tamano' => 16, 'negrita' => true, 'alineacion' => 'right', 'borde' => false]
            ));
            $tabla->appendChild($fila);
        }

        return [$tabla];
    }

    private function crearGraficaLineaActividad(DOMDocument $documento, array $periodos, $anchoUtil)
    {
        if (empty($periodos)) {
            return [
                $this->crearParrafo(
                    $documento,
                    'No se registraron actividades durante el periodo seleccionado.',
                    ['tamano' => 17, 'color' => self::COLOR_SECUNDARIO, 'despues' => 60]
                )
            ];
        }

        $anchoCoordenadas = 1000;
        $altoCoordenadas = 340;
        $altoRenderizadoPuntos = 260;
        $izquierda = 76;
        $derecha = 972;
        $superior = 24;
        $inferior = 258;
        $anchoGrafica = $derecha - $izquierda;
        $altoGrafica = $inferior - $superior;
        $totales = array_map(static function ($periodo) {
            return max(0, (int)($periodo['total'] ?? 0));
        }, $periodos);
        $maximoDatos = max(0, max($totales));
        $escalaMaxima = max(4, (int)(ceil(max(1, $maximoDatos) / 4) * 4));
        $cantidadPeriodos = count($periodos);
        $pasoX = $cantidadPeriodos > 1
            ? $anchoGrafica / ($cantidadPeriodos - 1)
            : 0;
        $puntos = [];

        foreach ($periodos as $indice => $periodo) {
            $x = $cantidadPeriodos > 1
                ? $izquierda + ($pasoX * $indice)
                : $izquierda + ($anchoGrafica / 2);
            $total = max(0, (int)($periodo['total'] ?? 0));
            $y = $inferior - (($total / $escalaMaxima) * $altoGrafica);
            $puntos[] = [
                'x' => $x,
                'y' => $y,
                'total' => $total,
                'etiqueta' => (string)($periodo['etiqueta'] ?? '')
            ];
        }

        $parrafo = $this->w($documento, 'p');
        $propiedades = $this->w($documento, 'pPr');
        $alineacion = $this->w($documento, 'jc');
        $this->attr($alineacion, 'w', 'w', 'val', 'center');
        $propiedades->appendChild($alineacion);
        $espaciado = $this->w($documento, 'spacing');
        $this->attr($espaciado, 'w', 'w', 'before', '0');
        $this->attr($espaciado, 'w', 'w', 'after', '80');
        $this->attr($espaciado, 'w', 'w', 'line', (string)((int)round($altoRenderizadoPuntos * 20)));
        $this->attr($espaciado, 'w', 'w', 'lineRule', 'exact');
        $propiedades->appendChild($espaciado);
        $parrafo->appendChild($propiedades);

        $run = $this->w($documento, 'r');
        $pict = $this->w($documento, 'pict');
        $grupo = $documento->createElementNS(self::V_NS, 'v:group');
        $grupo->setAttribute('coordorigin', '0,0');
        $grupo->setAttribute('coordsize', $anchoCoordenadas . ',' . $altoCoordenadas);
        $anchoPuntos = max(360, min(520, round($anchoUtil / 20, 2)));
        $grupo->setAttribute(
            'style',
            'position:relative;width:' . $anchoPuntos . 'pt;height:' . $altoRenderizadoPuntos . 'pt;'
        );

        for ($nivel = 0; $nivel <= 4; $nivel++) {
            $y = $superior + (($altoGrafica / 4) * $nivel);
            $valor = (int)round($escalaMaxima * (1 - ($nivel / 4)));
            $grupo->appendChild($this->crearVmlLinea(
                $documento,
                $izquierda,
                $y,
                $derecha,
                $y,
                self::COLOR_BORDE,
                '0.6pt'
            ));
            $grupo->appendChild($this->crearVmlTexto(
                $documento,
                4,
                $y - 11,
                60,
                22,
                (string)$valor,
                14,
                'right',
                self::COLOR_SECUNDARIO
            ));
        }

        $grupo->appendChild($this->crearVmlLinea(
            $documento,
            $izquierda,
            $superior,
            $izquierda,
            $inferior,
            self::COLOR_SECUNDARIO,
            '0.8pt'
        ));
        $grupo->appendChild($this->crearVmlLinea(
            $documento,
            $izquierda,
            $inferior,
            $derecha,
            $inferior,
            self::COLOR_SECUNDARIO,
            '0.8pt'
        ));

        for ($indice = 1; $indice < count($puntos); $indice++) {
            $anterior = $puntos[$indice - 1];
            $actual = $puntos[$indice];
            $grupo->appendChild($this->crearVmlLinea(
                $documento,
                $anterior['x'],
                $anterior['y'],
                $actual['x'],
                $actual['y'],
                self::COLOR_PRIMARIO,
                '2.2pt'
            ));
        }

        $saltoEtiquetas = max(1, (int)ceil($cantidadPeriodos / 8));
        $mostrarValores = $cantidadPeriodos <= 16;

        foreach ($puntos as $indice => $punto) {
            $circulo = $documento->createElementNS(self::V_NS, 'v:oval');
            $circulo->setAttribute(
                'style',
                'position:absolute;left:' . number_format($punto['x'] - 5, 2, '.', '') .
                ';top:' . number_format($punto['y'] - 5, 2, '.', '') .
                ';width:10;height:10;'
            );
            $circulo->setAttribute('fillcolor', '#' . self::COLOR_PRIMARIO);
            $circulo->setAttribute('strokecolor', '#FFFFFF');
            $circulo->setAttribute('strokeweight', '1.5pt');
            $grupo->appendChild($circulo);

            if ($mostrarValores) {
                $grupo->appendChild($this->crearVmlTexto(
                    $documento,
                    $punto['x'] - 25,
                    max(0, $punto['y'] - 28),
                    50,
                    18,
                    (string)$punto['total'],
                    13,
                    'center',
                    self::COLOR_TEXTO,
                    true
                ));
            }

            if ($indice % $saltoEtiquetas === 0 || $indice === $cantidadPeriodos - 1) {
                $grupo->appendChild($this->crearVmlTexto(
                    $documento,
                    $punto['x'] - 55,
                    278,
                    110,
                    36,
                    $punto['etiqueta'],
                    13,
                    'center',
                    self::COLOR_SECUNDARIO
                ));
            }
        }

        $pict->appendChild($grupo);
        $run->appendChild($pict);
        $parrafo->appendChild($run);

        return [$parrafo];
    }

    private function crearVmlLinea(
        DOMDocument $documento,
        $x1,
        $y1,
        $x2,
        $y2,
        $color,
        $grosor
    ) {
        $linea = $documento->createElementNS(self::V_NS, 'v:line');
        $linea->setAttribute(
            'from',
            number_format((float)$x1, 2, '.', '') . ',' . number_format((float)$y1, 2, '.', '')
        );
        $linea->setAttribute(
            'to',
            number_format((float)$x2, 2, '.', '') . ',' . number_format((float)$y2, 2, '.', '')
        );
        $linea->setAttribute('strokecolor', '#' . (string)$color);
        $linea->setAttribute('strokeweight', (string)$grosor);
        return $linea;
    }

    private function crearVmlTexto(
        DOMDocument $documento,
        $x,
        $y,
        $ancho,
        $alto,
        $texto,
        $tamano,
        $alineacion,
        $color,
        $negrita = false
    ) {
        $rectangulo = $documento->createElementNS(self::V_NS, 'v:rect');
        $rectangulo->setAttribute(
            'style',
            'position:absolute;left:' . number_format((float)$x, 2, '.', '') .
            ';top:' . number_format((float)$y, 2, '.', '') .
            ';width:' . number_format((float)$ancho, 2, '.', '') .
            ';height:' . number_format((float)$alto, 2, '.', '') . ';'
        );
        $rectangulo->setAttribute('filled', 'f');
        $rectangulo->setAttribute('stroked', 'f');

        $cajaTexto = $documento->createElementNS(self::V_NS, 'v:textbox');
        $cajaTexto->setAttribute('inset', '0,0,0,0');
        $contenido = $this->w($documento, 'txbxContent');
        $contenido->appendChild($this->crearParrafo(
            $documento,
            (string)$texto,
            [
                'tamano' => (int)$tamano,
                'negrita' => (bool)$negrita,
                'color' => (string)$color,
                'alineacion' => (string)$alineacion,
                'antes' => 0,
                'despues' => 0
            ]
        ));
        $cajaTexto->appendChild($contenido);
        $rectangulo->appendChild($cajaTexto);
        return $rectangulo;
    }

    private function crearTablaSimple(
        DOMDocument $documento,
        array $filas,
        array $anchos,
        $encabezado
    ) {
        $tabla = $this->crearTablaBase($documento, array_sum($anchos), $anchos, true);

        foreach ($filas as $indice => $valores) {
            $esEncabezado = $encabezado && $indice === 0;
            $fila = $this->crearFila($documento, $esEncabezado);
            $rellenoFila = $indice % 2 === 0 ? 'FFFFFF' : self::COLOR_FONDO;

            foreach ($anchos as $columna => $ancho) {
                $fila->appendChild($this->crearCelda(
                    $documento,
                    (string)($valores[$columna] ?? ''),
                    $ancho,
                    [
                        'tamano' => 17,
                        'negrita' => $columna === 0 || $esEncabezado,
                        'color' => $columna === 0 || $esEncabezado ? self::COLOR_PRIMARIO : self::COLOR_TEXTO,
                        'relleno' => $esEncabezado || $columna === 0 ? self::COLOR_FONDO_PRIMARIO : $rellenoFila
                    ]
                ));
            }

            $tabla->appendChild($fila);
        }

        return $tabla;
    }

    private function crearTablaDetalle(
        DOMDocument $documento,
        array $encabezados,
        array $filas,
        array $anchos
    ) {
        $tabla = $this->crearTablaBase($documento, array_sum($anchos), $anchos, true);
        $filaCabecera = $this->crearFila($documento, true);

        foreach ($encabezados as $indice => $encabezado) {
            $filaCabecera->appendChild($this->crearCelda(
                $documento,
                $encabezado,
                $anchos[$indice] ?? 900,
                [
                    'tamano' => 15,
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
                $esEstatus = $indice === 3;
                $fila->appendChild($this->crearCelda(
                    $documento,
                    (string)($valores[$indice] ?? ''),
                    $ancho,
                    [
                        'tamano' => 14,
                        'color' => $esEstatus ? self::COLOR_PRIMARIO : self::COLOR_TEXTO,
                        'relleno' => $esEstatus ? self::COLOR_FONDO_PRIMARIO : $rellenoFila
                    ]
                ));
            }
            $tabla->appendChild($fila);
        }

        return $tabla;
    }

    private function crearTablaBase(DOMDocument $documento, $anchoTotal, array $anchos, $bordes)
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
        $noDividir = $this->w($documento, 'cantSplit');
        $propiedades->appendChild($noDividir);

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

        if (!empty($opciones['alto'])) {
            $parrafo = $this->crearParrafo($documento, ' ', [
                'tamano' => 4,
                'despues' => 0,
                'antes' => 0
            ]);
        } else {
            $parrafo = $this->crearParrafo($documento, $texto, [
                'tamano' => $opciones['tamano'] ?? 16,
                'negrita' => $opciones['negrita'] ?? false,
                'color' => $opciones['color'] ?? self::COLOR_TEXTO,
                'alineacion' => $opciones['alineacion'] ?? 'left',
                'antes' => 30,
                'despues' => 30
            ]);
        }

        $celda->appendChild($parrafo);
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
                'No fue posible convertir el reporte a PDF con LibreOffice.',
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
                'No fue posible preparar la conversión del reporte.',
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
                'No fue posible convertir el reporte a PDF con Microsoft Word.',
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

    private function nombreArchivo(array $datosReporte)
    {
        $estado = trim((string)(($datosReporte['resumen_filtros']['Estado'] ?? '')));
        $sufijoEstado = $estado !== '' && strcasecmp($estado, 'Todos') !== 0
            ? '_' . $this->sanitizarNombre($estado)
            : '';

        return 'Reporte_Seguimiento_Vinculacion' . $sufijoEstado . '_' . date('Y-m-d');
    }

    private function sanitizarNombre($valor)
    {
        $valor = trim((string)$valor);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        $base = $ascii !== false ? $ascii : $valor;
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '_', $base);
        $base = trim((string)$base, '_');
        return $base !== '' ? $base : 'General';
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
