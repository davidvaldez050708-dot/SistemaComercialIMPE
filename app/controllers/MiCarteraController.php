<?php
require_once __DIR__.'/../helpers/PermissionHelper.php';
require_once __DIR__.'/../services/MiCarteraRepository.php';
require_once __DIR__.'/../../config/db_connection.php';

/**
 * Mi cartera es una ruta distinta de Prospectos (almacén administrativo).
 * La autorización se aplica en todas las acciones, nunca solo en el menú.
 * No incluye acciones POST ni manipulación de prospectos o asignaciones.
 */
final class MiCarteraController
{
    private function autorizar(): int
    {
        $usuarioId=(int)($_SESSION['usuario_id']??0);
        if ($usuarioId<1) {
            http_response_code(401);
            exit('Sesión no disponible.');
        }
        if ((int)($_SESSION['rol_id']??0)!==3 || !tienePermiso('prospectos.mi_cartera')) {
            http_response_code(403);
            exit('No tienes permiso para consultar Mi cartera.');
        }
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
            http_response_code(405);
            exit('Método no permitido.');
        }
        header('Cache-Control: private, no-store, max-age=0');
        return $usuarioId;
    }

    private function presentar(string $vista,array $datos=[]): void
    {
        extract($datos,EXTR_SKIP);
        $tituloPagina=$vista==='detalle'?'Ficha de Mi cartera':'Mi cartera';
        $subtituloPagina='Tus prospectos asignados y sus próximas acciones.';
        $opcionActiva='mi_cartera';
        require __DIR__.'/../views/layout/dashboard_head.php';
        require __DIR__.'/../views/layout/sidebar.php';
        require __DIR__.'/../views/layout/topbar.php';
        require __DIR__.'/../views/mi_cartera/'.$vista.'.php';
        require __DIR__.'/../views/layout/dashboard_footer.php';
    }

    public function index(): void
    {
        $usuarioId=$this->autorizar();
        $error='';
        $disponible=false;
        $cartera=[
            'registros'=>[],'total'=>0,'pagina'=>1,'paginas'=>1,
            'filtros'=>['vista'=>'todos','buscar'=>'','pagina'=>1],
            'resumen'=>['total'=>0,'atencion'=>0,'sin_fecha'=>0]
        ];
        try {
            // Validar filtros antes de consultar, sin aceptar asesor_id desde URL.
            $filtros=MiCarteraRepository::filtros($_GET);
            $repo=new MiCarteraRepository((new Database())->connect(false));
            $disponible=$repo->disponible();
            $cartera['filtros']=$filtros;
            if ($disponible) $cartera=$repo->listar($usuarioId,$filtros);
        } catch (DomainException $e) {
            http_response_code(400);
            $error=$e->getMessage();
        } catch (Throwable $e) {
            http_response_code(503);
            error_log('[mi_cartera_index] '.get_class($e).' codigo='.(int)$e->getCode());
            $error='No fue posible consultar tu cartera en este momento.';
        }
        $this->presentar('index',compact('cartera','error','disponible'));
    }

    public function detalle(): void
    {
        $usuarioId=$this->autorizar();
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $prospecto=null;
        $error='';
        if ($id===false||$id===null) {
            http_response_code(404);
            $error='Este prospecto no está disponible en tu cartera.';
        } else {
            try {
                $repo=new MiCarteraRepository((new Database())->connect(false));
                if (!$repo->disponible()) throw new RuntimeException('Estructura comercial incompleta.');
                $prospecto=$repo->detalle($usuarioId,(int)$id);
                if (!$prospecto) {
                    // No revelar si el ID existe bajo otro asesor.
                    http_response_code(404);
                    $error='Este prospecto no está disponible en tu cartera.';
                }
            } catch (Throwable $e) {
                http_response_code(503);
                error_log('[mi_cartera_detalle] '.get_class($e).' codigo='.(int)$e->getCode());
                $error='No fue posible consultar la ficha.';
            }
        }
        $this->presentar('detalle',compact('prospecto','error'));
    }
}
