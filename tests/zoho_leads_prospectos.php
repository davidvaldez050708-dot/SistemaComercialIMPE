<?php
require_once __DIR__.'/../app/services/ZohoLeadsProspectosBridge.php';

function verificar(bool $condicion, string $mensaje): void
{
    if (!$condicion) throw new RuntimeException($mensaje);
}

$lead = ZohoLeadsProspectosBridge::convertir([
    'id'=>'9876543210', 'First_Name'=>'María', 'Last_Name'=>'López',
    'Mobile'=>'5512345678', 'Phone'=>'5555555555', 'Email'=>'maria@example.com',
    'Created_Time'=>'2026-11-13T10:30:00-06:00',
]);
verificar($lead['nombre_completo'] === 'María López', 'Nombre');
verificar($lead['telefono_original'] === '5512345678', 'Prioridad del móvil');
verificar($lead['fecha'] === '2026-11-13 16:30:00', 'Zona horaria UTC');
verificar(ZohoLeadsProspectosBridge::convertir(['id'=>'123','Last_Name'=>'']) === null, 'Sin nombre');
try {
    ZohoLeadsProspectosBridge::convertir(['id'=>'no-valido','Last_Name'=>'Prueba']);
    throw new RuntimeException('Aceptó ID inválido');
} catch (RuntimeException $e) {
    verificar($e->getMessage() === 'ID de Zoho inválido.', 'Rechazo de ID');
}
echo "Mapeo Zoho correcto.\n";
