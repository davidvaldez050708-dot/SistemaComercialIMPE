<?php
/**
 * Instalación CLI aditiva de formulario_aliado_qr_diseno.
 * --check: jamás escribe SQL. --apply: crea exclusivamente la tabla faltante.
 * Conserva la base de datos y los diseños ya guardados si la tabla existe.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/db_connection.php';

try {
    $options=getopt('', ['check','apply','backup-dir:']);
    $check=isset($options['check']); $apply=isset($options['apply']);
    if ($check===$apply) throw new RuntimeException('Indica exactamente --check o --apply --backup-dir=RUTA.');
    $db=(new Database())->connect(false);
    $database=(string)$db->query('SELECT DATABASE()')->fetch_row()[0];
    foreach (['formulario_aliados','usuarios','formulario_aliado_registro'] as $table) {
        if (!$db->query("SHOW TABLES LIKE '$table'")->num_rows) {
            throw new RuntimeException('Falta dependencia: '.$table);
        }
    }
    $name='formulario_aliado_qr_diseno';
    $validate=static function() use($db,$name): void {
        $row=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$name'")->fetch_assoc();
        if (!$row || $row['ENGINE']!=='InnoDB') {
            throw new RuntimeException('El esquema del QR no tiene motor InnoDB.');
        }
        $cols=array_column($db->query("SHOW COLUMNS FROM `$name`")->fetch_all(MYSQLI_ASSOC),'Field');
        $wanted=['aliado_id','diseno_json','logo_binario','logo_mime','version','actualizado_por','updated_at'];
        if (array_diff($wanted,$cols) || array_diff($cols,$wanted)) {
            throw new RuntimeException('La tabla QR existente tiene columnas incompatibles.');
        }
        $primary=$db->query("SHOW INDEX FROM `$name` WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);
        if (count($primary)!==1 || $primary[0]['Column_name']!=='aliado_id') {
            throw new RuntimeException('La clave primaria del diseño QR es incompatible.');
        }
    };
    $exists=(bool)$db->query("SHOW TABLES LIKE '$name'")->num_rows;
    if ($exists) {
        $validate();
        echo "OK: esquema de QR por aliado ya instalado. No se modificó SQL.\n";
        exit;
    }
    if ($check) {
        echo "PENDIENTE: instalar tabla aditiva $name. No se ejecutó SQL de escritura.\n";
        echo "SITIO INTACTO\n";
        exit;
    }
    $dir=realpath((string)($options['backup-dir']??''));
    $root=realpath(__DIR__.'/..');
    if (!$dir || !$root || !is_dir($dir) || !is_writable($dir) ||
        strpos(str_replace('\\','/',$dir).'/',str_replace('\\','/',$root).'/')===0) {
        throw new RuntimeException('El respaldo debe estar fuera del sitio público, en un directorio privado escribible.');
    }
    $backup=$dir.'/qr-aliado-v6-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
    $record=['version'=>1,'database'=>$database,'tabla'=>$name,'existia'=>false,
        'accion'=>'CREATE TABLE','iniciada_utc'=>gmdate('c')];
    $json=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if (file_put_contents($backup,$json,LOCK_EX)!==strlen($json)) {
        throw new RuntimeException('No se pudo registrar el respaldo previo.');
    }
    chmod($backup,0600);
    $ddl=(string)file_get_contents(__DIR__.'/../database/migrations/2026_10_23_aliados_qr_diseno.sql');
    $ddl=preg_replace('/^--.*$/m','',$ddl);
    $ddl=trim($ddl);
    if (!preg_match('/^CREATE TABLE IF NOT EXISTS formulario_aliado_qr_diseno\s*\(/i',$ddl) ||
        substr_count($ddl,';')!==1) {
        throw new RuntimeException('Migración SQL no reconocida.');
    }
    $db->query($ddl);
    $validate();
    echo "CREADA: $name\n";
    echo "RESPALDO DE CAMBIO DE ESQUEMA: $backup\n";
    echo "OK: sólo se creó la tabla de diseños QR. Enlaces, programas, borradores y convocatorias sin cambios.\n";
} catch (Throwable $e) {
    fwrite(STDERR,'ERROR QR: '.$e->getMessage()."\n");
    exit(1);
}
