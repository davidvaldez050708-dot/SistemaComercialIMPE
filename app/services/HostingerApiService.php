<?php

class HostingerApiService
{
    private const BASE_URL_DEFAULT = 'https://developers.hostinger.com';

    private $token;
    private $baseUrl;

    public function __construct()
    {
        $archivoConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'config' . DIRECTORY_SEPARATOR . 'hostinger_mail_config.php';

        if (is_file($archivoConfig)) {
            require_once $archivoConfig;
        }

        $this->token = $this->config('HOSTINGER_API_TOKEN');
        $this->baseUrl = rtrim(
            $this->config(
                'HOSTINGER_API_BASE_URL',
                self::BASE_URL_DEFAULT
            ),
            '/'
        );
    }

    public function estaConfigurado()
    {
        return $this->token !== '' && $this->baseUrl !== '';
    }

    public function listarOrdenesCorreo()
    {
        if (!$this->estaConfigurado()) {
            return $this->error(
                'El token general de Hostinger todavía no está configurado.',
                500,
                'HOSTINGER_API_TOKEN no está definido.'
            );
        }

        $respuesta = $this->solicitar(
            'GET',
            '/api/mail/v1/orders?per_page=100'
        );

        if (!($respuesta['ok'] ?? false)) {
            return $respuesta;
        }

        $json = is_array($respuesta['json'] ?? null)
            ? $respuesta['json']
            : [];
        $ordenes = $json['data'] ?? [];

        if (!is_array($ordenes)) {
            $ordenes = [];
        }

        return [
            'ok' => true,
            'ordenes' => $ordenes,
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : []
        ];
    }

    public function listarBuzones($orderId)
    {
        if (!$this->estaConfigurado()) {
            return $this->error(
                'El token general de Hostinger todavía no está configurado.',
                500,
                'HOSTINGER_API_TOKEN no está definido.'
            );
        }

        $orderId = trim((string)$orderId);

        if ($orderId === '') {
            return $this->error(
                'No se recibió el identificador del servicio de correo.',
                422,
                'orderId vacío.'
            );
        }

        $respuesta = $this->solicitar(
            'GET',
            '/api/mail/v1/orders/' . rawurlencode($orderId) .
                '/mailboxes?per_page=100'
        );

        if (!($respuesta['ok'] ?? false)) {
            return $respuesta;
        }

        $json = is_array($respuesta['json'] ?? null)
            ? $respuesta['json']
            : [];
        $buzones = $json['data'] ?? [];

        if (!is_array($buzones)) {
            $buzones = [];
        }

        return [
            'ok' => true,
            'buzones' => $buzones,
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : []
        ];
    }

    public function listarAliases($orderId)
    {
        if (!$this->estaConfigurado()) {
            return $this->error(
                'El token general de Hostinger todavía no está configurado.',
                500,
                'HOSTINGER_API_TOKEN no está definido.'
            );
        }

        $orderId = trim((string)$orderId);

        if ($orderId === '') {
            return $this->error(
                'No se recibió el identificador del servicio de correo.',
                422,
                'orderId vacío.'
            );
        }

        $respuesta = $this->solicitar(
            'GET',
            '/api/mail/v1/orders/' . rawurlencode($orderId) .
                '/aliases?per_page=100'
        );

        if (!($respuesta['ok'] ?? false)) {
            return $respuesta;
        }

        $json = is_array($respuesta['json'] ?? null)
            ? $respuesta['json']
            : [];
        $aliases = $json['data'] ?? [];

        if (!is_array($aliases)) {
            $aliases = [];
        }

        return [
            'ok' => true,
            'aliases' => $aliases,
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : []
        ];
    }

    public function listarTokensMailApi($orderId = '')
    {
        if (!$this->estaConfigurado()) {
            return $this->error(
                'El token general de Hostinger todavía no está configurado.',
                500,
                'HOSTINGER_API_TOKEN no está definido.'
            );
        }

        $ruta = '/api/mail/v1/api-tokens?per_page=100';
        $orderId = trim((string)$orderId);

        if ($orderId !== '') {
            $ruta .= '&order_id=' . rawurlencode($orderId);
        }

        $respuesta = $this->solicitar('GET', $ruta);

        if (!($respuesta['ok'] ?? false)) {
            return $respuesta;
        }

        $json = is_array($respuesta['json'] ?? null)
            ? $respuesta['json']
            : [];
        $tokens = $json['data'] ?? [];

        if (!is_array($tokens)) {
            $tokens = [];
        }

        return [
            'ok' => true,
            'tokens' => $tokens,
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : []
        ];
    }

    public function crearTokenMailApi($orderId, $nombre, array $mailboxIds)
    {
        if (!$this->estaConfigurado()) {
            return $this->error(
                'El token general de Hostinger todavía no está configurado.',
                500,
                'HOSTINGER_API_TOKEN no está definido.'
            );
        }

        $orderId = trim((string)$orderId);
        $nombre = trim((string)$nombre);
        $mailboxIds = array_values(array_unique(array_filter(array_map(
            function ($id) {
                return trim((string)$id);
            },
            $mailboxIds
        ))));

        if ($orderId === '' || $nombre === '' || count($mailboxIds) === 0) {
            return $this->error(
                'Faltan datos para generar el token de Hostinger Mail API.',
                422,
                'Se requiere orderId, nombre y al menos un mailboxId.'
            );
        }

        $respuesta = $this->solicitar(
            'POST',
            '/api/mail/v1/orders/' . rawurlencode($orderId) . '/api-tokens',
            [
                'name' => $nombre,
                'scope' => [
                    'has_all_mailboxes' => false,
                    'mailbox_ids' => $mailboxIds
                ]
            ]
        );

        if (!($respuesta['ok'] ?? false)) {
            return $respuesta;
        }

        $json = is_array($respuesta['json'] ?? null)
            ? $respuesta['json']
            : [];
        $tokenPlano = trim((string)($json['token'] ?? ''));
        $tokenId = trim((string)($json['id'] ?? ''));

        if ($tokenPlano === '') {
            return $this->error(
                'Hostinger creó la credencial, pero no devolvió el token en la respuesta.',
                502,
                'Respuesta 2xx sin campo token.'
            );
        }

        return [
            'ok' => true,
            'token' => $tokenPlano,
            'token_id' => $tokenId,
            'nombre' => trim((string)($json['name'] ?? $nombre)),
            'scope' => is_array($json['scope'] ?? null) ? $json['scope'] : []
        ];
    }

    private function solicitar($metodo, $ruta, $payload = null)
    {
        if (!function_exists('curl_init')) {
            return $this->error(
                'La extensión cURL de PHP es necesaria para consultar Hostinger.',
                500,
                'curl_init no está disponible.'
            );
        }

        $url = $this->baseUrl . '/' . ltrim((string)$ruta, '/');
        $curl = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token
        ];

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper((string)$metodo));
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        if ($payload !== null) {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($json === false) {
                curl_close($curl);

                return $this->error(
                    'No fue posible preparar la solicitud para Hostinger.',
                    500,
                    'json_encode falló: ' . json_last_error_msg()
                );
            }

            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $respuesta = curl_exec($curl);
        $codigoHttp = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errorCurl = curl_error($curl);
        curl_close($curl);

        if ($respuesta === false) {
            return $this->error(
                'No fue posible comunicarse con Hostinger API.',
                502,
                $errorCurl !== '' ? $errorCurl : 'curl_exec devolvió false.'
            );
        }

        $jsonRespuesta = [];

        if (trim((string)$respuesta) !== '') {
            $decodificado = json_decode((string)$respuesta, true);

            if (is_array($decodificado)) {
                $jsonRespuesta = $decodificado;
            }
        }

        if ($codigoHttp < 200 || $codigoHttp >= 300) {
            $detalle = trim((string)(
                $jsonRespuesta['message'] ??
                $jsonRespuesta['error'] ??
                ''
            ));

            return $this->error(
                'Hostinger API rechazó la solicitud.',
                $codigoHttp > 0 ? $codigoHttp : 502,
                'HTTP ' . $codigoHttp . ($detalle !== '' ? ': ' . $detalle : '')
            );
        }

        return [
            'ok' => true,
            'codigo_http' => $codigoHttp,
            'json' => $jsonRespuesta
        ];
    }

    private function config($nombre, $default = '')
    {
        if (defined($nombre)) {
            return trim((string)constant($nombre));
        }

        $valor = getenv($nombre);

        if ($valor !== false && trim((string)$valor) !== '') {
            return trim((string)$valor);
        }

        return trim((string)$default);
    }

    private function error($mensaje, $codigoHttp, $mensajeTecnico)
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'codigo_http' => (int)$codigoHttp,
            'mensaje_tecnico' => $mensajeTecnico
        ];
    }
}
