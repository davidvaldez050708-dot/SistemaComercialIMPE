<?php
/**
 * Ejercicio de HTTP real -> FormularioPublicoController -> registro ->
 * cola -> worker -> Zoho simulado, 100% en MySQL temporal de GitHub Actions.
 *
 * PROHIBIDO ejecutar con BD o configuración de Hostinger.
 * No utiliza tokens, personas reales, red Zoho ni asignaciones comerciales.
 * Se ejecuta solo contra 127.0.0.1:33308 / impe_zoho_http_ci.
 */
$modo = $argv[1] ?? '';
$privado = (string)(getenv('IMPE_PRIVATE_CONFIG_DIR') ?: '');
$home = (string)(getenv('HOME') ?: '');
if (PHP_SAPI !== 'cli' || !in_array($modo, ['--setup', '--verify'], true) ||
    count($argv) !== 2 ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('IMPE_DB_HOST') !== '127.0.0.1:33308' ||
    getenv('IMPE_DB_NAME') !== 'impe_zoho_http_ci' ||
    getenv('IMPE_DB_USER') !== 'root' ||
    getenv('IMPE_DB_PASSWORD') !== '' ||
    !preg_match('#^/tmp/zoho-http-ci-[a-zA-Z0-9]{6,}/?$#D', $privado) ||
    !is_dir($privado) ||
    realpath($privado) !== rtrim($privado, '/') ||
    $home !== rtrim($privado, '/') . '/home' ||
    !is_dir($home) ||
    getenv('IMPE_BASE_URL') !== 'http://127.0.0.1:8098/') {
    fwrite(STDERR, "BLOQUEADO: SOLO_MYSQL_TEMPORAL_CI_SIN_PRODUCCION\n");
    exit(2);
}

function exigirZoho(bool $ok, string $detalle): void
{
    if (!$ok) {
        throw new RuntimeException('FALLO_' . $detalle);
    }
    echo "OK: $detalle\n";
}

require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/../app/services/ZohoDeveloperRegistroWorker.php';

if ($modo === '--setup') {
    // SOLO el contenedor MySQL8 del runner, nunca el host de producción.
    $admin = new mysqli('127.0.0.1', 'root', '', null, 33308);
    $admin->query('CREATE DATABASE impe_zoho_http_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->close();
    $db = (new Database())->connect(false);

    $db->query('CREATE TABLE estados (
        id INT PRIMARY KEY, nombre VARCHAR(100), estado INT
    ) ENGINE=InnoDB');
    $db->query("INSERT INTO estados VALUES (1,'Morelos',1)");
    $db->query('CREATE TABLE municipios (
        id INT PRIMARY KEY, estado_id INT, nombre VARCHAR(100), estado INT
    ) ENGINE=InnoDB');
    $db->query("INSERT INTO municipios VALUES (1,1,'Cuernavaca',1)");
    $esquema = "(
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(80) NOT NULL,
        apellido VARCHAR(120) NOT NULL,
        fecha_nacimiento DATE NOT NULL,
        movil VARCHAR(20) NOT NULL,
        movil_secundario VARCHAR(20) NULL,
        tel_recomendado VARCHAR(20) NULL,
        correo VARCHAR(190) NOT NULL,
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
    $db->query('CREATE TABLE formulario_registros ' . $esquema);
    $db->query('CREATE TABLE formulario_inscripciones ' . $esquema);

    $ddl = (string)file_get_contents(__DIR__ . '/../database/zoho_dev_registros_pendientes.sql');
    $ddl = trim((string)preg_replace('/^\s*--[^\n]*\n/m', '', $ddl));
    exigirZoho(substr_count($ddl, ';') === 1 &&
        strpos($ddl, 'CREATE TABLE IF NOT EXISTS zoho_dev_registros_pendientes') === 0,
        'DDL auxiliar aislado autorizado');
    $db->query($ddl);

    $cfg = [
        'enabled' => true,
        'environment' => 'developer',
        'expected_org_id' => '4608581000000025312',
        'unique_field' => 'IMPE_Registro_ID',
        'test_emails' => ['impe.http.staging@example.com'],
    ];
    file_put_contents(
        $privado . '/zoho_sync.local.php',
        "<?php\nreturn " . var_export($cfg, true) . ";\n"
    );
    chmod($privado . '/zoho_sync.local.php', 0600);

    $oauthDir = $home . '/.config/sistema_comercial_impe';
    if (!is_dir($home . '/.config')) {
        mkdir($home . '/.config', 0700);
    }
    mkdir($oauthDir, 0700);
    file_put_contents($oauthDir . '/zoho_oauth.json', json_encode([
        'client_id' => 'CLIENT_FAKE',
        'client_secret' => 'SECRET_FAKE',
        'refresh_token' => 'REFRESH_FAKE',
    ], JSON_THROW_ON_ERROR));
    chmod($oauthDir . '/zoho_oauth.json', 0600);
    exigirZoho(true, 'solo MySQL CI con catalogos ficticios; sin comerciales');
    exit(0);
}

$db = (new Database())->connect(false);
$cfg = ZohoDeveloperRegistroSync::configuracion();
exigirZoho($cfg !== [] && count($cfg['test_emails']) === 1,
    'configuracion ficticia explicita valida');

function enviarFormulario(string $action, string $email): array
{
    $url = 'http://127.0.0.1:8098/index.php?controller=formularioPublico&action=' . $action;
    $data = [
        'nombre' => 'Prueba',
        'apellido' => 'Staging',
        'fecha_nacimiento' => '2000-01-01',
        'movil' => '5512345678',
        'movil_confirmacion' => '5512345678',
        'correo' => $email,
        'correo_confirmacion' => $email,
        'perfil_interes' => 'Inglés',
        'lugar_laboras' => '',
        'cargo_puesto' => '',
        'estado_id' => '1',
        'municipio_id' => '1',
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 6,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch);
    curl_close($ch);
    if ($body === false || $error !== 0) {
        throw new RuntimeException('HTTP_FORMULARIO_NO_DISPONIBLE');
    }
    $parsed = json_decode((string)$body, true);
    exigirZoho($status === 200 && is_array($parsed) && ($parsed['ok'] ?? false) === true &&
        (int)($parsed['registro_id'] ?? 0) > 0, 'HTTP_' . $action . '_200');
    return $parsed;
}

$permitido = enviarFormulario('guardarRegistro', 'impe.http.staging@example.com');
$id = (int)$permitido['registro_id'];
$stmt = $db->prepare('SELECT registro_id, estado FROM zoho_dev_registros_pendientes WHERE registro_id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
exigirZoho((int)($row['registro_id'] ?? 0) === $id && ($row['estado'] ?? '') === 'pendiente',
    'HTTP registro real agrega cola exactamente una vez');

enviarFormulario('guardarRegistro', 'noexportar@example.org');
enviarFormulario('guardarInscripcion', 'impe.http.staging@example.com');
exigirZoho(
    (int)$db->query('SELECT COUNT(*) AS total FROM zoho_dev_registros_pendientes')
        ->fetch_assoc()['total'] === 1,
    'correo no autorizado e inscripcion NO se exportan'
);

$remote = [];
$escriturasZoho = 0;
ZohoDeveloperRegistroSync::establecerTransporteDePruebas(
    static function (string $url, ?array $post, ?string $token)
        use (&$remote, &$escriturasZoho): array {
        if ($url === 'https://accounts.zoho.com/oauth/v2/token') {
            return [200, ['access_token' => 'TOKEN_FICTICIO']];
        }
        if ($url === 'https://developer.zohoapis.com/crm/v8/org') {
            exigirZoho($token === 'TOKEN_FICTICIO', 'token solo para organizacion simulada');
            return [200, ['org' => [['id' => '4608581000000025312']]]];
        }
        if ($url === 'https://developer.zohoapis.com/crm/v8/Leads/upsert') {
            $lead = $post['data'][0] ?? [];
            exigirZoho(
                $token === 'TOKEN_FICTICIO' &&
                ($lead['Email'] ?? '') === 'impe.http.staging@example.com' &&
                ($lead['Last_Name'] ?? '') === 'Staging' &&
                ($post['duplicate_check_fields'] ?? []) === ['IMPE_Registro_ID'] &&
                ($post['trigger'] ?? null) === [],
                'worker envia solo lead ficticio autorizado'
            );
            $key = $lead['IMPE_Registro_ID'] ?? '';
            if (!isset($remote[$key])) {
                $remote[$key] = '4608581000000987654';
            }
            ++$escriturasZoho;
            return [200, ['data' => [[
                'status' => 'success',
                'details' => ['id' => $remote[$key]],
            ]]]];
        }
        throw new RuntimeException('URL_AJENA_EN_TRANSPORTE_SIMULADO');
    }
);
$res = ZohoDeveloperRegistroWorker::procesar($db, $cfg);
$stmt = $db->prepare('SELECT estado, zoho_id, intentos FROM zoho_dev_registros_pendientes WHERE registro_id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
$estado = $stmt->get_result()->fetch_assoc();
$stmt->close();
exigirZoho(
    $res === [1, 0] && ($estado['estado'] ?? '') === 'enviado' &&
    ($estado['zoho_id'] ?? '') === '4608581000000987654' &&
    (int)($estado['intentos'] ?? 0) === 1 &&
    count($remote) === 1 && $escriturasZoho === 1,
    'pipeline HTTP -> registro -> cola -> worker -> API simulada, sin duplicados'
);
exigirZoho(
    (int)$db->query('SELECT COUNT(*) AS n FROM formulario_registros')->fetch_assoc()['n'] === 2 &&
    (int)$db->query('SELECT COUNT(*) AS n FROM formulario_inscripciones')->fetch_assoc()['n'] === 1,
    'registros e inscripcion persistidos; sin tablas de asesores ni prospectos'
);
ZohoDeveloperRegistroSync::establecerTransporteDePruebas(null);
echo "STAGING_HTTP_ZOHO_OK: solo MySQL y OAuth simulados; sin credenciales ni prod.\n";
