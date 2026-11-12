<?php
/**
 * E2E de etapa PROCESO_INSCRIPCION en DB MySQL/MariaDB efímera.
 * No usuarios, personas ni formularios reales; no conectarse a Hostinger.
 */
if (getenv('IMPE_V3_INSCRIPCION_TEST_DB')!=='YES') {
    fwrite(STDERR,"BLOQUEADO: sólo bases de prueba desechables.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../app/services/EmbudoComercialV3InscripcionService.php';
function probarInscripcion(bool $cond,string $descripcion): void {
    if (!$cond) throw new RuntimeException('INSCRIPCION_V3: '.$descripcion);
}
function rechazarInscripcion(callable $run,string $detalle): void {
    try {$run();throw new RuntimeException('ACEPTADO INCORRECTAMENTE: '.$detalle);}
    catch (DomainException $ok) {}
}
putenv('IMPE_VENTAS_V3_UI=PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111');
putenv('IMPE_VENTAS_V3_UI_ASESORES=8');
$_SESSION=[
    'usuario_id'=>8,'rol_id'=>3,
    'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']
];
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'',
    '',(int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_test_inscripcion_'.bin2hex(random_bytes(5));
$created=false;
try {
    $db->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $created=true;
    $db->select_db($name);
    $ddl=[
      "CREATE TABLE prospectos_comerciales(
        id BIGINT UNSIGNED PRIMARY KEY, correo VARCHAR(190) NOT NULL,
        telefono_normalizado VARCHAR(24) NULL) ENGINE=InnoDB",
      "CREATE TABLE prospecto_asignaciones(
        id BIGINT UNSIGNED PRIMARY KEY, prospecto_id BIGINT UNSIGNED NOT NULL,
        asesor_id INT NOT NULL,vigente TINYINT NOT NULL,
        asignado_at DATETIME NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE formulario_inscripciones(
        id INT UNSIGNED PRIMARY KEY, movil VARCHAR(10) NOT NULL,
        correo VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE prospecto_embudo_etapas(
        asignacion_id BIGINT UNSIGNED PRIMARY KEY,etapa VARCHAR(30) NOT NULL,
        cambiado_por INT NOT NULL,actualizado_at DATETIME NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE prospecto_embudo_movimientos(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        asignacion_id BIGINT UNSIGNED NOT NULL,etapa_anterior VARCHAR(30),
        etapa_nueva VARCHAR(30),motivo VARCHAR(190),
        usuario_id INT,creado_at DATETIME NOT NULL) ENGINE=InnoDB"
    ];
    foreach ($ddl as $sql)$db->query($sql);
    $sub=file_get_contents(__DIR__.'/../database/migrations/2026_11_11_ventas_subetapas_comerciales_propuesta.sql');
    $sub=preg_replace('/^\\s*--.*$/m','',$sub);
    foreach (explode(';',$sub) as $sql)if(trim($sql)!=='')$db->query($sql);
    $migration=file_get_contents(__DIR__.'/../database/migrations/2026_11_12_ventas_inscripcion_v3_evidencia.sql');
    $migration=preg_replace('/^\\s*--.*$/m','',$migration);
    foreach (explode(';',$migration) as $sql)if(trim($sql)!=='')$db->query($sql);

    $db->query("INSERT INTO prospectos_comerciales VALUES
        (1,'Asesor@impe.test','+527771234567'),
        (2,'bloqueado@impe.test','+527771115555'),
        (3,'ajeno@impe.test','+527771116666'),
        (4,'asesor@impe.test','+527771234567'),
        (5,'sin.formulario@impe.test','+527771114444'),
        (6,'ambiguo@impe.test','+527771117777'),
        (7,'fase.nueva@impe.test','+527771118888')");
    $db->query("INSERT INTO prospecto_asignaciones VALUES
      (101,1,8,1,UTC_TIMESTAMP()-INTERVAL 1 DAY),
      (102,2,8,1,UTC_TIMESTAMP()-INTERVAL 1 DAY),
      (103,3,9,1,UTC_TIMESTAMP()-INTERVAL 1 DAY),
      (104,4,8,1,UTC_TIMESTAMP()-INTERVAL 1 DAY),
      (105,5,8,1,UTC_TIMESTAMP()-INTERVAL 1 DAY),
      (106,6,8,1,UTC_TIMESTAMP()-INTERVAL 1 DAY),
      (107,7,8,1,UTC_TIMESTAMP()-INTERVAL 1 DAY)");
    $db->query("INSERT INTO prospecto_embudo_etapas VALUES
      (101,'POTENCIAL',8,UTC_TIMESTAMP()),
      (102,'POTENCIAL',8,UTC_TIMESTAMP()),
      (103,'POTENCIAL',9,UTC_TIMESTAMP()),
      (104,'POTENCIAL',8,UTC_TIMESTAMP()),
      (105,'POTENCIAL',8,UTC_TIMESTAMP()),
      (106,'POTENCIAL',8,UTC_TIMESTAMP()),
      (107,'NUEVO',8,UTC_TIMESTAMP())");
    $db->query("INSERT INTO prospecto_embudo_subetapas VALUES
      (102,'NO_CONTACTAR',8,UTC_TIMESTAMP(),1)");
    $db->query("INSERT INTO formulario_inscripciones VALUES
      (501,'7771234567','asesor@impe.test',UTC_TIMESTAMP()),
      (502,'7771115555','bloqueado@impe.test',UTC_TIMESTAMP()),
      (503,'7771116666','ajeno@impe.test',UTC_TIMESTAMP()),
      (504,'7771117777','ambiguo@impe.test',UTC_TIMESTAMP()),
      (505,'7771117777','ambiguo@impe.test',UTC_TIMESTAMP()),
      (506,'7771114444','wrong@impe.test',UTC_TIMESTAMP()),
      (507,'7771118888','fase.nueva@impe.test',UTC_TIMESTAMP())");
    $svc=new EmbudoComercialV3InscripcionService($db);
    $candidate=$svc->candidato(8,101);
    probarInscripcion($candidate['estado']==='CANDIDATA' &&
        $candidate['formulario_id']===501,'evidencia exacta de teléfono y correo');
    probarInscripcion($svc->candidato(8,106)['estado']==='REVISION_INSTITUCIONAL',
        'solicitudes ambiguas requieren conciliación');
    probarInscripcion($svc->candidato(8,105)['estado']==='SIN_SOLICITUD',
        'correo distinto no vincula por teléfono');

    rechazarInscripcion(static function()use($svc){
        $svc->candidato(8,103);
    },'otro asesor nunca consulta la solicitud');
    rechazarInscripcion(static function()use($svc){
        $svc->candidato(8,102);
    },'No contactar no puede iniciar trámite');
    rechazarInscripcion(static function()use($svc){
        $svc->candidato(8,107);
    },'Nuevos no puede simular inscripción');
    rechazarInscripcion(static function()use($svc){
        $svc->vincular(8,101,501,'Validación con evidencia suficiente',false);
    },'confirmación obligatoria');
    rechazarInscripcion(static function()use($svc){
        $svc->vincular(8,101,506,'Intento usando correo de otra solicitud',true);
    },'no admitir otro formulario solo por teléfono');

    $result=$svc->vincular(8,101,501,
        'Solicitud institucional recibida y verificada',true);
    probarInscripcion($result['etapa']==='PROCESO_INSCRIPCION' &&
        $result['subetapa']==='SOLICITUD_INICIADA' &&
        $result['formulario_inscripcion_id']===501,
        'transición acreditada con ID institucional');
    $estado=$db->query("SELECT e.etapa,s.subetapa
       FROM prospecto_embudo_etapas e
       JOIN prospecto_embudo_subetapas s ON s.asignacion_id=e.asignacion_id
       WHERE e.asignacion_id=101")->fetch_assoc();
    probarInscripcion($estado['etapa']==='PROCESO_INSCRIPCION' &&
        $estado['subetapa']==='SOLICITUD_INICIADA','estado y subetapa consistentes');
    $vinculo=$db->query('SELECT * FROM prospecto_embudo_inscripciones_v3 WHERE asignacion_id=101')
        ->fetch_assoc();
    probarInscripcion((int)$vinculo['prospecto_id']===1 &&
        (int)$vinculo['formulario_inscripcion_id']===501 &&
        $vinculo['evidencia_tipo']==='FORMULARIO_INSCRIPCION',
        'relación explícita entre identidad y formulario');
    $n=(int)$db->query("SELECT COUNT(*) total FROM prospecto_embudo_movimientos
        WHERE asignacion_id=101")->fetch_assoc()['total'];
    $h=(int)$db->query("SELECT COUNT(*) total FROM prospecto_embudo_subetapas_historial
        WHERE asignacion_id=101")->fetch_assoc()['total'];
    probarInscripcion($n===1&&$h===1,'movimiento y subetapa auditados una vez');
    rechazarInscripcion(static function()use($svc){
        $svc->vincular(8,101,501,'Doble envío de la misma inscripción',true);
    },'no duplicar por repetir confirmación');
    rechazarInscripcion(static function()use($svc){
        $svc->vincular(8,104,501,'Intento con otra asignación idéntica',true);
    },'un formulario no puede vincularse a dos prospectos');
    probarInscripcion($db->query("SELECT etapa FROM prospecto_embudo_etapas
         WHERE asignacion_id=104")->fetch_assoc()['etapa']==='POTENCIAL',
        'un conflicto hace rollback completo');

    echo "CHECK_OK: solicitud institucional real, identidad, permisos, No contactar, auditoría, unicidad y rollback.\n";
} finally {
    if($created)$db->query('DROP DATABASE '.$name);
    $db->close();
}
