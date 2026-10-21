<?php
// Sólo CLI. La consulta --check nunca instala ni modifica tablas o registros.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/db_connection.php';
$apply=in_array('--apply',$argv,true); $backupDir='';
foreach($argv as $arg) if(strpos($arg,'--backup-dir=')===0) $backupDir=substr($arg,13);
try {
    $db=(new Database())->connect(false);
    foreach(['usuarios','estados','municipios','seguimientos_vinculacion','aliados_asignaciones','asignaciones_territorio','permisos','roles','rol_permisos','formulario_aliados','formulario_programas','formulario_aliado_programas','formulario_aliado_procedencias','formulario_registros'] as $table) {
        if(!$db->query("SHOW TABLES LIKE '$table'")->num_rows) throw new RuntimeException('Falta dependencia: '.$table);
    }
    $expected=['bachillerato-2-anos'=>'Bachillerato en 2 años','bachillerato-286'=>'Bachillerato 286','ingles'=>'Inglés','ejecutivas'=>'Titulación - Ejecutivas','titulacion-experiencia-laboral'=>'Titulación por experiencia laboral','inscripciones-abiertas'=>'Inscripciones Abiertas'];
    $current=$db->query('SELECT codigo,nombre FROM formulario_programas')->fetch_all(MYSQLI_ASSOC);
    foreach($current as $row) {
        if(isset($expected[$row['codigo']]) && $expected[$row['codigo']]!==$row['nombre']) throw new RuntimeException('Código existente con otro nombre oficial: '.$row['codigo']);
        foreach($expected as $code=>$name) if($name===$row['nombre'] && $code!==$row['codigo']) throw new RuntimeException('Nombre existente con otro código; revisar catálogo sin equivalencias automáticas: '.$code);
    }
    if($apply) {
        $dir=realpath($backupDir); $root=realpath(__DIR__.'/..');
        if(!$dir || !is_writable($dir) || strpos(str_replace('\\','/',$dir).'/',str_replace('\\','/',$root).'/')===0) throw new RuntimeException('--apply requiere --backup-dir existente, privado y fuera de public_html/repositorio.');
        $path=$dir.'/aliados-registro-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
        $out=fopen($path,'x'); if(!$out) throw new RuntimeException('No fue posible crear el respaldo.'); chmod($path,0600);
        fwrite($out,"-- Respaldo previo Aliados/Registro. Contiene datos privados.\nSET NAMES utf8mb4;\n");
        foreach(['formulario_programas','formulario_aliados','formulario_aliado_programas','formulario_aliado_procedencias','aliados_manuales','formulario_aliado_registro','permisos','rol_permisos'] as $table) {
            if(!$db->query("SHOW TABLES LIKE '$table'")->num_rows) continue;
            $ddl=$db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1]; fwrite($out,$ddl.";\n");
            $rows=$db->query("SELECT * FROM `$table`",MYSQLI_USE_RESULT);
            while($row=$rows->fetch_assoc()) {
                $values=array_map(fn($v)=>$v===null?'NULL':"'".$db->real_escape_string($v)."'",array_values($row));
                fwrite($out,"INSERT INTO `$table` VALUES (".implode(',',$values).");\n");
            } $rows->free();
        }
        fclose($out); if(filesize($path)<100) throw new RuntimeException('Respaldo incompleto.');
        echo "Respaldo privado: $path\n";
        $sql=file_get_contents(__DIR__.'/../database/migrations/2026_10_21_aliados_registro.sql');
        $db->multi_query($sql); do { if($r=$db->store_result()) $r->free(); } while($db->more_results() && $db->next_result());
        if($db->errno) throw new RuntimeException('Migración incompleta: '.$db->error);
    }
    foreach(['aliados_manuales','formulario_aliado_registro'] as $table) if(!$db->query("SHOW TABLES LIKE '$table'")->num_rows) { echo "PENDIENTE: migración Aliados/Registro ($table). No se modificó la BD.\n";exit(2); }
    $orphans=$db->query('SELECT COUNT(*) n FROM formulario_aliado_registro r LEFT JOIN formulario_aliados a ON a.id=r.aliado_id LEFT JOIN aliados_manuales m ON m.id=r.manual_id LEFT JOIN seguimientos_vinculacion s ON s.id=r.seguimiento_id WHERE a.id IS NULL OR (r.manual_id IS NULL AND r.seguimiento_id IS NULL) OR (r.manual_id IS NOT NULL AND r.seguimiento_id IS NOT NULL) OR (r.manual_id IS NOT NULL AND m.id IS NULL) OR (r.seguimiento_id IS NOT NULL AND s.id IS NULL)')->fetch_assoc()['n'];
    if((int)$orphans) throw new RuntimeException('Se encontraron orígenes incoherentes; revisar antes de publicar.');
    echo "OK: catálogo, dependencias y orígenes. Motor: ".$db->server_info.". Sin cambios en Inscripción.\n";
} catch(Throwable $e) { fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1); }
