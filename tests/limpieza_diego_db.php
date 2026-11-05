<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$name=getenv('IMPE_DIEGO_TEST_DB');
if(!$name||!preg_match('/^impe_diego_test_[a-f0-9]{8}$/D',$name))throw new RuntimeException('Sólo base aislada de pruebas.');
$db=new mysqli('127.0.0.1','root','',null,(int)(getenv('IMPE_DIEGO_TEST_PORT')?:33339));
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");$db->select_db($name);$db->set_charset('utf8mb4');
require __DIR__.'/../tools/limpiar_pruebas_diego.php';
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
function rows(mysqli $db,string $t):array{return $db->query("SELECT * FROM `$t` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);}
function fails(callable $f,string $contains):void{try{$f();}catch(Throwable $e){ok(str_contains($e->getMessage(),$contains),$e->getMessage());return;}throw new RuntimeException('Debió bloquear: '.$contains);}
try {
 $ddl=[
 'usuarios (id INT PRIMARY KEY,usuario VARCHAR(80),rol_id INT,estado INT)',
 'telefonia_extensiones (id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),activo INT)',
 'seguimientos_vinculacion (id INT PRIMARY KEY,analista_id INT,estado_id INT,clave_origen VARCHAR(100),observaciones TEXT,ultima_interaccion_at DATETIME NULL)',
 'interacciones_vinculacion (id INT PRIMARY KEY,seguimiento_id INT,usuario_id INT,canal VARCHAR(30),fecha_inicio DATETIME,notas TEXT,id_externo VARCHAR(100),FOREIGN KEY(seguimiento_id) REFERENCES seguimientos_vinculacion(id))',
 'seguimientos_vinculacion_post_envio (seguimiento_id INT PRIMARY KEY,convenio_carta_pdf VARCHAR(200),FOREIGN KEY(seguimiento_id) REFERENCES seguimientos_vinculacion(id))',
 'seguimientos_vinculacion_correos (id INT PRIMARY KEY,seguimiento_id INT,interaccion_id INT,usuario_id INT,FOREIGN KEY(seguimiento_id) REFERENCES seguimientos_vinculacion(id))',
 'formulario_aliados (id INT PRIMARY KEY,seguimiento_id INT NULL,token VARCHAR(50),FOREIGN KEY(seguimiento_id) REFERENCES seguimientos_vinculacion(id))',
 'formulario_aliado_registro (aliado_id INT PRIMARY KEY,seguimiento_id INT NULL,FOREIGN KEY(seguimiento_id) REFERENCES seguimientos_vinculacion(id))',
 'formulario_aliado_procedencias (id INT PRIMARY KEY,aliado_id INT,FOREIGN KEY(aliado_id) REFERENCES formulario_aliados(id))',
 'telefonia_analista_entrantes (id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),seguimiento_id INT NULL,interaccion_id INT NULL)',
 'telefonia_analista_webrtc_sesiones (id INT PRIMARY KEY,usuario_id INT,session_id VARCHAR(100),fecha_fin DATETIME NULL,created_at DATETIME,seguimiento_id INT NULL,interaccion_id INT NULL)',
 'telefonia_verificacion_evidencias (id INT PRIMARY KEY,usuario_id INT,seguimiento_id INT,interaccion_id INT,pbx_call_id VARCHAR(100))',
 'telefonia_borradores (usuario_id INT,referencia VARCHAR(100),datos_json TEXT,PRIMARY KEY(usuario_id,referencia))',
 'telefonia_notificaciones (id INT PRIMARY KEY,usuario_id INT,llave VARCHAR(100))',
 'notificaciones_convocatorias (id INT PRIMARY KEY,usuario_id INT,titulo VARCHAR(100))',
 'observaciones_seguimiento (id INT PRIMARY KEY,seguimiento_id INT,destinatario_id INT,leida INT,leida_at DATETIME NULL)',
 'seguimientos_vinculacion_cambios_datos (id INT PRIMARY KEY,seguimiento_id INT,destinatario_id INT,leida_at DATETIME NULL,leida_por INT NULL)',
 'recordatorios_vinculacion (id INT PRIMARY KEY,seguimiento_id INT,usuario_id INT,proxima_accion_at DATETIME,aviso_3h_at DATETIME NULL,aviso_1h_at DATETIME NULL,aviso_10m_at DATETIME NULL,aviso_vencida_at DATETIME NULL)',
 'telefonia_zadarma_eventos (id INT PRIMARY KEY,pbx_call_id VARCHAR(100),internal VARCHAR(12),evento VARCHAR(40),received_at DATETIME)',
 'telefonia_crm_exclusiones (pbx_call_id VARCHAR(100),extension VARCHAR(12),limpieza_id CHAR(32),PRIMARY KEY(pbx_call_id,extension))',
 'telefonia_crm_limpiezas (id CHAR(32) PRIMARY KEY,backup_sha CHAR(64),estado VARCHAR(12),created_at DATETIME)'
 ];
 foreach($ddl as $d)$db->query('CREATE TABLE '.$d.' ENGINE=InnoDB');
 $db->query("INSERT INTO usuarios VALUES(5,'diego.bahena',4,1),(6,'otro',4,1)");
 $db->query("INSERT INTO telefonia_extensiones VALUES(1,5,'101',1),(2,6,'102',1)");
 foreach([1351,1353,1354,1356,1357,1358,1359,1360,1361] as $id)$db->query("INSERT INTO seguimientos_vinculacion VALUES($id,5,7,'MANUAL:$id','prueba',NULL)");
 for($i=1;$i<=1303;$i++)$db->query("INSERT INTO seguimientos_vinculacion VALUES($i,5,".($i===1?7:2).",'XLSX:$i','Migrado desde Excel',NULL)");
 $db->query("INSERT INTO seguimientos_vinculacion VALUES(2000,6,7,'MANUAL:otro','otro usuario',NULL)");
 $db->query("INSERT INTO interacciones_vinculacion VALUES(1,1351,5,'LLAMADA_IP','2026-10-01','[VERIFICACION_EFECTIVA]','ref1'),(2,1351,5,'SISTEMA','2026-10-01','prueba',''),(3,1,5,'SISTEMA','2026-10-01','Historial importado',''),(4,2000,6,'LLAMADA_IP','2026-10-01','[VERIFICACION_EFECTIVA]','ref2')");
 $db->query("INSERT INTO seguimientos_vinculacion_post_envio VALUES(1351,'archivo-que-se-conserva.pdf')");
 $db->query("INSERT INTO seguimientos_vinculacion_correos VALUES(1,1351,2,5)");
 $db->query("INSERT INTO formulario_aliados VALUES(1,1351,'liga-que-se-conserva')");
 $db->query("INSERT INTO formulario_aliado_registro VALUES(1,1351)");
 $db->query('INSERT INTO formulario_aliado_procedencias VALUES(1,1)');
 $db->query("INSERT INTO telefonia_analista_entrantes VALUES(1,5,'101','ref1',1351,1),(2,6,'102','ref2',2000,4)");
 $db->query("INSERT INTO telefonia_analista_webrtc_sesiones VALUES(1,5,'sdk1','2026-10-01','2026-10-01',1351,1)");
 $db->query("INSERT INTO telefonia_verificacion_evidencias VALUES(1,5,1351,1,'ref1')");
 $db->query("INSERT INTO telefonia_borradores VALUES(5,'ref1','{}'),(6,'ref2','{}')");
 $db->query("INSERT INTO telefonia_notificaciones VALUES(1,5,'llamada:ref1'),(2,6,'llamada:ref2')");
 $db->query("INSERT INTO notificaciones_convocatorias VALUES(1,5,'prueba'),(2,6,'otro')");
 $db->query("INSERT INTO observaciones_seguimiento VALUES(1,1,5,0,NULL),(2,1,6,0,NULL)");
 $db->query('INSERT INTO seguimientos_vinculacion_cambios_datos VALUES(1,1,5,NULL,NULL)');
 $db->query("INSERT INTO recordatorios_vinculacion VALUES(1,1351,5,'2026-10-01',NULL,NULL,NULL,NULL),(2,1,5,'2026-10-01',NULL,NULL,NULL,NULL),(3,2,5,'2099-01-01',NULL,NULL,NULL,NULL),(4,2000,6,'2026-10-01',NULL,NULL,NULL,NULL)");
 $db->query("INSERT INTO observaciones_seguimiento VALUES(3,1351,5,0,NULL)");
 $db->query("INSERT INTO telefonia_zadarma_eventos VALUES(1,'ref1','101','NOTIFY_INTERNAL','2026-10-01 10:00:00'),(2,'ref1','101','NOTIFY_END','2026-10-01 10:01:00'),(3,'ref1','100','NOTIFY_END','2026-10-01 10:02:00'),(4,'ref1','','NOTIFY_RECORD','2026-10-01 10:03:00'),(5,'ref2','102','NOTIFY_END','2026-10-01 10:03:00')");
 $snapshot=static function()use($db):array{$out=[];foreach($db->query('SHOW TABLES')->fetch_all() as $r)if(!in_array($r[0],['telefonia_crm_exclusiones','telefonia_crm_limpiezas'],true))$out[$r[0]]=rows($db,$r[0]);return $out;};
 $initial=$snapshot();$tool=new LimpiezaPruebasDiego($db);$plan=$tool->plan();ok($snapshot()===$initial,'plan debe ser sólo lectura');
 ok($plan['seguimientos_de_diego_conservados']===1303,'1303 expedientes protegidos');
 $db->query("UPDATE usuarios SET usuario='otra' WHERE id=5");fails(fn()=>$tool->plan(),'identidad');$db->query("UPDATE usuarios SET usuario='diego.bahena' WHERE id=5");
 $db->query("UPDATE seguimientos_vinculacion SET clave_origen='XLSX:protegido' WHERE id=1351");fails(fn()=>$tool->plan(),'autorizadas');$db->query("UPDATE seguimientos_vinculacion SET clave_origen='MANUAL:1351' WHERE id=1351");
 $db->query("CREATE TABLE dependencia_no_autorizada(id INT PRIMARY KEY,interaccion_id INT,FOREIGN KEY(interaccion_id) REFERENCES interacciones_vinculacion(id)) ENGINE=InnoDB");$db->query('INSERT INTO dependencia_no_autorizada VALUES(1,1)');fails(fn()=>$tool->plan(),'Dependencia externa');$db->query('DROP TABLE dependencia_no_autorizada');
 $id=str_repeat('a',32);$sha=str_repeat('b',64);
 $db->query("UPDATE telefonia_notificaciones SET llave='cambió' WHERE id=1");fails(fn()=>$tool->aplicar($plan,$id,$sha),'datos cambiaron');$db->query("UPDATE telefonia_notificaciones SET llave='llamada:ref1' WHERE id=1");
 $db->query("INSERT INTO telefonia_zadarma_eventos VALUES(6,'activa','101','NOTIFY_OUT_START',UTC_TIMESTAMP())");fails(fn()=>$tool->plan(),'reciente');$db->query('DELETE FROM telefonia_zadarma_eventos WHERE id=6');
 $plan=$tool->plan();$tool->aplicar($plan,$id,$sha);
 ok((int)$db->query('SELECT COUNT(*) n FROM seguimientos_vinculacion WHERE analista_id=5')->fetch_assoc()['n']===1303,'sólo nueve eliminados');
 ok((int)$db->query("SELECT COUNT(*) n FROM interacciones_vinculacion WHERE usuario_id=5 AND canal='LLAMADA_IP'")->fetch_assoc()['n']===0,'efectivas eliminadas');
 ok(rows($db,'usuarios')===$initial['usuarios']&&rows($db,'telefonia_extensiones')===$initial['telefonia_extensiones'],'configuración intacta');
 ok(count(rows($db,'telefonia_zadarma_eventos'))===3,'tramos 100/102 y grabación compartida intactos');
 ok(rows($db,'formulario_aliados')[0]['seguimiento_id']===null,'liga preservada y desvinculada');
 ok(rows($db,'formulario_aliado_procedencias')===$initial['formulario_aliado_procedencias'],'prospectos preservados');
 ok(rows($db,'telefonia_notificaciones')===[ $initial['telefonia_notificaciones'][1] ],'avisos de otra cuenta intactos');
 ok(rows($db,'observaciones_seguimiento')[1]===$initial['observaciones_seguimiento'][1],'aviso de otro destinatario intacto');
 ok(rows($db,'recordatorios_vinculacion')[0]['aviso_vencida_at']!==null,'aviso vencido silenciado');
 ok(rows($db,'recordatorios_vinculacion')[1]===$initial['recordatorios_vinculacion'][2],'aviso futuro intacto');
 ok(rows($db,'recordatorios_vinculacion')[2]===$initial['recordatorios_vinculacion'][3],'recordatorio ajeno intacto');
 $after=$snapshot();$tool->aplicar($plan,$id,$sha);ok($snapshot()===$after,'apply idempotente');
 $db->query("UPDATE formulario_aliados SET token='edición posterior' WHERE id=1");fails(fn()=>$tool->restaurar($plan,$id,$sha),'no se sobrescribirá');ok(count(rows($db,'seguimientos_vinculacion'))===1304,'restore fallido hace rollback completo');$db->query("UPDATE formulario_aliados SET token='liga-que-se-conserva' WHERE id=1");
 $tool->restaurar($plan,$id,$sha);ok($snapshot()===$initial,'restauración exacta de todas las filas');
 // También se prueba el CLI que se ejecutará en Hostinger, incluido su respaldo privado.
 $dir=sys_get_temp_dir().'/impe-diego-cli-'.bin2hex(random_bytes(6));
 mkdir($dir,0700);mkdir($dir.'/site');mkdir($dir.'/site/config');mkdir($dir.'/site/app');mkdir($dir.'/site/app/services');
 file_put_contents($dir.'/site/index.php','<?php');
 $port=(int)(getenv('IMPE_DIEGO_TEST_PORT')?:33339);
 file_put_contents($dir.'/site/config/db_connection.php',"<?php class Database {function connect(\$persistente=true){\$db=new mysqli('127.0.0.1','root','','$name',$port);\$db->set_charset('utf8mb4');return \$db;}}");
 $guard=shell_exec('git show c0ee3dae4e3dd9ba0f99d841c8e484d1770a4c7e');
 ok(is_string($guard)&&hash('sha256',str_replace("\r\n","\n",$guard))==='17d03adc29084f54b7cdb7b8c09594b9f4da257dc469b674f221b4cdeae227bf','guard de fixture exacto');
 file_put_contents($dir.'/site/app/services/TelefoniaLimpiezaGuard.php',$guard);
 $cli=static function(array $args)use($dir):string {
  $cmd=array_merge([PHP_BINARY,__DIR__.'/../tools/limpiar_pruebas_diego.php','--site',$dir.'/site','--backup-root',$dir.'/backup'],$args);
  $p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
  if($code!==0)throw new RuntimeException('CLI: '.$out.$err);return $out;
 };
 try {
  $out=json_decode($cli(['--check']),true,512,JSON_THROW_ON_ERROR);$file=$out['plan_privado'];
  ok(is_file($file)&&$snapshot()===$initial,'check CLI guarda respaldo sin escribir SQL');
  ok(json_decode(gzdecode(file_get_contents($file)),true)['plan']['version']===5101,'respaldo legible');
  ok(str_contains($cli(['--apply',$file]),'APLICADO'),'apply CLI');
  ok(str_contains($cli(['--restore',$file]),'RESTAURADO'),'restore CLI');ok($snapshot()===$initial,'CLI restaura exactamente');
 } finally {
  foreach(glob($dir.'/backup/*')?:[] as $f)unlink($f);rmdir($dir.'/backup');
  unlink($dir.'/site/app/services/TelefoniaLimpiezaGuard.php');rmdir($dir.'/site/app/services');rmdir($dir.'/site/app');
  unlink($dir.'/site/config/db_connection.php');rmdir($dir.'/site/config');unlink($dir.'/site/index.php');rmdir($dir.'/site');rmdir($dir);
 }
 echo "PASS: MariaDB aislada; nueve pruebas, 1303 seguimientos conservados, llamadas/efectivas y avisos retirados, otras cuentas/tramos/ligas/prospectos protegidos, cambios concurrentes bloqueados, idempotencia y restauración exacta.\n";
}finally{$db->query("DROP DATABASE `$name`");}
