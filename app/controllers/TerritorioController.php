<?php

require_once __DIR__ . '/../models/TerritorioModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class TerritorioController
{
    private $tiposAsignacion = [
        'CUENTA_CLAVE',
        'ANALISTA_DATOS'
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
        $historialAsignaciones =
            $modeloTerritorio->obtenerHistorialAsignaciones($estadoId);

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

        if ($datos['tipo_asignacion'] === 'CUENTA_CLAVE') {
            $resultado = $modeloTerritorio->crearCuentaClave($datos);
            $mensajeExito = 'Cuenta Clave asignada correctamente.';
        } else {
            $resultado = $modeloTerritorio->crearAnalista($datos);
            $mensajeExito = 'Analista asignado correctamente.';
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
            $cuentaClaveAsignacionId
        );

        if ($this->esSolicitudFetch()) {
            $this->responderJson([
                'ok' => (bool)$resultado,
                'mensaje' => $resultado
                    ? 'Analista asignado a la Cuenta Clave correctamente.'
                    : 'No fue posible reasignar el Analista.'
            ], $resultado ? 200 : 500);
        }

        if ($resultado) {
            $_SESSION['mensaje_territorio'] =
                'Analista asignado a la Cuenta Clave correctamente.';
        } else {
            $_SESSION['error_territorio'] =
                'No fue posible reasignar el Analista.';
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
            if ($this->esSolicitudFetch()) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'La fecha de finalización no es válida.',
                    'errores' => [
                        'fecha_fin' => 'La fecha de finalización no es válida.'
                    ]
                ], 422);
            }

            $_SESSION['error_territorio'] =
                'La fecha de finalización no es válida.';
            $this->redirigirATerritorios();
        }

        if ($fechaFin === null) {
            $fechaFin = date('Y-m-d');
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
            if ($this->esSolicitudFetch()) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' => 'La fecha de finalización no es válida.',
                    'errores' => [
                        'fecha_fin' =>
                            'La fecha de finalización no puede ser anterior al inicio.'
                    ]
                ], 422);
            }

            $_SESSION['error_territorio'] =
                'La fecha de finalización no puede ser anterior al inicio.';
            $this->redirigirATerritorios();
        }

        $tieneAnalistas = false;

        if ($asignacion['tipo_asignacion'] === 'CUENTA_CLAVE') {
            $tieneAnalistas =
                $modeloTerritorio->cuentaClaveTieneAnalistasActivos($asignacionId);

        }

        if ($tieneAnalistas && $finalizarEquipo) {
            $resultado = $modeloTerritorio->finalizarCuentaClaveConEquipo(
                $asignacionId,
                $fechaFin
            );
        } elseif ($tieneAnalistas) {
            $resultado = $modeloTerritorio->finalizarCuentaClaveSinEquipo(
                $asignacionId,
                $fechaFin
            );
        } else {
            $resultado = $modeloTerritorio->finalizarAsignacion(
                $asignacionId,
                $fechaFin
            );
        }

        if ($this->esSolicitudFetch()) {
            $this->responderJson([
                'ok' => (bool)$resultado,
                'mensaje' => $resultado
                    ? 'Asignación finalizada correctamente.'
                    : 'No fue posible finalizar la asignación.'
            ], $resultado ? 200 : 500);
        }

        if ($resultado) {
            $_SESSION['mensaje_territorio'] =
                'Asignación finalizada correctamente.';
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
        $this->validarPermiso('territorios.actualizar_ficha');
        $this->validarMetodoPost();

        $modeloTerritorio = new TerritorioModel();
        $estadoId = (int)($_POST['id'] ?? 0);
        $estado = $modeloTerritorio->buscarEstadoPorId($estadoId);
        $datos = $this->limpiarDatosEstado($_POST);
        $errores = $this->validarDatosEstado(
            $modeloTerritorio,
            $datos,
            $estadoId,
            $estado
        );

        if (!empty($errores)) {
            $datos['id'] = $estadoId;
            $datos['clave_inegi'] = $estado['clave_inegi'] ?? '';
            $datos['nombre'] = $estado['nombre'] ?? '';
            $datos['nombre_corto'] = $estado['nombre_corto'] ?? '';
            $this->volverConErrores('estado', $errores, $datos);
        }

        if ($modeloTerritorio->actualizarFichaTerritorial($estadoId, $datos)) {
            $_SESSION['mensaje_territorio'] =
                'Ficha territorial actualizada correctamente.';
        } else {
            $_SESSION['error_territorio'] =
                'No fue posible actualizar la ficha territorial.';
        }

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
        }

        if (strlen($datos['observaciones']) > 255) {
            $errores['observaciones'] =
                'Las observaciones no deben superar 255 caracteres.';
        }

        if (
            $datos['fecha_inicio_original'] !== '' &&
            $this->normalizarFecha($datos['fecha_inicio_original']) === null
        ) {
            $errores['fecha_inicio'] =
                'La fecha de inicio no es válida.';
        }

        if ($datos['tipo_asignacion'] === 'ANALISTA_DATOS') {
            $cuentaClave = $modeloTerritorio->buscarAsignacionPorId(
                (int)$datos['cuenta_clave_asignacion_id']
            );

            if (
                !$cuentaClave ||
                $cuentaClave['tipo_asignacion'] !== 'CUENTA_CLAVE' ||
                (int)$cuentaClave['activo'] !== 1 ||
                (int)$cuentaClave['estado_id'] !== (int)$datos['estado_id']
            ) {
                $errores['cuenta_clave_asignacion_id'] =
                    'Selecciona una Cuenta Clave activa para vincular al analista.';
            } elseif (
                !empty($cuentaClave['fecha_inicio']) &&
                $datos['fecha_inicio'] < $cuentaClave['fecha_inicio']
            ) {
                $errores['fecha_inicio'] =
                    'La fecha de inicio del analista no puede ser anterior a la Cuenta Clave.';
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
                'La Cuenta Clave ya tiene una asignación activa en este territorio.';
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
                'El analista ya tiene una asignación activa en este territorio.';
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
            (int)$analista['activo'] !== 1
        ) {
            $errores['asignacion_analista_id'] =
                'Selecciona un Analista activo válido.';
        } elseif (!empty($analista['cuenta_clave_asignacion_id'])) {
            $errores['asignacion_analista_id'] =
                'El Analista ya está asociado a una Cuenta Clave.';
        }

        if (
            !$cuentaClave ||
            $cuentaClave['tipo_asignacion'] !== 'CUENTA_CLAVE' ||
            (int)$cuentaClave['activo'] !== 1
        ) {
            $errores['cuenta_clave_asignacion_id'] =
                'Selecciona una Cuenta Clave activa.';
        }

        if (
            empty($errores) &&
            (int)$analista['estado_id'] !== (int)$cuentaClave['estado_id']
        ) {
            $errores['cuenta_clave_asignacion_id'] =
                'La Cuenta Clave debe pertenecer al mismo territorio del Analista.';
        }

        return $errores;
    }

    private function limpiarDatosEstado($origen)
    {
        return [
            'capital' => $this->valorNullable($origen['capital'] ?? ''),
            'titular_gobierno' => $this->valorNullable(
                $origen['titular_gobierno'] ?? ''
            ),
            'cargo_titular' => $this->valorNullable(
                $origen['cargo_titular'] ?? ''
            ),
            'partido_politico' => $this->valorNullable(
                $origen['partido_politico'] ?? ''
            ),
            'poblacion' => trim((string)($origen['poblacion'] ?? '')),
            'total_municipios' => trim((string)($origen['total_municipios'] ?? '')),
            'total_secretarias' => trim((string)($origen['total_secretarias'] ?? '')),
            'periodo_gobierno' => $this->valorNullable(
                $origen['periodo_gobierno'] ?? ''
            ),
            'telefono' => $this->valorNullable($origen['telefono'] ?? ''),
            'redes_sociales' => $this->valorNullable(
                $origen['redes_sociales'] ?? ''
            ),
            'fuente' => $this->valorNullable($origen['fuente'] ?? ''),
            'fecha_actualizacion' => $this->normalizarFechaHora(
                $origen['fecha_actualizacion'] ?? ''
            )
        ];
    }

    private function validarDatosEstado(
        $modeloTerritorio,
        &$datos,
        $estadoId,
        $estado
    ) {
        $errores = [];

        if (!$estado) {
            $errores[] = 'El territorio seleccionado no es válido.';
        }

        $this->validarEnteroFicha(
            $datos['poblacion'],
            'La población debe ser un número válido.',
            $errores
        );
        $this->validarEnteroFicha(
            $datos['total_municipios'],
            'El total de municipios debe ser un número válido.',
            $errores
        );
        $this->validarEnteroFicha(
            $datos['total_secretarias'],
            'El total de secretarías debe ser un número válido.',
            $errores
        );

        if (empty($errores)) {
            $datos['poblacion'] =
                $datos['poblacion'] === '' ? null : (int)$datos['poblacion'];
            $datos['total_municipios'] =
                $datos['total_municipios'] === '' ? null : (int)$datos['total_municipios'];
            $datos['total_secretarias'] =
                $datos['total_secretarias'] === '' ? null : (int)$datos['total_secretarias'];
        }

        return $errores;
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

    private function normalizarFechaHora($fecha)
    {
        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            return null;
        }

        $fechaObjeto = DateTime::createFromFormat('Y-m-d\TH:i', $fecha);

        if ($fechaObjeto) {
            return $fechaObjeto->format('Y-m-d H:i:s');
        }

        $fechaObjeto = DateTime::createFromFormat('Y-m-d H:i:s', $fecha);

        if ($fechaObjeto) {
            return $fechaObjeto->format('Y-m-d H:i:s');
        }

        return null;
    }

    private function valorNullable($valor)
    {
        $valor = trim((string)$valor);

        return $valor === '' ? null : $valor;
    }

    private function validarEnteroFicha($valor, $mensaje, &$errores)
    {
        if ($valor === '') {
            return;
        }

        if (!ctype_digit((string)$valor)) {
            $errores[] = $mensaje;
        }
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
