<?php
require_once __DIR__.'/../helpers/EmbudoComercialAcceso.php';
require_once __DIR__.'/../services/EmbudoComercialRepository.php';
require_once __DIR__.'/../../config/db_connection.php';

/**
 * Vista Kanban de consulta: funciones de movimiento, pago y contacto
 * se incorporarán con permisos operativos propios y evidencia verificable.
 */
final class EmbudoComercialController
{
    private function autorizar(string $vista): array
    {
        $id=(int)($_SESSION['usuario_id']??0);
        if ($id<=0) {
            http_response_code(401);
            exit('Sesión no disponible.');
        }
        if (!isset(EmbudoComercialAcceso::VISTAS[$vista])) {
            http_response_code(404);
            exit('Vista comercial no disponible.');
        }
        if (!EmbudoComercialAcceso::puede($vista)) {
            http_response_code(403);
            exit('No tienes permiso para consultar esta vista comercial.');
        }
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') {
            http_response_code(405);
            exit('Método no permitido.');
        }
        header('Cache-Control: private, no-store, max-age=0');
        return [$id,EmbudoComercialAcceso::esSupervisor()];
    }

    private function presentar(string $archivo,string $vista,array $datos): void
    {
        extract($datos,EXTR_SKIP);
        $titulos=[
            'embudo'=>'Gestión comercial',
            'recuperacion'=>'Recuperación comercial',
            'descartados'=>'Prospectos descartados'
        ];
        $tituloPagina=$titulos[$vista];
        $subtituloPagina='Control de etapas, atención y seguimiento de prospectos.';
        $opcionActiva='embudo_comercial';
        require __DIR__.'/../views/layout/dashboard_head.php';
        require __DIR__.'/../views/layout/sidebar.php';
        require __DIR__.'/../views/layout/topbar.php';
        require __DIR__.'/../views/embudo_comercial/'.$archivo.'.php';
        require __DIR__.'/../views/layout/dashboard_footer.php';
    }

    public function index(): void
    {
        $vista=$_GET['vista']??'embudo';
        if (!is_string($vista)) {
            http_response_code(400);
            exit('Vista no válida.');
        }
        [$usuarioId,$supervisor]=$this->autorizar($vista);
        $error='';
        $disponible=false;
        $opciones=['programas'=>[],'asesores'=>[]];
        $tablero=['columnas'=>[],'conteos'=>[],'total'=>0];
        $filtros=[
            'buscar'=>'','origen'=>'','programa'=>'','vencidos'=>false,
            'asesor'=>0,'paginas'=>[]
        ];
        try {
            $filtros=EmbudoComercialRepository::filtros($_GET,$supervisor);
            $repo=new EmbudoComercialRepository((new Database())->connect(false));
            $disponible=$repo->disponible();
            if ($disponible) {
                $opciones=$repo->opciones($usuarioId,$supervisor);
                $tablero=$repo->listado($usuarioId,$supervisor,$filtros,$vista);
            }
        } catch (DomainException $e) {
            http_response_code(400);
            $error=$e->getMessage();
        } catch (Throwable $e) {
            http_response_code(503);
            error_log('[embudo_comercial_consulta] '.get_class($e).' codigo='.(int)$e->getCode());
            $error='No fue posible consultar el embudo comercial en este momento.';
        }
        $this->presentar('index',$vista,compact(
            'vista','usuarioId','supervisor','error','disponible','opciones','tablero','filtros'
        ));
    }

    public function detalle(): void
    {
        $vista=$_GET['vista']??'embudo';
        if (!is_string($vista)) {
            http_response_code(400);
            exit('Vista no válida.');
        }
        [$usuarioId,$supervisor]=$this->autorizar($vista);
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $prospecto=null;
        $error='';
        if (!$id) {
            http_response_code(404);
            $error='El prospecto no está disponible en esta vista.';
        } else {
            try {
                $repo=new EmbudoComercialRepository((new Database())->connect(false));
                if (!$repo->disponible()) throw new RuntimeException('Estructura no instalada.');
                $prospecto=$repo->detalle($usuarioId,$supervisor,(int)$id,$vista);
                if (!$prospecto) {
                    http_response_code(404);
                    $error='El prospecto no está disponible en esta vista.';
                }
            } catch (Throwable $e) {
                http_response_code(503);
                error_log('[embudo_comercial_detalle] '.get_class($e).' codigo='.(int)$e->getCode());
                $error='No fue posible consultar la ficha comercial.';
            }
        }
        $this->presentar('detalle',$vista,compact(
            'vista','usuarioId','supervisor','prospecto','error'
        ));
    }
}
