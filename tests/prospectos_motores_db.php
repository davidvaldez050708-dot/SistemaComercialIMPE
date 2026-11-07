<?php
/**
 * Integration test in a NEW disposable MySQL 8 database only.
 * Does NOT connect to production; no external phones, Zaparma or CRM data.
 */
require_once __DIR__.'/../app/services/ProspectoDistribucionMotor.php';
if (getenv('IMPE_MOTORES_DB_TEST')!=='YES') {fwrite(STDERR,"Solo BD desechable.\n");exit(2);}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'',
    '',(int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_motores_prueba_'.bin2hex(random_bytes(5));
$checks=0;
$check=static function($ok,$msg)use(&$checks) {
    if (!$ok)throw new RuntimeException('FALLÓ: '.$msg);
    $checks++;
};
try {
    $db->query("CREATE DATABASE `$name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);
    foreach ([
        "CREATE TABLE roles(id INT PRIMARY KEY,nombre VARCHAR(100),estado TINYINT) ENGINE=InnoDB",
        "CREATE TABLE usuarios(id INT PRIMARY KEY,rol_id INT,estado TINYINT) ENGINE=InnoDB",
        "CREATE TABLE formulario_programas(codigo VARCHAR(64) PRIMARY KEY,nombre VARCHAR(100),activo TINYINT) ENGINE=InnoDB",
        "CREATE TABLE estados(id INT PRIMARY KEY,nombre VARCHAR(100)) ENGINE=InnoDB",
        "CREATE TABLE prospectos_comerciales(
            id BIGINT UNSIGNED PRIMARY KEY,estado_id INT NULL,
            pais_nombre VARCHAR(120),estado_provincia VARCHAR(120)) ENGINE=InnoDB",
        "CREATE TABLE prospecto_origenes(
            id BIGINT UNSIGNED PRIMARY KEY,prospecto_id BIGINT UNSIGNED,
            tipo_origen VARCHAR(24),origen_padre_id BIGINT UNSIGNED NULL,
            lote_importacion_ref VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
            incorporado_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE prospecto_intereses(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            origen_id BIGINT UNSIGNED,prospecto_id BIGINT UNSIGNED,
            programa_codigo VARCHAR(80) NULL) ENGINE=InnoDB",
        "CREATE TABLE prospecto_preferencias_contacto(
            origen_id BIGINT UNSIGNED PRIMARY KEY,valor_original TEXT,
            dias_semana VARCHAR(32),hora_desde TIME,hora_hasta TIME,
            zona_horaria VARCHAR(64),interpretacion_estado VARCHAR(32)) ENGINE=InnoDB",
        "CREATE TABLE prospecto_asignaciones(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospecto_id BIGINT UNSIGNED NOT NULL,
            asesor_id INT NOT NULL,asignado_por INT NULL,
            asignado_at DATETIME NOT NULL,fecha_trabajo DATE NULL,
            proxima_accion_at DATETIME NULL,finalizado_at DATETIME NULL,
            vigente TINYINT UNSIGNED NULL,estado VARCHAR(24) NOT NULL,
            UNIQUE KEY uq_pa_vigente(prospecto_id,vigente)) ENGINE=InnoDB",
        "CREATE TABLE prospecto_asesores_config(
            usuario_id INT PRIMARY KEY,programa_codigo VARCHAR(64),
            jornada VARCHAR(20),activo TINYINT UNSIGNED) ENGINE=InnoDB",
        "CREATE TABLE prospecto_asesores_programas(
            usuario_id INT,programa_codigo VARCHAR(64),
            PRIMARY KEY(usuario_id,programa_codigo)) ENGINE=InnoDB",
        "CREATE TABLE prospecto_asesores_horarios(
            usuario_id INT,dia_semana TINYINT UNSIGNED,
            hora_desde TIME,hora_hasta TIME,
            PRIMARY KEY(usuario_id,dia_semana)) ENGINE=InnoDB"
    ] as $sql) $db->query($sql);
    $migration=file_get_contents(__DIR__.'/../database/migrations/2026_11_06_prospectos_motores_distribucion.sql');
    $db->multi_query($migration);
    do {$r=$db->store_result();if($r)$r->free();} while ($db->more_results() && $db->next_result());
    $cursorMigration=file_get_contents(
        __DIR__.'/../database/migrations/2026_11_07_prospectos_meta_cursor_escalable.sql'
    );
    $db->multi_query($cursorMigration);
    do {$r=$db->store_result();if($r)$r->free();} while ($db->more_results() && $db->next_result());

    $db->query("INSERT INTO roles VALUES (3,'Asesor de Ventas',1)");
    $db->query("INSERT INTO usuarios VALUES (10,3,1),(20,3,1),(30,3,1),(40,3,1)");
    $db->query("INSERT INTO formulario_programas VALUES
        ('TIT_EXP','Titulación por experiencia',1),
        ('BACH','Bachillerato general',1),
        ('INGLES','Inglés',1)");
    $db->query("INSERT INTO estados VALUES(1,'Puebla'),(2,'Morelos'),(3,'Baja California')");
    $db->query("INSERT INTO prospecto_asesores_config VALUES
        (10,'TIT_EXP','MEDIO_TIEMPO',1),
        (20,'BACH','MEDIO_TIEMPO',1),
        (30,'BACH','MEDIO_TIEMPO',0),
        (40,'INGLES','MEDIO_TIEMPO',0)");
    $db->query("INSERT INTO prospecto_asesores_programas VALUES
        (10,'TIT_EXP'),(20,'BACH'),(30,'BACH'),(40,'INGLES')");
    $db->query("INSERT INTO prospecto_asesores_horarios VALUES
        (10,6,'16:00','21:00'),
        (20,6,'08:00','14:00'),
        (30,6,'08:00','16:00'),
        (40,6,'08:00','14:00')");
    $motor=new ProspectoDistribucionMotor($db);
    $check($motor->disponible(),'estructura creada');
    $check(!$motor->habilitado('META')&&!$motor->habilitado('ALIADO'),
        'migración no activa reparto automático');
    try {
        $motor->distribuirMeta('2026-11-07',200,true);
        throw new RuntimeException('Meta activo antes de autorización');
    } catch (DomainException $esperado) {
        $checks++;
    }
    $db->query("UPDATE prospecto_distribucion_control
        SET meta_habilitado=1,aliados_habilitado=1 WHERE id=1");

    // Dos archivos independientes de la misma noche, 82 + 8 leads nuevos.
    $loteA=str_repeat('a',32);$loteB=str_repeat('b',32);
    $agregaMeta=$db->prepare(
        "INSERT INTO prospectos_comerciales(id,pais_nombre,estado_provincia)
         VALUES (?, 'México', 'Puebla')"
    );
    $origen=$db->prepare(
        "INSERT INTO prospecto_origenes
         (id,prospecto_id,tipo_origen,origen_padre_id,lote_importacion_ref,incorporado_at)
         VALUES (?,?,'META_ADS',NULL,?,'2026-11-06 21:00:00')"
    );
    $interes=$db->prepare(
        "INSERT INTO prospecto_intereses(origen_id,prospecto_id,programa_codigo)
         VALUES (?,?,?)"
    );
    for($i=1;$i<=90;$i++) {
        $lote=$i<=82?$loteA:$loteB;
        $programa=$i<=82?'TIT_EXP':'BACH';
        $agregaMeta->bind_param('i',$i);$agregaMeta->execute();
        $origen->bind_param('iis',$i,$i,$lote);$origen->execute();
        $interes->bind_param('iis',$i,$i,$programa);$interes->execute();
    }
    $agregaMeta->close();$origen->close();$interes->close();
    $empty=$motor->distribuirMeta('2026-11-07',200,true);
    $check($empty['asignados']===0 && $empty['lotes_confirmados']===0,
        'importación no confirmada no se reparte');
    $motor->confirmarLoteMeta($loteA,10,82);
    $preview=$motor->distribuirMeta('2026-11-07',200,false);
    $check($preview['inspeccionados']===82 &&
        $preview['asignados']>0,'simulación sólo del primer archivo completo');
    $check((int)$db->query('SELECT COUNT(*) n FROM prospecto_asignaciones')->fetch_assoc()['n']===0,
        'simulación no escribe asignaciones');
    $motor->confirmarLoteMeta($loteB,20,8);
    $check((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_meta_lotes_confirmados'
    )->fetch_assoc()['n']===2,'se confirman dos formularios/archivos');
    $result=$motor->distribuirMeta('2026-11-07',200,true);
    $check($result['inspeccionados']===90,'dos cargas sumadas');
    $check($result['asignados']===88 && $result['pendientes']===2,
        '80 titulación + 8 bachillerato; dos excedentes');
    $check((int)$db->query(
        "SELECT COUNT(*) n FROM prospecto_distribucion_registros
         WHERE tipo_origen='META' AND asesor_id=10"
    )->fetch_assoc()['n']===80,'asesor titulación exactamente 80');
    $check((int)$db->query(
        "SELECT COUNT(*) n FROM prospecto_distribucion_registros
         WHERE tipo_origen='META' AND asesor_id=20"
    )->fetch_assoc()['n']===8,'asesor bachillerato sólo su programa');
    $check((int)$db->query(
        "SELECT COUNT(*) n FROM prospecto_distribucion_pendientes
         WHERE motivo='SIN_ASESOR_O_CUPO'"
    )->fetch_assoc()['n']===2,'sobrantes permanecen pendientes');
    $second=$motor->distribuirMeta('2026-11-07',200,true);
    $check($second['asignados']===0,'reintento mismo día no duplica ni excede cupo');
    $check((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_asignaciones'
    )->fetch_assoc()['n']===88,'sin duplicados en motor Meta');

    // Un registro de Aliado: franja solicitada 17-19, asesor 16-21.
    $agregaAliado=static function($id,$programa,$estado,$preferencia)use($db) {
        $db->query("INSERT INTO prospectos_comerciales
            (id,pais_nombre,estado_provincia)
            VALUES ($id,'México','$estado')");
        $db->query("INSERT INTO prospecto_origenes
            (id,prospecto_id,tipo_origen,origen_padre_id,incorporado_at)
            VALUES ($id,$id,'ALIADO',NULL,'2026-11-06 20:00:00')");
        if($programa!==null) {
            $code=$db->real_escape_string($programa);
            $db->query("INSERT INTO prospecto_intereses
                (origen_id,prospecto_id,programa_codigo)
                VALUES ($id,$id,'$code')");
        }
        $estadoPref=$preferencia==='LIBRE'?'SIN_PREFERENCIA':'DEFINIDO_ZONA_PENDIENTE';
        $days='[6]';
        $db->query("INSERT INTO prospecto_preferencias_contacto
            (origen_id,dias_semana,hora_desde,hora_hasta,interpretacion_estado)
            VALUES ($id,'$days','17:00','19:00','$estadoPref')");
    };
    $agregaAliado(201,'TIT_EXP','Puebla','DEFINIDA');
    $time=new DateTimeImmutable('2026-11-06 12:00:00',new DateTimeZone('America/Mexico_City'));
    $a=$motor->asignarAliado(201,true,$time);
    $check($a['estado']==='ASIGNADO' && $a['asesor_id']===10,
        'Aliado inmediato con franja y programa compatibles');
    $row=$db->query('SELECT fecha_trabajo,proxima_accion_at FROM prospecto_asignaciones WHERE prospecto_id=201')->fetch_assoc();
    $check($row['fecha_trabajo']==='2026-11-07' &&
        $row['proxima_accion_at']==='2026-11-07 23:00:00',
        'siguiente sábado 17:00 México = 23:00 UTC');
    $check($motor->asignarAliado(201,true,$time)['estado']==='YA_ASIGNADO',
        'Aliado repetido no reasignado');

    $agregaAliado(202,'TIT_EXP','Baja California','DEFINIDA');
    $pend=$motor->asignarAliado(202,true,$time);
    $check($pend['estado']==='PENDIENTE' &&
        $pend['motivo']==='ZONA_HORARIA_PENDIENTE','zona no confirmada pendiente');
    $agregaAliado(203,null,'Morelos','LIBRE');
    $pend=$motor->asignarAliado(203,true,$time);
    $check($pend['motivo']==='SIN_PROGRAMA','sin programa no se inventa asesor');
    $agregaAliado(204,'INGLES','Puebla','LIBRE');
    $pend=$motor->asignarAliado(204,true,$time);
    $check($pend['motivo']==='SIN_ASESOR_COMPATIBLE','asesor inactivo no elegible');
    $check((int)$db->query(
        "SELECT COUNT(*) n FROM prospecto_asignaciones WHERE prospecto_id IN (202,203,204)"
    )->fetch_assoc()['n']===0,'pendientes no se asignan');
    $agregaAliado(205,'BACH','Puebla','LIBRE');
    $ap=$motor->asignarAliado(205,false,$time);
    $check($ap['estado']==='SIMULACION' && $ap['asesor_id']===20,
        'simulación Aliado sin escribir');
    $check($db->query(
        'SELECT id FROM prospecto_asignaciones WHERE prospecto_id=205'
    )->num_rows===0,'no se insertó simulación');
    $a=$motor->asignarAliado(205,true,$time);
    $check($a['asesor_id']===20,'Aliado sin límite Meta y Bachillerato correcto');
    $check((int)$db->query(
        "SELECT COUNT(*) n FROM prospecto_distribucion_registros WHERE tipo_origen='ALIADO'"
    )->fetch_assoc()['n']===2,'auditoría de dos Aliados');
    // Si se aclara zona y se configura programa después, reintentos recuperan.
    $db->query("UPDATE prospecto_preferencias_contacto
        SET zona_horaria='America/Mexico_City' WHERE origen_id=202");
    $db->query("UPDATE prospecto_asesores_config SET activo=1 WHERE usuario_id=40");
    $retry=$motor->reintentarAliados(50,true);
    $check($retry['asignados']===2,'reintento asigna tras resolver zona y activar asesor');
    $check((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_asignaciones'
    )->fetch_assoc()['n']===92,'cupo Meta no se consume con Aliados');
    $check((int)$db->query(
        "SELECT COUNT(*) n FROM prospecto_distribucion_registros WHERE tipo_origen='META' AND asesor_id=10"
    )->fetch_assoc()['n']===80,'Aliado no aumenta cupo Meta');
    $check($motor->asignarAliado(1,false,$time)['estado']==='IGNORADO_NO_ALIADO',
        'leads Meta jamás se tratan como Aliados');
    $check((int)$db->query(
        "SELECT COUNT(*) n FROM prospecto_distribucion_corridas WHERE tipo_origen='META'"
    )->fetch_assoc()['n']===3,'registro de corridas sin PII');
    // El asesor de INGLÉS solo se activó para la prueba previa de reintento;
    // en esta prueba de escala debe permanecer inactivo, de modo que
    // los registros sin asesor compatible ocupen las primeras páginas.
    $db->query("UPDATE prospecto_asesores_config SET activo=0 WHERE usuario_id=40");

    // Stress funcional de paginación: 28 archivos completos en una sola
    // jornada, con pendientes masivos al principio y un Bachillerato elegible
    // al final. La primera página NO puede ocultar indefinidamente el final.
    $insertPersona=$db->prepare(
        "INSERT INTO prospectos_comerciales(id,pais_nombre,estado_provincia)
         VALUES (?,'México','Puebla')"
    );
    $insertOrigen=$db->prepare(
        "INSERT INTO prospecto_origenes(id,prospecto_id,tipo_origen,origen_padre_id,
         lote_importacion_ref,incorporado_at)
         VALUES (?,?,'META_ADS',NULL,?,'2026-11-06 23:00:00')"
    );
    $insertInteres=$db->prepare(
        "INSERT INTO prospecto_intereses(origen_id,prospecto_id,programa_codigo)
         VALUES (?,?,?)"
    );
    for ($n=0;$n<28;$n++) {
        $token=str_pad(dechex($n+1),32,'c',STR_PAD_LEFT);
        $motor->confirmarLoteMeta($token,10,12);
        for ($z=0;$z<12;$z++) {
            $id=5000+$n*12+$z;
            $codigo=$n===27 && $z===11?'BACH':'INGLES';
            $insertPersona->bind_param('i',$id);$insertPersona->execute();
            $insertOrigen->bind_param('iis',$id,$id,$token);$insertOrigen->execute();
            $insertInteres->bind_param('iis',$id,$id,$codigo);$insertInteres->execute();
        }
    }
    $insertPersona->close();$insertOrigen->close();$insertInteres->close();
    $cursorAntes=$db->query('SELECT ultimo_origen_id FROM prospecto_distribucion_meta_cursor WHERE id=1')->fetch_assoc();
    $preview=$motor->distribuirMeta('2026-11-07',25,false);
    $check($preview['inspeccionados']===25 && !$preview['terminado'],
        'preview paginado sin límite total de archivos');
    $cursorDespues=$db->query('SELECT ultimo_origen_id FROM prospecto_distribucion_meta_cursor WHERE id=1')->fetch_assoc();
    $check($cursorAntes===$cursorDespues,'simulación no avanza el cursor real');
    $rondas=0;$vistos=0;$asignados=0;$cursores=[];
    do {
        $chunk=$motor->distribuirMeta('2026-11-07',25,true);
        $vistos+=$chunk['inspeccionados'];$asignados+=$chunk['asignados'];
        $cursores[]=$chunk['continuar_desde'];
        $rondas++;
        $check($rondas<=15,'recorrido termina en rondas acotadas');
    } while (!$chunk['terminado']);
    $check($vistos===336 && $rondas===14,'veintiocho lotes recorridos en catorce páginas');
    $check(count(array_unique($cursores))===$rondas,'cursor asciende sin repetir páginas');
    $check($asignados===1,'un Bachillerato al final supera los pendientes previos');
    $check((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_asignaciones WHERE prospecto_id=5335'
    )->fetch_assoc()['n']===1,'último prospecto llegó al asesor correcto');
    $repetir=$motor->distribuirMeta('2026-11-07',25,true);
    $check($repetir['inspeccionados']===0 && $repetir['terminado'],
        'mismo día completo no escanea otra vez');
    $nuevoDia=$motor->distribuirMeta('2026-11-08',25,false);
    $check($nuevoDia['inspeccionados']===25 && !$nuevoDia['terminado'],
        'siguiente jornada vuelve a revisar pendientes, sin perderlos');
    echo "OK: $checks pruebas SQL en BD temporal; 30 archivos Meta, reparto de 80, colas paginadas, horarios y duplicados.\n";
} finally {
    $db->select_db('information_schema');
    $db->query("DROP DATABASE IF EXISTS `$name`");
    $db->close();
}
