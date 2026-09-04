<?php

class HostingerMailApiService
{
    private const BASE_URL_DEFAULT = 'https://api.mail.hostinger.com';

    private $token;
    private $baseUrl;
    private $mailboxes = null;

    public function __construct()
    {
        $archivoConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'config' . DIRECTORY_SEPARATOR . 'hostinger_mail_config.php';

        if (is_file($archivoConfig)) {
            require_once $archivoConfig;
        }

        $this->token = $this->config(
            'HOSTINGER_MAIL_API_TOKEN'
        );
        $this->baseUrl = rtrim(
            $this->config(
                'HOSTINGER_MAIL_API_BASE_URL',
                self::BASE_URL_DEFAULT
            ),
            '/'
        );
    }

    public function estaConfigurado()
    {
        return $this->token !== '' && $this->baseUrl !== '';
    }

    public function enviarOficio($datos)
    {
        if (!$this->estaConfigurado()) {
            return $this->error(
                'La API de correo de Hostinger todavía no está configurada.',
                500,
                'HOSTINGER_MAIL_API_TOKEN no está definido.'
            );
        }

        $remitente = strtolower(trim((string)($datos['remitente'] ?? '')));
        $nombreRemitente = trim((string)($datos['nombre_remitente'] ?? ''));
        $destinatario = trim((string)($datos['destinatario'] ?? ''));
        $asunto = trim((string)($datos['asunto'] ?? ''));
        $cuerpo = (string)($datos['cuerpo'] ?? '');
        $rutaAdjunto = trim((string)($datos['ruta_adjunto'] ?? ''));
        $nombreAdjunto = trim((string)($datos['nombre_adjunto'] ?? ''));

        if ($remitente === '' || !filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'El Analista no tiene un correo corporativo válido para enviar el oficio.',
                422,
                'Correo del Analista inválido: ' . $remitente
            );
        }

        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'El destinatario no tiene un correo válido.',
                422,
                'Correo destinatario inválido: ' . $destinatario
            );
        }

        if ($asunto === '' || trim($cuerpo) === '') {
            return $this->error(
                'El asunto y el mensaje son obligatorios.',
                422,
                'Asunto o cuerpo vacío.'
            );
        }

        if ($rutaAdjunto === '' || !is_file($rutaAdjunto)) {
            return $this->error(
                'El PDF del oficio no está disponible para adjuntarlo.',
                422,
                'Archivo adjunto inexistente: ' . $rutaAdjunto
            );
        }

        $mailboxResourceId = $this->obtenerMailboxResourceId($remitente);

        if (!($mailboxResourceId['ok'] ?? false)) {
            return $mailboxResourceId;
        }

        $contenidoAdjunto = file_get_contents($rutaAdjunto);

        if ($contenidoAdjunto === false) {
            return $this->error(
                'No fue posible leer el PDF del oficio.',
                500,
                'file_get_contents falló para: ' . $rutaAdjunto
            );
        }

        if ($nombreAdjunto === '') {
            $nombreAdjunto = basename($rutaAdjunto);
        }

        $contentType = 'application/pdf';

        if (function_exists('mime_content_type')) {
            $detectado = mime_content_type($rutaAdjunto);

            if (is_string($detectado) && trim($detectado) !== '') {
                $contentType = trim($detectado);
            }
        }

        $payload = [
            'to' => [$destinatario],
            'subject' => $asunto,
            'text' => $cuerpo,
            'attachments' => [
                [
                    'filename' => $nombreAdjunto,
                    'content' => base64_encode($contenidoAdjunto),
                    'contentType' => $contentType,
                    'encoding' => 'base64'
                ]
            ]
        ];

        if ($nombreRemitente !== '') {
            $payload['displayName'] = $nombreRemitente;
        }

        $respuesta = $this->solicitar(
            'POST',
            '/api/v1/mailboxes/' . rawurlencode($mailboxResourceId['resource_id']) . '/send',
            $payload
        );

        if (!($respuesta['ok'] ?? false)) {
            return $this->error(
                'No fue posible enviar el correo institucional mediante Hostinger.',
                (int)($respuesta['codigo_http'] ?? 502),
                (string)($respuesta['mensaje_tecnico'] ?? 'Error desconocido de Hostinger Mail API.')
            );
        }

        return [
            'ok' => true,
            'proveedor' => 'HOSTINGER_MAIL_API',
            'remitente' => $remitente,
            'mailbox_resource_id' => $mailboxResourceId['resource_id']
        ];
    }

    private function obtenerMailboxResourceId($correo)
    {
        $correo = strtolower(trim((string)$correo));
        $mailboxes = $this->obtenerMailboxes();

        if (!($mailboxes['ok'] ?? false)) {
            return $mailboxes;
        }

        foreach ($mailboxes['mailboxes'] as $mailbox) {
            $address = strtolower(trim((string)($mailbox['address'] ?? '')));
            $resourceId = trim((string)(
                $mailbox['resource_id'] ??
                $mailbox['resourceId'] ??
                ''
            ));

            if ($address === $correo && $resourceId !== '') {
                return [
                    'ok' => true,
                    'resource_id' => $resourceId
                ];
            }
        }

        return $this->error(
            'El correo del Analista no corresponde a un buzón autorizado en Hostinger.',
            422,
            'No se encontró mailbox para: ' . $correo
        );
    }

    private function obtenerMailboxes()
    {
        if (is_array($this->mailboxes)) {
            return [
                'ok' => true,
                'mailboxes' => $this->mailboxes
            ];
        }

        $respuesta = $this->solicitar('GET', '/api/v1/me');

        if (!($respuesta['ok'] ?? false)) {
            return $this->error(
                'No fue posible consultar los buzones autorizados en Hostinger.',
                (int)($respuesta['codigo_http'] ?? 502),
                (string)($respuesta['mensaje_tecnico'] ?? '')
            );
        }

        $json = is_array($respuesta['json'] ?? null)
            ? $respuesta['json']
            : [];
        $mailboxes = $json['data']['mailboxes'] ?? $json['mailboxes'] ?? [];

        if (!is_array($mailboxes)) {
            $mailboxes = [];
        }

        $this->mailboxes = $mailboxes;

        return [
            'ok' => true,
            'mailboxes' => $this->mailboxes
        ];
    }

    private function solicitar($metodo, $ruta, $payload = null)
    {
        if (!function_exists('curl_init')) {
            return $this->error(
                'La extensión cURL de PHP es necesaria para usar Hostinger Mail API.',
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
                    'No fue posible preparar la solicitud de correo.',
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
                'No fue posible comunicarse con Hostinger Mail API.',
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
                $respuesta
            ));

            return $this->error(
                'Hostinger Mail API rechazó la solicitud.',
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
