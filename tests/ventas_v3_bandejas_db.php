<?php
/**
 * SOLO MYSQL/MARIADB AISLADOS. Crea y elimina una base de pruebas propia.
 * Prohibido ejecutar contra Hostinger o una base preexistente.
 */
if (getenv('IMPE_V3_BANDEJAS_TEST_DB')!=='YES') {
    fwrite(STDERR,"BLOQUEADO: falta IMPE_V3_BANDEJAS_TEST_DB=YES.\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../app/services/EmbudoComercialV3BandejasService.php';
function revisarBandeja(bool $ok,string $msg): void {
    if (!$ok) throw new RuntimeException('BANDEJAS_V3: '.$msg);
}
function rechazarBandeja(callable $op,string $msg): void {
    try {$op();throw new RuntimeException('ACEPTADO INDEBIDAMENTE: '.$msg);}
    catch(DomainException $esperado) {}
}
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'',
    '',(int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_test_bandejas_'.bin2hex(random_bytes(6));
$created=false;
try {
    $db->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $created=true;$db->select_db($name);
    foreach([
      "CREATE TABLE prospecto_asignaciones(id BIGINT UNSIGNED PRIMARY KEY,
        asesor_id INT NOT NULL,vigente TINYINT NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE prospecto_embudo_etapas(
        asignacion_id BIGINT UNSIGNED PRIMARY KEY,etapa VARCHAR(40) NOT NULL,
        cambiado_por INT NULL,actualizado_at DATETIME NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE prospecto_embudo_movimientos(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,asignacion_id BIGINT UNSIGNED NOT NULL,
        etapa_anterior VARCHAR(40) NOT NULL,etapa_nueva VARCHAR(40) NOT NULL,
        motivo VARCHAR(190) NOT NULL,usuario_id INT NOT NULL,creado_at DATETIME NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE prospecto_embudo_agenda(
        asignacion_id BIGINT UNSIGNED PRIMARY KEY,proxima_accion_at DATETIME NULL,
        revision_fecha DATE NULL,actualizado_at DATETIME NOT NULL,
        actualizado_por INT NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE prospecto_embudo_llamadas(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        asignacion_id BIGINT UNSIGNED NOT NULL,usuario_id INT NOT NULL,
        extension VARCHAR(20) NOT NULL,pbx_call_id VARCHAR(100) NOT NULL,
        resultado VARCHAR(36) NOT NULL,creado_at DATETIME NOT NULL,
        UNIQUE KEY uq_embudo_ref(extension,pbx_call_id)) ENGINE=InnoDB",
      "CREATE TABLE telefonia_ventas_llamadas(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,
        pbx_call_id VARCHAR(100) NOT NULL,direccion VARCHAR(10) NOT NULL,
        numero VARCHAR(40) NOT NULL,fecha DATETIME NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE telefonia_ventas_resultados(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,
        pbx_call_id VARCHAR(100) NOT NULL,resultado VARCHAR(36) NOT NULL) ENGINE=InnoDB",
      "CREATE TABLE telefonia_ventas_resultado_detalles(
        resultado_id BIGINT UNSIGNED PRIMARY KEY,catalogo_version VARCHAR(10) NOT NULL,
        contacto_antes_corte VARCHAR(2) NULL) ENGINE=InnoDB",
      "CREATE TABLE telefonia_ventas_resultado_selecciones(
        resultado_id BIGINT UNSIGNED NOT NULL,codigo VARCHAR(36) NOT NULL,
        posicion TINYINT NOT NULL,PRIMARY KEY(resultado_id,codigo)) ENGINE=InnoDB"
    ] as $sql) $db->query($sql);

    // Usar migración V3 real de subetapas para verificar compatibilidad.
    $migration=file_get_contents(__DIR__.'/../database/migrations/2026_11_11_ventas_subetapas_comerciales_propuesta.sql');
    $clean=preg_replace('/^\s*--.*$/m','',$migration);
    foreach(explode(';',$clean) as $statement)if(trim($statement)!=='')$db->query($statement);

    $_SESSION=['usuario_id'=>8,'rol_id'=>3,
        'permisos'=>[
            'ventas.embudo.ver','ventas.embudo.mover',
            'ventas.recuperacion.ver','ventas.recuperacion.gestionar',
            'ventas.descartados.ver','ventas.descartados.gestionar'
        ]];
    $db->query("INSERT INTO prospecto_asignaciones VALUES
        (101,8,1),(102,8,1),(103,8,1),(104,9,1),(105,8,0),(106,8,1)");
    $db->query("INSERT INTO prospecto_embudo_etapas
        (asignacion_id,etapa,cambiado_por,actualizado_at) VALUES
        (102,'RECUPERACION',8,UTC_TIMESTAMP()),
        (106,'PROCESO_INSCRIPCION',8,UTC_TIMESTAMP())");
    $db->query("INSERT INTO prospecto_embudo_agenda VALUES
        (101,'2026-12-15 18:00:00',NULL,UTC_TIMESTAMP(),8)");
    $svc=new EmbudoComercialV3BandejasService($db);
    $dia=(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))
        ->format('Y-m-d');
    $ayer=(new DateTimeImmutable('yesterday',new DateTimeZone('America/Mexico_City')))
        ->format('Y-m-d');
    revisarBandeja(EmbudoComercialV3BandejasService::validarJornada($dia)===$dia,
        'jornada local válida');
    rechazarBandeja(static function(){EmbudoComercialV3BandejasService::validarJornada('2026-02-30');},
        'fecha inexistente');
    $add=function(int $asig,int $usuario,string $ref,string $resultado,string $fecha,
        string $direccion='Saliente') use($db){
        $ext=$usuario===9?'151':'150';
        $s=$db->prepare("INSERT INTO telefonia_ventas_llamadas
          (usuario_id,extension,pbx_call_id,direccion,numero,fecha)
          VALUES (?,?,?,?,?,?)");
        $num='7771234567';
        $s->bind_param('isssss',$usuario,$ext,$ref,$direccion,$num,$fecha);
        $s->execute();$s->close();
        $s=$db->prepare("INSERT INTO telefonia_ventas_resultados
          (usuario_id,extension,pbx_call_id,resultado) VALUES (?,?,?,?)");
        $s->bind_param('isss',$usuario,$ext,$ref,$resultado);
        $s->execute();$s->close();
        $s=$db->prepare("INSERT INTO prospecto_embudo_llamadas
          (asignacion_id,usuario_id,extension,pbx_call_id,resultado,creado_at)
          VALUES (?,?,?,?,?,UTC_TIMESTAMP())");
        $s->bind_param('iisss',$asig,$usuario,$ext,$ref,$resultado);
        $s->execute();$s->close();
    };
    $f1=$ayer.' 09:00:00';
    $f2=$ayer.' 12:00:00';
    $f3=$ayer.' 16:00:00';
    $add(101,8,'out_test101a','SIN_RESPUESTA',$f1);
    rechazarBandeja(static function()use($svc,$ayer){
        $svc->ingresarRecuperacion(8,101,'NUEVO',$ayer,
            'Se revisó una llamada sin respuesta',true);
    },'uno no basta');
    $add(101,8,'out_test101b','BUZON_VOZ',$f2);
    rechazarBandeja(static function()use($svc,$ayer){
        $svc->ingresarRecuperacion(8,101,'NUEVO',$ayer,
            'Se revisaron dos llamadas sin respuesta',true);
    },'dos no bastan');
    $add(101,8,'out_test101c','OCUPADO',$f3);
    rechazarBandeja(static function()use($svc,$ayer){
        $svc->ingresarRecuperacion(8,101,'NUEVO',$ayer,
            'Se revisaron tres llamadas acreditadas',false);
    },'confirmación expresa');
    $id=$svc->ingresarRecuperacion(8,101,'NUEVO',$ayer,
        'Tres intentos sin conversación; revisar mañana',true);
    revisarBandeja($id['etapa']==='RECUPERACION' && $id['intentos_confirmados']===3,
        'tercer intento requiere revisión manual');
    $st=$db->query("SELECT e.etapa,ag.proxima_accion_at,ag.revision_fecha,
      sub.subetapa FROM prospecto_embudo_etapas e
      LEFT JOIN prospecto_embudo_agenda ag ON ag.asignacion_id=e.asignacion_id
      LEFT JOIN prospecto_embudo_subetapas sub ON sub.asignacion_id=e.asignacion_id
      WHERE e.asignacion_id=101")->fetch_assoc();
    revisarBandeja($st['etapa']==='RECUPERACION' &&
        $st['proxima_accion_at']==='2026-12-15 18:00:00' &&
        $st['subetapa']==='PENDIENTE_REVISION' &&
        $st['revision_fecha']!==null,
        'conserva agenda anterior, crea revisión y subetapa');
    rechazarBandeja(static function()use($svc,$ayer){
        $svc->ingresarRecuperacion(8,101,'NUEVO',$ayer,
            'Duplicado por concurrencia de etapa',true);
    },'segunda escritura de Recuperación no permitida');
    rechazarBandeja(static function()use($svc){
        $svc->descartar(8,101,'RECUPERACION','NO_CONTACTAR',
            'No se contactará a este prospecto',true);
    },'NO_CONTACTAR no disponible sin bloqueo omnicanal');
    rechazarBandeja(static function()use($svc){
        $svc->descartar(8,101,'RECUPERACION','NO_INTERESADO',
            'Sin prueba telefónica suficiente',true);
    },'descarte requiere resultado PBX exacto');

    // Descarte confirmado a partir de resultado humano reconocido, nunca
    // desde una casilla simple del navegador.
    $add(101,8,'out_test101d','NO_INTERESADO',$dia.' 11:05:00');
    $closed=$svc->descartar(8,101,'RECUPERACION','NO_INTERESADO',
        'El prospecto rechazó expresamente la propuesta',true);
    revisarBandeja($closed['etapa']==='DESCARTADO','descarte acreditado');
    rechazarBandeja(static function()use($svc){
        $svc->reactivar(8,101,'DESCARTADO','Quiero activarlo otra vez',true);
    },'descartado no se reactiva');

    // Casos separados: número incorrecto y protección de proceso inscripción.
    $add(103,8,'out_test103a','NUMERO_INEXISTENTE',$dia.' 10:00:00');
    $closed=$svc->descartar(8,103,'NUEVO','NUMERO_INVALIDO',
        'El proveedor confirma que el número no existe',true);
    revisarBandeja($closed['subetapa']==='NUMERO_INVALIDO','número acreditado');
    rechazarBandeja(static function()use($svc){
        $svc->descartar(8,106,'PROCESO_INSCRIPCION','NO_INTERESADO',
            'La inscripción no puede cerrarse aquí',true);
    },'inscripción protegida');
    rechazarBandeja(static function()use($svc,$ayer){
        $svc->ingresarRecuperacion(8,104,'NUEVO',$ayer,
            'Asignación ajena no permitida',true);
    },'no usar asignación ajena');
    $_SESSION['permisos']=['ventas.embudo.ver','ventas.embudo.mover'];
    rechazarBandeja(static function()use($svc,$ayer){
        $svc->ingresarRecuperacion(8,102,'NUEVO',$ayer,
            'Permiso retirado de Recuperación',true);
    },'permisos reales');
    $_SESSION['permisos'][]='ventas.recuperacion.ver';
    $_SESSION['permisos'][]='ventas.recuperacion.gestionar';
    $_SESSION['permisos'][]='ventas.descartados.ver';
    $_SESSION['permisos'][]='ventas.descartados.gestionar';

    // Reactivación: una conversación guardada ANTES de ingresar a
    // Recuperación no autoriza salir de esa bandeja.
    $db->query("INSERT INTO prospecto_embudo_movimientos
       (asignacion_id,etapa_anterior,etapa_nueva,motivo,usuario_id,creado_at)
       VALUES (102,'NUEVO','RECUPERACION','Revisión acreditada',8,UTC_TIMESTAMP())");
    $add(102,8,'out_test102old','CONVERSACION_PERSONA',$ayer.' 09:05:00');
    rechazarBandeja(static function()use($svc){
        $svc->reactivar(8,102,'RECUPERACION',
            'Una conversación histórica no debe bastar',true);
    },'no reactivar con llamada antigua');
    $futura=(new DateTimeImmutable('now',
        new DateTimeZone('America/Mexico_City')))->modify('+3 minutes')
        ->format('Y-m-d H:i:s');
    $add(102,8,'out_test102new','CONVERSACION_PERSONA',$futura);
    // Aunque la llamada sea posterior, una subetapa NO_CONTACTAR
    // impide reactivar desde el propio servicio, no solo desde el botón.
    $db->query("INSERT INTO prospecto_embudo_subetapas
        (asignacion_id,subetapa,actualizado_por,actualizado_at,version)
        VALUES (102,'NO_CONTACTAR',8,UTC_TIMESTAMP(),1)");
    rechazarBandeja(static function()use($svc){
        $svc->reactivar(8,102,'RECUPERACION',
            'Prueba de bloqueo con conversación posterior',true);
    },'No contactar prevalece aun con evidencia PBX');
    $db->query("DELETE FROM prospecto_embudo_subetapas WHERE asignacion_id=102");
    $reabierto=$svc->reactivar(8,102,'RECUPERACION',
        'Conversación acreditada después de Recuperación',true);
    revisarBandeja($reabierto['etapa']==='CONTACTADO','reactivación acreditada');

    $nMov=(int)$db->query("SELECT COUNT(*) total FROM prospecto_embudo_movimientos
        WHERE asignacion_id=101")->fetch_assoc()['total'];
    $nHistory=(int)$db->query("SELECT COUNT(*) total FROM prospecto_embudo_subetapas_historial
        WHERE asignacion_id=101")->fetch_assoc()['total'];
    revisarBandeja($nMov===2 && $nHistory===2,
        'sin duplicados y con ambos historiales auditados');
    echo "CHECK_OK: decisiones V3 con 3 PBX, propietario, permisos, doble envío, agenda, descarte y reactivación posterior.\n";
} finally {
    if($created) {
        $db->query('DROP DATABASE '.$name);
    }
    $db->close();
}
