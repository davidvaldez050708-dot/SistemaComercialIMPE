<?php
require_once __DIR__.'/../app/services/WhatsAppCloudApiService.php';
class PlantillaVentasFixture extends WhatsAppCloudApiService
{
    public array $templates=[];
    public function __construct(){}
    protected function listarPlantillasMeta(string $cuenta,string $nombre): array{return ['ok'=>true,'filas'=>$this->templates];}
}
$api=new PlantillaVentasFixture();
$template=['name'=>'inicio_atencion_comercial','language'=>'es_MX','status'=>'APPROVED','category'=>'MARKETING','components'=>[['type'=>'BODY','text'=>'Hola, somos Fundación Red Educativa México. ¿Deseas que continuemos con tu registro?']]];
$api->templates=[$template];
waCheck($api->consultarPlantillaComercial('2586216875220299','inicio_atencion_comercial','es_MX')['ok'],'rechaza plantilla aprobada válida');
foreach([
 array_replace($template,['status'=>'PENDING']),
 array_replace($template,['language'=>'en_US']),
 array_replace($template,['category'=>'AUTHENTICATION']),
 array_replace($template,['components'=>[['type'=>'BODY','text'=>'Hola {{1}}']]]),
 array_replace($template,['components'=>[['type'=>'HEADER','format'=>'IMAGE'],['type'=>'BODY','text'=>'Hola']]])
] as $bad){$api->templates=[$bad];waCheck(!$api->consultarPlantillaComercial('2586216875220299','inicio_atencion_comercial','es_MX')['ok'],'acepta plantilla no válida para primer contacto');}
$api->templates=[$template];
$model=new WhatsAppModel($db);waSession(22);
$trial=$model->guardarCuenta(['nombre'=>'Prueba asignada','phone_number_id'=>'000000001','numero_mostrado'=>'+527770099999','usuario_id'=>22,'tipo'=>'PRUEBA'],1);
$company=$model->guardarCuenta(['nombre'=>'Fundación empresarial','phone_number_id'=>'1471856739340789','numero_mostrado'=>'+5255667258084','es_predeterminada'=>true,'tipo'=>'EMPRESARIAL'],1);
waCheck((int)$model->resolverCuentaUsuario(22)['id']===$company,'se elige prueba asignada en vez de empresa');
$db->query('UPDATE whatsapp_cuentas SET activo=0 WHERE id='.$company);
waCheck($model->resolverCuentaUsuario(22)===null,'sin empresa vuelve silenciosamente a prueba');
$db->query('UPDATE whatsapp_cuentas SET activo=1 WHERE id='.$company);
$mine=WhatsAppContextoService::abrir($model,'prospecto',1,22);
waCheck((int)$model->obtenerConversacion($mine,22,false)['cuenta_id']===$company,'el embudo no usa empresa');
waCheck($mine===WhatsAppContextoService::abrir($model,'prospecto',1,22),'abrir duplica el chat');
waCheck(!$model->obtenerConversaciones(22,false),'abrir sin enviar llena la bandeja');
waDeny(fn()=>WhatsAppContextoService::abrir($model,'prospecto',2,22));
$model->registrarMensajeSalida($mine,22,'Mensaje guardado de prueba',['ok'=>true,'wamid'=>'fixture-seed'],'TEXT');
$other=$model->registrarMensajeEntrada($company,'527770000002','Prospecto dos','fixture-entrada','TEXT','Hola',time());
waCheck((int)$db->query('SELECT prospecto_id FROM whatsapp_conversacion_prospectos WHERE conversacion_id='.$other)->fetch_row()[0]===2,'no reconoce al prospecto entrante');
$unknown=$model->registrarMensajeEntrada($company,'527770000099','Desconocido','fixture-desconocido','TEXT','Hola',time());
$assignedRaw=$model->crearORecuperarConversacion($company,'527770000098','Sin prospecto',22);
$old=$model->crearORecuperarConversacion($trial,'527770000001','Chat de prueba',22);
WhatsAppContextoService::enlazar($db,$old,1);$model->registrarMensajeSalida($old,22,'Historial de prueba',['ok'=>false,'mensaje'=>'Error de prueba']);
waSession(22,3,['whatsapp.gestionar_conversaciones','whatsapp.responder_todas']);
waCheck(!WhatsAppContextoService::lecturaGlobal(),'el asesor obtiene consulta global');
foreach([$other,$unknown,$assignedRaw,$old] as $id)waCheck(!$model->obtenerConversacion($id,22,true),'el asesor consulta chat ajeno, libre o de prueba');
$list=$model->obtenerConversaciones(22,true);
waCheck(count($list)===1 && (int)$list[0]['id']===$mine,'la bandeja mezcla carteras');
waCheck((int)$list[0]['prospecto_id']===1 && $list[0]['prospecto_nombre']==='Prospecto uno' && trim($list[0]['responsable_nombre'])==='Asesor 22','etiquetas de prospecto y asesor incorrectas');
foreach([1=>1,2=>2,6=>6] as $uid=>$role){waSession($uid,$role,['whatsapp.gestionar_conversaciones']);waCheck(count($model->obtenerConversaciones($uid,true))>=4,'supervisión no consulta todos los chats');}
waSession(22,3,['whatsapp.gestionar_conversaciones']);
$db->query('UPDATE prospecto_asignaciones SET asesor_id=23 WHERE id=1');
waCheck(!$model->obtenerConversacion($mine,22,true),'asesor anterior conserva acceso');
waSession(23);$moved=$model->obtenerConversacion($mine,23,false);
waCheck($moved && trim($moved['responsable_nombre'])==='Asesor 23','nuevo asesor no obtiene chat o etiqueta');
$db->query('UPDATE prospecto_asignaciones SET asesor_id=22 WHERE id=1');
waSession(1,1,['whatsapp.gestionar_cuentas','whatsapp.gestionar_conversaciones']);
$ventas=new WhatsAppVentasService($db);
$ventas->guardarPlantilla($company,'2586216875220299','inicio_atencion_comercial','es_MX',$api);
waCheck($ventas->configuracion($company)['plantilla_cuerpo']===$template['components'][0]['text'],'se guarda un texto distinto al aprobado');
$api->templates=[array_replace($template,['status'=>'PAUSED'])];
waDeny(fn()=>$ventas->guardarPlantilla($company,'2586216875220299','inicio_atencion_comercial','es_MX',$api));
waCheck($ventas->configuracion($company)['plantilla_cuerpo']===$template['components'][0]['text'],'un rechazo sobreescribe la plantilla anterior');
waSession(22);waDeny(fn()=>$ventas->guardarPlantilla($company,'2586216875220299','inicio_atencion_comercial','es_MX',$api));
$ambiguous=$model->registrarMensajeEntrada($company,'527770000003','Ambiguo','fixture-ambiguo','TEXT','Hola',time());
waCheck(!$db->query('SELECT prospecto_id FROM whatsapp_conversacion_prospectos WHERE conversacion_id='.$ambiguous)->fetch_row(),'el reconocimiento asigna un teléfono ambiguo');
if(getenv('IMPE_WHATSAPP_INSTALL_TEST')==='YES'){
 $db->query('ALTER TABLE seguimientos_vinculacion ADD telefono_fuente VARCHAR(40), ADD telefono_verificado VARCHAR(40)');
 foreach(['whatsapp.ver','whatsapp.gestionar_conversaciones','whatsapp.responder_todas','whatsapp.enviar'] as $code){$s=$db->prepare("INSERT IGNORE INTO permisos(codigo,estado) VALUES(?,1)");$s->bind_param('s',$code);$s->execute();$s->close();}
 $db->query("INSERT IGNORE INTO rol_permisos SELECT 3,id FROM permisos");
 $p=proc_open([getenv('PYTHON_BINARY')?:'python3',__DIR__.'/whatsapp_ventas_install_test.py',$schema], [1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);waCheck(proc_close($p)===0,'Instalador ventas:'.$out.$err);echo $out;
}
if(getenv('IMPE_WHATSAPP_HTTP_TEST')==='YES'){
 $p=proc_open([getenv('PYTHON_BINARY')?:'python3',__DIR__.'/whatsapp_ventas_http.py',$schema,(string)$mine,(string)$other], [1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);waCheck(proc_close($p)===0,'HTTP ventas:'.$out.$err);echo $out;
}
echo "PASS: cartera exclusiva, supervisión global, etiquetas, reasignación, canal empresarial, reconocimiento y plantillas aprobadas.\n";
