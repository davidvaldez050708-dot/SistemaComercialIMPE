<?php
/**
 * Extensiones manuales y publicaciones por programa.
 * Sólo CLI. --check no modifica BD; --apply requiere respaldo privado explícito.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/db_connection.php';
$apply=in_array('--apply',$argv,true);
$backupDir='';
foreach($argv as $a) if(strpos($a,'--backup-dir=')===0) $backupDir=substr($a,13);
$tablas=['aliados_manuales_contactos','aliados_manuales_difusiones','marketing_aliado_manual_convocatorias'];
try {
    $db=(new Database())->connect(false);
    foreach(['aliados_manuales','convocatorias','formulario_programas','usuarios'] as $tabla) {
        if (!$db->query("SHOW TABLES LIKE '$tabla'")->num_rows)
            throw new RuntimeException('Falta dependencia: '.$tabla);
    }
    $faltan=[];
    foreach($tablas as $tabla) if(!$db->query("SHOW TABLES LIKE '$tabla'")->num_rows) $faltan[]=$tabla;
    if ($apply) {
        $private=realpath($backupDir);$public=realpath(dirname(__DIR__));
        if (!$private || !is_dir($private) || !is_writable($private) ||
            strpos($private.'/', $public.'/')===0)
            throw new RuntimeException('Se requiere --backup-dir privado y existente, fuera del CRM.');
        $archivo=$private.'/aliados-extensiones-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
        $out=fopen($archivo,'x');if(!$out)throw new RuntimeException('No fue posible abrir respaldo.');
        chmod($archivo,0600);
        fwrite($out,"-- Estructura/filas previas a extensiones; datos privados.\nSET NAMES utf8mb4;\n");
        foreach($tablas as $tabla) {
            if(!$db->query("SHOW TABLES LIKE '$tabla'")->num_rows) continue;
            $ddl=$db->query("SHOW CREATE TABLE `$tabla`")->fetch_row()[1];
            fwrite($out,$ddl.";\n");
            $res=$db->query("SELECT * FROM `$tabla`",MYSQLI_USE_RESULT);
            while($fila=$res->fetch_assoc()) {
                $vals=array_map(static fn($v)=>$v===null?'NULL':"'".$db->real_escape_string($v)."'",array_values($fila));
                fwrite($out,"INSERT INTO `$tabla` VALUES (".implode(',',$vals).");\n");
            }
            $res->free();
        }
        fclose($out);
        echo "Respaldo privado: ".$archivo."\n";
        foreach(['2026_10_22_aliados_manuales_contactos_difusion.sql','2026_10_22_marketing_convocatorias_aliados_manuales.sql'] as $migracion) {
            $sql=file_get_contents(__DIR__.'/../database/migrations/'.$migracion);
            if(!$db->multi_query($sql)) throw new RuntimeException('Error en migración '.$migracion.': '.$db->error);
            do { if($resultado=$db->store_result()) $resultado->free(); } while($db->more_results() && $db->next_result());
            if($db->errno)throw new RuntimeException('Migración incompleta: '.$db->error);
        }
    }
    $faltan=[];
    foreach($tablas as $tabla) if(!$db->query("SHOW TABLES LIKE '$tabla'")->num_rows) $faltan[]=$tabla;
    if($faltan) { echo "PENDIENTE: ".implode(', ',$faltan).". No se modificó BD.\n";exit(2); }
    foreach($tablas as $tabla) {
        $cols=$db->query("SHOW COLUMNS FROM `$tabla`")->fetch_all(MYSQLI_ASSOC);
        if(count($cols)<5)throw new RuntimeException('Estructura incompatible: '.$tabla);
    }
    echo "OK: extensiones Aliados, contactos y convocatorias manuales. Motor: ".$db->server_info.".\n";
} catch(Throwable $e) { fwrite(STDERR,"ERROR: ".$e->getMessage()."\n");exit(1); }
