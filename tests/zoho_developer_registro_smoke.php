<?php
/** Prueba sin BD ni red: el piloto siempre arranca desactivado. */
require_once __DIR__ . '/../app/services/ZohoDeveloperRegistroSync.php';

function verificar($condicion, $mensaje) {
    if (!$condicion) { fwrite(STDERR, "FALLO: $mensaje\n"); exit(1); }
}

$sinConfig = [];
verificar(
    !ZohoDeveloperRegistroSync::autorizado($sinConfig, 'demo.zoho.impe@example.com'),
    'la integración no debe autorizar correos sin configuración'
);
$cfg = [
    'external_field' => 'IMPE_Registro_ID',
    'test_emails' => ['demo.zoho.impe@example.com'],
];
verificar(
    ZohoDeveloperRegistroSync::autorizado($cfg, 'demo.zoho.impe@example.com'),
    'el correo de prueba autorizado debe pasar'
);
verificar(
    !ZohoDeveloperRegistroSync::autorizado($cfg, 'real@gmail.com'),
    'correo real prohibido'
);
verificar(
    ZohoDeveloperRegistroSync::claveExterna(17) === 'IMPE_REGISTRO_17',
    'clave externa estable'
);
$lead = ZohoDeveloperRegistroSync::datosLead([
    'nombre' => 'Prueba',
    'apellido' => 'Tesis',
    'correo' => 'demo.zoho.impe@example.com',
    'movil' => '5512345678',
], 17, $cfg);
verificar(
    $lead['Last_Name'] === 'Tesis' &&
    $lead['Company'] === 'SistemaComercialIMPE - Pruebas' &&
    $lead['IMPE_Registro_ID'] === 'IMPE_REGISTRO_17' &&
    $lead['Mobile'] === '5512345678',
    'mapeo seguro de campos'
);
verificar(
    !isset($lead['fecha_nacimiento']) && !isset($lead['perfil_interes']),
    'minimización de datos'
);
try {
    ZohoDeveloperRegistroSync::datosLead([
        'apellido' => 'Real',
        'correo' => 'persona@ejemplo.mx'
    ], 22, $cfg);
    verificar(false, 'no debe exportar correos no autorizados');
} catch (RuntimeException $e) {
    verificar($e->getMessage() === 'EMAIL_FUERA_DE_PRUEBA', 'motivo esperado');
}

echo "CHECK OK: sin BD, red, SQL ni escrituras; filtros y mapeo de Zoho Developer.\n";
