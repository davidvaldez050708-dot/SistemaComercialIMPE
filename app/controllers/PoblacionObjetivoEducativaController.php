<?php

require_once __DIR__ . '/../models/PoblacionObjetivoEducativaModel.php';
require_once __DIR__ . '/../models/DataTerritorialModel.php';
require_once __DIR__ . '/../services/InegiEducacionService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class PoblacionObjetivoEducativaController
{
    public function obtener()
    {
        $this->validarSesion();

        if (!tienePermiso('data_territorial.ver')) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para consultar información territorial.'
            ], 403);
        }

        $estadoId = (int)($_GET['estado_id'] ?? 0);
        $modeloTerritorial = new DataTerritorialModel();
        $this->validarAccesoEstado($modeloTerritorial, $estadoId);
        $modelo = new PoblacionObjetivoEducativaModel();

        if (!$modelo->tablaDisponible()) {
            $this->responderJson([
                'ok' => true,
                'datos' => [
                    'disponible' => false,
                    'migracion_pendiente' => true,
                    'codigo_indicador' => InegiEducacionService::CODIGO_INDICADOR,
                    'historico' => []
                ]
            ]);
        }

        $this->responderJson([
            'ok' => true,
            'datos' => $modelo->obtenerIndicadorEstado($estadoId)
        ]);
    }

    public function actualizar()
    {
        $this->validarSesion();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        if (!tienePermiso('data_territorial.actualizar_oficial')) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para actualizar información oficial.'
            ], 403);
        }

        $estadoIdPost = trim((string)($_POST['estado_id'] ?? ''));

        if ($estadoIdPost === '' || !ctype_digit($estadoIdPost) || (int)$estadoIdPost <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no es válido.'
            ], 422);
        }

        $estadoId = (int)$estadoIdPost;
        $modeloTerritorial = new DataTerritorialModel();
        $this->validarAccesoEstado($modeloTerritorial, $estadoId);
        $estado = $modeloTerritorial->obtenerEstado($estadoId);

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no existe o no está activo.'
            ], 404);
        }

        $claveInegi = str_pad(
            trim((string)($estado['clave_inegi'] ?? '')),
            2,
            '0',
            STR_PAD_LEFT
        );

        if (!preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $claveInegi)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio no tiene una clave INEGI válida.'
            ], 422);
        }

        $modelo = new PoblacionObjetivoEducativaModel();

        if (!$modelo->tablaDisponible()) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Falta aplicar la migración de población objetivo educativa en la base de datos.'
            ], 503);
        }

        $servicio = new InegiEducacionService();
        $resultado = $servicio->obtenerPoblacionObjetivoEstado($claveInegi);

        if (($resultado['ok'] ?? false) !== true) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $resultado['mensaje'] ??
                    'No fue posible obtener el indicador educativo oficial de INEGI.'
            ], 502);
        }

        $guardado = $modelo->guardarIndicadorEstado(
            $estadoId,
            $resultado,
            (int)$_SESSION['usuario_id']
        );

        if (!$guardado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar la población objetivo educativa.'
            ], 500);
        }

        $datosActualizados = $modelo->obtenerIndicadorEstado($estadoId);

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'La población objetivo educativa se actualizó correctamente desde INEGI.',
            'datos' => $datosActualizados
        ]);
    }

    private function validarSesion(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }
    }

    private function validarAccesoEstado(
        DataTerritorialModel $modelo,
        int $estadoId
    ): void {
        if ($estadoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no es válido.'
            ], 422);
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if (!$modelo->puedeAccederEstado($usuarioId, $rolId, $estadoId)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes acceso a la información de ese territorio.'
            ], 403);
        }
    }

    private function responderJson(array $datos, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
