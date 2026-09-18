<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionModel.php';
require_once __DIR__ . '/../../config/db_connection.php';

class ReporteSeguimientoInstitucionDetalleService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function construir($seguimientoId, $usuarioId, $modoAcceso): array
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $modoAcceso = (string)$modoAcceso;

        if ($seguimientoId <= 0 || $usuarioId <= 0) {
            return [];
        }

        $modelo = new SeguimientoVinculacionModel();
        $seguimiento = $this->obtenerSeguimientoAutorizado(
            $modelo,
            $seguimientoId,
            $usuarioId,
            $modoAcceso
        );

        if (!$seguimiento) {
            return [];
        }

        $interacciones = [];
        $oficios = [];
        $observaciones = [];

        try {
            $interacciones = $modelo->obtenerInteraccionesSeguimiento($seguimientoId);
        } catch (Throwable $error) {
            error_log('[reporte_institucion_interacciones] ' . $error->getMessage());
        }

        try {
            $oficios = $modelo->obtenerOficiosSeguimiento($seguimientoId);
        } catch (Throwable $error) {
            error_log('[reporte_institucion_oficios] ' . $error->getMessage());
        }

        try {
            $observaciones = $modelo->obtenerUltimasObservacionesSeguimiento($seguimientoId, 5);
        } catch (Throwable $error) {
            error_log('[reporte_institucion_observaciones] ' . $error->getMessage());
        }

        $interaccionesHumanas = array_values(array_filter(
            $interacciones,
            static function ($interaccion) {
                return strtoupper(trim((string)($interaccion['canal'] ?? ''))) !== 'SISTEMA';
            }
        ));

        return [
            'seguimiento' => $seguimiento,
            'contacto' => $this->contacto($seguimiento),
            'ultima_interaccion_humana' => $interaccionesHumanas[0] ?? null,
            'interacciones_recientes' => array_slice($interaccionesHumanas, 0, 8),
            'total_interacciones_humanas' => count($interaccionesHumanas),
            'oficios' => array_slice($oficios, 0, 4),
            'observaciones' => array_slice($observaciones, 0, 4),
            'reuniones' => $this->obtenerReuniones($seguimientoId),
            'post_envio' => $this->obtenerPostEnvio($seguimientoId)
        ];
    }

    private function obtenerSeguimientoAutorizado(
        SeguimientoVinculacionModel $modelo,
        int $seguimientoId,
        int $usuarioId,
        string $modoAcceso
    ) {
        if ($modoAcceso === 'administrador') {
            return $modelo->obtenerSeguimientoAdministrador($seguimientoId);
        }

        if ($modoAcceso === 'supervisor') {
            return $modelo->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId);
        }

        return $modelo->obtenerSeguimientoAnalista($usuarioId, $seguimientoId);
    }

    private function contacto(array $seguimiento): array
    {
        $telefono = trim((string)($seguimiento['telefono_verificado'] ?? ''));
        if ($telefono === '') {
            $telefono = trim((string)($seguimiento['telefono_fuente'] ?? ''));
        }

        $correo = trim((string)($seguimiento['correo_verificado'] ?? ''));
        if ($correo === '') {
            $correo = trim((string)($seguimiento['correo_fuente'] ?? ''));
        }

        return [
            'nombre' => trim((string)($seguimiento['contacto_nombre'] ?? '')),
            'cargo' => trim((string)($seguimiento['contacto_cargo'] ?? '')),
            'telefono' => $telefono,
            'whatsapp' => trim((string)($seguimiento['whatsapp_verificado'] ?? '')),
            'correo' => $correo,
            'sitio_web' => trim((string)($seguimiento['sitio_web_fuente'] ?? '')),
            'direccion' => trim((string)($seguimiento['direccion_fuente'] ?? '')),
            'actividad_giro' => trim((string)($seguimiento['actividad_giro'] ?? '')),
            'datos_verificados' => (int)($seguimiento['datos_verificados'] ?? 0) === 1
        ];
    }

    private function obtenerReuniones(int $seguimientoId): array
    {
        if (!$this->tablaDisponible('reuniones_vinculacion')) {
            return [];
        }

        try {
            $sql = "SELECT
                        r.id,
                        r.fecha_propuesta,
                        r.duracion_minutos,
                        r.modalidad,
                        r.objetivo,
                        r.estado,
                        r.ubicacion,
                        r.confirmada_at,
                        r.correo_confirmacion_at,
                        r.notas_analista,
                        r.notas_kam,
                        TRIM(CONCAT(COALESCE(k.nombre, ''), ' ', COALESCE(k.apellidos, ''))) AS cuenta_clave_nombre
                    FROM reuniones_vinculacion r
                    LEFT JOIN usuarios k ON k.id = r.cuenta_clave_id
                    WHERE r.seguimiento_id = ?
                    ORDER BY r.fecha_propuesta DESC, r.id DESC
                    LIMIT 5";
            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param('i', $seguimientoId);
            $stmt->execute();

            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } catch (Throwable $error) {
            error_log('[reporte_institucion_reuniones] ' . $error->getMessage());
            return [];
        }
    }

    private function obtenerPostEnvio(int $seguimientoId): array
    {
        if (!$this->tablaDisponible('seguimientos_vinculacion_post_envio')) {
            return [];
        }

        try {
            $stmt = $this->connection->prepare(
                "SELECT *
                 FROM seguimientos_vinculacion_post_envio
                 WHERE seguimiento_id = ?
                 LIMIT 1"
            );
            $stmt->bind_param('i', $seguimientoId);
            $stmt->execute();

            return $stmt->get_result()->fetch_assoc() ?: [];
        } catch (Throwable $error) {
            error_log('[reporte_institucion_post_envio] ' . $error->getMessage());
            return [];
        }
    }

    private function tablaDisponible(string $tabla): bool
    {
        $permitidas = [
            'reuniones_vinculacion',
            'seguimientos_vinculacion_post_envio'
        ];

        if (!in_array($tabla, $permitidas, true)) {
            return false;
        }

        $resultado = $this->connection->query("SHOW TABLES LIKE '" . $tabla . "'");
        return $resultado && $resultado->num_rows > 0;
    }
}
