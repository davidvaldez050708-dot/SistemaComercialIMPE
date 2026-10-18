<?php
// Ejecutar: php tests/telefonia_mis_llamadas_propiedad_smoke.php
// No requiere base de datos; comprueba aislamiento y exclusión de PBX ambiguos.
require_once __DIR__ . '/../app/services/TelefoniaMisLlamadasService.php';
$ejemplos = [
    ['usuario_id'=>101, 'pbx_call_id'=>'llamada-a', 'extension'=>'101'],
    ['usuario_id'=>100, 'pbx_call_id'=>'llamada-b', 'extension'=>'100'],
    ['usuario_id'=>0, 'pbx_call_id'=>'sin-dueno', 'extension'=>'101'],
    ['usuario_id'=>101, 'pbx_call_id'=>'', 'extension'=>'101'],
    ['usuario_id'=>101, 'pbx_call_id'=>'llamada-c', 'extension'=>'101'],
];
$propias = TelefoniaMisLlamadasService::filtrarPropias($ejemplos, 101);
if (count($propias)!==2 ||
    $propias[0]['pbx_call_id']!=='llamada-a' ||
    $propias[1]['pbx_call_id']!=='llamada-c' ||
    TelefoniaMisLlamadasService::filtrarPropias($ejemplos, 0)!==[]) {
    fwrite(STDERR,"FALLO: se filtraron incorrectamente llamadas ajenas o ambiguas.\n");
    exit(1);
}
echo "OK: aislamiento de historial por usuario y PBX verificado\n";
