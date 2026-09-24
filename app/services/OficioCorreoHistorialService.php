<?php

require_once __DIR__ . '/../../config/db_connection.php';

class OficioCorreoHistorialService
{
    private $connection;
    private $tablaDisponible = null;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerHistorial($seguimientoId, $usuarioId, $modoAcceso)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        if (!$this->tieneAccesoSeguimiento($seguimientoId, $usuarioId, $modoAcceso)) {
            return [
                'ok' => false,
                'mensaje' => 'No tienes acceso a este seguimiento.',
                'codigo_http' => 403
            ];
        }

        try {
            $correosOficio = $this->tablaHistorialDisponible()
                ? $this->consultarHistorialPersistente($seguimientoId)
                : $this->consultarHistorialLegado($seguimientoId);
            $correosSeguimiento = $this->consultarCorreosSeguimiento($seguimientoId);
            $correos = array_merge($correosOficio, $correosSeguimiento);

            usort($correos, static function ($correoA, $correoB) {
                $fechaA = strtotime((string)($correoA['fecha_envio'] ?? '')) ?: 0;
                $fechaB = strtotime((string)($correoB['fecha_envio'] ?? '')) ?: 0;

                if ($fechaA === $fechaB) {
                    return (int)($correoB['id'] ?? 0) <=> (int)($correoA['id'] ?? 0);
                }

                return $fechaB <=> $fechaA;
            });

            return [
                'ok' => true,
                'correos' => $correos,
                'total' => count($correos),
                'historial_persistente' => $this->tablaHistorialDisponible()
            ];
        } catch (Throwable $error) {
            error_log('Historial de correos de oficio: ' . $error->getMessage());

            return [
                'ok' => false,
                'mensaje' => 'No fue posible consultar el historial de correos.',
                'codigo_http' => 500
            ];
        }
    }

    public function registrarEnvio($seguimientoId, $usuarioId, $correo)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $correo = is_array($correo) ? $correo : [];

        if (!$this->tablaHistorialDisponible()) {
            return false;
        }

        $oficioId = (int)($correo['oficio_id'] ?? 0);
        $destinatario = trim((string)($correo['para'] ?? ''));
        $asunto = trim((string)($correo['asunto'] ?? ''));
        $cuerpo = (string)($correo['cuerpo'] ?? '');
        $fechaEnvio = trim((string)($correo['fecha_envio'] ?? ''));

        if (
            $seguimientoId <= 0 ||
            $oficioId <= 0 ||
            $destinatario === '' ||
            $asunto === '' ||
            $fechaEnvio === ''
        ) {
            return false;
        }

        $folio = trim((string)($correo['folio'] ?? ''));
        $destinatarioNombre = trim((string)($correo['destinatario_nombre'] ?? ''));
        $adjuntoNombre = trim((string)($correo['adjunto_nombre'] ?? ''));

        try {
            $sql = "INSERT IGNORE INTO correos_oficio_vinculacion (
                        seguimiento_id,
                        oficio_id,
                        usuario_id,
                        folio,
                        destinatario,
                        destinatario_nombre,
                        asunto,
                        cuerpo,
                        adjunto_nombre,
                        estado,
                        error_envio,
                        fecha_envio,
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ENVIADO', NULL, ?, ?)";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'iiissssssss',
                $seguimientoId,
                $oficioId,
                $usuarioId,
                $folio,
                $destinatario,
                $destinatarioNombre,
                $asunto,
                $cuerpo,
                $adjuntoNombre,
                $fechaEnvio,
                $fechaEnvio
            );

            return $stmt->execute();
        } catch (Throwable $error) {
            /*
             * La bitácora nunca debe convertir un correo ya enviado en un
             * error operativo para el usuario.
             */
            error_log('No fue posible registrar el correo enviado en la bitácora: ' . $error->getMessage());
            return false;
        }
    }

    private function consultarHistorialPersistente($seguimientoId)
    {
        $sql = "SELECT
                    historial.id,
                    historial.seguimiento_id,
                    historial.oficio_id,
                    historial.folio,
                    historial.destinatario,
                    historial.destinatario_nombre,
                    historial.asunto,
                    historial.cuerpo,
                    historial.adjunto_nombre,
                    historial.estado,
                    historial.error_envio,
                    historial.fecha_envio,
                    historial.created_at,
                    TRIM(CONCAT(COALESCE(usuario.nombre, ''), ' ', COALESCE(usuario.apellidos, ''))) AS enviado_por_nombre
                FROM correos_oficio_vinculacion historial
                LEFT JOIN usuarios usuario
                    ON usuario.id = historial.usuario_id
                WHERE historial.seguimiento_id = ?
                ORDER BY COALESCE(historial.fecha_envio, historial.created_at) DESC,
                    historial.id DESC";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $correos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $correos[] = $this->normalizarCorreo($fila);
        }

        return $correos;
    }

    private function consultarCorreosSeguimiento($seguimientoId)
    {
        if (!$this->tablaExiste('seguimientos_vinculacion_correos')) {
            return [];
        }

        $tieneAdjuntos = $this->tablaExiste(
            'seguimientos_vinculacion_correo_adjuntos'
        );
        $joinAdjuntos = $tieneAdjuntos
            ? "LEFT JOIN (
                    SELECT interaccion_id,
                        COUNT(*) AS adjuntos_count,
                        GROUP_CONCAT(nombre_original ORDER BY id SEPARATOR ' · ') AS adjuntos_nombres
                    FROM seguimientos_vinculacion_correo_adjuntos
                    GROUP BY interaccion_id
                ) adjuntos ON adjuntos.interaccion_id = correo.interaccion_id"
            : '';
        $camposAdjuntos = $tieneAdjuntos
            ? "COALESCE(adjuntos.adjuntos_count, correo.adjuntos_count, 0) AS adjuntos_count,
               COALESCE(adjuntos.adjuntos_nombres, '') AS adjuntos_nombres,"
            : "COALESCE(correo.adjuntos_count, 0) AS adjuntos_count,
               '' AS adjuntos_nombres,";

        $sql = "SELECT
                    correo.id,
                    correo.interaccion_id,
                    correo.destinatario,
                    correo.asunto,
                    correo.cuerpo,
                    correo.enviado_at,
                    " . $camposAdjuntos . "
                    TRIM(CONCAT(COALESCE(usuario.nombre, ''), ' ', COALESCE(usuario.apellidos, ''))) AS enviado_por_nombre,
                    COALESCE(seguimiento.contacto_nombre, '') AS destinatario_nombre
                FROM seguimientos_vinculacion_correos correo
                INNER JOIN seguimientos_vinculacion seguimiento
                    ON seguimiento.id = correo.seguimiento_id
                LEFT JOIN usuarios usuario
                    ON usuario.id = correo.usuario_id
                " . $joinAdjuntos . "
                WHERE correo.seguimiento_id = ?
                ORDER BY correo.enviado_at DESC, correo.id DESC";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $correos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $adjuntosCount = (int)($fila['adjuntos_count'] ?? 0);
            $nombres = trim((string)($fila['adjuntos_nombres'] ?? ''));

            $correos[] = [
                'id' => (int)($fila['id'] ?? 0),
                'interaccion_id' => (int)($fila['interaccion_id'] ?? 0),
                'origen' => 'SEGUIMIENTO',
                'oficio_id' => 0,
                'folio' => '',
                'destinatario' => trim((string)($fila['destinatario'] ?? '')),
                'destinatario_nombre' => trim((string)($fila['destinatario_nombre'] ?? '')),
                'asunto' => trim((string)($fila['asunto'] ?? '')),
                'cuerpo' => (string)($fila['cuerpo'] ?? ''),
                'adjunto_nombre' => $adjuntosCount === 1
                    ? $nombres
                    : ($adjuntosCount > 1 ? $adjuntosCount . ' archivos' : ''),
                'adjuntos_count' => $adjuntosCount,
                'estado' => 'ENVIADO',
                'error_envio' => '',
                'fecha_envio' => trim((string)($fila['enviado_at'] ?? '')),
                'enviado_por' => trim((string)($fila['enviado_por_nombre'] ?? ''))
            ];
        }

        return $correos;
    }

    private function tablaExiste($tabla)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', (string)$tabla);

        try {
            $resultado = $this->connection->query(
                "SHOW TABLES LIKE '" . $tabla . "'"
            );
            return $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            return false;
        }
    }

    private function consultarHistorialLegado($seguimientoId)
    {
        $sql = "SELECT
                    oficio.id AS id,
                    oficio.seguimiento_id,
                    oficio.id AS oficio_id,
                    oficio.folio,
                    COALESCE(oficio.destinatario_correo, '') AS destinatario,
                    COALESCE(oficio.destinatario_nombre, '') AS destinatario_nombre,
                    COALESCE(oficio.asunto_correo, '') AS asunto,
                    COALESCE(oficio.cuerpo_correo, '') AS cuerpo,
                    SUBSTRING_INDEX(COALESCE(oficio.archivo_pdf, ''), '/', -1) AS adjunto_nombre,
                    'ENVIADO' AS estado,
                    NULL AS error_envio,
                    oficio.fecha_envio,
                    oficio.fecha_envio AS created_at,
                    TRIM(CONCAT(COALESCE(usuario.nombre, ''), ' ', COALESCE(usuario.apellidos, ''))) AS enviado_por_nombre
                FROM oficios_vinculacion oficio
                LEFT JOIN usuarios usuario
                    ON usuario.id = oficio.enviado_por
                WHERE oficio.seguimiento_id = ?
                  AND oficio.fecha_envio IS NOT NULL
                ORDER BY oficio.fecha_envio DESC, oficio.id DESC";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $correos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $correos[] = $this->normalizarCorreo($fila);
        }

        return $correos;
    }

    private function normalizarCorreo($fila)
    {
        return [
            'id' => (int)($fila['id'] ?? 0),
            'interaccion_id' => 0,
            'origen' => 'OFICIO',
            'oficio_id' => (int)($fila['oficio_id'] ?? 0),
            'folio' => trim((string)($fila['folio'] ?? '')),
            'destinatario' => trim((string)($fila['destinatario'] ?? '')),
            'destinatario_nombre' => trim((string)($fila['destinatario_nombre'] ?? '')),
            'asunto' => trim((string)($fila['asunto'] ?? '')),
            'cuerpo' => (string)($fila['cuerpo'] ?? ''),
            'adjunto_nombre' => trim((string)($fila['adjunto_nombre'] ?? '')),
            'estado' => strtoupper(trim((string)($fila['estado'] ?? 'ENVIADO'))),
            'error_envio' => trim((string)($fila['error_envio'] ?? '')),
            'fecha_envio' => trim((string)($fila['fecha_envio'] ?? '')),
            'enviado_por' => trim((string)($fila['enviado_por_nombre'] ?? ''))
        ];
    }

    private function tablaHistorialDisponible()
    {
        if ($this->tablaDisponible !== null) {
            return $this->tablaDisponible;
        }

        try {
            $resultado = $this->connection->query(
                "SHOW TABLES LIKE 'correos_oficio_vinculacion'"
            );
            $this->tablaDisponible = $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            $this->tablaDisponible = false;
        }

        return $this->tablaDisponible;
    }

    private function tieneAccesoSeguimiento($seguimientoId, $usuarioId, $modoAcceso)
    {
        if ($seguimientoId <= 0 || $usuarioId <= 0) {
            return false;
        }

        if ($modoAcceso === 'administrador') {
            $sql = "SELECT id
                    FROM seguimientos_vinculacion
                    WHERE id = ?
                      AND activo = 1
                    LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $seguimientoId);
        } elseif ($modoAcceso === 'supervisor') {
            $sql = "SELECT seguimientos.id
                    FROM seguimientos_vinculacion seguimientos
                    INNER JOIN asignaciones_territorio asignacion_analista
                        ON asignacion_analista.usuario_id = seguimientos.analista_id
                        AND asignacion_analista.estado_id = seguimientos.estado_id
                        AND asignacion_analista.tipo_asignacion = 'ANALISTA_DATOS'
                        AND asignacion_analista.activo = 1
                        AND (asignacion_analista.fecha_inicio IS NULL OR asignacion_analista.fecha_inicio <= CURDATE())
                        AND (asignacion_analista.fecha_fin IS NULL OR asignacion_analista.fecha_fin >= CURDATE())
                    INNER JOIN asignaciones_territorio cuenta_clave
                        ON cuenta_clave.id = asignacion_analista.cuenta_clave_asignacion_id
                        AND cuenta_clave.estado_id = seguimientos.estado_id
                        AND cuenta_clave.tipo_asignacion = 'CUENTA_CLAVE'
                        AND cuenta_clave.activo = 1
                        AND cuenta_clave.usuario_id = ?
                        AND (cuenta_clave.fecha_inicio IS NULL OR cuenta_clave.fecha_inicio <= CURDATE())
                        AND (cuenta_clave.fecha_fin IS NULL OR cuenta_clave.fecha_fin >= CURDATE())
                    WHERE seguimientos.id = ?
                      AND seguimientos.activo = 1
                    LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $usuarioId, $seguimientoId);
        } else {
            $sql = "SELECT id
                    FROM seguimientos_vinculacion
                    WHERE id = ?
                      AND analista_id = ?
                      AND activo = 1
                    LIMIT 1";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        }

        $stmt->execute();

        return (bool)$stmt->get_result()->fetch_assoc();
    }
}
