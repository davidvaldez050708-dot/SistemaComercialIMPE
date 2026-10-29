<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SupervisionLlamadasRepository.php';
require_once __DIR__.'/../services/SupervisionLlamadasAudioService.php';

class SupervisionLlamadasController
{
    protected function db(): mysqli{return (new Database())->connect(false);}
    protected function proveedor(): object{require_once __DIR__.'/../services/ZadarmaRecordingService.php';return new ZadarmaRecordingService(false);}
    public static function filtros(array $input): array
    {
        $today=new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City'));
        $from=(string)($input['desde']??$today->modify('-6 days')->format('Y-m-d'));$until=(string)($input['hasta']??$today->format('Y-m-d'));
        foreach([$from,$until] as $date){$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Selecciona fechas válidas.');}
        if($until<$from||(new DateTimeImmutable($from))->diff(new DateTimeImmutable($until))->days>366)throw new InvalidArgumentException('Selecciona un periodo de hasta 366 días.');
        return ['area'=>($input['area']??'')==='analistas'?'analistas':'asesores','persona_id'=>max(0,(int)($input['persona_id']??0)),
            'desde'=>$from,'hasta'=>$until,'direccion'=>in_array($input['direccion']??'',['Entrante','Saliente'],true)?$input['direccion']:'',
            'resultado'=>mb_substr((string)($input['resultado']??''),0,80),'buscar'=>mb_substr(trim((string)($input['buscar']??'')),0,100),
            'pagina'=>max(1,(int)($input['pagina']??1))];
    }
    public function ejecutar(string $action='historial'): void
    {
        header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');return;}
        $uid=(int)($_SESSION['usuario_id']??0);
        if($uid<=0){http_response_code(401);echo 'Inicia sesión para consultar las llamadas.';return;}
        try{
            $db=$this->db();$repo=new SupervisionLlamadasRepository($db);$scope=$repo->acceso($uid,$action==='audio');
            if($action==='detalle'){
                $c=$repo->obtener($uid,(string)($_GET['llamada']??''));
                $detail=array_intersect_key($c,array_flip(['persona','numero','nombre','fecha','direccion','extension','resultado_texto','detalle_resultado','observacion','segundos','observada']));
                header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'llamada'=>$detail],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);return;
            }
            if($action==='audio'){
                $range=(string)($_SERVER['HTTP_RANGE']??'');
                if($range!==''&&!preg_match('/^bytes=\d*-\d*$/D',$range)){http_response_code(416);return;}
                // Revalidar antes de construir el cliente externo; el identificador
                // sólo permite cargar una llamada que pertenece al alcance vigente.
                $key=(string)($_GET['llamada']??'');$repo->obtener($uid,$key,true);
                if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
                $provider=$this->proveedor();
                $out=(new SupervisionLlamadasAudioService($db))->resolver($uid,$key,$provider,isset($_GET['verificar']));
                if(isset($_GET['verificar'])){unset($out['records']);header('Content-Type: application/json; charset=utf-8');echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);return;}
                $index=filter_var($_GET['segmento']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>7]]);
                if($index===false||!isset($out['records'][$index]['link'])){http_response_code(404);echo 'Esta parte de la grabación aún no está disponible.';return;}
                // Liberar la sesión evita bloquear la navegación o el teléfono.
                if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
                $response=SupervisionLlamadasAudioService::respuesta($provider->descargarGrabacion($out['records'][$index]['link'],$range),$range);
                http_response_code($response['status']);foreach($response['headers'] as $k=>$v)header($k.': '.$v);
                header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="grabacion-supervision-'.$index.'.mp3"');
                echo $response['body'];return;
            }
            $f=self::filtros($_GET);$data=$repo->listado($uid,$f);$puedeAudio=true;
            try{$repo->acceso($uid,true);}catch(DomainException $e){$puedeAudio=false;}
            $tituloPagina='Supervisión de llamadas';$subtituloPagina='Historial y grabaciones de analistas y asesores';$opcionActiva='supervision_llamadas';
            require __DIR__.'/../views/layout/dashboard_head.php';require __DIR__.'/../views/layout/sidebar.php';require __DIR__.'/../views/layout/topbar.php';
            require __DIR__.'/../views/telefonia/supervision_llamadas.php';require __DIR__.'/../views/layout/dashboard_footer.php';
        }catch(DomainException $e){http_response_code(403);header('Content-Type: text/plain; charset=utf-8');echo preg_match('/^[A-Z_]+$/D',$e->getMessage())?'No hay una grabación verificada disponible para esta llamada.':$e->getMessage();}
        catch(InvalidArgumentException $e){http_response_code(422);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
        catch(Throwable $e){error_log('[supervision_llamadas] '.get_class($e));http_response_code(503);header('Content-Type: text/plain; charset=utf-8');echo 'No se pudo consultar la información. Vuelve a intentarlo.';}
    }
}
