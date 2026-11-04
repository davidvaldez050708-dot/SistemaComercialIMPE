<?php
/** Sólo lectura: configuración de API y una consulta pública mínima. Sin DB ni registros del CRM. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opciones = getopt('', ['site:', 'ca:']);
$site = realpath($opciones['site'] ?? '');
if (!$site || !is_file($site . '/config/api_keys.php')) { fwrite(STDERR, "Indica --site con la ruta del CRM.\n"); exit(1); }
require $site . '/config/api_keys.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "cURL no disponible.\n"); exit(1); }
$base = defined('DENUE_BASE_URL') ? rtrim(DENUE_BASE_URL, '/') : '';
if (parse_url($base, PHP_URL_HOST) !== 'www.inegi.org.mx' || parse_url($base, PHP_URL_SCHEME) !== 'https') {
    fwrite(STDERR, "La URL configurada no es el host HTTPS oficial esperado. No se enviaron credenciales.\n"); exit(1);
}
$token = defined('DENUE_TOKEN') ? trim((string)DENUE_TOKEN) : '';
if ($token === '') { fwrite(STDERR, "DENUE no tiene token configurado.\n"); exit(1); }
$url = $base . '/BuscarAreaActEstr/09/014/0/0/0/61/0/0/0/0/1/1/0/0/' . rawurlencode($token);
$consultar = static function (?string $ca) use ($url): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['Accept: application/json']];
    if ($ca !== null) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    $texto = is_array($json) ? json_encode($json) : '';
    $r = ['http' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'curl_errno' => curl_errno($ch),
        'segundos' => round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2), 'json_valido' => is_array($json),
        'establecimiento_recibido' => isset($json[0]['Id']),
        'aviso_autorizacion' => (bool)preg_match('/autoriz|token|credencial/i', $texto),
        'sin_resultados' => (bool)preg_match('/no hay|no se encontraron|sin resultados/i', $texto)];
    curl_close($ch);
    return $r;
};
$ca = realpath($opciones['ca'] ?? (__DIR__ . '/../config/certificates/inegi-ca-chain.pem'));
if (!$ca || !is_readable($ca)) { fwrite(STDERR, "Falta --ca con el paquete de certificados comprobado.\n"); exit(1); }
echo json_encode(['confianza_servidor' => $consultar(null), 'cadena_completa' => $consultar($ca),
    'verificacion_https' => 'Activa en ambas consultas; ningún dato del CRM modificado.'],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
