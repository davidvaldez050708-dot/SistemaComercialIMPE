<?php
/**
 * Integración 100% sintética: MySQL efímero y transporte Zoho simulado.
 * Nunca conecta con la organización real de Zoho ni usa una BD de Hostinger.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('IMPE_DB_HOST') !== '127.0.0.1:33308' ||
    getenv('IMPE_DB_NAME') !== 'impe_zoho_piloto_ci') {
    fwrite(STDERR, "ERROR: prueba permitida solo contra MySQL efímero de CI.\n");
    exit(2);
}

function checkZoho(bool $ok, string $mensaje): void
{
    if (!$ok) {
        throw new RuntimeException('CHECK_FALLIDO_' . $mensaje);
    }
    echo "OK: " . $mensaje . "\n";
}
function estadoCola(mysqli $db, int $registroId): ?array
{
    $stmt = $db->prepare(
        'SELECT estado, zoho_id, intentos, proximo_intento, ultimo_error
         FROM zoho_dev_registros_pendientes WHERE registro_id=?'
    );
    $stmt->bind_param('i', $registroId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
function datosPrueba(string $correo, string $origen = 'PUBLICO'): array
{
    return [
        'nombre' => 'Prueba',
        'apellido' => 'Tesis',
        'fecha_nacimiento' => '2000-01-01',
        'movil' => '5512345678',
        'movil_secundario' => '',
        'tel_recomendado' => '',
        'correo' => $correo,
        'perfil_interes' => 'Inglés',
        'lugar_laboras' => '',
        'cargo_puesto' => '',
        'estado_id' => 1,
        'municipio_id' => 1,
        'creado_por' => null,
        'origen' => $origen,
    ];
}

require_once __DIR__ . '/../app/models/FormularioRegistroModel.php';
require_once __DIR__ . '/../app/services/ZohoDeveloperRegistroWorker.php';

$cfg = [
    'enabled' => true,
    'environment' => 'developer',
    'expected_org_id' => '4608581000000025312',
    'unique_field' => 'IMPE_Registro_ID',
    'test_emails' => ['demo.zoho.impe@example.com'],
];

$private = sys_get_temp_dir() . '/impe_zoho_ci_' . bin2hex(random_bytes(6));
if (!mkdir($private, 0700) || !mkdir($private . '/home', 0700) ||
    !mkdir($private . '/home/.config', 0700) ||
    !mkdir($private . '/home/.config/sistema_comercial_impe', 0700)) {
    throw new RuntimeException('NO_PUDO_CREAR_DIR_TEMPORAL');
}
register_shutdown_function(static function () use ($private): void {
    foreach ([
        '/zoho_sync.local.php',
        '/home/.config/sistema_comercial_impe/zoho_oauth.json',
    ] as $file) {
        @unlink($private . $file);
    }
    @rmdir($private . '/home/.config/sistema_comercial_impe');
    @rmdir($private . '/home/.config');
    @rmdir($private . '/home');
    @rmdir($private);
});
file_put_contents(
    $private . '/zoho_sync.local.php',
    "<?php\nreturn " . var_export($cfg, true) . ";\n"
);
file_put_contents(
    $private . '/home/.config/sistema_comercial_impe/zoho_oauth.json',
    json_encode([
        'client_id' => 'CLIENTE_FICTICIO',
        'client_secret' => 'SECRETO_FICTICIO',
        'refresh_token' => 'REFRESH_FICTICIO',
    ], JSON_THROW_ON_ERROR)
);
putenv('HOME=' . $private . '/home');
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $private);

$root = new mysqli('127.0.0.1', 'root', '', null, 33308);
$root->query(
    'CREATE DATABASE IF NOT EXISTS impe_zoho_piloto_ci
     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
$root->close();

$db = (new Database())->connect(false);
$tabla = "(
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    apellido VARCHAR(120) NOT NULL,
    fecha_nacimiento DATE NOT NULL,
    movil VARCHAR(20) NOT NULL,
    movil_secundario VARCHAR(20) NULL,
    tel_recomendado VARCHAR(20) NULL,
    correo VARCHAR(180) NOT NULL,
    perfil_interes VARCHAR(180) NOT NULL,
    lugar_laboras VARCHAR(180) NOT NULL,
    cargo_puesto VARCHAR(160) NULL,
    estado_id INT NOT NULL,
    municipio_id INT NOT NULL,
    creado_por INT NULL,
    origen VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

$db->query('CREATE TABLE formulario_registros ' . $tabla);
$db->query('CREATE TABLE formulario_inscripciones ' . $tabla);
$sql = trim((string)file_get_contents(
    __DIR__ . '/../database/zoho_dev_registros_pendientes.sql'
));
$db->query($sql);
checkZoho(true, 'tablas aisladas creadas en MySQL temporal');

$real = ZohoDeveloperRegistroSync::configuracion();
checkZoho(
    $real !== [] && $real['unique_field'] === 'IMPE_Registro_ID',
    'configuración privada sintética leída'
);

$modelo = new FormularioRegistroModel();
$id = $modelo->guardar(datosPrueba('demo.zoho.impe@example.com'));
checkZoho($id > 0, 'registro guardado por modelo real');
checkZoho(
    (estadoCola($db, $id)['estado'] ?? null) === 'pendiente',
    'registro ficticio encolado automáticamente'
);

$idReal = $modelo->guardar(datosPrueba('no-exportar@impe.mx'));
checkZoho(
    estadoCola($db, $idReal) === null,
    'registro no autorizado permanece solo en CRM local'
);

$totalAntesInscripcion = (int)$db->query(
    'SELECT COUNT(*) AS n FROM zoho_dev_registros_pendientes'
)->fetch_assoc()['n'];
$idInscripcion = $modelo->guardar(
    datosPrueba('demo.zoho.impe@example.com', 'PUBLICO_INSCRIPCION')
);
$totalDespuesInscripcion = (int)$db->query(
    'SELECT COUNT(*) AS n FROM zoho_dev_registros_pendientes'
)->fetch_assoc()['n'];
checkZoho(
    $idInscripcion > 0 && $totalAntesInscripcion === $totalDespuesInscripcion,
    'inscripción excluida de sincronización'
);

$falloTemporal = true;
$organizacionIncorrecta = false;
$llamadasUpsert = 0;
$leadsZohoSimulados = [];
$funcionMock = static function (string $url, ?array $post, ?string $token)
    use ($cfg, &$falloTemporal, &$organizacionIncorrecta, &$llamadasUpsert, &$leadsZohoSimulados): array {
    if ($url === 'https://accounts.zoho.com/oauth/v2/token') {
        if ($token !== null || ($post['grant_type'] ?? null) !== 'refresh_token') {
            throw new RuntimeException('TOKEN_REQUEST_INCORRECTO');
        }
        return [200, [
            'access_token' => 'ACCESS_FICTICIO',
            'api_domain' => 'https://www.zohoapis.com',
        ]];
    }
    if ($url === 'https://developer.zohoapis.com/crm/v8/org') {
        if ($token !== 'ACCESS_FICTICIO') {
            throw new RuntimeException('AUTH_ORG_INCORRECTA');
        }
        return [200, [
            'org' => [['id' => $organizacionIncorrecta
                ? '9999999999999999999' : $cfg['expected_org_id']]],
        ]];
    }
    if ($url === 'https://developer.zohoapis.com/crm/v8/Leads/upsert') {
        ++$llamadasUpsert;
        if ($token !== 'ACCESS_FICTICIO' ||
            ($post['duplicate_check_fields'] ?? []) !== ['IMPE_Registro_ID'] ||
            ($post['trigger'] ?? null) !== []) {
            throw new RuntimeException('UPSERT_REQUEST_INCORRECTO');
        }
        $lead = $post['data'][0] ?? [];
        if (($lead['Email'] ?? null) !== 'demo.zoho.impe@example.com' ||
            ($lead['Company'] ?? null) !== 'SistemaComercialIMPE - Pruebas' ||
            ($lead['Last_Name'] ?? null) !== 'Tesis' ||
            !preg_match('/^IMPE_REGISTRO_[0-9]+$/D', (string)($lead['IMPE_Registro_ID'] ?? ''))) {
            throw new RuntimeException('LEAD_INCORRECTO');
        }
        if ($falloTemporal) {
            return [503, ['code' => 'TEMP_FAIL']];
        }
        $key = $lead['IMPE_Registro_ID'];
        if (!array_key_exists($key, $leadsZohoSimulados)) {
            $leadsZohoSimulados[$key] = (string)(4608581000000123000 + count($leadsZohoSimulados));
        }
        return [200, [
            'data' => [[
                'status' => 'success',
                'code' => 'SUCCESS',
                'details' => ['id' => $leadsZohoSimulados[$key]],
            ]],
        ]];
    }
    throw new RuntimeException('URL_NO_PERMITIDA_EN_MOCK');
};

ZohoDeveloperRegistroSync::establecerTransporteDePruebas($funcionMock);
$resultado = ZohoDeveloperRegistroWorker::procesar($db, $real);
checkZoho(
    $resultado === [0, 1] &&
    (estadoCola($db, $id)['estado'] ?? null) === 'reintentar' &&
    (int)(estadoCola($db, $id)['intentos'] ?? 0) === 1,
    'fallo HTTP 503 no pierde datos y programa reintento'
);

$falloTemporal = false;
$db->query(
    "UPDATE zoho_dev_registros_pendientes
     SET proximo_intento=DATE_SUB(NOW(), INTERVAL 1 MINUTE)
     WHERE registro_id=$id"
);
$resultado = ZohoDeveloperRegistroWorker::procesar($db, $real);
$estado = estadoCola($db, $id);
checkZoho(
    $resultado === [1, 0] &&
    ($estado['estado'] ?? null) === 'enviado' &&
    preg_match('/^[0-9]+$/D', (string)($estado['zoho_id'] ?? '')) === 1 &&
    count($leadsZohoSimulados) === 1,
    'reintento exitoso conserva ID Zoho y evita pérdida'
);

$db->query(
    "UPDATE zoho_dev_registros_pendientes
     SET estado='reintentar', proximo_intento=NULL
     WHERE registro_id=$id"
);
$resultado = ZohoDeveloperRegistroWorker::procesar($db, $real);
checkZoho(
    $resultado === [1, 0] &&
    count($leadsZohoSimulados) === 1 &&
    (estadoCola($db, $id)['zoho_id'] ?? '') === $estado['zoho_id'],
    'upsert repetido conserva un solo lead (idempotencia)'
);

$idOrg = $modelo->guardar(datosPrueba('demo.zoho.impe@example.com'));
$organizacionIncorrecta = true;
$antes = $llamadasUpsert;
$resultado = ZohoDeveloperRegistroWorker::procesar($db, $real);
checkZoho(
    $resultado === [0, 1] &&
    (estadoCola($db, $idOrg)['estado'] ?? null) === 'revision' &&
    $llamadasUpsert === $antes &&
    count($leadsZohoSimulados) === 1,
    'organización OAuth incorrecta bloquea escritura'
);

checkZoho(
    (int)$db->query('SELECT COUNT(*) AS n FROM formulario_registros')
      ->fetch_assoc()['n'] === 3 &&
    (int)$db->query('SELECT COUNT(*) AS n FROM formulario_inscripciones')
      ->fetch_assoc()['n'] === 1,
    'datos del CRM permanecen guardados en todas las rutas'
);

ZohoDeveloperRegistroSync::establecerTransporteDePruebas(null);
echo "INTEGRACION ZOHO OK: MySQL efímero, API simulada, sin credenciales ni red Zoho.\n";
