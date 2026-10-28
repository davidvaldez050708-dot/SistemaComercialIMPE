<?php

require_once __DIR__ . '/../services/AgendaComentarioService.php';

class AgendaComentarioController
{
    public function listar() { $this->ejecutar('GET','listar'); }
    public function crear() { $this->ejecutar('POST','crear'); }
    public function eliminar() { $this->ejecutar('POST','eliminar'); }
    public function leer() { $this->ejecutar('POST','leer'); }

    private function responder(array $datos, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function ejecutar(string $metodo, string $accion): void
    {
        $usuario = (int)($_SESSION['usuario_id'] ?? 0);
        if (!AgendaComentarioService::puedeAcceder($usuario)) { $this->responder(['ok'=>false,'mensaje'=>'No tienes acceso a los comentarios de la agenda.'],403); }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $metodo) { $this->responder(['ok'=>false,'mensaje'=>'Método no permitido.'],405); }
        if ($metodo === 'POST') {
            $sesion = $_SESSION['csrf_token'] ?? '';
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
            if (!is_string($sesion) || !is_string($token) || $sesion==='' || $token==='' || !hash_equals($sesion,$token)) {
                $this->responder(['ok'=>false,'mensaje'=>'La sesión de seguridad cambió. Recarga la página e intenta nuevamente.'],419);
            }
        }
        try {
            $servicio = new AgendaComentarioService();
            if ($accion === 'listar') { $r=$servicio->listar($usuario,$_GET['desde']??'',$_GET['hasta']??''); }
            elseif ($accion === 'crear') { $r=$servicio->crear($usuario,$_POST); }
            else {
                $id = filter_var($_POST['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
                $r=$servicio->$accion($usuario,(int)$id);
            }
            $codigo=(int)($r['codigo_http']??200);unset($r['codigo_http']);
            $this->responder($r,$codigo);
        } catch (Throwable $e) {
            error_log('[agenda_comentarios] '.get_class($e));
            $this->responder(['ok'=>false,'mensaje'=>'No fue posible procesar el comentario. Intenta nuevamente.'],500);
        }
    }
}
