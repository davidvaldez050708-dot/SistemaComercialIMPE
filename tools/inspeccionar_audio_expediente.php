<?php
/** Diagnóstico local de sólo lectura. Nunca sirve por HTTP ni descarga audio. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if($argc!==4||!ctype_digit($argv[1])||!ctype_digit($argv[2])||!ctype_digit($argv[3])){
    fwrite(STDERR,"Uso: php tools/inspeccionar_audio_expediente.php USUARIO_ID SEGUIMIENTO_ID INTERACCION_ID\n");exit(2);
}
require_once dirname(__DIR__).'/config/db_connection.php';
require_once dirname(__DIR__).'/app/services/TelefoniaGrabacionExpedienteTransferidaService.php';
try{
    $db=(new Database())->connect(false);
    $db->query('START TRANSACTION READ ONLY');
    $result=(new TelefoniaGrabacionExpedienteTransferidaService($db))->inspeccionar((int)$argv[1],(int)$argv[2],(int)$argv[3]);
    $result['referencia_sha256']=empty($result['pbx_call_id'])?null:hash('sha256',$result['pbx_call_id']);unset($result['pbx_call_id']);
    $db->rollback();
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'No se pudo comprobar la evidencia: '.get_class($e).PHP_EOL);exit(1);}
