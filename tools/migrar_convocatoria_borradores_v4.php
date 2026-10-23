<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/db_connection.php';
try{
    $options=getopt('',['check','apply','restore:','backup-dir:']);
    if((int)isset($options['check'])+(int)isset($options['apply'])+(int)isset($options['restore'])!==1)throw new RuntimeException('Usar --check, --apply --backup-dir=RUTA o --restore=RESPALDO.');
    $db=(new Database())->connect(false);$database=$db->query('SELECT DATABASE()')->fetch_row()[0];
    $tables=[
        'convocatoria_borradores'=>['id','propietario_id','aliado_tipo','aliado_id','territorio_id','clave_activa','version','estado','datos','resultado','creado_at','actualizado_at','actualizado_por'],
        'convocatoria_borrador_imagenes'=>['borrador_id','programa_codigo','referencia','sha256','nombre','mime','bytes','actualizado_at','actualizado_por'],
        'convocatoria_borrador_operaciones'=>['borrador_id','operacion','tipo','peticion_sha256','respuesta','creado_at'],
    ];
    $schema=[];
    foreach($tables as $table=>$fields){
        $q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->bind_param('s',$table);$q->execute();$row=$q->get_result()->fetch_assoc();
        if(!$row)continue;
        $columns=array_column($db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC),'Field');
        if($row['ENGINE']!=='InnoDB'||array_diff($fields,$columns)||array_diff($columns,$fields))throw new RuntimeException('Estructura existente no compatible: '.$table);
        $unique=[];foreach($db->query("SHOW INDEX FROM `$table`")->fetch_all(MYSQLI_ASSOC) as $index)if((int)$index['Non_unique']===0)$unique[$index['Key_name']][(int)$index['Seq_in_index']]=$index['Column_name'];
        $keys=[];foreach($unique as $index){ksort($index);$keys[]=implode(',',array_values($index));}
        $required=$table==='convocatoria_borradores'?['id','clave_activa']:($table==='convocatoria_borrador_imagenes'?['borrador_id,programa_codigo','referencia']:['borrador_id,operacion']);
        if(array_diff($required,$keys))throw new RuntimeException('Faltan claves de concurrencia/idempotencia: '.$table);
        $schema[$table]=$db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
    }
    $root=str_replace('\\','/',realpath(__DIR__.'/..'));
    $private=static function(string $path)use($root):string{
        $real=realpath($path);if(!$real)throw new RuntimeException('La ruta privada debe existir.');
        $normalized=str_replace('\\','/',$real);if($normalized===$root||strpos($normalized,$root.'/')===0)throw new RuntimeException('El respaldo debe quedar fuera del sitio público.');return $real;
    };
    if(isset($options['restore'])){
        $file=$private($options['restore']);$backup=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(($backup['version']??0)!==1||($backup['database']??null)!==$database)throw new RuntimeException('Respaldo de otra BD o versión.');
        foreach($backup['creadas'] as $table=>$ddl){
            if(!isset($tables[$table])||($schema[$table]??null)!==$ddl)throw new RuntimeException('La estructura cambió después de instalar: '.$table);
            if((int)$db->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0])throw new RuntimeException('No se retiraron tablas: contienen borradores, imágenes o historial. Se conserva toda la información.');
        }
        foreach(array_reverse(array_keys($backup['creadas'])) as $table)$db->query("DROP TABLE `$table`");
        echo "RESTAURADO: sólo tablas creadas por esta entrega y vacías. Históricos intactos.\n";exit;
    }
    $pending=array_diff(array_keys($tables),array_keys($schema));
    if(!$pending){echo "OK: persistencia de borradores V4 instalada.\n";exit;}
    echo 'PENDIENTE: '.implode(', ',$pending).".\n";
    if(isset($options['check'])){echo "SITIO INTACTO. SQL de escritura NO ejecutado.\n";exit;}
    $dir=$private((string)($options['backup-dir']??''));if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('Directorio privado no escribible.');
    $file=$dir.'/borradores-v4-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
    $backup=['version'=>1,'database'=>$database,'creadas'=>[]];
    $save=static function()use(&$backup,$file):void{$json=json_encode($backup,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(file_put_contents($file,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('No se pudo guardar el respaldo de estructura.');chmod($file,0600);};$save();
    $sql=preg_replace('/^--.*$/m','',file_get_contents(__DIR__.'/../database/migrations/2026_10_23_convocatoria_borradores_v4.sql'));
    foreach(explode(';',$sql) as $query){
        $query=trim($query);if($query==='')continue;
        if(!preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/',$query,$match)||!isset($tables[$match[1]]))throw new RuntimeException('DDL fuera de la migración revisada.');
        $table=$match[1];if(isset($schema[$table]))continue;
        $db->query($query);$backup['creadas'][$table]=$db->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];$save();echo "CREADA: $table\n";
    }
    echo "OK: persistencia de borradores V4 instalada. Respaldo: $file\nNo se modificaron convocatorias, programas ni tokens existentes.\n";
}catch(Throwable $e){fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");exit(1);}
