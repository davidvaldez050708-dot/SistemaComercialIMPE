<?php
// Fixtures efímeras; nunca carga conexiones privadas ni llama al proveedor real.
require __DIR__.'/ventas_actividad_db.php';
require __DIR__.'/../app/services/SupervisionLlamadasRepository.php';
require __DIR__.'/../app/services/SupervisionLlamadasAudioService.php';
require __DIR__.'/../app/services/SupervisionLlamadasPermisosService.php';
require __DIR__.'/../app/controllers/SupervisionLlamadasController.php';
$db->query("INSERT INTO roles(id,nombre) VALUES(4,'Analista de Datos'),(7,'Sin acceso')");
$db->query("INSERT INTO usuarios(id,nombre,apellidos,rol_id,estado) VALUES(10,'Admin','Prueba',1,1),(20,'Director','Prueba',2,1),(401,'Analista','Propio',4,1),(402,'Analista','Ajeno',4,1),(700,'Sin','Acceso',7,1)");
$db->query("INSERT INTO asignaciones_territorio VALUES(21,401,1,'ANALISTA_DATOS',1,1,NULL,NULL),(22,402,1,'ANALISTA_DATOS',2,1,NULL,NULL)");
$db->query('ALTER TABLE telefonia_transferencia_operaciones ADD origen_extension VARCHAR(12), ADD sdk_consulta VARCHAR(100), ADD estado VARCHAR(30), ADD modo VARCHAR(20), ADD entregada_at DATETIME NULL, ADD destino_atendida DATETIME NULL');
$db->query('ALTER TABLE telefonia_disponibilidad ADD origen_extension VARCHAR(12), ADD estado VARCHAR(30)');
$db->query('CREATE TABLE interacciones_vinculacion(id INT PRIMARY KEY,usuario_id INT,seguimiento_id INT,canal VARCHAR(30),id_externo VARCHAR(100),telefono_destino VARCHAR(40),fecha_inicio DATETIME,fecha_fin DATETIME NULL,duracion_segundos INT NULL,resultado VARCHAR(80),notas TEXT)');
$db->query('CREATE TABLE telefonia_analista_entrantes(id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),estado VARCHAR(40),seguimiento_id INT NULL,interaccion_id INT NULL,observacion TEXT,created_at DATETIME)');
$db->query('CREATE TABLE telefonia_analista_webrtc_sesiones(id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),session_id VARCHAR(100),numero_origen VARCHAR(40),fecha_inicio DATETIME,fecha_fin DATETIME NULL,duracion_observada_segundos INT NULL,clasificacion VARCHAR(40),estado VARCHAR(30),seguimiento_id INT NULL,interaccion_id INT NULL,observacion TEXT)');
$db->query('CREATE TABLE telefonia_webrtc_grabaciones_internas(id INT PRIMARY KEY,usuario_id INT,session_id VARCHAR(100),pbx_call_id VARCHAR(100),call_id_with_rec VARCHAR(100),extension_origen VARCHAR(12),extension_destino VARCHAR(12))');
$ref='out_'.str_repeat('a',40);$other='out_'.str_repeat('b',40);$transfer='in_'.str_repeat('c',40);
$db->query("INSERT INTO interacciones_vinculacion VALUES(1,401,1,'LLAMADA_IP','$ref','5551000000','$today 09:00:00','$today 09:00:13',12,'CONTACTADO','<script>hostil</script>'),(2,402,2,'LLAMADA_IP','$other','5552000000','$today 09:00:00',NULL,NULL,'NO_CONTESTO','Ajeno'),(3,401,1,'CORREO','not-a-call','','$today 10:00:00',NULL,NULL,'CORREO_ENVIADO','')");
ev($db,$ref,'NOTIFY_OUT_START','101',"$today 09:00:00");ev($db,$ref,'NOTIFY_ANSWER','101',"$today 09:00:01");ev($db,$ref,'NOTIFY_OUT_END','101',"$today 09:00:13",12,['duration'=>12]);
$db->query("INSERT INTO telefonia_analista_entrantes VALUES(1,401,'101','$ref','INSTITUCION',1,1,'','$today 09:00:00'),(2,401,'101','$transfer','SIN_IDENTIFICAR',NULL,NULL,'','$today 11:00:00')");
$sdk='sdk-11111111-1111-1111';
$db->query("INSERT INTO telefonia_analista_webrtc_sesiones VALUES(1,401,'101','$sdk','100','$today 11:00:00','$today 11:00:14',13,'SIN_IDENTIFICAR','ended',NULL,NULL,''),(2,401,'101','sdk-22222222-2222-2222','100','$today 12:00:00','$today 12:00:06',5,'INTERNA','ended',NULL,NULL,'')");
$db->query("INSERT INTO telefonia_webrtc_grabaciones_internas VALUES(1,401,'sdk-22222222-2222-2222','internal-group','internal-record','100','101')");
$route=json_encode(['tipo'=>'INSTITUCION','responsable_id'=>401,'extension'=>'101','referencia_llamada'=>$transfer]);
$s=$db->prepare("INSERT INTO telefonia_disponibilidad(id,origen_usuario,destino_usuario,origen_extension,destino_extension,referencia,ruta_json,estado) VALUES(44,700,401,'100','101',?,?,'TRANSFERIDA')");$s->bind_param('ss',$transfer,$route);$s->execute();
$db->query("INSERT INTO telefonia_transferencia_operaciones(id,origen_usuario,destino_usuario,origen_extension,destino_extension,referencia,sdk_consulta,estado,modo,entregada_at,destino_atendida) VALUES(44,700,401,'100','101','$transfer','$sdk','ENTREGADA','DIRECTA',NOW(),NOW())");
ev($db,$transfer,'NOTIFY_INTERNAL','100',"$today 10:59:00");ev($db,$transfer,'NOTIFY_ANSWER','100',"$today 10:59:01");ev($db,$transfer,'NOTIFY_INTERNAL','101',"$today 11:00:00",0,['transfer_from'=>'100']);ev($db,$transfer,'NOTIFY_ANSWER','101',"$today 11:00:01",0,['transfer_from'=>'100']);
$salesRoute=json_encode(['tipo'=>'PROSPECTO','responsable_id'=>301,'extension'=>'150','referencia_llamada'=>'report-call-transfer']);
$s=$db->prepare("UPDATE telefonia_disponibilidad SET referencia='report-call-transfer',origen_extension='100',estado='TRANSFERIDA',ruta_json=? WHERE id=7");$s->bind_param('s',$salesRoute);$s->execute();
$db->query("INSERT INTO telefonia_transferencia_operaciones(id,origen_usuario,destino_usuario,origen_extension,destino_extension,referencia,estado,modo,entregada_at,destino_atendida) VALUES(45,700,301,'100','150','report-call-transfer','ENTREGADA','DIRECTA',NOW(),NOW())");
ev($db,'report-call-transfer','NOTIFY_INTERNAL','100',"$today 10:59:00");
foreach([$ref=>['analyst-record'], $transfer=>['reception-record','analyst-transfer-record'],'report-call-direct'=>['sales-record'],'report-call-transfer'=>['sales-reception-record','sales-transfer-record']] as $pbx=>$ids)foreach($ids as $id){
    $s=$db->prepare("INSERT INTO telefonia_zadarma_eventos(pbx_call_id,evento,internal,call_id_with_rec,is_recorded,received_at,payload_json) VALUES(?,'NOTIFY_RECORD','',?,'true',NOW(),'{}')");$s->bind_param('ss',$pbx,$id);$s->execute();
}
$installer=new SupervisionLlamadasPermisosService($db);$installer->verificarEstructura();$backup=[];
try{$installer->aplicar(function(){throw new RuntimeException('Respaldo fallido');});throw new LogicException('Instaló sin respaldo');}catch(RuntimeException $e){qcheck($e->getMessage()==='Respaldo fallido','Error de respaldo');}
qcheck(!$installer->comprobar(),'No conceder permisos sin respaldo');
qcheck($installer->aplicar(function($s)use(&$backup){$backup=$s;}),'Instalar supervisión');
$installer->restaurar($backup);qcheck(!$installer->comprobar(),'Restauración de permisos');$installer->aplicar(function(){});
qcheck(!$installer->aplicar(function(){throw new RuntimeException('Reinstalación');}),'Idempotencia');
$repo=new SupervisionLlamadasRepository($db);$f=SupervisionLlamadasController::filtros(['area'=>'analistas','desde'=>$today,'hasta'=>$today]);
qcheck(count($repo->personas(20,'analistas'))===2,'Dirección ve analistas nacionales');
qcheck(count($repo->personas(20,'asesores'))===3,'Dirección ve asesores incluyendo inactivos');
qcheck(count($repo->personas(601,'analistas'))===1,'Cuenta Clave sólo su analista');
qcheck(count($repo->personas(601,'asesores'))===2,'Cuenta Clave equipo explícito de asesores');
foreach([301,401,700] as $uid){try{$repo->acceso($uid);throw new LogicException('Rol sin supervisión accedió');}catch(DomainException $e){}}
$db->query("INSERT INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo LIKE 'llamadas.supervision.%'");
try{$repo->acceso(301);throw new LogicException('Asesor amplió rol por permiso');}catch(DomainException $e){}
$db->query('DELETE FROM rol_permisos WHERE rol_id=3 AND permiso_id IN (SELECT id FROM permisos WHERE codigo LIKE \'llamadas.supervision.%\')');
foreach(['interaccion:2','interaccion:3','venta:4','sesion:999'] as $key){try{$repo->obtener(601,$key);throw new LogicException('Leyó llamada ajena/no telefónica '.$key);}catch(DomainException $e){}}
$data=$repo->listado(601,$f);qcheck($data['total']===3,'Deduplicar interacción/clasificación y alias exacto de sesión');
qcheck($repo->listado(20,$f)['total']===4,'Dirección consulta también analista ajeno');
$snapshot=[];foreach($db->query('SHOW TABLES')->fetch_all() as [$table])$snapshot[$table]=$db->query("SELECT * FROM `$table`")->fetch_all(MYSQLI_ASSOC);
$provider=new class {
    public array $ids=[];
    public function obtenerGrabacionPorCallId(string $id):array{$this->ids[]=$id;return ['link'=>'https://audio.invalid/'.$id];}
    public function estadisticasPbx(string $a,string $b):array{return [];}
};
$audio=new SupervisionLlamadasAudioService($db);
$out=$audio->resolver(601,'interaccion:1',$provider,true);qcheck($out['segmentos']===1&&$provider->ids===['analyst-record'],'Identidad exacta, nunca audio global');
$provider->ids=[];$out=$audio->resolver(601,'entrante:2',$provider,true);qcheck($out['segmentos']===2&&$provider->ids===['reception-record','analyst-transfer-record'],'Ambos tramos de analista transferido');
$provider->ids=[];$audio->resolver(601,'sesion:2',$provider,true);qcheck($provider->ids===['internal-record'],'Relación interna exacta');
$provider->ids=[];$audio->resolver(601,'venta:1',$provider,true);qcheck($provider->ids===['sales-record'],'Audio de asesor');
$provider->ids=[];$out=$audio->resolver(601,'venta:2',$provider,true);qcheck($out['segmentos']===2&&$provider->ids===['sales-reception-record','sales-transfer-record'],'Ambos tramos de asesor transferido');
$response=SupervisionLlamadasAudioService::respuesta(['body'=>'0123456789','content_type'=>'audio/mpeg'],'bytes=2-4');qcheck($response['status']===206&&$response['body']==='234','Range parcial');
qcheck(SupervisionLlamadasAudioService::respuesta(['body'=>'0123456789','content_type'=>'audio/mpeg'],'bytes=99-')['status']===416,'Range inválido');
try{SupervisionLlamadasAudioService::respuesta(['body'=>'<html>error</html>','content_type'=>'text/html']);throw new LogicException('Aceptó HTML');}catch(RuntimeException $e){}
$db->query("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=6 AND p.codigo='llamadas.supervision.audio'");
try{$audio->resolver(601,'venta:1',$provider);throw new LogicException('Audio revocado funcionó');}catch(DomainException $e){}
qcheck($repo->obtener(601,'venta:1')['persona']!=='','Revocar audio conserva consulta');
qcheck(!$installer->aplicar(function(){}),'Reinstalar respeta revocación');
$db->query("INSERT INTO rol_permisos SELECT 6,id FROM permisos WHERE codigo='llamadas.supervision.audio'");
$db->query("UPDATE asignaciones_territorio SET fecha_fin='2000-01-01' WHERE id=1");
try{$audio->resolver(601,'interaccion:1',$provider);throw new LogicException('Equipo vencido accedió');}catch(DomainException $e){}
$db->query('UPDATE asignaciones_territorio SET fecha_fin=NULL WHERE id=1');
foreach($snapshot as $t=>$rows)qcheck($db->query("SELECT * FROM `$t`")->fetch_all(MYSQLI_ASSOC)===$rows,'Consulta modificó '.$t);
echo "Supervisión SQL real: ambos roles supervisados, alcance nacional/equipo, alias exactos, audio, revocaciones y sólo lectura OK\n";
