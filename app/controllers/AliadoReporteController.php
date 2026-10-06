<?php

require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../models/AliadoModel.php';
require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../services/ReporteAliadosPanoramaService.php';
require_once __DIR__ . '/../services/ReporteAliadosPdfService.php';

class AliadoReporteController
{
    public function index()
    {
        $this->validarAcceso();

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;

        $modeloAliado = new AliadoModel();
        $modeloSeguimiento = new SeguimientoVinculacionModel();

        $territorios = $esAdministrador
            ? $modeloSeguimiento->obtenerEstadosAdministrador()
            : $modeloSeguimiento->obtenerEstadosSupervisadosCuentaClave(
                $usuarioId
            );

        $idsTerritorios = [];
        foreach ($territorios as $territorio) {
            $id = (int)($territorio['id'] ?? 0);
            if ($id > 0) {
                $idsTerritorios[$id] = true;
            }
        }

        $estadoId = max(0, (int)($_GET['estado_id'] ?? 0));
        if ($estadoId > 0 && !isset($idsTerritorios[$estadoId])) {
            http_response_code(403);
            die('No tienes acceso al territorio seleccionado.');
        }

        $aliadosParaMunicipios = $modeloAliado->obtenerListado(
            $usuarioId,
            $esAdministrador
        );

        $municipios = [];
        foreach ($aliadosParaMunicipios as $aliado) {
            $municipioId = (int)($aliado['municipio_id'] ?? 0);
            $municipioNombre = trim(
                (string)($aliado['municipio_nombre'] ?? '')
            );

            if ($municipioId <= 0 || $municipioNombre === '') {
                continue;
            }

            $municipios[$municipioId] = [
                'id' => $municipioId,
                'nombre' => $municipioNombre,
                'estado_id' => (int)($aliado['estado_id'] ?? 0),
                'estado_nombre' => (string)($aliado['estado_nombre'] ?? '')
            ];
        }

        uasort($municipios, static function ($a, $b) {
            $comparacion = strcasecmp(
                (string)($a['estado_nombre'] ?? ''),
                (string)($b['estado_nombre'] ?? '')
            );

            return $comparacion !== 0
                ? $comparacion
                : strnatcasecmp(
                    (string)($a['nombre'] ?? ''),
                    (string)($b['nombre'] ?? '')
                );
        });

        $municipioId = max(0, (int)($_GET['municipio_id'] ?? 0));
        if (
            $municipioId > 0 &&
            (
                !isset($municipios[$municipioId]) ||
                (
                    $estadoId > 0 &&
                    (int)($municipios[$municipioId]['estado_id'] ?? 0) !==
                        $estadoId
                )
            )
        ) {
            $municipioId = 0;
        }

        if ($municipioId > 0 && $estadoId <= 0) {
            $estadoId = (int)($municipios[$municipioId]['estado_id'] ?? 0);
        }

        $situacionesPermitidas = [
            'todos',
            'con_difusion',
            'sin_difusion',
            'pendientes',
            'vencidos',
            'esperando_respuesta',
            'sin_respuesta',
            'solicita_informacion',
            'difusion_confirmada',
            'no_participara'
        ];
        $situacion = strtolower(trim(
            (string)($_GET['situacion'] ?? 'todos')
        ));
        if (!in_array($situacion, $situacionesPermitidas, true)) {
            $situacion = 'todos';
        }

        $filtroPeriodo = $this->normalizarFiltroPeriodo($_GET);
        $errorFiltroPeriodo = (string)(
            $filtroPeriodo['error'] ?? ''
        );

        $filtrosReporte = [
            'estado_id' => $estadoId,
            'municipio_id' => $municipioId,
            'situacion' => $situacion,
            'periodo' => $filtroPeriodo['periodo'],
            'fecha_desde' => $filtroPeriodo['fecha_desde'],
            'fecha_hasta' => $filtroPeriodo['fecha_hasta']
        ];

        $generarReporte = (string)($_GET['generar'] ?? '') === '1';
        $reporteAliados = null;

        if ($generarReporte) {
            $service = new ReporteAliadosPanoramaService();
            $reporteAliados = $service->prepararDatos(
                $usuarioId,
                $esAdministrador,
                $filtrosReporte
            );
        }

        $errorExportacionPdf = (string)(
            $_SESSION['error_reporte_aliados_pdf'] ?? ''
        );
        unset($_SESSION['error_reporte_aliados_pdf']);

        $urlExportarPdf = '';
        if (
            $generarReporte &&
            is_array($reporteAliados) &&
            tienePermiso('reportes.exportar')
        ) {
            $urlExportarPdf = BASE_URL . 'index.php?' . http_build_query(
                [
                    'controller' => 'aliadoReporte',
                    'action' => 'exportarPdf',
                    'estado_id' => $estadoId,
                    'municipio_id' => $municipioId,
                    'situacion' => $situacion,
                    'periodo' => $filtroPeriodo['periodo'],
                    'fecha_desde' => $filtroPeriodo['fecha_desde'],
                    'fecha_hasta' => $filtroPeriodo['fecha_hasta']
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        }

        $tituloPagina = 'Reporte de Aliados';
        $subtituloPagina =
            'Panorama ejecutivo de la red institucional y su seguimiento.';
        $opcionActiva = 'reportes';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/reportes/aliados.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function exportarPdf()
    {
        $this->validarAcceso(true);

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $esAdministrador = (int)($_SESSION['rol_id'] ?? 0) === 1;
        $estadoId = max(0, (int)($_GET['estado_id'] ?? 0));
        $municipioId = max(0, (int)($_GET['municipio_id'] ?? 0));

        $situaciones = [
            'todos' => 'Todos los aliados',
            'con_difusion' => 'Con difusión',
            'sin_difusion' => 'Sin difusión',
            'pendientes' => 'Seguimiento pendiente',
            'vencidos' => 'Seguimiento vencido',
            'esperando_respuesta' => 'Esperando respuesta',
            'sin_respuesta' => 'Sin respuesta',
            'solicita_informacion' => 'Solicita información',
            'difusion_confirmada' => 'Difusión confirmada',
            'no_participara' => 'No participará'
        ];

        $situacion = strtolower(trim(
            (string)($_GET['situacion'] ?? 'todos')
        ));
        if (!isset($situaciones[$situacion])) {
            $situacion = 'todos';
        }

        $filtroPeriodo = $this->normalizarFiltroPeriodo($_GET);

        if ((string)($filtroPeriodo['error'] ?? '') !== '') {
            $_SESSION['error_reporte_aliados_pdf'] =
                (string)$filtroPeriodo['error'];

            header(
                'Location: ' .
                BASE_URL .
                'index.php?' .
                http_build_query(
                    [
                        'controller' => 'aliadoReporte',
                        'action' => 'index',
                        'estado_id' => $estadoId,
                        'municipio_id' => $municipioId,
                        'situacion' => $situacion,
                        'periodo' => 'historico',
                        'generar' => 1
                    ],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                )
            );
            exit;
        }

        $modeloAliado = new AliadoModel();
        $modeloSeguimiento = new SeguimientoVinculacionModel();

        $territorios = $esAdministrador
            ? $modeloSeguimiento->obtenerEstadosAdministrador()
            : $modeloSeguimiento->obtenerEstadosSupervisadosCuentaClave(
                $usuarioId
            );

        $estadoNombre = '';
        $idsTerritorios = [];
        foreach ($territorios as $territorio) {
            $id = (int)($territorio['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $idsTerritorios[$id] = true;

            if ($id === $estadoId) {
                $estadoNombre = trim(
                    (string)($territorio['nombre'] ?? '')
                );
            }
        }

        if ($estadoId > 0 && !isset($idsTerritorios[$estadoId])) {
            http_response_code(403);
            die('No tienes acceso al territorio seleccionado.');
        }

        $municipioNombre = '';
        if ($municipioId > 0) {
            $aliadosMunicipio = $modeloAliado->obtenerListado(
                $usuarioId,
                $esAdministrador,
                ['municipio_id' => $municipioId]
            );

            $municipioValido = false;
            foreach ($aliadosMunicipio as $aliado) {
                $estadoAliado = (int)($aliado['estado_id'] ?? 0);
                $municipioAliado = (int)($aliado['municipio_id'] ?? 0);

                if ($municipioAliado !== $municipioId) {
                    continue;
                }

                if ($estadoId > 0 && $estadoAliado !== $estadoId) {
                    continue;
                }

                if (
                    $estadoId <= 0 &&
                    !isset($idsTerritorios[$estadoAliado])
                ) {
                    continue;
                }

                $municipioValido = true;
                $municipioNombre = trim(
                    (string)($aliado['municipio_nombre'] ?? '')
                );

                if ($estadoId <= 0) {
                    $estadoId = $estadoAliado;
                    foreach ($territorios as $territorio) {
                        if (
                            (int)($territorio['id'] ?? 0) ===
                            $estadoId
                        ) {
                            $estadoNombre = trim(
                                (string)($territorio['nombre'] ?? '')
                            );
                            break;
                        }
                    }
                }

                break;
            }

            if (!$municipioValido) {
                http_response_code(403);
                die('No tienes acceso al municipio seleccionado.');
            }
        }

        $filtros = [
            'estado_id' => $estadoId,
            'municipio_id' => $municipioId,
            'situacion' => $situacion,
            'periodo' => $filtroPeriodo['periodo'],
            'fecha_desde' => $filtroPeriodo['fecha_desde'],
            'fecha_hasta' => $filtroPeriodo['fecha_hasta']
        ];

        try {
            $reporte = (new ReporteAliadosPanoramaService())
                ->prepararDatos(
                    $usuarioId,
                    $esAdministrador,
                    $filtros
                );

            $reporte['estado_nombre'] = $estadoNombre;
            $reporte['municipio_nombre'] = $municipioNombre;
            $reporte['situacion_label'] =
                $situaciones[$situacion] ?? 'Todos los aliados';
            $reporte['fecha_generacion'] = date('d/m/Y H:i');
            $reporte['generado_por'] = trim(
                (string)($_SESSION['nombre'] ?? '') . ' ' .
                (string)($_SESSION['apellidos'] ?? '')
            );
            $reporte['generado_por_rol'] =
                (string)($_SESSION['rol'] ?? '');

            $resultado = (new ReporteAliadosPdfService())
                ->generar($reporte);

            if (!($resultado['ok'] ?? false)) {
                error_log(
                    '[reporte_aliados_pdf] ' .
                    (string)(
                        $resultado['mensaje_tecnico'] ??
                        $resultado['mensaje'] ??
                        'Error sin detalle.'
                    )
                );

                $_SESSION['error_reporte_aliados_pdf'] =
                    (string)(
                        $resultado['mensaje'] ??
                        'No fue posible generar el PDF.'
                    );

                header(
                    'Location: ' .
                    BASE_URL .
                    'index.php?' .
                    http_build_query(
                        [
                            'controller' => 'aliadoReporte',
                            'action' => 'index',
                            'estado_id' => $estadoId,
                            'municipio_id' => $municipioId,
                            'situacion' => $situacion,
                            'periodo' => $filtroPeriodo['periodo'],
                            'fecha_desde' => $filtroPeriodo['fecha_desde'],
                            'fecha_hasta' => $filtroPeriodo['fecha_hasta'],
                            'generar' => 1
                        ],
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    )
                );
                exit;
            }

            $contenido = (string)($resultado['contenido_pdf'] ?? '');
            $nombreArchivo = (string)(
                $resultado['nombre_archivo'] ??
                'Reporte_Aliados_Corte_' . date('Y-m-d') . '.pdf'
            );

            header('Content-Type: application/pdf');
            header(
                'Content-Disposition: attachment; filename="' .
                $nombreArchivo .
                '"'
            );
            header('Content-Length: ' . strlen($contenido));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');

            echo $contenido;
            exit;
        } catch (Throwable $error) {
            error_log(
                '[reporte_aliados_pdf] ' . $error->getMessage()
            );

            $_SESSION['error_reporte_aliados_pdf'] =
                'No fue posible generar el PDF del reporte de aliados.';

            header(
                'Location: ' .
                BASE_URL .
                'index.php?' .
                http_build_query(
                    [
                        'controller' => 'aliadoReporte',
                        'action' => 'index',
                        'estado_id' => $estadoId,
                        'municipio_id' => $municipioId,
                        'situacion' => $situacion,
                        'generar' => 1
                    ],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                )
            );
            exit;
        }
    }

    private function normalizarFiltroPeriodo(array $fuente)
    {
        $periodo = strtolower(trim(
            (string)($fuente['periodo'] ?? 'historico')
        ));

        $permitidos = [
            'historico',
            'ultimos_7',
            'ultimos_30',
            'este_mes',
            'mes_anterior',
            'personalizado'
        ];

        if (!in_array($periodo, $permitidos, true)) {
            $periodo = 'historico';
        }

        $fechaDesde = trim(
            (string)($fuente['fecha_desde'] ?? '')
        );
        $fechaHasta = trim(
            (string)($fuente['fecha_hasta'] ?? '')
        );
        $error = '';

        if ($periodo !== 'personalizado') {
            $fechaDesde = '';
            $fechaHasta = '';
        } else {
            $desde = $this->parseFechaReporte($fechaDesde);
            $hasta = $this->parseFechaReporte($fechaHasta);

            if (!$desde || !$hasta) {
                $error =
                    'Selecciona una fecha inicial y final válidas para el periodo personalizado.';
            } elseif ($desde > $hasta) {
                $error =
                    'La fecha inicial del periodo no puede ser posterior a la fecha final.';
            } elseif (
                $desde > new DateTimeImmutable('today') ||
                $hasta > new DateTimeImmutable('today')
            ) {
                $error =
                    'El periodo del reporte no puede incluir fechas futuras.';
            }

            if ($error !== '') {
                $periodo = 'historico';
                $fechaDesde = '';
                $fechaHasta = '';
            }
        }

        return [
            'periodo' => $periodo,
            'fecha_desde' => $fechaDesde,
            'fecha_hasta' => $fechaHasta,
            'error' => $error
        ];
    }

    private function parseFechaReporte($valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $valor
        );
        $errores = DateTimeImmutable::getLastErrors();

        if (
            !$fecha ||
            (
                is_array($errores) &&
                (
                    (int)($errores['warning_count'] ?? 0) > 0 ||
                    (int)($errores['error_count'] ?? 0) > 0
                )
            )
        ) {
            return null;
        }

        return $fecha;
    }

    private function validarAcceso($requiereExportar = false)
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        if (
            !tienePermiso('reportes.ver') ||
            !tienePermiso('reportes.aliados.panorama') ||
            !tienePermiso('aliados.ver')
        ) {
            http_response_code(403);
            die('No tienes permiso para consultar este reporte.');
        }

        if (
            $requiereExportar &&
            !tienePermiso('reportes.exportar')
        ) {
            http_response_code(403);
            die('No tienes permiso para exportar este reporte.');
        }
    }
}
