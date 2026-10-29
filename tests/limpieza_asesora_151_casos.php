<?php
require_once dirname(__DIR__).'/tools/limpiar_llamadas_asesora_151.php';
$db->query("INSERT INTO usuarios VALUES(15,1,3,'Yazmin','Hurtado')");
$db->query("INSERT INTO telefonia_extensiones(usuario_id,extension,created_at,updated_at) VALUES(15,'151',DATE_SUB(NOW(),INTERVAL 20 DAY),DATE_SUB(NOW(),INTERVAL 20 DAY))");
$db->query("CREATE TABLE telefonia_crm_exclusiones(pbx_call_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,extension VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,limpieza_id CHAR(32) CHARACTER SET ascii NOT NULL,PRIMARY KEY(pbx_call_id,extension)) ENGINE=InnoDB");
$db->query("CREATE TABLE telefonia_crm_limpiezas(id CHAR(32) CHARACTER SET ascii PRIMARY KEY,backup_sha CHAR(64) CHARACTER SET ascii NOT NULL,estado VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB");
$targetRef='out_'.str_repeat('c',32);$shared='pbx-shared-reception-151';
$event($targetRef,'NOTIFY_OUT_START','151');$event($targetRef,'NOTIFY_OUT_END','151',12,['disposition'=>'answered']);
$event($targetRef,'NOTIFY_RECORD','151',0,['call_id_with_rec'=>'audio-target-151']);
$results->guardar(15,$targetRef,'CONVERSACION_PERSONA',['observacion'=>'Observación de la prueba']);
$targetResult=$svc->obtener(15,$targetRef,'151')['registro']['id'];
$db->query("INSERT INTO telefonia_ventas_resultado_detalles(resultado_id,resultado_principal,envio_informacion,motivo_sin_whatsapp,contacto_antes_corte,catalogo_version) VALUES($targetResult,'PROSPECTO_POTENCIAL','WHATSAPP','','','3')");
$db->query("INSERT INTO telefonia_ventas_resultado_selecciones VALUES($targetResult,'PROSPECTO_POTENCIAL',1),($targetResult,'WHATSAPP',2)");
$db->query('CREATE TABLE telefonia_ventas_observaciones(id BIGINT PRIMARY KEY,resultado_id BIGINT UNSIGNED,autor_id INT,nota TEXT,FOREIGN KEY(resultado_id) REFERENCES telefonia_ventas_resultados(id)) ENGINE=InnoDB');
$db->query("INSERT INTO telefonia_ventas_observaciones VALUES(1,$targetResult,1,'Nota de supervisión de prueba')");
// Transferencia compartida: conservar los eventos y el resultado de Recepción.
$route=json_encode(['tipo'=>'PROSPECTO','responsable_id'=>15,'extension'=>'151','referencia_llamada'=>$shared,'institucion_nombre'=>'Prospecto prueba']);
$s=$db->prepare("INSERT INTO telefonia_disponibilidad(origen_usuario,origen_extension,destino_usuario,destino_extension,referencia,numero,ruta_json,estado,expires_at,created_at,updated_at) VALUES(7,'100',15,'151',?,'5550000000',?,'ENVIADA',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");$s->bind_param('ss',$shared,$route);$s->execute();$s->close();
$event($shared,'NOTIFY_INTERNAL','151');$event($shared,'NOTIFY_END','151',10);$event($shared,'NOTIFY_OUT_START','100');$event($shared,'NOTIFY_OUT_END','100',20);
$db->query('CREATE TABLE telefonia_recepcion_resultados(id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),pbx_call_id VARCHAR(100),resultado VARCHAR(40),created_at DATETIME) ENGINE=InnoDB');
$s=$db->prepare("INSERT INTO telefonia_recepcion_resultados VALUES(1,7,'100',?,'TRANSFERIDA',NOW())");$s->bind_param('s',$shared);$s->execute();$s->close();
foreach(['proxima_accion_at'=>'DATETIME NULL','observacion'=>'TEXT NULL','updated_at'=>'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'] as $field=>$type)if(!$db->query("SHOW COLUMNS FROM telefonia_contactos_personales LIKE '$field'")->num_rows)$db->query("ALTER TABLE telefonia_contactos_personales ADD `$field` $type");
$db->query("INSERT INTO telefonia_contactos_personales(id,usuario_id,nombre,telefono,proxima_accion_at) VALUES(3,15,'Conservar contacto','5550000000','2026-12-01 10:00:00'),(4,15,'Cita personal','5550000001','2026-12-02 11:00:00')");
$db->query("UPDATE telefonia_ventas_resultados SET contacto_id=3,proxima_accion_at='2026-12-01 10:00:00' WHERE id=$targetResult");
if(!$db->query("SHOW TABLES LIKE 'telefonia_notificaciones'")->num_rows)$db->query('CREATE TABLE telefonia_notificaciones(id INT PRIMARY KEY,usuario_id INT,extension VARCHAR(12),llave VARCHAR(180),created_at DATETIME) ENGINE=InnoDB');
$db->query("INSERT INTO telefonia_notificaciones(id,usuario_id,extension,llave,created_at) VALUES(901,15,'151','ventas:llamada:1:resultado',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 HOUR)),(902,15,'','ventas:programada:agenda:4:personal',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 HOUR)),(903,8,'150','ventas:llamada:1:resultado',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 HOUR)),(904,15,'','ventas:programada:agenda:3:espejo',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 6 HOUR))");
$tools=new LimpiezaLlamadasAsesora151($db);$p=$tools->plan();
$db->query('CREATE TABLE dependencia_ajena(id INT PRIMARY KEY,resultado_id BIGINT UNSIGNED,FOREIGN KEY(resultado_id) REFERENCES telefonia_ventas_resultados(id)) ENGINE=InnoDB');$db->query('INSERT INTO dependencia_ajena VALUES(1,'.$targetResult.')');
try{try{$tools->plan();throw new RuntimeException('Debe bloquear dependencias ajenas');}catch(RuntimeException $e){vcheck(str_contains($e->getMessage(),'Dependencia no incluida'),'No confía en cascadas desconocidas');}}
finally{$db->query('DROP TABLE dependencia_ajena');}
$readRows=static fn($t)=>$db->query("SELECT * FROM `$t` ORDER BY 1")->fetch_all(MYSQLI_ASSOC);
$beforeEvents=$readRows('telefonia_zadarma_eventos');$beforeReception=$readRows('telefonia_recepcion_resultados');$beforeContacts=$readRows('telefonia_contactos_personales');
$beforeOther=$db->query('SELECT * FROM telefonia_ventas_llamadas WHERE usuario_id<>15 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$beforeUser=$readRows('usuarios');$beforeExtensions=$readRows('telefonia_extensiones');
usleep(1100000);vcheck($tools->plan($p['corte'])===$p,'Corte estable al transcurrir el tiempo');
vcheck(count($p['usuarios'])===1&&(int)$p['usuarios'][0]['id']===15,'Plan limitado a la identidad de Yazmin');
vcheck(array_unique(array_column($p['exclusiones'],'extension'))===['151'],'Exclusiones sólo para el tramo 151');
putenv('IMPE_TEST_DATABASE='.$name);
$private=dirname(dirname(__DIR__)).'/backup151';
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/tools/limpiar_llamadas_asesora_151.php').' --site '.escapeshellarg(dirname(__DIR__)).' --backup-root '.escapeshellarg($private);
$run=static function(string $args,bool $ok=true)use($command):string{$out=[];$status=0;exec($command.' '.$args.' 2>&1',$out,$status);vcheck(($status===0)===$ok,'CLI esperado: '.implode("\n",$out));return implode("\n",$out);};
$preview=json_decode($run('--check'),true,512,JSON_THROW_ON_ERROR);$file=$preview['plan_privado'];
$guardPath=dirname(__DIR__).'/app/services/TelefoniaLimpiezaGuard.php';$guardBytes=file_get_contents($guardPath);file_put_contents($guardPath,$guardBytes."\n// variante desconocida");
try{$run('--apply '.escapeshellarg($file),false);vcheck($db->query('SELECT COUNT(*) n FROM telefonia_ventas_llamadas WHERE usuario_id=15')->fetch_assoc()['n']==='2','Protección diferente bloquea antes de borrar');}
finally{file_put_contents($guardPath,$guardBytes);}
vcheck(!str_contains(json_encode($preview),'5550000000'),'Resumen sin teléfonos');
$saved=json_decode(gzdecode(file_get_contents($file)),true,512,JSON_THROW_ON_ERROR);
vcheck(isset($preview['registros']['telefonia_ventas_observaciones']),'Notas adicionales incluidas por resultado');
vcheck($readRows('telefonia_zadarma_eventos')===$beforeEvents,'Check no toca datos PBX');
// Una edición posterior obliga a generar otro plan y no borra nada.
$db->query("UPDATE telefonia_ventas_resultados SET observacion='Cambio posterior' WHERE id=$targetResult");$run('--apply '.escapeshellarg($file),false);
vcheck($db->query('SELECT COUNT(*) n FROM telefonia_ventas_llamadas WHERE usuario_id=15')->fetch_assoc()['n']==='2','Cambio concurrente cancela sin borrar');
$db->query("UPDATE telefonia_ventas_resultados SET observacion='Observación de la prueba',updated_at=updated_at WHERE id=$targetResult");
$preview=json_decode($run('--check'),true,512,JSON_THROW_ON_ERROR);$file=$preview['plan_privado'];
$run('--apply '.escapeshellarg($file));
vcheck($db->query('SELECT COUNT(*) n FROM telefonia_ventas_llamadas WHERE usuario_id=15')->fetch_assoc()['n']==='0','Historial de Yazmin en cero');
vcheck($db->query('SELECT COUNT(*) n FROM telefonia_ventas_resultados WHERE usuario_id=15')->fetch_assoc()['n']==='0','Resultados de Yazmin en cero');
vcheck($readRows('telefonia_ventas_observaciones')===[],'Notas vinculadas retiradas');
vcheck($db->query('SELECT COUNT(*) n FROM telefonia_ventas_resultado_detalles WHERE resultado_id='.$targetResult)->fetch_assoc()['n']==='0','Detalle V2/V3 retirado explícitamente');
vcheck($readRows('telefonia_zadarma_eventos')===$beforeEvents&&$readRows('telefonia_recepcion_resultados')===$beforeReception,'Evidencias y resultado de Recepción intactos');
vcheck($db->query('SELECT * FROM telefonia_ventas_llamadas WHERE usuario_id<>15 ORDER BY id')->fetch_all(MYSQLI_ASSOC)===$beforeOther,'Otras asesoras conservan su historial');
vcheck($readRows('usuarios')===$beforeUser&&$readRows('telefonia_extensiones')===$beforeExtensions,'Cuentas y configuración de extensiones intactas');
$contacts=$readRows('telefonia_contactos_personales');
vcheck(count($contacts)===count($beforeContacts)&&$contacts[2]['nombre']===$beforeContacts[2]['nombre']&&$contacts[2]['proxima_accion_at']===null&&$contacts[3]===$beforeContacts[3],'Conserva agenda y sólo limpia el espejo de recordatorio de llamada');
vcheck($db->query('SELECT id FROM telefonia_notificaciones WHERE id>=901 ORDER BY id')->fetch_all(MYSQLI_ASSOC)===[['id'=>'902'],['id'=>'903']],'Conserva aviso personal y aviso de otra cuenta');
$svc->sincronizar(15);vcheck($svc->listar(15,[],false)===[],'Sincronizar no reimporta llamadas antiguas');
$event($targetRef,'NOTIFY_ANSWER','151');vcheck($svc->listar(15,[],false)===[],'Aviso tardío no resucita la llamada');
$newRef='out_'.str_repeat('d',32);$event($newRef,'NOTIFY_OUT_START','151');$event($newRef,'NOTIFY_OUT_END','151',5,['disposition'=>'answered']);
vcheck(count($svc->listar(15))===1,'Una nueva llamada se registra normalmente');
$run('--apply '.escapeshellarg($file));vcheck(count($svc->listar(15))===1,'Reaplicar no borra llamadas nuevas');
$run('--restore '.escapeshellarg($file));
vcheck(count($svc->listar(15))===3,'Restauración recupera antiguas conservando la nueva');
vcheck($readRows('telefonia_contactos_personales')===$beforeContacts,'Restauración recupera sólo el recordatorio original');
vcheck(count($readRows('telefonia_ventas_observaciones'))===1,'Restauración recupera las notas');
// Otra identidad o asignación bloquea la limpieza.
$db->query("UPDATE usuarios SET nombre='Otro' WHERE id=15");
try{$tools->plan();throw new RuntimeException('Debe bloquear otra identidad');}catch(RuntimeException $e){vcheck(str_contains($e->getMessage(),'no coincide'),'Otra identidad queda protegida');}
$db->query("UPDATE usuarios SET nombre='Yazmin' WHERE id=15");
$active='out_'.str_repeat('b',32);$at=date('Y-m-d H:i:s');$event($active,'NOTIFY_OUT_START','151',0,['received_at'=>$at,'call_start'=>$at]);
try{$tools->plan();throw new RuntimeException('Debe bloquear llamada activa');}catch(RuntimeException $e){vcheck(str_contains($e->getMessage(),'llamada reciente sin cierre'),'Limpieza bloqueada durante una llamada reciente');}
echo "Limpieza 151: CLI, respaldo, exclusiones, cero inicial, llamadas nuevas, restauración y otras cuentas comprobados.\n";
