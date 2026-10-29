<?php
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$modo=$argv[1]??'--check';
if ($argc>2 || !in_array($modo,['--check','--apply'],true)) {fwrite(STDERR,"Uso: --check|--apply\n");exit(2);}
require_once __DIR__.'/../config/db_connection.php';
try {
    $db=(new Database())->connect(false);
    if ($modo==='--apply') {
        $sql=file_get_contents(__DIR__.'/../database/migrations/2026_10_28_agenda_comentarios_vistos.sql');
        foreach(explode(';',$sql) as $s)if(trim($s)!=='')$db->query($s);
    }
    $db->query('SELECT comentario_id,lector_id,leido_at FROM agenda_comentarios_vistos_avisos LIMIT 0');
    $keys=$db->query("SHOW INDEX FROM agenda_comentarios_vistos_avisos WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);
    usort($keys,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
    if(array_column($keys,'Column_name')!==['comentario_id','lector_id'])throw new RuntimeException('Estructura incompatible.');
    echo "OK: avisos de lectura al autor preparados. Sin envíos ni cambios en reuniones o permisos.\n";
} catch(Throwable $e) {fwrite(STDERR,"Avisos de lectura no disponibles. Revisa la instalación de la tabla nueva. Código=".(int)$e->getCode()."\n");exit(1);}
