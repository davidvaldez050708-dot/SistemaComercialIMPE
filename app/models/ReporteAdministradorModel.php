<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReporteAdministradorModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerUsuariosConSeguimiento($rolesIds = [])
    {
        $rolesIds = $this->normalizarRolesIds($rolesIds);
        $filtroRoles = '';

        if (!empty($rolesIds)) {
            $filtroRoles = " WHERE usuarios.rol_id IN (" .
                implode(',', array_fill(0, count($rolesIds), '?')) .
                ")";
        }

        $sql = "SELECT
                    usuarios.id,
                    usuarios.usuario,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.correo,
                    usuarios.estado,
                    usuarios.ultimo_acceso,
                    roles.nombre AS rol,
                    COUNT(DISTINCT seguimientos.id) AS total_seguimientos,
                    COALESCE(SUM(
                        CASE
                            WHEN seguimientos.id IS NOT NULL
                                AND seguimientos.ultima_interaccion_at IS NULL
                            THEN 1 ELSE 0
                        END
                    ), 0) AS sin_actividad,
                    COALESCE(SUM(
                        CASE
                            WHEN seguimientos.ultima_interaccion_at IS NOT NULL
                                AND DATEDIFF(CURDATE(), DATE(seguimientos.ultima_interaccion_at)) > 7
                            THEN 1 ELSE 0
                        END
                    ), 0) AS mas_7_dias,
                    COALESCE(SUM(
                        CASE
                            WHEN seguimientos.proxima_accion_at IS NOT NULL
                                AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS acciones_pendientes,
                    COALESCE(SUM(
                        CASE
                            WHEN seguimientos.proxima_accion_at IS NOT NULL
                                AND seguimientos.proxima_accion_at <= NOW()
                                AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS acciones_vencidas
                FROM usuarios
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                LEFT JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.analista_id = usuarios.id
                    AND seguimientos.activo = 1" .
                $filtroRoles .
                " GROUP BY
                    usuarios.id,
                    usuarios.usuario,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.correo,
                    usuarios.estado,
                    usuarios.ultimo_acceso,
                    roles.id,
                    roles.nombre
                ORDER BY roles.id ASC, usuarios.nombre ASC, usuarios.apellidos ASC";

        return $this->ejecutarConsultaConRoles($sql, $rolesIds);
    }

    public function obtenerSeguimientosQueRequierenAtencion($rolesIds = [])
    {
        $rolesIds = $this->normalizarRolesIds($rolesIds);
        $filtroRoles = '';

        if (!empty($rolesIds)) {
            $filtroRoles = " AND usuarios.rol_id IN (" .
                implode(',', array_fill(0, count($rolesIds), '?')) .
                ")";
        }

        $sql = "SELECT
                    seguimientos.id,
                    seguimientos.nombre_entidad,
                    seguimientos.estado_seguimiento,
                    seguimientos.ultima_interaccion_at,
                    seguimientos.proxima_accion_at,
                    estados.nombre AS estado_nombre,
                    usuarios.usuario,
                    usuarios.nombre AS responsable_nombre,
                    usuarios.apellidos AS responsable_apellidos,
                    CASE
                        WHEN seguimientos.ultima_interaccion_at IS NULL THEN NULL
                        ELSE GREATEST(
                            0,
                            DATEDIFF(CURDATE(), DATE(seguimientos.ultima_interaccion_at))
                        )
                    END AS dias_sin_actividad
                FROM seguimientos_vinculacion seguimientos
                INNER JOIN usuarios
                    ON usuarios.id = seguimientos.analista_id
                INNER JOIN estados
                    ON estados.id = seguimientos.estado_id
                WHERE seguimientos.activo = 1
                    AND seguimientos.estado_seguimiento <> 'DESCARTADO'
                    AND (
                        seguimientos.proxima_accion_at IS NOT NULL
                        OR seguimientos.ultima_interaccion_at IS NULL
                        OR DATEDIFF(CURDATE(), DATE(seguimientos.ultima_interaccion_at)) > 7
                    )" .
                $filtroRoles .
                " ORDER BY
                    CASE
                        WHEN seguimientos.proxima_accion_at IS NOT NULL
                            AND seguimientos.proxima_accion_at <= NOW()
                        THEN 0
                        WHEN seguimientos.ultima_interaccion_at IS NULL
                        THEN 1
                        ELSE 2
                    END ASC,
                    seguimientos.proxima_accion_at ASC,
                    seguimientos.ultima_interaccion_at ASC,
                    usuarios.nombre ASC,
                    usuarios.apellidos ASC";

        return $this->ejecutarConsultaConRoles($sql, $rolesIds);
    }

    private function normalizarRolesIds($rolesIds)
    {
        if (!is_array($rolesIds)) {
            return [];
        }

        $roles = array_map('intval', $rolesIds);
        $roles = array_filter($roles, static function ($rolId) {
            return $rolId > 0;
        });

        return array_values(array_unique($roles));
    }

    private function ejecutarConsultaConRoles($sql, array $rolesIds)
    {
        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException('No fue posible preparar la consulta del reporte administrativo.');
        }

        if (!empty($rolesIds)) {
            $tipos = str_repeat('i', count($rolesIds));
            $referencias = [];
            $referencias[] = &$tipos;

            foreach ($rolesIds as $indice => $rolId) {
                $rolesIds[$indice] = (int)$rolId;
                $referencias[] = &$rolesIds[$indice];
            }

            call_user_func_array([$stmt, 'bind_param'], $referencias);
        }

        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    private function convertirResultadoEnArreglo($resultado)
    {
        $filas = [];

        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
    }
}
