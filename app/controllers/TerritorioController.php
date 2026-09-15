<?php

require_once __DIR__ . '/../models/TerritorioModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class TerritorioController
{
    private $tiposAsignacion = [
        'CUENTA_CLAVE',
        'ANALISTA_DATOS',
        'ASESOR'
    ];

    public function index()
    {
        $this->validarPermiso('territorios.ver');

        $modeloTerritorio = new TerritorioModel();
        $filtros = $this->obtenerFiltros();

        $estados = $modeloTerritorio->obtenerEstados($filtros);
        $resumenTerritorial = $modeloTerritorio->obtenerResumenTerritorial();
        $cuentasClaveFiltro = $modeloTerritorio->obtenerUsuariosCuentaClave();
        $analistasFiltro = $modeloTerritorio->obtenerUsuariosAnalistas();

        $mensajeExito = $_SESSION['mensaje_territorio'] ?? '';
        $mensajeError = $_SESSION['error_territorio'] ?? '';
        $erroresFormulario = $_SESSION['errores_territorio'] ?? [];
        $datosFormulario = $_SESSION['datos_territorio'] ?? [];
        $modalAbierto = $_SESSION['modal_territorio'] ?? '';

        unset(
            $_SESSION['mensaje_territorio'],
            $_SESSION['error_territorio'],
            $_SESSION['errores_territorio'],
            $_SESSION['datos_territorio'],
            $_SESSION['modal_territorio']
        );

        $tituloPagina = 'Territorios y asignaciones';
        $subtituloPagina = 'Administra la distribución territorial del equipo.';
        $opcionActiva = 'territorios';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/territorios/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function tabla()
    {
        $this->validarPermiso('territorios.ver');

        $modeloTerritorio = new TerritorioModel();
        $filtros = $this->obtenerFiltros();
        $estados = $modeloTerritorio->obtenerEstados($filtros);

        require_once __DIR__ . '/../views/territorios/tabla.php';
    }

    public function resumen()
    {
        $this->validarPermiso('territorios.ver');

        $modeloTerritorio = new TerritorioModel();
        $this->responderJson([
            'ok' => true,
            'resumen' => $modeloTerritorio->obtenerResumenTerritorial()
        ]);
    }

    public function detalle()
    {
        $this->validarPermiso('territorios.ver');

        $modeloTerritorio = new TerritorioModel();
        $estadoId = (int)($_GET['id'] ?? 0);
        $estado = $modeloTerritorio->buscarEstadoPorId($estadoId);

        if (!$estado) {
            http_response_code(404);
            echo 'No fue posible consultar el territorio.';
            return;
        }

        $equipoTerritorial = $modeloTerritorio->obtenerEquipoTerritorial($estadoId);
        $analistasSinCuentaClave =
            $modeloTerritorio->obtenerAnalistasSinCuentaClave($estadoId);
        $asesoresTerritorio = $modeloTerritorio->obtenerAsesoresActivos($estadoId);
        $historialAsignaciones =
            $modeloTerritorio->obtenerHistorialAsignaciones($estadoId);
        $movimientosTerritoriales =
            $modeloTerritorio->obtenerBitacoraMovimientos($estadoId);

        require_once __DIR__ . '/../views/territorios/detalle.php';
    }

    public function equipo()
    {
        $this->validarPermiso('territorios.asignar');

        $modeloTerritorio = new TerritorioModel();
        $estadoId = (int)($_GET['id'] ?? 0);
        $estado = $modeloTerritorio->buscarEstadoPorId($estadoId);

        if (!$estado) {
            http_response_code(404);
            echo 'No fue posible consultar el equipo territorial.';
            return;
        }

        $equipoTerritorial = $modeloTerritorio->obtenerEquipoTerritorial($estadoId);
        $analistasSinCuentaClave =
            $modeloTerritorio->obtenerAnalistasSinCuentaClave($estadoId);
        $usuariosCuentaClave = $modeloTerritorio->obtenerUsuariosCuentaClave();
        $usuariosAnalistas = $modeloTerritorio->obtenerUsuariosAnalistas();
        $asesoresTerritorio = $modeloTerritorio->obtenerAsesoresActivos($estadoId);
        $usuariosAsesores = $modeloTerritorio->obtenerUsuariosAsesores();

        require_once __DIR__ . '/../views/territorios/equipo.php';
    }

    public function guardarAsignacion()
    {
        $this->validarPermiso('territorios.asignar');
        $this->validarMetodoPost();

        $modeloTerritorio = new TerritorioModel();
        $datos = $this->limpiarDatosAsignacion($_POST);
        $errores = $this->validarDatosAsignacion($modeloTerritorio, $datos);

        if (!empty($errores)) {
            if ($this->esSolicitudFetch()) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'Revisa los datos del equipo territorial.',
                    'errores' => $errores
                ], 422);
            }

            $this->volverConErrores('equipo', $errores, $datos);
        }

        $usuarioAccionId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($datos['tipo_asignacion'] === 'CUENTA_CLAVE') {
            $resultado = $modeloTerritorio->crearCuentaClave($datos, $usuarioAccionId);
            $mensajeExito = 'Cuenta Clave asignada correctamente.';
        } elseif ($datos['tipo_asignacion'] === 'ANALISTA_DATOS') {
            $resultado = $modeloTerritorio->crearAnalista($datos, $usuarioAccionId);
            $mensajeExito = 'Analista asignado correctamente.';
        } else {
            $resultado = $modeloTerritorio->crearAsesor($datos, $usuarioAccionId);
            $mensajeExito = 'Asesor asignado correctamente.';
        }

        if ($this->esSolicitudFetch()) {
            $this->responderJson([
                'ok' => (bool)$resultado,
                'mensaje' => $resultado
                    ? $mensajeExito
                    : 'No fue posible guardar la asignación.'
            ], $resultado ? 200 : 500);
        }

        if ($resultado) {
            $_SESSION['mensaje_territorio'] = $mensajeExito;
        } else {
            $_SESSION['error_territorio'] =
                'No fue posible registrar la asignación.';
        }

        $this->redirigirATerritorios();
    }

    public function reasociarAnalistaCuentaClave()
    {
        $this->validarPermiso('territorios.asignar');
        $this->validarMetodoPost();

        $modeloTerritorio = new TerritorioModel();
        $analistaAsignacionId = (int)($_POST['asignacion_analista_id'] ?? 0);
        $cuentaClaveAsignacionId = (int)($_POST['cuenta_clave_asignacion_id'] ?? 0);
        $errores = $this->validarReasignacionAnalista(
            $modeloTerritorio,
            $analistaAsignacionId,
            $cuentaClaveAsignacionId
        );

        if (!empty($errores)) {
            if ($this->esSolicitudFetch()) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'Revisa los datos de reasignación.',
                    'errores' => $errores
                ], 422);
            }

            $_SESSION['error_territorio'] = 'Revisa los datos de reasignación.';
            $this->redirigirATerritorios();
        }

        $resultado = $modeloTerritorio->reasociarAnalistaCuentaClave(
            $analistaAsignacionId,
            $cuentaClaveAsignacionId,
            (int)($_SESSION['usuario_id'] ?? 0)
        );

        if ($this->esSolicitudFetch()) {
            $this->responderJson([
                'ok' => (bool)$resultado,
                'mensaje' => $resultado
                    ? 'Analista vinculado a la Cuenta Clave correctamente.'
                    : 'No fue posible cambiar la Cuenta Clave del Analista.'
            ], $resultado ? 200 : 500);
        }

        if ($resultado) {
            $_SESSION['mensaje_territorio'] =
                'Analista vinculado a la Cuenta Clave correctamente.';
        } else {
            $_SESSION['error_territorio'] =
                'No fue posible cambiar la Cuenta Clave del Analista.';
        }

        $this->redirigirATerritorios();
    }

    public function finalizarAsignacion()
    {
        $this->validarPermiso('territorios.asignar');
        $this->validarMetodoPost();

        $modeloTerritorio = new TerritorioModel();
        $asignacionId = (int)($_POST['asignacion_id'] ?? 0);
        $fechaFinOrigen = trim((string)($_POST['fecha_fin'] ?? ''));
        $fechaFin = $this->normalizarFecha($fechaFinOrigen);
        $finalizarEquipo = (string)($_POST['finalizar_equipo'] ?? '0') === '1';

        if ($fechaFinOrigen !== '' && $fechaFin === null) {
            $this->responderErrorFinalizacion(
                'La fecha de finalización no es válida.',
                'La fecha de finalización no es válida.'
            );
        }

        if ($fechaFin === null) {
            $fechaFin = date('Y-m-d');
        }

        if ($fechaFin > date('Y-m-d')) {
            $this->responderErrorFinalizacion(
                'La fecha de finalización no puede ser futura.',
                'Finaliza la asignación con la fecha de hoy o una fecha anterior.'
            );
        }

        $asignacion = $modeloTerritorio->buscarAsignacionPorId($asignacionId);

        if (!$asignacion || (int)$asignacion['activo'] !== 1) {
            if ($this->esSolicitudFetch()) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'La asignación seleccionada no es válida.',
                    'errores' => [
                        'asignacion_id' => 'La asignación seleccionada no es válida.'
                    ]
                ], 422);
            }

            $_SESSION['error_territorio'] =
                'La asignación seleccionada no es válida.';
            $this->redirigirATerritorios();
        }

        if (
            !empty($asignacion['fecha_inicio']) &&
            $fechaFin < $asignacion['fecha_inicio']
        ) {
            $this->responderErrorFinalizacion(
                'La fecha de finalización no es válida.',
                'La fecha de finalización no puede ser anterior al inicio.'
            );
        }

        $tieneAnalistas = false;

        if ($asignacion['tipo_asignacion'] === 'CUENTA_CLAVE') {
            $tieneAnalistas =
                $modeloTerritorio->cuentaClaveTieneAnalistasActivos($asignacionId);
        }

        $usuarioAccionId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($tieneAnalistas && $finalizarEquipo) {
            $resultado = $modeloTerritorio->finalizarCuentaClaveConEquipo(
                $asignacionId,
                $fechaFin,
                $usuarioAccionId
            );
        } elseif ($tieneAnalistas) {
            $resultado = $modeloTerritorio->finalizarCuentaClaveSinEquipo(
                $asignacionId,
                $fechaFin,
                $usuarioAccionId
            );
        } else {
            $resultado = $modeloTerritorio->finalizarAsignacion(
                $asignacionId,
                $fechaFin,
                $usuarioAccionId
            );
        }

        $mensajeFinalizacion = $asignacion['tipo_asignacion'] === 'ASESOR'
            ? 'Asesor desasignado correctamente.'
            : 'Asignación finalizada correctamente.';

        if ($this->esSolicitudFetch()) {
            $this->responderJson([
                'ok' => (bool)$resultado,
                'mensaje' => $resultado
                    ? $mensajeFinalizacion
                    : 'No fue posible finalizar la asignación.'
            ], $resultado ? 200 : 500);
        }

        if ($resultado) {
            $_SESSION['mensaje_territorio'] = $mensajeFinalizacion;
        } else {
            $_SESSION['error_territorio'] =
                'No fue posible finalizar la asignación.';
        }

        $this->redirigirATerritorios();
    }

    public function actualizarEstado()
    {
        $this->actualizarFichaTerritorial();
    }

    public function actualizarFichaTerritorial()
    {
        $this->validarPermiso('territorios.ver');
        $estadoId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $mensaje =
            'La ficha territorial se administra desde Información territorial.';

        if ($this->esSolicitudFetch()) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $mensaje,
                'redirect' => tienePermiso('data_territorial.ver')
                    ? BASE_URL . 'index.php?controller=dataTerritorial&action=index&estado_id=' . $estadoId
                    : null
            ], 409);
        }

        if (tienePermiso('data_territorial.ver')) {
            header(
                'Location: ' . BASE_URL .
                'index.php?controller=dataTerritorial&action=index&estado_id=' .
                $estadoId
            );
            exit;
        }

        $_SESSION['error_territorio'] = $mensaje;
        $this->redirigirATerritorios();
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            if ($this->esSolicitudFetch()) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'Tu sesión no está activa.'
                ], 401);
            }

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        if (!tienePermiso($codigo)) {
            if ($this->esSolicitudFetch()) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'No tienes permiso para realizar esta acción.'
                ], 403);
            }

            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );
            exit;
        }
    }

    private function validarMetodoPost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->esSolicitudFetch()) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'La solicitud no es válida.'
                ], 405);
            }

            $this->redirigirATerritorios();
        }
    }

    private function obtenerFiltros()
    {
        $cuentaClave = $_GET['cuenta_clave'] ?? '';
        $analista = $_GET['analista'] ?? '';
        $estadoAsignacion = $_GET['estado_asignacion'] ?? '';
        $estadosCuentaClave = ['con_cuenta_clave', 'sin_cuenta_clave'];
        $estadosAnalista = ['con_analista', 'sin_analista'];

        return [
            'buscar' => trim($_GET['buscar'] ?? ''),
            'cuenta_clave' => ctype_digit((string)$cuentaClave)
                ? (int)$cuentaClave
                : '',
            'cuenta_clave_filtro' => ctype_digit((string)$cuentaClave) ||
                in_array($cuentaClave, $estadosCuentaClave, true)
                    ? (string)$cuentaClave
                    : '',
            'estado_cuenta_clave' => in_array($cuentaClave, $estadosCuentaClave, true)
                ? $cuentaClave
                : '',
            'analista' => ctype_digit((string)$analista)
                ? (int)$analista
                : '',
            'analista_filtro' => ctype_digit((string)$analista) ||
                in_array($analista, $estadosAnalista, true)
                    ? (string)$analista
                    : '',
            'estado_analista' => in_array($analista, $estadosAnalista, true)
                ? $analista
                : '',
            'estado_asignacion' => in_array(
                $estadoAsignacion,
                [
                    'con_cuenta_clave',
                    'sin_cuenta_clave',
                    'con_analista',
                    'sin_analista',
                    'varias_cuenta_clave'
                ],
                true
            ) ? $estadoAsignacion : ''
        ];
    }

    private function limpiarDatosAsignacion($origen)
    {
        $fechaInicioOrigen = trim((string)($origen['fecha_inicio'] ?? ''));
        $fechaInicio = $this->normalizarFecha($fechaInicioOrigen);

        return [
            'estado_id' => (int)($origen['estado_id'] ?? 0),
            'usuario_id' => (int)($origen['usuario_id'] ?? 0),
            'cuenta_clave_asignacion_id' =>
                (int)($origen['cuenta_clave_asignacion_id'] ?? 0),
            'tipo_asignacion' => $origen['tipo_asignacion'] ?? '',
            'fecha_inicio_original' => $fechaInicioOrigen,
            'fecha_inicio' => $fechaInicio ?? date('Y-m-d'),
            'observaciones' => trim($origen['observaciones'] ?? '')
        ];
    }

    private function validarDatosAsignacion($modeloTerritorio, $datos)
    {
        $errores = [];
        $estado = $modeloTerritorio->buscarEstadoPorId((int)$datos['estado_id']);

        if (!$estado) {
            $errores['estado_id'] = 'El territorio seleccionado no es válido.';
        }

        if (!$this->tipoAsignacionValido($datos['tipo_asignacion'])) {
            $errores['tipo_asignacion'] = 'El tipo de asignación no es válido.';
        }

        $usuario = $modeloTerritorio->buscarUsuarioActivoPorId(
            (int)$datos['usuario_id']
        );

        if (!$usuario) {
            $errores['usuario_id'] = 'Selecciona un usuario activo válido.';
        } elseif (
            $datos['tipo_asignacion'] === 'CUENTA_CLAVE' &&
            $usuario['rol'] !== 'Cuenta Clave'
        ) {
            $errores['usuario_id'] =
                'Para Cuenta Clave selecciona un usuario con ese rol.';
        } elseif (
            $datos['tipo_asignacion'] === 'ANALISTA_DATOS' &&
            $usuario['rol'] !== 'Analista de Datos'
        ) {
            $errores['usuario_id'] =
                'Para Analista de Datos selecciona un usuario con ese rol.';
        } elseif (
            $datos['tipo_asignacion'] === 'ASESOR' &&
            !$this->esRolAsesor($usuario['rol'] ?? '')
        ) {
            $errores['usuario_id'] =
                'Para Asesor selecciona un usuario con ese rol.';
        }

        if (strlen($datos['observaciones']) > 255) {
            $errores['observaciones'] =
                'Las observaciones no deben superar 255 caracteres.';
        }

        if (
            $datos['fecha_inicio_original'] !== '' &&
            $this->normalizarFecha($datos['fecha_inicio_original']) === null
        ) {
            $errores['fecha_inicio'] = 'La fecha de inicio no es válida.';
        } elseif ($datos['fecha_inicio'] > date('Y-m-d')) {
            $errores['fecha_inicio'] =
                'La fecha de inicio no puede ser futura.';
        }

        if ($datos['tipo_asignacion'] === 'ANALISTA_DATOS') {
            $cuentaClave = $modeloTerritorio->buscarAsignacionPorId(
                (int)$datos['cuenta_clave_asignacion_id']
            );

            if (
                !$cuentaClave ||
                $cuentaClave['tipo_asignacion'] !== 'CUENTA_CLAVE' ||
                !$modeloTerritorio->asignacionEstaVigenteHoy($cuentaClave) ||
                (int)$cuentaClave['estado_id'] !== (int)$datos['estado_id']
            ) {
                $errores['cuenta_clave_asignacion_id'] =
                    'Selecciona una Cuenta Clave vigente del mismo territorio.';
            } elseif (
                !empty($cuentaClave['fecha_inicio']) &&
                $datos['fecha_inicio'] < $cuentaClave['fecha_inicio']
            ) {
                $errores['fecha_inicio'] =
                    'La fecha de inicio del Analista no puede ser anterior a la Cuenta Clave.';
            }
        }

        if (
            empty($errores) &&
            $datos['tipo_asignacion'] === 'CUENTA_CLAVE' &&
            $modeloTerritorio->existeCuentaClaveActiva(
                (int)$datos['estado_id'],
                (int)$datos['usuario_id']
            )
        ) {
            $errores['usuario_id'] =
                'La Cuenta Clave ya tiene una asignación abierta en este territorio.';
        }

        if (
            empty($errores) &&
            $datos['tipo_asignacion'] === 'ANALISTA_DATOS' &&
            $modeloTerritorio->existeAnalistaActivoEnEstado(
                (int)$datos['estado_id'],
                (int)$datos['usuario_id']
            )
        ) {
            $errores['usuario_id'] =
                'El Analista ya tiene una asignación abierta en este territorio.';
        }

        if (
            empty($errores) &&
            $datos['tipo_asignacion'] === 'ASESOR' &&
            $modeloTerritorio->existeAsesorActivoEnEstado(
                (int)$datos['estado_id'],
                (int)$datos['usuario_id']
            )
        ) {
            $errores['usuario_id'] =
                'El Asesor ya tiene una asignación abierta en este territorio.';
        }

        return $errores;
    }

    private function validarReasignacionAnalista(
        $modeloTerritorio,
        $analistaAsignacionId,
        $cuentaClaveAsignacionId
    ) {
        $errores = [];
        $analista = $modeloTerritorio->buscarAsignacionPorId($analistaAsignacionId);
        $cuentaClave = $modeloTerritorio->buscarAsignacionPorId($cuentaClaveAsignacionId);

        if (
            !$analista ||
            $analista['tipo_asignacion'] !== 'ANALISTA_DATOS' ||
            !$modeloTerritorio->asignacionEstaVigenteHoy($analista)
        ) {
            $errores['asignacion_analista_id'] =
                'Selecciona un Analista vigente válido.';
        }

        if (
            !$cuentaClave ||
            $cuentaClave['tipo_asignacion'] !== 'CUENTA_CLAVE' ||
            !$modeloTerritorio->asignacionEstaVigenteHoy($cuentaClave)
        ) {
            $errores['cuenta_clave_asignacion_id'] =
                'Selecciona una Cuenta Clave vigente.';
        }

        if (
            empty($errores) &&
            (int)$analista['estado_id'] !== (int)$cuentaClave['estado_id']
        ) {
            $errores['cuenta_clave_asignacion_id'] =
                'La Cuenta Clave debe pertenecer al mismo territorio del Analista.';
        }

        if (
            empty($errores) &&
            (int)($analista['cuenta_clave_asignacion_id'] ?? 0) ===
                (int)$cuentaClaveAsignacionId
        ) {
            $errores['cuenta_clave_asignacion_id'] =
                'El Analista ya está vinculado a esa Cuenta Clave.';
        }

        return $errores;
    }

    private function responderErrorFinalizacion($mensaje, $detalle)
    {
        if ($this->esSolicitudFetch()) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $mensaje,
                'errores' => ['fecha_fin' => $detalle]
            ], 422);
        }

        $_SESSION['error_territorio'] = $detalle;
        $this->redirigirATerritorios();
    }

    private function esRolAsesor($rol)
    {
        $rol = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string)$rol), 'UTF-8')
            : strtolower(trim((string)$rol));

        return in_array($rol, ['asesor', 'asesor de ventas'], true);
    }

    private function tipoAsignacionValido($tipo)
    {
        return in_array($tipo, $this->tiposAsignacion, true);
    }

    private function normalizarFecha($fecha)
    {
        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            return null;
        }

        $fechaObjeto = DateTime::createFromFormat('Y-m-d', $fecha);

        if (!$fechaObjeto || $fechaObjeto->format('Y-m-d') !== $fecha) {
            return null;
        }

        return $fecha;
    }

    private function volverConErrores($modal, $errores, $datos)
    {
        $_SESSION['errores_territorio'] = $errores;
        $_SESSION['datos_territorio'] = $datos;
        $_SESSION['modal_territorio'] = $modal;
        $this->redirigirATerritorios();
    }

    private function redirigirATerritorios()
    {
        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=territorio&action=index'
        );
        exit;
    }

    private function esSolicitudFetch()
    {
        return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
    }

    private function responderJson($datos, $codigoHttp = 200)
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
