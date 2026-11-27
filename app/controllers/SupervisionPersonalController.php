<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SupervisionPersonalRepository.php';
require_once __DIR__.'/../services/SupervisionPersonalAcceso.php';

class SupervisionPersonalController
{
    protected function conexion(): mysqli
    {
        require_once __DIR__.'/../../config/db_connection.php';
        return method_exists('Database','compartida')?Database::compartida():(new Database())->connect(false);
    }
    public function ejecutar(string $accion='index'): void
    {
        header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');
        $actor=(int)($_SESSION['usuario_id']??0);
        if($actor<=0){$this->json(['ok'=>false,'mensaje'=>'Inicia sesión nuevamente.'],401);return;}
        $metodos=['index'=>'GET','catalogo'=>'GET','detalle'=>'GET','bitacora'=>'GET','previsualizar'=>'POST','guardar'=>'POST'];
        if(!isset($metodos[$accion])){$this->json(['ok'=>false,'mensaje'=>'Acción no disponible.'],404);return;}
        if(($_SERVER['REQUEST_METHOD']??'GET')!==$metodos[$accion]){header('Allow: '.$metodos[$accion]);$this->json(['ok'=>false,'mensaje'=>'Método no permitido.'],405);return;}
        try {
            $repo=new SupervisionPersonalRepository($this->conexion());$repo->autorizar($actor,SupervisionPersonalRepository::VER);
            if($metodos[$accion]==='POST'){
                $token=$_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf_token']??'';
                if(!is_string($token)||empty($_SESSION['csrf_token'])||!hash_equals((string)$_SESSION['csrf_token'],$token)){
                    $this->json(['ok'=>false,'mensaje'=>'La sesión de seguridad cambió. Recarga la página.'],403);return;
                }
            }
            $input=$metodos[$accion]==='POST'?$_POST:$_GET;
            $entero=static function($v): int {
                if(!is_scalar($v)||!preg_match('/^[0-9]{1,10}$/D',(string)$v))throw new InvalidArgumentException('Identificador no válido.');return (int)$v;
            };
            if($accion==='index'){
                $datos=$repo->catalogo($actor);$tituloPagina='Supervisión de personal';
                $subtituloPagina='Administra los equipos de supervisión de Cuenta Clave.';$opcionActiva='supervision_personal';
                require __DIR__.'/../views/layout/dashboard_head.php';require __DIR__.'/../views/layout/sidebar.php';require __DIR__.'/../views/layout/topbar.php';
                require __DIR__.'/../views/supervision_personal/index.php';require __DIR__.'/../views/layout/dashboard_footer.php';return;
            }
            $kam=$entero($input['kam_id']??0);
            $data=match($accion){
                'catalogo'=>$repo->catalogo($actor),
                'detalle'=>$repo->detalle($actor,$kam),
                'bitacora'=>['movimientos'=>$repo->bitacora($actor,$kam,$entero($input['pagina']??1))],
                'previsualizar'=>$repo->previsualizar($actor,$kam,$input['colaboradores']??[],$entero($input['revision']??0),(string)$_SESSION['csrf_token']),
                'guardar'=>$repo->guardar($actor,$kam,$input['colaboradores']??[],$entero($input['revision']??0),is_string($input['confirmacion']??null)?$input['confirmacion']:'',(string)$_SESSION['csrf_token']),
            };
            if($accion==='detalle'){
                require_once __DIR__.'/../helpers/AvatarHelper.php';
                foreach($data['colaboradores'] as &$persona)$persona['foto_url']=obtenerUrlFotoPerfil($persona['foto_perfil']??'');
                unset($persona);
            }
            $this->json(['ok'=>true,'datos'=>$data]);
        }catch(InvalidArgumentException $e){$this->json(['ok'=>false,'mensaje'=>$e->getMessage()],422);}
        catch(DomainException $e){$this->json(['ok'=>false,'mensaje'=>$e->getMessage()],$e->getCode()===403?403:409);}
        catch(Throwable $e){error_log('[supervision_personal] '.get_class($e));$this->json(['ok'=>false,'mensaje'=>'No se pudo consultar la supervisión. Intenta nuevamente.'],503);}
    }
    protected function json(array $data,int $status=200): void
    {
        http_response_code($status);header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
