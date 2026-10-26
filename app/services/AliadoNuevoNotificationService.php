<?php

require_once __DIR__ . '/../../config/db_connection.php';

/** Novedades personales de aliados formalizados. No crea ni reasigna aliados. */
class AliadoNuevoNotificationService
{
    private $connection;

    public function __construct($connection = null)
    {
        $this->connection = $connection ?: (new Database())->connect();
    }

    public function obtener($usuarioId, $limite = 10)
    {
        $salida = ['ok' => true, 'requiere_migracion' => false, 'recordatorios' => [], 'avisos' => []];
        $usuarioId = (int)$usuarioId;
        $limite = max(1, min(30, (int)$limite));
        if ($usuarioId <= 0) {
            return $salida;
        }
        try {
            if (!$this->tablaDisponible('aliados_asignaciones')) {
                return $salida;
            }
            if (!$this->tablaDisponible('aliados_novedades_lecturas')) {
                $salida['ok'] = false;
                $salida['requiere_migracion'] = true;
                return $salida;
            }
            $sql = "SELECT a.id, a.seguimiento_id, a.asignado_at,
                           p.convenio_formalizado_at, s.nombre_entidad, s.estado_id
                    FROM aliados_asignaciones a
                    INNER JOIN seguimientos_vinculacion s ON s.id = a.seguimiento_id
                    INNER JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id = s.id
                    LEFT JOIN aliados_novedades_lecturas l
                      ON l.asignacion_id = a.id AND l.usuario_id = a.cuenta_clave_usuario_id
                     AND l.asignado_at = a.asignado_at AND l.formalizado_at = p.convenio_formalizado_at
                    WHERE a.cuenta_clave_usuario_id = ? AND a.activo = 1 AND s.activo = 1
                      AND p.convenio_formalizado_at IS NOT NULL AND l.asignacion_id IS NULL
                    ORDER BY a.asignado_at DESC, a.id DESC LIMIT {$limite}";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $usuarioId);
            $stmt->execute();
            $resultado = $stmt->get_result();
            $mostrados = is_array($_SESSION['aliados_nuevos_toasts'] ?? null)
                ? $_SESSION['aliados_nuevos_toasts'] : [];
            while ($fila = $resultado->fetch_assoc()) {
                $nombre = trim((string)$fila['nombre_entidad']) ?: 'Aliado institucional';
                $clave = hash('sha256', $fila['id'] . '|' . $fila['asignado_at'] .
                    '|' . $fila['convenio_formalizado_at']);
                $item = [
                    'id' => (int)$fila['seguimiento_id'],
                    'seguimiento_id' => (int)$fila['seguimiento_id'],
                    'aliado_asignacion_id' => (int)$fila['id'],
                    'aviso_aliado_clave' => $clave,
                    'nombre_entidad' => $nombre,
                    'accion' => 'Nuevo aliado asignado · consultar institución',
                    'fecha' => (string)$fila['asignado_at'],
                    'estado' => 'normal', 'etiqueta' => 'Nuevo aliado',
                    'icono' => 'bi-person-plus', 'prioridad' => 1,
                    'url' => 'index.php?controller=aliado&action=estado&estado_id=' .
                        (int)$fila['estado_id'] . '&aviso_aliado=' . (int)$fila['id']
                ];
                $salida['recordatorios'][] = $item;
                if (!isset($mostrados[$usuarioId . '|' . $clave])) {
                    $salida['avisos'][] = array_merge($item, [
                        'tipo' => 'ALIADO_NUEVO', 'titulo' => 'Nuevo aliado institucional',
                        'mensaje' => $nombre . ' se incorporó a tus aliados. Consulta su información.'
                    ]);
                }
            }
        } catch (Throwable $error) {
            error_log('[aliados_novedades] ' . get_class($error));
            $salida['ok'] = false;
        }
        return $salida;
    }

    /** No consumir eventos excluidos por el límite global de la campana. */
    public function registrarEntregados($usuarioId, array $recordatorios)
    {
        $mostrados = is_array($_SESSION['aliados_nuevos_toasts'] ?? null)
            ? $_SESSION['aliados_nuevos_toasts'] : [];
        foreach ($recordatorios as $item) {
            $clave = (string)($item['aviso_aliado_clave'] ?? '');
            if ((int)$usuarioId > 0 && (int)($item['aliado_asignacion_id'] ?? 0) > 0 &&
                preg_match('/^[a-f0-9]{64}$/', $clave)) {
                $mostrados[(int)$usuarioId . '|' . $clave] = time();
            }
        }
        arsort($mostrados);
        $_SESSION['aliados_nuevos_toasts'] = array_slice($mostrados, 0, 200, true);
    }

    /** Se llama únicamente después de autorizar el territorio y cargar el aliado. */
    public function marcarLeido($asignacionId, $usuarioId)
    {
        $asignacionId = (int)$asignacionId;
        $usuarioId = (int)$usuarioId;
        if ($asignacionId <= 0 || $usuarioId <= 0) {
            return false;
        }
        try {
            if (!$this->tablaDisponible('aliados_novedades_lecturas')) {
                return false;
            }
            $ahora = date('Y-m-d H:i:s');
            $sql = "INSERT INTO aliados_novedades_lecturas
                        (asignacion_id, usuario_id, asignado_at, formalizado_at, leida_at)
                    SELECT a.id, a.cuenta_clave_usuario_id, a.asignado_at, p.convenio_formalizado_at, ?
                    FROM aliados_asignaciones a
                    INNER JOIN seguimientos_vinculacion s ON s.id = a.seguimiento_id
                    INNER JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id = s.id
                    WHERE a.id = ? AND a.cuenta_clave_usuario_id = ?
                      AND a.activo = 1 AND s.activo = 1 AND p.convenio_formalizado_at IS NOT NULL
                    ON DUPLICATE KEY UPDATE leida_at = leida_at";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('sii', $ahora, $asignacionId, $usuarioId);
            return $stmt->execute();
        } catch (Throwable $error) {
            error_log('[aliados_novedades_lectura] ' . get_class($error));
            return false;
        }
    }

    private function tablaDisponible($tabla)
    {
        // Nombres internos constantes, nunca procedentes de la solicitud.
        $resultado = $this->connection->query("SHOW TABLES LIKE '" . $tabla . "'");
        return $resultado && $resultado->num_rows > 0;
    }
}
