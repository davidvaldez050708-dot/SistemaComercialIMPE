<?php
declare(strict_types=1);
$database=getenv('IMPE_DB_NAME')?:'';
if (PHP_SAPI!=='cli'||!preg_match('/^impe_test_prospectos_[a-z0-9_]+$/D',$database)) exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('IMPE_DB_HOST')?:'127.0.0.1:33319',getenv('IMPE_DB_USER')?:'root',getenv('IMPE_DB_PASSWORD')?:'');
$db->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db->select_db($database);$db->set_charset('utf8mb4');
$root=dirname(__DIR__);
function ok(bool $v,string $m): void {if (!$v) throw new RuntimeException($m);echo 'OK '.$m.PHP_EOL;}
function snapshot(mysqli $db,bool $central=false): string {
    $data=[];$tables=$db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
    foreach ($tables as [$t]) if ($central||!str_starts_with($t,'prospecto')) $data[$t]=$db->query('SELECT * FROM `'.$t.'`')->fetch_all(MYSQLI_ASSOC);
    ksort($data);return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function cli(array $args): array {
    global $root;
    $command=[PHP_BINARY,$root.'/tools/prospectos_almacen.php','--site',$root,...$args];
    $p=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
    if ($code!==0) throw new RuntimeException('CLI falló: '.$err);
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
try {
    $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    foreach ([
        'CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(100),estado TINYINT DEFAULT 1) ENGINE=InnoDB',
        'CREATE TABLE municipios(id INT PRIMARY KEY,estado_id INT,nombre VARCHAR(100),estado TINYINT DEFAULT 1) ENGINE=InnoDB',
        'CREATE TABLE usuarios(id INT PRIMARY KEY,nombre VARCHAR(100),apellidos VARCHAR(100)) ENGINE=InnoDB',
        'CREATE TABLE formulario_programas(codigo VARCHAR(80) PRIMARY KEY,nombre VARCHAR(255),activo TINYINT DEFAULT 1) ENGINE=InnoDB',
        'CREATE TABLE seguimientos_vinculacion(id INT PRIMARY KEY,estado_id INT,activo TINYINT DEFAULT 1) ENGINE=InnoDB',
        'CREATE TABLE aliados_asignaciones(id INT PRIMARY KEY,seguimiento_id INT,activo TINYINT DEFAULT 1) ENGINE=InnoDB',
        'CREATE TABLE formulario_aliados(id INT AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(100),nombre VARCHAR(255),ubicacion VARCHAR(255),seguimiento_id INT NULL,pais_codigo VARCHAR(2),token CHAR(64),activo TINYINT DEFAULT 1) ENGINE=InnoDB',
        'CREATE TABLE formulario_aliado_programas(aliado_id INT,programa_codigo VARCHAR(80),PRIMARY KEY(aliado_id,programa_codigo)) ENGINE=InnoDB',
        "CREATE TABLE formulario_aliado_procedencias(id INT AUTO_INCREMENT PRIMARY KEY,aliado_id INT,tipo VARCHAR(24),registro_id INT,programa_codigo VARCHAR(80),aliado_nombre VARCHAR(255),aliado_ubicacion VARCHAR(255),clave CHAR(64),convocatoria_id INT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY dedup(aliado_id,tipo,clave)) ENGINE=InnoDB",
        'CREATE TABLE convocatorias(id INT PRIMARY KEY,estado TINYINT,fecha_inicio DATE,fecha_termino DATE) ENGINE=InnoDB',
        'CREATE TABLE formulario_aliado_convocatorias(aliado_id INT,convocatoria_id INT,referencia CHAR(32),formulario VARCHAR(24),programa_codigo VARCHAR(80)) ENGINE=InnoDB'
    ] as $sql) $db->query($sql);
    $sql=file_get_contents($root.'/database/migrations/2026_10_08_formulario_registros.sql');$db->query($sql);
    $db->query('ALTER TABLE formulario_registros MODIFY estado_id INT NULL,MODIFY municipio_id INT NULL,ADD tel_recomendado VARCHAR(20) NULL,ADD horario_contacto VARCHAR(255) NULL,ADD pais_nombre VARCHAR(120) NULL,ADD estado_provincia VARCHAR(120) NULL,ADD ciudad VARCHAR(120) NULL');
    $db->query('CREATE TABLE formulario_inscripciones LIKE formulario_registros');
    for ($i=1;$i<=32;$i++) {$s=$db->prepare('INSERT INTO estados(id,nombre) VALUES (?,?)');$n=$i===11?'Guanajuato':($i===1?'Baja California':'Estado de prueba '.$i);$s->bind_param('is',$i,$n);$s->execute();}
    $db->query("INSERT INTO municipios VALUES (1,1,'Municipio ficticio',1),(11,11,'Municipio de origen',1)");
    $db->query("INSERT INTO usuarios VALUES(1,'Usuario','Ficticio')");
    $db->query("INSERT INTO formulario_programas VALUES('programa-a','Programa A (prueba)',1),('programa-b','Programa B (prueba)',1)");
    $db->query('INSERT INTO seguimientos_vinculacion VALUES(1,11,1)');
    $token=str_repeat('a',64);
    $db->query("INSERT INTO formulario_aliados VALUES(1,'aliado-ficticio','Aliado de prueba','Guanajuato',1,'MX','$token',1)");
    $db->query("INSERT INTO formulario_aliado_programas VALUES(1,'programa-a'),(1,'programa-b')");
    require $root.'/app/models/FormularioRegistroModel.php';
    require $root.'/app/services/ProspectoCentralIncorporacionService.php';
    require $root.'/app/models/ProspectoModel.php';
    $model=new FormularioRegistroModel();$repo=new ProspectoCentralRepository($db);
    $datos=['nombre'=>'Persona ficticia','apellido'=>'Caso general','fecha_nacimiento'=>'1990-01-01','movil'=>'7770000001','correo'=>'caso1@example.test',
        'perfil_interes'=>'Programa A (prueba)','lugar_laboras'=>'Entidad de prueba','cargo_puesto'=>'Prueba','estado_id'=>1,'municipio_id'=>1,'creado_por'=>null,
        'origen'=>'PUBLICO','horario_contacto'=>'Lun, Mar, Vie · 07:00–22:00 (hora local)'];
    $general=$model->guardar($datos);ok($general>0,'formulario original guardado con almacén todavía ausente');
    $originales=snapshot($db);$antes=snapshot($db,true);
    $check=cli(['--check']);ok(count($check['estructura']['faltantes'])===6 && snapshot($db,true)===$antes,'check sin DDL ni escrituras de datos');
    $preview=cli(['--check','--reconciliar']);ok($preview['nuevos']===1 && snapshot($db,true)===$antes,'vista previa histórica sin escrituras');
    $migration=cli(['--apply','--migrar']);ok(count($migration['creadas'])===6 && snapshot($db)===$originales,'migración sólo aditiva conserva originales');
    ok(cli(['--apply','--migrar'])['creadas']===[],'migración idempotente');
    $svc=new ProspectoCentralIncorporacionService($db);$r=$svc->incorporarRegistro($general);$id=$r['prospecto_id'];
    $full=snapshot($db,true);$again=$svc->incorporarRegistro($general);
    ok(!$again['nuevo'] && $again['prospecto_id']===$id && snapshot($db,true)===$full,'conciliación repetida sin duplicados ni fechas reescritas');
    $pref=$db->query('SELECT * FROM prospecto_preferencias_contacto')->fetch_assoc();
    ok($pref['valor_original']===$datos['horario_contacto'] && $pref['dias_semana']==='[1,2,5]' && $pref['zona_horaria']===null,'horario original exacto, estructura fiable y zona desconocida');
    ok(snapshot($db)===$originales,'incorporación no modifica originales');
    $aliado=$datos;$aliado['aliado_token']=$token;$aliado['nombre']='Persona ficticia aliada';$aliado['correo']='aliada@example.test';$aliado['movil']='7770000002';
    $aid=$model->guardar($aliado);$origin=$repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$aid);
    ok($origin!==null && $origin['tipo_origen']==='ALIADO','incorporación automática posterior al commit incluye procedencia de aliado');
    $count=(int)$db->query('SELECT COUNT(*) n FROM formulario_registros')->fetch_assoc()['n'];
    ok($model->guardar($aliado)===$aid && (int)$db->query('SELECT COUNT(*) n FROM formulario_registros')->fetch_assoc()['n']===$count,'deduplicación original del aliado intacta');
    $rows=$repo->detalle((int)$origin['prospecto_id']);
    ok(count($rows['origenes'])===2 && $rows['original']['lugar_laboras']==='Entidad de prueba' && $rows['original']['correo']===$aliado['correo'],'ficha recupera toda la información original y el nombre histórico del aliado');
    $m=new ProspectoModel();$territorios=$m->territorios();$by=array_column($territorios,null,'id');
    ok(count($territorios)===32 && (int)$by[11]['total']===1 && (int)$by[1]['total']===0,'32 estados territorializados por aliado, no por residencia');
    $lista=$m->listar(1,25,['estado_id'=>11]);ok($lista['total']===1 && $lista['registros'][0]['estado']==='Baja California','filtro territorial conserva residencia independiente');
    $otra=$datos;$otra['nombre']='Otra persona ficticia';$otra['correo']='otra@example.test';$oid=$model->guardar($otra);
    $o=$repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$oid);
    ok((int)$o['prospecto_id']!==$id && $repo->detalle($id)['coincidencias']===1,'teléfono compartido no fusiona personas y queda visible para revisión');
    foreach (['Sin preferencia',null,''] as $i=>$horario) {
        $d=$datos;$d['correo']='horario'.$i.'@example.test';$d['horario_contacto']=$horario;$rid=$model->guardar($d);
        if ($horario==='') {$db->query("UPDATE formulario_registros SET horario_contacto='' WHERE id=$rid");$rootOrigin=$repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$rid);$db->query('DELETE FROM prospecto_preferencias_contacto WHERE origen_id='.(int)$rootOrigin['id']);$svc->incorporarRegistro($rid);}
        $o=$repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$rid);$h=$repo->consulta('SELECT * FROM prospecto_preferencias_contacto WHERE origen_id=?',[(int)$o['id']])->fetch_assoc();
        ok($h['valor_original']===$horario,'preferencia sin horario o histórica vacía preservada '.$i);
    }
    $beforeSource=snapshot($db);
    $db->begin_transaction();$minimal=$repo->crearPersona(['nombre_completo'=>'Prospecto mínimo ficticio','telefono_original'=>'+525500000003','estado_id'=>1]);
    $metaId='000123456789012345678901234567890';
    $meta=$repo->guardarOrigen($minimal,['tipo_origen'=>'META_ADS','sistema_origen'=>'META','tipo_registro'=>'LEAD','identificador_origen'=>$metaId,'meta_lead_id'=>$metaId,
        'formulario_id'=>'0099999999999999999999','formulario_nombre'=>'Titulación','campania_id'=>'001234567890123456789','datos_originales_ref'=>'meta:'.$metaId]);$db->commit();
    $detail=$repo->detalle($minimal);
    ok($detail['correo']===null && $detail['intereses']===[] && $detail['origenes'][0]['meta_lead_id']===$metaId,'futuro registro mínimo y metadatos textuales sin deducir programa del formulario');
    ok($repo->guardarOrigen($minimal,['tipo_origen'=>'META_ADS','sistema_origen'=>'META','tipo_registro'=>'LEAD','identificador_origen'=>$metaId,'meta_lead_id'=>$metaId,'datos_originales_ref'=>'meta:'.$metaId])===$meta,'mismo evento externo no se duplica');
    try {$repo->guardarOrigen($id,['tipo_origen'=>'META_ADS','sistema_origen'=>'META','tipo_registro'=>'LEAD','identificador_origen'=>$metaId,'datos_originales_ref'=>'meta:'.$metaId]);throw new LogicException('Se reasignó un evento ajeno');}catch(DomainException $e){}
    $second=$repo->guardarOrigen($minimal,['tipo_origen'=>'META_ADS','sistema_origen'=>'META','tipo_registro'=>'LEAD','identificador_origen'=>'otro-evento','datos_originales_ref'=>'meta:otro-evento']);
    $repo->interes($minimal,$meta,'programa-a','Programa A (prueba)',null);$repo->interes($minimal,$second,'programa-b','Programa B (prueba)',null);
    ok(count($repo->detalle($minimal)['intereses'])===2,'una identidad admite varias procedencias y programas explícitos');
    ok(snapshot($db)===$beforeSource,'preparación Meta no modifica tablas originales');
    $db->query("CREATE TRIGGER comercial_falla BEFORE INSERT ON prospectos_comerciales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo comercial simulado'");
    $fallo=$datos;$fallo['nombre']='Fallo recuperable ficticio';$fallo['correo']='pendiente@example.test';$pending=$model->guardar($fallo);
    ok($pending>0 && $repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$pending)===null,'fallo comercial no pierde la solicitud original ni crea relación parcial');
    $db->query('DROP TRIGGER comercial_falla');$svc->incorporarRegistro($pending);
    ok($repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$pending)!==null,'reintento recupera la solicitud sin duplicar');
    $source=snapshot($db);$snap=snapshot($db,true);cli(['--check','--reconciliar']);ok(snapshot($db,true)===$snap,'check con almacén instalado tampoco escribe');
    $cursor=0;$visited=0;do {$batch=cli(['--apply','--reconciliar','--desde-id',(string)$cursor,'--limite','2']);$cursor=$batch['continuar_desde'];$visited+=$batch['examinados'];}while(!$batch['terminado']);
    ok($visited===(int)$db->query('SELECT COUNT(*) n FROM formulario_registros')->fetch_assoc()['n'] && snapshot($db)===$source,'lotes reanudables conservan todo el origen');
    $all=snapshot($db,true);cli(['--apply','--reconciliar','--limite','100']);ok(snapshot($db,true)===$all,'segunda ejecución completa es idempotente');
    $integrity=cli(['--check','--integridad']);ok($integrity['referencias_originales_ausentes']===0 && $integrity['resumen']['por_incorporar']===0 && $integrity['procedencias_por_incorporar']===0,'integridad final de vínculos y recuperación completa');
    $inscripcion=$datos;$inscripcion['origen']='PUBLICO_INSCRIPCION';$before=(int)$db->query('SELECT COUNT(*) n FROM prospectos_comerciales')->fetch_assoc()['n'];$iid=$model->guardar($inscripcion);
    ok($iid>0 && (int)$db->query('SELECT COUNT(*) n FROM formulario_inscripciones')->fetch_assoc()['n']===1 && (int)$db->query('SELECT COUNT(*) n FROM prospectos_comerciales')->fetch_assoc()['n']===$before,'inscripciones conservadas y fuera de la incorporación de Registro');
    $empty=$repo->listar(['buscar'=>'NO-EXISTE-FICTICIO','pagina'=>900]);ok($empty['total']===0 && $empty['pagina']===1,'búsqueda y paginación seguras');
    $page=$repo->listar(['limite'=>2,'pagina'=>2]);ok(count($page['registros'])===2 && $page['pagina']===2,'paginación en servidor acotada');
    // Reintentos concurrentes del hook y la conciliación comparten el bloqueo raíz.
    $db->query("INSERT INTO formulario_registros(nombre,apellido,fecha_nacimiento,movil,correo,perfil_interes,lugar_laboras,estado_id,municipio_id,origen,created_at,updated_at) SELECT 'Concurrencia ficticia',apellido,fecha_nacimiento,movil,'concurrencia@example.test',perfil_interes,lugar_laboras,estado_id,municipio_id,origen,created_at,updated_at FROM formulario_registros WHERE id=$general");
    $concurrent=(int)$db->insert_id;$processes=[];$outcomes=[];
    for ($n=0;$n<2;$n++) {$process=proc_open([PHP_BINARY,$root.'/tests/prospectos_central_worker.php',(string)$concurrent],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);$processes[]=[$process,$pipes];}
    foreach ($processes as [$process,$pipes]) {$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ok(proc_close($process)===0,'incorporación concurrente sin error');$outcomes[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
    ok($outcomes[0]['prospecto_id']===$outcomes[1]['prospecto_id'] && array_sum(array_column($outcomes,'nuevo'))===1,'dos procesos crean una sola identidad y una sola procedencia raíz');
    // Variantes internacionales y por convocatoria ya instaladas en Hostinger.
    $db->query("INSERT INTO formulario_aliados VALUES(2,'aliado-internacional','Aliado internacional ficticio','País ficticio',NULL,'CO','".str_repeat('b',64)."',1)");
    $db->query("INSERT INTO formulario_aliado_programas VALUES(2,'programa-a')");
    $db->query("INSERT INTO convocatorias VALUES(1,1,DATE_SUB(CURDATE(),INTERVAL 1 DAY),DATE_ADD(CURDATE(),INTERVAL 1 DAY))");
    $ref=str_repeat('c',32);$db->query("INSERT INTO formulario_aliado_convocatorias VALUES(2,1,'$ref','registro','programa-a')");
    $international=$datos+['aliado_token'=>str_repeat('b',64),'convocatoria_ref'=>$ref,'pais_nombre'=>'País ficticio','estado_provincia'=>'Provincia ficticia','ciudad'=>'Ciudad ficticia'];$international['estado_id']=null;$international['municipio_id']=null;$international['correo']='internacional@example.test';
    $internationalId=$model->guardar($international);$intlRoot=$repo->origen('IMPE_FORMULARIOS','REGISTRO',(string)$internationalId);
    ok($model->guardar($international)===$internationalId && $repo->detalle((int)$intlRoot['prospecto_id'])['pais_nombre']==='País ficticio','internacional por convocatoria conserva ubicación, programa autorizado y deduplicación');
    ok((int)$db->query("SELECT convocatoria_id FROM formulario_aliado_procedencias WHERE registro_id=$internationalId AND tipo='registro'")->fetch_assoc()['convocatoria_id']===1,'referencia de convocatoria conservada en procedencia original');
    // El guardado original y el modo check no dependen de DDL implícita.
    $db->query('ALTER TABLE prospecto_integraciones MODIFY externo_id VARCHAR(190) COLLATE utf8mb4_unicode_ci NULL');
    ok(!ProspectoCentralSchema::revisar($db)['disponible'],'verificación detecta una colación incompatible para identificadores externos');
    $db->query('ALTER TABLE prospecto_integraciones MODIFY externo_id VARCHAR(190) COLLATE utf8mb4_bin NULL');
    $db->query('ALTER TABLE prospecto_intereses DROP FOREIGN KEY fk_pi_origen');
    $db->query('ALTER TABLE prospecto_intereses ADD CONSTRAINT fk_pi_origen FOREIGN KEY(origen_id) REFERENCES prospecto_origenes(id)');
    ok(!ProspectoCentralSchema::revisar($db)['disponible'],'verificación detecta relación incorrecta aunque conserve el nombre');
    $db->query('ALTER TABLE prospecto_intereses DROP FOREIGN KEY fk_pi_origen');
    $db->query('ALTER TABLE prospecto_intereses ADD CONSTRAINT fk_pi_origen FOREIGN KEY(origen_id,prospecto_id) REFERENCES prospecto_origenes(id,prospecto_id)');
    ok(ProspectoCentralSchema::revisar($db)['disponible'],'estructura final íntegra después de pruebas de incompatibilidad');
    echo "PASS almacenamiento, conciliación, original intacto, fallos recuperables y regresiones de formularios.\n";
} finally {if (getenv('IMPE_CENTRAL_KEEP_FIXTURE')!=='1') $db->query('DROP DATABASE `'.$database.'`');$db->close();}
