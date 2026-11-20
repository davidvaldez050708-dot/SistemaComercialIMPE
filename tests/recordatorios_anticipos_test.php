<?php
// Serialización y resolutores reales; no abre conexiones ni escribe en la BD.
require_once __DIR__ . '/../app/helpers/ReminderHelper.php';
require_once __DIR__ . '/../app/services/ReminderReunionFollowupService.php';

$clase = new ReflectionClass(ReminderReunionFollowupService::class);
$acuerdos = $clase->newInstanceWithoutConstructor();
$resolver = $clase->getMethod('resolverTipoAviso');
$ahora = time();
foreach ([12000 => '', 9000 => '3H', 3000 => '1H', 500 => '10M', -60 => 'VENCIDA'] as $segundos => $tipo) {
    $fila = [
        'id' => 9,
        'proxima_accion_texto' => 'Volver a llamar',
        'proxima_accion_at' => date('Y-m-d H:i:s', $ahora + $segundos),
        'nombre_entidad' => 'Prueba'
    ];
    $resultado = serializarRecordatoriosSeguimiento([$fila]);
    if ($resultado[0]['tipo_anticipo'] !== $tipo ||
        $resolver->invoke($acuerdos, $segundos) !== $tipo ||
        resolverTipoAvisoRecordatorio($segundos) !== $tipo ||
        $resultado[0]['fecha'] !== $fila['proxima_accion_at'] ||
        $resultado[0]['accion'] !== $fila['proxima_accion_texto']) {
        throw new RuntimeException('Anticipo o datos operativos incorrectos: ' . $segundos);
    }
}
echo "OK: campana conserva acción/fecha e informa los intervalos operativos actuales, aunque no haya un nuevo aviso de BD.\n";
