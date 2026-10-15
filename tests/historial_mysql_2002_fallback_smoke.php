<?php
/** Fallback: no debe afectar a otros endpoints ni repetir indiscriminadamente. */
declare(strict_types=1);
$path = dirname(__DIR__) . '/prueba_telefonia/api/llamadas_seguimiento.php';
$source = file_get_contents($path);
$checks = [
    'persistente primero' => str_contains($source, '(new Database())->connect(true)'),
    'reintento una sola vez' => substr_count($source, '(new Database())->connect(false)') === 1,
    'código exacto 2002' => str_contains($source, "(int)\$causaConexion->getCode() === 2002"),
    'condición operativa' => str_contains($source, "'operation not permitted'"),
    'fallo no elegible sin modificar' => str_contains($source, 'throw $errorConexionPersistente;'),
    'segunda etapa diagnóstica' => str_contains($source, "conectar_mysql_sin_persistencia"),
    'manejo previo de errores preservado' => str_contains($source, "llamadas_seguimiento:%s")
];
foreach ($checks as $label=>$ok) {
    if (!$ok) { fwrite(STDERR, "FALLO: $label\n"); exit(1); }
}
echo "MySQL historial: OK (" . count($checks) . " comprobaciones).\n";
