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
                'total_enviados' => $totalEnviados,
                'adjuntos_disponibles' => $this->listarAdjuntosDisponibles(
                    (int)$seguimientoId
                )
            ]
        ];
    }

    public function enviar(
        $seguimientoId,
        $usuarioId,
        $asunto,
        $cuerpo,
        $adjuntosExpediente = [],
        $archivosNuevos = null
    ) {
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

        $preparacionAdjuntos = $this->prepararAdjuntos(
            $seguimientoId,
            $usuarioId,
            $adjuntosExpediente,
            $archivosNuevos
        );
        if (!($preparacionAdjuntos['ok'] ?? false)) {
            return $preparacionAdjuntos;
        }

        $adjuntos = $preparacionAdjuntos['adjuntos'] ?? [];
        $archivosCreados = $preparacionAdjuntos['archivos_creados'] ?? [];

        $resultadoEnvio = $this->enviarCorreo(
            $seguimiento,
            $asunto,
            $cuerpo,
            $adjuntos
        );
        if (!($resultadoEnvio['ok'] ?? false)) {
            $this->eliminarArchivos($archivosCreados);
            return $resultadoEnvio;
        }

        $destinatario = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        $nombresAdjuntos = array_values(array_filter(array_map(
            static function ($adjunto) {
                return trim((string)($adjunto['nombre'] ?? ''));
            },
            $adjuntos
        )));
        $detalleAdjuntos = !empty($nombresAdjuntos)
            ? "\nAdjuntos: " . implode(', ', $nombresAdjuntos)
            : '';

        $notasPost = 'Asunto: ' . $asunto . "\nMensaje: " . $cuerpo .
            $detalleAdjuntos;
        $notasInteraccion = "Seguimiento por correo enviado\n" .
            'Para: ' . $destinatario . "\n" .
            'Asunto: ' . $asunto .
            $detalleAdjuntos . "\n\n" .
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
            $interaccionId = (int)$this->connection->insert_id;

            if (!empty($adjuntos)) {
                $this->registrarAdjuntos(
                    $seguimientoId,
                    $interaccionId,
                    $usuarioId,
                    $adjuntos
                );
            }

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                               SET ultima_interaccion_at = NOW(),
                                   proxima_accion_at = NULL
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
            'total_enviados' => $this->contarCorreosSeguimiento($seguimientoId),
            'adjuntos_enviados' => count($adjuntos)
        ];
    }

    public function habilitarAgenda($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        if (!$this->estructuraCoordinacionDisponible()) {
            return $this->error(
                'Falta aplicar la migración de estabilización de la ruta del Analista.',
                500
            );
        }

        $seguimiento = $this->obtenerSeguimientoAnalista($seguimientoId, $usuarioId);

        $validacion = $this->validarEtapa($seguimiento);
        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        $this->asegurarPostEnvio($seguimientoId);

        /*
         * La decisión de continuar a reunión ya no se confunde con un correo
         * realmente enviado. seguimiento_correo_at conserva únicamente el
         * historial de correo; esta marca representa el cierre explícito del Paso 10.
         */
        $sql = "UPDATE seguimientos_vinculacion_post_envio
                SET coordinacion_reunion_habilitada_at =
                        COALESCE(coordinacion_reunion_habilitada_at, NOW()),
                    coordinacion_reunion_habilitada_por =
                        COALESCE(coordinacion_reunion_habilitada_por, ?)
                WHERE seguimiento_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $usuarioId, $seguimientoId);

        if (!$stmt->execute()) {
            return $this->error('No fue posible habilitar la coordinación de reunión.', 500);
        }

        $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                           SET ultima_interaccion_at = NOW(),
                               proxima_accion_at = NULL
                           WHERE id = ?
                             AND analista_id = ?
                             AND activo = 1";
        $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
        $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
        $stmtSeguimiento->execute();

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

        if (!$this->estructuraCoordinacionDisponible()) {
            return $flujo;
        }

        $seguimiento = $this->obtenerEstadoEtapa((int)$seguimientoId, (int)$usuarioId);
        if (!$seguimiento || trim((string)($seguimiento['respuesta_at'] ?? '')) === '') {
            return $flujo;
        }

        if ($this->contactoDiferidoPendiente($seguimiento)) {
            return $flujo;
        }

        if (trim((string)($seguimiento['reunion_agendada_at'] ?? '')) !== '') {
            return $flujo;
        }

        $totalPasos = max(13, (int)($flujo['total_pasos'] ?? 13));
        $coordinacionAt = trim((string)(
            $seguimiento['coordinacion_reunion_habilitada_at'] ?? ''
        ));

        if ($coordinacionAt !== '') {
            $flujo['paso_actual'] = 11;
            $flujo['total_pasos'] = $totalPasos;
            $flujo['porcentaje'] = (int)round((11 / $totalPasos) * 100);
            $flujo['titulo'] = 'Coordinar reunión';
            $flujo['descripcion'] =
                'La etapa de seguimiento quedó cerrada. Abre la agenda para proponer una fecha y coordinar la reunión con Cuenta Clave.';
            $flujo['faltantes'] = [];
            $flujo['accion_principal'] = [
                'codigo' => 'AGENDAR_REUNION',
                'etiqueta' => 'Abrir agenda',
                'icono' => 'bi-calendar3'
            ];
            $flujo['accion_secundaria'] = null;
            $flujo['ventana'] = [
                'anterior' => [
                    'numero' => 10,
                    'clave' => 'SEGUIMIENTO_CORREO',
                    'titulo' => 'Seguimiento por correo'
                ],
                'actual' => [
                    'numero' => 11,
                    'clave' => 'REUNION_AGENDADA',
                    'titulo' => 'Reunión agendada'
                ],
                'siguiente' => [
                    'numero' => 12,
                    'clave' => 'REUNION_REALIZADA',
                    'titulo' => 'Reunión realizada'
                ]
            ];
            if (!is_array($flujo['contexto'] ?? null)) {
                $flujo['contexto'] = [];
            }
            $flujo['contexto']['coordinacion_reunion_habilitada_at'] = $coordinacionAt;

            return $flujo;
        }

        if ($this->existeReunionActiva((int)$seguimientoId, (int)$usuarioId)) {
            return $flujo;
        }

        $totalEnviados = $this->contarCorreosSeguimiento((int)$seguimientoId);

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
        $flujo['contexto']['coordinacion_reunion_habilitada_at'] = '';

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
        if ($this->contactoDiferidoPendiente($seguimiento)) {
            return $this->error(
                'La institución solicitó retomar el contacto en la fecha programada. Aún no corresponde continuar esta etapa.',
                409
            );
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
                    p.contactar_despues_at,
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

        $sql = "SELECT
                    p.respuesta_at,
                    p.respuesta_tipo,
                    p.contactar_despues_at,
                    p.coordinacion_reunion_habilitada_at,
                    p.reunion_agendada_at
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

    private function listarAdjuntosDisponibles($seguimientoId)
    {
        $seguimientoId = (int)$seguimientoId;
        $salida = [];

        $sqlOficio = "SELECT id, folio, archivo_pdf
                      FROM oficios_vinculacion
                      WHERE seguimiento_id = ?
                        AND archivo_pdf IS NOT NULL
                        AND TRIM(archivo_pdf) <> ''
                      ORDER BY id DESC
                      LIMIT 1";
        $stmtOficio = $this->connection->prepare($sqlOficio);
        $stmtOficio->bind_param('i', $seguimientoId);
        $stmtOficio->execute();
        $oficio = $stmtOficio->get_result()->fetch_assoc();

        if ($oficio) {
            $rutaRelativa = trim((string)($oficio['archivo_pdf'] ?? ''));
            $ruta = $this->rutaInternaAbsoluta($rutaRelativa);
            if ($ruta !== null && is_file($ruta)) {
                $folio = trim((string)($oficio['folio'] ?? ''));
                $salida[] = [
                    'valor' => 'oficio:' . (int)$oficio['id'],
                    'nombre' => basename($ruta),
                    'detalle' => $folio !== ''
                        ? 'Oficio institucional · ' . $folio
                        : 'Oficio institucional',
                    'mime' => 'application/pdf',
                    'tamano' => (int)filesize($ruta)
                ];
            }
        }

        if (!$this->tablaExiste('seguimientos_vinculacion_correo_adjuntos')) {
            return $salida;
        }

        $sql = "SELECT id, archivo, nombre_original, mime, tamano
                FROM seguimientos_vinculacion_correo_adjuntos
                WHERE seguimiento_id = ?
                  AND origen = 'SUBIDO'
                ORDER BY id DESC";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $vistos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $nombre = trim((string)($fila['nombre_original'] ?? ''));
            $rutaRelativa = trim((string)($fila['archivo'] ?? ''));
            $clave = strtolower($nombre . '|' . $rutaRelativa);
            if ($nombre === '' || isset($vistos[$clave])) {
                continue;
            }

            $ruta = $this->rutaInternaAbsoluta($rutaRelativa);
            if ($ruta === null || !is_file($ruta)) {
                continue;
            }

            $vistos[$clave] = true;
            $salida[] = [
                'valor' => 'archivo:' . (int)$fila['id'],
                'nombre' => $nombre,
                'detalle' => 'Archivo del expediente',
                'mime' => trim((string)($fila['mime'] ?? 'application/octet-stream')),
                'tamano' => (int)($fila['tamano'] ?? filesize($ruta))
            ];

            if (count($salida) >= 8) {
                break;
            }
        }

        return $salida;
    }

    private function prepararAdjuntos(
        $seguimientoId,
        $usuarioId,
        $seleccionados,
        $archivosNuevos
    ) {
        $seleccionados = is_array($seleccionados)
            ? array_values(array_unique(array_filter(array_map('strval', $seleccionados))))
            : [];

        $subidos = $this->normalizarArchivosSubidos($archivosNuevos);
        $totalSolicitado = count($seleccionados) + count($subidos);

        if ($totalSolicitado > 8) {
            return $this->error(
                'El correo no puede incluir más de 8 archivos adjuntos.',
                422
            );
        }

        if (
            $totalSolicitado > 0 &&
            !$this->tablaExiste('seguimientos_vinculacion_correo_adjuntos')
        ) {
            return $this->error(
                'Falta aplicar la migración de adjuntos de correos de seguimiento.',
                500
            );
        }

        $adjuntos = [];
        $archivosCreados = [];
        $tamanoTotal = 0;

        foreach ($seleccionados as $valor) {
            $adjunto = $this->resolverAdjuntoExpediente(
                (int)$seguimientoId,
                (string)$valor
            );
            if (!($adjunto['ok'] ?? false)) {
                $this->eliminarArchivos($archivosCreados);
                return $adjunto;
            }

            $item = $adjunto['adjunto'];
            $tamanoTotal += (int)$item['tamano'];
            $adjuntos[] = $item;
        }

        foreach ($subidos as $archivo) {
            $validacion = $this->validarArchivoSubido($archivo);
            if (!($validacion['ok'] ?? false)) {
                $this->eliminarArchivos($archivosCreados);
                return $validacion;
            }

            $guardado = $this->guardarArchivoSubido(
                (int)$seguimientoId,
                (int)$usuarioId,
                $archivo,
                $validacion
            );
            if (!($guardado['ok'] ?? false)) {
                $this->eliminarArchivos($archivosCreados);
                return $guardado;
            }

            $item = $guardado['adjunto'];
            $archivosCreados[] = $item['ruta'];
            $tamanoTotal += (int)$item['tamano'];
            $adjuntos[] = $item;
        }

        if ($tamanoTotal > 20 * 1024 * 1024) {
            $this->eliminarArchivos($archivosCreados);
            return $this->error(
                'Los archivos adjuntos superan el tamaño total permitido de 20 MB.',
                422
            );
        }

        return [
            'ok' => true,
            'adjuntos' => $adjuntos,
            'archivos_creados' => $archivosCreados
        ];
    }

    private function resolverAdjuntoExpediente($seguimientoId, $valor)
    {
        if (preg_match('/^oficio:(\d+)$/', $valor, $coincidencia)) {
            $oficioId = (int)$coincidencia[1];
            $sql = "SELECT id, folio, archivo_pdf
                    FROM oficios_vinculacion
                    WHERE id = ?
                      AND seguimiento_id = ?
                    LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $oficioId, $seguimientoId);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();

            if (!$fila) {
                return $this->error('El oficio seleccionado ya no está disponible.', 422);
            }

            $ruta = $this->rutaInternaAbsoluta((string)$fila['archivo_pdf']);
            if ($ruta === null || !is_file($ruta)) {
                return $this->error('El PDF del oficio seleccionado no está disponible.', 422);
            }

            return [
                'ok' => true,
                'adjunto' => [
                    'ruta' => $ruta,
                    'archivo_relativo' => $this->rutaRelativa($ruta),
                    'nombre' => basename($ruta),
                    'mime' => 'application/pdf',
                    'tamano' => (int)filesize($ruta),
                    'origen' => 'OFICIO'
                ]
            ];
        }

        if (preg_match('/^archivo:(\d+)$/', $valor, $coincidencia)) {
            $adjuntoId = (int)$coincidencia[1];
            $sql = "SELECT archivo, nombre_original, mime, tamano
                    FROM seguimientos_vinculacion_correo_adjuntos
                    WHERE id = ?
                      AND seguimiento_id = ?
                    LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $adjuntoId, $seguimientoId);
            $stmt->execute();
            $fila = $stmt->get_result()->fetch_assoc();

            if (!$fila) {
                return $this->error('El archivo seleccionado ya no está disponible.', 422);
            }

            $ruta = $this->rutaInternaAbsoluta((string)$fila['archivo']);
            if ($ruta === null || !is_file($ruta)) {
                return $this->error('El archivo seleccionado ya no está disponible.', 422);
            }

            return [
                'ok' => true,
                'adjunto' => [
                    'ruta' => $ruta,
                    'archivo_relativo' => $this->rutaRelativa($ruta),
                    'nombre' => basename((string)$fila['nombre_original']),
                    'mime' => trim((string)$fila['mime']) ?: 'application/octet-stream',
                    'tamano' => (int)$fila['tamano'],
                    'origen' => 'EXPEDIENTE'
                ]
            ];
        }

        return $this->error('Uno de los archivos seleccionados no es válido.', 422);
    }

    private function normalizarArchivosSubidos($archivos)
    {
        if (!is_array($archivos) || !isset($archivos['name'])) {
            return [];
        }

        $nombres = is_array($archivos['name'])
            ? $archivos['name']
            : [$archivos['name']];
        $salida = [];

        foreach ($nombres as $indice => $nombre) {
            $error = is_array($archivos['error'])
                ? (int)($archivos['error'][$indice] ?? UPLOAD_ERR_NO_FILE)
                : (int)($archivos['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $salida[] = [
                'name' => (string)$nombre,
                'type' => is_array($archivos['type'])
                    ? (string)($archivos['type'][$indice] ?? '')
                    : (string)($archivos['type'] ?? ''),
                'tmp_name' => is_array($archivos['tmp_name'])
                    ? (string)($archivos['tmp_name'][$indice] ?? '')
                    : (string)($archivos['tmp_name'] ?? ''),
                'error' => $error,
                'size' => is_array($archivos['size'])
                    ? (int)($archivos['size'][$indice] ?? 0)
                    : (int)($archivos['size'] ?? 0)
            ];
        }

        return $salida;
    }

    private function validarArchivoSubido($archivo)
    {
        if ((int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->error('No fue posible recibir uno de los archivos adjuntos.', 422);
        }

        $tmp = (string)($archivo['tmp_name'] ?? '');
        $tamano = (int)($archivo['size'] ?? 0);
        $nombre = basename(str_replace('\\', '/', (string)($archivo['name'] ?? 'archivo')));

        if ($tmp === '' || !is_uploaded_file($tmp) || !is_file($tmp)) {
            return $this->error('Uno de los archivos adjuntos no es una carga válida.', 422);
        }
        if ($tamano <= 0 || $tamano > 12 * 1024 * 1024) {
            return $this->error('Cada archivo adjunto debe pesar como máximo 12 MB.', 422);
        }

        $extension = strtolower((string)pathinfo($nombre, PATHINFO_EXTENSION));
        $permitidos = [
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'txt', 'csv', 'png', 'jpg', 'jpeg'
        ];
        if (!in_array($extension, $permitidos, true)) {
            return $this->error('Uno de los archivos adjuntos tiene un formato no permitido.', 422);
        }

        if ($extension === 'pdf') {
            $manejador = @fopen($tmp, 'rb');
            $firma = is_resource($manejador) ? fread($manejador, 5) : false;
            if (is_resource($manejador)) {
                fclose($manejador);
            }
            if ($firma !== '%PDF-') {
                return $this->error('Uno de los PDF adjuntos no es válido.', 422);
            }
        }

        if (in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
            $imagen = @getimagesize($tmp);
            if ($imagen === false) {
                return $this->error('Una de las imágenes adjuntas no es válida.', 422);
            }
        }

        $mime = $this->mimePorExtension($extension);
        return [
            'ok' => true,
            'nombre' => $nombre,
            'extension' => $extension,
            'mime' => $mime,
            'tamano' => $tamano
        ];
    }

    private function guardarArchivoSubido(
        $seguimientoId,
        $usuarioId,
        $archivo,
        $validacion
    ) {
        $directorio = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' .
            DIRECTORY_SEPARATOR . 'seguimientos' . DIRECTORY_SEPARATOR .
            date('Y') . DIRECTORY_SEPARATOR .
            'seguimiento_' . (int)$seguimientoId . DIRECTORY_SEPARATOR .
            'adjuntos_correo';

        if (
            !is_dir($directorio) &&
            !mkdir($directorio, 0775, true) &&
            !is_dir($directorio)
        ) {
            return $this->error('No fue posible preparar la carpeta de adjuntos.', 500);
        }

        $base = pathinfo((string)$validacion['nombre'], PATHINFO_FILENAME);
        $base = $this->nombreArchivoSeguro($base);
        $extension = (string)$validacion['extension'];
        $nombreFisico = date('Ymd_His') . '_' .
            bin2hex(random_bytes(4)) . '_' . $base . '.' . $extension;
        $destino = $directorio . DIRECTORY_SEPARATOR . $nombreFisico;

        if (!move_uploaded_file((string)$archivo['tmp_name'], $destino)) {
            return $this->error('No fue posible guardar uno de los archivos adjuntos.', 500);
        }

        return [
            'ok' => true,
            'adjunto' => [
                'ruta' => $destino,
                'archivo_relativo' => $this->rutaRelativa($destino),
                'nombre' => (string)$validacion['nombre'],
                'mime' => (string)$validacion['mime'],
                'tamano' => (int)$validacion['tamano'],
                'origen' => 'SUBIDO'
            ]
        ];
    }

    private function registrarAdjuntos(
        $seguimientoId,
        $interaccionId,
        $usuarioId,
        $adjuntos
    ) {
        if (!$this->tablaExiste('seguimientos_vinculacion_correo_adjuntos')) {
            throw new RuntimeException(
                'Falta aplicar la migración de adjuntos de correos de seguimiento.'
            );
        }

        $sql = "INSERT INTO seguimientos_vinculacion_correo_adjuntos (
                    seguimiento_id,
                    interaccion_id,
                    usuario_id,
                    origen,
                    archivo,
                    nombre_original,
                    mime,
                    tamano
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->connection->prepare($sql);

        foreach ($adjuntos as $adjunto) {
            $origen = strtoupper(trim((string)($adjunto['origen'] ?? 'EXPEDIENTE')));
            $archivo = trim((string)($adjunto['archivo_relativo'] ?? ''));
            $nombre = trim((string)($adjunto['nombre'] ?? 'archivo'));
            $mime = trim((string)($adjunto['mime'] ?? 'application/octet-stream'));
            $tamano = (int)($adjunto['tamano'] ?? 0);

            $stmt->bind_param(
                'iiissssi',
                $seguimientoId,
                $interaccionId,
                $usuarioId,
                $origen,
                $archivo,
                $nombre,
                $mime,
                $tamano
            );
            $stmt->execute();
        }
    }

    private function mimePorExtension($extension)
    {
        $mapa = [
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

        return $mapa[strtolower((string)$extension)] ?? 'application/octet-stream';
    }

    private function rutaInternaAbsoluta($rutaRelativa)
    {
        $rutaRelativa = trim(str_replace('\\', '/', (string)$rutaRelativa));
        if ($rutaRelativa === '' || strpos($rutaRelativa, '..') !== false) {
            return null;
        }

        $ruta = $this->rootPath . DIRECTORY_SEPARATOR .
            str_replace('/', DIRECTORY_SEPARATOR, ltrim($rutaRelativa, '/'));
        $real = realpath($ruta);
        $rootReal = realpath($this->rootPath);

        if (
            $real === false ||
            $rootReal === false ||
            strpos($real, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0
        ) {
            return null;
        }

        return $real;
    }

    private function rutaRelativa($ruta)
    {
        $rootReal = realpath($this->rootPath);
        $real = realpath((string)$ruta);
        if ($rootReal === false || $real === false) {
            return '';
        }

        $prefijo = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strpos($real, $prefijo) !== 0) {
            return '';
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($prefijo)));
    }

    private function nombreArchivoSeguro($valor)
    {
        $valor = trim((string)$valor);
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
            if (is_string($ascii) && trim($ascii) !== '') {
                $valor = $ascii;
            }
        }

        $valor = preg_replace('/[^A-Za-z0-9_-]+/', '_', $valor);
        $valor = trim((string)$valor, '_');
        return $valor !== '' ? substr($valor, 0, 80) : 'archivo';
    }

    private function eliminarArchivos($rutas)
    {
        foreach (is_array($rutas) ? $rutas : [] as $ruta) {
            if (is_string($ruta) && is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    private function enviarCorreo($seguimiento, $asunto, $cuerpo, $adjuntos = [])
    {
        $configHostinger = $this->cargarConfiguracionHostinger();

        if ($configHostinger['token'] !== '') {
            $resultado = $this->enviarPorHostinger(
                $seguimiento,
                $asunto,
                $cuerpo,
                $configHostinger,
                $adjuntos
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

        return $this->enviarPorSmtp($seguimiento, $asunto, $cuerpo, $adjuntos);
    }

    private function enviarPorHostinger(
        $seguimiento,
        $asunto,
        $cuerpo,
        $config,
        $adjuntos = []
    ) {
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

        if (!empty($adjuntos)) {
            $payload['attachments'] = [];
            foreach ($adjuntos as $adjunto) {
                $contenido = @file_get_contents((string)$adjunto['ruta']);
                if ($contenido === false) {
                    return $this->error(
                        'No fue posible leer uno de los archivos adjuntos.',
                        500
                    );
                }

                $payload['attachments'][] = [
                    'filename' => (string)$adjunto['nombre'],
                    'content' => base64_encode($contenido),
                    'contentType' => (string)$adjunto['mime'],
                    'encoding' => 'base64'
                ];
            }
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

    private function enviarPorSmtp($seguimiento, $asunto, $cuerpo, $adjuntos = [])
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

            foreach ($adjuntos as $adjunto) {
                $mail->addAttachment(
                    (string)$adjunto['ruta'],
                    (string)$adjunto['nombre']
                );
            }

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

    private function contactoDiferidoPendiente($seguimiento)
    {
        if (
            strtoupper(trim((string)($seguimiento['respuesta_tipo'] ?? ''))) !==
            'CONTACTAR_DESPUES'
        ) {
            return false;
        }

        $fecha = trim((string)($seguimiento['contactar_despues_at'] ?? ''));
        if ($fecha === '') {
            return false;
        }

        $timestamp = strtotime($fecha);
        return $timestamp !== false && $timestamp > time();
    }

    private function estructuraCoordinacionDisponible()
    {
        if (!$this->tablaExiste('seguimientos_vinculacion_post_envio')) {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM seguimientos_vinculacion_post_envio
             LIKE 'coordinacion_reunion_habilitada_at'"
        );

        return $resultado && $resultado->num_rows > 0;
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
