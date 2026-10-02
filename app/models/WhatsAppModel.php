<?php

require_once __DIR__ . '/../../config/db_connection.php';

class WhatsAppModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function estructuraDisponible()
    {
        foreach (
            [
                'whatsapp_cuentas',
                'whatsapp_conversaciones',
                'whatsapp_mensajes',
                'whatsapp_webhook_eventos'
            ] as $tabla
        ) {
            if (!$this->tablaExiste($tabla)) {
                return false;
            }
        }

        return true;
    }

    public function obtenerCuentas($usuarioId, $gestionarTodas = false, $soloActivas = true)
    {
        if (!$this->estructuraDisponible()) {
            return [];
        }

        $sql = "SELECT
                    c.id,
                    c.nombre,
                    c.phone_number_id,
                    c.numero_mostrado,
                    c.usuario_id,
                    c.tipo,
                    c.es_predeterminada,
                    c.activo,
                    c.created_at,
                    c.updated_at,
                    CONCAT_WS(' ', u.nombre, u.apellidos) AS usuario_nombre
                FROM whatsapp_cuentas c
                LEFT JOIN usuarios u
                    ON u.id = c.usuario_id
                WHERE 1 = 1";

        $tipos = '';
        $parametros = [];

        if ($soloActivas) {
            $sql .= " AND c.activo = 1";
        }

        if (!$gestionarTodas) {
            $sql .= " AND (
                        c.usuario_id = ?
                        OR (
                            c.usuario_id IS NULL
                            AND c.es_predeterminada = 1
                        )
                    )";
            $tipos .= 'i';
            $parametros[] = (int)$usuarioId;
        }

        $sql .= " ORDER BY
                    c.activo DESC,
                    (c.usuario_id = ?) DESC,
                    c.es_predeterminada DESC,
                    c.nombre ASC";

        $tipos .= 'i';
        $parametros[] = (int)$usuarioId;

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    public function obtenerCuentaPorId($cuentaId, $usuarioId, $gestionarTodas = false)
    {
        $sql = "SELECT
                    c.*,
                    CONCAT_WS(' ', u.nombre, u.apellidos) AS usuario_nombre
                FROM whatsapp_cuentas c
                LEFT JOIN usuarios u
                    ON u.id = c.usuario_id
                WHERE c.id = ?";

        $tipos = 'i';
        $parametros = [(int)$cuentaId];

        if (!$gestionarTodas) {
            $sql .= " AND (
                        c.usuario_id = ?
                        OR (
                            c.usuario_id IS NULL
                            AND c.es_predeterminada = 1
                        )
                    )";
            $tipos .= 'i';
            $parametros[] = (int)$usuarioId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerCuentaPorPhoneNumberId($phoneNumberId)
    {
        $sql = "SELECT *
                FROM whatsapp_cuentas
                WHERE phone_number_id = ?
                  AND activo = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $phoneNumberId = trim((string)$phoneNumberId);
        $stmt->bind_param('s', $phoneNumberId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function resolverCuentaUsuario($usuarioId)
    {
        $sql = "SELECT *
                FROM whatsapp_cuentas
                WHERE activo = 1
                  AND (
                    usuario_id = ?
                    OR (
                        usuario_id IS NULL
                        AND es_predeterminada = 1
                    )
                  )
                ORDER BY
                    (usuario_id = ?) DESC,
                    es_predeterminada DESC,
                    id ASC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('ii', $usuarioId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function guardarCuenta(array $datos, $actorUsuarioId)
    {
        $id = (int)($datos['id'] ?? 0);
        $nombre = trim((string)($datos['nombre'] ?? ''));
        $phoneNumberId = trim((string)($datos['phone_number_id'] ?? ''));
        $numeroMostrado = trim((string)($datos['numero_mostrado'] ?? ''));
        $usuarioId = (int)($datos['usuario_id'] ?? 0);
        $tipo = strtoupper(trim((string)($datos['tipo'] ?? 'EMPRESARIAL')));
        $predeterminada = !empty($datos['es_predeterminada']) ? 1 : 0;
        $activo = isset($datos['activo']) ? ((int)$datos['activo'] === 1 ? 1 : 0) : 1;
        $actorUsuarioId = (int)$actorUsuarioId;

        if (!in_array($tipo, ['PRUEBA', 'EMPRESARIAL'], true)) {
            $tipo = 'EMPRESARIAL';
        }

        $this->connection->begin_transaction();

        try {
            if ($predeterminada === 1) {
                $this->connection->query(
                    "UPDATE whatsapp_cuentas SET es_predeterminada = 0"
                );
            }

            if ($id > 0) {
                $sql = "UPDATE whatsapp_cuentas
                        SET nombre = ?,
                            phone_number_id = ?,
                            numero_mostrado = ?,
                            usuario_id = NULLIF(?, 0),
                            tipo = ?,
                            es_predeterminada = ?,
                            activo = ?,
                            actualizado_por = ?
                        WHERE id = ?";

                $stmt = $this->connection->prepare($sql);
                $stmt->bind_param(
                    'sssisiiii',
                    $nombre,
                    $phoneNumberId,
                    $numeroMostrado,
                    $usuarioId,
                    $tipo,
                    $predeterminada,
                    $activo,
                    $actorUsuarioId,
                    $id
                );
            } else {
                $sql = "INSERT INTO whatsapp_cuentas (
                            nombre,
                            phone_number_id,
                            numero_mostrado,
                            usuario_id,
                            tipo,
                            es_predeterminada,
                            activo,
                            creado_por,
                            actualizado_por
                        ) VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?)";

                $stmt = $this->connection->prepare($sql);
                $stmt->bind_param(
                    'sssisiiii',
                    $nombre,
                    $phoneNumberId,
                    $numeroMostrado,
                    $usuarioId,
                    $tipo,
                    $predeterminada,
                    $activo,
                    $actorUsuarioId,
                    $actorUsuarioId
                );
            }

            if (!$stmt->execute()) {
                throw new RuntimeException('No fue posible guardar el canal de WhatsApp.');
            }

            $cuentaId = $id > 0 ? $id : (int)$this->connection->insert_id;

            $this->connection->commit();

            return $cuentaId;
        } catch (Throwable $error) {
            $this->connection->rollback();
            throw $error;
        }
    }

    public function obtenerUsuariosActivos()
    {
        $sql = "SELECT
                    u.id,
                    u.nombre,
                    u.apellidos,
                    r.nombre AS rol
                FROM usuarios u
                INNER JOIN roles r
                    ON r.id = u.rol_id
                WHERE u.estado = 1
                  AND r.estado = 1
                ORDER BY u.nombre, u.apellidos";

        return $this->resultadoArreglo($this->connection->query($sql));
    }

    public function obtenerConversaciones($usuarioId, $gestionarTodas = false)
    {
        if (!$this->estructuraDisponible()) {
            return [];
        }

        $sql = $this->consultaConversacionesBase() . "
                WHERE c.activo = 1";

        $tipos = '';
        $parametros = [];

        if (!$gestionarTodas) {
            $sql .= " AND (
                        conv.responsable_usuario_id = ?
                        OR c.usuario_id = ?
                    )";
            $tipos = 'ii';
            $parametros = [(int)$usuarioId, (int)$usuarioId];
        }

        $sql .= " ORDER BY
                    conv.ultimo_mensaje_at IS NULL ASC,
                    conv.ultimo_mensaje_at DESC,
                    conv.id DESC";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    public function obtenerConversacion($conversacionId, $usuarioId, $gestionarTodas = false)
    {
        $sql = $this->consultaConversacionesBase() . "
                WHERE conv.id = ?
                  AND c.activo = 1";

        $tipos = 'i';
        $parametros = [(int)$conversacionId];

        if (!$gestionarTodas) {
            $sql .= " AND (
                        conv.responsable_usuario_id = ?
                        OR c.usuario_id = ?
                    )";
            $tipos .= 'ii';
            array_push($parametros, (int)$usuarioId, (int)$usuarioId);
        }

        $sql .= " LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerMensajes($conversacionId, $usuarioId, $gestionarTodas = false, $despuesDeId = 0)
    {
        if (!$this->obtenerConversacion(
            $conversacionId,
            $usuarioId,
            $gestionarTodas
        )) {
            return [];
        }

        $sql = "SELECT
                    m.id,
                    m.wamid,
                    m.direccion,
                    m.tipo,
                    m.contenido,
                    m.estado,
                    m.error_codigo,
                    m.error_detalle,
                    m.usuario_id,
                    m.enviado_at,
                    m.entregado_at,
                    m.leido_at,
                    m.created_at,
                    CONCAT_WS(' ', u.nombre, u.apellidos) AS usuario_nombre
                FROM whatsapp_mensajes m
                LEFT JOIN usuarios u
                    ON u.id = m.usuario_id
                WHERE m.conversacion_id = ?";

        $tipos = 'i';
        $parametros = [(int)$conversacionId];

        if ((int)$despuesDeId > 0) {
            $sql .= " AND m.id > ?";
            $tipos .= 'i';
            $parametros[] = (int)$despuesDeId;
        }

        $sql .= " ORDER BY m.id ASC";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    public function crearORecuperarConversacion(
        $cuentaId,
        $telefono,
        $nombreContacto,
        $responsableUsuarioId = 0,
        $aliadoSeguimientoId = 0
    ) {
        $cuentaId = (int)$cuentaId;
        $telefono = trim((string)$telefono);
        $normalizado = $this->normalizarNumero($telefono);
        $nombreContacto = trim((string)$nombreContacto);
        $responsableUsuarioId = (int)$responsableUsuarioId;
        $aliadoSeguimientoId = (int)$aliadoSeguimientoId;

        $sql = "INSERT INTO whatsapp_conversaciones (
                    cuenta_id,
                    telefono_contacto,
                    telefono_normalizado,
                    nombre_contacto,
                    aliado_seguimiento_id,
                    responsable_usuario_id,
                    estado
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    NULLIF(?, ''),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    'ABIERTA'
                )
                ON DUPLICATE KEY UPDATE
                    telefono_contacto = VALUES(telefono_contacto),
                    nombre_contacto = COALESCE(
                        NULLIF(VALUES(nombre_contacto), ''),
                        nombre_contacto
                    ),
                    aliado_seguimiento_id = COALESCE(
                        aliado_seguimiento_id,
                        NULLIF(VALUES(aliado_seguimiento_id), 0)
                    ),
                    responsable_usuario_id = COALESCE(
                        responsable_usuario_id,
                        NULLIF(VALUES(responsable_usuario_id), 0)
                    ),
                    updated_at = NOW()";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'isssii',
            $cuentaId,
            $telefono,
            $normalizado,
            $nombreContacto,
            $aliadoSeguimientoId,
            $responsableUsuarioId
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('No fue posible abrir la conversación.');
        }

        $stmtBuscar = $this->connection->prepare(
            "SELECT id
             FROM whatsapp_conversaciones
             WHERE cuenta_id = ?
               AND telefono_normalizado = ?
             LIMIT 1"
        );
        $stmtBuscar->bind_param('is', $cuentaId, $normalizado);
        $stmtBuscar->execute();

        $fila = $stmtBuscar->get_result()->fetch_assoc();

        return (int)($fila['id'] ?? 0);
    }

    public function registrarMensajeSalida(
        $conversacionId,
        $usuarioId,
        $contenido,
        array $resultadoApi,
        $tipo = 'TEXT'
    ) {
        $ok = !empty($resultadoApi['ok']);
        $wamid = $ok ? trim((string)($resultadoApi['wamid'] ?? '')) : '';
        $estado = $ok ? 'ENVIADO' : 'ERROR';
        $errorCodigo = $ok ? '' : (string)($resultadoApi['codigo_meta'] ?? '');
        $errorDetalle = $ok ? '' : mb_substr(
            (string)($resultadoApi['mensaje'] ?? 'Error de envío'),
            0,
            700
        );
        $meta = json_encode(
            $resultadoApi['respuesta'] ?? [],
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        $sql = "INSERT INTO whatsapp_mensajes (
                    conversacion_id,
                    wamid,
                    direccion,
                    tipo,
                    contenido,
                    estado,
                    error_codigo,
                    error_detalle,
                    usuario_id,
                    mensaje_meta,
                    enviado_at
                ) VALUES (
                    ?,
                    NULLIF(?, ''),
                    'SALIENTE',
                    ?,
                    ?,
                    ?,
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    ?,
                    ?,
                    NOW()
                )";

        $stmt = $this->connection->prepare($sql);
        $conversacionId = (int)$conversacionId;
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param(
            'issssssis',
            $conversacionId,
            $wamid,
            $tipo,
            $contenido,
            $estado,
            $errorCodigo,
            $errorDetalle,
            $usuarioId,
            $meta
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('No fue posible registrar el mensaje.');
        }

        $this->actualizarResumenConversacion(
            $conversacionId,
            $contenido,
            false
        );

        return (int)$this->connection->insert_id;
    }

    public function registrarMensajeEntrada(
        $cuentaId,
        $telefono,
        $nombreContacto,
        $wamid,
        $tipo,
        $contenido,
        $timestamp
    ) {
        $cuenta = $this->obtenerCuentaDirecta((int)$cuentaId);

        if (!$cuenta) {
            return 0;
        }

        $responsable = (int)($cuenta['usuario_id'] ?? 0);
        $conversacionId = $this->crearORecuperarConversacion(
            (int)$cuentaId,
            $telefono,
            $nombreContacto,
            $responsable,
            0
        );

        if ($conversacionId <= 0) {
            return 0;
        }

        $fecha = $this->fechaDesdeTimestamp($timestamp);
        $ventanaHasta = (clone $fecha)->modify('+24 hours');

        $sql = "INSERT IGNORE INTO whatsapp_mensajes (
                    conversacion_id,
                    wamid,
                    direccion,
                    tipo,
                    contenido,
                    estado,
                    enviado_at
                ) VALUES (
                    ?,
                    ?,
                    'ENTRANTE',
                    ?,
                    ?,
                    'RECIBIDO',
                    ?
                )";

        $stmt = $this->connection->prepare($sql);
        $wamid = trim((string)$wamid);
        $tipo = strtoupper(trim((string)$tipo));
        $contenido = (string)$contenido;
        $fechaSql = $fecha->format('Y-m-d H:i:s');
        $stmt->bind_param(
            'issss',
            $conversacionId,
            $wamid,
            $tipo,
            $contenido,
            $fechaSql
        );
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $sqlConv = "UPDATE whatsapp_conversaciones
                        SET nombre_contacto = COALESCE(
                                NULLIF(?, ''),
                                nombre_contacto
                            ),
                            estado = 'ABIERTA',
                            ventana_servicio_hasta = ?,
                            no_leidos = no_leidos + 1,
                            ultimo_mensaje_at = ?,
                            ultimo_mensaje_preview = ?,
                            updated_at = NOW()
                        WHERE id = ?";

            $stmtConv = $this->connection->prepare($sqlConv);
            $ventanaSql = $ventanaHasta->format('Y-m-d H:i:s');
            $preview = mb_substr($contenido, 0, 300);
            $stmtConv->bind_param(
                'ssssi',
                $nombreContacto,
                $ventanaSql,
                $fechaSql,
                $preview,
                $conversacionId
            );
            $stmtConv->execute();
        }

        return $conversacionId;
    }

    public function actualizarEstadoMensaje($wamid, $estadoMeta, $timestamp, array $errores = [])
    {
        $wamid = trim((string)$wamid);
        $estadoMeta = strtolower(trim((string)$estadoMeta));

        $mapa = [
            'sent' => 'ENVIADO',
            'delivered' => 'ENTREGADO',
            'read' => 'LEIDO',
            'failed' => 'ERROR'
        ];

        if ($wamid === '' || !isset($mapa[$estadoMeta])) {
            return false;
        }

        $fecha = $this->fechaDesdeTimestamp($timestamp)->format('Y-m-d H:i:s');
        $estado = $mapa[$estadoMeta];
        $errorCodigo = '';
        $errorDetalle = '';

        if (!empty($errores[0]) && is_array($errores[0])) {
            $errorCodigo = (string)($errores[0]['code'] ?? '');
            $errorDetalle = mb_substr(
                (string)(
                    $errores[0]['title'] ??
                    $errores[0]['message'] ??
                    'Meta reportó un error.'
                ),
                0,
                700
            );
        }

        $camposFecha = [
            'sent' => 'enviado_at',
            'delivered' => 'entregado_at',
            'read' => 'leido_at'
        ];

        $sql = "UPDATE whatsapp_mensajes
                SET estado = ?,
                    error_codigo = NULLIF(?, ''),
                    error_detalle = NULLIF(?, '')";

        $tipos = 'sss';
        $parametros = [$estado, $errorCodigo, $errorDetalle];

        if (isset($camposFecha[$estadoMeta])) {
            $sql .= ", " . $camposFecha[$estadoMeta] . " = ?";
            $tipos .= 's';
            $parametros[] = $fecha;
        }

        $sql .= " WHERE wamid = ?";
        $tipos .= 's';
        $parametros[] = $wamid;

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);

        return $stmt->execute();
    }

    public function marcarConversacionLeida($conversacionId)
    {
        $stmt = $this->connection->prepare(
            "UPDATE whatsapp_conversaciones
             SET no_leidos = 0
             WHERE id = ?"
        );
        $conversacionId = (int)$conversacionId;
        $stmt->bind_param('i', $conversacionId);

        return $stmt->execute();
    }

    public function registrarEventoWebhook($payload, $tipo)
    {
        $hash = hash('sha256', (string)$payload);

        $sql = "INSERT IGNORE INTO whatsapp_webhook_eventos (
                    evento_hash,
                    tipo,
                    payload,
                    procesado
                ) VALUES (?, ?, ?, 0)";

        $stmt = $this->connection->prepare($sql);
        $tipo = trim((string)$tipo);
        $stmt->bind_param('sss', $hash, $tipo, $payload);
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            return 0;
        }

        return (int)$this->connection->insert_id;
    }

    public function finalizarEventoWebhook($eventoId, $error = '')
    {
        $sql = "UPDATE whatsapp_webhook_eventos
                SET procesado = ?,
                    error_detalle = NULLIF(?, ''),
                    procesado_at = NOW()
                WHERE id = ?";

        $procesado = trim((string)$error) === '' ? 1 : 0;
        $eventoId = (int)$eventoId;
        $error = mb_substr((string)$error, 0, 700);

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('isi', $procesado, $error, $eventoId);

        return $stmt->execute();
    }

    public function ventanaServicioAbierta(array $conversacion)
    {
        $hasta = trim((string)($conversacion['ventana_servicio_hasta'] ?? ''));

        if ($hasta === '') {
            return false;
        }

        try {
            return new DateTime($hasta) > new DateTime();
        } catch (Throwable $error) {
            return false;
        }
    }

    private function consultaConversacionesBase()
    {
        return "SELECT
                    conv.id,
                    conv.cuenta_id,
                    conv.telefono_contacto,
                    conv.telefono_normalizado,
                    conv.nombre_contacto,
                    conv.aliado_seguimiento_id,
                    conv.responsable_usuario_id,
                    conv.estado,
                    conv.ventana_servicio_hasta,
                    conv.no_leidos,
                    conv.ultimo_mensaje_at,
                    conv.ultimo_mensaje_preview,
                    c.nombre AS cuenta_nombre,
                    c.numero_mostrado AS cuenta_numero,
                    c.phone_number_id,
                    c.usuario_id AS cuenta_usuario_id,
                    CONCAT_WS(' ', responsable.nombre, responsable.apellidos)
                        AS responsable_nombre,
                    aliado.nombre_entidad AS aliado_nombre,
                    aliado.contacto_nombre AS aliado_contacto,
                    e.nombre AS aliado_estado,
                    COALESCE(m.nombre, '') AS aliado_municipio
                FROM whatsapp_conversaciones conv
                INNER JOIN whatsapp_cuentas c
                    ON c.id = conv.cuenta_id
                LEFT JOIN usuarios responsable
                    ON responsable.id = conv.responsable_usuario_id
                LEFT JOIN seguimientos_vinculacion aliado
                    ON aliado.id = conv.aliado_seguimiento_id
                LEFT JOIN estados e
                    ON e.id = aliado.estado_id
                LEFT JOIN municipios m
                    ON m.id = aliado.municipio_id";
    }

    private function obtenerCuentaDirecta($cuentaId)
    {
        $stmt = $this->connection->prepare(
            "SELECT *
             FROM whatsapp_cuentas
             WHERE id = ?
               AND activo = 1
             LIMIT 1"
        );
        $cuentaId = (int)$cuentaId;
        $stmt->bind_param('i', $cuentaId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function actualizarResumenConversacion($conversacionId, $contenido, $incrementarNoLeidos)
    {
        $sql = "UPDATE whatsapp_conversaciones
                SET ultimo_mensaje_at = NOW(),
                    ultimo_mensaje_preview = ?,
                    no_leidos = no_leidos + ?,
                    updated_at = NOW()
                WHERE id = ?";

        $preview = mb_substr((string)$contenido, 0, 300);
        $incremento = $incrementarNoLeidos ? 1 : 0;
        $conversacionId = (int)$conversacionId;

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('sii', $preview, $incremento, $conversacionId);
        $stmt->execute();
    }

    private function fechaDesdeTimestamp($timestamp)
    {
        $timestamp = (int)$timestamp;

        if ($timestamp <= 0) {
            return new DateTime();
        }

        $fecha = new DateTime('@' . $timestamp);
        $fecha->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $fecha;
    }

    private function normalizarNumero($numero)
    {
        return preg_replace('/[^0-9]+/', '', (string)$numero);
    }

    private function tablaExiste($tabla)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', (string)$tabla);
        $resultado = $this->connection->query(
            "SHOW TABLES LIKE '" . $tabla . "'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function vincularParametros($stmt, $tipos, $parametros)
    {
        if ($tipos === '') {
            return;
        }

        $referencias = [];
        $referencias[] = &$tipos;

        foreach ($parametros as $indice => $valor) {
            $referencias[] = &$parametros[$indice];
        }

        call_user_func_array([$stmt, 'bind_param'], $referencias);
    }

    private function resultadoArreglo($resultado)
    {
        $filas = [];

        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
    }
}
