<?php
require __DIR__.'/../app/services/DenueCircuitBreaker.php';
function comprobar($valor, string $mensaje): void {
    if (!$valor) throw new RuntimeException($mensaje);
}
$dir = sys_get_temp_dir().'/denue-circuito-'.bin2hex(random_bytes(8));
mkdir($dir, 0700);
$ahora = 1000;
try {
    $a = new DenueCircuitBreaker($dir.'/estado.json', function () use (&$ahora) { return $ahora; });
    $b = new DenueCircuitBreaker($dir.'/estado.json', function () use (&$ahora) { return $ahora; });
    $t = $a->admitir(); $tardio = $b->admitir();
    comprobar(is_array($t) && is_array($tardio), 'Circuito disponible');
    comprobar($a->informar($t,true), 'Primer fallo abre circuito');
    comprobar($b->admitir() === null, 'Pausa compartida');
    comprobar(!$b->informar($tardio,false), 'Respuesta tardía no altera pausa');
    $ahora += 119;
    comprobar($a->admitir() === null, 'Pausa de 120 segundos');
    $ahora++;
    $sonda = $a->admitir();
    comprobar(!empty($sonda['sonda']) && $b->admitir() === null, 'Sonda única');
    comprobar($a->informar($sonda,true), 'Segundo fallo');
    $ahora += 299;
    comprobar($b->admitir() === null, 'Retroceso de 300 segundos');
    $ahora++;
    $sonda = $b->admitir();
    comprobar($b->informar($sonda,false), 'Recuperación');
    comprobar(empty($a->admitir()['sonda']), 'Circuito normal');
    comprobar(DenueCircuitBreaker::falloTransitorio(0,28,false), 'Timeout');
    comprobar(DenueCircuitBreaker::falloTransitorio(429,0,false), 'Rate limit');
    comprobar(DenueCircuitBreaker::falloTransitorio(503,0,false), 'HTTP 503');
    comprobar(!DenueCircuitBreaker::falloTransitorio(401,0,false), 'No confundir autorización con timeout');
    comprobar(!DenueCircuitBreaker::falloTransitorio(200,0,true), 'Respuesta válida');
    echo "Circuito DENUE: OK\n";
} finally {
    foreach (glob($dir.'/*') ?: [] as $f) unlink($f);
    rmdir($dir);
}
