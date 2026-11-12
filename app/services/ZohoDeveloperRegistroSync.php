<?php
/**
 * Piloto de sincronización de REGISTROS (no inscripciones) a Zoho CRM Developer.
 * Desactivado sin configuración privada y limitado a emails ficticios exactos.
 * No imprime ni registra credenciales o datos personales.
 */
require_once __DIR__ . '/../../config/private_config.php';

final class ZohoDeveloperRegistroSync
{
    /** Transporte reemplazable solo por pruebas CLI, nunca en producción. */
    private static $transporteSimulado = null;

    public static function establecerTransporteDePruebas(?callable $transporte): void
    {
        if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing') {
            throw new LogicException('TRANSPORTE_SIMULADO_SOLO_CI');
        }
        self::$transporteSimulado = $transporte;
    }

    public static function configuracion(): array
    {
        $cfg = impeLeerConfigPrivada('zoho_sync.local.php');
        if (($cfg['enabled'] ?? false) !== true ||
            ($cfg['environment'] ?? '') !== 'developer' ||
            !preg_match('/^[0-9]{10,25}$/D', (string)($cfg['expected_org_id'] ?? '')) ||
            !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,79}$/D', (string)($cfg['unique_field'] ?? '')) ||
            !is_array($cfg['test_emails'] ?? null) ||
            count($cfg['test_emails']) === 0) {
            return [];
        }

        // Solo emails ficticios en el dominio reservado example.com.
        foreach ($cfg['test_emails'] as $email) {
            if (!is_string($email) ||
                !preg_match('/^[a-z0-9._+-]+@example\.com$/D', $email)) {
                return [];
            }
        }

        return $cfg;
    }

    public static function autorizado(array $cfg, string $email): bool
    {
        return $cfg !== [] &&
            in_array(strtolower(trim($email)), $cfg['test_emails'], true);
    }

    public static function encolarSiAutorizado(mysqli $db, int $registroId, string $email): void
    {
        $cfg = self::configuracion();
        if ($registroId <= 0 || !self::autorizado($cfg, $email)) {
            return;
        }
        $stmt = $db->prepare(
            "INSERT INTO zoho_dev_registros_pendientes
                (registro_id, estado, created_at, updated_at)
             VALUES (?, 'pendiente', NOW(), NOW())
             ON DUPLICATE KEY UPDATE registro_id = registro_id"
        );
        $stmt->bind_param('i', $registroId);
        if (!$stmt->execute()) {
            throw new RuntimeException('No fue posible encolar el registro ficticio.');
        }
        $stmt->close();
    }

    public static function claveExterna(int $registroId): string
    {
        if ($registroId <= 0) {
            throw new InvalidArgumentException('ID de registro inválido.');
        }
        return 'IMPE_REGISTRO_' . $registroId;
    }

    public static function datosLead(array $registro, int $registroId, array $cfg): array
    {
        $email = strtolower(trim((string)($registro['correo'] ?? '')));
        if (!self::autorizado($cfg, $email)) {
            throw new RuntimeException('EMAIL_FUERA_DE_PRUEBA');
        }
        $apellido = trim((string)($registro['apellido'] ?? ''));
        if ($apellido === '') {
            throw new RuntimeException('APELLIDO_REQUERIDO');
        }

        $lead = [
            'First_Name' => trim((string)($registro['nombre'] ?? '')),
            'Last_Name' => $apellido,
            'Company' => 'SistemaComercialIMPE - Pruebas',
            'Email' => $email,
            $cfg['unique_field'] => self::claveExterna($registroId),
        ];
        $movil = trim((string)($registro['movil'] ?? ''));
        if (preg_match('/^[0-9]{10}$/D', $movil)) {
            $lead['Mobile'] = $movil;
        }
        return $lead;
    }

    private static function peticion(
        string $url,
        ?array $post = null,
        ?string $token = null
    ): array {
        if (self::$transporteSimulado !== null) {
            $respuesta = call_user_func(self::$transporteSimulado, $url, $post, $token);
            if (!is_array($respuesta) || count($respuesta) !== 2 ||
                !is_int($respuesta[0] ?? null) || !is_array($respuesta[1] ?? null)) {
                throw new RuntimeException('RESPUESTA_SIMULADA_INVALIDA');
            }
            return $respuesta;
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('CURL_NO_DISPONIBLE');
        }
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($token !== null) {
            $headers[] = 'Authorization: Zoho-oauthtoken ' . $token;
        }
        if ($post !== null && strpos($url, '/oauth/v2/token') === false) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 22,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            $body = strpos($url, '/oauth/v2/token') !== false
                ? http_build_query($post, '', '&', PHP_QUERY_RFC3986)
                : json_encode($post, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        curl_close($ch);
        if ($curlError !== 0 || $raw === false) {
            throw new RuntimeException('ERROR_RED_ZOHO');
        }
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('RESPUESTA_ZOHO_INVALIDA');
        }
        return [$http, $json];
    }

    private static function token(): string
    {
        $home = (string)(getenv('HOME') ?: '');
        if ($home === '' || !is_dir($home) || strpos($home, 'public_html') !== false) {
            throw new RuntimeException('HOME_PRIVADO_NO_DISPONIBLE');
        }
        $ruta = rtrim($home, '/') .
            '/.config/sistema_comercial_impe/zoho_oauth.json';
        if (!is_file($ruta) || !is_readable($ruta)) {
            throw new RuntimeException('AUTORIZACION_ZOHO_NO_DISPONIBLE');
        }
        $oauth = json_decode((string)file_get_contents($ruta), true);
        if (!is_array($oauth)) {
            throw new RuntimeException('AUTORIZACION_ZOHO_INVALIDA');
        }
        foreach (['client_id', 'client_secret', 'refresh_token'] as $clave) {
            if (!is_string($oauth[$clave] ?? null) || $oauth[$clave] === '') {
                throw new RuntimeException('CREDENCIAL_ZOHO_INCOMPLETA');
            }
        }
        // Las credenciales fueron autorizadas en accounts.zoho.com.
        [$http, $json] = self::peticion(
            'https://accounts.zoho.com/oauth/v2/token',
            [
                'grant_type' => 'refresh_token',
                'client_id' => $oauth['client_id'],
                'client_secret' => $oauth['client_secret'],
                'refresh_token' => $oauth['refresh_token'],
            ]
        );
        if ($http !== 200 || !is_string($json['access_token'] ?? null)) {
            throw new RuntimeException('REFRESH_ZOHO_HTTP_' . $http);
        }
        // Zoho puede devolver www.zohoapis.com aunque la org sea Developer.
        return $json['access_token'];
    }

    public static function comprobarOrganizacion(array $cfg, string $accessToken): void
    {
        [$http, $json] = self::peticion(
            'https://developer.zohoapis.com/crm/v8/org',
            null,
            $accessToken
        );
        if ($http !== 200 || !is_array($json['org'] ?? null) ||
            count($json['org']) !== 1 ||
            (string)($json['org'][0]['id'] ?? '') !== (string)$cfg['expected_org_id']) {
            throw new RuntimeException('ORGANIZACION_DEVELOPER_NO_COINCIDE');
        }
    }

    public static function crearOActualizarLead(
        array $registro, int $registroId, array $cfg, string $accessToken
    ): string {
        $lead = self::datosLead($registro, $registroId, $cfg);
        [$http, $json] = self::peticion(
            'https://developer.zohoapis.com/crm/v8/Leads/upsert',
            [
                'data' => [$lead],
                'duplicate_check_fields' => [$cfg['unique_field']],
                'trigger' => [],
            ],
            $accessToken
        );
        $fila = $json['data'][0] ?? null;
        if (($http !== 200 && $http !== 201 && $http !== 207) ||
            !is_array($fila) || ($fila['status'] ?? '') !== 'success' ||
            !preg_match('/^[0-9]{5,30}$/D', (string)($fila['details']['id'] ?? ''))) {
            $codigo = preg_replace('/[^A-Z0-9_]/', '', strtoupper(
                (string)($fila['code'] ?? $json['code'] ?? 'HTTP_ERROR')
            ));
            throw new RuntimeException('UPSERT_ZOHO_HTTP_' . $http . '_' . substr($codigo, 0, 50));
        }
        return (string)$fila['details']['id'];
    }

    public static function tokenVerificado(array $cfg): string
    {
        $token = self::token();
        self::comprobarOrganizacion($cfg, $token);
        return $token;
    }
}
