<?php
/**
 * Fixture aislado para probar Kanban fase 2A.
 *
 * php crear_prospecto_prueba_embudo.php --site=/ruta/staging/public_html --check
 * php crear_prospecto_prueba_embudo.php --site=/ruta/staging/public_html --check --asesor=8
 * php crear_prospecto_prueba_embudo.php --site=/ruta/staging/public_html --apply
 *     --asesor=8 --expect-db=nombre_exacto_test --backup-root=/ruta/privada
 *
 * SOLO BASES CON IDENTIFICADOR "test/prueba/staging/sandbox" EN SU NOMBRE.
 * Nunca escribe en BD productiva, no activa motores ni genera llamadas.
 * La transacción completa se revierte ante cualquier fallo.
 */
if (PHP_SAPI!=='cli') { http_response_code(403); exit('Solo CLI.'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
umask(0077);

const MARCA_EMBUDO_TEST = 'EMBUDO-F2A-PRUEBA-001';
const CODIGO_PROGRAMA_TEST = '286';

function opcionesFixture(array $argv): array {
    $o=[];
    foreach (array_slice($argv,1) as $param) {
        if ($param==='--check'||$param==='--apply') {
            $k=substr($param,2);
            if (isset($o[$k])) throw new RuntimeException('Opción repetida: '.$param);
            $o[$k]=true;
            continue;
        }
        if (!preg_match('/^--(site|asesor|expect-db|backup-root)=(.+)$/D',$param,$m)
            || isset($o[$m[1]])) {
            throw new RuntimeException('Opción desconocida o duplicada.');
        }
        $o[$m[1]]=$m[2];
    }
    if (($o['check']??false)===($o['apply']??false) || empty($o['site'])) {
        throw new RuntimeException('Indica --site y exactamente --check o --apply.');
    }
    if (isset($o['asesor']) &&
        (!preg_match('/^[1-9][0-9]*$/D',$o['asesor']) ||
         filter_var($o['asesor'],FILTER_VALIDATE_INT)===false)) {
        throw new RuntimeException('ID del asesor no válido.');
    }
    if (!empty($o['apply']) &&
        (empty($o['asesor'])||empty($o['expect-db'])||empty($o['backup-root']))) {
        throw new RuntimeException(
            '--apply necesita --asesor, --expect-db y --backup-root.'
        );
    }
    return $o;
}
function simpleQuery(mysqli $db,string $sql,array $args=[]): array {
    $s=$db->prepare($sql);
    if ($args) {
        $types='';
        foreach ($args as $v) $types.=is_int($v)?'i':'s';
        $s->bind_param($types,...$args);
    }
    $s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}
function preflightFixture(mysqli $db,?int $asesor): array {
    $dbName=(string)$db->query('SELECT DATABASE() nombre')->fetch_assoc()['nombre'];
    if ($dbName==='') throw new RuntimeException('No se pudo identificar la base conectada.');
    echo "BASE DE DATOS DETECTADA: $dbName\n";
    $isTest=(bool)preg_match('/(?:^|[_-])(test|testing|prueba|pruebas|staging|sandbox)(?:[_-]|$)/i',$dbName);
    echo $isTest?"ENTORNO: base de PRUEBAS identificada\n":
        "ADVERTENCIA: la base NO está identificada como pruebas; --apply se bloqueará.\n";

    $required=[
        'prospectos_comerciales','prospecto_integraciones','prospecto_origenes',
        'prospecto_intereses','prospecto_preferencias_contacto',
        'prospecto_asignaciones','prospecto_embudo_etapas','formulario_programas',
        'prospecto_embudo_agenda','prospecto_distribucion_control'
    ];
    foreach ($required as $name) {
        $rows=simpleQuery($db,
            'SELECT ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$name]);
        if (count($rows)!==1 || strtoupper((string)$rows[0]['ENGINE'])!=='INNODB') {
            throw new RuntimeException('Tabla faltante o no InnoDB: '.$name);
        }
    }
    $control=simpleQuery($db,
        'SELECT meta_habilitado,aliados_habilitado
         FROM prospecto_distribucion_control WHERE id=1');
    if (count($control)!==1 || (int)$control[0]['meta_habilitado']!==0 ||
        (int)$control[0]['aliados_habilitado']!==0) {
        throw new RuntimeException('Los motores Meta y Aliados deben seguir desactivados.');
    }
    echo "DISTRIBUCION: Meta y Aliados APAGADOS\n";

    // IDs solamente; no se imprimen nombres, emails ni teléfonos.
    $asesores=simpleQuery($db,
        "SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id
          WHERE u.estado=1 AND r.estado=1 AND r.id=3
            AND r.nombre='Asesor de Ventas'
          ORDER BY u.id LIMIT 30");
    $ids=array_map('intval',array_column($asesores,'id'));
    echo "ASESORES ACTIVOS (IDs): ".($ids?implode(', ',$ids):'NINGUNO')."\n";
    if ($asesor!==null && !in_array($asesor,$ids,true)) {
        // Comprobar independiente del límite de visualización (30).
        $found=simpleQuery($db,
            "SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id
             WHERE u.id=? AND u.estado=1 AND r.estado=1 AND r.id=3
               AND r.nombre='Asesor de Ventas'",[$asesor]);
        if (count($found)!==1) {
            throw new RuntimeException('El asesor indicado no existe, está inactivo o tiene otro rol.');
        }
    }
    // El controlador y la acción operativa del embudo exigen rol_id=3
    // y concesiones explícitas al rol. No usar una ficha que no será visible.
    $grants=simpleQuery($db,
        "SELECT p.codigo FROM permisos p
         JOIN rol_permisos rp ON rp.permiso_id=p.id
         WHERE rp.rol_id=3 AND p.estado=1
           AND p.codigo IN ('ventas.embudo.ver','ventas.embudo.mover')");
    $codes=array_column($grants,'codigo');
    if (!in_array('ventas.embudo.ver',$codes,true) ||
        !in_array('ventas.embudo.mover',$codes,true)) {
        throw new RuntimeException(
            'El rol Asesor de Ventas necesita permisos de ver y mover en el embudo.'
        );
    }
    echo "PERMISOS: consulta y movimiento de Asesor OK\n";
    $existing=simpleQuery($db,
        "SELECT o.prospecto_id,a.id asignacion_id,a.asesor_id
           FROM prospecto_origenes o
           LEFT JOIN prospecto_asignaciones a
             ON a.prospecto_id=o.prospecto_id AND a.vigente=1
          WHERE o.sistema_origen='IMPE_PRUEBAS'
            AND o.tipo_registro='FICHA_TEST'
            AND o.identificador_origen=?",
        [MARCA_EMBUDO_TEST]);
    if (count($existing)>1) throw new RuntimeException('Marcador de prueba duplicado.');
    if ($existing) {
        echo 'YA EXISTE LA PRUEBA: prospecto='.$existing[0]['prospecto_id'].
            ' asignacion='.($existing[0]['asignacion_id']??'SIN ASIGNAR').
            ' asesor='.($existing[0]['asesor_id']??'SIN ASIGNAR')."\n";
    } else {
        echo "FICHA DE PRUEBA: pendiente; 1 prospecto y 1 asignación nueva\n";
    }
    return [$dbName,$isTest,$existing];
}
function respaldoFixture(string $root,string $site,string $name,int $asesor): string {
    $siteReal=realpath($site);
    if (!$siteReal || !is_dir($root) && !mkdir($root,0700,true) && !is_dir($root)) {
        throw new RuntimeException('Respaldo privado no disponible.');
    }
    $backupReal=realpath($root);
    if (!$backupReal || $backupReal===$siteReal ||
        strpos($backupReal,$siteReal.DIRECTORY_SEPARATOR)===0) {
        throw new RuntimeException('El respaldo debe estar fuera de public_html.');
    }
    $dir=$backupReal.'/fixture-embudo-'.gmdate('Ymd-His').'-'.getmypid();
    if (!mkdir($dir,0700)) throw new RuntimeException('No se pudo crear el respaldo.');
    $file=$dir.'/manifiesto.json';
    $json=json_encode([
        'momento_utc'=>gmdate('c'),'base'=>$name,'asesor'=>$asesor,
        'marca'=>MARCA_EMBUDO_TEST,'operacion'=>'fixture NUEVO; insercion transaccional',
        'reversion'=>'No borrar automaticamente; revisar asociaciones y movimientos'
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)||file_put_contents($file,$json,LOCK_EX)===false) {
        throw new RuntimeException('No se pudo guardar manifiesto.');
    }
    chmod($file,0600);
    echo "MANIFIESTO PRIVADO: $file\n";
    return $file;
}
try {
    $o=opcionesFixture($argv);
    $site=realpath((string)$o['site']);
    if (!$site || !is_file($site.'/config/db_connection.php') ||
        !is_file($site.'/app/services/ProspectoCentralRepository.php')) {
        throw new RuntimeException('Sitio de pruebas inválido o incompleto.');
    }
    require_once $site.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    $advisor=isset($o['asesor'])?(int)$o['asesor']:null;
    [$dbName,$isTest,$existing]=preflightFixture($db,$advisor);
    if (!empty($o['check'])) {
        echo "CHECK COMPLETO: sin escrituras, sin SQL y sin llamadas.\n";
        exit(0);
    }
    if (!$isTest) {
        throw new RuntimeException(
            'PROHIBIDO: la BD conectada no tiene identificador de entorno de pruebas.'
        );
    }
    if (!hash_equals((string)$o['expect-db'],$dbName)) {
        throw new RuntimeException('--expect-db no coincide exactamente con la BD conectada.');
    }
    if ($existing) {
        if ((int)($existing[0]['asesor_id']??0)!==$advisor ||
            (int)($existing[0]['asignacion_id']??0)<1) {
            throw new RuntimeException('Existe una prueba incompatible; no modificarla.');
        }
        echo "YA INSTALADA: la prueba está asignada a ese asesor, sin escrituras.\n";
        exit(0);
    }
    respaldoFixture((string)$o['backup-root'],$site,$dbName,$advisor);
    require_once $site.'/app/services/ProspectoCentralRepository.php';
    $repo=new ProspectoCentralRepository($db);
    $estructura=$repo->estructura();
    if (empty($estructura['disponible'])) {
        throw new RuntimeException('Almacén central incompatible: '.implode(',',$estructura['conflictos']??[]));
    }
    $db->begin_transaction();
    try {
        // Un marcador de origen único impide crear dos fichas por accidente.
        $again=simpleQuery($db,
            "SELECT id FROM prospecto_origenes WHERE sistema_origen='IMPE_PRUEBAS'
              AND tipo_registro='FICHA_TEST' AND identificador_origen=? FOR UPDATE",
            [MARCA_EMBUDO_TEST]);
        if ($again) throw new RuntimeException('La ficha se creó en otra sesión; revisar.');
        $pid=$repo->crearPersona([
            'nombre_completo'=>'PRUEBA EMBUDO FASE 2A - NO CONTACTAR',
            'telefono_original'=>null,'pais_nombre'=>'México'
        ]);
        $oid=$repo->guardarOrigen($pid,[
            'tipo_origen'=>'FORMULARIO_GENERAL',
            'sistema_origen'=>'IMPE_PRUEBAS',
            'tipo_registro'=>'FICHA_TEST',
            'identificador_origen'=>MARCA_EMBUDO_TEST,
            'datos_originales_ref'=>'prueba-interna:'.MARCA_EMBUDO_TEST,
            'formulario_nombre'=>'Prueba interna del embudo'
        ]);
        $programa=simpleQuery($db,
            'SELECT codigo,nombre FROM formulario_programas
             WHERE codigo=? AND activo=1 LIMIT 2',
            [CODIGO_PROGRAMA_TEST]);
        if (count($programa)===1) {
            $repo->interes($pid,$oid,(string)$programa[0]['codigo'],
                (string)$programa[0]['nombre'],gmdate('Y-m-d H:i:s'));
        } else {
            $repo->interes($pid,$oid,null,'Programa de prueba (sin catálogo)',
                gmdate('Y-m-d H:i:s'));
        }
        $fecha=(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))
            ->format('Y-m-d');
        $s=$db->prepare(
            "INSERT INTO prospecto_asignaciones
               (prospecto_id,asesor_id,asignado_por,asignado_at,fecha_trabajo,
                proxima_accion_at,finalizado_at,vigente,estado)
             VALUES (?,?,NULL,UTC_TIMESTAMP(),?,NULL,NULL,1,'NUEVO')"
        );
        $s->bind_param('iis',$pid,$advisor,$fecha);
        $s->execute();
        $aid=(int)$db->insert_id;
        $s->close();
        // No crear prospecto_embudo_etapas: NUEVO es el valor por omisión.
        $db->commit();
        echo "PRUEBA CREADA: prospecto=$pid asignacion=$aid asesor=$advisor\n";
        echo "ETAPA INICIAL: NUEVO (sin escrituras en etapas)\n";
        echo "SIN TELEFONO: no se permite marcar a contactos ficticios.\n";
        echo "SIN DISTRIBUCION: motores Meta/Aliados permanecen apagados.\n";
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    error_log('[fixture_embudo_fase2a] '.get_class($e).' codigo='.(int)$e->getCode());
    fwrite(STDERR,"BLOQUEADO: ".$e->getMessage()."\n");
    exit(2);
}
