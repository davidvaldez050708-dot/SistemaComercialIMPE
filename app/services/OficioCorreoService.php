<?php

require_once __DIR__ . '/../../config/db_connection.php';

class OficioCorreoService
{
    private const NOMBRE_PLANTILLA =
        'Correo institucional provisional - Fundación Red Educativa México';

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

        $plantilla = $this->obtenerOCrearPlantillaProvisional();

        if (!$plantilla) {
            return $this->error(
                'No fue posible preparar la plantilla provisional del correo.',
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

        $plantilla = $this->obtenerOCrearPlantillaProvisional();

        if (!$plantilla) {
            return $this->error(
                'No fue posible preparar la plantilla provisional del correo.',
                500
            );
        }

        $oficioId = (int)$seguimiento['oficio_id'];
        $plantillaId = (int)$plantilla['id'];
        $sql = "UPDATE oficios_vinculacion
                SET plantilla_correo_id = ?,
                    asunto_correo = ?,
                    cuerpo_correo = ?
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

        return [
            'ok' => true,
            'mensaje' => 'Borrador de correo guardado correctamente.',
            'correo' => $this->construirCorreo(
                $seguimiento,
                $plantilla
            )
        ];
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
        $asunto = $guardado
            ? $asuntoGuardado
            : strtr((string)($plantilla['asunto'] ?? ''), $reemplazos);
        $cuerpo = $guardado
            ? $cuerpoGuardado
            : strtr((string)($plantilla['contenido'] ?? ''), $reemplazos);
        $rutaPdf = trim((string)($seguimiento['archivo_pdf'] ?? ''));

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
            'plantilla' => (string)($plantilla['nombre'] ?? self::NOMBRE_PLANTILLA),
            'provisional' => true,
            'modo_prueba' => true
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

        if (!in_array($estadoOficio, ['GENERADO', 'ENVIADO'], true) || $rutaPdf === '') {
            return $this->error(
                'Primero genera el PDF del oficio antes de preparar el correo.',
                422
            );
        }

        $rutaAbsoluta = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($rutaPdf, '/\\'));

        if (!is_file($rutaAbsoluta)) {
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
                    oficio.estado_oficio
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

    private function obtenerOCrearPlantillaProvisional()
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
            'Plantilla provisional para acompañar el oficio institucional durante las pruebas del prototipo.';
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
