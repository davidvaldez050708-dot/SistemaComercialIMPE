<?php

require_once __DIR__ . '/FirmaCorreoService.php';
require_once __DIR__ . '/CorreoCuentaConectadaService.php';
require_once __DIR__ . '/CorreoEnvioControl.php';

class CorreoSalidaInstitucionalService
{
    private const HOSTINGER_BASE_DEFAULT = 'https://api.mail.hostinger.com';
    private const FIRMA_CID = 'firma-correo-usuario';

    private $rootPath;
    private $firmaService;

    private $mailerFactory;
    private $cuentas;
    private $apiRequest;
    private $hostinger;

    public function __construct(?callable $mailerFactory = null, ?CorreoCuentaConectadaService $cuentas = null, ?callable $apiRequest = null)
    {
        $this->rootPath = dirname(__DIR__, 2);
        $this->mailerFactory = $mailerFactory;
        $this->cuentas = $cuentas ?? new CorreoCuentaConectadaService();
        $this->apiRequest = $apiRequest;
        $this->hostinger = new CorreoHostingerApiService($apiRequest);
        $this->cuentas->configurarConsultaApi([$this->hostinger, 'solicitar']);
        $this->firmaService = new FirmaCorreoService();
    }

    public function remitentes(int $user,string $from=''): array { return $this->cuentas->remitentes($user,$from); }

    public function prepararSalida(string $from,int $user): array
    {
        $r=$this->cuentas->resolverSalida($from,$user);
        return empty($r['ok'])?$r:['ok'=>true,'salida'=>$r['salida']];
    }

    private function notaIdentidad(array $salida): string
    {
        if($salida['modo']!=='central') { return ''; }
        $note="\n\n— Envío institucional —\nRemitente real: ".$salida['remitente_real']."\nLas respuestas a este mensaje se reciben en: ".$salida['respuestas_a'];
        if($salida['redactor_nombre']!=='') { $note.="\nRedactado por: ".$salida['redactor_nombre']; }
        if($salida['contacto_autorizado']!=='') { $note.="\nContacto institucional autorizado: ".$salida['contacto_autorizado']."\nPara contactar directamente a esa persona, escribe a su dirección institucional."; }
        return $note;
    }

    public function validarRemitente(string $from, int $user): array
    {
        $r=$this->cuentas->resolver($from,$user);
        return empty($r['ok']) ? $r : ['ok'=>true,'remitente'=>$from];
    }

    public function enviar($datos)
    {
        $remitente = strtolower(trim((string)($datos['remitente'] ?? '')));
        $usuarioId = (int)($datos['usuario_id'] ?? 0);
        $perfilMarketing = !empty($datos['perfil_marketing']);

        /*
         * Compatibilidad con módulos existentes: si el llamador todavía no
         * manda usuario_id, reutilizamos el usuario autenticado de la sesión.
         * Todos los envíos comerciales validan el remitente con la API central.
         */
        if ($usuarioId <= 0 && isset($_SESSION['usuario_id'])) {
            $usuarioId = (int)$_SESSION['usuario_id'];
        }
        $nombreRemitente = trim((string)($datos['nombre_remitente'] ?? ''));
        $destinatario = trim((string)($datos['destinatario'] ?? ''));
        $nombreDestinatario = trim((string)($datos['nombre_destinatario'] ?? ''));
        $asunto = trim((string)($datos['asunto'] ?? ''));
        $cuerpo = trim((string)($datos['cuerpo'] ?? ''));
        $htmlAdicional = trim((string)($datos['html_adicional'] ?? ''));
        $imagenesResultado = $this->normalizarImagenesEmbebidas(
            $datos['imagenes_embebidas'] ?? []
        );
        $adjuntosResultado = $this->normalizarAdjuntos(
            $datos['adjuntos'] ?? []
        );

        if (!($imagenesResultado['ok'] ?? false)) {
            return $imagenesResultado;
        }
        if (!($adjuntosResultado['ok'] ?? false)) {
            return $adjuntosResultado;
        }

        $imagenesEmbebidas = $imagenesResultado['imagenes'];
        $adjuntos = $adjuntosResultado['adjuntos'];

        if ($remitente === '' || !filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Tu cuenta no tiene un correo válido para realizar el envío.', 422);
        }
        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return $this->error('El destinatario no tiene un correo válido.', 422);
        }
        if ($asunto === '' || $cuerpo === '') {
            return $this->error('El asunto y el mensaje son obligatorios.', 422);
        }
        if (
            mb_strlen($asunto) > 255 ||
            mb_strlen($cuerpo) > 20000 ||
            mb_strlen($htmlAdicional) > 20000
        ) {
            return $this->error('El asunto o el mensaje supera el tamaño permitido.', 422);
        }

        try {
            $connection = $this->cuentas->resolverSalida($remitente, $usuarioId);
        } catch (Throwable $error) {
            error_log('MAIL_TRANSPORT account_configuration_failed exception=' . get_class($error));
            return $this->error('No fue posible consultar las cuentas autorizadas. Solicita revisar la configuración privada.', 503);
        }
        if (empty($connection['ok'])) { return $connection; }
        $account = $connection['account'];
        $salida=$connection['salida'];$real=$salida['remitente_real'];
        $confirmed=trim((string)($datos['remitente_real_confirmado']??''));
        if($perfilMarketing && $salida['modo']==='central' && $confirmed==='') { return $this->error('Revisa y confirma en el formulario el buzón central que utilizará este correo.',409); }
        if($confirmed!=='' && !hash_equals($real,strtolower($confirmed))) { return $this->error('El buzón de salida cambió. Revisa el remitente real antes de volver a enviar.',409); }
        $nota=$this->notaIdentidad($salida);
        $cuerpo.=$nota;
        if($salida['modo']==='central') { $nombreRemitente=$salida['redactor_nombre']!==''?$salida['redactor_nombre']:'Fundación Red Educativa México'; }
        $rutaFirma = $this->firmaService->obtenerRuta($salida['contacto_autorizado']?:$real);
        $send = function () use ($account, $remitente, $nombreRemitente, $destinatario, $nombreDestinatario,
            $asunto, $cuerpo, $rutaFirma, $imagenesEmbebidas, $adjuntos, $htmlAdicional, $real, $salida, $nota) {
            if ($account['transport'] === 'hostinger_api') {
                $base = rtrim((string)($account['api_base_url'] ?? self::HOSTINGER_BASE_DEFAULT), '/');
                if ($base !== self::HOSTINGER_BASE_DEFAULT || empty($account['api_token'])) {
                    return $this->error('Falta la configuración privada de la API Hostinger para esta cuenta.', 422);
                }
                return $this->enviarHostinger($real, $nombreRemitente, $destinatario, $asunto, $cuerpo, $rutaFirma,
                    ['token' => $account['api_token'], 'base_url' => $base], $imagenesEmbebidas, $adjuntos, $htmlAdicional, true, $real)
                    + ['identidad_envio'=>$salida,'nota_identidad'=>$nota];
            }
            return $this->error('El envío comercial sólo admite Hostinger Mail API.', 422);
        };
        $attachments = array_map(static function ($a) { return [$a['nombre'], hash_file('sha256', $a['ruta'])]; }, array_merge($adjuntos, $imagenesEmbebidas));
        $fingerprint = hash('sha256', json_encode([$usuarioId, $remitente, $real, $destinatario, $asunto, $cuerpo, $htmlAdicional, $attachments,
            is_file($rutaFirma) ? hash_file('sha256', $rutaFirma) : '']));
        $explicit = trim((string)($datos['idempotency_key'] ?? ''));
        if ($explicit !== '' && !preg_match('/^[a-zA-Z0-9:_.-]{8,180}$/D', $explicit)) { return $this->error('La solicitud de envío no es válida.', 422); }
        try {
            return CorreoEnvioControl::ejecutar($usuarioId . ':' . ($explicit ?: $fingerprint), $fingerprint, $send, $explicit !== '');
        } catch (Throwable $error) {
            error_log('MAIL_TRANSPORT ' . json_encode(['phase' => 'account_or_state', 'exception' => get_class($error)]));
            return $this->error('No se pudo confirmar un envío seguro. Revisa el proveedor y el historial antes de repetirlo.', 503)
                + ['estado_entrega' => 'DESCONOCIDO', 'entrega_confirmada' => false];
        }
    }

    private function enviarHostinger(
        $remitente,
        $nombreRemitente,
        $destinatario,
        $asunto,
        $cuerpo,
        $rutaFirma,
        $config,
        $imagenesEmbebidas,
        $adjuntos,
        $htmlAdicional,
        $usarRemitenteExacto = false,
        $direccionPreferidaOverride = ''
    )
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

        $json = is_array($mailboxes['json'] ?? null)
            ? $mailboxes['json']
            : [];
        $lista = $json['data']['mailboxes'] ?? $json['mailboxes'] ?? [];
        $resourceId = '';
        $direccionSalida = '';

        /*
         * El correo del usuario NO tiene que pertenecer a Hostinger.
         * Buscamos primero una cuenta de salida configurada y, si no existe,
         * utilizamos el primer buzón autorizado disponible en la API.
         */
        $direccionPreferida = strtolower(
            trim((string)$direccionPreferidaOverride)
        );

        if ($direccionPreferida === '') {
            $direccionPreferida = strtolower(
                trim((string)$this->config('HOSTINGER_MAIL_FROM_ADDRESS'))
            );
        }

        if (is_array($lista)) {
            foreach ($lista as $mailbox) {
                if (!is_array($mailbox)) {
                    continue;
                }

                $address = strtolower(
                    trim((string)($mailbox['address'] ?? ''))
                );
                $id = trim((string)(
                    $mailbox['resource_id'] ??
                    $mailbox['resourceId'] ??
                    ''
                ));

                if ($id === '' || $address === '') {
                    continue;
                }

                if ($address === $remitente) {
                    $resourceId = $id;
                    $direccionSalida = $address;
                    break;
                }

                if (!$usarRemitenteExacto && $resourceId === '') {
                    $resourceId = $id;
                    $direccionSalida = $address;
                }

                if (
                    !$usarRemitenteExacto &&
                    $direccionPreferida !== '' &&
                    $address === $direccionPreferida
                ) {
                    $resourceId = $id;
                    $direccionSalida = $address;
                    break;
                }
            }
        }

        if ($resourceId === '') {
            return [
                'ok' => false,
                'mensaje' => $usarRemitenteExacto
                    ? 'El correo de origen no está autorizado como cuenta de salida. Usa una cuenta configurada en el sistema o un buzón autorizado de Hostinger.'
                    : 'No hay ningún buzón de salida autorizado disponible.'
            ];
        }

        $firmaDisponible = $rutaFirma !== '' && is_file($rutaFirma);
        $payload = [
            'to' => [$destinatario],
            'subject' => $asunto,
            'text' => $cuerpo,
            'html' => $this->construirHtml(
                $cuerpo,
                $firmaDisponible,
                $htmlAdicional
            )
        ];
        $attachments = [];

        if ($nombreRemitente !== '') {
            $payload['displayName'] = $nombreRemitente;
        }

        if ($firmaDisponible) {
            $contenidoFirma = file_get_contents($rutaFirma);
            if ($contenidoFirma !== false) {
                $attachments[] = [
                    'filename' => basename($rutaFirma),
                    'content' => base64_encode($contenidoFirma),
                    'contentType' => $this->firmaService->detectarMime($rutaFirma) ?: 'image/png',
                    'cid' => self::FIRMA_CID,
                    'encoding' => 'base64'
                ];
            } else {
                $firmaDisponible = false;
                $payload['html'] = $this->construirHtml(
                    $cuerpo,
                    false,
                    $htmlAdicional
                );
            }
        }

        foreach ($imagenesEmbebidas as $imagen) {
            $contenido = file_get_contents($imagen['ruta']);
            if ($contenido === false) {
                return $this->error(
                    'No fue posible leer una imagen que debe incluirse en el correo.',
                    500
                );
            }

            $attachments[] = [
                'filename' => $imagen['nombre'],
                'content' => base64_encode($contenido),
                'contentType' => $imagen['mime'],
                'cid' => $imagen['cid'],
                'encoding' => 'base64'
            ];
        }

        foreach ($adjuntos as $adjunto) {
            $contenido = file_get_contents($adjunto['ruta']);
            if ($contenido === false) {
                return $this->error(
                    'No fue posible leer un archivo adjunto del correo.',
                    500
                );
            }

            $attachments[] = [
                'filename' => $adjunto['nombre'],
                'content' => base64_encode($contenido),
                'contentType' => $adjunto['mime'],
                'encoding' => 'base64'
            ];
        }

        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        $respuesta = $this->solicitarHostinger(
            'POST',
            rtrim($config['base_url'], '/') . '/api/v1/mailboxes/' . rawurlencode($resourceId) . '/send',
            $config['token'],
            $payload
        );

        if (!($respuesta['ok'] ?? false) || (int)($respuesta['codigo_http'] ?? 0) !== 204) {
            $code = (int)($respuesta['codigo_http'] ?? 502);
            $unknown = !empty($respuesta['incierto']) || $code >= 500 || $code < 400 || $code === 408;
            return ['ok' => false, 'codigo_http' => 502, 'proveedor' => 'HOSTINGER_MAIL_API',
                'estado_entrega' => $unknown ? 'DESCONOCIDO' : 'RECHAZADO', 'entrega_confirmada' => false,
                'mensaje' => $unknown
                    ? 'La API no confirmó el resultado. Comprueba el buzón y el historial antes de repetir el envío.'
                    : 'Hostinger rechazó el envío. Revisa la autorización del buzón y la configuración de la API.'];
        }

        return [
            'ok' => true,
            'proveedor' => 'HOSTINGER_MAIL_API',
            'estado_entrega' => 'ACEPTADO',
            'entrega_confirmada' => false,
            'cuenta_salida' => $direccionSalida,
            'correo_usuario' => $remitente,
            'firma_incluida' => $firmaDisponible,
            'imagenes_embebidas' => count($imagenesEmbebidas),
            'adjuntos' => count($adjuntos)
        ];
    }

    private function enviarSmtp(
        $remitenteUsuario,
        $nombreRemitente,
        $destinatario,
        $nombreDestinatario,
        $asunto,
        $cuerpo,
        $rutaFirma,
        $imagenesEmbebidas,
        $adjuntos,
        $htmlAdicional,
        $configUsuario = null
    )
    {
        $autoload = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            return $this->error(
                'Las dependencias de correo no están instaladas. Ejecuta composer install antes de enviar.',
                500
            );
        }
        require_once $autoload;

        try {
            $configUsuario = is_array($configUsuario) ? $configUsuario : [];
            $mail = $this->mailerFactory ? ($this->mailerFactory)() : new \PHPMailer\PHPMailer\PHPMailer(true);
            CorreoSmtpTransport::configurar($mail, $configUsuario);
            $mail->setFrom($remitenteUsuario, $nombreRemitente !== '' ? $nombreRemitente : $remitenteUsuario);
            $mail->addAddress($destinatario, $nombreDestinatario);
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

            foreach ($imagenesEmbebidas as $imagen) {
                $mail->addEmbeddedImage(
                    $imagen['ruta'],
                    $imagen['cid'],
                    $imagen['nombre'],
                    'base64',
                    $imagen['mime']
                );
            }

            foreach ($adjuntos as $adjunto) {
                $mail->addAttachment(
                    $adjunto['ruta'],
                    $adjunto['nombre'],
                    'base64',
                    $adjunto['mime']
                );
            }

            $mail->Subject = $asunto;
            $mail->isHTML(true);
            $mail->Body = $this->construirHtml(
                $cuerpo,
                $firmaDisponible,
                $htmlAdicional
            );
            $mail->AltBody = $cuerpo;
            $outcome = CorreoSmtpTransport::enviar($mail, 'commercial');
            if (empty($outcome['ok'])) { return $outcome; }
            return $outcome + [
                'firma_incluida' => $firmaDisponible,
                'imagenes_embebidas' => count($imagenesEmbebidas),
                'adjuntos' => count($adjuntos)
            ];
        } catch (Throwable $error) {
            error_log('MAIL_TRANSPORT ' . json_encode(['phase' => 'smtp_configuration', 'exception' => get_class($error)]));
            return $this->error('No fue posible enviar el correo institucional.', 502);
        }
    }

    private function construirHtml($cuerpo, $firmaDisponible, $htmlAdicional = '')
    {
        $texto = htmlspecialchars((string)$cuerpo, ENT_QUOTES, 'UTF-8');
        $html = '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:0;background:#ffffff;">';
        $html .= '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.65;color:#222222;max-width:760px;">';
        $html .= nl2br($texto, false);

        if (trim((string)$htmlAdicional) !== '') {
            $html .= (string)$htmlAdicional;
        }

        if ($firmaDisponible) {
            $html .= '<div style="margin-top:18px;">';
            $html .= '<img src="cid:' . self::FIRMA_CID . '" alt="Firma institucional" width="480" style="display:block;width:100%;max-width:480px;height:auto;border:0;">';
            $html .= '</div>';
        }

        $html .= '</div></body></html>';
        return $html;
    }

    private function normalizarImagenesEmbebidas($imagenes)
    {
        if ($imagenes === null || $imagenes === '') {
            $imagenes = [];
        }

        if (!is_array($imagenes)) {
            return $this->error('Las imágenes del correo no tienen un formato válido.', 422);
        }

        $salida = [];
        $rootReal = realpath($this->rootPath);

        foreach ($imagenes as $imagen) {
            if (!is_array($imagen)) {
                return $this->error('Una imagen del correo no tiene un formato válido.', 422);
            }

            $ruta = trim((string)($imagen['ruta'] ?? ''));
            $cid = trim((string)($imagen['cid'] ?? ''));
            $nombre = basename(trim((string)($imagen['nombre'] ?? 'imagen.jpg')));
            $mime = trim((string)($imagen['mime'] ?? ''));

            if ($ruta === '' || $cid === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $cid)) {
                return $this->error('Faltan datos de una imagen que debe incluirse en el correo.', 422);
            }

            $real = realpath($ruta);
            if (
                $real === false ||
                !is_file($real) ||
                ($rootReal !== false && strpos($real, $rootReal) !== 0)
            ) {
                return $this->error('Una imagen del correo no está disponible.', 422);
            }

            if ($mime === '' && function_exists('mime_content_type')) {
                $detectado = mime_content_type($real);
                if (is_string($detectado)) {
                    $mime = trim($detectado);
                }
            }

            if (strpos($mime, 'image/') !== 0) {
                $mime = 'image/jpeg';
            }

            $salida[] = [
                'ruta' => $real,
                'cid' => $cid,
                'nombre' => $nombre !== '' ? $nombre : 'imagen.jpg',
                'mime' => $mime
            ];
        }

        return [
            'ok' => true,
            'imagenes' => $salida
        ];
    }

    private function normalizarAdjuntos($adjuntos)
    {
        if ($adjuntos === null || $adjuntos === '') {
            $adjuntos = [];
        }

        if (!is_array($adjuntos)) {
            return $this->error('Los archivos adjuntos no tienen un formato válido.', 422);
        }

        if (count($adjuntos) > 8) {
            return $this->error('El correo no puede incluir más de 8 archivos adjuntos.', 422);
        }

        $salida = [];
        $rootReal = realpath($this->rootPath);
        $tamanoTotal = 0;
        $mimesPermitidos = [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
            'text/csv',
            'image/png',
            'image/jpeg',
            'application/octet-stream'
        ];

        foreach ($adjuntos as $adjunto) {
            if (!is_array($adjunto)) {
                return $this->error('Un archivo adjunto no tiene un formato válido.', 422);
            }

            $ruta = trim((string)($adjunto['ruta'] ?? ''));
            $nombre = basename(trim((string)($adjunto['nombre'] ?? 'archivo')));
            $mime = trim((string)($adjunto['mime'] ?? ''));

            $real = $ruta !== '' ? realpath($ruta) : false;
            if (
                $real === false ||
                !is_file($real) ||
                ($rootReal !== false && strpos($real, $rootReal) !== 0)
            ) {
                return $this->error('Uno de los archivos adjuntos no está disponible.', 422);
            }

            $tamano = (int)filesize($real);
            if ($tamano <= 0 || $tamano > 12 * 1024 * 1024) {
                return $this->error('Uno de los archivos adjuntos supera el tamaño permitido.', 422);
            }
            $tamanoTotal += $tamano;
            if ($tamanoTotal > 20 * 1024 * 1024) {
                return $this->error('Los archivos adjuntos superan el tamaño total permitido.', 422);
            }

            if ($mime === '' && function_exists('mime_content_type')) {
                $detectado = mime_content_type($real);
                if (is_string($detectado)) {
                    $mime = trim($detectado);
                }
            }

            $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            $mimesPorExtension = [
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'txt' => 'text/plain',
                'csv' => 'text/csv',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg'
            ];

            if (isset($mimesPorExtension[$extension])) {
                $mime = $mimesPorExtension[$extension];
            }

            if (!in_array($mime, $mimesPermitidos, true)) {
                return $this->error('Uno de los archivos adjuntos tiene un tipo no permitido.', 422);
            }

            $salida[] = [
                'ruta' => $real,
                'nombre' => $nombre !== '' ? $nombre : 'archivo',
                'mime' => $mime
            ];
        }

        return [
            'ok' => true,
            'adjuntos' => $salida
        ];
    }

    private function solicitarHostinger($metodo, $url, $token, $payload)
    {
        return $this->hostinger->solicitar($metodo, $url, $token, $payload);
    }

    private function cargarHostinger()
    {
        /*
         * La configuración del servicio de correo es administrativa y privada.
         * Puede vivir fuera de public_html (impe-private) o en config/ para
         * instalaciones locales. Los usuarios finales no configuran SMTP.
         */
        $archivo = function_exists('impeRutaConfigPrivada')
            ? impeRutaConfigPrivada('hostinger_mail_config.php')
            : null;

        if ($archivo !== null && is_file($archivo)) {
            require_once $archivo;
        } else {
            $fallback = $this->rootPath .
                DIRECTORY_SEPARATOR . 'config' .
                DIRECTORY_SEPARATOR . 'hostinger_mail_config.php';

            if (is_file($fallback)) {
                require_once $fallback;
            }
        }

        return [
            'token' => $this->config('HOSTINGER_MAIL_API_TOKEN'),
            'base_url' => rtrim(
                $this->config(
                    'HOSTINGER_MAIL_API_BASE_URL',
                    self::HOSTINGER_BASE_DEFAULT
                ),
                '/'
            )
        ];
    }

    private function cargarConfigMarketing()
    {
        require_once $this->rootPath .
            DIRECTORY_SEPARATOR . 'config' .
            DIRECTORY_SEPARATOR . 'private_config.php';

        $datos = function_exists('impeLeerConfigPrivada')
            ? impeLeerConfigPrivada('marketing_mail.local.php')
            : [];

        return is_array($datos) ? $datos : [];
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
