<?php
/**
 * Integración comercial V3 con PBX falso (MySQL/MariaDB desechable).
 * NUNCA conecta a la base real ni a Zadarma.
 */
if (getenv('IMPE_V3_PILOTO_TEST_DB')!=='YES') {
    fwrite(STDERR,"BLOQUEADO: exige base temporal aislada.\n");exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../app/services/EmbudoComercialResultadoBridge.php';
require_once __DIR__.'/../app/services/EmbudoComercialV3PilotoControlado.php';
require_once __DIR__.'/../app/services/EmbudoComercialMovimientoService.php';
function validarV3($ok,$msg) {
    if(!$ok)throw new RuntimeException('FALLO V3 PILOTO: '.$msg);
}
function cantidad(mysqli $db,string $tabla,string $where='1=1'): int {
    return (int)$db->query('SELECT COUNT(*) n FROM '.$tabla.' WHERE '.$where)
        ->fetch_assoc()['n'];
}
function actual(mysqli $db,int $asig): string {
    $x=$db->query('SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id='.$asig);
    return (string)($x->fetch_assoc()['etapa']??'NUEVO');
}
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'',
    '',(int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_v3_piloto_'.bin2hex(random_bytes(5));$created=false;
try {
    $db->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $created=true;$db->select_db($name);
    foreach([
        'CREATE TABLE prospectos_comerciales(
            id BIGINT UNSIGNED PRIMARY KEY,
            telefono_normalizado VARCHAR(40) NOT NULL,pais_nombre VARCHAR(50)
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_asignaciones(
            id BIGINT UNSIGNED PRIMARY KEY,prospecto_id BIGINT UNSIGNED NOT NULL,
            asesor_id INT NOT NULL,vigente TINYINT NOT NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_etapas(
            asignacion_id BIGINT UNSIGNED PRIMARY KEY,
            etapa VARCHAR(40),cambiado_por INT,actualizado_at DATETIME
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_llamadas(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asignacion_id BIGINT UNSIGNED NOT NULL,usuario_id INT NOT NULL,
            extension VARCHAR(20) NOT NULL,pbx_call_id VARCHAR(100) NOT NULL,
            resultado VARCHAR(36) NOT NULL,creado_at DATETIME NOT NULL,
            UNIQUE KEY uq_asignacion_pbx(extension,pbx_call_id)
        ) ENGINE=InnoDB',
        'CREATE TABLE telefonia_ventas_llamadas(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,
            fecha DATETIME NOT NULL,direccion VARCHAR(12) NOT NULL,
            UNIQUE KEY uq_pbx(extension,pbx_call_id)
        ) ENGINE=InnoDB',
        'CREATE TABLE telefonia_ventas_resultados(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,resultado VARCHAR(36) NOT NULL,
            proxima_accion_at DATETIME NULL,
            UNIQUE KEY uq_resultado(extension,pbx_call_id)
        ) ENGINE=InnoDB',
        'CREATE TABLE telefonia_ventas_resultado_detalles(
            resultado_id BIGINT UNSIGNED PRIMARY KEY,
            catalogo_version VARCHAR(10) NOT NULL,
            contacto_antes_corte VARCHAR(2) NOT NULL DEFAULT \'\'
        ) ENGINE=InnoDB',
        'CREATE TABLE telefonia_ventas_resultado_selecciones(
            resultado_id BIGINT UNSIGNED NOT NULL,
            codigo VARCHAR(36) NOT NULL,posicion INT UNSIGNED NOT NULL,
            PRIMARY KEY(resultado_id,codigo),
            UNIQUE KEY uq_seleccion_pos(resultado_id,posicion)
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_movimientos(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asignacion_id BIGINT UNSIGNED NOT NULL,
            etapa_anterior VARCHAR(40),etapa_nueva VARCHAR(40),
            motivo VARCHAR(190),usuario_id INT,creado_at DATETIME
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_agenda(
            asignacion_id BIGINT UNSIGNED PRIMARY KEY,
            proxima_accion_at DATETIME NULL,revision_fecha DATE NULL,
            actualizado_at DATETIME NULL,actualizado_por INT NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_subetapas(
            asignacion_id BIGINT UNSIGNED PRIMARY KEY,
            subetapa VARCHAR(40) NOT NULL,actualizado_por INT,
            actualizado_at DATETIME NOT NULL,version INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_subetapas_historial(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asignacion_id BIGINT UNSIGNED NOT NULL,
            etapa_en_evento VARCHAR(40) NOT NULL,
            subetapa_anterior VARCHAR(40),
            subetapa_nueva VARCHAR(40),
            motivo VARCHAR(190),origen VARCHAR(20),
            usuario_id INT,creado_at DATETIME
        ) ENGINE=InnoDB'
    ] as $sql)$db->query($sql);
    foreach ([101=>8,102=>9,103=>8,104=>8,105=>8] as $asig=>$asesor) {
        $db->query("INSERT INTO prospectos_comerciales VALUES
            ($asig,'+527771234567','México')");
        $db->query("INSERT INTO prospecto_asignaciones VALUES
            ($asig,$asig,$asesor,1)");
    }
    $db->query("INSERT INTO prospecto_embudo_etapas
        VALUES(103,'POTENCIAL',8,UTC_TIMESTAMP()),
              (104,'INTERESADO',8,UTC_TIMESTAMP())");
    $db->query("INSERT INTO prospecto_embudo_subetapas
        VALUES(103,'DOCUMENTACION_LISTA',8,UTC_TIMESTAMP(),1)");
    $_SESSION=['usuario_id'=>8,'rol_id'=>3,'permisos'=>[
        'ventas.embudo.ver','ventas.embudo.mover',
        'ventas.recuperacion.gestionar','ventas.descartados.gestionar'
    ]];

    $add=function(int $asig,int $user,string $pbx,string $code,
        string $date,string $direction='Saliente',string $version='')use($db) {
        $ext=$user===9?'151':'150';
        $s=$db->prepare('INSERT INTO telefonia_ventas_llamadas
            (usuario_id,extension,pbx_call_id,fecha,direccion)
            VALUES(?,?,?,?,?)');
        $s->bind_param('issss',$user,$ext,$pbx,$date,$direction);
        $s->execute();$s->close();
        $s=$db->prepare('INSERT INTO telefonia_ventas_resultados
            (usuario_id,extension,pbx_call_id,resultado) VALUES(?,?,?,?)');
        $s->bind_param('isss',$user,$ext,$pbx,$code);
        $s->execute();$id=(int)$db->insert_id;$s->close();
        if($version!==''){
            $s=$db->prepare('INSERT INTO telefonia_ventas_resultado_detalles
                (resultado_id,catalogo_version,contacto_antes_corte)
                VALUES(?,?,?)');
            $cut='';
            $s->bind_param('iss',$id,$version,$cut);$s->execute();$s->close();
            $s=$db->prepare('INSERT INTO telefonia_ventas_resultado_selecciones
                VALUES(?,?,0)');
            $s->bind_param('is',$id,$code);$s->execute();$s->close();
        }
        return [
            'extension'=>$ext,'pbx_call_id'=>$pbx,
            'numero'=>'7771234567',
            'direccion'=>$direction,'finalizada'=>true
        ];
    };
    $apply=function(int $asig,int $user,array $call,string $result)use($db) {
        $_SESSION['usuario_id']=$user;
        $db->begin_transaction();
        try {
            $v=(new EmbudoComercialResultadoBridge($db))->aplicar(
                $user,$asig,$call,$result,null,false
            );
            $db->commit();
            return $v;
        }catch(Throwable $e){
            $db->rollback();throw $e;
        }
    };

    putenv('IMPE_VENTAS_V3_PILOTO');
    putenv('IMPE_VENTAS_V3_PILOTO_ASESORES');
    validarV3(!EmbudoComercialV3PilotoControlado::habilitadoPara(8),
        'sin configuración, piloto OFF');
    putenv('IMPE_VENTAS_V3_PILOTO_ASESORES=8');
    validarV3(!EmbudoComercialV3PilotoControlado::habilitadoPara(8),
        'asesores listados no activan solo el piloto');
    putenv('IMPE_VENTAS_V3_PILOTO=incorrecto');
    validarV3(!EmbudoComercialV3PilotoControlado::habilitadoPara(8),
        'token inexacto no activa');
    putenv('IMPE_VENTAS_V3_PILOTO=AUTORIZADO_PILOTO_V3_TRES_INTENTOS_IMPE_20261111');
    putenv('IMPE_VENTAS_V3_PILOTO_ASESORES=*');
    validarV3(!EmbudoComercialV3PilotoControlado::habilitadoPara(8),
        'comodines prohibidos');
    putenv('IMPE_VENTAS_V3_PILOTO_ASESORES=8,999');
    validarV3(EmbudoComercialV3PilotoControlado::habilitadoPara(8),
        'lista autorizada válida');
    validarV3(!EmbudoComercialV3PilotoControlado::habilitadoPara(9),
        'segundo asesor queda fuera');

    // En el piloto, el arrastre antiguo se bloquea únicamente para el
    // asesor seleccionado; sin iniciar siquiera una transacción.
    $mover=new EmbudoComercialMovimientoService($db);
    try {
        $mover->moverManual(8,101,'NUEVO','RECUPERACION',
            'Llamadas sin contacto');
        throw new RuntimeException('Se admitió arrastre viejo durante piloto.');
    }catch(DomainException $ok){
        validarV3(strpos($ok->getMessage(),'piloto')!==false,
            'rechazo de arrastre debe explicar motivo');
    }

    // Tres llamadas del mismo asesor y asignación en el mismo día local.
    $calls=[];
    $codes=['BUZON_VOZ','SIN_RESPUESTA','OCUPADO'];
    for($i=0;$i<3;$i++){
        $pbx='out_81'.($i+1).'abc123';
        $calls[$i]=$add(101,8,$pbx,$codes[$i],
            '2026-11-11 '.(9+$i*3).':00:00');
        $x=$apply(101,8,$calls[$i],$codes[$i]);
        validarV3(!empty($x['v3_piloto']),'aplicación desde puente nuevo');
        validarV3(($x['intentos_jornada']??-1)===$i+1,
            'contador cronológico de PBX distintos '.($i+1).'/3');
        if($i<2){
            validarV3(!$x['movida'] && actual($db,101)==='NUEVO',
                'no se debe mover en intento 1/3 ni 2/3');
            validarV3($x['subetapa']==='INTENTO_'.($i+1),
                'subetapa de intento '.$i);
        }else{
            validarV3($x['movida'] && actual($db,101)==='RECUPERACION',
                'tercer PBX mueve a Recuperación');
            validarV3($x['subetapa']==='PENDIENTE_REVISION',
                'subetapa Recuperación');
        }
    }
    validarV3(cantidad($db,'prospecto_embudo_llamadas','asignacion_id=101')===3,
        'tres vínculos comerciales, cero duplicados');
    validarV3(cantidad($db,'prospecto_embudo_movimientos','asignacion_id=101')===1,
        'un único movimiento en tres intentos');
    validarV3(cantidad($db,'prospecto_embudo_subetapas_historial','asignacion_id=101')===3,
        'historial de subetapas 1/3, 2/3 y Recuperación');
    $rev=$db->query('SELECT revision_fecha FROM prospecto_embudo_agenda
        WHERE asignacion_id=101')->fetch_assoc()['revision_fecha']??null;
    validarV3($rev==='2026-11-12',
        'revisión local al día siguiente del tercer PBX');

    try{
        $apply(101,8,$calls[2],$codes[2]);
        throw new RuntimeException('Se guardó dos veces el mismo PBX.');
    }catch(DomainException $e){
        validarV3(strpos($e->getMessage(),'vinculado')!==false,
            'duplicado PBX rechaza y mantiene historial');
    }
    validarV3(cantidad($db,'prospecto_embudo_movimientos','asignacion_id=101')===1,
        'resultado duplicado nunca crea otro movimiento');

    // Otra cuenta, aunque tenga el mismo token global, usa el
    // bridge V1 SIN modificación; su resultado inicial sigue actual.
    $_SESSION=['usuario_id'=>9,'rol_id'=>3,'permisos'=>[
        'ventas.embudo.ver','ventas.embudo.mover',
        'ventas.recuperacion.gestionar','ventas.descartados.gestionar'
    ]];
    $legacyCall=$add(102,9,'out_91abc123','BUZON_VOZ','2026-11-11 10:00:00');
    $legacyResult=$apply(102,9,$legacyCall,'BUZON_VOZ');
    validarV3(empty($legacyResult['v3_piloto']) &&
        actual($db,102)==='RECUPERACION',
        'asesor no piloto conserva el comportamiento existente sin cambio');

    $_SESSION=['usuario_id'=>8,'rol_id'=>3,'permisos'=>[
        'ventas.embudo.ver','ventas.embudo.mover',
        'ventas.recuperacion.gestionar','ventas.descartados.gestionar'
    ]];
    // Potencial con documentos listos NO es degradado aun si recibe
    // tres llamadas sin respuesta posteriores.
    for($i=0;$i<3;$i++){
        $call=$add(103,8,'out_83'.$i.'abc123','SIN_RESPUESTA',
            '2026-11-11 '.(10+$i).':00:00');
        $res=$apply(103,8,$call,'SIN_RESPUESTA');
        validarV3(!$res['movida'] && actual($db,103)==='POTENCIAL',
            'Potenciales no se degradan por sin respuesta');
    }
    $sub=$db->query('SELECT subetapa,version FROM prospecto_embudo_subetapas
        WHERE asignacion_id=103')->fetch_assoc();
    validarV3($sub['subetapa']==='DOCUMENTACION_LISTA' &&
        (int)$sub['version']===1,
        'subetapa de un potencial no se pierde ni reescribe');
    validarV3(cantidad($db,'prospecto_embudo_movimientos','asignacion_id=103')===0,
        'sin retrocesos en bitácora');

    $l=$add(104,8,'out_84abc1234','PROSPECTO_POTENCIAL',
        '2026-11-11 13:00:00');
    $res=$apply(104,8,$l,'PROSPECTO_POTENCIAL');
    validarV3(actual($db,104)==='INTERESADO' && !$res['movida'],
        'Interesado histórico no se convierte a Potencial sin requisitos');
    validarV3(cantidad($db,'prospecto_embudo_subetapas','asignacion_id=104')===0,
        'sin subetapa de calificación inventada');

    $conv=$add(105,8,'out_85abc1234','CONVERSACION_PERSONA',
        '2026-11-11 15:00:00');
    $res=$apply(105,8,$conv,'CONVERSACION_PERSONA');
    validarV3(actual($db,105)==='CONTACTADO' && $res['movida'] &&
        $res['subetapa']==='PRIMER_CONTACTO',
        'conversación acreditada mueve Nuevos a Contactados');

    // Teléfono distinto es rechazado antes del vínculo; la llamada
    // original ya está persistida y no puede borrarse por fallo comercial.
    $bad=$add(105,8,'out_85abc2234','SIN_RESPUESTA',
        '2026-11-11 16:00:00');
    $bad['numero']='7770009999';
    try {
        $apply(105,8,$bad,'SIN_RESPUESTA');
        throw new RuntimeException('Vínculo con teléfono distinto permitido');
    }catch(DomainException $ok){}
    validarV3(cantidad($db,'prospecto_embudo_llamadas',"pbx_call_id='out_85abc2234'")===0,
        'el vínculo incorrecto no entra al embudo');
    validarV3(cantidad($db,'telefonia_ventas_resultados',"pbx_call_id='out_85abc2234'")===1,
        'el registro telefónico previamente guardado nunca se pierde');

    // Desactivar de nuevo preserva todo el camino V1, sin modificar los
    // permisos de las sesiones de ningún asesor.
    putenv('IMPE_VENTAS_V3_PILOTO');
    validarV3(!EmbudoComercialV3PilotoControlado::habilitadoPara(8),
        'desactivación inmediata por bandera');
    echo "CHECK OK: flags OFF, lista autorizada, 1/3-3/3 real, un movimiento, historial, próxima revisión, otros asesores intactos, legados, Potenciales, duplicados y resultado PBX inmutable. MySQL temporal.\n";
}finally{
    putenv('IMPE_VENTAS_V3_PILOTO');
    putenv('IMPE_VENTAS_V3_PILOTO_ASESORES');
    if($created)$db->query('DROP DATABASE '.$name);
    $db->close();
}
