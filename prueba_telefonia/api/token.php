<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensaje' => 'Sesión no activa.']);
    exit;
}

$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
$rolId = (int)($_SESSION['rol_id'] ?? 0);

if ($usuarioId <= 0 || $rolId !== 4) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'La telefonía integrada está disponible para el Analista responsable.'
    ]);
    exit;
}

$configPath = dirname(__DIR__, 2) . '/config/voip_config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'Falta config/voip_config.php.']);
    exit;
}

$config = require $configPath;
$tokenUrl = trim((string)($config['token_url'] ?? ''));
$accountSid = trim((string)($config['account_sid'] ?? ''));
$authToken = trim((string)($config['auth_token'] ?? ''));
$callerIdConfigurado = trim((string)($config['caller_id'] ?? ''));

if ($tokenUrl === '' || !filter_var($tokenUrl, FILTER_VALIDATE_URL)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'La URL de token de Twilio no está configurada.']);
    exit;
}

function normalizarTelefonoUsuario(string $valor): string
{
    $original = trim($valor);
    if ($original === '') {
        return '';
    }

    $digitos = preg_replace('/\D+/', '', $original) ?: '';

    if (strlen($digitos) === 13 && str_starts_with($digitos, '521')) {
        $digitos = '52' . substr($digitos, 3);
    }

    if (strlen($digitos) === 10) {
        return '+52' . $digitos;
    }

    if (strlen($digitos) === 12 && str_starts_with($digitos, '52')) {
        return '+' . $digitos;
    }

    if (str_starts_with($original, '+') && strlen($digitos) >= 8 && strlen($digitos) <= 15) {
        return '+' . $digitos;
    }

    return '';
}

function twilioGetJson(string $url, string $accountSid, string $authToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERPWD => $accountSid . ':' . $authToken,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException(
            $error !== '' ? $error : 'Twilio respondió HTTP ' . $status
        );
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Twilio devolvió una respuesta JSON no válida.');
    }

    return $data;
}

function telefonoAutorizadoTwilio(
    string $telefono,
    string $callerIdConfigurado,
    string $accountSid,
    string $authToken
): bool {
    if ($telefono === '') {
        return false;
    }

    if ($callerIdConfigurado !== '' && hash_equals($callerIdConfigurado, $telefono)) {
        return true;
    }

    if (!preg_match('/^AC[a-fA-F0-9]{32}$/', $accountSid) || $authToken === '') {
        return false;
    }

    $base = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid);
    $query = '?PhoneNumber=' . rawurlencode($telefono) . '&PageSize=1';

    $callerIds = twilioGetJson(
        $base . '/OutgoingCallerIds.json' . $query,
        $accountSid,
        $authToken
    );

    if (!empty($callerIds['outgoing_caller_ids'])) {
        return true;
    }

    $numerosTwilio = twilioGetJson(
        $base . '/IncomingPhoneNumbers.json' . $query,
        $accountSid,
        $authToken
    );

    return !empty($numerosTwilio['incoming_phone_numbers']);
}

try {
    require_once dirname(__DIR__, 2) . '/config/db_connection.php';
    $database = new Database();
    $connection = $database->connect();

    $stmtUsuario = $connection->prepare(
        'SELECT telefono FROM usuarios WHERE id = ? AND estado = 1 LIMIT 1'
    );
    $stmtUsuario->bind_param('i', $usuarioId);
    $stmtUsuario->execute();
    $usuario = $stmtUsuario->get_result()->fetch_assoc() ?: null;

    $telefonoUsuario = normalizarTelefonoUsuario((string)($usuario['telefono'] ?? ''));

    $callerIdVerificado = false;
    $callerIdEstado = $telefonoUsuario === '' ? 'SIN_TELEFONO' : 'NO_VERIFICADO';

    if ($telefonoUsuario !== '') {
        try {
            $callerIdVerificado = telefonoAutorizadoTwilio(
                $telefonoUsuario,
                normalizarTelefonoUsuario($callerIdConfigurado),
                $accountSid,
                $authToken
            );
            $callerIdEstado = $callerIdVerificado ? 'VERIFICADO' : 'NO_VERIFICADO';
        } catch (Throwable $errorVerificacion) {
            $callerIdEstado = 'NO_DISPONIBLE';
        }
    }

    $separador = str_contains($tokenUrl, '?') ? '&' : '?';
    $tokenUrlUsuario = $tokenUrl
        . $separador
        . http_build_query([
            'identity' => 'impe_user_' . $usuarioId
        ]);

    $ch = curl_init($tokenUrlUsuario);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'mensaje' => 'No se pudo obtener el token de Twilio.',
            'detalle' => $error !== '' ? $error : 'HTTP ' . $status,
        ]);
        exit;
    }

    $data = json_decode($body, true);
    if (!is_array($data) || empty($data['token'])) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'mensaje' => 'Twilio devolvió una respuesta de token no válida.'
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'token' => $data['token'],
        'identity' => $data['identity'] ?? null,
        'caller_id' => $telefonoUsuario,
        'caller_id_verified' => $callerIdVerificado,
        'caller_id_status' => $callerIdEstado,
        'caller_id_source' => 'USUARIO',
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'mensaje' => 'No fue posible preparar la telefonía del usuario.',
        'detalle' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
