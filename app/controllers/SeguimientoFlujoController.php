<?php

require_once __DIR__ . '/../services/SeguimientoFlujoService.php';
require_once __DIR__ . '/../services/SeguimientoPostEnvioService.php';
require_once __DIR__ . '/../services/AgendaReunionService.php';
require_once __DIR__ . '/../services/ReunionFechaGuardService.php';
require_once __DIR__ . '/../services/ReunionResultadoService.php';

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
        $rolId = (int)($_SESSION['rol_id'] ?? 0);
        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);

        if ($usuarioId <= 0 || $rolId !== 4) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La ruta de trabajo está disponible para el Analista responsable.'
            ], 403);
        }

        if ($seguimientoId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Selecciona un seguimiento válido.'
            ], 422);
        }

        $postEnvio = $this->postEnvioService->obtenerFlujoSiAplica(
            $seguimientoId,
            $usuarioId
        );

        if (($postEnvio['ok'] ?? false) && ($postEnvio['aplica'] ?? false)) {
            $flujo = $this->agendaReunionService->ajustarFlujoAnalista(
                $seguimientoId,
                $usuarioId,
                $postEnvio['flujo']
            );

            $flujo = $this->reunionFechaGuardService->ajustarFlujo(
                $seguimientoId,
                $usuarioId,
                $flujo
            );

            $flujo = $this->reunionResultadoService->ajustarFlujo(
                $seguimientoId,
                $usuarioId,
                $flujo
            );

            $this->responder([
                'ok' => true,
                'flujo' => $flujo
            ]);
        }

        $resultado = $this->service->obtenerEstado($seguimientoId, $usuarioId);
        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

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
