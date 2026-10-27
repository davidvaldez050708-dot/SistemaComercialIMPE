<?php
/** Reglas de supervisión: prueba pura, sin conexión, grabaciones ni SQL de escritura. */
declare(strict_types=1);
$service = file_get_contents(__DIR__ . '/../app/services/TelefoniaControlService.php');
if (!is_string($service)) throw new RuntimeException('No se pudo leer el servicio.');
$start = strpos($service, 'public static function estadoFinalPbx(');
$end = strpos($service, '    public function consultar(', $start);
if ($start === false || $end === false) throw new RuntimeException('Falta clasificador.');
$method = substr($service, $start, $end - $start);
eval('final class TelephonySupervisionPbxIsolated {' . $method . '}');
$cases = [
    ['Ocupada',true,false,true,'busy'],
    ['Cancelada',true,false,true,'cancel'],
    ['Sin respuesta',true,false,true,'no answer'],
    ['Sin respuesta',true,false,true,'no_answer'],
    ['Fallida',true,false,true,'failed'],
    ['Conectada',true,true,true,'answered'],
    ['Conectada',true,true,true,'busy'],
    ['Sin cierre confirmado',true,false,false,'cancel'],
    ['Sin conexión confirmada',true,false,true,''],
    ['Sin conexión confirmada',false,false,true,'busy'],
    ['Sin cierre confirmado',false,false,false,'']
];
foreach ($cases as [$expected,$outgoing,$connected,$finished,$disposition]) {
    $actual = TelephonySupervisionPbxIsolated::estadoFinalPbx(
        $outgoing,$connected,$finished,$disposition
    );
    if ($actual !== $expected) throw new RuntimeException(
        "Error de estado PBX: esperaba $expected, obtuvo $actual"
    );
}
foreach ([
    "TelefoniaLimpiezaGuard::condicion",
    "vc.pbx_call_id = BINARY e.pbx_call_id",
    "vc.extension = BINARY e.internal",
    "vr.extension = BINARY e.internal",
    "\$idTramo !== \$idMarcacion",
    "AS disposicion_final",
    "ORDER BY e.id DESC",
    "'estado_pbx' => \$estadoPbx",
    "'resultado_guardado' => \$resultadoGuardado",
    "'resultado_estado' => \$resultadoEstado"
] as $required) {
    if (strpos($service,$required) === false) {
        throw new RuntimeException("Se perdió protección SQL / estado: $required");
    }
}
if (strpos($service, 'UPDATE telefonia_ventas_llamadas') !== false ||
    strpos($service, 'DELETE FROM telefonia_zadarma_eventos') !== false) {
    throw new RuntimeException('El servicio de supervisión no debe reescribir llamadas.');
}
echo "PASS: 11 estados PBX, integración segura por usuario/extensión y resultados verificados.\n";
