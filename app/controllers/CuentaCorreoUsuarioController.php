<?php

require_once __DIR__ . '/../services/CuentaCorreoUsuarioService.php';

class CuentaCorreoUsuarioController
{
    public function estado()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            $this->responder(['ok' => false, 'mensaje' => 'La sesión no está activa.'], 401);
        }

        $service = new CuentaCorreoUsuarioService();
        $cuenta = $service->obtener($usuarioId, false);

        $this->responder([
            'ok' => true,
            'configurada' => is_array($cuenta),
            'cuenta' => $cuenta
        ]);
    }

    public function diagnostico()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            $this->responder(
                ['ok' => false, 'mensaje' => 'La sesión no está activa.'],
                401
            );
        }

        $service = new CuentaCorreoUsuarioService();
        $this->responder($service->diagnostico($usuarioId));
    }

    public function guardar()
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $service = new CuentaCorreoUsuarioService();
        $resultado = $service->guardar($usuarioId, $_POST);

        $codigo = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);
        $this->responder($resultado, $codigo);
    }

    public function probar()
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $service = new CuentaCorreoUsuarioService();
        $resultado = $service->probar($usuarioId, $_POST);

        $codigo = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);
        $this->responder($resultado, $codigo);
    }

    public function eliminar()
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder(['ok' => false, 'mensaje' => 'Método no permitido.'], 405);
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $service = new CuentaCorreoUsuarioService();
        $resultado = $service->eliminar($usuarioId);

        $codigo = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);
        $this->responder($resultado, $codigo);
    }

    private function responder(array $datos, int $codigo = 200)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
