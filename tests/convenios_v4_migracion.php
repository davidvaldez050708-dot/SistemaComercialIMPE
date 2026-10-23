<?php
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^impe_test_handly_aliados_v4_[a-z0-9_]+$/D',getenv('IMPE_DB_NAME')?:''))exit(2);
require_once __DIR__.'/../config/db_connection.php';$db=(new Database())->connect(false);$checks=0;
$check=static function($v,$label)use(&$checks){if(!$v)throw new RuntimeException($label);$checks++;echo "OK $label\n";};
$private=sys_get_temp_dir().'/impe-v4-ddl-'.bin2hex(random_bytes(6));mkdir($private,0700);
$run=static function(array $args):array{
    $process=proc_open(array_merge([PHP_BINARY,__DIR__.'/../tools/migrar_convocatoria_borradores_v4.php'],$args),[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('No se pudo ejecutar el CLI de prueba.');$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out];
};
foreach(['vacio','con_datos'] as $case){
    $schema='impe_test_handly_aliados_v4_ddl_'.$case.'_'.bin2hex(random_bytes(4));$db->query("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");$db->select_db($schema);putenv('IMPE_DB_NAME='.$schema);
    $db->query('CREATE TABLE convocatorias (id INT PRIMARY KEY,titulo VARCHAR(100)) ENGINE=InnoDB');$db->query("INSERT INTO convocatorias VALUES (1,'Histórico intacto')");
    [$code,$out]=$run(['--check']);$check($code===0&&strpos($out,'PENDIENTE')!==false&&(int)$db->query('SHOW TABLES')->num_rows===1,'check sólo consulta · '.$case);
    [$code,$out]=$run(['--apply','--backup-dir='.$private]);$check($code===0&&strpos($out,'Respaldo: ')!==false,'apply crea únicamente tablas aditivas · '.$case);
    preg_match('/Respaldo: (.+)/',$out,$match);$journal=trim($match[1]);$check(is_file($journal),'respaldo de estructura privado confirmado · '.$case);
    [$code,$out]=$run(['--apply','--backup-dir='.$private]);$check($code===0&&strpos($out,'PENDIENTE')===false,'segundo apply es idempotente · '.$case);
    if($case==='con_datos')$db->query("INSERT INTO convocatoria_borradores (propietario_id,aliado_tipo,aliado_id,territorio_id,clave_activa,datos,actualizado_por) VALUES (4,'manual',1,1,REPEAT('a',64),'{}',4)");
    [$code,$out]=$run(['--restore='.$journal]);
    if($case==='vacio')$check($code===0&&$db->query('SHOW TABLES')->num_rows===1,'restore retira sólo las tablas nuevas vacías');
    else $check($code!==0&&(int)$db->query('SELECT COUNT(*) FROM convocatoria_borradores')->fetch_row()[0]===1,'restore bloquea eliminación de borradores existentes');
    $check($db->query('SELECT titulo FROM convocatorias WHERE id=1')->fetch_row()[0]==='Histórico intacto','históricos no se modifican al instalar/restaurar · '.$case);
}
echo "PASS $checks comprobaciones CLI/migración V4 · ".$db->server_info."\n";
