<?php

require_once __DIR__ . '/../services/SeguimientoFlujoService.php';
require_once __DIR__ . '/../services/SeguimientoPostEnvioService.php';
require_once __DIR__ . '/../services/AgendaReunionService.php';
require_once __DIR__ . '/../services/ReunionFechaGuardService.php';
require_once __DIR__ . '/../services/ReunionResultadoService.php';
require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class SeguimientoFlujoController
{
    private $service;
    private $postEnvioService;
    private $agendaReunionService;
    private $reunionFechaGuardService;
    private $reunionResultadoService;

    public function __construct()
    {
        $this->service = new SeguimientoFlujoService();
        $this->postEnvioService = new SeguimientoPostEnvioService();
        $this->agendaReunionService = new AgendaReunionService();
        $this->reunionFechaGuardService = new ReunionFechaGuardService();
        $this->reunionResultadoService = new ReunionResultadoService();
    }

    public function estado()
    {
        header('Content-Type: application/json; charset=utf-8');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);

        if ($usuarioId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if ($seguimientoId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Selecciona un seguimiento válido.'
            ], 422);
        }

        $modoAcceso = $this->resolverModoAcceso();
        $seguimiento = $this->obtenerSeguimientoLectura(
            $seguimientoId,
            $usuarioId,
            $modoAcceso
        );

        if (!$seguimiento) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este seguimiento.'
            ], 403);
        }

        /*
         * Los servicios de ruta están construidos alrededor del Analista
         * responsable. Para Cuenta Clave y Administrador solamente reutilizamos
         * ese mismo cálculo en modo consulta; no les transferimos la autoría de
         * ninguna acción.
         */
        $analistaId = (int)($seguimiento['analista_id'] ?? 0);

        if ($analistaId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'El seguimiento no tiene un Analista responsable válido.'
            ], 422);
        }

        $postEnvio = $this->postEnvioService->obtenerFlujoSiAplica(
            $seguimientoId,
            $analistaId
        );

        if (($postEnvio['ok'] ?? false) && ($postEnvio['aplica'] ?? false)) {
            $flujo = $this->agendaReunionService->ajustarFlujoAnalista(
                $seguimientoId,
                $analistaId,
                $postEnvio['flujo']
            );

            $flujo = $this->reunionFechaGuardService->ajustarFlujo(
                $seguimientoId,
                $analistaId,
                $flujo
            );

            $flujo = $this->reunionResultadoService->ajustarFlujo(
                $seguimientoId,
                $analistaId,
                $flujo
            );

            $this->responder([
                'ok' => true,
                'flujo' => $flujo,
                'modo_acceso' => $modoAcceso,
                'solo_lectura' => $modoAcceso === 'administrador'
            ]);
        }

        $resultado = $this->service->obtenerEstado($seguimientoId, $analistaId);
        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        if (($resultado['ok'] ?? false) && is_array($resultado['flujo'] ?? null)) {
            $resultado['flujo'] = $this->ajustarPasoInicial(
                $resultado['flujo'],
                $seguimiento
            );
        }

        $resultado['modo_acceso'] = $modoAcceso;
        $resultado['solo_lectura'] = $modoAcceso === 'administrador';

        $this->responder($resultado, $codigoHttp);
    }

    public function registrarPostEnvio()
    {
        header('Content-Type: application/json; charset=utf-8');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if ($usuarioId <= 0 || $rolId !== 4) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Solo el Analista responsable puede registrar este avance.'
            ], 403);
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $accion = strtoupper(trim((string)($_POST['accion'] ?? '')));

        if ($seguimientoId <= 0 || $accion === '') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Faltan datos para guardar el avance.'
            ], 422);
        }

        if ($accion === 'AGENDAR_REUNION') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Las reuniones ahora se coordinan desde la agenda compartida con Cuenta Clave.'
            ], 409);
        }

        if ($accion === 'REGISTRAR_SEGUIMIENTO_REUNION') {
            $resultado = $this->reunionResultadoService->registrarSeguimiento(
                $seguimientoId,
                $usuarioId,
                $_POST
            );
            $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
            unset($resultado['codigo_http']);
            $this->responder($resultado, $codigoHttp);
        }

        if ($accion === 'REGISTRAR_REUNION_REALIZADA') {
            $validacionResultado = $this->reunionResultadoService->validarResultadoReunion(
                $_POST
            );

            if (!($validacionResultado['ok'] ?? false)) {
                $this->responder([
                    'ok' => false,
                    'mensaje' => (string)($validacionResultado['mensaje'] ?? 'Revisa los datos del seguimiento posterior a la reunión.')
                ], (int)($validacionResultado['codigo_http'] ?? 422));
            }

            $validacionFecha = $this->reunionFechaGuardService->validarRegistro(
                $seguimientoId,
                $usuarioId
            );

            if (!($validacionFecha['ok'] ?? false)) {
                $this->responder([
                    'ok' => false,
                    'mensaje' => (string)($validacionFecha['mensaje'] ?? 'La reunión todavía no puede registrarse como realizada.')
                ], (int)($validacionFecha['codigo_http'] ?? 409));
            }
        }

        if ($accion === 'FORMALIZAR_CONVENIO') {
            $validacionConvenio = $this->reunionResultadoService->validarFormalizacion(
                $seguimientoId,
                $usuarioId
            );

            if (!($validacionConvenio['ok'] ?? false)) {
                $this->responder([
                    'ok' => false,
                    'mensaje' => (string)($validacionConvenio['mensaje'] ?? 'El seguimiento todavía no puede avanzar a convenio.')
                ], (int)($validacionConvenio['codigo_http'] ?? 409));
            }
        }

        $resultado = $this->postEnvioService->registrarAccion(
            $seguimientoId,
            $usuarioId,
            $accion,
            $_POST
        );
        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        if (
            $accion === 'REGISTRAR_REUNION_REALIZADA' &&
            ($resultado['ok'] ?? false)
        ) {
            $this->reunionFechaGuardService->marcarRealizada(
                $seguimientoId,
                $usuarioId
            );

            $this->reunionResultadoService->programarSeguimientoTrasReunion(
                $seguimientoId,
                $usuarioId,
                $_POST
            );
        }

        $this->responder($resultado, $codigoHttp);
    }

    private function resolverModoAcceso()
    {
        if ((int)($_SESSION['rol_id'] ?? 0) === 1) {
            return 'administrador';
        }

        if (tienePermiso('seguimientos_vinculacion.supervisar')) {
            return 'supervisor';
        }

        return 'analista';
    }

    private function obtenerSeguimientoLectura($seguimientoId, $usuarioId, $modoAcceso)
    {
        $modelo = new SeguimientoVinculacionModel();

        if ($modoAcceso === 'administrador') {
            return $modelo->obtenerSeguimientoAdministrador($seguimientoId);
        }

        if ($modoAcceso === 'supervisor') {
            return $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);
        }

        return $modelo->obtenerSeguimientoAnalista($usuarioId, $seguimientoId);
    }

    private function ajustarPasoInicial($flujo, $seguimiento)
    {
        if (!is_array($flujo) || !is_array($seguimiento)) {
            return $flujo;
        }

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
                $fechaCreado = new DateTime($creado);
                $fechaActualizado = new DateTime($actualizado);

                if ($fechaActualizado > $fechaCreado) {
                    return $flujo;
                }
            } catch (Throwable $error) {
                // Si no puede comparar las marcas, conserva el criterio de NUEVO sin actividad.
            }
        }

        $totalPasos = max(13, (int)($flujo['total_pasos'] ?? 13));
        $flujo['paso_actual'] = 1;
        $flujo['total_pasos'] = $totalPasos;
        $flujo['porcentaje'] = (int)round((1 / $totalPasos) * 100);
        $flujo['titulo'] = 'Iniciar investigación';
        $flujo['descripcion'] =
            'El seguimiento acaba de registrarse. Revisa la información disponible y comienza la investigación de datos para avanzar en la ruta.';
        $flujo['faltantes'] = is_array($flujo['faltantes'] ?? null)
            ? $flujo['faltantes']
            : [];
        $flujo['accion_principal'] = [
            'codigo' => 'COMPLETAR_DATOS',
            'etiqueta' => 'Comenzar investigación',
            'icono' => 'bi-search'
        ];
        $flujo['accion_secundaria'] = null;
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

    private function responder($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
