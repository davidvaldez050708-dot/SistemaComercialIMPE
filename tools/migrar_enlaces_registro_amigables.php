<?php
if(PHP_SAPI!=='cli')exit(2);
require_once __DIR__.'/../config/db_connection.php';
try{
    $args=array_slice($argv,1);if(count($args)!==1||!in_array($args[0],['--check','--apply'],true))throw new RuntimeException('Usa --check o --apply.');
    $db=(new Database())->connect(false);
    if($db->query("SHOW TABLES LIKE 'registro_enlaces_amigables'")->num_rows){
        $engine=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='registro_enlaces_amigables'")->fetch_row()[0];
        $cols=array_column($db->query('SHOW COLUMNS FROM registro_enlaces_amigables')->fetch_all(MYSQLI_ASSOC),'Type','Field');
        $indexes=$db->query('SHOW INDEX FROM registro_enlaces_amigables')->fetch_all(MYSQLI_ASSOC);
        $unique=[];foreach($indexes as $i)if(!(int)$i['Non_unique'])$unique[$i['Key_name']][]=$i['Column_name'];
        if($engine!=='InnoDB'||($cols['codigo']??'')!=='varbinary(12)'||($cols['destino_hash']??'')!=='binary(32)'||($cols['parametros']??'')!=='text'||!in_array(['codigo'],$unique,true)||!in_array(['destino_hash'],$unique,true))throw new RuntimeException('Estructura incompatible; no se modificó.');
        echo "OK: enlaces amigables de Registro instalados.\n";exit;
    }
    if($args[0]==='--check'){echo "PENDIENTE: tabla de equivalencias de Registro. SITIO INTACTO. SQL NO ejecutado.\n";exit;}
    $db->query('CREATE TABLE registro_enlaces_amigables (codigo VARBINARY(12) NOT NULL PRIMARY KEY,destino_hash BINARY(32) NOT NULL UNIQUE,parametros TEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    echo "APLICADO: tabla aditiva de enlaces amigables. Tokens, programas y convocatorias sin cambios.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
