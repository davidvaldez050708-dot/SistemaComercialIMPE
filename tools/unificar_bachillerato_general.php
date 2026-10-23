<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/db_connection.php';
require_once __DIR__.'/lib/UnificarBachillerato.php';
try {
    $options=getopt('',['check','apply','restore:','backup-dir:']);
    $modes=(int)isset($options['check'])+(int)isset($options['apply'])+(int)isset($options['restore']);
    if($modes!==1) throw new RuntimeException('Usar --check, --apply --backup-dir=RUTA o --restore=RESPALDO.');
    $migration=new UnificarBachillerato((new Database())->connect(false));
    if(isset($options['check'])) {
        $report=$migration->check();
        foreach($report['tablas'] as $table=>$count) echo "$table: $count acciones pendientes\n";
        echo ($report['acciones']?'PENDIENTE: '.$report['acciones'].' acciones.':'OK: un único Bachillerato General.')."\nSITIO INTACTO. SQL de escritura NO ejecutado.\n";
    } else {
        $root=str_replace('\\','/',realpath(__DIR__.'/..'));
        $path=realpath($options['backup-dir']??($options['restore']??''));
        if(!$path) throw new RuntimeException('La ruta privada debe existir.');
        $normalized=str_replace('\\','/',$path);
        if($normalized===$root||strpos($normalized,$root.'/')===0) throw new RuntimeException('El respaldo debe estar fuera de public_html/repositorio.');
        if(isset($options['apply'])) {
            if(!is_dir($path)||!is_writable($path)) throw new RuntimeException('Directorio privado no escribible.');
            $backup=$migration->apply($path);
            echo $backup?"APLICADO. Respaldo privado: $backup\n":"SIN CAMBIOS: unificación ya aplicada.\n";
        } else {$migration->restore($path);echo "RESTAURADO: sólo los cambios de esta unificación.\n";}
    }
} catch(Throwable $error){fwrite(STDERR,'ERROR: '.$error->getMessage()."\n");exit(1);}
