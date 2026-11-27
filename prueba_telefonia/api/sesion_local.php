<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$root=dirname(__DIR__,2);
require_once $root.'/app/helpers/TelefoniaPeticionContexto.php';
TelefoniaPeticionContexto::actualizar(false);
require_once $root.'/app/services/TelefoniaSesionLocalService.php';
function responderSesionLocal(array $data,int $status=200):void
{
    http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
function presentarSesionLocal(array $row):array
{
    // Sólo datos de transporte propios, sin notas ni expedientes institucionales.
    $fields=['session_id','extension','direccion','estado','fecha_inicio','fecha_atendida','fecha_fin',
        'numero_origen','caller_name','duracion_observada_segundos','emitida','protocolo','ultima_secuencia',
        'pbx_call_id','pbx_inicio_evento'];
    $data=array_intersect_key($row,array_fill_keys($fields,true));
    foreach(['fecha_inicio','fecha_atendida','fecha_fin'] as $field){
        if(!empty($data[$field]))$data[$field]=str_replace(' ','T',$data[$field]).'Z';
    }
    $data['interna']=($row['clasificacion']??'')==='INTERNA'&&empty($row['pbx_call_id']);
    $data['resultado_registrado']=in_array($row['clasificacion']??'',['VINCULADA','SIN_IDENTIFICAR'],true);
    return $data+['fuente'=>'OBSERVACION_SDK','zona_horaria'=>'UTC'];
}
$user=(int)($_SESSION['usuario_id']??0);
if(!$user||!tienePermiso('telefonia.usar')||(!tienePermiso('telefonia.recibir')&&!tienePermiso('telefonia.salientes')))
    responderSesionLocal(['ok'=>false,'mensaje'=>'No tienes acceso al registro de sesiones telefónicas.'],403);
$method=$_SERVER['REQUEST_METHOD']??'';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');responderSesionLocal(['ok'=>false],405);}
try{
    $service=new TelefoniaSesionLocalService(Database::compartida());
    if($method==='GET'){
        if(array_key_exists('pbx_call_id',$_GET)){
            if(!tienePermiso('telefonia.recibir'))responderSesionLocal(['ok'=>false],403);
            if(!is_string($_GET['pbx_call_id']))throw new InvalidArgumentException('Referencia PBX inválida.');
            responderSesionLocal(['ok'=>true,'pbx'=>$service->obtenerPbxEntrantePropia($user,$_GET['pbx_call_id'])]);
        }
        $id=$_GET['session_id']??null;
        if(!is_string($id))throw new InvalidArgumentException('Sesión inválida.');
        $row=$service->obtener($user,$id);
        if(!$row)responderSesionLocal(['ok'=>false,'mensaje'=>'No existe esa sesión en tu historial.'],404);
        responderSesionLocal(['ok'=>true,'sesion'=>presentarSesionLocal($row)]);
    }
    $raw=file_get_contents('php://input',false,null,0,131073);
    if(!is_string($raw)||strlen($raw)>131072)responderSesionLocal(['ok'=>false],413);
    $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($data))throw new InvalidArgumentException('Evento inválido.');
    $token=$data['csrf_token']??null;$csrf=(string)($_SESSION['csrf_token']??'');
    if(!is_string($token)||$csrf===''||!hash_equals($csrf,$token))responderSesionLocal(['ok'=>false,'mensaje'=>'Actualiza el formulario antes de continuar.'],403);
    if(($data['accion']??'')==='preparar_saliente'){
        if(!tienePermiso('telefonia.salientes'))responderSesionLocal(['ok'=>false],403);
        if(!is_string($data['destino']??null))throw new InvalidArgumentException('Destino inválido.');
        responderSesionLocal(['ok'=>true,'sesion'=>$service->prepararSaliente($user,$data['destino'])],201);
    }
    if(($data['accion']??'')!=='registrar')throw new InvalidArgumentException('Acción inválida.');
    $service->registrarEvento($user,$data,2);
    $row=$service->obtener($user,(string)$data['session_id']);
    responderSesionLocal(['ok'=>true,'sesion'=>presentarSesionLocal($row)]);
}catch(InvalidArgumentException|JsonException $error){responderSesionLocal(['ok'=>false,'mensaje'=>$error->getMessage()],422);}
catch(DomainException $error){responderSesionLocal(['ok'=>false,'mensaje'=>$error->getMessage()],409);}
catch(Throwable $error){
    if(Database::responderFalloTemporal($error))exit;
    error_log('[telefonia_sesion_local] '.get_class($error));
    responderSesionLocal(['ok'=>false,'mensaje'=>'No se pudo confirmar el registro local. La captura debe conservarse para reintentar.'],503);
}
