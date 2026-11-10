<?php
/**
 * Fixture aislado de PRUEBA PBX para el Asesor 22 y la extensión 157.
 * Sintético salvo el número que debe pertenecer al operador de la prueba.
 *
 * CHECK: php crear_prospecto_prueba_pbx_157.php --site=... --check
 * APPLY: IMPE_EMBUDO_NUMERO=... php crear_prospecto_prueba_pbx_157.php \
 * --site=... --expect-db=u170338188_red_crm --backup-root=/ruta/privada \
 * --confirm=FICHA_PBX_CONTROLADA_22_157 --apply
 *
 * No inicia llamadas, no modifica prospectos previos ni motores de asignación,
 * no publica código, no registra resultados de Telefonía, no modifica horarios.
 * El número nunca se imprime y la transacción se revierte ante fallos.
 */
if (PHP_SAPI!=='cli') { http_response_code(403); exit('Sólo CLI'); }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
umask(0077);
const IMPE_PBX_TEST_DB='u170338188_red_crm';
const IMPE_PBX_TEST_ADVISOR=22;
const IMPE_PBX_TEST_EXTENSION='157';
const IMPE_PBX_TEST_MARKER='EMBUDO-F2A-PBX-157-001';
const IMPE_PBX_TEST_CONFIRM='FICHA_PBX_CONTROLADA_22_157';

function pbxOptions(array $argv): array
{
    $o=[];
    foreach (array_slice($argv,1) as $arg) {
        if ($arg==='--check'||$arg==='--apply') {
            $k=substr($arg,2);
            if (isset($o[$k])) throw new RuntimeException('Opción duplicada');
            $o[$k]=true;
        } elseif (preg_match('/^--(site|backup-root|expect-db|confirm)=(.+)$/D',$arg,$m)
            && !isset($o[$m[1]])) {
            $o[$m[1]]=$m[2];
        } else {
            throw new RuntimeException('Opción no reconocida');
        }
    }
    if (($o['check']??false)===($o['apply']??false)||empty($o['site'])) {
        throw new RuntimeException('Se requiere --site y --check o --apply');
    }
    if (!empty($o['apply']) &&
        (empty($o['backup-root'])||empty($o['expect-db'])||
         ($o['confirm']??'')!==IMPE_PBX_TEST_CONFIRM)) {
        throw new RuntimeException('Apply requiere respaldo privado y confirmación exacta');
    }
    return $o;
}

function pbxRows(mysqli $db,string $sql,array $args=[]): array
{
    $s=$db->prepare($sql);
    if ($args) {
        $types='';
        foreach ($args as $v) $types.=is_int($v)?'i':'s';
        $s->bind_param($types,...$args);
    }
    try {
        $s->execute();
        return $s->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $s->close();
    }
}

function pbxPreflight(mysqli $db): array
{
    $name=(string)($db->query('SELECT DATABASE() nombre')->fetch_assoc()['nombre']??'');
    if ($name!==IMPE_PBX_TEST_DB) {
        throw new RuntimeException('Base de datos inesperada; prueba bloqueada');
    }
    $tables=[
        'prospectos_comerciales','prospecto_integraciones',
        'prospecto_origenes','prospecto_intereses',
        'prospecto_asignaciones','prospecto_embudo_etapas',
        'prospecto_embudo_movimientos','prospecto_embudo_llamadas',
        'prospecto_distribucion_control','telefonia_extensiones',
        'telefonia_ventas_resultados','telefonia_ventas_llamadas',
        'formulario_programas'
    ];
    foreach ($tables as $table) {
        $row=pbxRows($db,'SELECT ENGINE FROM information_schema.TABLES
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if (count($row)!==1||strtoupper((string)$row[0]['ENGINE'])!=='INNODB') {
            throw new RuntimeException('Tabla base no compatible: '.$table);
        }
    }
    $control=pbxRows($db,
        'SELECT meta_habilitado,aliados_habilitado
           FROM prospecto_distribucion_control WHERE id=1');
    if (count($control)!==1 ||
        (int)$control[0]['meta_habilitado']!==0 ||
        (int)$control[0]['aliados_habilitado']!==0) {
        throw new RuntimeException('Motores Meta/Aliados encendidos o sin control');
    }
    $advisor=pbxRows($db,"SELECT u.id,te.extension FROM usuarios u
         JOIN roles r ON r.id=u.rol_id
         JOIN telefonia_extensiones te ON te.usuario_id=u.id
         WHERE u.id=? AND u.estado=1 AND r.estado=1
           AND r.id=3 AND r.nombre='Asesor de Ventas'
           AND te.proveedor='ZADARMA' AND te.extension=?
           AND te.activo=1 AND te.permite_salientes=1",
         [IMPE_PBX_TEST_ADVISOR,IMPE_PBX_TEST_EXTENSION]);
    if (count($advisor)!==1) {
        throw new RuntimeException('Asesor 22 o extensión 157 no están habilitados');
    }
    $access=pbxRows($db,"SELECT p.codigo FROM permisos p
        JOIN rol_permisos rp ON rp.permiso_id=p.id
        WHERE rp.rol_id=3 AND p.estado=1 AND p.codigo IN
        ('ventas.embudo.ver','ventas.embudo.mover',
         'ventas.recuperacion.gestionar','telefonia.usar','telefonia.salientes')");
    $codes=array_column($access,'codigo');
    foreach (['ventas.embudo.ver','ventas.embudo.mover',
              'ventas.recuperacion.gestionar','telefonia.usar',
              'telefonia.salientes'] as $required) {
        if (!in_array($required,$codes,true)) {
            throw new RuntimeException('Falta permiso de asesor: '.$required);
        }
    }
    // Revisar solamente formato y longitud de números PBX históricos.
    // No se imprime ningún número. Esto identifica posibles diferencias
    // entre marcación +52 y eventos con diez dígitos nacionales.
    $samples=pbxRows($db,
        "SELECT numero FROM telefonia_ventas_llamadas
          WHERE usuario_id=22 AND extension='157' AND direccion='Saliente'
          ORDER BY id DESC LIMIT 30");
    $formatos=['mex_10'=>0,'mex_52'=>0,'otros'=>0];
    foreach ($samples as $sample) {
        $digitos=preg_replace('/\\D/','',(string)$sample['numero']);
        if (strlen($digitos)===10) $formatos['mex_10']++;
        elseif (strlen($digitos)===12 && substr($digitos,0,2)==='52') {
            $formatos['mex_52']++;
        } else $formatos['otros']++;
    }
    echo "PBX FORMATO (sin mostrar números): +52=".
         $formatos['mex_52'].", nacional 10=".
         $formatos['mex_10'].", otros=".$formatos['otros'].".\n";
    if ($formatos['mex_10']>0) {
        echo "AVISO: la PBX también guarda destinos de diez dígitos;".
             " el puente exige coincidencia internacional exacta.\n";
    }
    $existing=pbxRows($db,
        "SELECT o.prospecto_id,a.id asignacion_id,a.asesor_id,
                p.telefono_normalizado
           FROM prospecto_origenes o
           JOIN prospectos_comerciales p ON p.id=o.prospecto_id
           LEFT JOIN prospecto_asignaciones a
              ON a.prospecto_id=o.prospecto_id AND a.vigente=1
          WHERE o.sistema_origen='IMPE_PRUEBAS'
            AND o.tipo_registro='LLAMADA_CONTROLADA'
            AND o.identificador_origen=?",
         [IMPE_PBX_TEST_MARKER]);
    if (count($existing)>1) throw new RuntimeException('Marcador duplicado');
    echo "BASE OK: ".IMPE_PBX_TEST_DB."\n";
    echo "ASESOR OK: 22 / ZADARMA extensión 157 / salientes.\n";
    echo "PERMISOS OK: Telefonía y Gestión comercial.\n";
    echo "MOTORES OK: Meta y Aliados desactivados.\n";
    if ($existing) {
        if ((int)$existing[0]['asesor_id']!==IMPE_PBX_TEST_ADVISOR ||
            (int)$existing[0]['asignacion_id']<1) {
            throw new RuntimeException('Fixture previo incompatible, sin escrituras');
        }
        echo "YA EXISTE: prospecto=".(int)$existing[0]['prospecto_id'].
             " asignacion=".(int)$existing[0]['asignacion_id']."\n";
    } else echo "PENDIENTE: una ficha PBX de prueba controlada.\n";
    return ['existing'=>$existing,'numero_nacional_en_pbx'=>$formatos['mex_10']];
}

function pbxNumeroAutorizado(string $raw): string
{
    $raw=trim($raw);
    // Pruebas en México, en formato de 10 cifras o +52 y 10 cifras.
    if (!preg_match('/^(?:[0-9]{10}|\+?52[0-9]{10})$/D',$raw)) {
        throw new RuntimeException(
            'Número no válido: usa 10 dígitos mexicanos o +52 con 10 dígitos'
        );
    }
    $digits=preg_replace('/\D/','',$raw);
    if (strlen($digits)===10) $digits='52'.$digits;
    $phone='+'.$digits;
    // Verificar misma normalización que PBX y repositorio comercial.
    $normalizado=ProspectoCentralDatos::telefono($phone);
    $normalPBX=TelefoniaContactosService::normalizarTelefono($phone);
    if (!is_string($normalizado) || $normalizado!==$normalPBX) {
        throw new RuntimeException('Incompatibilidad de normalización telefónica');
    }
    return $phone;
}

function pbxManifest(string $folder,string $site): string
{
    $s=realpath($site);
    if (!$s || (!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder))) {
        throw new RuntimeException('Respaldo privado no disponible');
    }
    $root=realpath($folder);
    if (!$root || $root===$s || strpos($root,$s.DIRECTORY_SEPARATOR)===0) {
        throw new RuntimeException('El respaldo debe quedar fuera de public_html');
    }
    $dest=$root.'/fixture-pbx-157-'.gmdate('Ymd-His').'-'.getmypid();
    if (!mkdir($dest,0700)) throw new RuntimeException('Error al crear carpeta privada');
    $file=$dest.'/manifest.json';
    $json=json_encode([
        'fecha_utc'=>gmdate('c'),'base'=>IMPE_PBX_TEST_DB,
        'marca'=>IMPE_PBX_TEST_MARKER,
        'asesor'=>IMPE_PBX_TEST_ADVISOR,
        'extension'=>IMPE_PBX_TEST_EXTENSION,
        'tipo'=>'1 prospecto sintetico, 1 origen, 1 asignacion',
        'telefono'=>'NO SE GUARDA EN EL MANIFIESTO',
        'rollback'=>'Revisar actividad comercial y PBX antes de limpiar'
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    if (!is_string($json)||file_put_contents($file,$json,LOCK_EX)===false) {
        throw new RuntimeException('No se pudo crear manifiesto privado');
    }
    chmod($file,0600);
    echo "MANIFIESTO PRIVADO: $file\n";
    return $file;
}

try {
    $o=pbxOptions($argv);
    $site=realpath((string)$o['site']);
    if (!$site || !is_file($site.'/config/db_connection.php') ||
        !is_file($site.'/app/services/ProspectoCentralRepository.php')) {
        throw new RuntimeException('Sitio incompatible');
    }
    require_once $site.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    $preflight=pbxPreflight($db);
    $existing=$preflight['existing'];
    if (!empty($o['check'])) {
        echo "CHECK COMPLETO: sin escrituras y sin consultar números privados.\n";
        exit(0);
    }
    if ((int)$preflight['numero_nacional_en_pbx']>0) {
        // Validar que la equivalencia mexicana ya fue publicada EN EL PUENTE
        // y que el sitio actual no contiene la variante incompatible.
        // No cambiar ni ejecutar servicios de Telefonía para esta prueba.
        require_once $site.'/app/services/EmbudoComercialResultadoBridge.php';
        if (!method_exists('EmbudoComercialResultadoBridge','mismoNumeroAsignado') ||
            !EmbudoComercialResultadoBridge::mismoNumeroAsignado(
                '+527771234567','7771234567','México') ||
            EmbudoComercialResultadoBridge::mismoNumeroAsignado(
                '+527771234567','7771234568','México') ||
            EmbudoComercialResultadoBridge::mismoNumeroAsignado(
                '+527771234567','7771234567','Estados Unidos')) {
            throw new RuntimeException(
                'El puente comercial del sitio aún no fue validado para PBX '.
                'nacional de 10 dígitos. No se crea ninguna ficha.'
            );
        }
        echo "VALIDACIÓN PUENTE: compatibilidad mexicana +52/10 confirmada en sitio.\n";
    }
    if (!hash_equals(IMPE_PBX_TEST_DB,(string)$o['expect-db'])) {
        throw new RuntimeException('--expect-db incorrecta');
    }
    // No registrar el número ni en el shell, logs, mensajes ni manifiesto.
    $number=getenv('IMPE_EMBUDO_NUMERO');
    if (!is_string($number)||$number==='') {
        throw new RuntimeException('No se indicó el número controlado por canal privado');
    }
    if (strlen($number)>20) {
        throw new RuntimeException('Longitud de número no válida');
    }
    // Para evitar revelar datos, ningún detalle de excepciones se registra.
    // El teléfono se inserta exclusivamente en la ficha comercial sintética.
    require_once $site.'/app/helpers/ProspectoCentralDatos.php';
    require_once $site.'/app/services/TelefoniaContactosService.php';
    $phone=pbxNumeroAutorizado($number);
    // Evitar ambigüedad con cualquier prospecto comercial ya existente.
    // Una ficha duplicada podría causar atribución confusa de llamadas.
    $existingPhone=pbxRows($db,
        'SELECT COUNT(*) total FROM prospectos_comerciales
         WHERE telefono_normalizado=?',
        [$phone]);
    if (!$existing && (int)$existingPhone[0]['total']!==0) {
        throw new RuntimeException(
            'Ese número ya figura en el almacén comercial: no crear duplicado'
        );
    }
    if ($existing) {
        if (!hash_equals((string)$existing[0]['telefono_normalizado'],$phone)) {
            throw new RuntimeException('La ficha anterior corresponde a otro número');
        }
        echo "YA INSTALADA: ficha PBX asignada al asesor 22. Sin escrituras.\n";
        exit(0);
    }
    // El respaldo es un MANIFIESTO (no un dump completo). Ante excepción SQL
    // se revierte toda la operación porque únicamente se usa INSERT InnoDB.
    pbxManifest((string)$o['backup-root'],$site);
    require_once $site.'/app/services/ProspectoCentralRepository.php';
    $repo=new ProspectoCentralRepository($db);
    $schema=$repo->estructura();
    if (empty($schema['disponible'])) {
        throw new RuntimeException('Almacén central incompatible; sin escrituras');
    }
    $db->begin_transaction();
    try {
        $duplicado=pbxRows($db,
            "SELECT id FROM prospecto_origenes
              WHERE sistema_origen='IMPE_PRUEBAS'
                AND tipo_registro='LLAMADA_CONTROLADA'
                AND identificador_origen=? FOR UPDATE",
            [IMPE_PBX_TEST_MARKER]);
        if ($duplicado) throw new RuntimeException('Ficha creada en otra sesión');
        $already=pbxRows($db,
            'SELECT id FROM prospectos_comerciales
             WHERE telefono_normalizado=? FOR UPDATE',[$phone]);
        if ($already) throw new RuntimeException('Número existente: ficha cancelada');
        $pid=$repo->crearPersona([
            'nombre_completo'=>'PRUEBA ZADARMA 157 - CONTACTO AUTORIZADO',
            'telefono_original'=>$phone,
            'pais_nombre'=>'México'
        ]);
        $oid=$repo->guardarOrigen($pid,[
            'tipo_origen'=>'FORMULARIO_GENERAL',
            'sistema_origen'=>'IMPE_PRUEBAS',
            'tipo_registro'=>'LLAMADA_CONTROLADA',
            'identificador_origen'=>IMPE_PBX_TEST_MARKER,
            'datos_originales_ref'=>'prueba-controlada:'.IMPE_PBX_TEST_MARKER,
            'formulario_nombre'=>'Prueba de Telefonía y Embudo'
        ]);
        $programa=pbxRows($db,
            'SELECT codigo,nombre FROM formulario_programas
             WHERE codigo=? AND activo=1 LIMIT 2',['286']);
        if (count($programa)===1) {
            $repo->interes($pid,$oid,(string)$programa[0]['codigo'],
                (string)$programa[0]['nombre'],gmdate('Y-m-d H:i:s'));
        }
        $fecha=(new DateTimeImmutable('today',new DateTimeZone('America/Mexico_City')))
            ->format('Y-m-d');
        $s=$db->prepare(
            "INSERT INTO prospecto_asignaciones
              (prospecto_id,asesor_id,asignado_por,asignado_at,fecha_trabajo,
               proxima_accion_at,finalizado_at,vigente,estado)
             VALUES (?,22,NULL,UTC_TIMESTAMP(),?,NULL,NULL,1,'NUEVO')");
        $s->bind_param('is',$pid,$fecha);
        $s->execute();
        $aid=(int)$db->insert_id;
        $s->close();
        $db->commit();
        echo "PRUEBA PBX CREADA: prospecto=$pid asignacion=$aid asesor=22 extensión=157.\n";
        echo "ETAPA INICIAL: NUEVO. Teléfono autorizado registrado (no mostrado).\n";
        echo "SIN LLAMADAS: no se marcó a ningún teléfono.\n";
        echo "SIN DISTRIBUCIÓN: Meta y Aliados permanecen apagados.\n";
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    // Nunca hacer error_log($e), pues un mensaje del driver podría incluir
    // datos privados. Solo categoría y código, sin contenido SQL.
    error_log('[fixture_pbx_157] '.get_class($e).' codigo='.(int)$e->getCode());
    // Mensaje de excepción limitado a textos controlados; driver SQL sin PII.
    if ($e instanceof mysqli_sql_exception) {
        fwrite(STDERR,"BLOQUEADO: fallo SQL, se revirtió la transacción.\n");
    } else {
        fwrite(STDERR,"BLOQUEADO: ".$e->getMessage()."\n");
    }
    exit(2);
}
