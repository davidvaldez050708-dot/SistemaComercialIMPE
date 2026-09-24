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
        $fechaFin = (string)($reunion['fecha_fin'] ?? '');
        $duracion = max(1, (int)($reunion['duracion_minutos'] ?? 60));
        $disponible = (int)($reunion['disponible'] ?? 0) === 1;
        $enCurso = (int)($reunion['en_curso'] ?? 0) === 1;

        $flujo['contexto'] = is_array($flujo['contexto'] ?? null)
            ? $flujo['contexto']
            : [];
        $flujo['contexto']['reunion_fecha'] = $fecha;
        $flujo['contexto']['reunion_fin'] = $fechaFin;
        $flujo['contexto']['reunion_duracion_minutos'] = $duracion;
        $flujo['contexto']['reunion_disponible'] = $disponible;
        $flujo['contexto']['reunion_en_curso'] = $enCurso;
        $flujo['contexto']['reunion_iniciada'] =
            (int)($reunion['iniciada'] ?? 0) === 1;

        $flujo['accion_secundaria'] = [
            'codigo' => 'AGENDAR_REUNION',
            'etiqueta' => 'Ver / reprogramar',
            'icono' => 'bi-calendar3'
        ];

        if ($disponible) {
            return $flujo;
        }

        /*
         * El paso 12 usa la duración programada para distinguir entre una
         * reunión futura, una reunión en curso y una reunión ya finalizada.
         */
        $fechaLegible = $this->fechaLegible($fecha);
        $finLegible = $this->fechaLegible($fechaFin);

        if (
            isset($flujo['ventana']['actual']) &&
            is_array($flujo['ventana']['actual'])
        ) {
            $flujo['ventana']['actual']['titulo'] = $enCurso
                ? 'Reunión en curso'
                : 'Reunión programada';
        }

        if ($enCurso) {
            $flujo['titulo'] = 'Reunión en curso';
            $flujo['descripcion'] = $finLegible !== ''
                ? 'La reunión inició a las ' .
                    (new DateTime($fecha))->format('H:i') .
                    ' y está programada por ' . $duracion .
                    ' min. Si termina antes de ' . $finLegible .
                    ', puedes finalizarla y registrar el resultado en ese momento.'
                : 'La reunión se encuentra en curso. Si ya terminó, puedes finalizarla y registrar el resultado ahora.';

            $flujo['accion_principal'] = [
                'codigo' => 'REGISTRAR_REUNION_REALIZADA',
                'etiqueta' => 'Finalizar y registrar reunión',
                'icono' => 'bi-check2-circle'
            ];

            return $flujo;
        }

        $flujo['titulo'] = 'Reunión programada';
        $flujo['descripcion'] = $fechaLegible !== ''
            ? 'La reunión está programada para ' . $fechaLegible .
                ' y durará aproximadamente ' . $duracion .
                ' min. Podrás registrar el resultado cuando la reunión haya iniciado.'
            : 'La reunión todavía no ha iniciado. Podrás registrar el resultado cuando comience.';

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

        if ((int)($reunion['iniciada'] ?? 0) !== 1) {
            $fechaLegible = $this->fechaLegible(
                (string)($reunion['fecha_propuesta'] ?? '')
            );

            return [
                'ok' => false,
                'mensaje' => $fechaLegible !== ''
                    ? 'La reunión está programada para ' . $fechaLegible . '. No puede registrarse antes de que inicie.'
                    : 'La reunión todavía no puede registrarse como realizada.',
                'codigo_http' => 409
            ];
        }

        return ['ok' => true];
    }

    public function marcarRealizada($seguimientoId, $analistaId, $datos = [])
    {
        if (!$this->tablaDisponible()) {
            return;
        }

        $resultado = strtoupper(trim((string)($datos['reunion_resultado'] ?? '')));
        $notas = trim((string)($datos['reunion_resultado_notas'] ?? ''));

        if ($this->resultadoAgendaDisponible()) {
            $sql = "UPDATE reuniones_vinculacion
                    SET estado = 'REALIZADA',
                        reunion_resultado = ?,
                        reunion_resultado_notas = ?,
                        realizada_at = NOW(),
                        realizada_por = ?
                    WHERE seguimiento_id = ?
                      AND analista_id = ?
                      AND estado = 'CORREO_ENVIADO'
                      AND fecha_propuesta <= NOW()
                    ORDER BY id DESC
                    LIMIT 1";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'ssiii',
                $resultado,
                $notas,
                $analistaId,
                $seguimientoId,
                $analistaId
            );
            $stmt->execute();
            return;
        }

        // Compatibilidad temporal si la migración nueva todavía no se aplicó.
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
                    duracion_minutos,
                    estado,
                    DATE_ADD(
                        fecha_propuesta,
                        INTERVAL COALESCE(NULLIF(duracion_minutos, 0), 60) MINUTE
                    ) AS fecha_fin,
                    CASE
                        WHEN fecha_propuesta <= NOW()
                        THEN 1 ELSE 0
                    END AS iniciada,
                    CASE
                        WHEN fecha_propuesta <= NOW()
                         AND DATE_ADD(
                            fecha_propuesta,
                            INTERVAL COALESCE(NULLIF(duracion_minutos, 0), 60) MINUTE
                         ) > NOW()
                        THEN 1 ELSE 0
                    END AS en_curso,
                    CASE
                        WHEN DATE_ADD(
                            fecha_propuesta,
                            INTERVAL COALESCE(NULLIF(duracion_minutos, 0), 60) MINUTE
                        ) <= NOW()
                        THEN 1 ELSE 0
                    END AS disponible
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

    private function resultadoAgendaDisponible()
    {
        if (!$this->tablaDisponible()) {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM reuniones_vinculacion LIKE 'reunion_resultado'"
        );

        return $resultado && $resultado->num_rows > 0;
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
