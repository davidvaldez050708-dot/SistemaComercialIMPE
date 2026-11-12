<?php
/**
 * Visual V3: exclusivamente contra una base nueva temporal de CI.
 * El test nunca conoce el dominio o credenciales de Hostinger.
 */
if(getenv('IMPE_V3_PREVIA_DB_TEST')!=='YES'){
    fwrite(STDERR,"BLOQUEADO: sólo base de pruebas temporal.\n");
    exit(2);
}
require_once __DIR__.'/../app/services/EmbudoComercialRepository.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function okV3p($bool,$msg){if(!$bool)throw new RuntimeException('V3 PREVIA: '.$msg);}
$db=new mysqli(getenv('MYSQL_HOST')?:'127.0.0.1',
    getenv('MYSQL_USER')?:'root',getenv('MYSQL_PASSWORD')?:'',
    '',(int)(getenv('MYSQL_PORT')?:3306));
$db->set_charset('utf8mb4');
$name='impe_v3_preview_'.bin2hex(random_bytes(5));$created=false;
try{
    $db->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4');
    $created=true;$db->select_db($name);
    foreach([
        'CREATE TABLE prospectos_comerciales (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            nombre_completo VARCHAR(255) NOT NULL,
            telefono_original VARCHAR(64),correo VARCHAR(190)
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_asignaciones(
            id BIGINT UNSIGNED PRIMARY KEY,
            prospecto_id BIGINT UNSIGNED NOT NULL, asesor_id INT NOT NULL,
            vigente TINYINT UNSIGNED NOT NULL, asignado_at DATETIME NOT NULL,
            fecha_trabajo DATE NULL,proxima_accion_at DATETIME NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_etapas (
            asignacion_id BIGINT UNSIGNED PRIMARY KEY,
            etapa VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_agenda (
            asignacion_id BIGINT UNSIGNED PRIMARY KEY,
            proxima_accion_at DATETIME NULL,revision_fecha DATE NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_origenes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospecto_id BIGINT UNSIGNED NOT NULL,
            origen_padre_id BIGINT UNSIGNED NULL,
            tipo_origen VARCHAR(30)
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_intereses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            prospecto_id BIGINT UNSIGNED NOT NULL,
            programa_codigo VARCHAR(80) NULL,nombre_original VARCHAR(120)
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_preferencias_contacto (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            origen_id BIGINT UNSIGNED NOT NULL,
            valor_original VARCHAR(120),
            zona_horaria VARCHAR(80),interpretacion_estado VARCHAR(40)
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_subetapas (
            asignacion_id BIGINT UNSIGNED PRIMARY KEY,
            subetapa VARCHAR(40) NOT NULL,
            actualizado_at DATETIME,actualizado_por INT,
            version INT UNSIGNED NOT NULL DEFAULT 1
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_subetapas_historial (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asignacion_id BIGINT UNSIGNED NOT NULL,
            etapa_en_evento VARCHAR(40) NOT NULL
        ) ENGINE=InnoDB',
        'CREATE TABLE prospecto_embudo_llamadas(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asignacion_id BIGINT UNSIGNED NOT NULL,usuario_id INT NOT NULL,
            extension VARCHAR(20) NOT NULL,pbx_call_id VARCHAR(100) NOT NULL,
            resultado VARCHAR(36) NOT NULL,creado_at DATETIME NOT NULL,
            UNIQUE KEY uq_pbx_link(extension,pbx_call_id)
        ) ENGINE=InnoDB',
        'CREATE TABLE telefonia_ventas_llamadas(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,fecha DATETIME NOT NULL,
            direccion VARCHAR(12) NOT NULL,
            UNIQUE KEY uq_tramo(extension,pbx_call_id)
        ) ENGINE=InnoDB',
        'CREATE TABLE telefonia_ventas_resultados(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,extension VARCHAR(20) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL,resultado VARCHAR(36) NOT NULL,
            UNIQUE KEY uq_definitivo(extension,pbx_call_id)
        ) ENGINE=InnoDB',
        'CREATE TABLE telefonia_ventas_resultado_detalles(
            resultado_id BIGINT UNSIGNED PRIMARY KEY,
            catalogo_version VARCHAR(10) NOT NULL,
            contacto_antes_corte VARCHAR(2) NOT NULL DEFAULT \'\'
        ) ENGINE=InnoDB',
        'CREATE TABLE telefonia_ventas_resultado_selecciones(
            resultado_id BIGINT UNSIGNED NOT NULL,
            codigo VARCHAR(36) NOT NULL,
            posicion TINYINT UNSIGNED NOT NULL,
            PRIMARY KEY(resultado_id,codigo)
        ) ENGINE=InnoDB'
    ] as $sql)$db->query($sql);
    $etapas=[
        11=>['INTERESADO',8],12=>['SEGUIMIENTO',8],
        13=>['NUEVO',8],14=>['POTENCIAL',9],
        15=>['PROCESO_INSCRIPCION',9],16=>['PAGO_CONFIRMADO',9],
        17=>['RECUPERACION',8],18=>['DESCARTADO',8],
        19=>['INTERESADO',8],20=>['EN_CONTACTO',8]
    ];
    foreach($etapas as $id=>[$etapa,$uid]){
        $db->query("INSERT INTO prospectos_comerciales VALUES
            ($id,'Prospecto Ficticio $id','7770000000',NULL)");
        $vigente=$id===19?0:1;
        $db->query("INSERT INTO prospecto_asignaciones
            VALUES ($id,$id,$uid,$vigente,'2026-11-11 10:00:00',
                    '2026-11-11',NULL)");
        $db->query("INSERT INTO prospecto_embudo_etapas VALUES ($id,'$etapa')");
    }
    $db->query("INSERT INTO prospecto_embudo_agenda VALUES
        (12,'2026-11-13 17:00:00',NULL)");
    $db->query("INSERT INTO prospecto_embudo_subetapas
        VALUES(12,'CONTACTO_ACORDADO','2026-11-11 12:00:00',8,1)");
    $db->query("INSERT INTO prospecto_intereses(prospecto_id,programa_codigo,nombre_original)
        VALUES(14,'286','Programa Prueba')");
    // Resultados ficticios, siempre ligados a una asignación concreta,
    // fecha ORIGINAL de la llamada en America/Mexico_City.
    $hoy=(new DateTimeImmutable('today',
        new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    $ayer=(new DateTimeImmutable('yesterday',
        new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
    $add=function(int $asig,int $user,string $pbx,string $codigo,
        string $dia,string $hora='10:00:00',string $dir='Saliente',
        string $version='',string $corte='',bool $vinculo=true)use($db){
        $extension=$user===9?'151':'150';
        $fecha=$dia.' '.$hora;
        $s=$db->prepare('INSERT INTO telefonia_ventas_llamadas
            (usuario_id,extension,pbx_call_id,fecha,direccion)
            VALUES(?,?,?,?,?)');
        $s->bind_param('issss',$user,$extension,$pbx,$fecha,$dir);
        $s->execute();$s->close();
        $s=$db->prepare('INSERT INTO telefonia_ventas_resultados
            (usuario_id,extension,pbx_call_id,resultado) VALUES(?,?,?,?)');
        $s->bind_param('isss',$user,$extension,$pbx,$codigo);
        $s->execute();$resultadoId=(int)$db->insert_id;$s->close();
        if($version!==''){
            $s=$db->prepare('INSERT INTO telefonia_ventas_resultado_detalles
                (resultado_id,catalogo_version,contacto_antes_corte)
                VALUES(?,?,?)');
            $s->bind_param('iss',$resultadoId,$version,$corte);
            $s->execute();$s->close();
        }
        if($vinculo){
            $save='2026-11-12 15:00:00';
            $s=$db->prepare('INSERT INTO prospecto_embudo_llamadas
                (asignacion_id,usuario_id,extension,pbx_call_id,resultado,creado_at)
                VALUES(?,?,?,?,?,?)');
            $s->bind_param('iissss',$asig,$user,$extension,$pbx,$codigo,$save);
            $s->execute();$s->close();
        }
    };
    $r=new EmbudoComercialRepository($db);
    $f=EmbudoComercialRepository::filtros([],false);
    $own=$r->listadoV3Preparacion(8,false,$f);
    okV3p($own['total']===6,'seis asignaciones vigentes del asesor');
    okV3p($own['total_activos']===3,
        'revisión legacy, Nuevo y Seguimiento se incluyen fuera de bandejas externas');
    okV3p($own['registros_sin_clasificar']===1,
        'INTERESADO histórico exige revisión sin ascenso');
    okV3p($own['columnas']['CONTACTADO']['total']===0,
        'no inventa conversación de un INTERESADO histórico');
    okV3p($own['columnas']['POTENCIAL']['total']===0,
        'no inventa calificación y documentación');
    okV3p($own['columnas']['SEGUIMIENTO']['total']===1,
        'Seguimiento queda en la etapa correcta');
    okV3p($own['columnas']['SEGUIMIENTO']['registros'][0]['subetapa']==='CONTACTO_ACORDADO',
        'preserva subetapa legítima');
    okV3p($own['columnas']['SEGUIMIENTO']['registros'][0]['proxima_accion_at']==='2026-11-13 17:00:00',
        'preserva próxima tarea');
    okV3p($own['columnas']['REVISION_LEGACY']['registros'][0]['etapa_original']==='INTERESADO',
        'no se sobrescribe código original');
    okV3p($own['externos']['RECUPERACION']===2 &&
        $own['externos']['DESCARTADO']===1,
        'exteriores incluyen EN_CONTACTO legacy sin convertirse en columna');
    okV3p(!array_key_exists('INTERESADO',$own['columnas']) &&
        !array_key_exists('PAGO_CONFIRMADO',$own['columnas']),
        'sólo cinco columnas nuevas más panel de clasificación');
    okV3p($own['modo']==='PREVISUALIZACION_SIN_ESCRITURAS',
        'no aplicar movimientos');
    okV3p($own['dia_indicadores']===$hoy,'indicadores usan día de México');
    okV3p($own['columnas']['NUEVO']['registros'][0]['intentos_hoy']['intentos']===0,
        'sin PBX hay 0 intentos verificados hoy, no tres por histórico');
    // Un prospecto en Nuevos pasa de 0 a 1/3, 2/3 y 3/3 sólo en la
    // vista previa: NO se mueve a Recuperación automáticamente.
    $add(13,8,'out_13111111','BUZON_VOZ',$hoy,'09:00:00');
    $uno=$r->listadoV3Preparacion(8,false,$f);
    okV3p($uno['columnas']['NUEVO']['registros'][0]['intentos_hoy']['intentos']===1,
        'primera llamada acreditada 1/3');
    $add(13,8,'out_13222222','SIN_RESPUESTA',$hoy,'12:00:00');
    $dos=$r->listadoV3Preparacion(8,false,$f);
    okV3p($dos['columnas']['NUEVO']['registros'][0]['intentos_hoy']['intentos']===2,
        'dos PBX distintos acreditan 2/3');
    $add(13,8,'out_13333333','COMUNICACION_INTERRUMPIDA',
        $hoy,'15:00:00','Saliente','4','NO');
    $tres=$r->listadoV3Preparacion(8,false,$f);
    okV3p($tres['columnas']['NUEVO']['registros'][0]['intentos_hoy']['intentos']===3,
        'comunicación interrumpida sin persona cuenta en 3/3');
    okV3p($tres['columnas']['NUEVO']['total']===1 &&
        (int)$db->query("SELECT COUNT(*) n FROM prospecto_embudo_etapas
            WHERE asignacion_id=13 AND etapa='NUEVO'")->fetch_assoc()['n']===1,
        'vista previa de 3/3 nunca mueve a Recuperación');
    // Entrantes, jornada distinta, resultados con contacto y PBX sin
    // vínculo comercial no deben inflar la barra de progreso.
    $add(13,8,'in_13444444','SIN_RESPUESTA',$hoy,'17:00:00','Entrante');
    $add(13,8,'out_13555555','SIN_RESPUESTA',$ayer,'10:00:00');
    $add(13,8,'out_13666666','CONVERSACION_PERSONA',$hoy,'18:00:00');
    $add(13,8,'out_13777777','SIN_RESPUESTA',$hoy,
        '20:00:00','Saliente','','',false);
    okV3p($r->listadoV3Preparacion(8,false,$f)['columnas']['NUEVO']
        ['registros'][0]['intentos_hoy']['intentos']===3,
        'contacto humano, entrante, ayer y sin vínculo excluidos');

    // Una asignación legacy con TRES llamadas vinculadas incluye sólo
    // dos intentos sin respuesta, por lo que no puede mostrar 3/3.
    $add(11,8,'out_11111111','BUZON_VOZ',$hoy,'09:00:00');
    $add(11,8,'out_11222222','PROSPECTO_POTENCIAL',$hoy,'10:00:00');
    $add(11,8,'out_11333333','SIN_RESPUESTA',$hoy,'11:00:00');
    $legacy=$r->listadoV3Preparacion(8,false,$f);
    okV3p($legacy['columnas']['REVISION_LEGACY']['registros'][0]
        ['intentos_hoy']['intentos']===2,
        'tres llamadas históricas no equivalen a 3 intentos sin contacto');
    okV3p($legacy['columnas']['REVISION_LEGACY']['registros'][0]
        ['etapa_original']==='INTERESADO','legacy visible sin reclasificar');
    // De otro asesor, los PBX sólo se muestran a supervisor o al dueño.
    $add(14,9,'out_14111111','BUZON_VOZ',$hoy,'12:30:00');
    $ownProtected=$r->listadoV3Preparacion(8,false,$f);
    okV3p($ownProtected['total']===6 &&
        $ownProtected['columnas']['POTENCIAL']['total']===0,
        'asesor 8 no puede consultar asesor 9');

    // Supervisor ve todos los propios y no propios; asesor nunca ve ajenos.
    $all=$r->listadoV3Preparacion(8,true,
        EmbudoComercialRepository::filtros([],true));
    okV3p($all['total']===9 &&
        $all['columnas']['REVISION_LEGACY']['total']===2 &&
        $all['columnas']['POTENCIAL']['total']===1 &&
        $all['columnas']['PROCESO_INSCRIPCION']['total']===1,
        'supervisor ve 5 etapas, históricos aislados y todos los asesores');
    okV3p($all['columnas']['POTENCIAL']['registros'][0]['intentos_hoy']
        ['intentos']===1,
        'supervisor ve 1 PBX sin respuesta de Potencial ajeno sin cambiar etapa');
    $only9=$r->listadoV3Preparacion(8,true,
        EmbudoComercialRepository::filtros(['asesor'=>'9'],true));
    okV3p($only9['total']===3 &&
        $only9['columnas']['REVISION_LEGACY']['total']===1 &&
        $only9['columnas']['SEGUIMIENTO']['total']===0,
        'filtro por asesor supervisado no mezcla propietarios');
    $onlyProgram=$r->listadoV3Preparacion(8,true,
        EmbudoComercialRepository::filtros(['programa'=>'286'],true));
    okV3p($onlyProgram['total']===1 &&
        $onlyProgram['columnas']['POTENCIAL']['total']===1,
        'filtro por programa, sin duplicar filas');

    $orig=(int)$db->query('SELECT COUNT(*) n FROM prospecto_embudo_etapas')->fetch_assoc()['n'];
    for($i=0;$i<3;$i++)$r->listadoV3Preparacion(8,false,$f);
    okV3p($orig===(int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_embudo_etapas')->fetch_assoc()['n'],
        'ninguna consulta cambia estados');
    okV3p((int)$db->query(
        'SELECT COUNT(*) n FROM prospecto_embudo_subetapas_historial')->fetch_assoc()['n']===0,
        'ninguna consulta crea movimientos de subetapas');

    // Tope de 20 tarjetas y aviso de truncamiento, nunca mentir en totales.
    foreach(range(21,42) as $id){
        $db->query("INSERT INTO prospectos_comerciales
            VALUES($id,'Prospecto Extra $id',NULL,NULL)");
        $db->query("INSERT INTO prospecto_asignaciones
            VALUES($id,$id,8,1,'2026-11-11 11:00:00','2026-11-11',NULL)");
        $db->query("INSERT INTO prospecto_embudo_etapas VALUES($id,'NUEVO')");
    }
    $extra=$r->listadoV3Preparacion(8,false,$f);
    okV3p($extra['columnas']['NUEVO']['total']===23 &&
        count($extra['columnas']['NUEVO']['registros'])===20 &&
        $extra['columnas']['NUEVO']['truncado'],
        '20 tarjetas + totales completos y aviso de truncamiento');

    // El asesor V3 puede recorrer todas las páginas, no sólo las primeras
    // 20 tarjetas. La consulta conserva permisos y no mueve Prospectos.
    require_once __DIR__.'/../app/helpers/PermissionHelper.php';
    require_once __DIR__.'/../app/services/EmbudoComercialV3MovimientosPermitidos.php';
    $_SESSION=[
        'usuario_id'=>8,'rol_id'=>3,
        'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']
    ];
    $pg=EmbudoComercialRepository::paginasV3Operativo([]);
    $ops=$r->listadoV3Operativo(8,$f,$pg);
    okV3p($ops['columnas']['NUEVO']['total']===23 &&
        $ops['columnas']['NUEVO']['paginas']===2 &&
        count($ops['columnas']['NUEVO']['registros'])===20,
        'vista operativa V3 muestra 20/23 con navegación completa');
    okV3p($ops['columnas']['REVISION_LEGACY']['total']===0 &&
        $ops['columnas']['POTENCIAL']['registros'][0]['etapa_original']==='INTERESADO',
        'asesor ve el histórico en Potenciales sin cambiar su código');
    okV3p($ops['columnas']['POTENCIAL']['total']===1 &&
        $ops['columnas']['PROCESO_INSCRIPCION']['total']===0 &&
        $ops['columnas']['SEGUIMIENTO']['total']===1,
        'no reinterpreta legados y conserva Seguimiento');
    okV3p($ops['total_activos']===25 &&
        $ops['externos']['RECUPERACION']===2 &&
        $ops['externos']['DESCARTADO']===1,
        'totales completos sin duplicados ni bandejas mezcladas');
    $pg2=EmbudoComercialRepository::paginasV3Operativo(['p3_nuevo'=>'2']);
    $page2=$r->listadoV3Operativo(8,$f,$pg2);
    okV3p($page2['columnas']['NUEVO']['pagina']===2 &&
        count($page2['columnas']['NUEVO']['registros'])===3,
        'la segunda página muestra tres prospectos restantes');
    $over=EmbudoComercialRepository::paginasV3Operativo(['p3_nuevo'=>'999']);
    okV3p($r->listadoV3Operativo(8,$f,$over)['columnas']['NUEVO']['pagina']===2,
        'la paginación fuera de rango se ajusta al máximo');
    foreach(['0','-1','1e4','11.2','10001'] as $badPage) {
        try{
            EmbudoComercialRepository::paginasV3Operativo(['p3_nuevo'=>$badPage]);
            throw new RuntimeException('Se aceptó una página inválida');
        }catch(DomainException $ok){}
    }
    $foreign=$r->listadoV3Operativo(8,
        EmbudoComercialRepository::filtros(['asesor'=>'9'],false),$pg);
    okV3p($foreign['columnas']['POTENCIAL']['total']===1 &&
        $foreign['columnas']['PROCESO_INSCRIPCION']['total']===0,
        'filtro manipulado no deja ver asignaciones del asesor 9');
    okV3p(EmbudoComercialV3MovimientosPermitidos::destinos('NUEVO')===['CONTACTADO'] &&
        EmbudoComercialV3MovimientosPermitidos::destinos('CONTACTADO')===['SEGUIMIENTO'] &&
        EmbudoComercialV3MovimientosPermitidos::destinos('SEGUIMIENTO')===['CONTACTADO'],
        'sólo transiciones manuales compatibles con backend V1');
    foreach([
        ['NUEVO','RECUPERACION'],
        ['NUEVO','POTENCIAL'],
        ['INTERESADO','POTENCIAL'],
        ['SEGUIMIENTO','PROCESO_INSCRIPCION'],
        ['POTENCIAL','CONTACTADO'],
        ['CONTACTADO','DESCARTADO']
    ] as $pair) {
        try{
            EmbudoComercialV3MovimientosPermitidos::validar($pair[0],$pair[1]);
            throw new RuntimeException('Se permitió movimiento sin evidencia');
        }catch(DomainException $ok){}
    }
    okV3p(EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada(
        $db,8,13),'conversación telefónica real en asignación propia');
    okV3p(!EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada(
        $db,8,12),'sin conversación PBX explícita de asesor y asignación');
    okV3p(!EmbudoComercialV3MovimientosPermitidos::conversacionAcreditada(
        $db,8,14),'no atribuye conversación de asesor ajeno');
    $_SESSION['rol_id']=1;
    try{
        $r->listadoV3Operativo(8,$f,$pg);
        throw new RuntimeException('Se permitió operativo V3 al administrador');
    }catch(DomainException $ok){}
    $_SESSION=['usuario_id'=>9,'rol_id'=>3,
        'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']];
    try{
        $r->listadoV3Operativo(8,$f,$pg);
        throw new RuntimeException('Se permitió suplantar identidad en V3');
    }catch(DomainException $ok){}
    $_SESSION=['usuario_id'=>8,'rol_id'=>3,
        'permisos'=>['ventas.embudo.ver','ventas.embudo.mover']];

    // Completa las tablas de escritura exclusivamente dentro del esquema temporal.
    $db->query("ALTER TABLE prospecto_embudo_etapas ADD cambiado_por INT, ADD actualizado_at DATETIME");
    $db->query("ALTER TABLE prospecto_embudo_subetapas_historial
        ADD subetapa_anterior VARCHAR(40), ADD subetapa_nueva VARCHAR(40),
        ADD motivo VARCHAR(190), ADD origen VARCHAR(40), ADD usuario_id INT, ADD creado_at DATETIME");
    $db->query("CREATE TABLE prospecto_embudo_movimientos (
        id INT AUTO_INCREMENT PRIMARY KEY, asignacion_id BIGINT UNSIGNED,
        etapa_anterior VARCHAR(30), etapa_nueva VARCHAR(30), motivo VARCHAR(190),
        usuario_id INT, creado_at DATETIME
    ) ENGINE=InnoDB");
    $db->query("UPDATE prospecto_embudo_etapas SET etapa='CONTACTADO' WHERE asignacion_id IN (12,13)");
    $db->query("DELETE FROM prospecto_embudo_subetapas WHERE asignacion_id=12");
    $db->query("INSERT INTO prospecto_embudo_agenda VALUES (13,'2099-01-01 16:00:00','2099-01-01')");
    $db->query("INSERT INTO prospecto_embudo_subetapas VALUES(13,'PENDIENTE_CALIFICACION',UTC_TIMESTAMP(),8,1)");
    $visibles=array_column($r->listadoV3Operativo(8,$f,$pg)['columnas']['CONTACTADO']['registros'],
        'puede_calificar','asignacion_id');
    okV3p($visibles[13]===true && $visibles[12]===false &&
        EmbudoComercialV3MovimientosPermitidos::destinos('CONTACTADO',true)===['SEGUIMIENTO','POTENCIAL'],
        'la opción de Potenciales sólo se anuncia con conversación acreditada');

    require_once __DIR__.'/../app/services/EmbudoComercialV3CalificacionService.php';
    require_once __DIR__.'/../app/services/EmbudoComercialV3GestionPotencialService.php';
    $calificaciones=new EmbudoComercialV3CalificacionService($db);
    $motivo='Confirmó su intención de inscripción durante la llamada.';
    $rechazar=static function(callable $operacion,string $caso): void {
        try {
            $operacion();
        } catch(DomainException $esperado) {
            return;
        }
        throw new RuntimeException('No se bloqueó: '.$caso);
    };
    $rechazar(fn()=>$calificaciones->registrar(8,13,$motivo,false),
        'sin confirmación expresa');
    $rechazar(fn()=>$calificaciones->registrar(8,12,$motivo,true),
        'sin conversación');
    $rechazar(fn()=>$calificaciones->registrar(8,14,$motivo,true),
        'asignación ajena');
    $db->query("UPDATE prospecto_asignaciones SET vigente=0 WHERE id=13");
    $rechazar(fn()=>$calificaciones->registrar(8,13,$motivo,true),
        'asignación revocada');
    $db->query("UPDATE prospecto_asignaciones SET vigente=1 WHERE id=13");
    $_SESSION['permisos']=['ventas.embudo.ver'];
    $rechazar(fn()=>$calificaciones->registrar(8,13,$motivo,true),
        'sin permiso operativo');
    $_SESSION['permisos'][]='ventas.embudo.mover';
    $calificaciones->registrar(8,13,$motivo,true);
    $rechazar(fn()=>$calificaciones->registrar(8,13,$motivo,true),
        'reintento con etapa anterior obsoleta');
    $calificado=$db->query("SELECT e.etapa,s.subetapa,s.version,a.proxima_accion_at,a.revision_fecha
        FROM prospecto_embudo_etapas e
        JOIN prospecto_embudo_subetapas s USING (asignacion_id)
        JOIN prospecto_embudo_agenda a USING (asignacion_id) WHERE e.asignacion_id=13")->fetch_assoc();
    okV3p($calificado['etapa']==='POTENCIAL' && $calificado['subetapa']==='CALIFICADO' &&
        (int)$calificado['version']===2 && $calificado['proxima_accion_at']==='2099-01-01 16:00:00' &&
        $calificado['revision_fecha']==='2099-01-01',
        'califica con subetapa y conserva próxima acción y fecha de revisión');
    okV3p((int)$db->query("SELECT COUNT(*) FROM prospecto_embudo_movimientos
        WHERE asignacion_id=13 AND etapa_anterior='CONTACTADO' AND etapa_nueva='POTENCIAL'")->fetch_row()[0]===1 &&
        (int)$db->query("SELECT COUNT(*) FROM prospecto_embudo_subetapas_historial
        WHERE asignacion_id=13 AND subetapa_anterior='PENDIENTE_CALIFICACION'
        AND subetapa_nueva='CALIFICADO'")->fetch_row()[0]===1,
        'un solo evento de etapa y de subetapa');
    $db->query("ALTER TABLE prospecto_embudo_agenda ADD actualizado_at DATETIME, ADD actualizado_por INT");
    $gestion=(new EmbudoComercialV3GestionPotencialService($db))->registrar(
        8,11,'INTERESADO','','REUNIENDO_DOCUMENTOS','Reunirá la documentación necesaria.','2099-01-02T10:00'
    );
    okV3p($gestion['etapa_conservada']==='INTERESADO' && $gestion['proxima_accion_at']==='2099-01-02 16:00:00' &&
        $db->query("SELECT etapa FROM prospecto_embudo_etapas WHERE asignacion_id=11")->fetch_row()[0]==='INTERESADO',
        'gestión y próxima acción del histórico sin cambiar su etapa');

    $source=file_get_contents(__DIR__.'/../app/services/EmbudoComercialRepository.php');
    foreach(['UPDATE prospecto_embudo','INSERT INTO prospecto_embudo',
        '->begin_transaction(', '->commit('] as $forbidden) {
        okV3p(strpos($source,$forbidden)===false,'no se permiten escrituras '.$forbidden);
    }
    $view=file_get_contents(__DIR__.'/../app/views/embudo_comercial/previsualizacion_v3.php');
    foreach(['draggable="true"','data-mover-tarjeta',
        'data-embudo-marcar','impe-embudo-mover-form'] as $forbidden) {
        okV3p(strpos($view,$forbidden)===false,
            'la vista previa no activa teléfono ni movimientos');
    }
    echo "CHECK OK: preview y operativo V3, paginación >20, propietario, PBX real, transiciones seguras y legacy sin conversión. MySQL temporal.\n";
}finally{
    if($created)$db->query('DROP DATABASE '.$name);
    $db->close();
}
