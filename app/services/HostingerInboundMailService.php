<?php

require_once __DIR__ . '/../../config/db_connection.php';

class HostingerInboundMailService
{
    private $connection;
    private $webhookSecret;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();

        $archivoConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'config' . DIRECTORY_SEPARATOR . 'hostinger_mail_config.php';

        if (is_file($archivoConfig)) {
            require_once $archivoConfig;
        }

        $this->webhookSecret = $this->config('HOSTINGER_MAIL_WEBHOOK_SECRET');
    }

    public function tablaDisponible()
    {
        return $this->tablaExiste('correo_respuestas_entrantes') &&
            $this->tablaExiste('correo_respuestas_destinatarios');
    }

    public function procesarWebhook($authorization, $rawBody)
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            return $this->error('Método no permitido.', 405);
        }

        if (!$this->tablaDisponible()) {
            return $this->error('Falta aplicar la migración de respuestas de correo.', 503);
        }

        if ($this->webhookSecret === '') {
            return $this->error('El webhook de Hostinger todavía no está configurado.', 503);
        }

        $token = $this->extraerBearer($authorization);
        if ($token === '' || !hash_equals($this->webhookSecret, $token)) {
            return $this->error('Webhook no autorizado.', 401);
        }

        $payload = json_decode((string)$rawBody, true);
        if (!is_array($payload)) {
            return $this->error('El cuerpo del webhook no contiene JSON válido.', 400);
        }

        $evento = trim((string)($payload['event'] ?? ''));
        if ($evento !== 'message.received') {
            return [
                'ok' => true,
                'codigo_http' => 200,
                'ignorado' => true,
                'mensaje' => 'Evento ignorado.'
            ];
        }

        $message = is_array($payload['message'] ?? null) ? $payload['message'] : [];
        $remitente = $this->normalizarCorreo($message['from'] ?? $payload['from'] ?? '');
        if ($remitente === '') {
            return $this->error('El webhook no incluye un remitente válido.', 422);
        }

        $mailbox = $this->normalizarMailbox($payload['mailbox'] ?? '');
        $asunto = $this->limpiarTexto($message['subject'] ?? $payload['subject'] ?? '', 500);
        $vistaPrevia = $this->extraerVistaPrevia($message, $payload);
        $mensajeExternoId = $this->limpiarTexto(
            $message['id'] ?? $message['message_id'] ?? $message['messageId'] ?? '',
            191
        );
        $threadId = $this->limpiarTexto(
            $message['thread_id'] ?? $message['threadId'] ?? '',
            191
        );
        $recibidoAt = $this->normalizarFecha(
            $message['received_at'] ??
            $message['timestamp'] ??
            $payload['received_at'] ??
            $payload['timestamp'] ??
            $payload['created_at'] ??
            ''
        );

        $rawNormalizado = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($rawNormalizado === false) {
            $rawNormalizado = '{}';
        }

        $eventoHash = hash('sha256', implode('|', [
            $evento,
            strtolower($mailbox),
            $mensajeExternoId,
            $threadId,
            strtolower($remitente),
            $asunto,
            $recibidoAt,
            $vistaPrevia
        ]));

        $existente = $this->buscarPorHash($eventoHash);
        if ($existente) {
            return [
                'ok' => true,
                'codigo_http' => 200,
                'duplicado' => true,
                'respuesta_id' => (int)$existente['id'],
                'mensaje' => 'Evento recibido previamente.'
            ];
        }

        $coincidencia = $this->buscarSeguimiento($remitente, $asunto);
        $seguimientoId = $coincidencia ? (int)$coincidencia['seguimiento_id'] : null;
        $reunionId = $coincidencia && (int)($coincidencia['reunion_id'] ?? 0) > 0
            ? (int)$coincidencia['reunion_id']
            : null;
        $contexto = $coincidencia
            ? (string)($coincidencia['contexto'] ?? 'OFERTA')
            : 'SIN_VINCULAR';

        $sql = "INSERT INTO correo_respuestas_entrantes (
                    evento_hash,
                    proveedor,
                    evento,
                    mensaje_externo_id,
                    thread_id,
                    mailbox,
                    remitente,
                    asunto,
                    vista_previa,
                    recibido_at,
                    seguimiento_id,
                    reunion_id,
                    contexto,
                    estado,
                    raw_payload
                ) VALUES (?, 'HOSTINGER_MAIL_API', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?)";
        $stmt = $this->connection->prepare($sql);
        $tipos = 'sssssssssii' . 'ss';
        $stmt->bind_param(
            $tipos,
            $eventoHash,
            $evento,
            $mensajeExternoId,
            $threadId,
            $mailbox,
            $remitente,
            $asunto,
            $vistaPrevia,
            $recibidoAt,
            $seguimientoId,
            $reunionId,
            $contexto,
            $rawNormalizado
        );
        $stmt->execute();
        $respuestaId = (int)$stmt->insert_id;

        if ($coincidencia) {
            $this->crearDestinatarios($respuestaId, $coincidencia);
        }

        return [
            'ok' => true,
            'codigo_http' => 200,
            'respuesta_id' => $respuestaId,
            'vinculado' => $seguimientoId !== null,
            'seguimiento_id' => $seguimientoId,
            'contexto' => $contexto,
            'mensaje' => $seguimientoId !== null
                ? 'Respuesta recibida y vinculada al seguimiento.'
                : 'Respuesta recibida sin coincidencia automática.'
        ];
    }

    public function obtenerParaUsuario($respuestaId, $usuarioId, $rolId)
    {
        if (!$this->tablaDisponible()) {
            return null;
        }

        $joinPost = $this->tablaExiste('seguimientos_vinculacion_post_envio')
            ? "LEFT JOIN seguimientos_vinculacion_post_envio post
                    ON post.seguimiento_id = correo.seguimiento_id"
            : '';
        $campoPost = $joinPost !== ''
            ? 'post.respuesta_at AS flujo_respuesta_at,'
            : 'NULL AS flujo_respuesta_at,';
        $joinReunion = $this->tablaExiste('reuniones_vinculacion')
            ? "LEFT JOIN reuniones_vinculacion reunion
                    ON reunion.id = correo.reunion_id"
            : '';
        $camposReunion = $joinReunion !== ''
            ? 'reunion.estado AS reunion_estado, reunion.fecha_propuesta AS reunion_fecha'
            : 'NULL AS reunion_estado, NULL AS reunion_fecha';

        $sql = "SELECT
                    correo.id,
                    correo.evento,
                    correo.mensaje_externo_id,
                    correo.thread_id,
                    correo.mailbox,
                    correo.remitente,
                    correo.asunto,
                    correo.vista_previa,
                    correo.recibido_at,
                    correo.seguimiento_id,
                    correo.reunion_id,
                    correo.contexto,
                    correo.estado,
                    correo.procesado_at,
                    destinatario.rol_id AS destinatario_rol_id,
                    destinatario.leido_at,
                    seguimiento.nombre_entidad,
                    seguimiento.analista_id,
                    {$campoPost}
                    {$camposReunion}
                FROM correo_respuestas_entrantes correo
                INNER JOIN correo_respuestas_destinatarios destinatario
                    ON destinatario.respuesta_id = correo.id
                    AND destinatario.usuario_id = ?
                LEFT JOIN seguimientos_vinculacion seguimiento
                    ON seguimiento.id = correo.seguimiento_id
                {$joinPost}
                {$joinReunion}
                WHERE correo.id = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $respuestaId = (int)$respuestaId;
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('ii', $usuarioId, $respuestaId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;

        if (!$fila) {
            return null;
        }

        $fila['puede_registrar_respuesta'] =
            (int)$rolId === 4 &&
            (int)($fila['analista_id'] ?? 0) === $usuarioId &&
            (string)($fila['contexto'] ?? '') !== 'REUNION' &&
            trim((string)($fila['flujo_respuesta_at'] ?? '')) === '' &&
            (string)($fila['estado'] ?? '') === 'PENDIENTE';

        return $fila;
    }

    public function marcarLeido($respuestaId, $usuarioId)
    {
        if (!$this->tablaDisponible()) {
            return false;
        }

        $sql = "UPDATE correo_respuestas_destinatarios
                SET leido_at = COALESCE(leido_at, NOW())
                WHERE respuesta_id = ? AND usuario_id = ?";
        $stmt = $this->connection->prepare($sql);
        $respuestaId = (int)$respuestaId;
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('ii', $respuestaId, $usuarioId);

        return $stmt->execute();
    }

    public function marcarProcesada($respuestaId, $usuarioId)
    {
        if (!$this->tablaDisponible()) {
            return false;
        }

        $this->connection->begin_transaction();
        try {
            $sql = "UPDATE correo_respuestas_entrantes
                    SET estado = 'REGISTRADA',
                        procesado_at = NOW(),
                        procesado_por = ?
                    WHERE id = ?";
            $stmt = $this->connection->prepare($sql);
            $respuestaId = (int)$respuestaId;
            $usuarioId = (int)$usuarioId;
            $stmt->bind_param('ii', $usuarioId, $respuestaId);
            $stmt->execute();

            $sqlDest = "UPDATE correo_respuestas_destinatarios
                        SET leido_at = COALESCE(leido_at, NOW())
                        WHERE respuesta_id = ?";
            $stmtDest = $this->connection->prepare($sqlDest);
            $stmtDest->bind_param('i', $respuestaId);
            $stmtDest->execute();

            $this->connection->commit();
            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('No fue posible marcar correo entrante como procesado: ' . $error->getMessage());
            return false;
        }
    }

    private function buscarSeguimiento($remitente, $asunto)
    {
        $correo = strtolower(trim((string)$remitente));
        $joinPost = $this->tablaExiste('seguimientos_vinculacion_post_envio')
            ? "LEFT JOIN seguimientos_vinculacion_post_envio post
                    ON post.seguimiento_id = seguimiento.id"
            : '';
        $campoRespuesta = $joinPost !== ''
            ? 'post.respuesta_at'
            : 'NULL AS respuesta_at';

        $sql = "SELECT
                    seguimiento.id AS seguimiento_id,
                    seguimiento.analista_id,
                    seguimiento.nombre_entidad,
                    seguimiento.correo_verificado,
                    seguimiento.correo_fuente,
                    {$campoRespuesta}
                FROM seguimientos_vinculacion seguimiento
                {$joinPost}
                WHERE seguimiento.activo = 1
                    AND seguimiento.estado_seguimiento <> 'DESCARTADO'
                    AND (
                        LOWER(TRIM(COALESCE(seguimiento.correo_verificado, ''))) = ?
                        OR LOWER(TRIM(COALESCE(seguimiento.correo_fuente, ''))) = ?
                    )
                ORDER BY seguimiento.id DESC
                LIMIT 10";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ss', $correo, $correo);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $candidatos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $fila['reunion'] = $this->obtenerUltimaReunion((int)$fila['seguimiento_id']);
            $fila['puntaje'] = $this->puntuarCoincidencia($fila, $correo, $asunto);
            $candidatos[] = $fila;
        }

        if (empty($candidatos)) {
            return null;
        }

        usort($candidatos, static function ($a, $b) {
            if ((int)$a['puntaje'] === (int)$b['puntaje']) {
                return (int)$b['seguimiento_id'] <=> (int)$a['seguimiento_id'];
            }
            return (int)$b['puntaje'] <=> (int)$a['puntaje'];
        });

        $mejor = $candidatos[0];
        $reunion = is_array($mejor['reunion'] ?? null) ? $mejor['reunion'] : [];
        $esReunion = trim((string)($reunion['correo_confirmacion_at'] ?? '')) !== '';

        return [
            'seguimiento_id' => (int)$mejor['seguimiento_id'],
            'analista_id' => (int)$mejor['analista_id'],
            'nombre_entidad' => (string)($mejor['nombre_entidad'] ?? ''),
            'reunion_id' => (int)($reunion['id'] ?? 0),
            'cuenta_clave_id' => (int)($reunion['cuenta_clave_id'] ?? 0),
            'contexto' => $esReunion ? 'REUNION' : 'OFERTA'
        ];
    }

    private function obtenerUltimaReunion($seguimientoId)
    {
        if (!$this->tablaExiste('reuniones_vinculacion')) {
            return null;
        }

        $sql = "SELECT
                    id,
                    cuenta_clave_id,
                    estado,
                    correo_confirmacion_asunto,
                    correo_confirmacion_at,
                    fecha_propuesta
                FROM reuniones_vinculacion
                WHERE seguimiento_id = ?
                ORDER BY id DESC
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function puntuarCoincidencia($fila, $correo, $asunto)
    {
        $puntaje = 0;
        $verificado = strtolower(trim((string)($fila['correo_verificado'] ?? '')));
        $fuente = strtolower(trim((string)($fila['correo_fuente'] ?? '')));

        if ($verificado !== '' && $verificado === $correo) {
            $puntaje += 60;
        }
        if ($fuente !== '' && $fuente === $correo) {
            $puntaje += 45;
        }
        if (trim((string)($fila['respuesta_at'] ?? '')) === '') {
            $puntaje += 10;
        }

        $asuntoNormalizado = $this->normalizarBusqueda($asunto);
        $entidadNormalizada = $this->normalizarBusqueda($fila['nombre_entidad'] ?? '');
        if (
            $asuntoNormalizado !== '' &&
            $entidadNormalizada !== '' &&
            (
                strpos($asuntoNormalizado, $entidadNormalizada) !== false ||
                strpos($entidadNormalizada, $asuntoNormalizado) !== false
            )
        ) {
            $puntaje += 25;
        }

        $reunion = is_array($fila['reunion'] ?? null) ? $fila['reunion'] : [];
        $asuntoReunion = $this->normalizarBusqueda($reunion['correo_confirmacion_asunto'] ?? '');
        if ($asuntoReunion !== '' && $asuntoNormalizado !== '') {
            if (
                strpos($asuntoNormalizado, $asuntoReunion) !== false ||
                strpos($asuntoReunion, $asuntoNormalizado) !== false
            ) {
                $puntaje += 35;
            }
        }

        return $puntaje;
    }

    private function crearDestinatarios($respuestaId, $coincidencia)
    {
        $destinatarios = [];
        $analistaId = (int)($coincidencia['analista_id'] ?? 0);
        $cuentaClaveId = (int)($coincidencia['cuenta_clave_id'] ?? 0);
        $contexto = (string)($coincidencia['contexto'] ?? 'OFERTA');

        if ($analistaId > 0) {
            $destinatarios[$analistaId] = 4;
        }
        if ($contexto === 'REUNION' && $cuentaClaveId > 0) {
            $destinatarios[$cuentaClaveId] = 6;
        }

        $sql = "INSERT IGNORE INTO correo_respuestas_destinatarios
                    (respuesta_id, usuario_id, rol_id)
                VALUES (?, ?, ?)";
        $stmt = $this->connection->prepare($sql);

        foreach ($destinatarios as $usuarioId => $rolId) {
            $respuesta = (int)$respuestaId;
            $usuario = (int)$usuarioId;
            $rol = (int)$rolId;
            $stmt->bind_param('iii', $respuesta, $usuario, $rol);
            $stmt->execute();
        }
    }

    private function buscarPorHash($eventoHash)
    {
        $sql = "SELECT id
                FROM correo_respuestas_entrantes
                WHERE evento_hash = ?
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('s', $eventoHash);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function extraerVistaPrevia($message, $payload)
    {
        $candidatos = [
            $message['preview'] ?? null,
            $message['snippet'] ?? null,
            $message['text'] ?? null,
            $message['body'] ?? null,
            $message['content'] ?? null,
            $payload['preview'] ?? null,
            $payload['snippet'] ?? null
        ];

        foreach ($candidatos as $valor) {
            if (is_array($valor)) {
                $valor = $valor['text'] ?? $valor['plain'] ?? '';
            }
            $texto = $this->limpiarTexto($valor, 8000);
            if ($texto !== '') {
                return $texto;
            }
        }

        return '';
    }

    private function normalizarCorreo($valor)
    {
        if (is_array($valor)) {
            if (isset($valor['email'])) {
                $valor = $valor['email'];
            } elseif (isset($valor['address'])) {
                $valor = $valor['address'];
            } elseif (isset($valor[0])) {
                return $this->normalizarCorreo($valor[0]);
            } else {
                return '';
            }
        }

        $valor = trim((string)$valor);
        if (preg_match('/<([^>]+)>/', $valor, $coincidencia)) {
            $valor = trim((string)$coincidencia[1]);
        }
        $valor = strtolower($valor);

        return filter_var($valor, FILTER_VALIDATE_EMAIL) ? $valor : '';
    }

    private function normalizarMailbox($valor)
    {
        if (is_array($valor)) {
            $valor = $valor['address'] ?? $valor['email'] ?? '';
        }
        return $this->limpiarTexto($valor, 255);
    }

    private function limpiarTexto($valor, $limite)
    {
        if (is_array($valor) || is_object($valor)) {
            $valor = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $texto = html_entity_decode(
            strip_tags((string)$valor),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $texto = preg_replace('/[\t ]+/u', ' ', $texto);
        $texto = preg_replace('/\R{3,}/u', "\n\n", $texto);
        $texto = trim((string)$texto);

        return mb_substr($texto, 0, (int)$limite, 'UTF-8');
    }

    private function normalizarFecha($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return date('Y-m-d H:i:s');
        }

        try {
            $fecha = new DateTime($valor);
            $fecha->setTimezone(new DateTimeZone(date_default_timezone_get()));
            return $fecha->format('Y-m-d H:i:s');
        } catch (Throwable $error) {
            return date('Y-m-d H:i:s');
        }
    }

    private function normalizarBusqueda($valor)
    {
        $valor = mb_strtolower(trim((string)$valor), 'UTF-8');
        $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if (is_string($transliterado)) {
            $valor = strtolower($transliterado);
        }
        $valor = preg_replace('/[^a-z0-9]+/', ' ', $valor);

        return trim((string)$valor);
    }

    private function extraerBearer($authorization)
    {
        $authorization = trim((string)$authorization);
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $coincidencia)) {
            return trim((string)$coincidencia[1]);
        }
        return '';
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
            'codigo_http' => (int)$codigoHttp,
            'mensaje' => (string)$mensaje
        ];
    }
}
