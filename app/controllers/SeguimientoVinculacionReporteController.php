<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/ReporteSeguimientoVinculacionPdfService.php';
require_once __DIR__ . '/../services/ReporteSeguimientoVinculacionPdfProfesionalService.php';
require_once __DIR__ . '/../services/ReporteSeguimientoInstitucionDetalleService.php';
require_once __DIR__ . '/../services/ReporteSeguimientoPdfCacheService.php';
require_once __DIR__ . '/../services/EvolucionActividadSeguimientoService.php';
require_once __DIR__ . '/../services/SeguimientoReporteAnaliticaService.php';
require_once __DIR__ . '/../services/ReporteSeguimientoCarteraService.php';
require_once __DIR__ . '/../services/SeguimientoFlujoService.php';
require_once __DIR__ . '/../services/SeguimientoPostEnvioService.php';
require_once __DIR__ . '/../services/SeguimientoCorreoService.php';
require_once __DIR__ . '/../services/AgendaReunionService.php';
require_once __DIR__ . '/../services/ReunionFechaGuardService.php';
require_once __DIR__ . '/../services/ReunionResultadoService.php';

class SeguimientoVinculacionReporteController
{
    private const ESTADOS_SEGUIMIENTO = [
        'NUEVO' => 'Nuevo',
        'CONTACTANDO' => 'Contactando',
        'DATOS_VERIFICADOS' => 'Datos verificados',
        'NO_LOCALIZADO' => 'No localizado',
        'DESCARTADO' => 'Descartado',
        'OFICIO_PREPARADO' => 'Oficio preparado',
        'ESPERANDO_RESPUESTA' => 'Esperando respuesta'
    ];

    private const CANALES = [
        'LLAMADA_IP' => 'Llamada',
        'LLAMADA' => 'Llamada',
        'WHATSAPP' => 'WhatsApp',
        'CORREO' => 'Correo',
        'NOTA' => 'Nota',
        'SISTEMA' => 'Sistema'
    ];

    private const ETAPAS_CARTERA = [
        'DATOS_CONTACTO' => 'Datos de contacto',
        'OFICIO_INSTITUCIONAL' => 'Oficio institucional',
        'RESPUESTA_INSTITUCION' => 'Respuesta de la institución',
        'REUNION' => 'Reunión',
        'CONVENIO_FORMALIZACION' => 'Convenio / formalización',
        'DESCARTADO' => 'Descartado'
    ];

    public function index()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');
        $this->validarAccesoReporteSeguimiento();
        $contexto = $this->construirContextoReporte(false);
        extract($contexto, EXTR_SKIP);

        $errorExportacionPdf = (string)($_SESSION['error_reporte_seguimiento_pdf'] ?? '');
        unset($_SESSION['error_reporte_seguimiento_pdf']);
        $urlExportarPdf =
            $generarReporte &&
            $errorFiltros === '' &&
            tienePermiso('reportes.exportar')
                ? $this->construirUrlExportacion($filtrosReporte)
                : '';

        $tituloPagina = 'Generar reportes';
        $subtituloPagina = 'Seguimiento de vinculación';
        $opcionActiva = 'seguimiento_vinculacion';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/seguimiento_vinculacion/reportes.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function opcionesFiltros()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');
        $this->validarAccesoReporteSeguimiento();
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: private, no-store, max-age=0');

        try {
            $modelo = new SeguimientoVinculacionModel();
            $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
            $modoSeguimiento = $this->resolverModoSeguimiento();
            $territorios = $this->obtenerTerritoriosPorModo(
                $modelo,
                $usuarioId,
                $modoSeguimiento
            );
            $territoriosPorId = [];

            foreach ($territorios as $territorio) {
                $territorioId = (int)($territorio['id'] ?? 0);
                if ($territorioId > 0) {
                    $territoriosPorId[$territorioId] = $territorio;
                }
            }

            $filtros = $this->obtenerFiltrosReporte();

            if (!$this->puedeGenerarTipoReporte((string)$filtros['tipo_reporte'])) {
                http_response_code(403);
                echo json_encode([
                    'ok' => false,
                    'mensaje' => 'No tienes permiso para generar este tipo de reporte.'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            $estadoId = (int)$filtros['estado_id'];

            if ($estadoId > 0 && !isset($territoriosPorId[$estadoId])) {
                http_response_code(403);
                echo json_encode([
                    'ok' => false,
                    'mensaje' => 'No tienes acceso a este territorio.'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            if ($estadoId <= 0) {
                $filtros['municipio_id'] = 0;
            }

            $territoriosConsulta = $estadoId > 0
                ? [$estadoId => $territoriosPorId[$estadoId]]
                : $territoriosPorId;
            $seguimientos = $this->cargarSeguimientosAccesibles(
                $modelo,
                $usuarioId,
                $modoSeguimiento,
                $territoriosConsulta
            );

            if (
                (string)($filtros['tipo_reporte'] ?? 'cartera') === 'cartera'
            ) {
                $seguimientos = (new ReporteSeguimientoCarteraService())->enriquecer(
                    $seguimientos
                );
            }

            $responsablesPermitidos = $this->obtenerResponsablesPorModo(
                $modelo,
                $usuarioId,
                $modoSeguimiento,
                (int)($filtros['estado_id'] ?? 0),
                $seguimientos
            );
            $filtros = $this->normalizarFiltrosDependientes(
                $seguimientos,
                $filtros,
                $modoSeguimiento,
                $responsablesPermitidos
            );
            $opciones = $this->construirOpcionesDependientes(
                $seguimientos,
                $filtros,
                $modoSeguimiento,
                $responsablesPermitidos
            );

            $canalesRespuesta = $opciones['canales'];
            if (
                (string)($filtros['tipo_reporte'] ?? '') === 'actividad'
            ) {
                $canalesRespuesta = $this->opcionesCanalesActividad();
            }

            echo json_encode([
                'ok' => true,
                'modo' => $modoSeguimiento,
                'mostrar_responsable' => $modoSeguimiento !== 'analista',
                'seleccion' => [
                    'estado_id' => (int)$filtros['estado_id'],
                    'municipio_id' => (int)$filtros['municipio_id'],
                    'institucion_id' => (int)$filtros['institucion_id'],
                    'responsable_id' => (int)$filtros['responsable_id'],
                    'estado_seguimiento' => (string)$filtros['estado_seguimiento'],
                    'tipo_actividad' => (string)$filtros['tipo_actividad']
                ],
                'municipios' => $opciones['municipios'],
                'hay_instituciones' => !empty($opciones['instituciones']),
                'total_instituciones' => count($opciones['instituciones']),
                'instituciones' => $modoSeguimiento === 'analista'
                    ? []
                    : $opciones['instituciones'],
                'responsables' => $opciones['responsables'],
                'estatus' => $opciones['estatus'],
                'canales' => $canalesRespuesta
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            error_log('[reporte_filtros_dependientes] ' . $error->getMessage());
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'mensaje' => 'No fue posible actualizar los filtros del reporte.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function institucionesSelector()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');
        $this->validarPermiso('reportes.seguimiento.institucion');
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: private, no-store, max-age=0');

        try {
            $modelo = new SeguimientoVinculacionModel();
            $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
            $modoSeguimiento = $this->resolverModoSeguimiento();
            $estadoId = max(0, (int)($_GET['estado_id'] ?? 0));
            $municipioId = max(0, (int)($_GET['municipio_id'] ?? 0));
            $pagina = max(1, (int)($_GET['pagina'] ?? 1));
            $limite = 12;
            $busqueda = trim((string)($_GET['q'] ?? ''));

            if ($estadoId <= 0) {
                echo json_encode([
                    'ok' => true,
                    'instituciones' => [],
                    'pagina' => 1,
                    'paginas' => 0,
                    'total' => 0,
                    'mensaje' => 'Selecciona un estado para consultar instituciones.'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            $territorios = $this->obtenerTerritoriosPorModo(
                $modelo,
                $usuarioId,
                $modoSeguimiento
            );
            $territorio = null;

            foreach ($territorios as $item) {
                if ((int)($item['id'] ?? 0) === $estadoId) {
                    $territorio = $item;
                    break;
                }
            }

            if (!is_array($territorio)) {
                http_response_code(403);
                echo json_encode([
                    'ok' => false,
                    'mensaje' => 'No tienes acceso a este territorio.'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return;
            }

            $seguimientos = $this->cargarSeguimientosAccesibles(
                $modelo,
                $usuarioId,
                $modoSeguimiento,
                [$estadoId => $territorio]
            );

            if ($municipioId > 0) {
                $seguimientos = array_values(array_filter(
                    $seguimientos,
                    static function ($seguimiento) use ($municipioId) {
                        return (int)($seguimiento['municipio_id'] ?? 0) === $municipioId;
                    }
                ));
            }

            if ($busqueda !== '') {
                $aguja = mb_strtolower($busqueda, 'UTF-8');
                $seguimientos = array_values(array_filter(
                    $seguimientos,
                    static function ($seguimiento) use ($aguja) {
                        $nombre = mb_strtolower(
                            trim((string)($seguimiento['nombre_entidad'] ?? '')),
                            'UTF-8'
                        );
                        $municipio = mb_strtolower(
                            trim((string)($seguimiento['municipio'] ?? '')),
                            'UTF-8'
                        );

                        return mb_strpos($nombre, $aguja) !== false ||
                            mb_strpos($municipio, $aguja) !== false;
                    }
                ));
            }

            usort($seguimientos, static function ($a, $b) {
                return strnatcasecmp(
                    (string)($a['nombre_entidad'] ?? ''),
                    (string)($b['nombre_entidad'] ?? '')
                );
            });

            $total = count($seguimientos);
            $paginas = $total > 0 ? (int)ceil($total / $limite) : 0;
            if ($paginas > 0 && $pagina > $paginas) {
                $pagina = $paginas;
            }

            $inicio = ($pagina - 1) * $limite;
            $segmento = array_slice($seguimientos, max(0, $inicio), $limite);
            $resultado = array_map(function ($seguimiento) use ($territorio) {
                $codigo = strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? '')));
                $estadoSeguimiento = self::ESTADOS_SEGUIMIENTO[$codigo]
                    ?? ($codigo !== ''
                        ? ucfirst(strtolower(str_replace('_', ' ', $codigo)))
                        : 'Seguimiento');

                if ($codigo !== 'DESCARTADO') {
                    try {
                        $flujo = $this->construirFlujoOperativoIndividual([$seguimiento]);
                        $etapaActual = trim((string)(
                            $flujo['ventana']['actual']['titulo']
                                ?? ''
                        ));
                        $pasoActual = (int)($flujo['paso_actual'] ?? 0);
                        $totalPasos = max(13, (int)($flujo['total_pasos'] ?? 13));

                        if ($pasoActual >= $totalPasos) {
                            $estadoSeguimiento = 'Convenio formalizado';
                        } elseif ($etapaActual !== '') {
                            $estadoSeguimiento = $etapaActual;
                        }
                    } catch (Throwable $error) {
                        error_log(
                            '[reporte_selector_estado_operativo] seguimiento=' .
                            (int)($seguimiento['id'] ?? 0) . ' ' . $error->getMessage()
                        );
                    }
                }

                return [
                    'id' => (int)($seguimiento['id'] ?? 0),
                    'nombre' => trim((string)($seguimiento['nombre_entidad'] ?? '')),
                    'municipio' => trim((string)($seguimiento['municipio'] ?? '')),
                    'estado' => trim((string)($territorio['nombre'] ?? '')),
                    'estatus' => $estadoSeguimiento
                ];
            }, $segmento);

            echo json_encode([
                'ok' => true,
                'instituciones' => $resultado,
                'pagina' => $pagina,
                'paginas' => $paginas,
                'total' => $total,
                'limite' => $limite
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            error_log('[reporte_selector_instituciones] ' . $error->getMessage());
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'mensaje' => 'No fue posible consultar las instituciones.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function exportarPdf()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');
        $this->validarAccesoReporteSeguimiento();

        if (!tienePermiso('reportes.exportar')) {
            http_response_code(403);
            die('No tienes permiso para exportar reportes.');
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $modoSeguimientoCache = $this->resolverModoSeguimiento();
        $filtrosCache = $this->obtenerFiltrosReporte();
        $this->validarTipoReportePermitido(
            (string)$filtrosCache['tipo_reporte']
        );
        $cachePdf = new ReporteSeguimientoPdfCacheService();
        $claveCache = '';

        try {
            $modeloCache = new SeguimientoVinculacionModel();
            $territoriosCache = $this->obtenerTerritoriosPorModo(
                $modeloCache,
                $usuarioId,
                $modoSeguimientoCache
            );
            $territorioIdsCache = [];

            foreach ($territoriosCache as $territorioCache) {
                $territorioIdCache = (int)($territorioCache['id'] ?? 0);
                if ($territorioIdCache > 0) {
                    $territorioIdsCache[] = $territorioIdCache;
                }
            }

            sort($territorioIdsCache);
            $estadoSolicitado = (int)($filtrosCache['estado_id'] ?? 0);
            $puedeUsarCache = $estadoSolicitado <= 0 ||
                in_array($estadoSolicitado, $territorioIdsCache, true);

            if ($puedeUsarCache) {
                $claveCache = $cachePdf->crearClave([
                    'version' => 'seguimiento-pdf-profesional-v18',
                    'usuario_id' => $usuarioId,
                    'rol_id' => (int)($_SESSION['rol_id'] ?? 0),
                    'modo' => $modoSeguimientoCache,
                    'territorios' => $territorioIdsCache,
                    'filtros' => $filtrosCache
                ]);

                $resultadoCache = $cachePdf->obtener($claveCache);
                if (is_array($resultadoCache)) {
                    $this->enviarPdfDescarga(
                        (string)$resultadoCache['contenido_pdf'],
                        (string)$resultadoCache['nombre_archivo']
                    );
                }
            }
        } catch (Throwable $error) {
            error_log('[reporte_seguimiento_pdf_cache_lectura] ' . $error->getMessage());
            $claveCache = '';
        }

        $contexto = $this->construirContextoReporte(true);

        if ((string)$contexto['errorFiltros'] !== '') {
            $this->redirigirErrorPdf(
                (string)$contexto['errorFiltros'],
                $contexto['filtrosReporte']
            );
        }

        $estadoPdf = (int)($contexto['filtrosReporte']['estado_id'] ?? 0);
        if ($estadoPdf > 0) {
            foreach (($contexto['seguimientosReporte'] ?? []) as $seguimientoPdf) {
                if ((int)($seguimientoPdf['estado_id'] ?? 0) !== $estadoPdf) {
                    error_log(
                        '[reporte_seguimiento_pdf_territorio] seguimiento=' .
                        (int)($seguimientoPdf['id'] ?? 0) .
                        ' estado_esperado=' . $estadoPdf .
                        ' estado_real=' . (int)($seguimientoPdf['estado_id'] ?? 0)
                    );
                    $this->redirigirErrorPdf(
                        'No fue posible mantener el filtro territorial al generar el PDF.',
                        $contexto['filtrosReporte']
                    );
                }
            }
        }

        $evolucionActividad = [];
        try {
            $evolucionActividad = (new EvolucionActividadSeguimientoService())->construir(
                $contexto['seguimientosActividad'] ?? [],
                $contexto['filtrosReporte'],
                $usuarioId,
                (string)($contexto['modoSeguimiento'] ?? 'analista'),
                is_array($contexto['actoresActividad'] ?? null)
                    ? $contexto['actoresActividad']
                    : []
            );
        } catch (Throwable $error) {
            error_log('[reporte_evolucion_actividad_pdf] ' . $error->getMessage());
        }

        $seguimientosFuenteAnaliticaPdf =
            (string)($contexto['filtrosReporte']['tipo_reporte'] ?? '') === 'actividad'
                ? (
                    is_array($contexto['seguimientosActividad'] ?? null)
                        ? $contexto['seguimientosActividad']
                        : []
                )
                : (
                    is_array($contexto['seguimientosReporte'] ?? null)
                        ? $contexto['seguimientosReporte']
                        : []
                );
        $seguimientoIds = array_values(array_filter(array_map(
            static function ($seguimiento) {
                return (int)($seguimiento['id'] ?? 0);
            },
            $seguimientosFuenteAnaliticaPdf
        )));

        $analitica = [];
        try {
            $analitica = (new SeguimientoReporteAnaliticaService())->construir(
                $seguimientoIds,
                $usuarioId,
                (string)($contexto['modoSeguimiento'] ?? 'analista'),
                (string)($contexto['filtrosReporte']['fecha_inicial'] ?? ''),
                (string)($contexto['filtrosReporte']['fecha_final'] ?? ''),
                (string)(($contexto['filtrosReporte']['tipo_reporte'] ?? '') === 'actividad'
                    ? ($contexto['filtrosReporte']['tipo_actividad'] ?? '')
                    : ''),
                (string)($contexto['filtrosReporte']['tipo_reporte'] ?? '') === 'actividad'
                    ? 200
                    : 60,
                is_array($contexto['actoresActividad'] ?? null)
                    ? $contexto['actoresActividad']
                    : []
            );
        } catch (Throwable $error) {
            error_log('[reporte_analitica_pdf] ' . $error->getMessage());
        }

        $flujoIndividual = $this->construirFlujoOperativoIndividual(
            is_array($contexto['seguimientosReporte'] ?? null)
                ? $contexto['seguimientosReporte']
                : []
        );

        $detalleInstitucion = [];
        $seguimientosReporte = is_array($contexto['seguimientosReporte'] ?? null)
            ? $contexto['seguimientosReporte']
            : [];

        if (
            (int)($contexto['filtrosReporte']['institucion_id'] ?? 0) > 0 &&
            count($seguimientosReporte) === 1
        ) {
            try {
                $detalleInstitucion = (new ReporteSeguimientoInstitucionDetalleService())->construir(
                    (int)($seguimientosReporte[0]['id'] ?? 0),
                    $usuarioId,
                    (string)($contexto['modoSeguimiento'] ?? 'analista')
                );
            } catch (Throwable $error) {
                error_log('[reporte_detalle_institucion_pdf] ' . $error->getMessage());
            }
        }

        $datosPdf = [
            'resumen_filtros' => $contexto['resumenFiltros'],
            'filtros_reporte' => $contexto['filtrosReporte'],
            'resumen_reporte' => $contexto['resumenReporte'],
            'seguimientos' => $contexto['seguimientosReporte'],
            'evolucion_actividad' => $evolucionActividad,
            'analitica' => $analitica,
            'flujo_individual' => $flujoIndividual,
            'detalle_institucion' => $detalleInstitucion,
            'etiquetas_estatus' => (
                (string)($contexto['filtrosReporte']['tipo_reporte'] ?? '') === 'cartera'
                    ? self::ETAPAS_CARTERA
                    : self::ESTADOS_SEGUIMIENTO
            ),
            'fecha_generacion' => date('Y-m-d H:i:s'),
            'generado_por' => trim(
                (string)($_SESSION['nombre'] ?? '') . ' ' .
                (string)($_SESSION['apellidos'] ?? '')
            ),
            'generado_por_rol' => (string)($_SESSION['rol'] ?? ''),
            'modo_reporte' => (string)($contexto['modoSeguimiento'] ?? 'analista')
        ];

        $servicio = new ReporteSeguimientoVinculacionPdfProfesionalService();
        $resultado = $servicio->generar($datosPdf);

        if (!($resultado['ok'] ?? false)) {
            error_log(
                '[reporte_seguimiento_pdf_profesional] ' .
                (string)($resultado['mensaje_tecnico'] ?? $resultado['mensaje'] ?? 'Error sin detalle.')
            );

            $resultado = (new ReporteSeguimientoVinculacionPdfService())->generar($datosPdf);
        }

        if (!($resultado['ok'] ?? false)) {
            error_log(
                '[reporte_seguimiento_pdf] ' .
                (string)($resultado['mensaje_tecnico'] ?? $resultado['mensaje'] ?? 'Error sin detalle.')
            );
            $this->redirigirErrorPdf(
                (string)($resultado['mensaje'] ?? 'No fue posible generar el PDF del reporte.'),
                $contexto['filtrosReporte']
            );
        }

        $contenidoPdf = (string)($resultado['contenido_pdf'] ?? '');
        $nombreArchivo = (string)($resultado['nombre_archivo'] ?? 'Reporte_Seguimiento_Vinculacion.pdf');

        if ($claveCache !== '' && $contenidoPdf !== '') {
            try {
                $cachePdf->guardar($claveCache, $contenidoPdf, $nombreArchivo);
            } catch (Throwable $error) {
                error_log('[reporte_seguimiento_pdf_cache_escritura] ' . $error->getMessage());
            }
        }

        $this->enviarPdfDescarga($contenidoPdf, $nombreArchivo);
    }

    private function enviarPdfDescarga(string $contenidoPdf, string $nombreArchivo): void
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Content-Length: ' . strlen($contenidoPdf));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $contenidoPdf;
        exit;
    }

    private function construirFlujoOperativoIndividual(array $seguimientos)
    {
        if (count($seguimientos) !== 1) {
            return [];
        }

        $seguimiento = $seguimientos[0];
        $seguimientoId = (int)($seguimiento['id'] ?? 0);
        $analistaId = (int)($seguimiento['analista_id'] ?? 0);

        if ($seguimientoId <= 0 || $analistaId <= 0) {
            return [];
        }

        try {
            $postEnvio = (new SeguimientoPostEnvioService())->obtenerFlujoSiAplica(
                $seguimientoId,
                $analistaId
            );

            if (($postEnvio['ok'] ?? false) && ($postEnvio['aplica'] ?? false)) {
                $flujo = is_array($postEnvio['flujo'] ?? null)
                    ? $postEnvio['flujo']
                    : [];

                $flujo = (new AgendaReunionService())->ajustarFlujoAnalista(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
                $flujo = (new SeguimientoCorreoService())->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
                $flujo = (new ReunionFechaGuardService())->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );
                $flujo = (new ReunionResultadoService())->ajustarFlujo(
                    $seguimientoId,
                    $analistaId,
                    $flujo
                );

                return is_array($flujo) ? $flujo : [];
            }

            $resultado = (new SeguimientoFlujoService())->obtenerEstado(
                $seguimientoId,
                $analistaId
            );
            $flujo = is_array($resultado['flujo'] ?? null)
                ? $resultado['flujo']
                : [];

            return $this->ajustarPasoInicialPdf($flujo, $seguimiento);
        } catch (Throwable $error) {
            error_log('[reporte_flujo_individual_pdf] ' . $error->getMessage());
            return [];
        }
    }

    private function ajustarPasoInicialPdf(array $flujo, array $seguimiento)
    {
        if ((int)($flujo['paso_actual'] ?? 0) !== 2) {
            return $flujo;
        }

        if (strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? ''))) !== 'NUEVO') {
            return $flujo;
        }

        if (
            (int)($seguimiento['datos_verificados'] ?? 0) === 1 ||
            trim((string)($seguimiento['ultima_interaccion_at'] ?? '')) !== ''
        ) {
            return $flujo;
        }

        $creado = trim((string)($seguimiento['created_at'] ?? ''));
        $actualizado = trim((string)($seguimiento['updated_at'] ?? ''));

        if ($creado !== '' && $actualizado !== '') {
            try {
                if (new DateTime($actualizado) > new DateTime($creado)) {
                    return $flujo;
                }
            } catch (Throwable $error) {
                // Conserva el criterio de NUEVO sin actividad si no puede comparar marcas.
            }
        }

        $totalPasos = max(13, (int)($flujo['total_pasos'] ?? 13));
        $flujo['paso_actual'] = 1;
        $flujo['total_pasos'] = $totalPasos;
        $flujo['porcentaje'] = (int)round((1 / $totalPasos) * 100);
        $flujo['titulo'] = 'Iniciar investigación';
        $flujo['accion_principal'] = [
            'codigo' => 'COMPLETAR_DATOS',
            'etiqueta' => 'Comenzar investigación',
            'icono' => 'bi-search'
        ];
        $flujo['ventana'] = [
            'anterior' => null,
            'actual' => [
                'numero' => 1,
                'clave' => 'INICIO',
                'titulo' => 'Seguimiento iniciado'
            ],
            'siguiente' => [
                'numero' => 2,
                'clave' => 'INVESTIGACION',
                'titulo' => 'Investigación de datos'
            ]
        ];

        return $flujo;
    }

    private function construirContextoReporte($forzarGeneracion)
    {
        $modelo = new SeguimientoVinculacionModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $modoSeguimiento = $this->resolverModoSeguimiento();
        $territorios = $this->obtenerTerritoriosPorModo(
            $modelo,
            $usuarioId,
            $modoSeguimiento
        );
        $territoriosPorId = [];

        foreach ($territorios as $territorio) {
            $territorioId = (int)($territorio['id'] ?? 0);

            if ($territorioId > 0) {
                $territoriosPorId[$territorioId] = $territorio;
            }
        }

        $filtrosReporte = $this->obtenerFiltrosReporte();
        $this->validarTipoReportePermitido(
            (string)$filtrosReporte['tipo_reporte']
        );
        $tiposReportePermitidos = $this->obtenerTiposReportePermitidos();
        $estadoId = (int)$filtrosReporte['estado_id'];

        if ($estadoId > 0 && !isset($territoriosPorId[$estadoId])) {
            $_SESSION['error_seguimiento_vinculacion'] = 'No tienes acceso a este territorio.';
            header(
                'Location: ' . BASE_URL .
                'index.php?controller=seguimientoVinculacion&action=index'
            );
            exit;
        }

        $municipiosPorEstado = [];

        if ($forzarGeneracion) {
            if ($estadoId > 0 && isset($territoriosPorId[$estadoId])) {
                $municipiosPorEstado[$estadoId] = $modelo->obtenerMunicipiosActivosEstado(
                    $estadoId
                );
            }
        } else {
            foreach ($territoriosPorId as $territorioId => $territorio) {
                $municipiosPorEstado[$territorioId] = $modelo->obtenerMunicipiosActivosEstado(
                    $territorioId
                );
            }
        }

        if ($estadoId <= 0) {
            $filtrosReporte['municipio_id'] = 0;
        } elseif ((int)$filtrosReporte['municipio_id'] > 0) {
            $municipioValido = false;

            foreach ($municipiosPorEstado[$estadoId] ?? [] as $municipio) {
                if ((int)($municipio['id'] ?? 0) === (int)$filtrosReporte['municipio_id']) {
                    $municipioValido = true;
                    break;
                }
            }

            if (!$municipioValido) {
                $filtrosReporte['municipio_id'] = 0;
            }
        }

        $territoriosConsulta = $estadoId > 0
            ? [$estadoId => $territoriosPorId[$estadoId]]
            : $territoriosPorId;
        $seguimientosDisponibles = $this->cargarSeguimientosAccesibles(
            $modelo,
            $usuarioId,
            $modoSeguimiento,
            $territoriosConsulta
        );

        if (
            (string)($filtrosReporte['tipo_reporte'] ?? 'cartera') === 'cartera'
        ) {
            try {
                $seguimientosDisponibles =
                    (new ReporteSeguimientoCarteraService())->enriquecer(
                        $seguimientosDisponibles
                    );
            } catch (Throwable $error) {
                error_log('[reporte_cartera_enriquecimiento] ' . $error->getMessage());
            }
        }

        $responsablesDisponibles = $this->obtenerResponsablesPorModo(
            $modelo,
            $usuarioId,
            $modoSeguimiento,
            $estadoId,
            $seguimientosDisponibles
        );
        $responsableSolicitado = (int)($filtrosReporte['responsable_id'] ?? 0);
        $errorAlcanceAnalista = '';
        if (
            $modoSeguimiento === 'supervisor' &&
            $responsableSolicitado > 0 &&
            !isset($responsablesDisponibles[$responsableSolicitado])
        ) {
            $errorAlcanceAnalista =
                'El Analista seleccionado no pertenece a tu alcance de supervisión.';
        }

        $filtrosReporte = $this->normalizarFiltrosDependientes(
            $seguimientosDisponibles,
            $filtrosReporte,
            $modoSeguimiento,
            $responsablesDisponibles
        );

        $tipoReporte = (string)($filtrosReporte['tipo_reporte'] ?? 'cartera');

        if ($tipoReporte === 'actividad') {
            $filtrosReporte['institucion_id'] = 0;
            $filtrosReporte['institucion'] = '';
            $filtrosReporte['estado_seguimiento'] = '';
            $filtrosReporte['dias_sin_actividad'] = 0;

            if ($modoSeguimiento === 'analista') {
                $filtrosReporte['municipio_id'] = 0;
                $filtrosReporte['responsable_id'] = 0;
            }

            $canalActividad = strtoupper(trim(
                (string)($filtrosReporte['tipo_actividad'] ?? '')
            ));
            if (!in_array(
                $canalActividad,
                ['', 'LLAMADA', 'LLAMADA_IP', 'CORREO'],
                true
            )) {
                $filtrosReporte['tipo_actividad'] = '';
            }

            if (
                trim((string)$filtrosReporte['fecha_inicial']) === '' &&
                trim((string)$filtrosReporte['fecha_final']) === ''
            ) {
                $hoy = date('Y-m-d');
                $filtrosReporte['fecha_inicial'] = $hoy;
                $filtrosReporte['fecha_final'] = $hoy;
            }
        } elseif ($tipoReporte === 'institucion') {
            $filtrosReporte['fecha_inicial'] = '';
            $filtrosReporte['fecha_final'] = '';
            $filtrosReporte['responsable_id'] = 0;
            $filtrosReporte['estado_seguimiento'] = '';
            $filtrosReporte['tipo_actividad'] = '';
            $filtrosReporte['dias_sin_actividad'] = 0;
        } else {
            $filtrosReporte['fecha_inicial'] = '';
            $filtrosReporte['fecha_final'] = '';
            $filtrosReporte['institucion_id'] = 0;
            $filtrosReporte['institucion'] = '';

            if ($modoSeguimiento === 'analista') {
                $filtrosReporte['responsable_id'] = 0;
            }
        }

        $institucionesDisponibles = $this->obtenerInstitucionesDisponibles(
            $seguimientosDisponibles
        );
        $canalesDisponibles = $this->obtenerCanalesDisponibles(
            $seguimientosDisponibles
        );

        if ((string)($filtrosReporte['tipo_reporte'] ?? '') === 'actividad') {
            $canalesDisponibles = [
                'LLAMADA_IP' => 'Llamada',
                'CORREO' => 'Correo'
            ];
        }

        $actoresActividad = $this->resolverActoresActividad(
            $modoSeguimiento,
            $usuarioId,
            $filtrosReporte,
            $responsablesDisponibles
        );

        $errorFiltros = $errorAlcanceAnalista !== ''
            ? $errorAlcanceAnalista
            : $this->validarPeriodo($filtrosReporte);
        $generarReporte = $forzarGeneracion || (string)($_GET['generar'] ?? '') === '1';
        $seguimientosReporte = [];
        $seguimientosActividad = [];
        $seguimientosComparacionActividad = [];
        $resumenReporte = $this->crearResumenReporte([]);
        $analiticaReporte = [];
        $detalleInstitucionReporte = [];

        if (
            $generarReporte &&
            $errorFiltros === '' &&
            (string)($filtrosReporte['tipo_reporte'] ?? '') === 'institucion'
        ) {
            if ((int)($filtrosReporte['estado_id'] ?? 0) <= 0) {
                $errorFiltros = 'Selecciona un estado antes de elegir la institución.';
            } elseif ((int)($filtrosReporte['institucion_id'] ?? 0) <= 0) {
                $errorFiltros = 'Selecciona una institución para generar este reporte.';
            }
        }

        if ($generarReporte && $errorFiltros === '') {
            $seguimientosBaseReporte = $seguimientosDisponibles;

            if (
                (string)($filtrosReporte['tipo_reporte'] ?? '') === 'actividad'
            ) {
                // Alcance para evolución/comparación: todos los seguimientos
                // accesibles dentro del territorio elegido, no solo aquellos
                // con actividad en el periodo actual.
                $filtrosComparacionActividad = $filtrosReporte;
                $filtrosComparacionActividad['fecha_inicial'] = '';
                $filtrosComparacionActividad['fecha_final'] = '';
                $filtrosComparacionActividad['tipo_actividad'] = '';
                $seguimientosComparacionActividad = $this->aplicarFiltrosReporte(
                    $seguimientosDisponibles,
                    $filtrosComparacionActividad
                );

                $idsAccesibles = array_values(array_filter(array_map(
                    static function ($seguimiento) {
                        return (int)($seguimiento['id'] ?? 0);
                    },
                    $seguimientosDisponibles
                )));
                $idsConActividad = $modelo->obtenerSeguimientoIdsConActividadPeriodo(
                    $idsAccesibles,
                    $usuarioId,
                    (string)$filtrosReporte['fecha_inicial'],
                    (string)$filtrosReporte['fecha_final'],
                    (string)$filtrosReporte['tipo_actividad'],
                    $modoSeguimiento,
                    $actoresActividad
                );
                $mapaActividad = array_fill_keys($idsConActividad, true);
                $seguimientosBaseReporte = array_values(array_filter(
                    $seguimientosDisponibles,
                    static function ($seguimiento) use ($mapaActividad) {
                        return isset($mapaActividad[(int)($seguimiento['id'] ?? 0)]);
                    }
                ));
            }

            $filtrosActividad = $filtrosReporte;
            $filtrosActividad['fecha_inicial'] = '';
            $filtrosActividad['fecha_final'] = '';
            $filtrosActividad['tipo_actividad'] = '';

            if (
                (string)($filtrosReporte['tipo_reporte'] ?? '') === 'actividad'
            ) {
                $seguimientosActividad = $seguimientosComparacionActividad;
            } else {
                $seguimientosActividad = $this->aplicarFiltrosReporte(
                    $seguimientosBaseReporte,
                    $filtrosActividad
                );
            }

            $filtrosSeguimientos = $filtrosReporte;
            if (
                (string)($filtrosReporte['tipo_reporte'] ?? '') === 'actividad'
            ) {
                $filtrosSeguimientos['fecha_inicial'] = '';
                $filtrosSeguimientos['fecha_final'] = '';
                $filtrosSeguimientos['tipo_actividad'] = '';
            }

            $seguimientosReporte = $this->aplicarFiltrosReporte(
                $seguimientosBaseReporte,
                $filtrosSeguimientos
            );
            $seguimientosReporte = array_map(
                [$this, 'prepararSeguimientoReporte'],
                $seguimientosReporte
            );
            $resumenReporte = $this->crearResumenReporte($seguimientosReporte);

            $seguimientosFuenteAnalitica =
                (string)($filtrosReporte['tipo_reporte'] ?? '') === 'actividad'
                    ? $seguimientosActividad
                    : $seguimientosReporte;
            $seguimientoIdsReporte = array_values(array_filter(array_map(
                static function ($seguimiento) {
                    return (int)($seguimiento['id'] ?? 0);
                },
                $seguimientosFuenteAnalitica
            )));

            try {
                $analiticaReporte = (new SeguimientoReporteAnaliticaService())->construir(
                    $seguimientoIdsReporte,
                    $usuarioId,
                    $modoSeguimiento,
                    (string)($filtrosReporte['fecha_inicial'] ?? ''),
                    (string)($filtrosReporte['fecha_final'] ?? ''),
                    (string)(($filtrosReporte['tipo_reporte'] ?? '') === 'actividad'
                        ? ($filtrosReporte['tipo_actividad'] ?? '')
                        : ''),
                    60,
                    $actoresActividad
                );
            } catch (Throwable $error) {
                error_log('[reporte_analitica_web] ' . $error->getMessage());
            }

            if (
                (string)($filtrosReporte['tipo_reporte'] ?? '') === 'institucion' &&
                count($seguimientosReporte) === 1
            ) {
                try {
                    $detalleInstitucionReporte =
                        (new ReporteSeguimientoInstitucionDetalleService())->construir(
                            (int)($seguimientosReporte[0]['id'] ?? 0),
                            $usuarioId,
                            $modoSeguimiento
                        );
                } catch (Throwable $error) {
                    error_log('[reporte_detalle_institucion_web] ' . $error->getMessage());
                }
            }
        }

        $estadosSeguimiento =
            (string)($filtrosReporte['tipo_reporte'] ?? '') === 'cartera'
                ? self::ETAPAS_CARTERA
                : self::ESTADOS_SEGUIMIENTO;
        $resumenFiltros = $this->crearResumenFiltros(
            $filtrosReporte,
            $territoriosPorId,
            $municipiosPorEstado,
            $responsablesDisponibles,
            $canalesDisponibles
        );

        return [
            'territorios' => $territorios,
            'municipiosPorEstado' => $municipiosPorEstado,
            'institucionesDisponibles' => $institucionesDisponibles,
            'responsablesDisponibles' => $responsablesDisponibles,
            'canalesDisponibles' => $canalesDisponibles,
            'estadosSeguimiento' => $estadosSeguimiento,
            'filtrosReporte' => $filtrosReporte,
            'resumenFiltros' => $resumenFiltros,
            'seguimientosReporte' => $seguimientosReporte,
            'seguimientosActividad' => $seguimientosActividad,
            'resumenReporte' => $resumenReporte,
            'analiticaReporte' => $analiticaReporte,
            'detalleInstitucionReporte' => $detalleInstitucionReporte,
            'generarReporte' => $generarReporte,
            'errorFiltros' => $errorFiltros,
            'modoSeguimiento' => $modoSeguimiento,
            'tiposReportePermitidos' => $tiposReportePermitidos,
            'actoresActividad' => $actoresActividad
        ];
    }

    private function cargarSeguimientosAccesibles($modelo, $usuarioId, $modo, array $territoriosConsulta)
    {
        $seguimientosDisponibles = [];

        foreach ($territoriosConsulta as $territorioConsultaId => $territorio) {
            $seguimientosEstado = $this->obtenerSeguimientosPorModo(
                $modelo,
                $usuarioId,
                (int)$territorioConsultaId,
                $modo,
                []
            );

            foreach ($seguimientosEstado as $seguimiento) {
                $seguimiento['estado_id'] = (int)$territorioConsultaId;
                $seguimiento['estado_nombre'] = (string)($territorio['nombre'] ?? '');
                $seguimientosDisponibles[] = $seguimiento;
            }
        }

        return $seguimientosDisponibles;
    }

    private function normalizarFiltrosDependientes(
        array $seguimientos,
        array $filtros,
        $modo,
        array $responsablesPermitidos = []
    ) {
        $actuales = $seguimientos;
        $municipioId = (int)($filtros['municipio_id'] ?? 0);

        if ($municipioId > 0) {
            $coinciden = array_values(array_filter($actuales, function ($seguimiento) use ($municipioId) {
                return (int)($seguimiento['municipio_id'] ?? 0) === $municipioId;
            }));

            if (empty($coinciden)) {
                $filtros['municipio_id'] = 0;
            } else {
                $actuales = $coinciden;
            }
        }

        $institucionId = (int)($filtros['institucion_id'] ?? 0);
        $institucionLegacy = trim((string)($filtros['institucion'] ?? ''));

        if ($institucionId <= 0 && $institucionLegacy !== '') {
            foreach ($actuales as $seguimiento) {
                if ((string)($seguimiento['nombre_entidad'] ?? '') === $institucionLegacy) {
                    $institucionId = (int)($seguimiento['id'] ?? 0);
                    break;
                }
            }
        }

        $filtros['institucion_id'] = 0;
        $filtros['institucion'] = '';

        if ($institucionId > 0) {
            $coinciden = array_values(array_filter($actuales, function ($seguimiento) use ($institucionId) {
                return (int)($seguimiento['id'] ?? 0) === $institucionId;
            }));

            if (!empty($coinciden)) {
                $actuales = $coinciden;
                $filtros['institucion_id'] = $institucionId;
                $filtros['institucion'] = trim((string)($coinciden[0]['nombre_entidad'] ?? ''));
            }
        }

        if ($modo === 'analista') {
            $filtros['responsable_id'] = 0;
        } else {
            $responsableId = (int)($filtros['responsable_id'] ?? 0);

            if ($responsableId > 0) {
                if (
                    $modo === 'supervisor' &&
                    !isset($responsablesPermitidos[$responsableId])
                ) {
                    $filtros['responsable_id'] = 0;
                } else {
                    $coinciden = array_values(array_filter(
                        $actuales,
                        function ($seguimiento) use ($responsableId) {
                            return (int)($seguimiento['analista_id'] ?? 0) === $responsableId;
                        }
                    ));

                    if (empty($coinciden)) {
                        if ($modo !== 'supervisor') {
                            $filtros['responsable_id'] = 0;
                        } else {
                            // El Analista sigue siendo seleccionable aunque aún no tenga
                            // seguimientos o actividad dentro del alcance elegido.
                            $actuales = [];
                        }
                    } else {
                        $actuales = $coinciden;
                    }
                }
            }
        }

        $estatus = (string)($filtros['estado_seguimiento'] ?? '');
        if ($estatus !== '') {
            $esCartera =
                (string)($filtros['tipo_reporte'] ?? '') === 'cartera';
            $coinciden = array_values(array_filter(
                $actuales,
                function ($seguimiento) use ($estatus, $esCartera) {
                    $codigo = $esCartera
                        ? (string)($seguimiento['etapa_operativa_codigo'] ?? '')
                        : (string)($seguimiento['estado_seguimiento'] ?? '');
                    return $codigo === $estatus;
                }
            ));

            if (empty($coinciden)) {
                $filtros['estado_seguimiento'] = '';
            } else {
                $actuales = $coinciden;
            }
        }

        $canal = strtoupper(trim((string)($filtros['tipo_actividad'] ?? '')));
        $esReporteActividad =
            (string)($filtros['tipo_reporte'] ?? '') === 'actividad';

        if ($canal !== '' && !$esReporteActividad) {
            $coinciden = array_values(array_filter($actuales, function ($seguimiento) use ($canal) {
                $ultimoCanal = (string)(
                    $seguimiento['ultimo_canal_humano'] ??
                    $seguimiento['ultimo_canal'] ??
                    ''
                );
                return strtoupper(trim($ultimoCanal)) === $canal;
            }));

            if (empty($coinciden)) {
                $filtros['tipo_actividad'] = '';
            }
        }

        return $filtros;
    }

    private function construirOpcionesDependientes(
        array $seguimientos,
        array $filtros,
        $modo,
        array $responsablesPermitidos = []
    ) {
        $actuales = $seguimientos;
        $municipios = [];

        if ((int)($filtros['estado_id'] ?? 0) > 0) {
            foreach ($actuales as $seguimiento) {
                $id = (int)($seguimiento['municipio_id'] ?? 0);
                $nombre = trim((string)($seguimiento['municipio'] ?? ''));
                if ($id > 0 && $nombre !== '') {
                    $municipios[$id] = $nombre;
                }
            }
            natcasesort($municipios);
        }

        if ((int)$filtros['municipio_id'] > 0) {
            $municipioId = (int)$filtros['municipio_id'];
            $actuales = array_values(array_filter($actuales, function ($seguimiento) use ($municipioId) {
                return (int)($seguimiento['municipio_id'] ?? 0) === $municipioId;
            }));
        }

        $instituciones = [];
        foreach ($actuales as $seguimiento) {
            $id = (int)($seguimiento['id'] ?? 0);
            $nombre = trim((string)($seguimiento['nombre_entidad'] ?? ''));
            if ($id > 0 && $nombre !== '') {
                $instituciones[$id] = $nombre;
            }
        }
        natcasesort($instituciones);

        if ((int)$filtros['institucion_id'] > 0) {
            $institucionId = (int)$filtros['institucion_id'];
            $actuales = array_values(array_filter($actuales, function ($seguimiento) use ($institucionId) {
                return (int)($seguimiento['id'] ?? 0) === $institucionId;
            }));
        }

        $responsables = [];
        if ($modo === 'supervisor') {
            $responsables = $responsablesPermitidos;
        } elseif ($modo !== 'analista') {
            foreach ($actuales as $seguimiento) {
                $id = (int)($seguimiento['analista_id'] ?? 0);
                $nombre = trim(
                    (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
                    (string)($seguimiento['analista_apellidos'] ?? '')
                );
                if ($id > 0 && $nombre !== '') {
                    $responsables[$id] = $nombre;
                }
            }
            natcasesort($responsables);
        }

        if ((int)$filtros['responsable_id'] > 0) {
            $responsableId = (int)$filtros['responsable_id'];
            $actuales = array_values(array_filter($actuales, function ($seguimiento) use ($responsableId) {
                return (int)($seguimiento['analista_id'] ?? 0) === $responsableId;
            }));
        }

        $estatus = [];
        $esCartera =
            (string)($filtros['tipo_reporte'] ?? '') === 'cartera';

        foreach ($actuales as $seguimiento) {
            $codigo = strtoupper(trim((string)(
                $esCartera
                    ? ($seguimiento['etapa_operativa_codigo'] ?? '')
                    : ($seguimiento['estado_seguimiento'] ?? '')
            )));
            $mapaEstatus = $esCartera
                ? self::ETAPAS_CARTERA
                : self::ESTADOS_SEGUIMIENTO;
            if ($codigo !== '' && isset($mapaEstatus[$codigo])) {
                $estatus[$codigo] = $mapaEstatus[$codigo];
            }
        }

        if ((string)$filtros['estado_seguimiento'] !== '') {
            $estadoSeguimiento = (string)$filtros['estado_seguimiento'];
            $actuales = array_values(array_filter(
                $actuales,
                function ($seguimiento) use ($estadoSeguimiento, $esCartera) {
                    $codigo = $esCartera
                        ? (string)($seguimiento['etapa_operativa_codigo'] ?? '')
                        : (string)($seguimiento['estado_seguimiento'] ?? '');
                    return $codigo === $estadoSeguimiento;
                }
            ));
        }

        $canales = [];
        foreach ($actuales as $seguimiento) {
            $canal = strtoupper(trim((string)(
                $seguimiento['ultimo_canal_humano'] ??
                $seguimiento['ultimo_canal'] ??
                ''
            )));
            if ($canal !== '' && $canal !== 'SISTEMA') {
                $canales[$canal] = $this->etiquetarCanal($canal);
            }
        }
        asort($canales, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'municipios' => $this->convertirMapaOpciones($municipios),
            'instituciones' => $this->convertirMapaOpciones($instituciones),
            'responsables' => $this->convertirMapaOpciones($responsables),
            'estatus' => $this->convertirMapaOpciones($estatus),
            'canales' => $this->convertirMapaOpciones($canales)
        ];
    }

    private function convertirMapaOpciones(array $mapa)
    {
        $opciones = [];
        foreach ($mapa as $valor => $etiqueta) {
            $opciones[] = [
                'valor' => (string)$valor,
                'etiqueta' => (string)$etiqueta
            ];
        }
        return $opciones;
    }

    private function opcionesCanalesActividad()
    {
        return [
            ['valor' => 'LLAMADA_IP', 'etiqueta' => 'Llamada'],
            ['valor' => 'CORREO', 'etiqueta' => 'Correo']
        ];
    }

    private function construirUrlExportacion(array $filtros)
    {
        $parametros = [
            'controller' => 'seguimientoVinculacionReporte',
            'action' => 'exportarPdf',
            'tipo_reporte' => (string)($filtros['tipo_reporte'] ?? 'cartera'),
            'fecha_inicial' => (string)$filtros['fecha_inicial'],
            'fecha_final' => (string)$filtros['fecha_final'],
            'estado_id' => (int)$filtros['estado_id'],
            'municipio_id' => (int)$filtros['municipio_id'],
            'institucion_id' => (int)$filtros['institucion_id'],
            'responsable_id' => (int)$filtros['responsable_id'],
            'estado_seguimiento' => (string)$filtros['estado_seguimiento'],
            'tipo_actividad' => (string)$filtros['tipo_actividad'],
            'dias_sin_actividad' => (int)$filtros['dias_sin_actividad']
        ];

        return BASE_URL . 'index.php?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
    }

    private function redirigirErrorPdf($mensaje, array $filtros)
    {
        $_SESSION['error_reporte_seguimiento_pdf'] = (string)$mensaje;
        $parametros = [
            'controller' => 'seguimientoVinculacionReporte',
            'action' => 'index',
            'generar' => 1,
            'tipo_reporte' => (string)($filtros['tipo_reporte'] ?? 'cartera'),
            'fecha_inicial' => (string)$filtros['fecha_inicial'],
            'fecha_final' => (string)$filtros['fecha_final'],
            'estado_id' => (int)$filtros['estado_id'],
            'municipio_id' => (int)$filtros['municipio_id'],
            'institucion_id' => (int)$filtros['institucion_id'],
            'responsable_id' => (int)$filtros['responsable_id'],
            'estado_seguimiento' => (string)$filtros['estado_seguimiento'],
            'tipo_actividad' => (string)$filtros['tipo_actividad'],
            'dias_sin_actividad' => (int)$filtros['dias_sin_actividad']
        ];

        header(
            'Location: ' . BASE_URL . 'index.php?' .
            http_build_query($parametros, '', '&', PHP_QUERY_RFC3986)
        );
        exit;
    }

    private function obtenerFiltrosReporte()
    {
        $estadoSeguimiento = strtoupper(trim((string)($_GET['estado_seguimiento'] ?? '')));
        $tipoActividad = strtoupper(trim((string)($_GET['tipo_actividad'] ?? '')));
        $dias = (int)($_GET['dias_sin_actividad'] ?? 0);
        $tipoReporte = strtolower(trim((string)($_GET['tipo_reporte'] ?? '')));

        if (!in_array($tipoReporte, ['actividad', 'cartera', 'institucion'], true)) {
            $tipoReporte = '';
        }

        if ($tipoReporte === '') {
            $tipoReporte = $this->obtenerTipoReportePredeterminado();
        }

        return [
            'tipo_reporte' => $tipoReporte,
            'fecha_inicial' => $this->normalizarFecha($_GET['fecha_inicial'] ?? ''),
            'fecha_final' => $this->normalizarFecha($_GET['fecha_final'] ?? ''),
            'estado_id' => $this->enteroPositivo($_GET['estado_id'] ?? 0),
            'municipio_id' => $this->enteroPositivo($_GET['municipio_id'] ?? 0),
            'institucion_id' => $this->enteroPositivo($_GET['institucion_id'] ?? 0),
            'institucion' => trim((string)($_GET['institucion'] ?? '')),
            'responsable_id' => $this->enteroPositivo($_GET['responsable_id'] ?? 0),
            'estado_seguimiento' => (
                $tipoReporte === 'cartera'
                    ? isset(self::ETAPAS_CARTERA[$estadoSeguimiento])
                    : isset(self::ESTADOS_SEGUIMIENTO[$estadoSeguimiento])
            )
                ? $estadoSeguimiento
                : '',
            'tipo_actividad' => $tipoActividad,
            'dias_sin_actividad' => in_array($dias, [0, 3, 7, 15, 30], true)
                ? $dias
                : 0
        ];
    }

    private function aplicarFiltrosReporte($seguimientos, $filtros)
    {
        return array_values(array_filter(
            $seguimientos,
            function ($seguimiento) use ($filtros) {
                if (
                    (int)$filtros['estado_id'] > 0 &&
                    (int)($seguimiento['estado_id'] ?? 0) !== (int)$filtros['estado_id']
                ) {
                    return false;
                }

                if (
                    (int)$filtros['municipio_id'] > 0 &&
                    (int)($seguimiento['municipio_id'] ?? 0) !== (int)$filtros['municipio_id']
                ) {
                    return false;
                }

                if (
                    (int)$filtros['institucion_id'] > 0 &&
                    (int)($seguimiento['id'] ?? 0) !== (int)$filtros['institucion_id']
                ) {
                    return false;
                }

                if (
                    (int)$filtros['institucion_id'] <= 0 &&
                    $filtros['institucion'] !== '' &&
                    (string)($seguimiento['nombre_entidad'] ?? '') !== $filtros['institucion']
                ) {
                    return false;
                }

                if (
                    (int)$filtros['responsable_id'] > 0 &&
                    (int)($seguimiento['analista_id'] ?? 0) !== (int)$filtros['responsable_id']
                ) {
                    return false;
                }

                if ($filtros['estado_seguimiento'] !== '') {
                    $codigoEstado = (string)(
                        (string)($filtros['tipo_reporte'] ?? '') === 'cartera' &&
                        isset($seguimiento['etapa_operativa_codigo'])
                            ? ($seguimiento['etapa_operativa_codigo'] ?? '')
                            : ($seguimiento['estado_seguimiento'] ?? '')
                    );

                    if ($codigoEstado !== $filtros['estado_seguimiento']) {
                        return false;
                    }
                }

                if ($filtros['tipo_actividad'] !== '') {
                    $ultimoCanal = strtoupper(trim((string)(
                        $seguimiento['ultimo_canal_humano'] ??
                        $seguimiento['ultimo_canal'] ??
                        ''
                    )));
                    if ($ultimoCanal !== $filtros['tipo_actividad']) {
                        return false;
                    }
                }

                if (!$this->coincidePeriodo($seguimiento, $filtros)) {
                    return false;
                }

                $diasMinimos = (int)$filtros['dias_sin_actividad'];

                if ($diasMinimos > 0) {
                    $diasSinActividad = array_key_exists('dias_sin_actividad_humana', $seguimiento)
                        ? $seguimiento['dias_sin_actividad_humana']
                        : $this->calcularDiasSinActividad(
                            $seguimiento['ultima_interaccion_humana_at'] ??
                            $seguimiento['ultima_interaccion_at'] ??
                            ''
                        );

                    // "Más de X días" solo considera seguimientos que sí tuvieron
                    // actividad humana. Los que nunca se han trabajado se reportan
                    // por separado como "Sin actividad registrada".
                    if ($diasSinActividad === null || (int)$diasSinActividad <= $diasMinimos) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    private function coincidePeriodo($seguimiento, $filtros)
    {
        $fechaInicial = (string)$filtros['fecha_inicial'];
        $fechaFinal = (string)$filtros['fecha_final'];

        if ($fechaInicial === '' && $fechaFinal === '') {
            return true;
        }

        $fechaSeguimiento = $this->crearFecha($seguimiento['fecha_inicio'] ?? '');

        if (!$fechaSeguimiento) {
            return false;
        }

        if ($fechaInicial !== '') {
            $desde = new DateTimeImmutable($fechaInicial . ' 00:00:00');

            if ($fechaSeguimiento < $desde) {
                return false;
            }
        }

        if ($fechaFinal !== '') {
            $hasta = new DateTimeImmutable($fechaFinal . ' 23:59:59');

            if ($fechaSeguimiento > $hasta) {
                return false;
            }
        }

        return true;
    }

    private function prepararSeguimientoReporte($seguimiento)
    {
        $seguimiento['estado_label'] = self::ESTADOS_SEGUIMIENTO[
            (string)($seguimiento['estado_seguimiento'] ?? '')
        ] ?? 'Sin estado';
        $seguimiento['responsable_nombre'] = trim(
            (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
            (string)($seguimiento['analista_apellidos'] ?? '')
        );
        $fechaHumana = (string)(
            $seguimiento['ultima_interaccion_humana_at'] ??
            $seguimiento['ultima_interaccion_at'] ??
            ''
        );
        $seguimiento['ultima_actividad_label'] = $this->formatearFechaHora($fechaHumana);
        $seguimiento['dias_sin_actividad'] = array_key_exists(
            'dias_sin_actividad_humana',
            $seguimiento
        )
            ? $seguimiento['dias_sin_actividad_humana']
            : $this->calcularDiasSinActividad($fechaHumana);
        $seguimiento['proxima_accion_label'] = $this->obtenerProximaAccionLabel($seguimiento);
        $seguimiento['canal_label'] = $this->etiquetarCanal(
            $seguimiento['ultimo_canal_humano'] ??
            $seguimiento['ultimo_canal'] ??
            ''
        );

        if (isset($seguimiento['etapa_operativa_label'])) {
            $seguimiento['estado_label'] = (string)$seguimiento['etapa_operativa_label'];
        }

        return $seguimiento;
    }

    private function crearResumenReporte($seguimientos)
    {
        $porEstatus = [];
        $porEtapa = [];
        $porMunicipio = [];
        $porEstado = [];
        $territorioJerarquico = [];
        $sinActividad = 0;
        $masSieteDias = 0;
        $accionesVencidas = 0;
        $formalizados = 0;
        $descartados = 0;
        $enGestion = 0;
        $requierenAtencion = 0;
        $prioritarios = [];

        foreach ($seguimientos as $seguimiento) {
            $estado = (string)($seguimiento['estado_seguimiento'] ?? '');
            if ($estado !== '') {
                if (!isset($porEstatus[$estado])) {
                    $porEstatus[$estado] = 0;
                }
                $porEstatus[$estado]++;
            }

            $etapa = (string)($seguimiento['etapa_operativa_codigo'] ?? '');
            if ($etapa !== '') {
                if (!isset($porEtapa[$etapa])) {
                    $porEtapa[$etapa] = 0;
                }
                $porEtapa[$etapa]++;
            }

            $estadoIdTerritorial = (int)($seguimiento['estado_id'] ?? 0);
            $estadoNombreTerritorial = trim((string)($seguimiento['estado_nombre'] ?? ''));
            if ($estadoNombreTerritorial === '') {
                $estadoNombreTerritorial = 'Sin estado';
            }

            $estadoClaveTerritorial = $estadoIdTerritorial > 0
                ? 'id:' . $estadoIdTerritorial
                : 'nombre:' . strtolower($estadoNombreTerritorial);

            if (!isset($territorioJerarquico[$estadoClaveTerritorial])) {
                $territorioJerarquico[$estadoClaveTerritorial] = [
                    'estado_id' => $estadoIdTerritorial,
                    'estado_nombre' => $estadoNombreTerritorial,
                    'total' => 0,
                    'municipios' => []
                ];
            }

            $territorioJerarquico[$estadoClaveTerritorial]['total']++;
            if (!isset($porEstado[$estadoNombreTerritorial])) {
                $porEstado[$estadoNombreTerritorial] = 0;
            }
            $porEstado[$estadoNombreTerritorial]++;

            $municipio = trim((string)($seguimiento['municipio'] ?? ''));
            if ($municipio !== '') {
                if (!isset($territorioJerarquico[$estadoClaveTerritorial]['municipios'][$municipio])) {
                    $territorioJerarquico[$estadoClaveTerritorial]['municipios'][$municipio] = 0;
                }
                $territorioJerarquico[$estadoClaveTerritorial]['municipios'][$municipio]++;

                $municipioEtiqueta = $municipio;
                if ($estadoNombreTerritorial !== 'Sin estado') {
                    $municipioEtiqueta .= ', ' . $estadoNombreTerritorial;
                }
                if (!isset($porMunicipio[$municipioEtiqueta])) {
                    $porMunicipio[$municipioEtiqueta] = 0;
                }
                $porMunicipio[$municipioEtiqueta]++;
            }

            $dias = $seguimiento['dias_sin_actividad'] ??
                $seguimiento['dias_sin_actividad_humana'] ??
                null;

            if ($dias === null) {
                $sinActividad++;
            } elseif ((int)$dias > 7) {
                $masSieteDias++;
            }

            $atencion = (string)($seguimiento['atencion_codigo'] ?? '');
            if ($atencion === 'VENCIDA') {
                $accionesVencidas++;
            }
            if (in_array($atencion, ['VENCIDA', 'SIN_ACTIVIDAD', 'INACTIVA'], true)) {
                $requierenAtencion++;
                $prioritarios[] = $seguimiento;
            }

            if (!empty($seguimiento['convenio_formalizado'])) {
                $formalizados++;
            } elseif (
                (string)($seguimiento['etapa_operativa_codigo'] ?? '') === 'DESCARTADO' ||
                $estado === 'DESCARTADO'
            ) {
                $descartados++;
            } else {
                $enGestion++;
            }
        }

        uksort($porEstatus, function ($a, $b) {
            $orden = array_keys(self::ESTADOS_SEGUIMIENTO);
            return array_search($a, $orden, true) <=> array_search($b, $orden, true);
        });

        uksort($porEtapa, function ($a, $b) {
            $orden = array_keys(self::ETAPAS_CARTERA);
            $posA = array_search($a, $orden, true);
            $posB = array_search($b, $orden, true);
            $posA = $posA === false ? 999 : $posA;
            $posB = $posB === false ? 999 : $posB;
            return $posA <=> $posB;
        });

        arsort($porEstado);
        arsort($porMunicipio);

        foreach ($territorioJerarquico as &$territorioEstado) {
            $municipiosEstado = [];
            foreach (($territorioEstado['municipios'] ?? []) as $municipioNombre => $municipioTotal) {
                $municipiosEstado[] = [
                    'nombre' => (string)$municipioNombre,
                    'total' => (int)$municipioTotal
                ];
            }

            usort($municipiosEstado, static function ($a, $b) {
                $comparacionTotal = (int)($b['total'] ?? 0) <=> (int)($a['total'] ?? 0);
                if ($comparacionTotal !== 0) {
                    return $comparacionTotal;
                }
                return strcasecmp(
                    (string)($a['nombre'] ?? ''),
                    (string)($b['nombre'] ?? '')
                );
            });

            $territorioEstado['municipios'] = $municipiosEstado;
        }
        unset($territorioEstado);

        $territorioJerarquico = array_values($territorioJerarquico);
        usort($territorioJerarquico, static function ($a, $b) {
            $comparacionTotal = (int)($b['total'] ?? 0) <=> (int)($a['total'] ?? 0);
            if ($comparacionTotal !== 0) {
                return $comparacionTotal;
            }
            return strcasecmp(
                (string)($a['estado_nombre'] ?? ''),
                (string)($b['estado_nombre'] ?? '')
            );
        });

        usort($prioritarios, static function ($a, $b) {
            $orden = (int)($a['prioridad_orden'] ?? 99)
                <=> (int)($b['prioridad_orden'] ?? 99);
            if ($orden !== 0) {
                return $orden;
            }
            return (int)($b['dias_sin_actividad'] ?? -1)
                <=> (int)($a['dias_sin_actividad'] ?? -1);
        });

        return [
            'total' => count($seguimientos),
            'sin_actividad' => $sinActividad,
            'mas_7_dias' => $masSieteDias,
            'acciones_vencidas' => $accionesVencidas,
            'formalizados' => $formalizados,
            'descartados' => $descartados,
            'en_gestion' => $enGestion,
            'requieren_atencion' => $requierenAtencion,
            'por_estatus' => $porEstatus,
            'por_etapa' => $porEtapa,
            'por_estado' => $porEstado,
            'por_municipio' => $porMunicipio,
            'territorio_jerarquico' => $territorioJerarquico,
            'prioritarios' => array_slice($prioritarios, 0, 6)
        ];
    }

    private function crearResumenFiltros(
        $filtros,
        $territoriosPorId,
        $municipiosPorEstado,
        $responsablesDisponibles,
        $canalesDisponibles
    ) {
        $estadoId = (int)$filtros['estado_id'];
        $municipioId = (int)$filtros['municipio_id'];
        $municipioNombre = 'Todos';

        if ($estadoId > 0 && $municipioId > 0) {
            foreach ($municipiosPorEstado[$estadoId] ?? [] as $municipio) {
                if ((int)($municipio['id'] ?? 0) === $municipioId) {
                    $municipioNombre = (string)($municipio['nombre'] ?? 'Todos');
                    break;
                }
            }
        }

        $periodo = 'Todos';

        if ($filtros['fecha_inicial'] !== '' || $filtros['fecha_final'] !== '') {
            $periodo = ($filtros['fecha_inicial'] !== ''
                ? $this->formatearFechaCorta($filtros['fecha_inicial'])
                : 'Inicio') .
                ' - ' .
                ($filtros['fecha_final'] !== ''
                    ? $this->formatearFechaCorta($filtros['fecha_final'])
                    : 'Actualidad');
        }

        return [
            'Periodo' => $periodo,
            'Estado' => $estadoId > 0
                ? (string)($territoriosPorId[$estadoId]['nombre'] ?? 'Todos')
                : 'Todos',
            'Municipio' => $municipioNombre,
            'Institución' => $filtros['institucion'] !== ''
                ? $filtros['institucion']
                : 'Todas',
            'Responsable' => (int)$filtros['responsable_id'] > 0
                ? (string)($responsablesDisponibles[(int)$filtros['responsable_id']] ?? 'Todos')
                : 'Todos',
            ((string)($filtros['tipo_reporte'] ?? '') === 'cartera'
                ? 'Etapa'
                : 'Estatus') => $filtros['estado_seguimiento'] !== ''
                ? (
                    (string)($filtros['tipo_reporte'] ?? '') === 'cartera'
                        ? (self::ETAPAS_CARTERA[$filtros['estado_seguimiento']] ?? 'Todos')
                        : (self::ESTADOS_SEGUIMIENTO[$filtros['estado_seguimiento']] ?? 'Todos')
                )
                : 'Todos',
            ((string)($filtros['tipo_reporte'] ?? '') === 'actividad'
                ? 'Tipo de interacción'
                : 'Último canal de contacto') => $filtros['tipo_actividad'] !== ''
                ? (string)($canalesDisponibles[$filtros['tipo_actividad']] ?? 'Todos')
                : 'Todos',
            'Días sin actividad' => (int)$filtros['dias_sin_actividad'] > 0
                ? 'Más de ' . (int)$filtros['dias_sin_actividad'] . ' días'
                : 'Todos'
        ];
    }

    private function obtenerInstitucionesDisponibles($seguimientos)
    {
        $instituciones = [];

        foreach ($seguimientos as $seguimiento) {
            $nombre = trim((string)($seguimiento['nombre_entidad'] ?? ''));

            if ($nombre !== '') {
                $instituciones[$nombre] = $nombre;
            }
        }

        natcasesort($instituciones);
        return $instituciones;
    }

    private function obtenerResponsablesPorModo(
        SeguimientoVinculacionModel $modelo,
        $usuarioId,
        $modo,
        $estadoId,
        array $seguimientos
    ) {
        if ($modo !== 'supervisor') {
            return $this->obtenerResponsablesDisponibles($seguimientos);
        }

        $responsables = [];
        $analistas = $modelo->obtenerAnalistasSupervisadosCuentaClave(
            (int)$usuarioId,
            (int)$estadoId
        );

        foreach ($analistas as $analista) {
            $id = (int)($analista['id'] ?? 0);
            $nombre = trim(
                (string)($analista['nombre'] ?? '') . ' ' .
                (string)($analista['apellidos'] ?? '')
            );

            if ($id > 0 && $nombre !== '') {
                $responsables[$id] = $nombre;
            }
        }

        natcasesort($responsables);
        return $responsables;
    }

    private function resolverActoresActividad(
        $modo,
        $usuarioId,
        array $filtros,
        array $responsablesDisponibles
    ) {
        if ((string)($filtros['tipo_reporte'] ?? '') !== 'actividad') {
            return [];
        }

        if ($modo === 'analista') {
            return [(int)$usuarioId];
        }

        $responsableId = (int)($filtros['responsable_id'] ?? 0);
        if ($responsableId > 0) {
            return isset($responsablesDisponibles[$responsableId])
                ? [$responsableId]
                : [];
        }

        if ($modo === 'supervisor') {
            return array_values(array_map('intval', array_keys($responsablesDisponibles)));
        }

        return [];
    }

    private function obtenerResponsablesDisponibles($seguimientos)
    {
        $responsables = [];

        foreach ($seguimientos as $seguimiento) {
            $id = (int)($seguimiento['analista_id'] ?? 0);
            $nombre = trim(
                (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
                (string)($seguimiento['analista_apellidos'] ?? '')
            );

            if ($id > 0 && $nombre !== '') {
                $responsables[$id] = $nombre;
            }
        }

        natcasesort($responsables);
        return $responsables;
    }

    private function obtenerCanalesDisponibles($seguimientos)
    {
        $canales = [];

        foreach ($seguimientos as $seguimiento) {
            $canal = strtoupper(trim((string)(
                $seguimiento['ultimo_canal_humano'] ??
                $seguimiento['ultimo_canal'] ??
                ''
            )));

            if ($canal !== '' && $canal !== 'SISTEMA') {
                $canales[$canal] = $this->etiquetarCanal($canal);
            }
        }

        asort($canales, SORT_NATURAL | SORT_FLAG_CASE);
        return $canales;
    }

    private function calcularDiasSinActividad($fecha)
    {
        $ultimaActividad = $this->crearFecha($fecha);

        if (!$ultimaActividad) {
            return null;
        }

        $hoy = new DateTimeImmutable('today');
        $diaActividad = $ultimaActividad->setTime(0, 0, 0);

        if ($diaActividad >= $hoy) {
            return 0;
        }

        return (int)$diaActividad->diff($hoy)->days;
    }

    private function obtenerProximaAccionLabel($seguimiento)
    {
        $texto = trim((string)($seguimiento['proxima_accion_texto'] ?? ''));

        if ($texto !== '') {
            return $texto;
        }

        return $this->formatearFechaHora($seguimiento['proxima_accion_at'] ?? '');
    }

    private function validarPeriodo($filtros)
    {
        if ($filtros['fecha_inicial'] === '' || $filtros['fecha_final'] === '') {
            return '';
        }

        if ($filtros['fecha_inicial'] > $filtros['fecha_final']) {
            return 'La fecha inicial no puede ser posterior a la fecha final.';
        }

        return '';
    }

    private function normalizarFecha($valor)
    {
        $valor = trim((string)$valor);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return '';
        }

        try {
            $fecha = new DateTimeImmutable($valor);
        } catch (Exception $error) {
            return '';
        }

        return $fecha->format('Y-m-d') === $valor ? $valor : '';
    }

    private function crearFecha($valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valor);
        } catch (Exception $error) {
            return null;
        }
    }

    private function formatearFechaHora($valor)
    {
        $fecha = $this->crearFecha($valor);
        return $fecha ? $fecha->format('d/m/Y H:i') : '—';
    }

    private function formatearFechaCorta($valor)
    {
        $fecha = $this->crearFecha($valor);
        return $fecha ? $fecha->format('d/m/Y') : '—';
    }

    private function etiquetarCanal($canal)
    {
        $canal = strtoupper(trim((string)$canal));
        return self::CANALES[$canal] ?? $canal;
    }

    private function enteroPositivo($valor)
    {
        return ctype_digit((string)$valor) ? max(0, (int)$valor) : 0;
    }

    private function obtenerMapaPermisosTiposReporte()
    {
        return [
            'cartera' => 'reportes.seguimiento.cartera',
            'actividad' => 'reportes.seguimiento.actividad',
            'institucion' => 'reportes.seguimiento.institucion'
        ];
    }

    private function obtenerTiposReportePermitidos()
    {
        $permitidos = [];

        foreach ($this->obtenerMapaPermisosTiposReporte() as $tipo => $permiso) {
            if (tienePermiso($permiso)) {
                $permitidos[$tipo] = true;
            }
        }

        return $permitidos;
    }

    private function obtenerTipoReportePredeterminado()
    {
        $permitidos = $this->obtenerTiposReportePermitidos();

        foreach (['cartera', 'actividad', 'institucion'] as $tipo) {
            if (!empty($permitidos[$tipo])) {
                return $tipo;
            }
        }

        return 'cartera';
    }

    private function puedeGenerarTipoReporte($tipo)
    {
        $mapa = $this->obtenerMapaPermisosTiposReporte();
        $tipo = strtolower(trim((string)$tipo));

        return isset($mapa[$tipo]) && tienePermiso($mapa[$tipo]);
    }

    private function validarAccesoReporteSeguimiento()
    {
        if (!empty($this->obtenerTiposReportePermitidos())) {
            return;
        }

        http_response_code(403);
        die('No tienes permiso para generar reportes de seguimiento.');
    }

    private function validarTipoReportePermitido($tipo)
    {
        if ($this->puedeGenerarTipoReporte($tipo)) {
            return;
        }

        http_response_code(403);
        die('No tienes permiso para generar este tipo de reporte.');
    }

    private function resolverModoSeguimiento()
    {
        if ((int)($_SESSION['rol_id'] ?? 0) === 1) {
            return 'administrador';
        }

        if (tienePermiso('seguimientos_vinculacion.supervisar')) {
            return 'supervisor';
        }

        return 'analista';
    }

    private function obtenerTerritoriosPorModo($modelo, $usuarioId, $modo)
    {
        if ($modo === 'administrador') {
            return $modelo->obtenerEstadosAdministrador();
        }

        if ($modo === 'supervisor') {
            return $modelo->obtenerEstadosSupervisadosCuentaClave($usuarioId);
        }

        return $modelo->obtenerEstadosAsignadosAnalista($usuarioId);
    }

    private function obtenerSeguimientosPorModo($modelo, $usuarioId, $estadoId, $modo, $filtros)
    {
        if ($modo === 'administrador') {
            return $modelo->obtenerSeguimientosAdministradorEstado($estadoId, $filtros);
        }

        if ($modo === 'supervisor') {
            return $modelo->obtenerSeguimientosSupervisorEstado(
                $usuarioId,
                $estadoId,
                $filtros
            );
        }

        return $modelo->obtenerSeguimientosAnalistaEstado(
            $usuarioId,
            $estadoId,
            $filtros
        );
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' . BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        if (
            !tienePermiso('reportes.ver') ||
            !tienePermiso($codigo)
        ) {
            header(
                'Location: ' . BASE_URL .
                'index.php?controller=home&action=index'
            );
            exit;
        }
    }
}
