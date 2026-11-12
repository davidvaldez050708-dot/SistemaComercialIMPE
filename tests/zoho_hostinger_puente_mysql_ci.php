<?php
/**
 * Ensayo MySQL efímero del contrato de Hostinger, NO modelo productivo real.
 * Compone un modelo sintético con el parche REAL y servicios PHP REALES.
 * Sustituye incorporación central por un doble inerte, sin asignaciones.
 * Ningún dato/credencial de Hostinger ni Zoho sale a la red.
 */
$base = (string)(getenv('ZOHO_PUENTE_ROOT') ?: '');
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || count($argv) !== 2 ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('IMPE_DB_HOST') !== '127.0.0.1:33308' ||
    getenv('IMPE_DB_NAME') !== 'impe_zoho_puente_ci' ||
    getenv('IMPE_DB_USER') !== 'root' ||
    getenv('IMPE_DB_PASSWORD') !== '' ||
    getenv('IMPE_PRIVATE_CONFIG_DIR') !== $base . '/private' ||
    getenv('HOME') !== $base . '/home' ||
    !preg_match('#^/tmp/zoho-puente-ci-[a-zA-Z0-9]{6,}$#D', $base) ||
    !is_dir($base) || realpath($base) !== $base ||
    !is_file($base . '/app/models/FormularioRegistroModel.php')) {
    fwrite(STDERR, "BLOQUEADO: PRUEBA_SOLO_CI_MYSQL_TEMPORAL\n");
    exit(2);
}
function zCheck(bool $condicion, string $etiqueta): void
{
    if (!$condicion) {
        throw new RuntimeException('FALLO_' . $etiqueta);
    }
    echo "OK: $etiqueta\n";
}
require_once $base . '/app/models/FormularioRegistroModel.php';
require_once $base . '/app/services/ZohoDeveloperRegistroWorker.php';

$admin = new mysqli('127.0.0.1', 'root', '', null, 33308);
$admin->query(
    'CREATE DATABASE impe_zoho_puente_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);
$admin->close();
$db = (new Database())->connect(false);
foreach (['formulario_registros', 'formulario_inscripciones'] as $nombre) {
    $db->query("CREATE TABLE $nombre (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(80) NOT NULL,
        apellido VARCHAR(120) NOT NULL,
        correo VARCHAR(190) NOT NULL,
        movil VARCHAR(20) NOT NULL,
        horario_contacto VARCHAR(255) NOT NULL,
        origen VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
$ddl = (string)file_get_contents(
    __DIR__ . '/../database/zoho_dev_registros_pendientes.sql'
);
$ddl = trim((string)preg_replace('/^\s*--[^\n]*\n/m', '', $ddl));
zCheck(
    strpos($ddl, 'CREATE TABLE IF NOT EXISTS zoho_dev_registros_pendientes') === 0 &&
    substr_count($ddl, ';') === 1,
    'DDL cola separado en MySQL CI'
);
$db->query($ddl);
zCheck(
    (int)$db->query(
        "SELECT COUNT(*) AS n FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE()"
    )->fetch_assoc()['n'] === 3,
    'ninguna tabla de prospectos, usuarios ni asignaciones en la base de prueba'
);

$modelo = new FormularioRegistroModel();
$datos = static function (string $email, string $origen = 'PUBLICO'): array {
    return [
        'nombre' => 'Prueba',
        'apellido' => 'Puente',
        'correo' => $email,
        'movil' => '5512345678',
        'horario_contacto' => '16:00-18:00',
        'origen' => $origen,
    ];
};
$contarCola = static function () use ($db): int {
    return (int)$db->query(
        'SELECT COUNT(*) AS n FROM zoho_dev_registros_pendientes'
    )->fetch_assoc()['n'];
};

// 1. Con configuración desactivada (misma condición operativa actual).
$idOff = $modelo->guardar($datos('impe.puente@example.com'));
zCheck($idOff > 0 && $contarCola() === 0 &&
    count(ProspectoCentralIncorporacionService::$llamadas) === 1,
    'sin config: registro persistido, incorporación central simulada, cola vacía');

// 2. Opt-in estrictamente ficticio en un HOME aislado.
$cfg = [
    'enabled' => true,
    'environment' => 'developer',
    'expected_org_id' => '4608581000000025312',
    'unique_field' => 'IMPE_Registro_ID',
    'test_emails' => ['impe.puente@example.com'],
];
file_put_contents(
    $base . '/private/zoho_sync.local.php',
    "<?php\nreturn " . var_export($cfg, true) . ";\n"
);
chmod($base . '/private/zoho_sync.local.php', 0600);
$oauthDir = $base . '/home/.config/sistema_comercial_impe';
mkdir($base . '/home/.config', 0700);
mkdir($oauthDir, 0700);
file_put_contents($oauthDir . '/zoho_oauth.json', json_encode([
    'client_id' => 'CLIENTE_SINTETICO',
    'client_secret' => 'SECRETO_SINTETICO',
    'refresh_token' => 'REFRESH_SINTETICO',
]));
chmod($oauthDir . '/zoho_oauth.json', 0600);
zCheck(
    ZohoDeveloperRegistroSync::configuracion() !== [],
    'configuracion de prueba exclusiva habilitada fuera de sitio web'
);

$idPermitido = $modelo->guardar($datos('impe.puente@example.com'));
zCheck($contarCola() === 1 &&
    count(ProspectoCentralIncorporacionService::$llamadas) === 2 &&
    (int)$db->query(
        'SELECT COUNT(*) AS n FROM zoho_dev_registros_pendientes
         WHERE registro_id=' . (int)$idPermitido . " AND estado='pendiente'"
    )->fetch_assoc()['n'] === 1,
    'incorporación central simulada ANTES de encolar ficticio');

$idNoPermitido = $modelo->guardar($datos('persona-no-exportar@impe.mx'));
zCheck($idNoPermitido > 0 && $contarCola() === 1,
    'correo fuera de lista permanece solo en base de prueba');

$antesCentral = count(ProspectoCentralIncorporacionService::$llamadas);
$idInscripcion = $modelo->guardar(
    $datos('impe.puente@example.com', 'PUBLICO_INSCRIPCION')
);
zCheck($idInscripcion > 0 && $contarCola() === 1 &&
    count(ProspectoCentralIncorporacionService::$llamadas) === $antesCentral &&
    (int)$db->query('SELECT COUNT(*) AS n FROM formulario_inscripciones')
        ->fetch_assoc()['n'] === 1,
    'inscripción excluida de incorporación central y Zoho');

// Falla controlada en la lógica comercial ficticia: el gancho no altera retorno.
ProspectoCentralIncorporacionService::$fallar = true;
$idCentralFallo = $modelo->guardar($datos('impe.puente@example.com'));
ProspectoCentralIncorporacionService::$fallar = false;
zCheck($idCentralFallo > 0 && $contarCola() === 2,
    'excepción comercial simulada no impide encolado autorizado');

// Falta deliberada de tabla de cola solo en MySQL efímero.
// El registro original debe guardarse incluso si Zoho falla.
$db->query(
    'RENAME TABLE zoho_dev_registros_pendientes
     TO zoho_dev_registros_pendientes_oculta'
);
try {
    $idSinCola = $modelo->guardar($datos('impe.puente@example.com'));
} finally {
    $db->query(
        'RENAME TABLE zoho_dev_registros_pendientes_oculta
         TO zoho_dev_registros_pendientes'
    );
}
zCheck($idSinCola > 0 && $contarCola() === 2,
    'si falla cola, registro comercial sintético persiste sin error HTTP');

$mapa = [];
$escrituras = 0;
ZohoDeveloperRegistroSync::establecerTransporteDePruebas(
    static function (string $url, ?array $post, ?string $token)
        use (&$mapa, &$escrituras): array {
        if ($url === 'https://accounts.zoho.com/oauth/v2/token') {
            return [200, ['access_token' => 'TOKEN_FAKE']];
        }
        if ($url === 'https://developer.zohoapis.com/crm/v8/org') {
            zCheck($token === 'TOKEN_FAKE', 'organizacion ficticia autorizada');
            return [200, ['org' => [['id' => '4608581000000025312']]]];
        }
        if ($url === 'https://developer.zohoapis.com/crm/v8/Leads/upsert') {
            $lead = $post['data'][0] ?? [];
            zCheck(
                $token === 'TOKEN_FAKE' &&
                ($lead['Email'] ?? '') === 'impe.puente@example.com' &&
                ($post['duplicate_check_fields'] ?? []) === ['IMPE_Registro_ID'] &&
                ($post['trigger'] ?? null) === [],
                'upsert usa correo ficticio, campo único y sin automatismos Zoho'
            );
            $clave = $lead['IMPE_Registro_ID'] ?? '';
            if (!isset($mapa[$clave])) {
                $mapa[$clave] = (string)(4608581000000100001 + count($mapa));
            }
            ++$escrituras;
            return [200, ['data' => [[
                'status' => 'success',
                'details' => ['id' => $mapa[$clave]],
            ]]]];
        }
        throw new RuntimeException('URL_NO_SIMULADA');
    }
);
$procesado = ZohoDeveloperRegistroWorker::procesar($db, $cfg);
zCheck($procesado === [2, 0] && $contarCola() === 2 &&
    $escrituras === 2 && count($mapa) === 2 &&
    (int)$db->query(
        "SELECT COUNT(*) AS n FROM zoho_dev_registros_pendientes
         WHERE estado='enviado' AND zoho_id IS NOT NULL"
    )->fetch_assoc()['n'] === 2,
    'worker real procesa exactamente dos registros ficticios');

$db->query(
    "UPDATE zoho_dev_registros_pendientes
     SET estado='reintentar', proximo_intento=NULL
     WHERE registro_id=" . (int)$idPermitido
);
$retry = ZohoDeveloperRegistroWorker::procesar($db, $cfg);
zCheck($retry === [1, 0] && $escrituras === 3 && count($mapa) === 2,
    'reintento idempotente no crea un tercer lead');
ZohoDeveloperRegistroSync::establecerTransporteDePruebas(null);

zCheck(
    (int)$db->query('SELECT COUNT(*) AS n FROM formulario_registros')
        ->fetch_assoc()['n'] === 5 &&
    (int)$db->query('SELECT COUNT(*) AS n FROM formulario_inscripciones')
        ->fetch_assoc()['n'] === 1 &&
    (int)$db->query(
        "SELECT COUNT(*) AS n FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE()"
    )->fetch_assoc()['n'] === 3,
    'sin cambios ajenos: 5 registros, 1 inscripción, 0 tablas comerciales'
);
echo "PUENTE_HOSTINGER_SINTETICO_OK: SQL temporal y API simulada; sin producción.\n";
