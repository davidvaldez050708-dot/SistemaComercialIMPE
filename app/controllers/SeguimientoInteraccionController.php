<?php

require_once __DIR__ . '/../services/InteraccionRutaService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class SeguimientoInteraccionController
{
    private $service;

    public function __construct()
    {
        $this->service = new InteraccionRutaService();
    }

    public function registrarInformativa()
    {
        header('Content-Type: application/json; charset=utf-8');

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if (
            $usuarioId <= 0 ||
            !tienePermiso('seguimientos_vinculacion.operar_propios')
        ) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'No tienes permiso para operar este seguimiento.'
            ], 403);
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $seguimientoId = (int)($_POST['seguimiento_id'] ?? 0);

        if ($seguimientoId <= 0) {
            $this->responder([
                'ok' => false,
                'mensaje' => 'Selecciona un seguimiento válido.'
            ], 422);
        }

        $datos=$_POST;
        if(isset($datos['captura_confirmacion'])){
            $csrf=(string)($_SESSION['csrf_token']??'');
            if($csrf===''||!is_string($datos['csrf_token']??null)||!hash_equals($csrf,$datos['csrf_token']))
                $this->responder(['ok'=>false,'mensaje'=>'La sesión expiró. Actualiza el CRM.'],403);
            require_once __DIR__.'/../helpers/TelefoniaPeticionContexto.php';
            TelefoniaPeticionContexto::liberarSesion();
        }
        try{
            require_once __DIR__.'/../services/TelefoniaResultadoConfirmacion.php';
            $resultado=(new TelefoniaResultadoConfirmacion(Database::compartida()))->ejecutar($usuarioId,'INFORMATIVA',$datos,
                function(array $valores,?callable $confirmar)use($seguimientoId,$usuarioId):array{
                    $r=$this->service->registrarInformativa($seguimientoId,$usuarioId,$valores,$confirmar);
                    if($confirmar&&empty($r['ok']))throw new DomainException($r['mensaje'],(int)($r['codigo_http']??409));
                    return $r;
                });
        }catch(DomainException|InvalidArgumentException $e){
            $this->responder(['ok'=>false,'mensaje'=>$e->getMessage()],in_array($e->getCode(),[403,409,422],true)?$e->getCode():409);
        }catch(Throwable $e){
            error_log('[informativa_resultado_confirmado] '.get_class($e));
            $this->responder(['ok'=>false,'mensaje'=>'No fue posible confirmar la interacción. Conserva la captura y vuelve a comprobar.'],500);
        }
        $codigoHttp = (int)($resultado['codigo_http'] ?? 200);
        unset($resultado['codigo_http']);

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
