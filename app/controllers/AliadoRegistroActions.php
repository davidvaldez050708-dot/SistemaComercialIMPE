<?php
require_once __DIR__ . '/../services/AliadoRegistroService.php';
trait AliadoRegistroActions
{
    private function responderRegistro(callable $operacion): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        try {
            if (empty($_SESSION['usuario_id'])) { throw new DomainException('Inicia sesión.',401); }
            $servicio = new AliadoRegistroService((new Database())->connect());
            if (!$servicio->disponible()) { throw new DomainException('Falta instalar la migración de Aliados y Registro.',503); }
            echo json_encode(['ok'=>true,'datos'=>$operacion($servicio)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (DomainException $e) {
            $code = in_array($e->getCode(),[401,403,404,405,419,503],true)?$e->getCode():422;
            http_response_code($code); echo json_encode(['ok'=>false,'mensaje'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[aliado_registro] '.$e->getMessage()); http_response_code(500);
            echo json_encode(['ok'=>false,'mensaje'=>'No fue posible completar la operación.']);
        }
    }
    public function registroDetalle(): void
    {
        $this->responderRegistro(function ($s) {
            $marketing = strcasecmp($_SESSION['rol']??'','Marketing')===0;
            $s->autorizar($marketing?'formularios_aliados.gestionar_enlaces':'aliados.gestionar_programas');
            return $s->detalle((string)($_GET['origen']??''),(int)($_GET['id']??0),$marketing);
        });
    }
    public function registroManualDatos(): void
    {
        $this->responderRegistro(function ($servicio) {
            return $servicio->consultarGestionManual((int)($_GET['id'] ?? 0));
        });
    }
    public function registroGestion(): void
    {
        $this->responderRegistro(function ($s) {
            if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { throw new DomainException('Método no permitido.',405); }
            $csrf=(string)($_SESSION['csrf_token']??'');
            if (!$csrf || !hash_equals($csrf,(string)($_POST['csrf_token']??''))) { throw new DomainException('Recarga la página e intenta nuevamente.',419); }
            $accion=(string)($_POST['operacion']??''); $tipo=(string)($_POST['origen']??''); $id=(int)($_POST['id']??0);
            if ($accion==='guardar_manual') { return ['id'=>$s->guardarManual($_POST)]; }
            if ($accion==='programas') { $s->configurar($tipo,$id,(array)($_POST['programas']??[])); return []; }
            if ($accion==='desactivar_manual') { $s->desactivar($id); return []; }
            if ($accion==='contacto_guardar') { $s->guardarContactoManual($_POST); return []; }
            if ($accion==='contacto_desactivar') { $s->desactivarContactoManual($id,(int)($_POST['contacto_id']??0)); return []; }
            if ($accion==='difusion_manual') { $s->registrarDifusionManual($_POST); return []; }
            return $s->enlace($tipo,$id,$accion);
        });
    }
}
