<?php
// Réplica temporal sin configuración ni datos de producción. Circuito abierto:
 // ninguna llamada debe alcanzar el proveedor.
$root = sys_get_temp_dir().'/denue-respaldo-'.bin2hex(random_bytes(8));
mkdir($root.'/app/services',0700,true);
mkdir($root.'/config',0700,true);
mkdir($root.'/storage/cache/denue',0700,true);
foreach (['DenueService.php','DenueCircuitBreaker.php'] as $f) {
    copy(__DIR__.'/../app/services/'.$f,$root.'/app/services/'.$f);
}
file_put_contents($root.'/config/api_keys.php',"<?php define('DENUE_TOKEN','fixture'); define('DENUE_BASE_URL','http://127.0.0.1:1');");
require $root.'/app/services/DenueService.php';
function verificar($condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
}
function limpiar($p): void {
    if (is_dir($p)) {
        foreach (scandir($p) as $f) if ($f!=='.' && $f!=='..') limpiar($p.'/'.$f);
        rmdir($p);
    } else unlink($p);
}
try {
    $servicio = new DenueService();
    $circuito = new DenueCircuitBreaker($root.'/storage/cache/denue/circuito_v1.json');
    $circuito->informar($circuito->admitir(),true);
    $sectores = ['93','61','62','81','31','54','55','52','48'];
    $ref = new ReflectionMethod(DenueService::class,'obtenerArchivoCacheRecomendados');
    $archivo = $ref->invoke($servicio,'07','0',$sectores);
    $filas = [['clave_origen'=>'DENUE:1','nombre'=>'Candidato sintético','id_origen'=>'1']];
    file_put_contents($archivo,json_encode($filas));
    touch($archivo,time()-3600);
    clearstatcache();
    $r = $servicio->buscarCandidatosRecomendados('07','0',20);
    verificar($r['ok'] && !empty($r['cache_respaldo']) && $r['resultados']===$filas,'Recuperar caché vencida en contingencia');
    verificar(filemtime($archivo) < time()-1800,'No refrescar artificialmente la antigüedad');
    $r = $servicio->buscarCandidatosRecomendados('17','0',20);
    verificar(!$r['ok'],'No inventar datos ni reutilizar otro territorio');
    touch($archivo,time()-8*86400);
    clearstatcache();
    $r = $servicio->buscarCandidatosRecomendados('07','0',20);
    verificar(!$r['ok'],'No utilizar caché superior a siete días');
    echo "Respaldo DENUE: OK\n";
} finally {
    limpiar($root);
}
