<?php
/**
 * Instalador CLI controlado de BD fase 2A.
 *
 * php tools/instalar_embudo_fase2a_hostinger.php \
 *   --site=/home/.../public_html --sql=/home/.../embudo_fase2.sql \
 *   --backup-root=/home/.../respaldos --check|--apply
 *
 * --check: 100% lecturas. --apply: backup privado primero; DDL MySQL NO es
 * transaccional, por lo que si hay error se bloquea la repetición y se indica
 * posible instalación parcial para revisión. No borra, vacía ni altera datos.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
umask(0077);
const IMPE_SQL_GIT_BLOB = '478f3405b956c8801e93af588deec08f5d481054';
const IMPE_ETAPAS_TABLAS = [
    'prospecto_embudo_agenda' => [
        'asignacion_id','proxima_accion_at','revision_fecha','actualizado_at','actualizado_por'
    ],
    'prospecto_embudo_llamadas' => [
        'id','asignacion_id','usuario_id','extension','pbx_call_id','resultado','creado_at'
    ]
];
const IMPE_NUEVOS_PERMISOS = [
    'ventas.embudo.mover','ventas.recuperacion.gestionar','ventas.descartados.gestionar'
];
function impeOpciones(array $argv): array {
    $opts=[];
    foreach (array_slice($argv,1) as $arg) {
        if (in_array($arg,['--apply','--check'],true)) {
            $opts[substr($arg,2)]=true;
            continue;
        }
        if (!preg_match('/^--(site|sql|backup-root)=(.+)$/D',$arg,$m)) {
            throw new RuntimeException('Opción CLI no admitida: '.$arg);
        }
        $opts[$m[1]]=$m[2];
    }
    if (($opts['check']??false)===($opts['apply']??false)) {
        throw new RuntimeException('Indica exactamente --check o --apply.');
    }
    foreach (['site','sql'] as $key) {
        if (empty($opts[$key])) throw new RuntimeException('Falta --'.$key);
    }
    if (!empty($opts['apply']) && empty($opts['backup-root'])) {
        throw new RuntimeException('--apply requiere --backup-root fuera de public_html.');
    }
    return $opts;
}
function impeTabla(mysqli $db,string $name): ?string {
    $s=$db->prepare(
        'SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $s->bind_param('s',$name);
    $s->execute();
    $row=$s->get_result()->fetch_assoc();
    $s->close();
    return $row===null?null:strtoupper((string)$row['ENGINE']);
}
function impeColumnas(mysqli $db,string $name): array {
    $s=$db->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $s->bind_param('s',$name);
    $s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return array_column($rows,'COLUMN_NAME');
}
function impePermisos(mysqli $db): array {
    $lista=implode(',',array_fill(0,count(IMPE_NUEVOS_PERMISOS),'?'));
    $s=$db->prepare(
        "SELECT id,codigo,estado FROM permisos WHERE codigo IN ($lista)"
    );
    $codes=IMPE_NUEVOS_PERMISOS;
    $s->bind_param(str_repeat('s',count($codes)),...$codes);
    $s->execute();
    $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}
function impeControl(mysqli $db): void {
    $control=$db->query(
        'SELECT meta_habilitado,aliados_habilitado
           FROM prospecto_distribucion_control WHERE id=1'
    )->fetch_assoc();
    if (!$control || (int)$control['meta_habilitado']!==0 ||
        (int)$control['aliados_habilitado']!==0) {
        throw new RuntimeException('Meta o Aliados activados; instalación bloqueada.');
    }
}
function impeEstado(mysqli $db): string {
    impeControl($db);
    foreach (['roles','usuarios','permisos','rol_permisos','prospecto_asignaciones',
        'prospecto_embudo_etapas','prospecto_embudo_movimientos'] as $tabla) {
        if (impeTabla($db,$tabla)!=='INNODB') {
            throw new RuntimeException('Falta tabla base InnoDB: '.$tabla);
        }
    }
    $roles=$db->query(
        "SELECT id FROM roles WHERE nombre='Asesor de Ventas' AND estado=1"
    )->fetch_all(MYSQLI_ASSOC);
    if (count($roles)!==1) throw new RuntimeException('Rol asesor ambiguo o inactivo.');
    $faltantes=0;
    $presentes=0;
    foreach (IMPE_ETAPAS_TABLAS as $tabla=>$cols) {
        $engine=impeTabla($db,$tabla);
        if ($engine===null) {
            $faltantes++;
            echo "PENDIENTE: tabla $tabla\n";
            continue;
        }
        if ($engine!=='INNODB') {
            throw new RuntimeException('Tabla nueva no InnoDB: '.$tabla);
        }
        $enBD=impeColumnas($db,$tabla);
        foreach ($cols as $col) {
            if (!in_array($col,$enBD,true)) {
                throw new RuntimeException('Tabla incompatible: '.$tabla.'.'.$col);
            }
        }
        $presentes++;
        echo "INSTALADA: tabla $tabla\n";
    }
    $rows=impePermisos($db);
    $presentes+=count($rows);
    $faltantes+=count(IMPE_NUEVOS_PERMISOS)-count($rows);
    foreach ($rows as $row) {
        if ((int)$row['estado']!==1) {
            throw new RuntimeException('Permiso de fase 2A inactivo: '.$row['codigo']);
        }
        echo "INSTALADO: permiso ".$row['codigo']."\n";
    }
    foreach (IMPE_NUEVOS_PERMISOS as $codigo) {
        if (!in_array($codigo,array_column($rows,'codigo'),true)) {
            echo "PENDIENTE: permiso $codigo\n";
        }
    }
    if ($faltantes===5 && $presentes===0) return 'PENDIENTE';
    if ($presentes===5 && $faltantes===0) return 'INSTALADA';
    return 'PARCIAL';
}
function impeVerificarConcesiones(mysqli $db): void {
    $rol=$db->query(
        "SELECT id FROM roles WHERE nombre='Asesor de Ventas' AND estado=1"
    )->fetch_assoc();
    if (!$rol) throw new RuntimeException('No se encontró el rol Asesor de Ventas.');
    $rolId=(int)$rol['id'];
    foreach (IMPE_NUEVOS_PERMISOS as $codigo) {
        $s=$db->prepare(
            'SELECT p.id,p.estado,COUNT(rp.permiso_id) asignaciones
               FROM permisos p
               LEFT JOIN rol_permisos rp
                 ON rp.permiso_id=p.id AND rp.rol_id=?
              WHERE p.codigo=?
              GROUP BY p.id,p.estado'
        );
        $s->bind_param('is',$rolId,$codigo);
        $s->execute();
        $result=$s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
        if (count($result)!==1 || (int)$result[0]['estado']!==1 ||
            (int)$result[0]['asignaciones']!==1) {
            throw new RuntimeException('Permiso no concedido al asesor: '.$codigo);
        }
        echo "ASIGNACIÓN ROL OK: $codigo\n";
    }
}
function impeBackup(mysqli $db,string $site,string $backupRoot): string {
    $site=realpath($site);
    if ($site===false) throw new RuntimeException('Sitio no disponible.');
    if (!is_dir($backupRoot) && !mkdir($backupRoot,0700,true) && !is_dir($backupRoot)) {
        throw new RuntimeException('No se pudo crear carpeta de respaldo.');
    }
    $root=realpath($backupRoot);
    if (!$root || $root===$site || strpos($root,$site.DIRECTORY_SEPARATOR)===0) {
        throw new RuntimeException('El respaldo tiene que quedar fuera del sitio.');
    }
    $dir=$root.'/embudo-fase2a-db-'.gmdate('Ymd-His').'-'.getmypid();
    if (!mkdir($dir,0700)) {
        throw new RuntimeException('No se pudo crear el respaldo privado.');
    }
    $codes=implode(',',array_map(static function($x) use ($db) {
        return "'".$db->real_escape_string($x)."'";
    },IMPE_NUEVOS_PERMISOS));
    $respaldo=[
        'fecha_utc'=>gmdate('c'),
        'sql_blob'=>IMPE_SQL_GIT_BLOB,
        'permisos_antes'=>$db->query(
            "SELECT * FROM permisos WHERE codigo IN ($codes)"
        )->fetch_all(MYSQLI_ASSOC),
        'concesiones_antes'=>$db->query(
            "SELECT rp.* FROM rol_permisos rp
             INNER JOIN permisos p ON p.id=rp.permiso_id
             WHERE p.codigo IN ($codes)"
        )->fetch_all(MYSQLI_ASSOC),
        'rol_asesor'=>$db->query(
            "SELECT id,nombre,estado FROM roles
             WHERE nombre='Asesor de Ventas'"
        )->fetch_all(MYSQLI_ASSOC)
    ];
    $json=json_encode($respaldo,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|
        JSON_INVALID_UTF8_SUBSTITUTE);
    $file=$dir.'/estado_antes.json';
    if ($json===false || file_put_contents($file,$json,LOCK_EX)===false) {
        throw new RuntimeException('No se pudo escribir respaldo de roles y permisos.');
    }
    chmod($file,0600);
    echo "RESPALDO BD PRIVADO: $file\n";
    return $file;
}
try {
    $o=impeOpciones($argv);
    $site=realpath((string)$o['site']);
    $file=realpath((string)$o['sql']);
    if (!$site || !$file || !is_file($file)) {
        throw new RuntimeException('Sitio o SQL inexistente.');
    }
    $sql=file_get_contents($file);
    if (!is_string($sql) ||
        !hash_equals(IMPE_SQL_GIT_BLOB,sha1('blob '.strlen($sql)."\0".$sql))) {
        throw new RuntimeException('Integridad SQL incorrecta.');
    }
    require_once $site.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    $estado=impeEstado($db);
    if ($estado==='PARCIAL') {
        throw new RuntimeException(
            'Instalación parcial o permisos existentes; no ejecutar migración automáticamente.'
        );
    }
    if ($estado==='INSTALADA') {
        impeVerificarConcesiones($db);
        echo "YA INSTALADA: fase 2A comprobada. Sin escrituras.\n";
        exit(0);
    }
    if (!empty($o['check'])) {
        echo "CHECK BD COMPATIBLE: fase 2A pendiente, sin escrituras.\n";
        exit(0);
    }
    // DDL (CREATE TABLE) produce COMMIT implícito en MySQL: no se presenta
    // nunca como una transacción reversible ni se ejecutan DROP/DELETE.
    impeBackup($db,$site,(string)$o['backup-root']);
    $iniciada=true;
    try {
        if (!$db->multi_query($sql)) {
            throw new RuntimeException('MySQL no inició la migración.');
        }
        do {
            $rs=$db->store_result();
            if ($rs instanceof mysqli_result) $rs->free();
        } while ($db->more_results() && $db->next_result());
        if (impeEstado($db)!=='INSTALADA') {
            throw new RuntimeException('Las dos tablas y tres permisos no quedaron completos.');
        }
        impeVerificarConcesiones($db);
        impeControl($db);
        echo "MIGRACIÓN FASE 2A INSTALADA Y VERIFICADA.\n";
        echo "No se activaron motores ni se modificaron prospectos existentes.\n";
    } catch (Throwable $e) {
        fwrite(STDERR,
            "ATENCIÓN: migración SQL con posible ejecución parcial. ".
            "No repetir ni hacer DROP; comprobar antes de continuar.\n");
        throw $e;
    }
    $db->close();
} catch (Throwable $e) {
    error_log('[embudo_fase2_instalador] '.get_class($e).' codigo='.(int)$e->getCode());
    fwrite(STDERR,"BLOQUEADO: ".$e->getMessage()."\n");
    exit(2);
}
