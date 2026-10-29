<?php
$case=json_decode((string)getenv('IMPE_TEST_AUDIO_CASE'),true,512,JSON_THROW_ON_ERROR);
date_default_timezone_set('America/Mexico_City');session_save_path(sys_get_temp_dir());session_id('impe-audio-'.bin2hex(random_bytes(10)));
session_start();$_SESSION=['usuario_id'=>$case['uid'],'rol_id'=>3];session_write_close();
$_SERVER['REQUEST_METHOD']=$case['method']??'GET';$_SERVER['HTTP_RANGE']=$case['range']??'';
$_GET=['pbx_call_id'=>$case['ref'],'extension'=>$case['ext']??'150'];
if(!empty($case['verify']))$_GET['verificar']='1';
register_shutdown_function(static function(){fwrite(STDERR,'HTTP:'.(http_response_code()?:200)."\n");if(session_status()===PHP_SESSION_ACTIVE){$_SESSION=[];session_destroy();}});
require dirname(__DIR__).'/prueba_telefonia/api/grabacion_ventas.php';
