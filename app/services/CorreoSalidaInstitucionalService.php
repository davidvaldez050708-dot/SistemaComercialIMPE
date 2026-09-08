<?php

require_once __DIR__ . '/FirmaCorreoService.php';

class CorreoSalidaInstitucionalService
{
    private const HOSTINGER_BASE_DEFAULT = 'https://api.mail.hostinger.com';
    private const FIRMA_CID = 'firma-correo-usuario';

    private $rootPath;
    private $firmaService;

    public function __construct()
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->firmaService = new FirmaCorreoService();
    }

    public function enviar($datos)
    {
        $remitente = strtolower(trim((string)($datos['remitente'] ?? '')));
        $nombreRemitente = trim((string)($datos['nombre_remitente'] ?? ''));
        $destinatario = trim((string)($datos['destinatario'] ?? ''));
        $nombreDestinatario = trim((string)($datos['nombre_destinatario'] ?? ''));
        $asunto = trim((string)($datos['asunto'] ?? ''));
        $cuerpo = trim((string)($datos['cuerpo'] ?? ''));

        if ($remitente === '' || !filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Tu cuenta no tiene un correo válido para realizar el envío.', 422);
        }
        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return $this->error('El destinatario no tiene un correo válido.', 422);
        }
        if ($asunto === '' || $cuerpo === '') {
            return $this->error('El asunto y el mensaje son obligatorios.', 422);
        }
        if (mb_strlen($asunto) > 255 || mb_strlen($cuerpo) > 20000) {
            return $this->error('El asunto o el mensaje supera el tamaño permitido.', 422);
        }

        $rutaFirma = $this->firmaService->obtenerRuta($remitente);

        $hostinger = $this->cargarHostinger();
        if ($hostinger['token'] !== '') {
            return $this->enviarHostinger(
                $remitente,
                $nombreRemitente,
                $destinatario,
                $asunto,
                $cuerpo,
                $rutaFirma,
                $hostinger
            );
        }

        return $this->enviarSmtp(
            $remitente,
            $nombreRemitente,
            $destinatario,
            $nombreDestinatario,
            $asunto,
            $cuerpo,
            $rutaFirma
        );
    }

    private function enviarHostinger($remitente, $nombreRemitente, $destinatario, $asunto, $cuerpo, $rutaFirma, $config)
    {
        if (!function_exists('curl_init')) {
            return $this->error('La extensión cURL de PHP es necesaria para enviar el correo.', 500);
        }

        $mailboxes = $this->solicitarHostinger(
            'GET',
            rtrim($config['base_url'], '/') . '/api/v1/me',
            $config['token'],
            null
        );

        if (!($mailboxes['ok'] ?? false)) {
            return $this->error(
                'No fue posible consultar los buzones autorizados para el envío.',
                (int)($mailboxes['codigo_http'] ?? 502)
            );
        }

        $json = is_array($mailboxes['json'] ?? null) ? $mailboxes['json'] : [];
        $lista = $json['data']['mailboxes'] ?? $json['mailboxes'] ?? [];
        $resourceId = '';

        if (is_array($lista)) {
            foreach ($lista as $mailbox) {
                if (!is_array($mailbox)) {
                    continue;
                }
                $address = strtolower(trim((string)($mailbox['address'] ?? '')));
                $id = trim((string)($mailbox['resource_id'] ?? $mailbox['resourceId'] ?? ''));
                if ($address === $remitente && $id !== '') {
                    $resourceId = $id;
                    break;
                }
            }
        }

        if ($resourceId === '') {
            return $this->error(
                'Tu correo no corresponde a un buzón autorizado para enviar desde el sistema.',
                422
            );
        }

        $firmaDisponible = $rutaFirma !== '' && is_file($rutaFirma);
        $payload = [
            'to' => [$destinatario],
            'subject' => $asunto,
            'text' => $cuerpo,
            'html' => $this->construirHtml($cuerpo, $firmaDisponible)
        ];

        if ($nombreRemitente !== '') {
            $payload['displayName'] = $nombreRemitente;
        }

        if ($firmaDisponible) {
            $contenidoFirma = file_get_contents($rutaFirma);
            if ($contenidoFirma !== false) {
                $payload['attachments'] = [[
                    'filename' => basename($rutaFirma),
                    'content' => base64_encode($contenidoFirma),
                    'contentType' => $this->firmaService->detectarMime($rutaFirma) ?: 'image/png',
                    'cid' => self::FIRMA_CID,
                    'encoding' => 'base64'
                ]];
            } else {
                $firmaDisponible = false;
                $payload['html'] = $this->construirHtml($cuerpo, false);
            }
        }

        $respuesta = $this->solicitarHostinger(
            'POST',
            rtrim($config['base_url'], '/') . '/api/v1/mailboxes/' . rawurlencode($resourceId) . '/send',
            $config['token'],
            $payload
        );

        if (!($respuesta['ok'] ?? false)) {
            return $this->error(
                'No fue posible enviar el correo institucional.',
                (int)($respuesta['codigo_http'] ?? 502)
            );
        }

        return [
            'ok' => true,
            'proveedor' => 'HOSTINGER_MAIL_API',
            'firma_incluida' => $firmaDisponible
        ];
    }

    private function enviarSmtp($remitenteUsuario, $nombreRemitente, $destinatario, $nombreDestinatario, $asunto, $cuerpo, $rutaFirma)
    {
        $autoload = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            return $this->error(
                'Las dependencias de correo no están instaladas. Ejecuta composer install antes de enviar.',
                500
            );
        }
        require_once $autoload;

        $archivoConfig = $this->rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'mail_config.php';
        if (is_file($archivoConfig)) {
            require_once $archivoConfig;
        }

        $host = $this->config('MAIL_HOST');
        $puerto = (int)$this->config('MAIL_PORT', '587');
        $usuario = $this->config('MAIL_USERNAME');
        $password = $this->config('MAIL_PASSWORD');
        $encriptacion = strtolower($this->config('MAIL_ENCRYPTION', 'tls'));
        $from = $this->config('MAIL_FROM_ADDRESS', $usuario);
        $fromName = $this->config('MAIL_FROM_NAME', $nombreRemitente !== '' ? $nombreRemitente : 'Fundación Red Educativa México');

        if ($host === '' || $puerto <= 0 || $from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $this->error('La configuración SMTP institucional está incompleta.', 500);
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $puerto;
            $mail->SMTPAuth = $usuario !== '';
            if ($usuario !== '') {
                $mail->Username = $usuario;
                $mail->Password = $password;
            }
            if ($encriptacion !== '' && $encriptacion !== 'none') {
                $mail->SMTPSecure = $encriptacion;
            }

            $mail->setFrom($from, $fromName);
            $mail->addAddress($destinatario, $nombreDestinatario);
            $mail->addReplyTo(
                $remitenteUsuario,
                $nombreRemitente !== '' ? $nombreRemitente : $remitenteUsuario
            );

            $firmaDisponible = $rutaFirma !== '' && is_file($rutaFirma);
            if ($firmaDisponible) {
                $mail->addEmbeddedImage(
                    $rutaFirma,
                    self::FIRMA_CID,
                    basename($rutaFirma),
                    'base64',
                    $this->firmaService->detectarMime($rutaFirma) ?: 'image/png'
                );
            }

            $mail->Subject = $asunto;
            $mail->isHTML(true);
            $mail->Body = $this->construirHtml($cuerpo, $firmaDisponible);
            $mail->AltBody = $cuerpo;
            $mail->send();

            return [
                'ok' => true,
                'proveedor' => 'SMTP',
                'firma_incluida' => $firmaDisponible
            ];
        } catch (Throwable $error) {
            error_log('Correo institucional SMTP: ' . $error->getMessage());
            return $this->error('No fue posible enviar el correo institucional.', 502);
        }
    }

    private function construirHtml($cuerpo, $firmaDisponible)
    {
        $texto = htmlspecialchars((string)$cuerpo, ENT_QUOTES, 'UTF-8');
        $html = '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:0;background:#ffffff;">';
        $html .= '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.65;color:#222222;max-width:760px;">';
        $html .= nl2br($texto, false);

        if ($firmaDisponible) {
            $html .= '<div style="margin-top:18px;">';
            $html .= '<img src="cid:' . self::FIRMA_CID . '" alt="Firma institucional" style="display:block;width:100%;max-width:720px;height:auto;border:0;">';
            $html .= '</div>';
        }

        $html .= '</div></body></html>';
        return $html;
    }

    private function solicitarHostinger($metodo, $url, $token, $payload)
    {
        $curl = curl_init($url);
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ];

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper((string)$metodo));
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        if (is_array($payload)) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                curl_close($curl);
                return [
                    'ok' => false,
                    'codigo_http' => 500
                ];
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $respuesta = curl_exec($curl);
        $codigo = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($respuesta === false || $error !== '') {
            error_log('Hostinger Mail API: ' . $error);
            return ['ok' => false, 'codigo_http' => 502];
        }

        $jsonRespuesta = json_decode((string)$respuesta, true);
        $ok = $codigo >= 200 && $codigo < 300;

        if (!$ok) {
            error_log('Hostinger Mail API HTTP ' . $codigo . ': ' . (string)$respuesta);
        }

        return [
            'ok' => $ok,
            'codigo_http' => $codigo > 0 ? $codigo : 502,
            'json' => is_array($jsonRespuesta) ? $jsonRespuesta : []
        ];
    }

    private function cargarHostinger()
    {
        $archivo = $this->rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'hostinger_mail_config.php';
        if (is_file($archivo)) {
            require_once $archivo;
        }

        return [
            'token' => $this->config('HOSTINGER_MAIL_API_TOKEN'),
            'base_url' => rtrim(
                $this->config('HOSTINGER_MAIL_API_BASE_URL', self::HOSTINGER_BASE_DEFAULT),
                '/'
            )
        ];
    }

    private function config($clave, $default = '')
    {
        if (defined($clave)) {
            return trim((string)constant($clave));
        }

        $valor = getenv($clave);
        return $valor !== false ? trim((string)$valor) : trim((string)$default);
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
