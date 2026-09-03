<?php

require_once __DIR__ . '/../services/OficioCorreoService.php';
require_once __DIR__ . '/../services/OficioProgramacionService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class OficioCorreoController
{
    public function borrador()
    {
        $this->validarPermisoJson('oficios.ver');

        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $servicio = new OficioCorreoService();
        $resultado = $servicio->obtenerBorrador(
            $seguimientoId,
            $usuarioId,
            $this->resolverModoAcceso()
        );

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $resultado['correo'] = $this->completarPermisosCorreo(
            $resultado['correo'] ?? [],
            $usuarioId
        );

        $this->responderJson($resultado);
    }

    public function guardarBorrador()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('oficios.generar');

        if ((int)($_SESSION['rol_id'] ?? 0) !== 4) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El borrador solo puede ser preparado por el Analista responsable.'
            ], 403);
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $asunto = trim((string)($_POST['asunto'] ?? ''));
        $cuerpo = trim((string)($_POST['cuerpo'] ?? ''));

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $servicio = new OficioCorreoService();
        $resultado = $servicio->guardarBorrador(
            $seguimientoId,
            $usuarioId,
            $asunto,
            $cuerpo
        );

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $resultado['correo'] = $this->completarPermisosCorreo(
            $resultado['correo'] ?? [],
            $usuarioId
        );

        $this->responderJson($resultado);
    }

    public function programacion()
    {
        $this->validarPermisoJson('oficios.ver');

        $seguimientoId = (int)($_GET['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $servicio = new OficioProgramacionService();
        $resultado = $servicio->obtenerEstado(
            $seguimientoId,
            $usuarioId,
            $this->resolverModoAcceso()
        );

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $resultado['programacion'] = $this->completarPermisosProgramacion(
            $resultado['programacion'] ?? [],
            $usuarioId
        );

        $this->responderJson($resultado);
    }

    public function programarEnvio()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('oficios.enviar');

        if ((int)($_SESSION['rol_id'] ?? 0) !== 4) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La programación solo puede realizarla el Analista responsable.'
            ], 403);
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $fecha = trim((string)($_POST['proxima_accion_at'] ?? ''));

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $servicio = new OficioProgramacionService();
        $resultado = $servicio->programar(
            $seguimientoId,
            $usuarioId,
            $fecha
        );

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $resultado['programacion'] = $this->completarPermisosProgramacion(
            $resultado['programacion'] ?? [],
            $usuarioId
        );

        $this->responderJson($resultado);
    }

    private function completarPermisosCorreo($correo, $usuarioId)
    {
        $esAnalistaResponsable =
            (int)($_SESSION['rol_id'] ?? 0) === 4 &&
            (int)($correo['analista_id'] ?? 0) === (int)$usuarioId;

        $correo['es_analista_responsable'] = $esAnalistaResponsable;
        $correo['solo_consulta'] = !$esAnalistaResponsable;
        $correo['puede_editar'] =
            $esAnalistaResponsable &&
            tienePermiso('oficios.generar');

        unset($correo['analista_id']);

        return $correo;
    }

    private function completarPermisosProgramacion($programacion, $usuarioId)
    {
        $esAnalistaResponsable =
            (int)($_SESSION['rol_id'] ?? 0) === 4 &&
            (int)($programacion['analista_id'] ?? 0) === (int)$usuarioId;

        $programacion['es_analista_responsable'] = $esAnalistaResponsable;
        $programacion['solo_consulta'] = !$esAnalistaResponsable;
        $programacion['puede_programar'] =
            $esAnalistaResponsable &&
            tienePermiso('oficios.enviar') &&
            !empty($programacion['cumple_requisitos']);

        unset($programacion['analista_id']);

        return $programacion;
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

    private function validarMetodoPostJson()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }
    }

    private function validarPermisoJson($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if (!tienePermiso($codigo)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para realizar esta acción.'
            ], 403);
        }
    }

    private function responderJson($datos, $codigoHttp = 200)
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
