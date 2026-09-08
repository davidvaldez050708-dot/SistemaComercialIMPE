<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class SeguimientoObservacionController
{
    public function registrarTrabajo()
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if ($usuarioId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if ($rolId !== 6 || !tienePermiso('seguimientos_vinculacion.comentar')) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Solo Cuenta Clave puede registrar observaciones para el Analista.'
            ], 403);
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);
        $observacion = trim((string)($_POST['observacion'] ?? ''));

        if ($seguimientoId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'El seguimiento no es válido.'
            ], 422);
        }

        if ($observacion === '') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Escribe una observación antes de guardar.'
            ], 422);
        }

        $longitud = function_exists('mb_strlen')
            ? mb_strlen($observacion, 'UTF-8')
            : strlen($observacion);

        if ($longitud > 2000) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La observación no debe superar 2000 caracteres.'
            ], 422);
        }

        $modelo = new SeguimientoVinculacionModel();
        $seguimiento = $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);

        if (!$seguimiento) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes acceso a este seguimiento.'
            ], 403);
        }

        $analistaId = (int)($seguimiento['analista_id'] ?? 0);

        if ($analistaId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'El seguimiento no tiene un Analista asignado.'
            ], 422);
        }

        if (!$modelo->crearObservacion($seguimientoId, $usuarioId, $analistaId, $observacion)) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible guardar la observación.'
            ], 500);
        }

        $ultimas = $modelo->obtenerUltimasObservacionesSeguimiento($seguimientoId, 1);
        $ultima = $ultimas[0] ?? [];
        $fechaLabel = 'Ahora';
        $fecha = trim((string)($ultima['created_at'] ?? ''));

        if ($fecha !== '') {
            try {
                $fechaObjeto = new DateTime($fecha);
                $fechaLabel = $fechaObjeto->format('d/m/Y H:i');
            } catch (Exception $error) {
                $fechaLabel = 'Ahora';
            }
        }

        $autor = trim(
            (string)($ultima['nombre'] ?? '') . ' ' .
            (string)($ultima['apellidos'] ?? '')
        );

        $this->responder([
            'ok' => true,
            'mensaje' => 'Observación guardada en el expediente del Analista.',
            'observacion' => [
                'id' => (int)($ultima['id'] ?? 0),
                'autor' => $autor !== '' ? $autor : 'Cuenta Clave',
                'observacion' => (string)($ultima['observacion'] ?? $observacion),
                'fecha_label' => $fechaLabel
            ]
        ]);
    }

    private function responder($datos, $codigo = 200)
    {
        http_response_code((int)$codigo);
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
