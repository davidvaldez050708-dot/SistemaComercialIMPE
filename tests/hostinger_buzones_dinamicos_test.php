<?php
// Descubrimiento y diagnóstico dirigidos: sólo API simulada y configuración ficticia.
require dirname(__DIR__).'/app/services/CorreoCuentaConectadaService.php';
require dirname(__DIR__).'/tools/diagnosticar_hostinger_comercial.php';
function bdCheck($ok,string $label): void { if(!$ok) { throw new RuntimeException($label); } }
$dir=sys_get_temp_dir().'/impe-boxes-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
putenv('IMPE_PRIVATE_CONFIG_DIR='.$dir);putenv('HOSTINGER_MAIL_API_TOKEN=QA_BOXES_TOKEN');
$write=static function(string $name,array $data)use($dir){file_put_contents($dir.'/'.$name,'<?php return '.var_export($data,true).';');};
$boxes=[['address'=>'qa.uno@rededucativamexico.org','resourceId'=>'ACuno']];$calls=0;
$request=static function($method,$url,$token,$payload)use(&$boxes,&$calls){
    bdCheck($method==='GET' && $url===CorreoHostingerApiService::BASE.'/api/v1/me','Una consulta oficial, sin endpoint ni paginación inventados');
    bdCheck($token==='QA_BOXES_TOKEN','Credencial central seleccionada');$calls++;
    return ['ok'=>true,'codigo_http'=>200,'json'=>['data'=>['mailboxes'=>$boxes]]];
};
$identity=static function(){return ['estado'=>1,'rol_estado'=>1,'rol_id'=>4,'rol'=>'Marketing'];};
$resolver=new CorreoCuentaConectadaService($identity);$resolver->configurarConsultaApi($request);
$api=new CorreoHostingerApiService($request);
try {
    $a=$resolver->remitentes(4);bdCheck($a['ok'] && count($a['remitentes'])===1,'Token de un solo buzón');
    bdCheck(!$resolver->resolver('qa.nuevo@rededucativamexico.org',4)['ok'],'El dominio no basta para autorizar');
    $boxes[]=['address'=>'QA.NUEVO@rededucativamexico.org','resourceId'=>'ACnuevo'];
    $boxes[]=['address'=>'qa.otro@rededucativamexico.org','resourceId'=>'ACotro'];
    $boxes[]=['address'=>'ajeno@otra-institucion.test','resourceId'=>'ACajeno'];
    $a=$resolver->remitentes(4);
    bdCheck(count($a['remitentes'])===3 && $a['diagnostico']['total_api']===4 && $a['diagnostico']['total_institucional']===3,'Todos los buzones institucionales, sin permitir otro dominio');
    bdCheck($resolver->resolver('QA.NUEVO@rededucativamexico.org',4)['ok'],'Nuevo buzón utilizable sin cambiar código ni registrarlo');
    foreach([2,3,4,5] as $user) { bdCheck($resolver->resolver('qa.nuevo@rededucativamexico.org',$user)['ok'],'Servicio compartido con identidad activa'); }
    array_shift($boxes);bdCheck(!$resolver->resolver('qa.uno@rededucativamexico.org',4)['ok'],'Revocación visible sin caché');
    bdCheck(!str_contains(json_encode($a),'QA_BOXES_TOKEN') && !str_contains(json_encode($a),'ACnuevo'),'El endpoint no expone credenciales ni identificadores');
    $write('mail_central.local.php',['allowed_senders'=>['qa.nuevo@rededucativamexico.org'],'user_senders'=>[3=>[]],'role_senders'=>['Marketing'=>['qa.nuevo@rededucativamexico.org']]]);
    $a=$resolver->remitentes(4);bdCheck($a['remitentes']===['qa.nuevo@rededucativamexico.org'] && $a['diagnostico']['restricciones_locales'],'Intersección con restricciones existentes');
    bdCheck(!$resolver->remitentes(3)['remitentes'],'Restricción del usuario no anulada');
    $write('mail_accounts.local.php',['accounts'=>['qa.nuevo@rededucativamexico.org'=>['activo'=>false,'usuarios'=>[4]]]]);
    bdCheck(!$resolver->remitentes(4)['remitentes'],'Desactivación local conservada');
    $diagnostic=implode("\n",diagnosticarHostingerComercial($api,'qa.ausente@rededucativamexico.org'));
    bdCheck(str_contains($diagnostic,'API autorizados: 3; institucionales') && str_contains($diagnostic,'NO devuelto') && str_contains($diagnostic,'Restricción user_senders: configurada'),'Diagnóstico separa alcance API, dominio y filtros locales');
    bdCheck(!str_contains($diagnostic,'QA_BOXES_TOKEN'),'Diagnóstico sin token');
    // Configuraciones antiguas rotas no interfieren con un token de mayor prioridad.
    file_put_contents($dir.'/hostinger_mail_config.php','<?php throw new RuntimeException("NO_CARGAR_LEGACY");');
    file_put_contents($dir.'/marketing_mail.local.php','<?php throw new RuntimeException("NO_CARGAR_LEGACY");');
    bdCheck($api->configuracion()['source']==='entorno HOSTINGER_MAIL_API_TOKEN','Entorno válido evita ejecutar archivos antiguos');
    putenv('HOSTINGER_MAIL_API_TOKEN=   ');
    $write('mail_central.local.php',['transport'=>'hostinger_api','api_token'=>'QA_BOXES_TOKEN']);
    bdCheck($api->configuracion()['source']==='mail_central.local.php','Entorno vacío permite token privado central');
    $before=file_get_contents($dir.'/mail_central.local.php');
    $saved=guardarTokenHostingerComercial('QA_BOXES_TOKEN',static function(){return ['qa.nuevo@rededucativamexico.org'=>'ACnuevo'];});
    bdCheck(!empty($saved['sin_cambios']) && file_get_contents($dir.'/mail_central.local.php')===$before,'Configuración válida idéntica no se reescribe ni respalda innecesariamente');
    $boxes[]=['address'=>'qa.nuevo@rededucativamexico.org','resourceId'=>'ACconflicto'];
    try {$api->buzones('QA_BOXES_TOKEN');throw new LogicException('Aceptó identidad ambigua');}catch(RuntimeException $e){bdCheck($e->getMessage()==='HOSTINGER_RESPUESTA_INVALIDA','Dirección/ID ambiguos rechazados');}
    $boxes=[['address'=>'qa@otro-dominio.test','resourceId'=>'ACotro']];
    try {$api->buzones('QA_BOXES_TOKEN');throw new LogicException('Aceptó otro dominio');}catch(RuntimeException $e){bdCheck($e->getMessage()==='HOSTINGER_SIN_BUZONES_INSTITUCIONALES','Sin buzones institucionales se distingue de token ausente');}
    bdCheck($api->descubrimiento()['total_api']===1,'Se conserva evidencia del alcance sin revelar el token');
    echo "OK: descubrimiento 1→3, alta/revocación sin caché, dominio exacto, identidad/ID, filtros, fuente privada, diagnóstico e ingreso idempotente. Sin red, envíos ni BD reales.\n";
} finally {
    foreach(glob($dir.'/*')?:[] as $p) { unlink($p); }
    foreach(glob($dir.'/.*')?:[] as $p) { if(is_file($p)) { unlink($p); } }
    rmdir($dir);putenv('IMPE_PRIVATE_CONFIG_DIR');putenv('HOSTINGER_MAIL_API_TOKEN');
}
