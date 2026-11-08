<?php
/**
 * Diagnóstico SOLO LECTURA de Hostinger para publicar Embudo Comercial IMPE.
 * Uso: php tools/embudo_comercial_db_preflight.php /ruta/public_html /ruta/migracion.sql
 * No escribe tablas, datos, permisos ni archivos.
 */
if (PHP_SAPI!=='cli') {
    http_response_code(403);
    exit('Solo CLI.');
}
$site=$argv[1]??'';
$migracion=$argv[2]??'';
if (!is_string($site)||!is_dir($site)||!is_string($migracion)||!is_file($migracion)) {
    fwrite(STDERR,"Uso: php embudo_comercial_db_preflight.php SITIO RUTA_SQL\n");
    exit(2);
}
$sql=file_get_contents($migracion);
$esperado='0f3eb2d23c5b5ee965cb227e6be07700195a3d23';
$actual=is_string($sql)?sha1('blob '.strlen($sql)."\0".$sql):'';
if (!hash_equals($esperado,$actual)) {
    fwrite(STDERR,"ERROR: integridad SQL incorrecta, sin escrituras.\n");
    exit(2);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once rtrim($site,'/').'/config/db_connection.php';
    require_once rtrim($site,'/').'/app/helpers/AdminRolePolicy.php';
    $db=(new Database())->connect(false);
    $tablas=['roles','usuarios','permisos','rol_permisos','prospectos_comerciales',
        'prospecto_asignaciones','prospecto_origenes','prospecto_intereses',
        'prospecto_preferencias_contacto','prospecto_distribucion_control'];
    foreach ($tablas as $tabla) {
        $s=$db->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $s->bind_param('s',$tabla);$s->execute();
        $row=$s->get_result()->fetch_assoc();$s->close();
        if (!$row || strtoupper((string)$row['ENGINE'])!=='INNODB') {
            throw new RuntimeException('Tabla previa no disponible o no InnoDB: '.$tabla);
        }
    }
    $control=$db->query('SELECT meta_habilitado,aliados_habilitado FROM prospecto_distribucion_control WHERE id=1')
        ->fetch_assoc();
    if (!$control||(int)$control['meta_habilitado']!==0||(int)$control['aliados_habilitado']!==0) {
        throw new RuntimeException('Meta o Aliados no están desactivados. No continuar.');
    }
    foreach ([
        'prospecto_embudo_etapas'=>[
            'asignacion_id','etapa','cambiado_por','actualizado_at'
        ],
        'prospecto_embudo_movimientos'=>[
            'id','asignacion_id','etapa_anterior','etapa_nueva','motivo','usuario_id','creado_at'
        ]
    ] as $tabla=>$columnas) {
        $s=$db->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $s->bind_param('s',$tabla);$s->execute();
        $row=$s->get_result()->fetch_assoc();$s->close();
        if (!$row) {
            echo "PENDIENTE (se creará con migración): $tabla\n";
            continue;
        }
        if (strtoupper((string)$row['ENGINE'])!=='INNODB') {
            throw new RuntimeException('Tabla existente con motor incorrecto: '.$tabla);
        }
        $cols=[];
        $s=$db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $s->bind_param('s',$tabla);$s->execute();
        $rs=$s->get_result();
        while ($r=$rs->fetch_assoc()) $cols[]=$r['COLUMN_NAME'];
        $s->close();
        foreach ($columnas as $col) if (!in_array($col,$cols,true)) {
            throw new RuntimeException('Tabla existente incompatible: '.$tabla.'.'.$col);
        }
        echo "EXISTENTE: $tabla, columnas mínimas presentes\n";
    }
    $codigos=['ventas.embudo.ver','ventas.recuperacion.ver',
        'ventas.descartados.ver','ventas.embudo.supervisar'];
    $stmt=$db->prepare('SELECT codigo,estado FROM permisos WHERE codigo=? LIMIT 1');
    foreach ($codigos as $codigo) {
        $stmt->bind_param('s',$codigo);
        $stmt->execute();
        $row=$stmt->get_result()->fetch_assoc();
        if (!$row) echo "PERMISO POR INSTALAR: $codigo\n";
        else echo "PERMISO EXISTENTE ".((int)$row['estado']===1?'ACTIVO':'INACTIVO').": $codigo\n";
    }
    $stmt->close();
    if (function_exists('permisoOperativoRestringidoAdministrador') &&
        permisoOperativoRestringidoAdministrador('prospectos.importar_meta')!==true) {
        throw new RuntimeException('Política del Administrador no coincide.');
    }
    echo "CHECK BD OK: tablas base InnoDB, Meta y Aliados apagados, SQL íntegro.\n";
    echo "SIN ESCRITURAS: pendiente verificar y aplicar migración por separado.\n";
    $db->close();
} catch (Throwable $e) {
    error_log('[embudo_preflight] '.get_class($e).' codigo='.(int)$e->getCode());
    fwrite(STDERR,"BLOQUEADO: preflight de base de datos no aprobado. ".$e->getMessage()."\n");
    exit(2);
}
