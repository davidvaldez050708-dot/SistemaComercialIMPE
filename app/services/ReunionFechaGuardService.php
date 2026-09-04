<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReunionFechaGuardService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function ajustarFlujo($seguimientoId, $analistaId, $flujo)
    {
        if (!is_array($flujo) || (int)($flujo['paso_actual'] ?? 0) !== 12) {
            return $flujo;
        }

        $reunion = $this->obtenerReunionAgenda($seguimientoId, $analistaId);
        if (!$reunion) {
            return $flujo;
        }

        $fecha = (string)($reunion['fecha_propuesta'] ?? '');
        $disponible = (int)($reunion['disponible'] ?? 0) === 1;

        $flujo['contexto'] = is_array($flujo['contexto'] ?? null)
            ? $flujo['contexto']
            : [];
        $flujo['contexto']['reunion_fecha'] = $fecha;
        $flujo['contexto']['reunion_disponible'] = $disponible;

        if ($disponible) {
            return $flujo;
        }

        $fechaLegible = $this->fechaLegible($fecha);
        $flujo['titulo'] = 'Reunión programada';
        $flujo['descripcion'] = $fechaLegible !== ''
            ? 'La reunión está programada para ' . $fechaLegible . '. Podrás registrar el resultado cuando llegue esa fecha y hora.'
            : 'La reunión todavía no ha ocurrido. Podrás registrar el resultado cuando llegue la fecha programada.';
        $flujo['accion_principal'] = [
            'codigo' => 'REUNION_AUN_NO_DISPONIBLE',
            'etiqueta' => 'Registrar reunión',
            'icono' => 'bi-people',
            'deshabilitada' => true
        ];

        return $flujo;
    }

    public function validarRegistro($seguimientoId, $analistaId)
    {
        $reunion = $this->obtenerReunionAgenda($seguimientoId, $analistaId);

        // Compatibilidad con seguimientos anteriores a la agenda compartida.
        if (!$reunion) {
            return ['ok' => true];
        }

        if ((string)($reunion['estado'] ?? '') !== 'CORREO_ENVIADO') {
            return [
                'ok' => false,
                'mensaje' => 'Primero debe quedar enviada la confirmación de la reunión a la institución.',
                'codigo_http' => 409
            ];
        }

        if ((int)($reunion['disponible'] ?? 0) !== 1) {
            $fechaLegible = $this->fechaLegible((string)($reunion['fecha_propuesta'] ?? ''));
            return [
                'ok' => false,
                'mensaje' => $fechaLegible !== ''
                    ? 'La reunión está programada para ' . $fechaLegible . '. No puede registrarse como realizada antes de esa fecha y hora.'
                    : 'La reunión todavía no puede registrarse como realizada.',
                'codigo_http' => 409
            ];
        }

        return ['ok' => true];
    }

    public function marcarRealizada($seguimientoId, $analistaId)
    {
        if (!$this->tablaDisponible()) {
            return;
        }

        $sql = "UPDATE reuniones_vinculacion
                SET estado = 'REALIZADA'
                WHERE seguimiento_id = ?
                  AND analista_id = ?
                  AND estado = 'CORREO_ENVIADO'
                  AND fecha_propuesta <= NOW()
                ORDER BY id DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $analistaId);
        $stmt->execute();
    }

    private function obtenerReunionAgenda($seguimientoId, $analistaId)
    {
        if (!$this->tablaDisponible()) {
            return null;
        }

        $sql = "SELECT
                    id,
                    fecha_propuesta,
                    estado,
                    CASE WHEN fecha_propuesta <= NOW() THEN 1 ELSE 0 END AS disponible
                FROM reuniones_vinculacion
                WHERE seguimiento_id = ?
                  AND analista_id = ?
                  AND estado <> 'CANCELADA'
                ORDER BY id DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ii', $seguimientoId, $analistaId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function tablaDisponible()
    {
        $resultado = $this->connection->query("SHOW TABLES LIKE 'reuniones_vinculacion'");
        return $resultado && $resultado->num_rows > 0;
    }

    private function fechaLegible($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return '';
        }

        try {
            return (new DateTime($valor))->format('d/m/Y H:i');
        } catch (Throwable $error) {
            return $valor;
        }
    }
}
