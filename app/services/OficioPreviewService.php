<?php

require_once __DIR__ . '/../../config/db_connection.php';

class OficioPreviewService
{
    private const NOMBRE_PLANTILLA =
        'Oficio institucional provisional - Fundación Red Educativa México';

    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerVistaPrevia($seguimientoId, $usuarioId, $modoAcceso)
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

        $oficioId = (int)($seguimiento['oficio_id'] ?? 0);
        $folio = trim((string)($seguimiento['folio'] ?? ''));

        if ($oficioId <= 0 || $folio === '') {
            return $this->error(
                'Primero genera el oficio para poder consultar su vista previa.',
                422
            );
        }

        $plantilla = $this->obtenerOCrearPlantillaProvisional();

        if (!$plantilla) {
            return $this->error(
                'No fue posible preparar la plantilla provisional del oficio.',
                500
            );
        }

        $this->asignarPlantillaSiFalta(
            $oficioId,
            (int)$plantilla['id']
        );

        $fecha = $this->formatearFechaOficio(
            $seguimiento['oficio_created_at'] ?? ''
        );
        $analistaNombre = trim(
            (string)($seguimiento['analista_nombre'] ?? '') . ' ' .
            (string)($seguimiento['analista_apellidos'] ?? '')
        );

        $reemplazos = [
            '{{FOLIO}}' => $folio,
            '{{FECHA}}' => $fecha,
            '{{DESTINATARIO_NOMBRE}}' => trim((string)($seguimiento['destinatario_nombre'] ?? '')),
            '{{DESTINATARIO_CARGO}}' => trim((string)($seguimiento['destinatario_cargo'] ?? '')),
            '{{INSTITUCION}}' => trim((string)($seguimiento['nombre_entidad'] ?? '')),
            '{{ESTADO}}' => trim((string)($seguimiento['estado_nombre'] ?? '')),
            '{{ANALISTA_NOMBRE}}' => $analistaNombre,
            '{{ANALISTA_CORREO}}' => trim((string)($seguimiento['analista_correo'] ?? ''))
        ];

        return [
            'ok' => true,
            'vista_previa' => [
                'oficio_id' => $oficioId,
                'folio' => $folio,
                'fecha' => $fecha,
                'asunto' => (string)($plantilla['asunto'] ?? ''),
                'contenido' => strtr(
                    (string)($plantilla['contenido'] ?? ''),
                    $reemplazos
                ),
                'institucion' => (string)($seguimiento['nombre_entidad'] ?? ''),
                'estado' => (string)($seguimiento['estado_nombre'] ?? ''),
                'destinatario_nombre' => (string)($seguimiento['destinatario_nombre'] ?? ''),
                'destinatario_cargo' => (string)($seguimiento['destinatario_cargo'] ?? ''),
                'destinatario_correo' => (string)($seguimiento['destinatario_correo'] ?? ''),
                'analista_nombre' => $analistaNombre,
                'analista_correo' => (string)($seguimiento['analista_correo'] ?? ''),
                'estado_oficio' => (string)($seguimiento['estado_oficio'] ?? ''),
                'plantilla' => (string)($plantilla['nombre'] ?? self::NOMBRE_PLANTILLA),
                'provisional' => true
            ]
        ];
    }

    private function obtenerSeguimientoAutorizado($seguimientoId, $usuarioId, $modoAcceso)
    {
        $sql = "SELECT DISTINCT
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
                    oficio.plantilla_oficio_id,
                    oficio.destinatario_nombre,
                    oficio.destinatario_cargo,
                    oficio.destinatario_correo,
                    oficio.estado_oficio,
                    oficio.created_at AS oficio_created_at
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

    private function obtenerOCrearPlantillaProvisional()
    {
        $nombre = self::NOMBRE_PLANTILLA;
        $sqlBuscar = "SELECT id, nombre, asunto, contenido
                FROM plantillas_vinculacion
                WHERE tipo = 'OFICIO'
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
            'Plantilla provisional para el primer acercamiento institucional del prototipo.';
        $asunto = 'Invitación a vinculación institucional';
        $contenido = <<<'TEXTO'
{{DESTINATARIO_NOMBRE}}
{{DESTINATARIO_CARGO}}
{{INSTITUCION}}
Presente.

Por medio de la presente, reciba un cordial saludo.

Nos ponemos en contacto en representación de la Fundación Red Educativa México, con el propósito de establecer un primer acercamiento con {{INSTITUCION}} y explorar oportunidades de colaboración y vinculación que puedan resultar de interés para ambas partes.

Como parte de nuestras actividades, buscamos generar espacios de comunicación con instituciones y organizaciones de {{ESTADO}}, con la finalidad de presentar nuestras iniciativas, conocer sus áreas de interés e identificar posibles oportunidades de colaboración.

Por lo anterior, nos gustaría proponer una reunión virtual de presentación, en la cual podamos compartir mayor información sobre la Fundación Red Educativa México y conocer también las necesidades e intereses de su institución.

Agradecemos de antemano su atención y quedamos pendientes para acordar una fecha y horario que resulten convenientes.

Sin otro particular, reciba un cordial saludo.

Atentamente

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
                ) VALUES (?, 'OFICIO', ?, ?, ?, 1, NULL)";
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
                SET plantilla_oficio_id = ?
                WHERE id = ?
                    AND plantilla_oficio_id IS NULL";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $plantillaId, $oficioId);
        $stmt->execute();
    }

    private function formatearFechaOficio($fecha)
    {
        $meses = [
            1 => 'enero',
            2 => 'febrero',
            3 => 'marzo',
            4 => 'abril',
            5 => 'mayo',
            6 => 'junio',
            7 => 'julio',
            8 => 'agosto',
            9 => 'septiembre',
            10 => 'octubre',
            11 => 'noviembre',
            12 => 'diciembre'
        ];

        try {
            $fechaObjeto = trim((string)$fecha) !== ''
                ? new DateTime((string)$fecha)
                : new DateTime();
        } catch (Exception $error) {
            $fechaObjeto = new DateTime();
        }

        return sprintf(
            '%d de %s de %d',
            (int)$fechaObjeto->format('j'),
            $meses[(int)$fechaObjeto->format('n')] ?? '',
            (int)$fechaObjeto->format('Y')
        );
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
