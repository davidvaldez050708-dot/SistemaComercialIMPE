<?php
// Tests aislados: sin conectarse a la PBX, sin llamadas, BD o datos reales.
$_SESSION=['usuario_id'=>22,'rol_id'=>3,'permisos'=>[
    'ventas.embudo.ver','ventas.embudo.mover',
    'ventas.recuperacion.gestionar'
]];
require_once __DIR__.'/../app/services/EmbudoComercialResultadoBridge.php';
function validar($v,string $mensaje):void {
    if (!$v) throw new RuntimeException('FALLÓ: '.$mensaje);
}
$good=[
    ['+527771234567','7771234567','México'],
    ['+527771234567','777 123 4567','México'],
    ['+527771234567','(777) 123-4567','Mexico'],
    ['+527771234567','+527771234567','México'],
    ['+527771234567','527771234567','México'],
    ['+12025550123','+12025550123','Estados Unidos'],
];
foreach($good as $case) {
    validar(EmbudoComercialResultadoBridge::mismoNumeroAsignado(
        $case[0],$case[1],$case[2]), 'debe coincidir '.implode(' | ',$case));
}
$bad=[
    ['+527771234567','7771234568','México'],
    ['+527771234567','777123456','México'],
    ['+527771234567','+7771234567','México'],
    ['+527771234567','17771234567','México'],
    ['+527771234567','01527771234567','México'],
    ['+527771234567','ext7771234567','México'],
    ['+527771234567',' 7771234567 ext 101','México'],
    ['+527771234567','7771234567','Estados Unidos'],
    ['+527771234567','7771234567',null],
    ['+527771234567','7771234567',''],
    ['+12025550123','2025550123','Estados Unidos'],
    ['+447911123456','7911123456','Reino Unido'],
    ['+528001112222','8001112222','Canadá'],
    ['+5217771234567','7771234567','México'],
    ['+527771234567','0007771234567','México']
];
foreach($bad as $case) {
    validar(!EmbudoComercialResultadoBridge::mismoNumeroAsignado(
        $case[0],$case[1],$case[2]), 'rechazar coincidencia insegura');
}
validar(EmbudoComercialResultadoBridge::numeroCanonico('7771234567')==='7771234567',
    'el método de normalización anterior no se alteró');
validar(EmbudoComercialResultadoBridge::numeroCanonico('+52 (777)123-4567')==='527771234567',
    'prefijo internacional intacto');
validar(EmbudoComercialResultadoBridge::numeroCanonico('12345')==='',
    'cortos se siguen rechazando');
echo "CHECK OK: +52/nacional 10 únicamente con México, sin sufijos ni coincidencias inseguras.\n";
