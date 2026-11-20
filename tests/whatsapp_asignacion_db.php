<?php
if(PHP_SAPI!=='cli' || getenv('IMPE_WA_ASSIGN_TEST')!=='YES')exit(2);
require __DIR__.'/../app/services/WhatsAppAsignacionService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'',(int)getenv('MYSQL_PORT'));
$schema='impe_wa_queue_test_'.bin2hex(random_bytes(5));
$db->query("CREATE DATABASE $schema CHARACTER SET utf8mb4");$db->select_db($schema);$db->set_charset('utf8mb4');
function okQueue($v,string $why):void {if(!$v)throw new RuntimeException($why);}
function denyQueue(callable $fn,int $code):void {try{$fn();}catch(DomainException $e){okQueue($e->getCode()===$code,'Código de rechazo incorrecto');return;}throw new RuntimeException('Se permitió una operación prohibida.');}
function adminQueue():void {$_SESSION=['usuario_id'=>1,'rol_id'=>1,'permisos'=>['whatsapp.ver','whatsapp.gestionar_cuentas','whatsapp.gestionar_conversaciones']];}
try {
 foreach([
 'roles(id INT PRIMARY KEY,nombre VARCHAR(100),estado INT)',
 'usuarios(id INT PRIMARY KEY,nombre VARCHAR(100),apellidos VARCHAR(100),rol_id INT,estado INT)',
 'permisos(id INT PRIMARY KEY,codigo VARCHAR(100),estado INT)',
 'rol_permisos(rol_id INT,permiso_id INT)',
 'whatsapp_cuentas(id INT PRIMARY KEY,nombre VARCHAR(100),activo INT)',
 'whatsapp_conversaciones(id INT PRIMARY KEY,cuenta_id INT,nombre_contacto VARCHAR(150),telefono_normalizado VARCHAR(40),aliado_seguimiento_id INT NULL,responsable_usuario_id INT NULL,ultimo_mensaje_preview TEXT,ultimo_mensaje_at DATETIME)',
 'whatsapp_mensajes(id INT AUTO_INCREMENT PRIMARY KEY,conversacion_id INT,contenido TEXT)',
 'whatsapp_conversacion_prospectos(conversacion_id INT PRIMARY KEY,prospecto_id INT)',
 'prospectos_comerciales(id INT PRIMARY KEY,telefono_normalizado VARCHAR(40))',
 'prospecto_asignaciones(id INT PRIMARY KEY,prospecto_id INT,asesor_id INT,vigente INT)',
 'seguimientos_vinculacion(id INT PRIMARY KEY,activo INT,estado_id INT,analista_id INT,telefono_fuente VARCHAR(40),telefono_verificado VARCHAR(40),whatsapp_verificado VARCHAR(40))',
 'estados(id INT PRIMARY KEY,estado INT)',
 'asignaciones_territorio(id INT PRIMARY KEY,usuario_id INT,estado_id INT,tipo_asignacion VARCHAR(30),activo INT,fecha_inicio DATE,fecha_fin DATE)'
 ] as $table)$db->query('CREATE TABLE '.$table.' ENGINE=InnoDB');
 $db->query(file_get_contents(__DIR__.'/../database/migrations/2026_11_20_whatsapp_asignaciones_historial.sql'));
 $db->query("INSERT INTO roles VALUES(1,'Administrador',1),(3,'Asesor de Ventas',1),(4,'Analista de Datos',1)");
 $db->query("INSERT INTO usuarios VALUES(1,'Admin','',1,1),(22,'Asesor','Uno',3,1),(23,'Asesor','Dos',3,1),(24,'Inactivo','',3,0),(40,'Analista','Uno',4,1)");
 $db->query("INSERT INTO permisos VALUES(1,'whatsapp.ver',1),(2,'whatsapp.enviar',1)");
 $db->query('INSERT INTO rol_permisos VALUES(3,1),(3,2),(4,1),(4,2)');
 $db->query("INSERT INTO whatsapp_cuentas VALUES(1,'Empresarial',1),(2,'Inactivo',0)");
 $db->query('INSERT INTO estados VALUES(11,1)');
 $db->query("INSERT INTO asignaciones_territorio VALUES(1,40,11,'ANALISTA_DATOS',1,NULL,NULL)");
 for($id=1;$id<=60;$id++) {
  $db->query("INSERT INTO whatsapp_conversaciones VALUES($id,1,'Contacto $id','525500000000',NULL,NULL,'Mensaje $id',NOW())");
  $db->query("INSERT INTO whatsapp_mensajes(conversacion_id,contenido) VALUES($id,'Mensaje')");
 }
 $service=new WhatsAppAsignacionService($db);adminQueue();
 okQueue(count($service->candidatos())===3,'Candidatos incluyen inactivos o excluyen operativos válidos');
 $page=$service->pendientes(1);okQueue($page['total']===60 && count($page['filas'])===50 && $page['paginas']===2,'Paginación incorrecta');
 okQueue(count($service->pendientes(2)['filas'])===10,'Segunda página incorrecta');
 okQueue($service->pendientes(1,'Contacto 60')['total']===1,'Búsqueda incorrecta');
 okQueue($service->pendientes(1,"' OR 1=1 --")['total']===0,'Búsqueda no parametrizada');
 $service->asignar(1,22);
 okQueue((int)$db->query('SELECT responsable_usuario_id FROM whatsapp_conversaciones WHERE id=1')->fetch_row()[0]===22,'Asignación no guardada');
 okQueue($service->pendientes()['total']===59,'La asignación sigue en pendientes');
 okQueue($service->asignar(1,22)['ya_asignada'],'Reintento no idempotente');
 okQueue((int)$db->query('SELECT COUNT(*) FROM whatsapp_asignaciones_historial')->fetch_row()[0]===1,'Reintento duplicó auditoría');
 denyQueue(fn()=>$service->asignar(1,23),409);
 denyQueue(fn()=>$service->asignar(2,24),422);
 denyQueue(fn()=>$service->asignar(2,1),422);
 $_SESSION=['usuario_id'=>22,'rol_id'=>3,'permisos'=>['whatsapp.ver','whatsapp.enviar','whatsapp.gestionar_conversaciones']];
 denyQueue(fn()=>$service->asignar(2,22),403);denyQueue(fn()=>$service->pendientes(),403);
 $scope=WhatsAppContextoService::alcance($db,22,false);
 okQueue((int)$db->query('SELECT COUNT(*) FROM whatsapp_conversaciones conv WHERE conv.id=1 AND ('.$scope.')')->fetch_row()[0]===1,'Asignado sin acceso a su chat');
 $_SESSION['usuario_id']=23;$scope=WhatsAppContextoService::alcance($db,23,false);
 okQueue((int)$db->query('SELECT COUNT(*) FROM whatsapp_conversaciones conv WHERE conv.id=1 AND ('.$scope.')')->fetch_row()[0]===0,'Otro asesor accede');
 adminQueue();
 $db->query("INSERT INTO prospectos_comerciales VALUES(100,'+525511111111'),(101,'+525522222222'),(102,'+525522222222')");
 $db->query('INSERT INTO prospecto_asignaciones VALUES(1,100,22,1),(2,101,22,1),(3,102,23,1)');
 $source=$db->query('SELECT * FROM prospecto_asignaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC);
 $db->query("UPDATE whatsapp_conversaciones SET telefono_normalizado='525511111111' WHERE id=10");
 denyQueue(fn()=>$service->asignar(10,23),409);
 okQueue($service->enrutarEntrada(2,'5215511111111')['estado']==='ASIGNADA','No reconoce prospecto con formato 521');
 okQueue((int)$db->query('SELECT prospecto_id FROM whatsapp_conversacion_prospectos WHERE conversacion_id=2')->fetch_row()[0]===100,'Enlace comercial incorrecto');
 okQueue($service->enrutarEntrada(2,'5215511111111')['estado']==='CONSERVADA','Se reemplaza enlace existente');
 denyQueue(fn()=>$service->asignar(2,23),409);
 okQueue($service->enrutarEntrada(3,'525522222222')['estado']==='REVISAR','La ambigüedad selecciona responsable');
 okQueue($service->enrutarEntrada(4,'525599999999')['estado']==='SIN_ASIGNAR','Desconocido asignado automáticamente');
 $db->query("INSERT INTO seguimientos_vinculacion VALUES(10,1,11,40,'(55) 3333-3333','',''),(11,1,11,40,'55.4444.4444','',''),(12,1,11,40,'+52 55 2222 2222','','')");
 okQueue($service->enrutarEntrada(5,'525533333333')['estado']==='ASIGNADA','No reconoce institución o territorio vigente');
 okQueue((int)$db->query('SELECT aliado_seguimiento_id FROM whatsapp_conversaciones WHERE id=5')->fetch_row()[0]===10,'Institución incorrecta');
 $db->query('UPDATE asignaciones_territorio SET activo=0');
 okQueue($service->enrutarEntrada(6,'525544444444')['estado']==='REVISAR','Territorio inactivo recibe chats');
 $db->query('UPDATE asignaciones_territorio SET activo=1');
 okQueue($service->enrutarEntrada(7,'525522222222')['estado']==='REVISAR','Coincidencia entre institución y prospecto se adivina');
 okQueue($service->enrutarEntrada(1,'525533333333')['estado']==='CONSERVADA','Se reemplaza asignación manual');
 okQueue($source===$db->query('SELECT * FROM prospecto_asignaciones ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Se modificó la cartera comercial');
 $db->query('UPDATE whatsapp_conversaciones SET cuenta_id=2 WHERE id=8');denyQueue(fn()=>$service->asignar(8,22),404);
 $db->query('RENAME TABLE whatsapp_asignaciones_historial TO historial_no_disponible');
 try {$service->asignar(9,22);throw new RuntimeException('Se asignó sin auditoría');}catch(mysqli_sql_exception $expected){}
 okQueue($db->query('SELECT responsable_usuario_id FROM whatsapp_conversaciones WHERE id=9')->fetch_row()[0]===null,'El fallo de auditoría no revirtió la asignación');
 if(getenv('IMPE_WA_PUBLISH_TEST')==='YES'){
  $process=proc_open([getenv('PYTHON_BINARY')?:'python3',__DIR__.'/publicar_whatsapp_asignacion_test.py',$schema],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
  $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
  okQueue(proc_close($process)===0,'Publicación:'.$out.$err);echo $out;
 }
 echo "PASS: bandeja, paginación, búsqueda, permisos, asignación, auditoría, idempotencia, reconocimiento, ambigüedad y conservación de cartera.\n";
} finally {$db->query('DROP DATABASE '.$schema);$db->close();}
