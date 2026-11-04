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
    ['pbx_call_id'=>'mk-1','tipo'=>'Entrante','numero'=>'5550123','fecha'=>'2026-10-18 10:00:00','estado'=>'Transferida','contestada'=>true,'segundos'=>39],
    ['pbx_call_id'=>'mk-2','tipo'=>'Saliente','numero'=>'5550555','fecha'=>'2026-10-18 11:00:00','estado'=>'Completada','contestada'=>true,'segundos'=>19],
    ['pbx_call_id'=>'','tipo'=>'Entrante'],
],100,'100');
if (count($marketing)!==2 || !$marketing[0]['transferida']
    || $marketing[0]['direccion']!=='Entrante'
    || $marketing[1]['direccion']!=='Saliente'
    || $marketing[0]['usuario_id']!==100) {
    fwrite(STDERR, "FALLO: normalización de recepción Marketing.\n");
    exit(1);
}
$propia=[
    ['pbx_call_id'=>'pbx-01','extension'=>'101','usuario_id'=>101,
     'direccion'=>'Saliente','fecha'=>'2026-11-04 12:00:00','resultado'=>'',
     'conectada'=>false,'segundos'=>0]
];
$op=[
    ['pbx_call_id'=>'pbx-01','fuente'=>'PBX','tipo_llamada'=>'SALIENTE',
     'fecha'=>'2026-11-04 12:00:00','clasificacion'=>'SIN_IDENTIFICAR','contestada'=>false],
    ['pbx_call_id'=>'pbx-02','fuente'=>'PBX','tipo_llamada'=>'SALIENTE',
     'fecha'=>'2026-11-04 12:05:00','numero'=>'+525512345678',
     'clasificacion'=>'VINCULADA','pbx_conversacion_confirmada'=>true,'duracion'=>22],
    ['pbx_call_id'=>'sdk-test-sesion-01','fuente'=>'SDK_WEBRTC','tipo_llamada'=>'ENTRANTE',
     'fecha'=>'2026-11-04 12:06:00','clasificacion'=>'PENDIENTE',
     'contestada'=>true,'duracion'=>12],
    ['pbx_call_id'=>'sin-propietario','fuente'=>'OPERACION','tipo_llamada'=>'ENTRANTE',
     'fecha'=>'2026-11-04 12:08:00'],
    ['pbx_call_id'=>'pbx-04','fuente'=>'PBX','tipo_llamada'=>'SALIENTE',
     'fecha'=>'2026-09-01 11:00:00']
];
$unidos=TelefoniaMisLlamadasService::integrarAnalista(
    $propia,$op,101,'101','2026-11-01','2026-11-04');
if (count($unidos)!==3 || $unidos[0]['pbx_call_id']!=='sdk-test-sesion-01' ||
    empty($unidos[0]['observada']) || !empty($unidos[0]['conectada']) ||
    $unidos[1]['pbx_call_id']!=='pbx-02' || !empty($unidos[1]['efectiva']) ||
    $unidos[2]['resultado']!=='SIN_IDENTIFICAR') {
    fwrite(STDERR,"FALLO: consolidación de llamadas PBX y SDK del Analista.\n");
    exit(1);
}
$soloSalientes=TelefoniaMisLlamadasService::integrarAnalista(
    $propia,$op,101,'101','2026-11-01','2026-11-04','Saliente');
if (count($soloSalientes)!==2 ||
    count(TelefoniaMisLlamadasService::integrarAnalista([], $op,101,'', '2026-11-01','2026-11-04'))!==0) {
    fwrite(STDERR,"FALLO: filtro de sentido o ausencia de extensión propia.\n");
    exit(1);
}
echo "OK: historial único, salientes clasificadas, entrantes WebRTC observadas y filtros seguros\n";
echo "OK: aislamiento de historial, extensión y sentido de devolución\n";
