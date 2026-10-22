<?php
// Sólo lo ejecuta el arnés Python contra una instalación temporal, nunca contra el CRM real.
$_SERVER['DOCUMENT_ROOT']=$argv[1];
require $argv[1].'/tests/correos_marketing_test.php';
require $argv[1].'/app/services/CorreoConvocatoriaComposicion.php';
function v4check($ok,$message) { if(!$ok) { throw new RuntimeException($message); } }
$stage=$argv[1];$legacy=$argv[2]==='legacy';
putenv('IMPE_MAIL_STATE_DIR='.dirname($stage).'/state');
$imageDir=$stage.'/public/uploads/convocatorias';mkdir($imageDir,0700,true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR1sAAAAASUVORK5CYII=');
file_put_contents($imageDir.'/original.png',$png);
$selection=['id'=>11,'titulo'=>'Convocatoria QA','imagen_ref'=>'public/uploads/convocatorias/original.png','imagen_sha'=>hash('sha256',$png)];
$composition=new CorreoConvocatoriaComposicion(null,$stage);
$payloads=[];$status=204;
$loader=static fn($id)=>['correo'=>'qa@rededucativamexico.org','estado'=>1,'rol_estado'=>1,'rol'=>'Marketing','nombre'=>'QA'];
$transport=new CorreoSalidaInstitucionalService(null,new CorreoCuentaConectadaService($loader),static function($method,$url,$token,$payload)use(&$payloads,&$status){
    v4check($token==='TOKEN_FICTICIO_AISLADO','Token sólo ficticio');
    if($method==='GET') { return ['ok'=>true,'codigo_http'=>200,'json'=>['data'=>['mailboxes'=>[['address'=>'qa@rededucativamexico.org','resourceId'=>'ACqa']]]]]; }
    v4check($method==='POST' && str_ends_with($url,'/ACqa/send'),'Contrato existente y buzón exacto');
    $payloads[]=$payload;return ['ok'=>$status===204,'codigo_http'=>$status];
});
$data=['remitente'=>'qa@rededucativamexico.org','destinatario'=>'destino@example.test','asunto'=>'Prueba aislada','cuerpo'=>"Texto completo\nMéxico",'idempotency_key'=>'qa-sin-convocatoria'];
$db=new MarketingDb();$service=new CorreoMarketingService($db,$transport,$loader);
$plain=$service->enviar(7,$data);
v4check($plain['ok'] && count($payloads)===1 && $db->sentWrites===1,'A: envío simple acepta y registra');
$db=new MarketingDb();$decorator=$composition->envio($selection,$transport);$service=new CorreoMarketingService($db,$decorator,$loader);
$data['idempotency_key']='qa-con-convocatoria';$data['convocatoria_id']=11;
$sent=$service->enviar(7,$data);
if($legacy) {
    v4check(!$sent['ok'] && $sent['estado_entrega']==='DESCONOCIDO' && count($payloads)===1 && $db->rows===[],'B anterior falla antes de POST e historial');
    try { $decorator->prepararSalida($data['remitente'],7);throw new LogicException('Debió fallar'); }
    catch(Error $e) { v4check(str_contains($e->getMessage(),'resolverSalida() on null'),'Excepción exacta: cuentas del padre sin inicializar'); }
    echo "REPRODUCIDO v4: sin convocatoria funciona; con convocatoria Error resolverSalida() on null, en prepararSalida heredado, antes de POST/registro. No es rechazo de imagen por API.\n";exit;
}
v4check($sent['ok'] && count($payloads)===2 && $db->sentWrites===1 && $db->rows[1]['estado']==='ENVIADO','B corregido acepta y registra una vez');
$p=$payloads[1];$inline=array_values(array_filter($p['attachments'],static fn($a)=>isset($a['cid'])));
v4check(count($inline)===1 && base64_decode($inline[0]['content'])===$png && str_contains($p['html'],'cid:'.$inline[0]['cid']),'Imagen original una vez en HTML/CID/base64');
v4check($p['text']===$data['cuerpo'] && $p['to']===['destino@example.test'] && $p['subject']===$data['asunto'],'Contenido e identidad conservados');
v4check($service->enviar(7,$data)===$sent && count($payloads)===2 && $db->sentWrites===1,'Repetir petición no duplica POST ni historial');
v4check($decorator->validarRemitente($data['remitente'],7)['ok'],'Validación se delega al transporte instalado');
// Borrador y metadatos por el contrato original.
$data['idempotency_key']='qa-con-borrador';$db=new MarketingDb();$service=new CorreoMarketingService($db,$decorator,$loader);
$draft=$service->guardarBorrador(7,$data);$selection['filtro_convenio']='manual:1';
$composition->registrar(7,$draft['correo_id'],$selection);$data['correo_id']=$draft['correo_id'];
v4check($service->obtener(7,1)['cuerpo']===$data['cuerpo'] && $composition->leer(7,1)['filtro_convenio']==='manual:1','Borrador recupera contenido y referencia');
v4check($service->enviar(7,$data)['ok'] && $db->rows[1]['estado']==='ENVIADO','Borrador con convocatoria conserva historial');unset($data['correo_id']);
// Rechazo y resultado incierto nunca son ENVIADO ni disparan reenvío automático.
foreach([403=>'RECHAZADO',503=>'DESCONOCIDO'] as $http=>$expected) {
    $status=$http;$db=new MarketingDb();$service=new CorreoMarketingService($db,$decorator,$loader);$data['idempotency_key']='qa-api-'.$http;
    $r=$service->enviar(7,$data);v4check(!$r['ok'] && $r['estado_entrega']===$expected && $db->sentWrites===0,'Clasificación real del transporte conservada');
    if($http===503) { $before=count($payloads);$service->enviar(7,$data);v4check(count($payloads)===$before,'No reintentar incertidumbre'); }
}
$status=204;
// Adjunto manual junto a imagen; no cambia su nombre, bytes ni disposición.
$input=$data+['usuario_id'=>7,'perfil_marketing'=>true];$input['idempotency_key']='qa-imagen-y-manual';
$manual=$stage.'/manual.txt';file_put_contents($manual,'Documento manual íntegro');
$input['adjuntos']=[['ruta'=>$manual,'nombre'=>'manual.txt','mime'=>'text/plain']];
v4check($decorator->enviar($input)['ok'],'Imagen junto con adjunto manual');
$p=end($payloads);$manuals=array_values(array_filter($p['attachments'],static fn($a)=>empty($a['cid'])));
v4check(count($p['attachments'])===2 && count($manuals)===1 && base64_decode($manuals[0]['content'])==='Documento manual íntegro','Sin duplicar imagen como adjunto manual');
// Cambiar convocatoria usa exactamente el archivo nuevo.
file_put_contents($imageDir.'/segunda.png',$png.'SEGUNDA');
$other=['id'=>12,'titulo'=>'Otra QA','imagen_ref'=>'public/uploads/convocatorias/segunda.png','imagen_sha'=>hash_file('sha256',$imageDir.'/segunda.png')];
$input['idempotency_key']='qa-cambio-imagen';v4check($composition->envio($other,$transport)->enviar($input)['ok'],'Cambio de convocatoria');
$p=end($payloads);v4check(str_contains($p['html'],'cid:convocatoria-12-') && !str_contains($p['html'],'cid:convocatoria-11-'),'No arrastra imagen anterior');
// Convocatoria sin imagen mantiene el envío normal y el documento manual.
$input['idempotency_key']='qa-sin-imagen';v4check($composition->envio(['id'=>13,'titulo'=>'Sin imagen','imagen_ref'=>'','imagen_sha'=>''],$transport)->enviar($input)['ok'],'Convocatoria sin imagen');
$p=end($payloads);v4check(count($p['attachments'])===1 && !str_contains($p['html'],'cid:convocatoria-'),'No adjunta una imagen ajena');
// PNG válido cercano al límite original de 5 MB, sin reducir ni modificar su contenido.
$large=$png.str_repeat("\0",5*1024*1024-1024-strlen($png));file_put_contents($imageDir.'/grande.png',$large);
$big=['id'=>14,'titulo'=>'Grande QA','imagen_ref'=>'public/uploads/convocatorias/grande.png','imagen_sha'=>hash('sha256',$large)];
$input['idempotency_key']='qa-imagen-grande';v4check($composition->envio($big,$transport)->enviar($input)['ok'],'Imagen cercana al límite conserva contrato');
$p=end($payloads);$inline=array_values(array_filter($p['attachments'],static fn($a)=>isset($a['cid'])));
v4check(count($inline)===1 && hash('sha256',base64_decode($inline[0]['content']))===$big['imagen_sha'],'Imagen grande íntegra, sin duplicados');
$input['adjuntos'][0]['ruta']=$imageDir.'/grande.png';$input['adjuntos']=array_fill(0,4,$input['adjuntos'][0]);
$input['idempotency_key']='qa-total-excedido';$before=count($payloads);$r=$composition->envio($big,$transport)->enviar($input);
v4check(!$r['ok'] && $r['estado_entrega']==='RECHAZADO' && count($payloads)===$before,'Total mayor a 20 MB se rechaza antes de POST');
file_put_contents($imageDir.'/grande.png',$large.str_repeat("\0",2048));
$input['idempotency_key']='qa-imagen-excedida';$r=$composition->envio($big,$transport)->enviar($input);
v4check(!$r['ok'] && count($payloads)===$before,'Imagen alterada/fuera de límite se rechaza sin envío');
class CvHistoryFailureDb extends MarketingDb {
    public function prepare($sql) { return new CvHistoryFailureStmt($this,$sql); }
}
class CvHistoryFailureStmt extends MarketingStmt {
    private $fail;
    public function __construct($db,$sql) { parent::__construct($db,$sql);$this->fail=str_contains($sql,'fecha_envio = NOW()'); }
    public function execute() { if($this->fail) { throw new RuntimeException('FALLO_HISTORIAL_SIMULADO'); }return parent::execute(); }
}
$recordInput=$data;$recordInput['idempotency_key']='qa-historial-incierto';$db=new CvHistoryFailureDb();
$service=new CorreoMarketingService($db,$decorator,$loader);$before=count($payloads);$r=$service->enviar(7,$recordInput);
v4check(!$r['ok'] && $r['estado_entrega']==='DESCONOCIDO' && count($payloads)===$before+1 && $db->sentWrites===0,'Aceptación del proveedor con registro fallido no inventa historial');
$service->enviar(7,$recordInput);v4check(count($payloads)===$before+1,'Registro incierto no reenvía un correo ya aceptado');
echo "OK v4: envío simple y convocatoria, autorización delegada, POST/CID/bytes originales, aceptación/historial únicos, borrador, rechazo y resultado incierto sin reenvío. API/BD simuladas, sin recepción real.\n";
