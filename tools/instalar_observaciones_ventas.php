<?php
// Ejecutar sólo desde SSH. Crea una tabla nueva; no actualiza datos existentes.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$args=getopt('',['site:','check','apply']);
try {
    $root=realpath($args['site']??'');
    if(!$root||!is_file($root.'/index.php')||count(array_intersect(['check','apply'],array_keys($args)))!==1)
        throw new RuntimeException('Indica --site y exactamente --check o --apply.');
    $sql=file_get_contents(__DIR__.'/ventas_observaciones.sql');
    if(!is_string($sql)||hash('sha256',str_replace("\r\n","\n",$sql))!=='dfde0ee0f12ee1adc382209aa7568abcef7cf35c0d7348167a23acf58550c559')
        throw new RuntimeException('La migración no coincide con la versión verificada.');
    require $root.'/config/db_connection.php';
    $db=(new Database())->connect(false);
    foreach(['usuarios'=>['id','nombre','apellidos','rol_id','estado'],
        'telefonia_ventas_resultados'=>['id','usuario_id','extension','pbx_call_id'],
        'telefonia_ventas_llamadas'=>['usuario_id','extension','pbx_call_id']] as $table=>$required){
        $columns=array_column($db->query('SHOW COLUMNS FROM '.$table)->fetch_all(MYSQLI_ASSOC),'Field');
        if(array_diff($required,$columns))throw new RuntimeException('Estructura no compatible: '.$table);
    }
    $exists=static function()use($db):bool{return (bool)$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_ventas_observaciones'")->num_rows;};
    $verify=static function()use($db):void{
        $columns=$db->query('SHOW COLUMNS FROM telefonia_ventas_observaciones')->fetch_all(MYSQLI_ASSOC);
        $types=['id'=>'bigint','resultado_id'=>'bigint','autor_usuario_id'=>'int','extension'=>'varchar(12)','pbx_call_id'=>'varchar(100)',
            'autor_nombre'=>'varchar(255)','nota'=>'varchar(1000)','token'=>'char(32)','created_at'=>'datetime'];
        if(array_column($columns,'Field')!==array_keys($types))throw new RuntimeException('La tabla de observaciones tiene otra estructura. No se modificó.');
        foreach($columns as $c){
            if(!str_starts_with(strtolower($c['Type']),$types[$c['Field']])||$c['Null']!=='NO')throw new RuntimeException('Tipo de columna incompatible: '.$c['Field']);
            if($c['Field']==='id'&&($c['Key']!=='PRI'||!str_contains($c['Extra'],'auto_increment')))throw new RuntimeException('Identificador no compatible.');
        }
        $unique=[];foreach($db->query('SHOW INDEX FROM telefonia_ventas_observaciones')->fetch_all(MYSQLI_ASSOC) as $i)
            if((int)$i['Non_unique']===0)$unique[$i['Key_name']][(int)$i['Seq_in_index']]=$i['Column_name'];
        $found=false;foreach($unique as $parts){ksort($parts);if(array_values($parts)===['autor_usuario_id','token'])$found=true;}
        if(!$found)throw new RuntimeException('Falta la clave de reintentos de las observaciones.');
        $meta=$db->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_ventas_observaciones'")->fetch_assoc();
        if(strcasecmp($meta['ENGINE'],'InnoDB')!==0)throw new RuntimeException('La tabla debe usar transacciones InnoDB.');
    };
    if($exists()){$verify();echo "ACTUALIZADO: tabla de observaciones adicionales. Notas existentes conservadas.\n";exit;}
    if(isset($args['check'])){echo "COMPATIBLE: falta crear la tabla independiente de observaciones. CHECK OK: sin escrituras.\n";exit;}
    // Es una única sentencia CREATE TABLE IF NOT EXISTS. Sin ALTER, UPDATE ni DELETE.
    $db->query($sql);$verify();
    echo "APLICADO: tabla de observaciones adicionales preparada. Resultados y datos anteriores intactos.\n";
} catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage().PHP_EOL);exit(1);}
