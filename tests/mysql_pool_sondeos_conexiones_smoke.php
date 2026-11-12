<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$home = file_get_contents($root.'/app/controllers/HomeController.php');
$api = file_get_contents($root.'/prueba_telefonia/api/transferencia_operacion.php');
$service = file_get_contents($root.'/app/services/TelefoniaTransferenciaOperacionService.php');
if (strpos($home, 'new ConvocatoriaModel(Database::compartida())') === false ||
    strpos($home, 'new ConvocatoriaModel();') !== false) {
    throw new RuntimeException('Marketing todavía abre una conexión independiente.');
}
if (!str_contains($api, '$metodo===\'GET\' ? Database::compartida() : null')) {
    throw new RuntimeException('La consulta de transferencias no reutiliza el handle.');
}
if (!str_contains($service, '__construct(?mysqli $db=null)')) {
    throw new RuntimeException('Firma de inyección no compatible.');
}
if (!str_contains($api, "if(\$metodo==='POST'") ||
    !str_contains($api, '$svc->accion(')) {
    throw new RuntimeException('POST/operaciones de transferencia alterados.');
}
echo "PASS: panel Marketing y sondeo GET reutilizan; POST conserva contrato.\n";
