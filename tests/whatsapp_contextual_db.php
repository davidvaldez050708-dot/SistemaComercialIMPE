<?php
if(PHP_SAPI!=='cli' || getenv('IMPE_WHATSAPP_CONTEXT_TEST')!=='YES')exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
putenv('IMPE_BASE_URL=https://wa.test/');
if($helper=getenv('IMPE_WA_PERMISSION_FIXTURE'))require_once $helper;
require_once __DIR__.'/../app/models/WhatsAppModel.php';
function waCheck(bool $v,string $why): void {if(!$v)throw new RuntimeException($why);}
function waDeny(callable $fn): void {try{$fn();}catch(DomainException $e){return;}throw new RuntimeException('Se aceptó un acceso indebido.');}
function waSession(int $uid,int $rol=3,array $extra=[]): void {$_SESSION=['usuario_id'=>$uid,'rol_id'=>$rol,'permisos'=>['whatsapp.ver','whatsapp.enviar','ventas.embudo.ver',...$extra]];}
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'',(int)getenv('MYSQL_PORT'));
$db->set_charset('utf8mb4');$schema='impe_whatsapp_test_'.bin2hex(random_bytes(5));
$db->query("CREATE DATABASE $schema CHARACTER SET utf8mb4");$db->select_db($schema);
try{
 foreach([
 'usuarios(id INT PRIMARY KEY,nombre VARCHAR(80),apellidos VARCHAR(80),rol_id INT,estado INT)',
 'roles(id INT PRIMARY KEY,nombre VARCHAR(90),estado INT)',
 'permisos(id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(90),codigo VARCHAR(120) UNIQUE,nombre VARCHAR(180),descripcion TEXT,estado INT)',
 'rol_permisos(rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id))',
 'estados(id INT PRIMARY KEY,nombre VARCHAR(80),estado INT)',
 'municipios(id INT PRIMARY KEY,nombre VARCHAR(80))',
 'seguimientos_vinculacion(id INT PRIMARY KEY,estado_id INT,municipio_id INT NULL,analista_id INT,nombre_entidad VARCHAR(180),contacto_nombre VARCHAR(100),whatsapp_verificado VARCHAR(80),activo INT)',
 'asignaciones_territorio(id INT PRIMARY KEY,estado_id INT,usuario_id INT,tipo_asignacion VARCHAR(40),activo INT,fecha_inicio DATE NULL,fecha_fin DATE NULL)',
 'aliados_asignaciones(seguimiento_id INT,cuenta_clave_usuario_id INT,activo INT)',
 'aliados_contactos(id INT PRIMARY KEY,seguimiento_id INT,numero VARCHAR(40),activo INT,confirmado_whatsapp INT,autorizado_whatsapp INT,preferido_difusion INT)',
 'prospectos_comerciales(id BIGINT UNSIGNED PRIMARY KEY,nombre_completo VARCHAR(180),telefono_normalizado VARCHAR(40))',
 'prospecto_asignaciones(id BIGINT UNSIGNED PRIMARY KEY,prospecto_id BIGINT UNSIGNED,asesor_id INT,vigente INT NULL)',
 'prospecto_embudo_etapas(asignacion_id BIGINT UNSIGNED PRIMARY KEY,etapa VARCHAR(40))',
 'prospecto_embudo_subetapas(asignacion_id BIGINT UNSIGNED PRIMARY KEY,subetapa VARCHAR(40))'
 ] as $sql)$db->query("CREATE TABLE $sql ENGINE=InnoDB");
 $db->query("INSERT INTO roles VALUES(1,'Administrador',1),(3,'Asesor de Ventas',1),(4,'Analista',1),(6,'Cuenta Clave',1)");
 $db->query("INSERT INTO usuarios VALUES(1,'Admin','',1,1),(22,'Asesor','22',3,1),(23,'Asesor','23',3,1),(44,'Analista','44',4,1),(45,'Analista','45',4,1),(66,'Cuenta','Clave',6,1)");
 $db->query("INSERT INTO estados VALUES(1,'Morelos',1),(2,'Otro',1)");
 $db->query("INSERT INTO seguimientos_vinculacion VALUES(10,1,NULL,45,'Institución prueba','Contacto','+527770000010',1),(11,2,NULL,44,'Otra institución','Otro','+527770000011',1)");
 $db->query("INSERT INTO asignaciones_territorio VALUES(1,1,44,'ANALISTA_DATOS',1,NULL,NULL),(2,2,45,'ANALISTA_DATOS',1,NULL,NULL)");
 $db->query("INSERT INTO aliados_asignaciones VALUES(10,66,1)");
 $db->query("INSERT INTO aliados_contactos VALUES(1,10,'+527770000010',1,1,1,1)");
 $db->query("INSERT INTO prospectos_comerciales VALUES(1,'Prospecto uno','+527770000001'),(2,'Prospecto dos','+527770000002'),(3,'Compartido A','+527770000003'),(4,'Compartido B','+527770000003')");
 $db->query("INSERT INTO prospecto_asignaciones VALUES(1,1,22,1),(2,2,23,1),(3,3,22,1),(4,4,23,1)");
 $db->query("INSERT INTO prospecto_embudo_etapas VALUES(1,'NUEVO'),(2,'SEGUIMIENTO'),(3,'NUEVO'),(4,'NUEVO')");
 foreach(['2026_10_02_c_whatsapp_conversaciones.sql','2026_11_18_whatsapp_contexto_comercial.sql'] as $file){
  $sql=preg_replace('/^\s*--.*$/m','',file_get_contents(__DIR__.'/../database/migrations/'.$file));
  foreach(explode(';',$sql) as $piece)if(trim($piece)!=='')$db->query($piece);
 }
 $model=new WhatsAppModel($db);waSession(22);
 $account=$model->guardarCuenta(['nombre'=>'Institucional','phone_number_id'=>'000000001','numero_mostrado'=>'+527770099999','es_predeterminada'=>true,'tipo'=>'PRUEBA'],1);
 $cid=WhatsAppContextoService::abrir($model,'prospecto',1,22);
 waCheck($cid===WhatsAppContextoService::abrir($model,'prospecto',1,22),'mismo chat desde el mismo contexto');
 waCheck((int)$db->query('SELECT COUNT(*) n FROM whatsapp_mensajes')->fetch_assoc()['n']===0,'abrir no envía mensajes');
 waDeny(fn()=>WhatsAppContextoService::abrir($model,'prospecto',2,22));
 $other=$model->crearORecuperarConversacion($account,'527770000002','Otro',23);
 // Propietario del canal no hereda conversaciones ajenas.
 $db->query("UPDATE whatsapp_cuentas SET usuario_id=22 WHERE id=$account");
 waCheck(!$model->obtenerConversacion($other,22,false),'canal no concede acceso');
 $db->query("UPDATE whatsapp_cuentas SET usuario_id=NULL WHERE id=$account");
 waSession(23);waCheck(!$model->obtenerConversacion($cid,23,false),'aislamiento por asesor');
 $db->query('UPDATE prospecto_asignaciones SET asesor_id=23 WHERE id=1');
 waCheck((bool)$model->obtenerConversacion($cid,23,false),'nuevo asesor consulta la conversación');
 waSession(22);waCheck(!$model->obtenerConversacion($cid,22,false),'el anterior pierde acceso');
 waSession(1,1,['whatsapp.gestionar_conversaciones']);
 waCheck((bool)$model->obtenerConversacion($cid,1,WhatsAppContextoService::lecturaGlobal()),'supervisión consulta');
 waCheck(!tienePermiso('whatsapp.enviar'),'administrador conserva restricción operativa');
 waSession(44,4,['seguimientos_vinculacion.ver']);
 $analyst=WhatsAppContextoService::abrir($model,'seguimiento',10,44);
 waDeny(fn()=>WhatsAppContextoService::abrir($model,'seguimiento',11,44));
 waCheck((bool)$model->obtenerConversacion($analyst,44,false),'alcance territorial vigente');
 $db->query('UPDATE asignaciones_territorio SET usuario_id=45 WHERE id=1');
 waCheck(!$model->obtenerConversacion($analyst,44,false),'autoría histórica no concede acceso');
 waSession(45,4,['seguimientos_vinculacion.ver']);waCheck((bool)$model->obtenerConversacion($analyst,45,false),'nuevo analista por territorio');
 waSession(66,6,['aliados.ver']);waCheck($analyst===WhatsAppContextoService::abrir($model,'aliado',10,66),'aliado y analista comparten el chat');
 $db->query('UPDATE aliados_contactos SET autorizado_whatsapp=0 WHERE id=1');waDeny(fn()=>WhatsAppContextoService::abrir($model,'aliado',10,66));
 waSession(23);
 $incoming=$model->registrarMensajeEntrada($account,'527770000001','Prospecto','wamid_prueba','TEXT','Mensaje de prueba',time());
 waCheck($incoming===$cid && (bool)$model->obtenerConversacion($incoming,23,false),'entrada se vincula al mismo prospecto');
 $model->registrarMensajeEntrada($account,'527770000001','Prospecto','wamid_prueba','TEXT','Mensaje de prueba',time());
 waCheck((int)$db->query("SELECT no_leidos FROM whatsapp_conversaciones WHERE id=$cid")->fetch_assoc()['no_leidos']===1,'webhook repetido no duplica no leídos');
 $unknown=$model->registrarMensajeEntrada($account,'527770000099','Desconocido','wamid_sin_asignar','TEXT','Hola',time());
 waCheck(!$model->obtenerConversacion($unknown,23,false),'entrada desconocida queda sin asignar');
 $ambiguous=$model->registrarMensajeEntrada($account,'527770000003','Compartido','wamid_ambiguo','TEXT','Hola',time());
 waCheck(!$model->obtenerConversacion($ambiguous,23,false),'número ambiguo no concede acceso');
 $db->query("UPDATE prospecto_embudo_etapas SET etapa='RECUPERACION' WHERE asignacion_id=1");
 waDeny(fn()=>WhatsAppContextoService::exigirSalida($db,['id'=>$cid]));
 $db->query("UPDATE prospecto_embudo_etapas SET etapa='NUEVO' WHERE asignacion_id=1");
 $db->query("INSERT INTO prospecto_embudo_subetapas VALUES(1,'NO_CONTACTAR')");
 waDeny(fn()=>ProspectoNoContactarV3::exigirPermitido($db,'527770000001'));
 $db->query('DELETE FROM prospecto_embudo_subetapas WHERE asignacion_id=1');
 waCheck((int)$db->query("SELECT COUNT(*) n FROM prospecto_embudo_etapas WHERE etapa='NUEVO'")->fetch_assoc()['n']===3,'abrir no mueve el embudo');
 if(getenv('IMPE_WHATSAPP_HTTP_TEST')==='YES') {
  $p=proc_open(['py',__DIR__.'/whatsapp_contextual_http.py',$schema,(string)$cid], [1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  waCheck(proc_close($p)===0,'HTTP: '.$out.$err);echo $out;
 }
 if(getenv('IMPE_WHATSAPP_PUBLICACION_TEST')==='YES') {
  $digest=static fn()=>hash('sha256',json_encode([$db->query('SELECT * FROM whatsapp_mensajes ORDER BY id')->fetch_all(MYSQLI_ASSOC),$db->query('SELECT * FROM prospecto_asignaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC),$db->query('SELECT * FROM whatsapp_conversacion_prospectos ORDER BY conversacion_id')->fetch_all(MYSQLI_ASSOC)]));
  $antes=$digest();
  $p=proc_open(['py',__DIR__.'/publicar_whatsapp_contextual_test.py',$schema],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  waCheck(proc_close($p)===0,'publicación: '.$out.$err);waCheck($antes===$digest(),'publicación conserva datos');echo $out;
 }
 if(getenv('IMPE_WA_HOST_COMPAT_TEST')==='YES') {
  $p=proc_open(['py',__DIR__.'/publicar_whatsapp_hostinger_compat_test.py',$schema],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  waCheck(proc_close($p)===0,'compatibilidad Hostinger: '.$out.$err);echo $out;
 }
 if($dir=getenv('IMPE_WA_RENDER_DIR')){
  if(!is_dir($dir))mkdir($dir,0700,true);
  waSession(23);$_SESSION['csrf_token']='browser-fixture-csrf-only';
  $conversacionSeleccionada=$model->obtenerConversacion($cid,23,false);
  $ventanaServicioAbierta=true;$puedeEnviarWhatsapp=true;$puedePlantillaPrueba=true;
  $mensajesIniciales=$model->obtenerMensajes($cid,23,false);
  $estadoConfiguracionWhatsapp=['lista'=>true];
  ob_start();require __DIR__.'/../app/views/whatsapp/panel.php';file_put_contents($dir.'/panel.html',ob_get_clean());
  $estadoConfiguracionWhatsapp=['lista'=>false];$puedeEnviarWhatsapp=false;$puedePlantillaPrueba=false;
  ob_start();require __DIR__.'/../app/views/whatsapp/panel.php';file_put_contents($dir.'/pendiente.html',ob_get_clean());
 }
 echo "CHECK_OK WhatsApp contextual: reutilización, canal compartido, permisos, reasignación, territorio, consentimiento, entrada, duplicados, sin asignar, ambigüedad y restricciones de salida.\n";
}finally{$db->query("DROP DATABASE $schema");$db->close();}
