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

    public function probarConexion($phoneNumberId)
    {
        $token = trim((string)($this->config['access_token'] ?? ''));
        $version = trim((string)($this->config['graph_version'] ?? ''));
        $phoneNumberId = trim((string)$phoneNumberId);

        if ($token === '') {
            return [
                'ok' => false,
                'mensaje' => 'Falta configurar el Access Token de Meta.'
            ];
        }

        if ($version === '') {
            return [
                'ok' => false,
                'mensaje' => 'Falta configurar la versión de Graph API.'
            ];
        }

        if ($phoneNumberId === '') {
            return [
                'ok' => false,
                'mensaje' => 'El canal no tiene Phone Number ID.'
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'mensaje' => 'La extensión cURL de PHP no está disponible.'
            ];
        }

        $url = 'https://graph.facebook.com/' .
            rawurlencode($version) . '/' .
            rawurlencode($phoneNumberId) .
            '?fields=id,display_phone_number,verified_name';

        $curl = curl_init($url);

        curl_setopt_array(
            $curl,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json'
                ]
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
            return [
                'ok' => false,
                'mensaje' => (string)(
                    $datos['error']['message'] ??
                    'Meta rechazó la comprobación del canal.'
                ),
                'codigo_meta' => (string)($datos['error']['code'] ?? '')
            ];
        }

        return [
            'ok' => true,
            'phone_number_id' => (string)($datos['id'] ?? $phoneNumberId),
            'display_phone_number' => (string)($datos['display_phone_number'] ?? ''),
            'verified_name' => (string)($datos['verified_name'] ?? ''),
            'graph_version' => $version
        ];
    }

    public function enviarImagenLocal(
        $phoneNumberId,
        $destinatario,
        $rutaArchivo,
        $caption = ''
    ) {
        $subida = $this->subirMediaLocal(
            (string)$phoneNumberId,
            (string)$rutaArchivo
        );

        if (empty($subida['ok'])) {
            return $subida;
        }

        $imagen = [
            'id' => (string)$subida['media_id']
        ];

        $caption = trim((string)$caption);
        if ($caption !== '') {
            $imagen['caption'] = mb_substr($caption, 0, 1024);
        }

        return $this->enviar(
            (string)$phoneNumberId,
            [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $this->normalizarNumero($destinatario),
                'type' => 'image',
                'image' => $imagen
            ]
        );
    }

    private function subirMediaLocal($phoneNumberId, $rutaArchivo)
    {
        $token = trim((string)($this->config['access_token'] ?? ''));
        $version = trim((string)($this->config['graph_version'] ?? ''));
        $phoneNumberId = trim((string)$phoneNumberId);
        $rutaArchivo = trim((string)$rutaArchivo);

        if ($token === '' || $version === '' || $phoneNumberId === '') {
            return [
                'ok' => false,
                'mensaje' => 'La configuración de WhatsApp no está completa para adjuntar la convocatoria.'
            ];
        }

        if ($rutaArchivo === '' || !is_file($rutaArchivo)) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible localizar la imagen de la convocatoria.'
            ];
        }

        if (!function_exists('curl_init') || !class_exists('CURLFile')) {
            return [
                'ok' => false,
                'mensaje' => 'La instalación de PHP no puede adjuntar archivos a WhatsApp.'
            ];
        }

        $mime = function_exists('mime_content_type')
            ? (string)mime_content_type($rutaArchivo)
            : 'image/jpeg';

        if (strpos($mime, 'image/') !== 0) {
            return [
                'ok' => false,
                'mensaje' => 'El archivo de la convocatoria no es una imagen válida.'
            ];
        }

        $url = 'https://graph.facebook.com/' .
            rawurlencode($version) . '/' .
            rawurlencode($phoneNumberId) .
            '/media';

        $curl = curl_init($url);
        $archivo = new CURLFile(
            $rutaArchivo,
            $mime,
            basename($rutaArchivo)
        );

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_POSTFIELDS => [
                    'messaging_product' => 'whatsapp',
                    'file' => $archivo
                ]
            ]
        );

        $respuesta = curl_exec($curl);
        $errorCurl = curl_error($curl);
        $codigoHttp = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($respuesta === false || $errorCurl !== '') {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible subir la imagen a Meta: ' . $errorCurl
            ];
        }

        $datos = json_decode((string)$respuesta, true);

        if ($codigoHttp < 200 || $codigoHttp >= 300) {
            return [
                'ok' => false,
                'mensaje' => (string)(
                    $datos['error']['message'] ??
                    'Meta rechazó la imagen de la convocatoria.'
                ),
                'codigo_meta' => (string)($datos['error']['code'] ?? ''),
                'respuesta' => is_array($datos) ? $datos : []
            ];
        }

        $mediaId = trim((string)($datos['id'] ?? ''));

        if ($mediaId === '') {
            return [
                'ok' => false,
                'mensaje' => 'Meta aceptó la imagen sin devolver un identificador de media.'
            ];
        }

        return [
            'ok' => true,
            'media_id' => $mediaId
        ];
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
            'graph_version' => 'v26.0',
            'test_template' => 'hello_world',
            'test_template_lang' => 'en_US'
        ];

        require_once dirname(__DIR__, 2) . '/config/private_config.php';
        $rutaLocal = impeRutaConfigPrivada('whatsapp.local.php');

        if ($rutaLocal !== null && is_file($rutaLocal)) {
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
        $digitos = preg_replace('/[^0-9]+/', '', (string)$numero);

        /*
         * Unificamos el formato mexicano que Meta todavía puede entregar
         * como 521 + 10 dígitos con el formato vigente 52 + 10 dígitos.
         */
        if (
            strlen($digitos) === 13 &&
            strpos($digitos, '521') === 0
        ) {
            $digitos = '52' . substr($digitos, 3);
        }

        return $digitos;
    }
}
