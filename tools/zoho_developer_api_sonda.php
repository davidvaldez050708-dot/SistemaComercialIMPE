<?php
/**
 * Sonda aislada de API Zoho CRM Developer para el piloto IMPE.
 *
 * NO usa base de datos, NO llama al guardado del formulario, NO asigna
 * prospectos y NO requiere crear zoho_sync.local.php ni cron.
 *
 * --check: consulta OAuth y comprueba el ID de organización Developer.
 * --send --confirm-one-fake-lead: upsert de UN registro sintético en
 * Zoho Developer y lectura de verificación. Es una escritura REMOTA
 * intencional y deja un registro de prueba en Developer.
 */
if (PHP_SAPI !== 'cli' ||
    !(($argc === 2 && $argv[1] === '--check') ||
      ($argc === 3 && $argv[1] === '--send' &&
       $argv[2] === '--confirm-one-fake-lead'))) {
    fwrite(STDERR,
        "Uso: php tools/zoho_developer_api_sonda.php --check | --send --confirm-one-fake-lead\n"
    );
    exit(2);
}

require_once __DIR__ . '/../app/services/ZohoDeveloperRegistroSync.php';

$modo = $argv[1];
$cfg = [
    'environment' => 'developer',
    'expected_org_id' => '4608581000000025312',
    'unique_field' => 'IMPE_Registro_ID',
    'test_emails' => ['impe.sonda.zoho@example.com'],
];

// Fuera del rango del formulario_registros.id INT UNSIGNED: no se
// corresponde con un registro comercial verdadero ni con un asesor.
// La clave determinista permite repetir el upsert sin duplicar Leads.
$registroId = 900000000000001;
$registro = [
    'nombre' => 'Prueba',
    'apellido' => 'Aislada IMPE',
    'correo' => 'impe.sonda.zoho@example.com',
    'movil' => '',
];

try {
    // El token solo se usa en memoria. Verifica la organización exacta
    // antes de que cualquier llamada pueda crear un Lead.
    $token = ZohoDeveloperRegistroSync::tokenVerificado($cfg);
    echo "ORGANIZACION_DEVELOPER: VERIFICADA\n";

    if ($modo === '--check') {
        echo "CHECK_OK: solo OAuth y lectura de organización; sin BD ni Leads nuevos.\n";
        exit(0);
    }

    $zohoId = ZohoDeveloperRegistroSync::crearOActualizarLead(
        $registro, $registroId, $cfg, $token
    );

    // Confirma lectura del Lead recién creado/actualizado, sin presentar
    // credenciales, respuestas completas ni datos de otros registros.
    if (!function_exists('curl_init')) {
        throw new RuntimeException('CURL_NO_DISPONIBLE');
    }
    $url = 'https://developer.zohoapis.com/crm/v8/Leads/' . rawurlencode($zohoId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 22,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Zoho-oauthtoken ' . $token,
        ],
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_errno($ch);
    curl_close($ch);
    if ($err !== 0 || $raw === false || $http !== 200) {
        throw new RuntimeException('LECTURA_VERIFICACION_NO_DISPONIBLE');
    }
    $respuesta = json_decode((string)$raw, true);
    $lead = $respuesta['data'][0] ?? null;
    if (!is_array($lead) ||
        (string)($lead['id'] ?? '') !== $zohoId ||
        strtolower((string)($lead['Email'] ?? '')) !== $registro['correo'] ||
        (string)($lead[$cfg['unique_field']] ?? '') !==
            ZohoDeveloperRegistroSync::claveExterna($registroId)) {
        throw new RuntimeException('LEAD_NO_COINCIDE_CON_PRUEBA');
    }

    echo "ENVIO_DE_PRUEBA: OK\n";
    echo "LECTURA_DE_VERIFICACION: OK\n";
    echo "ZOHO_LEAD_ID_PRUEBA: " . $zohoId . "\n";
    echo "BASE_OPERATIVA_IMPE: SIN_MODIFICACIONES\n";
    echo "NOTA: el Lead sintético permanece en Zoho Developer.\n";
} catch (Throwable $e) {
    $codigo = preg_replace('/[^A-Z0-9_]/', '',
        strtoupper((string)$e->getMessage()));
    fwrite(STDERR, "BLOQUEADO: " . substr($codigo, 0, 90) .
        "; sin escrituras en la BD IMPE.\n");
    exit(1);
}
