<?php
// Ejecutar: php tests/telefonia_mis_llamadas_propiedad_smoke.php
// No requiere base de datos; comprueba aislamiento y exclusión de PBX ambiguos.
require_once __DIR__ . '/../app/services/TelefoniaMisLlamadasService.php';
$ejemplos = [
    ['usuario_id'=>101, 'pbx_call_id'=>'llamada-a', 'extension'=>'101', 'direccion'=>'Saliente'],
    ['usuario_id'=>100, 'pbx_call_id'=>'llamada-b', 'extension'=>'100', 'direccion'=>'Saliente'],
    ['usuario_id'=>0, 'pbx_call_id'=>'sin-dueno', 'extension'=>'101'],
    ['usuario_id'=>101, 'pbx_call_id'=>'', 'extension'=>'101'],
    ['usuario_id'=>101, 'pbx_call_id'=>'llamada-c', 'extension'=>'101', 'direccion'=>'Saliente'],
];
$propias = TelefoniaMisLlamadasService::filtrarPropias($ejemplos, 101);
if (count($propias)!==2 ||
    $propias[0]['pbx_call_id']!=='llamada-a' ||
    $propias[1]['pbx_call_id']!=='llamada-c' ||
    TelefoniaMisLlamadasService::filtrarPropias($ejemplos, 0)!==[]) {
    fwrite(STDERR,"FALLO: se filtraron incorrectamente llamadas ajenas o ambiguas.\n");
    exit(1);
}
$enriquecidas = TelefoniaMisLlamadasService::enriquecerDevoluciones($propias, [
    ['id'=>1,'usuario_id'=>101,'extension'=>'101','pbx_call_id'=>'llamada-a','resultado'=>'BUZON_VOZ','seguimiento_id'=>9],
    ['id'=>2,'usuario_id'=>100,'extension'=>'100','pbx_call_id'=>'llamada-c','resultado'=>'CONTACTO_CORRECTO','seguimiento_id'=>8],
    ['id'=>3,'usuario_id'=>101,'extension'=>'101','pbx_call_id'=>'llamada-x','resultado'=>'SIN_RESPUESTA','seguimiento_id'=>0],
],101);
if (count($enriquecidas)!==2
    || ($enriquecidas[0]['resultado_devolucion']??'')!=='BUZON_VOZ'
    || isset($enriquecidas[1]['resultado_devolucion'])) {
    fwrite(STDERR,"FALLO: devolución ajena o duplicada en el historial.\n");
    exit(1);
}
$sinCoincidencia = TelefoniaMisLlamadasService::enriquecerDevoluciones([
    ['usuario_id'=>101,'pbx_call_id'=>'llamada-a','extension'=>'101','direccion'=>'Entrante']
],[
    ['id'=>4,'usuario_id'=>101,'extension'=>'101','pbx_call_id'=>'llamada-a','resultado'=>'CONTACTO_CORRECTO']
],101);
if (isset($sinCoincidencia[0]['resultado_devolucion'])) {
    fwrite(STDERR,"FALLO: se vinculó una devolución a un tramo entrante.\n");
    exit(1);
}
$muestra = range(1, 1050);
$primera = TelefoniaMisLlamadasService::paginar($muestra, 1);
$ultima = TelefoniaMisLlamadasService::paginar($muestra, 999);
$vacia = TelefoniaMisLlamadasService::paginar([], -3);
if ($primera['paginas'] !== 53 || count($primera['filas']) !== 20
    || $ultima['pagina'] !== 53 || $ultima['filas'][0] !== 1041
    || count($ultima['filas']) !== 10 || $vacia['pagina'] !== 1
    || $vacia['paginas'] !== 1) {
    fwrite(STDERR,"FALLO: paginación incompleta o fuera de rango.\n");
    exit(1);
}
$marketing = TelefoniaMisLlamadasService::normalizarMarketing([
    ['pbx_call_id'=>'mk-1','tipo'=>'Entrante','numero'=>'5550123','fecha'=>'2026-10-18 10:00:00','estado'=>'Transferida','atendida'=>true,'segundos'=>39],
    ['pbx_call_id'=>'mk-2','tipo'=>'Saliente','numero'=>'5550555','fecha'=>'2026-10-18 11:00:00','estado'=>'Completada','atendida'=>true,'segundos'=>19],
    ['pbx_call_id'=>'','tipo'=>'Entrante'],
],100,'100');
if (count($marketing)!==2 || !$marketing[0]['transferida']
    || $marketing[0]['direccion']!=='Entrante'
    || $marketing[1]['direccion']!=='Saliente'
    || $marketing[0]['usuario_id']!==100
    || !$marketing[0]['conectada'] || !$marketing[1]['conectada']) {
    fwrite(STDERR, "FALLO: normalización de recepción Marketing.\n");
    exit(1);
}
echo "OK: aislamiento de historial, extensión y sentido de devolución\n";
