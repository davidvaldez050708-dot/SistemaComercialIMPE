<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReminderObservacionService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtener($analistaId, $limite = 10)
    {
        $analistaId = (int)$analistaId;
        $limite = max(1, min(20, (int)$limite));

        if ($analistaId <= 0) {
            return [
                'recordatorios' => [],
                'avisos' => []
            ];
        }

        try {
            $sql = "SELECT
                        observaciones.id,
                        observaciones.seguimiento_id,
                        observaciones.created_at,
                        seguimientos.nombre_entidad,
                        usuarios.nombre,
                        usuarios.apellidos
                    FROM observaciones_seguimiento observaciones
                    INNER JOIN seguimientos_vinculacion seguimientos
                        ON seguimientos.id = observaciones.seguimiento_id
                    INNER JOIN usuarios
                        ON usuarios.id = observaciones.autor_id
                    WHERE observaciones.destinatario_id = ?
                        AND observaciones.leida = 0
                        AND observaciones.activo = 1
                        AND seguimientos.analista_id = ?
                        AND seguimientos.activo = 1
                    ORDER BY observaciones.created_at DESC, observaciones.id DESC
                    LIMIT $limite";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('ii', $analistaId, $analistaId);
            $stmt->execute();
            $resultado = $stmt->get_result();

            $recordatorios = [];
            $avisos = [];
            $mostradas = is_array($_SESSION['recordatorios_observaciones_mostradas'] ?? null)
                ? $_SESSION['recordatorios_observaciones_mostradas']
                : [];

            while ($fila = $resultado->fetch_assoc()) {
                $observacionId = (int)($fila['id'] ?? 0);
                $seguimientoId = (int)($fila['seguimiento_id'] ?? 0);
                $nombreEntidad = trim((string)($fila['nombre_entidad'] ?? ''));
                $autor = trim(
                    (string)($fila['nombre'] ?? '') . ' ' .
                    (string)($fila['apellidos'] ?? '')
                );
                $fecha = trim((string)($fila['created_at'] ?? ''));
                $url = 'index.php?controller=seguimientoVinculacion&action=detalle&id=' .
                    $seguimientoId;

                if ($autor === '') {
                    $autor = 'Cuenta Clave';
                }

                if ($nombreEntidad === '') {
                    $nombreEntidad = 'Seguimiento';
                }

                $recordatorios[] = [
                    'id' => $seguimientoId,
                    'seguimiento_id' => $seguimientoId,
                    'observacion_id' => $observacionId,
                    'nombre_entidad' => $nombreEntidad,
                    'accion' => $autor . ' te dejó una observación',
                    'fecha' => $fecha,
                    'etiqueta' => 'Nueva',
                    'estado' => 'normal',
                    'icono' => 'bi-chat-left-text',
                    'url' => $url
                ];

                if ($observacionId > 0 && empty($mostradas[$observacionId])) {
                    $avisos[] = [
                        'id' => $seguimientoId,
                        'seguimiento_id' => $seguimientoId,
                        'observacion_id' => $observacionId,
                        'tipo' => 'OBSERVACION',
                        'titulo' => 'Nueva observación de Cuenta Clave',
                        'mensaje' => $autor . ' te dejó una observación en ' . $nombreEntidad . '.',
                        'icono' => 'bi-chat-left-text',
                        'url' => $url
                    ];
                    $mostradas[$observacionId] = time();
                }
            }

            if (count($mostradas) > 100) {
                arsort($mostradas);
                $mostradas = array_slice($mostradas, 0, 100, true);
            }

            $_SESSION['recordatorios_observaciones_mostradas'] = $mostradas;

            return [
                'recordatorios' => $recordatorios,
                'avisos' => $avisos
            ];
        } catch (Throwable $error) {
            error_log(
                'No fue posible consultar observaciones pendientes: ' .
                $error->getMessage()
            );

            return [
                'recordatorios' => [],
                'avisos' => []
            ];
        }
    }
}
