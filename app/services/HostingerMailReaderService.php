<?php

class HostingerMailReaderService
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

        $this->token = $this->config('HOSTINGER_MAIL_API_TOKEN');
        $this->baseUrl = rtrim(
            $this->config('HOSTINGER_MAIL_API_BASE_URL', self::BASE_URL_DEFAULT),
            '/'
        );
    }

    public function obtenerTextoRespuesta($datos)
    {
        if ($this->token === '' || $this->baseUrl === '') {
            return [
                'ok' => false,
                'disponible' => false,
                'mensaje' => 'Hostinger Mail API no está configurada para lectura.'
            ];
        }

        $mailbox = strtolower(trim((string)($datos['mailbox'] ?? '')));
        $remitente = strtolower(trim((string)($datos['remitente'] ?? '')));
        $asunto = trim((string)($datos['asunto'] ?? ''));
        $recibidoAt = trim((string)($datos['recibido_at'] ?? ''));
        $mensajeExternoId = trim((string)($datos['mensaje_externo_id'] ?? ''));

        if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok' => false,
                'disponible' => false,
                'mensaje' => 'El webhook no identificó un buzón válido.'
            ];
        }

        $mailboxResourceId = $this->obtenerMailboxResourceId($mailbox);
        if (!($mailboxResourceId['ok'] ?? false)) {
            return $mailboxResourceId;
        }

        $criterios = [];
        if (filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            $criterios['from'] = $remitente;
        }

        if ($recibidoAt !== '') {
            try {
                $fecha = new DateTime($recibidoAt);
                $criterios['since'] = (clone $fecha)->modify('-2 days')->format('Y-m-d');
                $criterios['before'] = (clone $fecha)->modify('+2 days')->format('Y-m-d');
            } catch (Throwable $error) {
                // La búsqueda por remitente sigue funcionando aunque la fecha no sea utilizable.
            }
        }

        $resourceId = rawurlencode((string)$mailboxResourceId['resource_id']);
        $rutaBusqueda = '/api/v1/mailboxes/' . $resourceId .
            '/folders/INBOX/messages/search?page=1&perPage=30&sort=-uid';
        $busqueda = $this->solicitar('POST', $rutaBusqueda, $criterios);

        if (!($busqueda['ok'] ?? false)) {
            return [
                'ok' => false,
                'disponible' => true,
                'mensaje' => 'No fue posible consultar el contenido completo del correo.'
            ];
        }

        $mensajes = $busqueda['json']['data'] ?? [];
        if (!is_array($mensajes) || empty($mensajes)) {
            return [
                'ok' => false,
                'disponible' => true,
                'mensaje' => 'No se encontró el mensaje en INBOX.'
            ];
        }

        $mejor = $this->seleccionarMensaje(
            $mensajes,
            $remitente,
            $asunto,
            $recibidoAt,
            $mensajeExternoId
        );

        if (!$mejor || (int)($mejor['uid'] ?? 0) <= 0) {
            return [
                'ok' => false,
                'disponible' => true,
                'mensaje' => 'No fue posible identificar con seguridad el mensaje recibido.'
            ];
        }

        $uid = (int)$mejor['uid'];
        $rutaTexto = '/api/v1/mailboxes/' . $resourceId .
            '/folders/INBOX/messages/' . $uid . '/text';
        $contenido = $this->solicitar('GET', $rutaTexto);

        if (!($contenido['ok'] ?? false)) {
            return [
                'ok' => false,
                'disponible' => true,
                'mensaje' => 'Hostinger encontró el mensaje, pero no fue posible leer su contenido.'
            ];
        }

        $data = is_array($contenido['json']['data'] ?? null)
            ? $contenido['json']['data']
            : [];
        $texto = trim((string)($data['text'] ?? ''));

        if ($texto === '' && trim((string)($data['html'] ?? '')) !== '') {
            $texto = html_entity_decode(
                strip_tags((string)$data['html']),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $texto = trim((string)$texto);
        }

        if ($texto === '') {
            return [
                'ok' => false,
                'disponible' => true,
                'mensaje' => 'El mensaje no contiene una parte de texto legible.'
            ];
        }

        return [
            'ok' => true,
            'disponible' => true,
            'texto' => $texto,
            'uid' => $uid,
            'message_id' => trim((string)($mejor['message_id'] ?? '')),
            'fuente' => 'HOSTINGER_MAIL_API'
        ];
    }

    private function seleccionarMensaje($mensajes, $remitente, $asunto, $recibidoAt, $mensajeExternoId)
    {
        $mejor = null;
        $mejorPuntaje = -1;
        $asuntoEsperado = $this->normalizarAsunto($asunto);
        $timestampEsperado = $this->timestampSeguro($recibidoAt);

        foreach ($mensajes as $mensaje) {
            if (!is_array($mensaje)) {
                continue;
            }

            $puntaje = 0;
            $messageId = trim((string)($mensaje['message_id'] ?? $mensaje['messageId'] ?? ''));
            if (
                $mensajeExternoId !== '' &&
                $messageId !== '' &&
                hash_equals($mensajeExternoId, $messageId)
            ) {
                $puntaje += 120;
            }

            $from = $mensaje['from'] ?? [];
            if (is_array($from)) {
                $from = $from['address'] ?? $from['email'] ?? '';
            }
            $from = strtolower(trim((string)$from));
            if ($remitente !== '' && $from === $remitente) {
                $puntaje += 60;
            }

            $asuntoMensaje = $this->normalizarAsunto($mensaje['subject'] ?? '');
            if ($asuntoEsperado !== '' && $asuntoMensaje !== '') {
                if ($asuntoMensaje === $asuntoEsperado) {
                    $puntaje += 35;
                } elseif (
                    strpos($asuntoMensaje, $asuntoEsperado) !== false ||
                    strpos($asuntoEsperado, $asuntoMensaje) !== false
                ) {
                    $puntaje += 20;
                }
            }

            $fechaMensaje = $mensaje['date'] ?? $mensaje['var_date'] ?? $mensaje['received_at'] ?? '';
            $timestampMensaje = $this->timestampSeguro($fechaMensaje);
            if ($timestampEsperado !== null && $timestampMensaje !== null) {
                $diferencia = abs($timestampMensaje - $timestampEsperado);
                if ($diferencia <= 10 * 60) {
                    $puntaje += 30;
                } elseif ($diferencia <= 2 * 60 * 60) {
                    $puntaje += 20;
                } elseif ($diferencia <= 24 * 60 * 60) {
                    $puntaje += 10;
                }
            }

            if ($puntaje > $mejorPuntaje) {
                $mejorPuntaje = $puntaje;
                $mejor = $mensaje;
            }
        }

        // Exigimos al menos coincidencia de remitente u otra señal fuerte.
        return $mejorPuntaje >= 50 ? $mejor : null;
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
                $mailbox['resourceId'] ??
                $mailbox['resource_id'] ??
                ''
            ));

            if ($address === $correo && $resourceId !== '') {
                return [
                    'ok' => true,
                    'resource_id' => $resourceId
                ];
            }
        }

        return [
            'ok' => false,
            'disponible' => true,
            'mensaje' => 'El buzón del webhook no está autorizado por el token de Hostinger.'
        ];
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
            return [
                'ok' => false,
                'disponible' => true,
                'mensaje' => 'No fue posible consultar los buzones autorizados en Hostinger.'
            ];
        }

        $mailboxes = $respuesta['json']['data']['mailboxes'] ?? [];
        $this->mailboxes = is_array($mailboxes) ? $mailboxes : [];

        return [
            'ok' => true,
            'mailboxes' => $this->mailboxes
        ];
    }

    private function solicitar($metodo, $ruta, $payload = null)
    {
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'codigo_http' => 500
            ];
        }

        $curl = curl_init($this->baseUrl . '/' . ltrim((string)$ruta, '/'));
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token
        ];

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper((string)$metodo));
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);

        if ($payload !== null) {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($json === false) {
                curl_close($curl);
                return ['ok' => false, 'codigo_http' => 500];
            }

            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $respuesta = curl_exec($curl);
        $codigoHttp = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($respuesta === false || $codigoHttp < 200 || $codigoHttp >= 300) {
            return [
                'ok' => false,
                'codigo_http' => $codigoHttp > 0 ? $codigoHttp : 502
            ];
        }

        $json = json_decode((string)$respuesta, true);

        return [
            'ok' => true,
            'codigo_http' => $codigoHttp,
            'json' => is_array($json) ? $json : []
        ];
    }

    private function normalizarAsunto($valor)
    {
        $valor = trim((string)$valor);
        $valor = preg_replace('/^(?:(?:re|rv|fw|fwd)\s*:\s*)+/iu', '', $valor);
        $valor = mb_strtolower(trim((string)$valor), 'UTF-8');
        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if (is_string($transliterado)) {
            $valor = strtolower($transliterado);
        }
        $valor = preg_replace('/[^a-z0-9]+/', ' ', $valor);

        return trim((string)$valor);
    }

    private function timestampSeguro($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }

        try {
            return (new DateTime($valor))->getTimestamp();
        } catch (Throwable $error) {
            return null;
        }
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
}
