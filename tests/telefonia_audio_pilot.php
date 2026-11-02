<?php
declare(strict_types=1);
require __DIR__ . '/../app/helpers/TelefoniaAudioDispositivosPilot.php';
$s = ['usuario_id'=>30,'usuario'=>'Prueba.llamadas','rol_id'=>3];
$cases = [
    [false, $s, '155', []],
    [false, $s, '155', ['enabled'=>false]],
    [false, $s, '155', ['enabled'=>'true']],
    [true, $s, '155', ['enabled'=>true]],
    [false, $s, '150', ['enabled'=>true]],
    [false, array_replace($s,['usuario'=>'Mayra.mendoza']), '155', ['enabled'=>true]],
    [false, array_replace($s,['rol_id'=>4]), '155', ['enabled'=>true]],
    [false, array_replace($s,['rol_id'=>5]), '155', ['enabled'=>true]],
    [false, array_replace($s,['usuario_id'=>0]), '155', ['enabled'=>true]],
    [true, array_replace($s,['usuario'=>'recepcion','rol_id'=>5]), '100', ['enabled'=>true,'scope'=>'assigned']],
    [true, array_replace($s,['usuario'=>'analista','rol_id'=>4]), '101', ['enabled'=>true,'scope'=>'assigned']],
    [true, array_replace($s,['usuario'=>'asesora']), '150', ['enabled'=>true,'scope'=>'assigned']],
    [false, $s, '', ['enabled'=>true,'scope'=>'assigned']],
    [false, $s, 'abc', ['enabled'=>true,'scope'=>'assigned']],
    [false, array_replace($s,['usuario_id'=>0]), '100', ['enabled'=>true,'scope'=>'assigned']],
    [false, $s, '155', ['enabled'=>true,'scope'=>'otro']],
];
foreach ($cases as [$expected,$session,$extension,$cfg]) {
    if (telefoniaAudioDispositivosPilot($session,$extension,$cfg) !== $expected) throw new RuntimeException('Falla de aislamiento del piloto');
}
echo 'PASS: ' . count($cases) . " controles de activación por bandera, usuario, extensión y rol.\n";
