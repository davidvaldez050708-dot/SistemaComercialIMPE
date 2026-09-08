<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReminderCorreoEntranteService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtener($usuarioId, $rolId, $limite = 10)
    {
        $usuarioId = (int)$usuarioId;
        $rolId = (int)$rolId;
        $limite = max(1, min(20, (int)$limite));

        if ($usuarioId <= 0 || !in_array($rolId, [4, 6], true) || !$this->tablaDisponible()) {
            return [
                'recordatorios' => [],
                'avisos' => []
            ];
        }

        $sql = "SELECT
                    correo.id AS respuesta_id,
                    correo.seguimiento_id,
                    correo.reunion_id,
                    correo.contexto,
                    correo.remitente,
                    correo.asunto,
                    correo.vista_previa,
                    correo.recibido_at,
                    seguimiento.nombre_entidad,
                    destinatario.notificado_at
                FROM correo_respuestas_destinatarios destinatario
                INNER JOIN correo_respuestas_entrantes correo
                    ON correo.id = destinatario.respuesta_id
                LEFT JOIN seguimientos_vinculacion seguimiento
                    ON seguimiento.id = correo.seguimiento_id
                WHERE destinatario.usuario_id = ?
                    AND destinatario.leido_at IS NULL
                    AND correo.estado = 'PENDIENTE'
                ORDER BY correo.recibido_at DESC, correo.id DESC
                LIMIT ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $usuarioId, $limite);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $recordatorios = [];
        $avisos = [];
        $idsAvisados = [];

        while ($fila = $resultado->fetch_assoc()) {
            $respuestaId = (int)$fila['respuesta_id'];
            $seguimientoId = (int)($fila['seguimiento_id'] ?? 0);
            $contexto = strtoupper(trim((string)($fila['contexto'] ?? 'OFERTA')));
            $nombreEntidad = trim((string)($fila['nombre_entidad'] ?? ''));
            if ($nombreEntidad === '') {
                $nombreEntidad = trim((string)($fila['remitente'] ?? 'Institución'));
            }

            $accion = $contexto === 'REUNION'
                ? 'Nueva respuesta al correo de reunión'
                : 'Nueva respuesta de la institución';
            $url = 'index.php?controller=reminder&action=correoEntrante&id=' . $respuestaId;

            $recordatorios[] = [
                'id' => $seguimientoId,
                'seguimiento_id' => $seguimientoId,
                'respuesta_id' => $respuestaId,
                'nombre_entidad' => $nombreEntidad,
                'accion' => $accion,
                'fecha' => (string)($fila['recibido_at'] ?? ''),
                'etiqueta' => 'Nueva respuesta',
                'estado' => 'hoy',
                'icono' => 'bi-envelope-exclamation',
                'url' => $url
            ];

            if (trim((string)($fila['notificado_at'] ?? '')) === '') {
                $preview = trim((string)($fila['vista_previa'] ?? ''));
                if ($preview === '') {
                    $preview = trim((string)($fila['asunto'] ?? ''));
                }
                if ($preview === '') {
                    $preview = 'La institución respondió un correo del seguimiento.';
                }

                $avisos[] = [
                    'id' => $seguimientoId,
                    'seguimiento_id' => $seguimientoId,
                    'respuesta_id' => $respuestaId,
                    'tipo' => 'CORREO_ENTRANTE',
                    'titulo' => $contexto === 'REUNION'
                        ? 'Respuesta sobre reunión'
                        : 'Nueva respuesta de correo',
                    'mensaje' => $nombreEntidad . ': ' . mb_substr($preview, 0, 180, 'UTF-8'),
                    'icono' => 'bi-envelope-exclamation',
                    'url' => $url
                ];
                $idsAvisados[] = $respuestaId;
            }
        }

        if (!empty($idsAvisados)) {
            $this->marcarNotificados($usuarioId, $idsAvisados);
        }

        return [
            'recordatorios' => $recordatorios,
            'avisos' => $avisos
        ];
    }

    private function marcarNotificados($usuarioId, $ids)
    {
        $sql = "UPDATE correo_respuestas_destinatarios
                SET notificado_at = COALESCE(notificado_at, NOW())
                WHERE respuesta_id = ? AND usuario_id = ?";
        $stmt = $this->connection->prepare($sql);

        foreach ($ids as $respuestaId) {
            $respuesta = (int)$respuestaId;
            $usuario = (int)$usuarioId;
            $stmt->bind_param('ii', $respuesta, $usuario);
            $stmt->execute();
        }
    }

    private function tablaDisponible()
    {
        foreach (['correo_respuestas_entrantes', 'correo_respuestas_destinatarios'] as $tabla) {
            $resultado = $this->connection->query("SHOW TABLES LIKE '" . $tabla . "'");
            if (!$resultado || $resultado->num_rows === 0) {
                return false;
            }
        }

        return true;
    }
}
