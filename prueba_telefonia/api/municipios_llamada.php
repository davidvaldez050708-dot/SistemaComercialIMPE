<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
require_once dirname(__DIR__,2).'/app/helpers/PermissionHelper.php';
require_once dirname(__DIR__,2).'/app/services/TelefoniaEntrantesAnalistaService.php';
$user=(int)($_SESSION['usuario_id']??0);
if(!$user){http_response_code(401);echo json_encode(['ok'=>false]);exit;}
if(!tienePermiso('telefonia.usar')||!tienePermiso('seguimientos_vinculacion.operar_propios')){
    http_response_code(403);echo json_encode(['ok'=>false,'mensaje'=>'No tienes acceso al catálogo para esta llamada.']);exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);echo json_encode(['ok'=>false]);exit;}
try{
    $estado=filter_var($_GET['estado_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $db=(new Database())->connect();
    $permitidos=(new TelefoniaEntrantesAnalistaService($db))->estados($user);
    if(!$estado||!in_array($estado,array_map('intval',array_column($permitidos,'id')),true)){
        http_response_code(422);echo json_encode(['ok'=>false,'mensaje'=>'El estado no pertenece a tus territorios.']);exit;
    }
    $rows=(new SeguimientoVinculacionModel($db))->obtenerMunicipiosActivosEstado($estado);
    echo json_encode(['ok'=>true,'municipios'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'nombre'=>(string)$r['nombre']],$rows)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
    error_log('[municipios_llamada] '.get_class($e));http_response_code(503);
    echo json_encode(['ok'=>false,'mensaje'=>'No se pudo cargar el catálogo de municipios. Intenta nuevamente.']);
}
