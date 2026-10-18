<?php
// Prueba de plantilla sin MySQL, usuarios reales ni servicios externos.
define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', '/');
$misLlamadas = [
 'filas'=>[
   ['usuario_id'=>101,'pbx_call_id'=>'test-1','direccion'=>'Entrante',
    'numero'=>'<script>alert(1)</script>','extension'=>'101',
    'fecha'=>'2026-10-18 10:00:00','segundos'=>39,'conectada'=>true,
    'resultado'=>'SOLICITO_INFORMACION'],
 ],
 'total'=>1,'conectadas'=>1,'entrantes'=>1,'salientes'=>0,
 'pagina'=>1,'paginas'=>2,
 'filtros'=>['desde'=>'2026-10-01','hasta'=>'2026-10-18','direccion'=>''],
 'limitacion'=>'Solo lectura'
];
ob_start();
require ROOT_PATH . '/app/views/telefonia/mis_llamadas.php';
$html = ob_get_clean();
$checks = [
 'escape de número' => !str_contains($html, '<script>alert(1)</script>')
    && str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'),
 'título' => str_contains($html, 'Mis llamadas'),
 'extensión' => str_contains($html, 'Ext. 101'),
 'duración' => str_contains($html, '00:39'),
 'paginación' => str_contains($html, 'pagina=2'),
 'sin controles telefónicos activos' => !str_contains($html, 'data-call-start')
];
foreach ($checks as $nombre=>$ok) {
 if (!$ok) {fwrite(STDERR, "ERROR: $nombre\n"); exit(1);}
}
echo "OK: vista Mis llamadas, escape y paginación\n";
