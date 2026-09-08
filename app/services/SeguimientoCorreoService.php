<?php

require_once __DIR__ . '/../../config/db_connection.php';

class SeguimientoCorreoService
{
    private const HOSTINGER_BASE_URL = 'https://api.mail.hostinger.com';

    private $connection;
    private $rootPath;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function obtenerBorrador($seguimientoId, $usuarioId)
    {
        $seguimiento = $this->obtenerSeguimientoAnalista(
            (int)$seguimientoId,
            (int)$usuarioId
        );

        $validacion = $this->validarEtapa($seguimiento);
        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        $totalEnviados = $this->contarCorreosSeguimiento((int)$seguimientoId);
        $institucion = trim((string)($seguimiento['nombre_entidad'] ?? ''));
        $contacto = trim((string)($seguimiento['contacto_nombre'] ?? ''));
        $analista = trim(
            (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
            (string)($seguimiento['analista_apellidos'] ?? '')
        );

        $saludo = $contacto !== ''
            ? 'Buen día, ' . $contacto . ':'
            : 'Buen día:';

        if ($totalEnviados > 0) {
            $lineas = [
                $saludo,
                '',
                'Dando seguimiento a nuestra conversación, quedamos atentos para continuar con la coordinación de la reunión.',
                '',
                'Si ya cuentan con una fecha y horario de preferencia, con gusto podemos revisarlo. También quedamos disponibles para resolver cualquier duda o compartir información adicional antes de la reunión.',
                '',
                'Quedo atento a sus comentarios.',
                '',
                'Saludos cordiales,'
            ];
        } else {
            $lineas = [
                $saludo,
                '',
                'Muchas gracias por su respuesta y por el interés mostrado en nuestra propuesta de vinculación educativa.',
                '',
                'Con gusto podemos continuar con la coordinación. Para avanzar, quedamos atentos a la fecha y horario que les resulte más conveniente para la reunión. También podemos revisar previamente cualquier duda o información adicional que requieran.',
                '',
                'Quedo atento a sus comentarios.',
                '',
                'Saludos cordiales,'
            ];
        }

        if ($analista !== '') {
            $lineas[] = $analista;
        }
        $lineas[] = 'Analista de Enlace Institucional';
        $lineas[] = 'Fundación Red Educativa México';

        return [
            'ok' => true,
            'correo' => [
                'para' => (string)($seguimiento['destinatario_correo'] ?? ''),
                'destinatario_nombre' => $contacto,
                'institucion' => $institucion,
                'asunto' => 'Seguimiento a propuesta de vinculación educativa' .
                    ($institucion !== '' ? ' - ' . $institucion : ''),
                'cuerpo' => implode("\n", $lineas),
                'total_enviados' => $totalEnviados
            ]
        ];
    }

    public function enviar($seguimientoId, $usuarioId, $asunto, $cuerpo)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $asunto = trim((string)$asunto);
        $cuerpo = trim((string)$cuerpo);
        $seguimiento = $this->obtenerSeguimientoAnalista($seguimientoId, $usuarioId);

        $validacion = $this->validarEtapa($seguimiento);
        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        if ($asunto === '' || $cuerpo === '') {
            return $this->error('El asunto y el mensaje son obligatorios.', 422);
        }
        if (mb_strlen($asunto) > 255) {
            return $this->error('El asunto no puede superar 255 caracteres.', 422);
        }
        if (mb_strlen($cuerpo) > 20000) {
            return $this->error('El mensaje es demasiado largo.', 422);
        }

        $resultadoEnvio = $this->enviarCorreo($seguimiento, $asunto, $cuerpo);
        if (!($resultadoEnvio['ok'] ?? false)) {
            return $resultadoEnvio;
        }

        $destinatario = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        $notasPost = 'Asunto: ' . $asunto . "\nMensaje: " . $cuerpo;
        $notasInteraccion = "Seguimiento por correo enviado\n" .
            'Para: ' . $destinatario . "\n" .
            'Asunto: ' . $asunto . "\n\n" .
            $cuerpo;

        $this->connection->begin_transaction();

        try {
            $this->asegurarPostEnvio($seguimientoId);

            $sqlPost = "UPDATE seguimientos_vinculacion_post_envio
                        SET seguimiento_correo_notas = ?,
                            seguimiento_correo_at = NOW(),
                            seguimiento_correo_por = ?
                        WHERE seguimiento_id = ?";
            $stmtPost = $this->connection->prepare($sqlPost);
            $stmtPost->bind_param('sii', $notasPost, $usuarioId, $seguimientoId);
            $stmtPost->execute();

            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                                seguimiento_id,
                                usuario_id,
                                canal,
                                fecha_inicio,
                                resultado,
                                notas
                            ) VALUES (?, ?, 'CORREO', NOW(), 'CORREO_ENVIADO', ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param('iis', $seguimientoId, $usuarioId, $notasInteraccion);
            $stmtInteraccion->execute();

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                               SET ultima_interaccion_at = NOW()
                               WHERE id = ? AND analista_id = ? AND activo = 1";
            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
            $stmtSeguimiento->execute();

            $this->connection->commit();
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('Correo de seguimiento enviado pero no registrado: ' . $error->getMessage());

            return $this->error(
                'El correo fue aceptado por el proveedor, pero no fue posible registrarlo en el expediente. Revisa el correo enviado antes de intentar nuevamente.',
                500
            );
        }

        return [
            'ok' => true,
            'mensaje' => 'Correo de seguimiento enviado y registrado correctamente.',
            'total_enviados' => $this->contarCorreosSeguimiento($seguimientoId)
        ];
    }

    public function habilitarAgenda($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $seguimiento = $this->obtenerSeguimientoAnalista($seguimientoId, $usuarioId);

        $validacion = $this->validarEtapa($seguimiento);
        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        $this->asegurarPostEnvio($seguimientoId);

        /*
         * La agenda existente usa seguimiento_correo_at como señal de que la
         * etapa posterior a la respuesta ya puede avanzar. Si no hubo correo
         * adicional, este momento representa la decisión explícita del Analista
         * de continuar a reunión; no se registra una interacción falsa.
         */
        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET seguimiento_correo_at = COALESCE(seguimiento_correo_at, NOW()),
                    seguimiento_correo_por = COALESCE(seguimiento_correo_por, ?)
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $usuarioId, $seguimientoId);

        if (!$stmt->execute()) {
            return $this->error('No fue posible habilitar la coordinación de reunión.', 500);
        }

        return [
            'ok' => true,
            'mensaje' => 'La coordinación de reunión está lista.',
            'url' => 'index.php?controller=agendaReunion&action=index&seguimiento_id=' . $seguimientoId
        ];
    }

    public function ajustarFlujo($seguimientoId, $usuarioId, $flujo)
    {
        if (!is_array($flujo)) {
            return $flujo;
        }

        $paso = (int)($flujo['paso_actual'] ?? 0);
        if (!in_array($paso, [10, 11], true)) {
            return $flujo;
        }

        $seguimiento = $this->obtenerEstadoEtapa((int)$seguimientoId, (int)$usuarioId);
        if (!$seguimiento || trim((string)($seguimiento['respuesta_at'] ?? '')) === '') {
            return $flujo;
        }

        if (trim((string)($seguimiento['reunion_agendada_at'] ?? '')) !== '') {
            return $flujo;
        }

        if ($this->existeReunionActiva((int)$seguimientoId, (int)$usuarioId)) {
            return $flujo;
        }

        $totalEnviados = $this->contarCorreosSeguimiento((int)$seguimientoId);
        $totalPasos = max(13, (int)($flujo['total_pasos'] ?? 13));

        $flujo['paso_actual'] = 10;
        $flujo['total_pasos'] = $totalPasos;
        $flujo['porcentaje'] = (int)round((10 / $totalPasos) * 100);
        $flujo['titulo'] = $totalEnviados > 0
            ? 'Continuar seguimiento por correo'
            : 'Coordinar por correo';
        $flujo['descripcion'] = $totalEnviados > 0
            ? 'Puedes enviar más correos si todavía necesitan acordar fecha, horario, modalidad o resolver dudas. Cuando todo esté definido, continúa a reunión.'
            : 'Puedes enviar uno o varios correos para acordar fecha, horario, modalidad o resolver dudas. Si la respuesta ya dejó todo definido, puedes continuar directamente a reunión.';
        $flujo['faltantes'] = [];
        $flujo['accion_principal'] = [
            'codigo' => 'ENVIAR_SEGUIMIENTO_CORREO',
            'etiqueta' => 'Enviar correo de seguimiento',
            'icono' => 'bi-envelope'
        ];
        $flujo['accion_secundaria'] = [
            'codigo' => 'CONTINUAR_REUNION',
            'etiqueta' => 'Continuar a reunión',
            'icono' => 'bi-calendar3'
        ];
        $flujo['ventana'] = [
            'anterior' => [
                'numero' => 9,
                'clave' => 'RESPUESTA',
                'titulo' => 'Respuesta recibida'
            ],
            'actual' => [
                'numero' => 10,
                'clave' => 'SEGUIMIENTO_CORREO',
                'titulo' => 'Seguimiento por correo'
            ],
            'siguiente' => [
                'numero' => 11,
                'clave' => 'REUNION_AGENDADA',
                'titulo' => 'Reunión agendada'
            ]
        ];
        if (!is_array($flujo['contexto'] ?? null)) {
            $flujo['contexto'] = [];
        }
        $flujo['contexto']['correos_seguimiento_enviados'] = $totalEnviados;

        return $flujo;
    }

    private function validarEtapa($seguimiento)
    {
        if (!$this->tablaExiste('seguimientos_vinculacion_post_envio')) {
            return $this->error('Falta aplicar la migración del flujo posterior al envío.', 500);
        }
        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }
        if (strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? ''))) === 'DESCARTADO') {
            return $this->error('Este seguimiento ya fue descartado.', 409);
        }
        if (trim((string)($seguimiento['respuesta_at'] ?? '')) === '') {
            return $this->error('Primero registra la respuesta de la institución.', 409);
        }
        if (trim((string)($seguimiento['reunion_agendada_at'] ?? '')) !== '') {
            return $this->error('La reunión ya fue formalmente agendada.', 409);
        }

        $correo = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return $this->error('El seguimiento no tiene un correo de contacto válido.', 422);
        }

        return ['ok' => true];
    }

    private function obtenerSeguimientoAnalista($seguimientoId, $usuarioId)
    {
        if (!$this->tablaExiste('seguimientos_vinculacion_post_envio')) {
            return null;
        }

        $sql = "SELECT
                    s.id,
                    s.analista_id,
                    s.nombre_entidad,
                    s.contacto_nombre,
                    s.contacto_cargo,
                    s.estado_seguimiento,
                    COALESCE(
                        NULLIF(TRIM(s.correo_verificado), ''),
                        NULLIF(TRIM(s.correo_fuente), '')
                    ) AS destinatario_correo,
                    p.respuesta_at,
                    p.respuesta_tipo,
                    p.respuesta_texto,
                    p.seguimiento_correo_at,
                    p.reunion_agendada_at,
                    u.nombre AS analista_nombre,
                    u.apellidos AS analista_apellidos,
                    u.correo AS analista_correo,
                    u.telefono AS analista_telefono
                FROM seguimientos_vinculacion s
                JOIN seguimientos_vinculacion_post_envio p
                    ON p.seguimiento_id = s.id
                JOIN usuarios u
                    ON u.id = s.analista_id
                WHERE s.id = ?
                    AND s.analista_id = ?
                    AND s.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function obtenerEstadoEtapa($seguimientoId, $usuarioId)
    {
        if (!$this->tablaExiste('seguimientos_vinculacion_post_envio')) {
            return null;
        }

        $sql = "SELECT p.respuesta_at, p.reunion_agendada_at
                FROM seguimientos_vinculacion s
                JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id = s.id
                WHERE s.id = ? AND s.analista_id = ? AND s.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function contarCorreosSeguimiento($seguimientoId)
    {
        $sql = "SELECT COUNT(*) AS total
                FROM interacciones_vinculacion
                WHERE seguimiento_id = ?
                    AND canal = 'CORREO'
                    AND resultado = 'CORREO_ENVIADO'
                    AND notas LIKE 'Seguimiento por correo enviado%'";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return (int)($fila['total'] ?? 0);
    }

    private function existeReunionActiva($seguimientoId, $analistaId)
    {
        if (!$this->tablaExiste('reuniones_vinculacion')) {
            return false;
        }

        $sql = "SELECT id
                FROM reuniones_vinculacion
                WHERE seguimiento_id = ?
                    AND analista_id = ?
                    AND estado <> 'CANCELADA'
                ORDER BY id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $analistaId);
        $stmt->execute();

        return (bool)$stmt->get_result()->fetch_assoc();
    }

    private function asegurarPostEnvio($seguimientoId)
    {
        $sql = "INSERT IGNORE INTO seguimientos_vinculacion_post_envio (seguimiento_id)
                VALUES (?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
    }

    private function enviarCorreo($seguimiento, $asunto, $cuerpo)
    {
        $configHostinger = $this->cargarConfiguracionHostinger();

        if ($configHostinger['token'] !== '') {
            $resultado = $this->enviarPorHostinger(
                $seguimiento,
                $asunto,
                $cuerpo,
                $configHostinger
            );

            if ($resultado['ok'] ?? false) {
                return $resultado;
            }

            /*
             * Si existe un token específico de Hostinger, mantenemos el mismo
             * criterio del envío de oferta y reportamos el error del proveedor;
             * no duplicamos el correo intentando otro canal después.
             */
            return $resultado;
        }

        return $this->enviarPorSmtp($seguimiento, $asunto, $cuerpo);
    }

    private function enviarPorHostinger($seguimiento, $asunto, $cuerpo, $config)
    {
        if (!function_exists('curl_init')) {
            return $this->error('La extensión cURL de PHP es necesaria para enviar el correo.', 500);
        }

        $analistaCorreo = strtolower(trim((string)($seguimiento['analista_correo'] ?? '')));
        if ($analistaCorreo === '' || !filter_var($analistaCorreo, FILTER_VALIDATE_EMAIL)) {
            return $this->error('El Analista no tiene un correo corporativo válido.', 422);
        }

        $me = $this->solicitarHostinger(
            $config['base_url'] . '/api/v1/me',
            $config['token'],
            'GET'
        );
        if (!($me['ok'] ?? false)) {
            return $me;
        }

        $mailboxes = $me['json']['data']['mailboxes'] ?? $me['json']['mailboxes'] ?? [];
        $resourceId = '';

        foreach (is_array($mailboxes) ? $mailboxes : [] as $mailbox) {
            $address = strtolower(trim((string)($mailbox['address'] ?? '')));
            if ($address === $analistaCorreo) {
                $resourceId = trim((string)(
                    $mailbox['resource_id'] ??
                    $mailbox['resourceId'] ??
                    ''
                ));
                break;
            }
        }

        if ($resourceId === '') {
            return $this->error('El correo del Analista no corresponde a un buzón autorizado en Hostinger.', 422);
        }

        $destinatario = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        $nombreAnalista = trim(
            (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
            (string)($seguimiento['analista_apellidos'] ?? '')
        );
        $html = '<!DOCTYPE html><html lang="es"><body>' .
            '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.65;color:#222;">' .
            nl2br(htmlspecialchars($cuerpo, ENT_QUOTES, 'UTF-8'), false) .
            '</div></body></html>';
        $payload = [
            'to' => [$destinatario],
            'subject' => $asunto,
            'text' => $cuerpo,
            'html' => $html
        ];

        if ($nombreAnalista !== '') {
            $payload['displayName'] = $nombreAnalista;
        }

        return $this->solicitarHostinger(
            $config['base_url'] . '/api/v1/mailboxes/' . rawurlencode($resourceId) . '/send',
            $config['token'],
            'POST',
            $payload
        );
    }

    private function solicitarHostinger($url, $token, $metodo, $payload = null)
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

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                curl_close($curl);
                return $this->error('No fue posible preparar el correo.', 500);
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        $respuesta = curl_exec($curl);
        $codigo = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $detalleCurl = curl_error($curl);
        curl_close($curl);

        if ($respuesta === false) {
            error_log('Hostinger seguimiento: ' . $detalleCurl);
            return $this->error('No fue posible comunicarse con Hostinger Mail API.', 502);
        }

        $jsonRespuesta = [];
        if (trim((string)$respuesta) !== '') {
            $decodificado = json_decode((string)$respuesta, true);
            if (is_array($decodificado)) {
                $jsonRespuesta = $decodificado;
            }
        }

        if ($codigo < 200 || $codigo >= 300) {
            $detalle = trim((string)(
                $jsonRespuesta['message'] ??
                $jsonRespuesta['error'] ??
                $respuesta
            ));
            if ($detalle !== '') {
                error_log('Hostinger seguimiento HTTP ' . $codigo . ': ' . $detalle);
            }
            return $this->error('No fue posible enviar el correo institucional mediante Hostinger.', $codigo > 0 ? $codigo : 502);
        }

        return [
            'ok' => true,
            'codigo_http' => $codigo,
            'json' => $jsonRespuesta
        ];
    }

    private function enviarPorSmtp($seguimiento, $asunto, $cuerpo)
    {
        $autoload = $this->rootPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            return $this->error('Las dependencias de correo no están instaladas. Ejecuta composer install antes de enviar.', 500);
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
        $remitente = $this->config('MAIL_FROM_ADDRESS', $usuario);
        $nombreRemitente = $this->config('MAIL_FROM_NAME', 'Fundación Red Educativa México');

        if ($host === '' || $puerto <= 0 || $remitente === '') {
            return $this->error('La configuración SMTP institucional está incompleta.', 500);
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $puerto;
            $mail->SMTPAuth = $usuario !== '';

            if ($mail->SMTPAuth) {
                $mail->Username = $usuario;
                $mail->Password = $password;
            }
            if ($encriptacion !== '' && $encriptacion !== 'none') {
                $mail->SMTPSecure = $encriptacion;
            }

            $mail->setFrom($remitente, $nombreRemitente);
            $mail->addAddress(
                (string)$seguimiento['destinatario_correo'],
                trim((string)($seguimiento['contacto_nombre'] ?? ''))
            );

            $analistaCorreo = trim((string)($seguimiento['analista_correo'] ?? ''));
            $analistaNombre = trim(
                (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
                (string)($seguimiento['analista_apellidos'] ?? '')
            );
            if ($analistaCorreo !== '' && filter_var($analistaCorreo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($analistaCorreo, $analistaNombre !== '' ? $analistaNombre : $analistaCorreo);
            }

            $mail->Subject = $asunto;
            $mail->isHTML(false);
            $mail->Body = $cuerpo;
            $mail->send();

            return ['ok' => true];
        } catch (Throwable $error) {
            error_log('SMTP seguimiento: ' . $error->getMessage());
            return $this->error('No fue posible enviar el correo institucional. Verifica la cuenta de correo e intenta nuevamente.', 502);
        }
    }

    private function cargarConfiguracionHostinger()
    {
        $archivo = $this->rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'hostinger_mail_config.php';
        if (is_file($archivo)) {
            require_once $archivo;
        }

        return [
            'token' => $this->config('HOSTINGER_MAIL_API_TOKEN'),
            'base_url' => rtrim(
                $this->config('HOSTINGER_MAIL_API_BASE_URL', self::HOSTINGER_BASE_URL),
                '/'
            )
        ];
    }

    private function tablaExiste($tabla)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', (string)$tabla);
        $resultado = $this->connection->query("SHOW TABLES LIKE '" . $tabla . "'");
        return $resultado && $resultado->num_rows > 0;
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

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
