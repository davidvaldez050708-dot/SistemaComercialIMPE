<?php

require_once __DIR__ . '/../../config/db_connection.php';

class OficioCorreoService
{
    private const NOMBRE_PLANTILLA =
        'Correo institucional - Fundación Red Educativa México';

    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerBorrador($seguimientoId, $usuarioId, $modoAcceso)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $seguimiento = $this->obtenerSeguimientoAutorizado(
            $seguimientoId,
            $usuarioId,
            $modoAcceso
        );

        if (!$seguimiento) {
            return $this->error('No tienes acceso a este seguimiento.', 403);
        }

        $validacion = $this->validarOficioParaCorreo($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        $plantilla = $this->obtenerOCrearPlantillaInstitucional();

        if (!$plantilla) {
            return $this->error(
                'No fue posible preparar la plantilla institucional del correo.',
                500
            );
        }

        $this->asignarPlantillaSiFalta(
            (int)$seguimiento['oficio_id'],
            (int)$plantilla['id']
        );

        return [
            'ok' => true,
            'correo' => $this->construirCorreo(
                $seguimiento,
                $plantilla
            )
        ];
    }

    public function guardarBorrador($seguimientoId, $usuarioId, $asunto, $cuerpo)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $asunto = trim((string)$asunto);
        $cuerpo = trim((string)$cuerpo);

        if ($asunto === '' || $cuerpo === '') {
            return $this->error(
                'El asunto y el mensaje son obligatorios para guardar el borrador.',
                422
            );
        }

        if (mb_strlen($asunto) > 255) {
            return $this->error(
                'El asunto no puede superar 255 caracteres.',
                422
            );
        }

        if (mb_strlen($cuerpo) > 20000) {
            return $this->error(
                'El mensaje es demasiado largo.',
                422
            );
        }

        $seguimiento = $this->obtenerSeguimientoAnalista(
            $seguimientoId,
            $usuarioId
        );

        if (!$seguimiento) {
            return $this->error(
                'El borrador solo puede ser preparado por el Analista responsable.',
                403
            );
        }

        $validacion = $this->validarOficioParaCorreo($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        if ($this->correoYaEnviado($seguimiento)) {
            return $this->error(
                'El correo ya fue enviado y ya no puede modificarse.',
                409
            );
        }

        $plantilla = $this->obtenerOCrearPlantillaInstitucional();

        if (!$plantilla) {
            return $this->error(
                'No fue posible preparar la plantilla institucional del correo.',
                500
            );
        }

        $oficioId = (int)$seguimiento['oficio_id'];
        $plantillaId = (int)$plantilla['id'];
        $sql = "UPDATE oficios_vinculacion
                SET plantilla_correo_id = ?,
                    asunto_correo = ?,
                    cuerpo_correo = ?,
                    error_envio = NULL
                WHERE id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'issi',
            $plantillaId,
            $asunto,
            $cuerpo,
            $oficioId
        );

        if (!$stmt->execute()) {
            return $this->error(
                'No fue posible guardar el borrador del correo.',
                500
            );
        }

        $seguimiento['plantilla_correo_id'] = $plantillaId;
        $seguimiento['asunto_correo'] = $asunto;
        $seguimiento['cuerpo_correo'] = $cuerpo;
        $seguimiento['error_envio'] = null;

        return [
            'ok' => true,
            'mensaje' => 'Borrador de correo guardado correctamente.',
            'correo' => $this->construirCorreo(
                $seguimiento,
                $plantilla
            )
        ];
    }

    public function enviarAhora($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $seguimiento = $this->obtenerSeguimientoAnalista(
            $seguimientoId,
            $usuarioId
        );

        if (!$seguimiento) {
            return $this->error(
                'El correo solo puede ser enviado por el Analista responsable.',
                403
            );
        }

        $validacion = $this->validarOficioParaCorreo($seguimiento);

        if (!($validacion['ok'] ?? false)) {
            return $validacion;
        }

        if ($this->correoYaEnviado($seguimiento)) {
            $plantilla = $this->obtenerOCrearPlantillaInstitucional();

            return [
                'ok' => true,
                'existente' => true,
                'mensaje' => 'Este correo ya fue enviado anteriormente.',
                'correo' => $this->construirCorreo(
                    $seguimiento,
                    $plantilla ?: []
                )
            ];
        }

        $asunto = trim((string)($seguimiento['asunto_correo'] ?? ''));
        $cuerpo = trim((string)($seguimiento['cuerpo_correo'] ?? ''));

        if ($asunto === '' || $cuerpo === '') {
            return $this->error(
                'Guarda el borrador del correo antes de enviarlo.',
                422
            );
        }

        $rutaPdf = $this->rutaAbsolutaPdf(
            (string)($seguimiento['archivo_pdf'] ?? '')
        );

        if ($rutaPdf === '' || !is_file($rutaPdf)) {
            return $this->error(
                'El PDF del oficio no está disponible para adjuntarlo.',
                422
            );
        }

        $resultadoSmtp = $this->enviarPorSmtp(
            $seguimiento,
            $asunto,
            $cuerpo,
            $rutaPdf
        );

        if (!($resultadoSmtp['ok'] ?? false)) {
            $this->registrarErrorEnvio(
                (int)($seguimiento['oficio_id'] ?? 0),
                (string)($resultadoSmtp['mensaje_tecnico'] ?? $resultadoSmtp['mensaje'] ?? '')
            );

            return $this->error(
                (string)($resultadoSmtp['mensaje'] ?? 'No fue posible enviar el correo.'),
                (int)($resultadoSmtp['codigo_http'] ?? 502)
            );
        }

        $this->connection->begin_transaction();

        try {
            $oficioId = (int)$seguimiento['oficio_id'];
            $folio = trim((string)($seguimiento['folio'] ?? ''));
            $destinatario = trim((string)($seguimiento['destinatario_correo'] ?? ''));
            $notas = "Oficio/correo enviado\nFolio: {$folio}\nDestinatario: {$destinatario}";

            $sqlOficio = "UPDATE oficios_vinculacion
                    SET estado_oficio = 'ENVIADO',
                        fecha_envio = NOW(),
                        enviado_por = ?,
                        error_envio = NULL
                    WHERE id = ?
                        AND seguimiento_id = ?";
            $stmtOficio = $this->connection->prepare($sqlOficio);
            $stmtOficio->bind_param('iii', $usuarioId, $oficioId, $seguimientoId);
            $stmtOficio->execute();

            if ($stmtOficio->affected_rows <= 0) {
                throw new RuntimeException('No fue posible marcar el oficio como enviado.');
            }

            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                        seguimiento_id,
                        usuario_id,
                        canal,
                        fecha_inicio,
                        resultado,
                        notas
                    ) VALUES (?, ?, 'CORREO', NOW(), 'CORREO_ENVIADO', ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param('iis', $seguimientoId, $usuarioId, $notas);
            $stmtInteraccion->execute();

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                    SET estado_seguimiento = 'ESPERANDO_RESPUESTA',
                        ultima_interaccion_at = NOW(),
                        proxima_accion_at = NULL
                    WHERE id = ?
                        AND analista_id = ?
                        AND activo = 1";
            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $stmtSeguimiento->bind_param('ii', $seguimientoId, $usuarioId);
            $stmtSeguimiento->execute();

            if ($stmtSeguimiento->affected_rows <= 0) {
                throw new RuntimeException('No fue posible avanzar el seguimiento.');
            }

            $this->connection->commit();
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log(
                'Correo SMTP enviado, pero no se pudo actualizar el expediente: ' .
                $error->getMessage()
            );

            return $this->error(
                'El servidor SMTP aceptó el correo, pero no fue posible actualizar el expediente. Revisa el correo enviado antes de intentar nuevamente.',
                500
            );
        }

        $actualizado = $this->obtenerSeguimientoAnalista(
            $seguimientoId,
            $usuarioId
        );
        $plantilla = $this->obtenerOCrearPlantillaInstitucional();

        return [
            'ok' => true,
            'existente' => false,
            'mensaje' => 'Correo enviado correctamente.',
            'correo' => $this->construirCorreo(
                $actualizado ?: $seguimiento,
                $plantilla ?: []
            )
        ];
    }

    private function enviarPorSmtp($seguimiento, $asunto, $cuerpo, $rutaPdf)
    {
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        if (!is_file($autoload)) {
            return [
                'ok' => false,
                'codigo_http' => 500,
                'mensaje' => 'Las dependencias de correo no están instaladas. Ejecuta composer install antes de enviar.',
                'mensaje_tecnico' => 'vendor/autoload.php no existe.'
            ];
        }

        require_once $autoload;

        $archivoConfig = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'config' . DIRECTORY_SEPARATOR . 'mail_config.php';

        if (is_file($archivoConfig)) {
            require_once $archivoConfig;
        }

        $host = $this->configCorreo('MAIL_HOST');
        $puerto = (int)$this->configCorreo('MAIL_PORT', '587');
        $usuario = $this->configCorreo('MAIL_USERNAME');
        $password = $this->configCorreo('MAIL_PASSWORD');
        $encriptacion = strtolower($this->configCorreo('MAIL_ENCRYPTION', 'tls'));
        $remitente = $this->configCorreo('MAIL_FROM_ADDRESS', $usuario);
        $nombreRemitente = $this->configCorreo(
            'MAIL_FROM_NAME',
            'Fundación Red Educativa México'
        );

        if ($host === '' || $puerto <= 0 || $remitente === '') {
            return [
                'ok' => false,
                'codigo_http' => 500,
                'mensaje' => 'La configuración SMTP institucional está incompleta.',
                'mensaje_tecnico' => 'Faltan MAIL_HOST, MAIL_PORT o MAIL_FROM_ADDRESS.'
            ];
        }

        if ($usuario !== '' && $password === '') {
            return [
                'ok' => false,
                'codigo_http' => 500,
                'mensaje' => 'La cuenta SMTP no tiene credenciales completas.',
                'mensaje_tecnico' => 'MAIL_USERNAME existe pero MAIL_PASSWORD está vacío.'
            ];
        }

        $destinatario = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        $destinatarioNombre = trim((string)($seguimiento['destinatario_nombre'] ?? ''));
        $analistaCorreo = trim((string)($seguimiento['analista_correo'] ?? ''));
        $analistaNombre = trim(
            (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
            (string)($seguimiento['analista_apellidos'] ?? '')
        );

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
                $destinatario,
                $destinatarioNombre !== '' ? $destinatarioNombre : $destinatario
            );

            if (
                $analistaCorreo !== '' &&
                filter_var($analistaCorreo, FILTER_VALIDATE_EMAIL)
            ) {
                $mail->addReplyTo(
                    $analistaCorreo,
                    $analistaNombre !== '' ? $analistaNombre : $analistaCorreo
                );
            }

            $mail->Subject = $asunto;
            $mail->isHTML(false);
            $mail->Body = $cuerpo;
            $mail->addAttachment(
                $rutaPdf,
                basename((string)($seguimiento['archivo_pdf'] ?? $rutaPdf))
            );
            $mail->send();

            return ['ok' => true];
        } catch (Throwable $error) {
            error_log('Error SMTP al enviar oficio: ' . $error->getMessage());

            return [
                'ok' => false,
                'codigo_http' => 502,
                'mensaje' => 'No fue posible enviar el correo institucional. Verifica la cuenta SMTP e intenta nuevamente.',
                'mensaje_tecnico' => $error->getMessage()
            ];
        }
    }

    private function construirCorreo($seguimiento, $plantilla)
    {
        $analistaNombre = trim(
            (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
            (string)($seguimiento['analista_apellidos'] ?? '')
        );
        $reemplazos = [
            '{{FOLIO}}' => trim((string)($seguimiento['folio'] ?? '')),
            '{{DESTINATARIO_NOMBRE}}' => trim((string)($seguimiento['destinatario_nombre'] ?? '')),
            '{{DESTINATARIO_CARGO}}' => trim((string)($seguimiento['destinatario_cargo'] ?? '')),
            '{{INSTITUCION}}' => trim((string)($seguimiento['nombre_entidad'] ?? '')),
            '{{ESTADO}}' => trim((string)($seguimiento['estado_nombre'] ?? '')),
            '{{ANALISTA_NOMBRE}}' => $analistaNombre,
            '{{ANALISTA_CORREO}}' => trim((string)($seguimiento['analista_correo'] ?? ''))
        ];

        $asuntoGuardado = trim((string)($seguimiento['asunto_correo'] ?? ''));
        $cuerpoGuardado = trim((string)($seguimiento['cuerpo_correo'] ?? ''));
        $guardado = $asuntoGuardado !== '' && $cuerpoGuardado !== '';
        $asuntoPlantilla = (string)($plantilla['asunto'] ?? '');
        $cuerpoPlantilla = (string)($plantilla['contenido'] ?? '');
        $asunto = $guardado
            ? $asuntoGuardado
            : strtr($asuntoPlantilla, $reemplazos);
        $cuerpo = $guardado
            ? $cuerpoGuardado
            : strtr($cuerpoPlantilla, $reemplazos);
        $rutaPdf = trim((string)($seguimiento['archivo_pdf'] ?? ''));
        $enviado = $this->correoYaEnviado($seguimiento);

        return [
            'oficio_id' => (int)($seguimiento['oficio_id'] ?? 0),
            'analista_id' => (int)($seguimiento['analista_id'] ?? 0),
            'folio' => (string)($seguimiento['folio'] ?? ''),
            'para' => (string)($seguimiento['destinatario_correo'] ?? ''),
            'destinatario_nombre' => (string)($seguimiento['destinatario_nombre'] ?? ''),
            'asunto' => $asunto,
            'cuerpo' => $cuerpo,
            'adjunto_nombre' => $rutaPdf !== '' ? basename($rutaPdf) : '',
            'pdf_generado' => $rutaPdf !== '',
            'guardado' => $guardado,
            'enviado' => $enviado,
            'fecha_envio' => (string)($seguimiento['fecha_envio'] ?? ''),
            'error_envio' => (string)($seguimiento['error_envio'] ?? ''),
            'plantilla' => (string)($plantilla['nombre'] ?? self::NOMBRE_PLANTILLA),
            'provisional' => false,
            'modo_prueba' => false
        ];
    }

    private function validarOficioParaCorreo($seguimiento)
    {
        $oficioId = (int)($seguimiento['oficio_id'] ?? 0);
        $folio = trim((string)($seguimiento['folio'] ?? ''));
        $correo = trim((string)($seguimiento['destinatario_correo'] ?? ''));
        $rutaPdf = trim((string)($seguimiento['archivo_pdf'] ?? ''));
        $estadoOficio = strtoupper(trim((string)($seguimiento['estado_oficio'] ?? '')));

        if ($oficioId <= 0 || $folio === '') {
            return $this->error(
                'Primero genera el oficio antes de preparar el correo.',
                422
            );
        }

        if (
            !in_array($estadoOficio, ['GENERADO', 'ERROR_ENVIO', 'ENVIADO'], true) ||
            $rutaPdf === ''
        ) {
            return $this->error(
                'Primero genera el PDF del oficio antes de preparar el correo.',
                422
            );
        }

        $rutaAbsoluta = $this->rutaAbsolutaPdf($rutaPdf);

        if ($rutaAbsoluta === '' || !is_file($rutaAbsoluta)) {
            return $this->error(
                'El PDF registrado no está disponible. Genera nuevamente el PDF antes de preparar el correo.',
                422
            );
        }

        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'El destinatario no tiene un correo verificado válido.',
                422
            );
        }

        return ['ok' => true];
    }

    private function obtenerSeguimientoAutorizado($seguimientoId, $usuarioId, $modoAcceso)
    {
        $sql = $this->consultaSeguimientoBase();

        if ($modoAcceso === 'administrador') {
            $sql .= "
                WHERE seguimientos.id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $seguimientoId);
        } elseif ($modoAcceso === 'supervisor') {
            $sql .= "
                INNER JOIN asignaciones_territorio asignacion_analista
                    ON asignacion_analista.usuario_id = seguimientos.analista_id
                    AND asignacion_analista.estado_id = seguimientos.estado_id
                    AND asignacion_analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignacion_analista.activo = 1
                    AND " . $this->condicionAsignacionVigente('asignacion_analista') . "
                INNER JOIN asignaciones_territorio cuenta_clave
                    ON cuenta_clave.id = asignacion_analista.cuenta_clave_asignacion_id
                    AND cuenta_clave.estado_id = seguimientos.estado_id
                    AND cuenta_clave.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuenta_clave.activo = 1
                    AND cuenta_clave.usuario_id = ?
                    AND " . $this->condicionAsignacionVigente('cuenta_clave') . "
                WHERE seguimientos.id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $usuarioId, $seguimientoId);
        } else {
            $sql .= "
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        }

        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function obtenerSeguimientoAnalista($seguimientoId, $usuarioId)
    {
        $sql = $this->consultaSeguimientoBase() . "
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function consultaSeguimientoBase()
    {
        return "SELECT DISTINCT
                    seguimientos.id,
                    seguimientos.nombre_entidad,
                    seguimientos.estado_id,
                    seguimientos.analista_id,
                    seguimientos.estado_seguimiento,
                    estados.nombre AS estado_nombre,
                    analista.nombre AS analista_nombre,
                    analista.apellidos AS analista_apellidos,
                    analista.correo AS analista_correo,
                    oficio.id AS oficio_id,
                    oficio.folio,
                    oficio.plantilla_correo_id,
                    oficio.destinatario_nombre,
                    oficio.destinatario_cargo,
                    oficio.destinatario_correo,
                    oficio.asunto_correo,
                    oficio.cuerpo_correo,
                    oficio.archivo_pdf,
                    oficio.estado_oficio,
                    oficio.fecha_envio,
                    oficio.error_envio
                FROM seguimientos_vinculacion seguimientos
                INNER JOIN estados
                    ON estados.id = seguimientos.estado_id
                INNER JOIN usuarios analista
                    ON analista.id = seguimientos.analista_id
                LEFT JOIN oficios_vinculacion oficio
                    ON oficio.id = (
                        SELECT oficio_reciente.id
                        FROM oficios_vinculacion oficio_reciente
                        WHERE oficio_reciente.seguimiento_id = seguimientos.id
                        ORDER BY oficio_reciente.id DESC
                        LIMIT 1
                    )";
    }

    private function obtenerOCrearPlantillaInstitucional()
    {
        $nombre = self::NOMBRE_PLANTILLA;
        $sqlBuscar = "SELECT id, nombre, asunto, contenido
                FROM plantillas_vinculacion
                WHERE tipo = 'CORREO'
                    AND nombre = ?
                    AND activo = 1
                ORDER BY id DESC
                LIMIT 1";
        $stmtBuscar = $this->connection->prepare($sqlBuscar);
        $stmtBuscar->bind_param('s', $nombre);
        $stmtBuscar->execute();
        $plantilla = $stmtBuscar->get_result()->fetch_assoc() ?: null;

        if ($plantilla) {
            return $plantilla;
        }

        $descripcion =
            'Plantilla institucional para acompañar el oficio de vinculación.';
        $asunto =
            'Invitación a vinculación institucional | Fundación Red Educativa México | {{FOLIO}}';
        $contenido = <<<'TEXTO'
Estimado/a {{DESTINATARIO_NOMBRE}}:

Espero se encuentre muy bien.

Mi nombre es {{ANALISTA_NOMBRE}} y me comunico de parte de la Fundación Red Educativa México. Adjunto encontrará el oficio {{FOLIO}}, mediante el cual nos gustaría establecer un primer acercamiento con {{INSTITUCION}} y proponer una reunión virtual de presentación.

El objetivo es compartir brevemente nuestras iniciativas, conocer los intereses de su institución e identificar posibles oportunidades de colaboración y vinculación.

Quedamos atentos a la fecha y horario que les resulte conveniente para realizar esta reunión.

Agradezco de antemano su atención.

Saludos cordiales,

{{ANALISTA_NOMBRE}}
Fundación Red Educativa México
{{ANALISTA_CORREO}}
TEXTO;

        $sqlInsertar = "INSERT INTO plantillas_vinculacion (
                    nombre,
                    tipo,
                    descripcion,
                    asunto,
                    contenido,
                    activo,
                    creado_por
                ) VALUES (?, 'CORREO', ?, ?, ?, 1, NULL)";
        $stmtInsertar = $this->connection->prepare($sqlInsertar);
        $stmtInsertar->bind_param(
            'ssss',
            $nombre,
            $descripcion,
            $asunto,
            $contenido
        );

        if (!$stmtInsertar->execute()) {
            return null;
        }

        return [
            'id' => (int)$this->connection->insert_id,
            'nombre' => $nombre,
            'asunto' => $asunto,
            'contenido' => $contenido
        ];
    }

    private function asignarPlantillaSiFalta($oficioId, $plantillaId)
    {
        $sql = "UPDATE oficios_vinculacion
                SET plantilla_correo_id = ?
                WHERE id = ?
                    AND plantilla_correo_id IS NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $plantillaId, $oficioId);
        $stmt->execute();
    }

    private function correoYaEnviado($seguimiento)
    {
        return strtoupper(trim((string)($seguimiento['estado_oficio'] ?? ''))) === 'ENVIADO' ||
            trim((string)($seguimiento['fecha_envio'] ?? '')) !== '';
    }

    private function rutaAbsolutaPdf($rutaPdf)
    {
        $rutaPdf = trim((string)$rutaPdf);

        if ($rutaPdf === '') {
            return '';
        }

        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($rutaPdf, '/\\'));
    }

    private function configCorreo($nombre, $default = '')
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

    private function registrarErrorEnvio($oficioId, $mensaje)
    {
        $oficioId = (int)$oficioId;

        if ($oficioId <= 0) {
            return;
        }

        $mensaje = trim((string)$mensaje);

        if (mb_strlen($mensaje) > 1000) {
            $mensaje = mb_substr($mensaje, 0, 1000);
        }

        $sql = "UPDATE oficios_vinculacion
                SET estado_oficio = 'ERROR_ENVIO',
                    error_envio = ?
                WHERE id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('si', $mensaje, $oficioId);
        $stmt->execute();
    }

    private function condicionAsignacionVigente($alias)
    {
        return "(
            ($alias.fecha_inicio IS NULL OR $alias.fecha_inicio <= CURDATE())
            AND ($alias.fecha_fin IS NULL OR $alias.fecha_fin >= CURDATE())
        )";
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
