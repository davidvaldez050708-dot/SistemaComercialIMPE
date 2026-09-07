<?php

class HostingerMailApiService
{
    private const BASE_URL_DEFAULT = 'https://api.mail.hostinger.com';
    private const FIRMA_CID = 'firma-analista';

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

    public function diagnosticarAcceso()
    {
        if (!$this->estaConfigurado()) {
            return $this->error(
                'La API de correo de Hostinger todavía no está configurada.',
                500,
                'HOSTINGER_MAIL_API_TOKEN no está definido.'
            );
        }

        $resultado = $this->obtenerMailboxes();

        if (!($resultado['ok'] ?? false)) {
            return $resultado;
        }

        $mailboxes = [];

        foreach ($resultado['mailboxes'] as $mailbox) {
            if (!is_array($mailbox)) {
                continue;
            }

            $mailboxes[] = [
                'address' => trim((string)($mailbox['address'] ?? '')),
                'resource_id' => trim((string)(
                    $mailbox['resource_id'] ??
                    $mailbox['resourceId'] ??
                    ''
                ))
            ];
        }

        return [
            'ok' => true,
            'mailboxes' => $mailboxes,
            'total' => count($mailboxes)
        ];
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

        $contentType = $this->detectarContentType(
            $rutaAdjunto,
            'application/pdf'
        );

        $attachments = [
            [
                'filename' => $nombreAdjunto,
                'content' => base64_encode($contenidoAdjunto),
                'contentType' => $contentType,
                'encoding' => 'base64'
            ]
        ];

        $rutaFirma = $this->buscarFirmaLocal($remitente);
        $firmaDisponible = $rutaFirma !== '' && is_file($rutaFirma);

        if ($firmaDisponible) {
            $contenidoFirma = file_get_contents($rutaFirma);

            if ($contenidoFirma !== false) {
                $attachments[] = [
                    'filename' => basename($rutaFirma),
                    'content' => base64_encode($contenidoFirma),
                    'contentType' => $this->detectarContentType(
                        $rutaFirma,
                        'image/png'
                    ),
                    'cid' => self::FIRMA_CID,
                    'encoding' => 'base64'
                ];
            } else {
                $firmaDisponible = false;
            }
        }

        $texto = rtrim($cuerpo);
        $firmaTexto = $this->construirFirmaTexto(
            $nombreRemitente,
            $remitente
        );

        if (!$this->cuerpoYaIncluyeFirma($texto, $nombreRemitente, $remitente)) {
            $texto .= "\n\n" . $firmaTexto;
        }

        $payload = [
            'to' => [$destinatario],
            'subject' => $asunto,
            'text' => $texto,
            'html' => $this->construirHtmlCorreo(
                $cuerpo,
                $nombreRemitente,
                $remitente,
                $firmaDisponible
            ),
            'attachments' => $attachments
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
            'mailbox_resource_id' => $mailboxResourceId['resource_id'],
            'firma_incluida' => $firmaDisponible
        ];
    }

    private function construirHtmlCorreo($cuerpo, $nombreRemitente, $remitente, $firmaDisponible)
    {
        $cuerpoSeguro = htmlspecialchars(
            trim((string)$cuerpo),
            ENT_QUOTES,
            'UTF-8'
        );
        $cuerpoHtml = nl2br($cuerpoSeguro, false);

        $html = '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:0;background:#ffffff;">';
        $html .= '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.65;color:#222222;max-width:760px;">';
        $html .= $cuerpoHtml;

        if ($firmaDisponible) {
            $html .= '<div style="margin-top:22px;">';
            $html .= '<img src="cid:' . self::FIRMA_CID . '" alt="Firma institucional" style="display:block;width:100%;max-width:720px;height:auto;border:0;">';
            $html .= '</div>';
        } else {
            $firma = htmlspecialchars(
                $this->construirFirmaTexto($nombreRemitente, $remitente),
                ENT_QUOTES,
                'UTF-8'
            );
            $html .= '<div style="margin-top:22px;">' . nl2br($firma, false) . '</div>';
        }

        $html .= '</div></body></html>';

        return $html;
    }

    private function construirFirmaTexto($nombreRemitente, $remitente)
    {
        $nombreRemitente = trim((string)$nombreRemitente);
        $remitente = trim((string)$remitente);
        $lineas = [];

        if ($nombreRemitente !== '') {
            $lineas[] = $nombreRemitente;
        }

        $lineas[] = 'Analista de Enlace Institucional';
        $lineas[] = 'Fundación Red Educativa México';

        if ($remitente !== '') {
            $lineas[] = $remitente;
        }

        return implode("\n", $lineas);
    }

    private function cuerpoYaIncluyeFirma($cuerpo, $nombreRemitente, $remitente)
    {
        $cuerpo = strtolower((string)$cuerpo);
        $nombreRemitente = strtolower(trim((string)$nombreRemitente));
        $remitente = strtolower(trim((string)$remitente));

        if ($remitente !== '' && strpos($cuerpo, $remitente) !== false) {
            return true;
        }

        return $nombreRemitente !== '' && strpos($cuerpo, $nombreRemitente) !== false;
    }

    private function buscarFirmaLocal($remitente)
    {
        $remitente = strtolower(trim((string)$remitente));

        if ($remitente === '') {
            return '';
        }

        $nombreBase = preg_replace('/[^a-z0-9._-]+/i', '_', $remitente);
        $directorio = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . 'firmas';

        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            $ruta = $directorio . DIRECTORY_SEPARATOR . $nombreBase . '.' . $extension;

            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return '';
    }

    private function detectarContentType($ruta, $default)
    {
        $contentType = trim((string)$default);

        if (function_exists('mime_content_type')) {
            $detectado = mime_content_type($ruta);

            if (is_string($detectado) && trim($detectado) !== '') {
                $contentType = trim($detectado);
            }
        }

        return $contentType;
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
