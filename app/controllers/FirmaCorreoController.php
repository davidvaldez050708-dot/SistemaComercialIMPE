<?php

require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/../services/FirmaCorreoService.php';

class FirmaCorreoController
{
    private $service;

    public function __construct()
    {
        $this->service = new FirmaCorreoService();
    }

    public function estado()
    {
        $usuario = $this->usuarioActual();
        $estado = $this->service->obtenerEstado((string)($usuario['correo'] ?? ''));

        $this->responder([
            'ok' => true,
            'firma' => [
                'disponible' => (bool)($estado['disponible'] ?? false),
                'nombre_archivo' => (string)($estado['nombre_archivo'] ?? ''),
                'imagen_url' => !empty($estado['disponible'])
                    ? BASE_URL . 'index.php?controller=firmaCorreo&action=imagen&v=' . (int)($estado['actualizado_at'] ?? time())
                    : ''
            ]
        ]);
    }

    public function guardar()
    {
        $this->validarPost();
        $usuario = $this->usuarioActual();

        $resultado = $this->service->guardar(
            (string)($usuario['correo'] ?? ''),
            $_FILES['firma_correo'] ?? null
        );

        $codigo = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http'], $resultado['ruta']);

        if ($resultado['ok'] ?? false) {
            $estado = $this->service->obtenerEstado((string)($usuario['correo'] ?? ''));
            $resultado['firma'] = [
                'disponible' => true,
                'nombre_archivo' => (string)($estado['nombre_archivo'] ?? ''),
                'imagen_url' => BASE_URL . 'index.php?controller=firmaCorreo&action=imagen&v=' .
                    (int)($estado['actualizado_at'] ?? time())
            ];
        }

        $this->responder($resultado, $codigo);
    }

    public function eliminar()
    {
        $this->validarPost();
        $usuario = $this->usuarioActual();

        $resultado = $this->service->eliminar((string)($usuario['correo'] ?? ''));
        $codigo = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

        $this->responder($resultado, $codigo);
    }

    public function imagen()
    {
        $usuario = $this->usuarioActual();
        $ruta = $this->service->obtenerRuta((string)($usuario['correo'] ?? ''));

        if ($ruta === '' || !is_file($ruta)) {
            http_response_code(404);
            exit;
        }

        $mime = $this->service->detectarMime($ruta);
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($ruta));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }

    private function usuarioActual()
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        if ($usuarioId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        $modelo = new UsuarioModel();
        $usuario = $modelo->buscarPorId($usuarioId);
        if (!$usuario) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No fue posible cargar tu cuenta.'
            ], 404);
        }

        return $usuario;
    }

    private function validarPost()
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }
    }

    private function responder($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
