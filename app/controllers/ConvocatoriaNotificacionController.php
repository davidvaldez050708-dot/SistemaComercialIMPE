<?php

require_once __DIR__ . '/../models/ConvocatoriaModel.php';
require_once __DIR__ . '/../models/ConvocatoriaNotificacionModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class ConvocatoriaNotificacionController
{
    public function pendientes()
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->esMarketingAutenticado()) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'mensaje' => 'No tienes acceso a estas notificaciones.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $convocatoriaModel = new ConvocatoriaModel();
            $convocatoriaModel->sincronizarConvocatoriasPorFecha();

            $usuarioId = (int)$_SESSION['usuario_id'];
            $modelo = new ConvocatoriaNotificacionModel();
            $phone=[];
            if(tienePermiso('telefonia.usar')&&tienePermiso('telefonia.recibir')){
                try{require_once __DIR__.'/../services/TelefoniaNotificacionService.php';$phone=(new TelefoniaNotificacionService())->pendientes($usuarioId,true);}
                catch(Throwable $e){error_log('[campana_marketing_telefonia] '.get_class($e));}
            }

            echo json_encode([
                'ok' => true,
                // La insignia conserva obligaciones telefónicas aun después de leerlas.
                // Convocatorias siguen contabilizando únicamente las no leídas.
                'no_leidas' => $modelo->contarNoLeidas($usuarioId)+count(array_filter($phone,
                    static fn($a)=>(int)($a['leida']??0)===0||!empty($a['pendiente_operativo']))),
                'notificaciones' => array_merge($phone,$modelo->obtenerPorUsuario($usuarioId, 20))
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $error) {
            error_log($error->getMessage());
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'mensaje' => 'No fue posible consultar las notificaciones.'
            ], JSON_UNESCAPED_UNICODE);
        }

        exit;
    }

    public function marcarLeida()
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->esMarketingAutenticado()) {
            http_response_code(403);
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $modelo = new ConvocatoriaNotificacionModel();
        $ok = $modelo->marcarLeida($id, (int)$_SESSION['usuario_id']);

        echo json_encode(['ok' => (bool)$ok], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function marcarTodasLeidas()
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->esMarketingAutenticado()) {
            http_response_code(403);
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $modelo = new ConvocatoriaNotificacionModel();
        $ok = $modelo->marcarTodasLeidas((int)$_SESSION['usuario_id']);
        if(tienePermiso('telefonia.usar')&&tienePermiso('telefonia.recibir')){
            try{require_once __DIR__.'/../services/TelefoniaNotificacionService.php';(new TelefoniaNotificacionService())->leerTodas((int)$_SESSION['usuario_id']);}
            catch(Throwable $e){error_log('[telefono_notificaciones_leer] '.get_class($e));}
        }

        echo json_encode(['ok' => (bool)$ok], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function esMarketingAutenticado()
    {
        return (int)($_SESSION['usuario_id'] ?? 0) > 0
            && tienePermiso('convocatorias.gestionar');
    }
}
