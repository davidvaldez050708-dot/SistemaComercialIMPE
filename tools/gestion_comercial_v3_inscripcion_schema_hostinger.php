<?php
/**
 * Migración ÚNICAMENTE ADITIVA de la relación entre inscripción y prospecto.
 *
 * CHECK sin escritura. APPLY solo crea una tabla nueva vacía cuando
 * faltaba. No elimina/edita las inscripciones ni los prospectos.
 * La estructura creada se conserva si posteriormente se revierte el código.
 */
if (PHP_SAPI!=='cli') {http_response_code(403);exit(2);}
$opts=getopt('',['site:','stage:','check','apply','confirm:']);
$check=isset($opts['check']);
$apply=isset($opts['apply']);
if ($check===$apply) {fwrite(STDERR,"BLOQUEADO: utiliza --check o --apply.\n");exit(2);}
$site=realpath((string)($opts['site']??''));
$stage=realpath((string)($opts['stage']??''));
if (!$site || !$stage || !is_dir($site) || !is_dir($stage) ||
    !is_file($site.'/config/db_connection.php') ||
    strncmp($stage,$site.DIRECTORY_SEPARATOR,strlen($site)+1)===0) {
    fwrite(STDERR,"BLOQUEADO: sitio o stage privado no reconocido.\n");exit(2);
}
$rel='database/migrations/2026_11_12_ventas_inscripcion_v3_evidencia.sql';
$file=$stage.'/'.$rel;
$expected='8374abf8aae014f6eb5e72b29d7ba61cee82c474';
if (!is_file($file) || is_link($file) ||
    is_link($stage.'/database') || is_link($stage.'/database/migrations')) {
    fwrite(STDERR,"BLOQUEADO: falta archivo de migración seguro.\n");exit(2);
}
$contents=file_get_contents($file);
if (!is_string($contents)) {fwrite(STDERR,"BLOQUEADO: SQL ilegible.\n");exit(2);}
$gitBlob=sha1('blob '.strlen($contents)."\0".$contents);
if (!hash_equals($expected,$gitBlob)) {
    fwrite(STDERR,"BLOQUEADO: SQL no corresponde a la versión validada.\n");exit(2);
}
$statement=trim((string)preg_replace('/^\\s*--[^\r\n]*(?:\r?\n|$)/m','',$contents));
$statement=rtrim($statement," \r\n\t;");
if (preg_match('/^CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS\\s+prospecto_embudo_inscripciones_v3\\s*\\(/i',$statement)!==1 ||
    preg_match('/;|DROP\\s+|ALTER\\s+|DELETE\\s+|INSERT\\s+|UPDATE\\s+/i',$statement)) {
    fwrite(STDERR,"BLOQUEADO: solo se admite la creación de la tabla revisada.\n");exit(2);
}
require $site.'/config/db_connection.php';
$db=(new Database())->connect(false);
try {
    $parents=[
        'prospectos_comerciales'=>['id'=>'bigint'],
        'prospecto_asignaciones'=>['id'=>'bigint'],
        'formulario_inscripciones'=>['id'=>'int'],
    ];
    foreach ($parents as $table=>$cols) {
        $s=$db->prepare(
            'SELECT ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->bind_param('s',$table);$s->execute();
        $row=$s->get_result()->fetch_assoc();$s->close();
        if (!$row || strtoupper((string)$row['ENGINE'])!=='INNODB')
            throw new DomainException('Tabla base no disponible o no es InnoDB: '.$table);
        $s=$db->prepare(
            'SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->bind_param('s',$table);$s->execute();$r=$s->get_result();
        $found=[];while($x=$r->fetch_assoc())$found[$x['COLUMN_NAME']]=$x;
        $s->close();
        foreach ($cols as $name=>$dataType) {
            if (!isset($found[$name]) || $found[$name]['DATA_TYPE']!==$dataType ||
                strpos(strtolower($found[$name]['COLUMN_TYPE']),'unsigned')===false)
                throw new DomainException('Tipo de llave incompatible: '.$table.'.'.$name);
        }
    }
    $s=$db->query("SELECT ENGINE FROM information_schema.TABLES
                    WHERE TABLE_SCHEMA=DATABASE()
                      AND TABLE_NAME='prospecto_embudo_inscripciones_v3'");
    $actual=$s->fetch_assoc();
    if ($actual) {
        if (strtoupper((string)$actual['ENGINE'])!=='INNODB')
            throw new DomainException('La tabla ya existe con otro motor.');
        $required=['asignacion_id','prospecto_id','formulario_inscripcion_id',
            'vinculado_por','evidencia_tipo','verificado_at'];
        $res=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE()
                           AND TABLE_NAME='prospecto_embudo_inscripciones_v3'");
        $found=[];while($row=$res->fetch_assoc())$found[$row['COLUMN_NAME']]=true;
        foreach($required as $col)if(!isset($found[$col]))
            throw new DomainException('La tabla existente está incompleta: '.$col);
        $n=$db->query("SELECT COUNT(*) total FROM information_schema.STATISTICS
                       WHERE TABLE_SCHEMA=DATABASE()
                         AND TABLE_NAME='prospecto_embudo_inscripciones_v3'
                         AND INDEX_NAME='uq_peiv3_formulario'
                         AND NON_UNIQUE=0")->fetch_assoc();
        if ((int)$n['total']<1)
            throw new DomainException('La tabla existente no tiene unicidad de formulario.');
        echo "ESQUEMA_INTEGRAL_OK: tabla compatible ya existente.\n";
        exit(0);
    }
    if ($check) {
        echo "ESQUEMA_INTEGRAL_PENDIENTE: tabla nueva, tipos y relaciones compatibles.\n";
        exit(0);
    }
    if (($opts['confirm']??'')!=='CREAR_SOLO_TABLA_INSCRIPCION_V3_IMPE') {
        throw new DomainException('Falta autorización explícita para la tabla nueva.');
    }
    $db->query($statement);
    echo "MIGRACION_ADITIVA_OK: tabla nueva creada; ninguna inscripción modificada.\n";
} catch (Throwable $e) {
    error_log('[impe_v3_inscripcion_schema] '.get_class($e).': '.$e->getMessage());
    fwrite(STDERR,"BLOQUEADO: preflight o creación de tabla no completado.\n");
    exit(2);
} finally {
    $db->close();
}
