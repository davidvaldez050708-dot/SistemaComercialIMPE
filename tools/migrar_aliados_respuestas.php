<?php
// Instalación explícita y aditiva; --check sólo consulta. No altera tablas previas.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/db_connection.php';
try {
    if(count(array_intersect(['--check','--apply'],$argv))!==1)throw new RuntimeException('Usa --check o --apply.');
    $db=(new Database())->connect(false);
    $sql=file_get_contents(__DIR__.'/../database/migrations/2026_10_23_aliados_respuestas.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z_]+) \([\s\S]+?;/',$sql,$matches,PREG_SET_ORDER);
    if(count($matches)!==3)throw new RuntimeException('Archivo de estructura incompleto.');
    $pending=[];
    foreach($matches as $m){
        $table=$m[1];
        if(!$db->query("SHOW TABLES LIKE '$table'")->num_rows){$pending[$table]=$m[0];continue;}
        $cols=array_column($db->query("SHOW COLUMNS FROM $table")->fetch_all(MYSQLI_ASSOC),'Field');
        preg_match_all('/^    ([a-z_]+) (?:BIGINT|INT|VARCHAR|DATETIME|TINYINT)/m',$m[0],$expected);
        if(array_diff($expected[1],$cols))throw new RuntimeException('Otra estructura en '.$table.'. No se modificó.');
    }
    if(!$pending){echo "OK: respuestas por convocatoria instaladas. Sin cambios.\n";exit;}
    echo 'PENDIENTE: '.implode(', ',array_keys($pending))."\n";
    if(in_array('--check',$argv,true)){echo "SQL NO ejecutado. SITIO INTACTO.\n";exit(2);}
    if(!$db->query("SHOW TABLES LIKE 'aliados_manuales'")->num_rows)throw new RuntimeException('Falta instalar Aliados manuales.');
    foreach($pending as $table=>$ddl){if(!$db->query($ddl))throw new RuntimeException('No fue posible crear '.$table);echo 'CREADA: '.$table."\n";}
    echo "OK: respuestas instaladas. Envíos y expedientes sin cambios.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
