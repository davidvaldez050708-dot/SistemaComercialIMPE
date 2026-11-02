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
];
foreach ($cases as [$expected,$session,$extension,$cfg]) {
    if (telefoniaAudioDispositivosPilot($session,$extension,$cfg) !== $expected) throw new RuntimeException('Falla de aislamiento del piloto');
}
echo 'PASS: ' . count($cases) . " controles de activación por bandera, usuario, extensión y rol.\n";
