<?php

class WhatsAppCloudApiService
{
    private $config;

    public function __construct()
    {
        $this->config = $this->cargarConfiguracion();
    }

    public function obtenerEstadoConfiguracion()
    {
        $faltantes = [];

        foreach (
            [
                'access_token' => 'Access Token',
                'verify_token' => 'Verify Token',
                'app_secret' => 'App Secret',
                'graph_version' => 'Graph API Version'
            ] as $campo => $etiqueta
        ) {
            if (trim((string)($this->config[$campo] ?? '')) === '') {
                $faltantes[] = $etiqueta;
            }
        }

        return [
            'lista' => empty($faltantes),
            'faltantes' => $faltantes,
            'graph_version' => (string)($this->config['graph_version'] ?? ''),
            'test_template' => (string)($this->config['test_template'] ?? 'hello_world'),
            'test_template_lang' => (string)($this->config['test_template_lang'] ?? 'en_US')
        ];
    }

    public function obtenerVerifyToken()
    {
        return (string)($this->config['verify_token'] ?? '');
    }

    public function validarFirmaWebhook($payload, $firmaHeader)
    {
        $secret = trim((string)($this->config['app_secret'] ?? ''));
        $firmaHeader = trim((string)$firmaHeader);

        if ($secret === '' || $firmaHeader === '') {
            return false;
        }

        if (strpos($firmaHeader, 'sha256=') !== 0) {
            return false;
        }

        $esperada = 'sha256=' . hash_hmac('sha256', (string)$payload, $secret);

        return hash_equals($esperada, $firmaHeader);
    }

    public function enviarTexto($phoneNumberId, $destinatario, $texto)
    {
        return $this->enviar(
            (string)$phoneNumberId,
            [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $this->normalizarNumero($destinatario),
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => (string)$texto
                ]
            ]
        );
    }

    public function enviarPlantillaPrueba($phoneNumberId, $destinatario)
    {
        $nombre = trim(
            (string)($this->config['test_template'] ?? 'hello_world')
        );
        $idioma = trim(
            (string)($this->config['test_template_lang'] ?? 'en_US')
        );

        if ($nombre === '') {
            return [
                'ok' => false,
                'mensaje' => 'No hay una plantilla de prueba configurada.'
            ];
        }

        return $this->enviar(
            (string)$phoneNumberId,
            [
                'messaging_product' => 'whatsapp',
                'to' => $this->normalizarNumero($destinatario),
                'type' => 'template',
                'template' => [
                    'name' => $nombre,
                    'language' => [
                        'code' => $idioma !== '' ? $idioma : 'en_US'
                    ]
                ]
            ]
        );
    }

    private function enviar($phoneNumberId, array $payload)
    {
        $estado = $this->obtenerEstadoConfiguracion();

        if (!$estado['lista']) {
            return [
                'ok' => false,
                'mensaje' => 'WhatsApp Cloud API no está configurado. Faltan: ' .
                    implode(', ', $estado['faltantes'])
            ];
        }

        $phoneNumberId = trim((string)$phoneNumberId);

        if ($phoneNumberId === '') {
            return [
                'ok' => false,
                'mensaje' => 'La cuenta de WhatsApp no tiene Phone Number ID.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'mensaje' => 'La extensión cURL de PHP no está disponible.'
            ];
        }

        $version = trim((string)$this->config['graph_version']);
        $url = 'https://graph.facebook.com/' .
            rawurlencode($version) . '/' .
            rawurlencode($phoneNumberId) .
            '/messages';

        $curl = curl_init($url);

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' .
                        (string)$this->config['access_token'],
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES |
                    JSON_INVALID_UTF8_SUBSTITUTE
                )
            ]
        );

        $respuesta = curl_exec($curl);
        $errorCurl = curl_error($curl);
        $codigoHttp = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($respuesta === false || $errorCurl !== '') {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible conectar con Meta: ' . $errorCurl
            ];
        }

        $datos = json_decode((string)$respuesta, true);

        if ($codigoHttp < 200 || $codigoHttp >= 300) {
            $mensaje = (string)(
                $datos['error']['message'] ??
                'Meta rechazó el envío.'
            );

            return [
                'ok' => false,
                'mensaje' => $mensaje,
                'codigo_meta' => (string)($datos['error']['code'] ?? ''),
                'respuesta' => is_array($datos) ? $datos : []
            ];
        }

        $wamid = trim((string)($datos['messages'][0]['id'] ?? ''));

        if ($wamid === '') {
            return [
                'ok' => false,
                'mensaje' => 'Meta aceptó la petición sin devolver un ID de mensaje.',
                'respuesta' => is_array($datos) ? $datos : []
            ];
        }

        return [
            'ok' => true,
            'wamid' => $wamid,
            'respuesta' => $datos
        ];
    }

    private function cargarConfiguracion()
    {
        $config = [
            'access_token' => '',
            'verify_token' => '',
            'app_secret' => '',
            'graph_version' => '',
            'test_template' => 'hello_world',
            'test_template_lang' => 'en_US'
        ];

        $rutaLocal = dirname(__DIR__, 2) . '/config/whatsapp.local.php';

        if (is_file($rutaLocal)) {
            $local = require $rutaLocal;

            if (is_array($local)) {
                $config = array_merge($config, $local);
            }
        }

        $variables = [
            'access_token' => 'WHATSAPP_ACCESS_TOKEN',
            'verify_token' => 'WHATSAPP_VERIFY_TOKEN',
            'app_secret' => 'WHATSAPP_APP_SECRET',
            'graph_version' => 'WHATSAPP_GRAPH_VERSION',
            'test_template' => 'WHATSAPP_TEST_TEMPLATE',
            'test_template_lang' => 'WHATSAPP_TEST_TEMPLATE_LANG'
        ];

        foreach ($variables as $campo => $variable) {
            $valor = getenv($variable);

            if ($valor !== false && trim((string)$valor) !== '') {
                $config[$campo] = trim((string)$valor);
            }
        }

        return $config;
    }

    private function normalizarNumero($numero)
    {
        return preg_replace('/[^0-9]+/', '', (string)$numero);
    }
}
