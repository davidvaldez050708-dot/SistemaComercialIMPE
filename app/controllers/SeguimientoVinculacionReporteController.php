<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';
require_once __DIR__ . '/../services/ReporteSeguimientoVinculacionPdfService.php';

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
        'WHATSAPP' => 'WhatsApp',
        'CORREO' => 'Correo',
        'NOTA' => 'Otro',
        'SISTEMA' => 'Sistema'
    ];

    public function index()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');
        $contexto = $this->construirContextoReporte(false);
        extract($contexto, EXTR_SKIP);

        $errorExportacionPdf = (string)($_SESSION['error_reporte_seguimiento_pdf'] ?? '');
        unset($_SESSION['error_reporte_seguimiento_pdf']);
        $urlExportarPdf = $generarReporte && $errorFiltros === ''
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

    public function exportarPdf()
    {
        $this->validarPermiso('seguimientos_vinculacion.ver');
        $contexto = $this->construirContextoReporte(true);

        if ((string)$contexto['errorFiltros'] !== '') {
            $this->redirigirErrorPdf(
                (string)$contexto['errorFiltros'],
                $contexto['filtrosReporte']
            );
        }

        $servicio = new ReporteSeguimientoVinculacionPdfService();
        $resultado = $servicio->generar([
            'resumen_filtros' => $contexto['resumenFiltros'],
            'resumen_reporte' => $contexto['resumenReporte'],
            'seguimientos' => $contexto['seguimientosReporte'],
            'etiquetas_estatus' => self::ESTADOS_SEGUIMIENTO,
            'fecha_generacion' => date('d/m/Y H:i')
        ]);

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

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Content-Length: ' . strlen($contenidoPdf));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $contenidoPdf;
        exit;
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

        foreach ($territoriosPorId as $territorioId => $territorio) {
            $municipiosPorEstado[$territorioId] = $modelo->obtenerMunicipiosActivosEstado(
                $territorioId
            );
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
        $seguimientosDisponibles = [];

        foreach ($territoriosConsulta as $territorioConsultaId => $territorio) {
            $seguimientosEstado = $this->obtenerSeguimientosPorModo(
                $modelo,
                $usuarioId,
                (int)$territorioConsultaId,
                $modoSeguimiento,
                []
            );

            foreach ($seguimientosEstado as $seguimiento) {
                $seguimiento['estado_id'] = (int)$territorioConsultaId;
                $seguimiento['estado_nombre'] = (string)($territorio['nombre'] ?? '');
                $seguimientosDisponibles[] = $seguimiento;
            }
        }

        $institucionesDisponibles = $this->obtenerInstitucionesDisponibles(
            $seguimientosDisponibles
        );
        $responsablesDisponibles = $this->obtenerResponsablesDisponibles(
            $seguimientosDisponibles
        );
        $canalesDisponibles = $this->obtenerCanalesDisponibles(
            $seguimientosDisponibles
        );

        if (
            $filtrosReporte['institucion'] !== '' &&
            !isset($institucionesDisponibles[$filtrosReporte['institucion']])
        ) {
            $filtrosReporte['institucion'] = '';
        }

        if (
            (int)$filtrosReporte['responsable_id'] > 0 &&
            !isset($responsablesDisponibles[(int)$filtrosReporte['responsable_id']])
        ) {
            $filtrosReporte['responsable_id'] = 0;
        }

        if (
            $filtrosReporte['tipo_actividad'] !== '' &&
            !isset($canalesDisponibles[$filtrosReporte['tipo_actividad']])
        ) {
            $filtrosReporte['tipo_actividad'] = '';
        }

        $errorFiltros = $this->validarPeriodo($filtrosReporte);
        $generarReporte = $forzarGeneracion || (string)($_GET['generar'] ?? '') === '1';
        $seguimientosReporte = [];
        $resumenReporte = $this->crearResumenReporte([]);

        if ($generarReporte && $errorFiltros === '') {
            $seguimientosReporte = $this->aplicarFiltrosReporte(
                $seguimientosDisponibles,
                $filtrosReporte
            );
            $seguimientosReporte = array_map(
                [$this, 'prepararSeguimientoReporte'],
                $seguimientosReporte
            );
            $resumenReporte = $this->crearResumenReporte($seguimientosReporte);
        }

        $estadosSeguimiento = self::ESTADOS_SEGUIMIENTO;
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
            'resumenReporte' => $resumenReporte,
            'generarReporte' => $generarReporte,
            'errorFiltros' => $errorFiltros
        ];
    }

    private function construirUrlExportacion(array $filtros)
    {
        $parametros = [
            'controller' => 'seguimientoVinculacionReporte',
            'action' => 'exportarPdf',
            'fecha_inicial' => (string)$filtros['fecha_inicial'],
            'fecha_final' => (string)$filtros['fecha_final'],
            'estado_id' => (int)$filtros['estado_id'],
            'municipio_id' => (int)$filtros['municipio_id'],
            'institucion' => (string)$filtros['institucion'],
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
            'fecha_inicial' => (string)$filtros['fecha_inicial'],
            'fecha_final' => (string)$filtros['fecha_final'],
            'estado_id' => (int)$filtros['estado_id'],
            'municipio_id' => (int)$filtros['municipio_id'],
            'institucion' => (string)$filtros['institucion'],
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

        return [
            'fecha_inicial' => $this->normalizarFecha($_GET['fecha_inicial'] ?? ''),
            'fecha_final' => $this->normalizarFecha($_GET['fecha_final'] ?? ''),
            'estado_id' => $this->enteroPositivo($_GET['estado_id'] ?? 0),
            'municipio_id' => $this->enteroPositivo($_GET['municipio_id'] ?? 0),
            'institucion' => trim((string)($_GET['institucion'] ?? '')),
            'responsable_id' => $this->enteroPositivo($_GET['responsable_id'] ?? 0),
            'estado_seguimiento' => isset(self::ESTADOS_SEGUIMIENTO[$estadoSeguimiento])
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
                    (int)$filtros['municipio_id'] > 0 &&
                    (int)($seguimiento['municipio_id'] ?? 0) !== (int)$filtros['municipio_id']
                ) {
                    return false;
                }

                if (
                    (int)$filtros['responsable_id'] > 0 &&
                    (int)($seguimiento['analista_id'] ?? 0) !== (int)$filtros['responsable_id']
                ) {
                    return false;
                }

                if (
                    $filtros['estado_seguimiento'] !== '' &&
                    (string)($seguimiento['estado_seguimiento'] ?? '') !== $filtros['estado_seguimiento']
                ) {
                    return false;
                }

                if (
                    $filtros['institucion'] !== '' &&
                    (string)($seguimiento['nombre_entidad'] ?? '') !== $filtros['institucion']
                ) {
                    return false;
                }

                if (
                    $filtros['tipo_actividad'] !== '' &&
                    strtoupper((string)($seguimiento['ultimo_canal'] ?? '')) !== $filtros['tipo_actividad']
                ) {
                    return false;
                }

                if (!$this->coincidePeriodo($seguimiento, $filtros)) {
                    return false;
                }

                $diasMinimos = (int)$filtros['dias_sin_actividad'];

                if ($diasMinimos > 0) {
                    $diasSinActividad = $this->calcularDiasSinActividad(
                        $seguimiento['ultima_interaccion_at'] ?? ''
                    );

                    if ($diasSinActividad === null || $diasSinActividad <= $diasMinimos) {
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
        $seguimiento['ultima_actividad_label'] = $this->formatearFechaHora(
            $seguimiento['ultima_interaccion_at'] ?? ''
        );
        $seguimiento['dias_sin_actividad'] = $this->calcularDiasSinActividad(
            $seguimiento['ultima_interaccion_at'] ?? ''
        );
        $seguimiento['proxima_accion_label'] = $this->obtenerProximaAccionLabel($seguimiento);
        $seguimiento['canal_label'] = $this->etiquetarCanal(
            $seguimiento['ultimo_canal'] ?? ''
        );

        return $seguimiento;
    }

    private function crearResumenReporte($seguimientos)
    {
        $porEstatus = [];
        $porMunicipio = [];
        $sinActividad = 0;
        $masSieteDias = 0;

        foreach ($seguimientos as $seguimiento) {
            $estado = (string)($seguimiento['estado_seguimiento'] ?? '');

            if ($estado !== '') {
                if (!isset($porEstatus[$estado])) {
                    $porEstatus[$estado] = 0;
                }
                $porEstatus[$estado]++;
            }

            $municipio = trim((string)($seguimiento['municipio'] ?? ''));

            if ($municipio !== '') {
                if (!isset($porMunicipio[$municipio])) {
                    $porMunicipio[$municipio] = 0;
                }
                $porMunicipio[$municipio]++;
            }

            $dias = $seguimiento['dias_sin_actividad'] ?? null;

            if ($dias === null) {
                $sinActividad++;
            } elseif ((int)$dias > 7) {
                $masSieteDias++;
            }
        }

        uksort($porEstatus, function ($a, $b) {
            $orden = array_keys(self::ESTADOS_SEGUIMIENTO);
            return array_search($a, $orden, true) <=> array_search($b, $orden, true);
        });
        arsort($porMunicipio);

        return [
            'total' => count($seguimientos),
            'sin_actividad' => $sinActividad,
            'mas_7_dias' => $masSieteDias,
            'por_estatus' => $porEstatus,
            'por_municipio' => $porMunicipio
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
            'Estatus' => $filtros['estado_seguimiento'] !== ''
                ? (self::ESTADOS_SEGUIMIENTO[$filtros['estado_seguimiento']] ?? 'Todos')
                : 'Todos',
            'Tipo de actividad' => $filtros['tipo_actividad'] !== ''
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
            $canal = strtoupper(trim((string)($seguimiento['ultimo_canal'] ?? '')));

            if ($canal !== '') {
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

        if (!tienePermiso($codigo)) {
            header(
                'Location: ' . BASE_URL .
                'index.php?controller=home&action=index'
            );
            exit;
        }
    }
}
