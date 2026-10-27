<?php
// Base efímera exclusiva de CI: nunca consulta la configuración privada del CRM.
require __DIR__.'/../app/services/VentasActividadService.php';
require __DIR__.'/../app/services/VentasActividadPermisosService.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$port=(int)getenv('IMPE_TEST_MYSQL_PORT');if (!$port) throw new RuntimeException('Indica el puerto del servicio MySQL de pruebas.');
$db=new mysqli('127.0.0.1','root','',null,$port);$db->set_charset('utf8mb4');
$name=getenv('IMPE_VENTAS_TEST_DB');if (!preg_match('/^impe_ventas_reportes_test_[0-9a-f]{8}$/D',$name)) throw new RuntimeException('Nombre de base de pruebas inválido.');
if (($argv[1]??'')==='--cleanup') { $db->query('DROP DATABASE IF EXISTS `'.$name.'`');exit; }
$db->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->select_db($name);
$db->query("SET time_zone='-06:00'");
function qcheck(bool $ok,string $m):void { if(!$ok) throw new RuntimeException($m); }
$schema=[
    'CREATE TABLE roles(id INT PRIMARY KEY,nombre VARCHAR(80),estado INT DEFAULT 1)',
    "INSERT INTO roles(id,nombre) VALUES(1,'Administrador'),(2,'Director de Operaciones'),(3,'Asesor de Ventas'),(6,'Cuenta Clave')",
    'CREATE TABLE usuarios(id INT PRIMARY KEY,nombre VARCHAR(80),apellidos VARCHAR(80),foto_perfil VARCHAR(255) DEFAULT NULL,rol_id INT,estado INT DEFAULT 1)',
    "INSERT INTO usuarios(id,nombre,apellidos,rol_id,estado) VALUES(301,'Asesor','Propio',3,1),(302,'Asesor','Ajeno',3,1),(303,'Asesor','Histórico',3,0),(601,'Cuenta','Uno',6,1),(602,'Cuenta','Dos',6,1)",
    'CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(80),estado INT)',
    "INSERT INTO estados VALUES(1,'Morelos',1),(2,'Baja California',1)",
    'CREATE TABLE asignaciones_territorio(id INT PRIMARY KEY,usuario_id INT,estado_id INT,tipo_asignacion VARCHAR(32),cuenta_clave_asignacion_id INT NULL,activo INT,fecha_inicio DATE NULL,fecha_fin DATE NULL)',
    "INSERT INTO asignaciones_territorio VALUES(1,601,1,'CUENTA_CLAVE',NULL,1,NULL,NULL),(2,602,1,'CUENTA_CLAVE',NULL,1,NULL,NULL),(3,301,1,'ASESOR',1,1,NULL,NULL),(4,302,1,'ASESOR',2,1,NULL,NULL),(5,303,1,'ASESOR',1,1,NULL,NULL)",
    'CREATE TABLE permisos(id INT AUTO_INCREMENT PRIMARY KEY,modulo VARCHAR(80),codigo VARCHAR(100) UNIQUE,nombre VARCHAR(180),descripcion TEXT,estado INT DEFAULT 1)',
    'CREATE TABLE rol_permisos(rol_id INT,permiso_id INT,PRIMARY KEY(rol_id,permiso_id))',
    'CREATE TABLE telefonia_ventas_llamadas(id INT AUTO_INCREMENT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),direccion VARCHAR(20),numero VARCHAR(32),nombre VARCHAR(180) DEFAULT \'\',origen_extension VARCHAR(12) DEFAULT \'\',solicitud_id INT NULL,estado_id INT NULL,fecha DATETIME,UNIQUE KEY ident(usuario_id,extension,pbx_call_id))',
    'CREATE TABLE telefonia_ventas_resultados(id INT AUTO_INCREMENT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),resultado VARCHAR(64),observacion TEXT,prospecto_nombre VARCHAR(180),contacto_id INT NULL,proxima_accion_at DATETIME NULL,recordatorio_atendido_at DATETIME NULL)',
    'CREATE TABLE telefonia_zadarma_eventos(id INT AUTO_INCREMENT PRIMARY KEY,pbx_call_id VARCHAR(100),evento VARCHAR(40),internal VARCHAR(12),disposition VARCHAR(30) NULL,duration INT DEFAULT 0,call_id_with_rec VARCHAR(100) NULL,is_recorded VARCHAR(10) NULL,received_at DATETIME,payload_json LONGTEXT)',
    'CREATE TABLE telefonia_ventas_evidencia(usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),datos_json LONGTEXT)',
    'CREATE TABLE telefonia_ventas_marcaciones(id INT AUTO_INCREMENT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),destino VARCHAR(32),pbx_call_id VARCHAR(100) NULL,created_at DATETIME)',
    'CREATE TABLE telefonia_disponibilidad(id INT PRIMARY KEY,destino_usuario INT,destino_extension VARCHAR(12),referencia VARCHAR(100),ruta_json LONGTEXT,devolucion VARCHAR(20),origen_usuario INT DEFAULT 700)',
    'CREATE TABLE telefonia_transferencia_operaciones(id INT PRIMARY KEY,origen_usuario INT,destino_usuario INT,destino_extension VARCHAR(12),referencia VARCHAR(100),numero VARCHAR(32),created_at DATETIME)',
    'CREATE TABLE telefonia_contactos_personales(id INT PRIMARY KEY,usuario_id INT,nombre VARCHAR(180),telefono VARCHAR(32),observacion TEXT,proxima_accion_at DATETIME NULL)',
];
foreach($schema as $sql) $db->query($sql);
foreach(array_merge(VentasActividadPermisosService::COMUNES,['desempeno.ver_propio','desempeno.ver_equipo','desempeno.ver_global']) as $code) {
    $s=$db->prepare("INSERT INTO permisos(modulo,codigo,nombre,descripcion) VALUES('Prueba',?,?,?)");$s->bind_param('sss',$code,$code,$code);$s->execute();
}
$installer=new VentasActividadPermisosService($db);$backup=[];
qcheck(!$installer->comprobar(),'Catálogo inicialmente ausente');
try{$installer->aplicar(function(){throw new RuntimeException('Respaldo fallido de prueba');});throw new LogicException('Instaló sin respaldo');}catch(RuntimeException $e){qcheck($e->getMessage()==='Respaldo fallido de prueba','Motivo de fallo');}
qcheck(!$installer->comprobar(),'Fallo de respaldo no instala parcialmente');
qcheck($installer->aplicar(function($d)use(&$backup){$backup=$d;}),'Instalación inicial');qcheck(count($backup['catalogo_nuevo'])===4,'Respaldo');
$db->query("DELETE r FROM rol_permisos r JOIN permisos p ON p.id=r.permiso_id WHERE r.rol_id=3 AND p.codigo='reportes.ventas.propio'");
qcheck(!$installer->aplicar(function(){throw new RuntimeException('No debe reescribir respaldo');}),'Instalación idempotente');
qcheck((int)$db->query("SELECT COUNT(*) n FROM rol_permisos r JOIN permisos p ON p.id=r.permiso_id WHERE r.rol_id=3 AND p.codigo='reportes.ventas.propio'")->fetch_assoc()['n']===0,'Reinstalar respeta revocación');
$db->query("INSERT INTO rol_permisos SELECT 7,id FROM permisos WHERE codigo='reportes.ventas.global'");
try{$installer->restaurar($backup);throw new LogicException('Restauró sobre asignación posterior');}catch(RuntimeException $e){qcheck(str_contains($e->getMessage(),'posteriores'),'Bloquear cambios posteriores');}
$db->query('DELETE FROM rol_permisos WHERE rol_id=7');
$installer->restaurar($backup);qcheck(!$installer->comprobar(),'Restaurar catálogo');
qcheck((int)$db->query('SELECT COUNT(*) n FROM rol_permisos')->fetch_assoc()['n']===0,'Restaurar enlaces anteriores');
$installer->aplicar(function(){});$installer->verificarEstructura();
$today=(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
function callrow(mysqli $db,int $uid,string $ref,string $direction,string $date,string $origin='',?int $request=null):void {
    $s=$db->prepare("INSERT INTO telefonia_ventas_llamadas(usuario_id,extension,pbx_call_id,direccion,numero,fecha,origen_extension,solicitud_id) VALUES(?,'150',?,?,'5550000000',?,?,?)");
    $s->bind_param('issssi',$uid,$ref,$direction,$date,$origin,$request);$s->execute();
}
function ev(mysqli $db,string $ref,string $type,string $ext,string $date,int $secs=0,array $payload=[]):void {
    $json=json_encode($payload);$disp=$type==='NOTIFY_END'?'answered':'';
    $s=$db->prepare('INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,received_at,duration,disposition,payload_json) VALUES(?,?,?,?,?,?,?)');
    $s->bind_param('ssssiss',$ref,$type,$ext,$date,$secs,$disp,$json);$s->execute();
}
callrow($db,301,'report-call-direct','Saliente',$today.' 10:00:00');
ev($db,'report-call-direct','NOTIFY_ANSWER','150',$today.' 10:00:01');ev($db,'report-call-direct','NOTIFY_END','150',$today.' 10:00:14',12,['duration'=>12]);
// Un evento repetido no duplica la llamada.
ev($db,'report-call-direct','NOTIFY_END','150',$today.' 10:00:14',12,['duration'=>12]);
callrow($db,301,'report-call-transfer','Entrante',$today.' 11:00:00','100',7);
ev($db,'report-call-transfer','NOTIFY_START','',$today.' 10:59:00');ev($db,'report-call-transfer','NOTIFY_ANSWER','100',$today.' 10:59:01');
ev($db,'report-call-transfer','NOTIFY_INTERNAL','150',$today.' 11:00:00',0,['transfer_from'=>'100']);ev($db,'report-call-transfer','NOTIFY_ANSWER','150',$today.' 11:00:01',0,['transfer_from'=>'100']);
ev($db,'report-call-transfer','NOTIFY_END','100',$today.' 11:00:20',44,['duration'=>44,'last_internal'=>'150']);
$cache=json_encode(['segundos'=>13,'tramos'=>[]]);$s=$db->prepare("INSERT INTO telefonia_ventas_evidencia VALUES(301,'150','report-call-transfer',?)");$s->bind_param('s',$cache);$s->execute();
callrow($db,301,'report-call-pending','Entrante',$today.' 12:00:00','100',8);ev($db,'report-call-pending','NOTIFY_ANSWER','150',$today.' 12:00:01');
callrow($db,302,'report-call-foreign','Saliente',$today.' 10:00:00');ev($db,'report-call-foreign','NOTIFY_END','150',$today.' 10:00:14',99,['duration'=>99]);
callrow($db,303,'report-call-inactive','Saliente',$today.' 10:00:00');
callrow($db,301,'report-call-historical','Saliente','2024-01-03 10:00:00');ev($db,'report-call-historical','NOTIFY_END','150','2024-01-03 10:00:14',20,['duration'=>20]);
foreach(['report-call-direct'=>'INTERESADO','report-call-transfer'=>'CONVERSACION_PERSONA'] as $ref=>$result) {
    $s=$db->prepare("INSERT INTO telefonia_ventas_resultados(usuario_id,extension,pbx_call_id,resultado,observacion,prospecto_nombre) VALUES(301,'150',?,?,'Observación de prueba','Persona de prueba')");$s->bind_param('ss',$ref,$result);$s->execute();
}
$db->query("INSERT INTO telefonia_ventas_marcaciones(usuario_id,extension,destino,created_at) VALUES(301,'150','5550000000','$today 13:00:00')");
$db->query("INSERT INTO telefonia_contactos_personales VALUES(1,301,'Contacto','5550000000','Nota','$today 01:00:00')");
$route=json_encode(['tipo'=>'PROSPECTO','responsable_id'=>301,'extension'=>'150','referencia_llamada'=>'callback-ref-001','devolucion_solicitada_at'=>gmdate('Y-m-d H:i:s',time()-2*86400)]);
$s=$db->prepare("INSERT INTO telefonia_disponibilidad(id,destino_usuario,destino_extension,referencia,ruta_json,devolucion) VALUES(7,301,'150','callback-ref-001',?,'PENDIENTE')");$s->bind_param('s',$route);$s->execute();
$route=json_encode(['tipo'=>'PROSPECTO','responsable_id'=>301,'extension'=>'150','referencia_llamada'=>'unconfirmed-transfer-001']);
$s=$db->prepare("INSERT INTO telefonia_disponibilidad(id,destino_usuario,destino_extension,referencia,ruta_json,devolucion) VALUES(11,301,'150','unconfirmed-transfer-001',?,'')");$s->bind_param('s',$route);$s->execute();
$db->query("INSERT INTO telefonia_transferencia_operaciones VALUES(11,700,301,'150','unconfirmed-transfer-001','5550000000','$today 08:00:00')");
$yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
callrow($db,301,'report-call-late','Saliente',$yesterday.' 23:59:00');ev($db,'report-call-late','NOTIFY_END','150',$today.' 00:00:15',9,['duration'=>9]);
function sessionFor(mysqli $db,int $role,int $uid):void {
    $perms=[];$s=$db->query('SELECT p.codigo FROM rol_permisos r JOIN permisos p ON p.id=r.permiso_id WHERE r.rol_id='.(int)$role.' AND p.estado=1');
    while($p=$s->fetch_assoc())$perms[]=$p['codigo'];
    $_SESSION=['usuario_id'=>$uid,'rol_id'=>$role,'rol'=>'Prueba','nombre'=>'Cuenta','apellidos'=>'de prueba','permisos'=>$perms];
}
$snap=[];foreach(['telefonia_ventas_llamadas','telefonia_zadarma_eventos','telefonia_ventas_resultados','telefonia_ventas_evidencia','telefonia_ventas_marcaciones','telefonia_disponibilidad','telefonia_transferencia_operaciones'] as $t)$snap[$t]=$db->query("SELECT * FROM $t")->fetch_all(MYSQLI_ASSOC);
sessionFor($db,3,301);$svc=new VentasActividadService($db);$f=['periodo'=>'hoy'];$data=$svc->preparar($f,false);$s=$data['resumen'];
qcheck($s['llamadas']===3 && $s['intentos']===1 && $s['conversaciones']===2,'Resumen personal exacto');
qcheck($s['transferencias_pendientes']===1,'Transferencia sin confirmar separada de llamadas');
qcheck($s['segundos']===25 && $s['promedio_segundos']===13 && $s['duraciones_pendientes']===1,'Duración sólo asesor, evidencia persistida y pendiente');
qcheck($data['organizacion']['programadas']===1 && $data['organizacion']['devoluciones']===1,'Organización actual');
qcheck($data['organizacion']['devoluciones_vencidas']===1 && $data['organizacion']['personas'][301]['devoluciones_vencidas']===1,'Vencimientos exactos por asesor');
qcheck($svc->preparar(['periodo'=>'ayer'],false)['resumen']['segundos']===9,'Evento tardío conserva la fecha de la llamada');
try{$svc->preparar($f+['persona_id'=>302],false);throw new LogicException('Consultó asesor ajeno');}catch(DomainException $e){}
qcheck($svc->preparar(['periodo'=>'personalizado','fecha_desde'=>'2024-01-01','fecha_hasta'=>'2024-01-31'],false)['resumen']['llamadas']===1,'Consulta histórica fuera de 90 días sin alterar historial');
sessionFor($db,6,601);$team=$svc->preparar($f,false);$teamIds=array_map('intval',array_column($team['personas'],'id'));sort($teamIds);qcheck($teamIds===[301,303],'Equipo explícito');$pendingIds=array_keys($team['organizacion']['personas']);sort($pendingIds);qcheck($pendingIds===[301,303],'Pendientes sólo del equipo autorizado');
try{$svc->preparar($f+['persona_id'=>302],false);throw new LogicException('Consultó asesor ajeno del mismo estado');}catch(DomainException $e){}
$db->query('UPDATE asignaciones_territorio SET fecha_fin=\'2000-01-01\' WHERE id=1');qcheck(!$svc->preparar($f,false)['personas'],'Vencimiento bloquea equipo');$db->query('UPDATE asignaciones_territorio SET fecha_fin=NULL WHERE id=1');
sessionFor($db,2,20);qcheck(count($svc->preparar($f,true)['personas'])===3,'Director nacional, asesor inactivo con historial');
require __DIR__.'/../app/services/VentasDesempenoService.php';
$performance=new VentasDesempenoService($db);
$global=$performance->preparar($f);qcheck(!$global['territorios'] && $global['estado_id']===0 && count($global['ranking'])===3,'Desempeño nacional sin asignar Dirección');
qcheck($global['resumen']===$svc->preparar($f,true)['resumen'],'Cifras de Desempeño y Reportes iguales');
$db->query("INSERT INTO asignaciones_territorio VALUES(7,602,2,'CUENTA_CLAVE',NULL,1,NULL,NULL),(8,301,2,'ASESOR',7,1,NULL,NULL)");
$filtered=$performance->preparar($f+['estado_id'=>2]);qcheck(count($filtered['ranking'])===3 && $filtered['resumen']===$global['resumen'],'Territorio antiguo no restringe el ranking de asesores');
sessionFor($db,6,601);
$teamPerformance=$performance->preparar($f+['estado_id'=>1]);
qcheck(!$teamPerformance['territorios'] && count($teamPerformance['ranking'])===2,'Cuenta Clave: área y territorio de su equipo explícito');
qcheck(count($performance->preparar($f+['estado_id'=>2])['ranking'])===2,'Ignorar estado no amplía el equipo autorizado');
try{$performance->preparar($f+['persona_id'=>302]);throw new LogicException('Amplió equipo de desempeño');}catch(DomainException $e){}
sessionFor($db,3,301);qcheck($performance->preparar($f)['ranking'][0]['indice']===null,'Mi desempeño personal sin ranking');
try{$performance->preparar($f+['persona_id'=>302]);throw new LogicException('Consultó desempeño personal ajeno');}catch(DomainException $e){}
$db->query('DELETE FROM asignaciones_territorio WHERE id IN (7,8)');
$_SESSION['permisos']=[];try{$svc->preparar($f,true);throw new LogicException('Acceso revocado permitido');}catch(DomainException $e){}
foreach($snap as $t=>$rows)qcheck($db->query("SELECT * FROM $t")->fetch_all(MYSQLI_ASSOC)===$rows,'Consulta modificó '.$t);
echo "DB real: permisos/revocación, propietario histórico, alcance territorial explícito, fechas, duración propia y consultas sin escrituras OK\n";
