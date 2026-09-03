<?php

require_once __DIR__ . '/../models/OficioVinculacionModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class OficioVinculacionController
{
    public function estado()
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

        $modelo = new OficioVinculacionModel();
        $estado = $modelo->obtenerEstadoSeguimiento($seguimientoId, $usuarioId);

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este seguimiento.'
            ], 403);
        }

        $this->responderJson([
            'ok' => true,
            'estado' => $estado
        ]);
    }

    public function generarBorrador()
    {
        $this->validarMetodoPostJson();
        $this->validarPermisoJson('oficios.generar');

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El seguimiento solicitado no es válido.'
            ], 422);
        }

        $modelo = new OficioVinculacionModel();
        $resultado = $modelo->generarBorrador($seguimientoId, $usuarioId);

        if (!($resultado['ok'] ?? false)) {
            $codigoHttp = (int)($resultado['codigo_http'] ?? 500);
            unset($resultado['codigo_http']);
            $this->responderJson($resultado, $codigoHttp);
        }

        $this->responderJson($resultado);
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
