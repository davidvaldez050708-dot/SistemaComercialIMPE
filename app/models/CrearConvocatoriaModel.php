<?php

require_once __DIR__ . '/../../config/db_connection.php';

class CrearConvocatoriaModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    /**
     * Fuente de verdad para Marketing:
     * cualquier seguimiento que actualmente exista en aliados_asignaciones.
     *
     * Esto permite incluir aliados creados por formalización y también
     * registros históricos/migrados que ya forman parte del módulo Aliados.
     */
    public function listarConveniosAliados()
    {
        if (!$this->estructuraDisponible()) {
            return [];
        }

        $sql = "SELECT
                    seguimientos.id AS seguimiento_id,
                    seguimientos.estado_id,
                    estados.nombre AS estado_nombre,
                    seguimientos.municipio_id,
                    COALESCE(municipios.nombre, '') AS municipio_nombre,
                    seguimientos.nombre_entidad,
                    seguimientos.tipo_entidad,
                    seguimientos.contacto_nombre,
                    seguimientos.contacto_cargo,
                    COALESCE(
                        NULLIF(TRIM(seguimientos.correo_verificado), ''),
                        NULLIF(TRIM(seguimientos.correo_fuente), '')
                    ) AS correo_contacto,
                    post.convenio_fecha,
                    post.convenio_referencia,
                    post.convenio_notas,
                    COALESCE(
                        post.convenio_formalizado_at,
                        aliado.asignado_at
                    ) AS convenio_formalizado_at,
                    post.convenio_formalizado_por,
                    aliado.origen_asignacion,
                    aliado.asignado_at AS aliado_asignado_at,
                    usuarios.nombre AS analista_nombre,
                    usuarios.apellidos AS analista_apellidos
                FROM aliados_asignaciones aliado
                INNER JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.id = aliado.seguimiento_id
                INNER JOIN estados
                    ON estados.id = seguimientos.estado_id
                LEFT JOIN municipios
                    ON municipios.id = seguimientos.municipio_id
                LEFT JOIN seguimientos_vinculacion_post_envio post
                    ON post.seguimiento_id = seguimientos.id
                LEFT JOIN usuarios
                    ON usuarios.id = seguimientos.analista_id
                WHERE aliado.activo = 1
                  AND seguimientos.activo = 1
                ORDER BY
                    COALESCE(
                        post.convenio_formalizado_at,
                        aliado.asignado_at
                    ) DESC,
                    seguimientos.id DESC
                LIMIT 500";

        $resultado = $this->connection->query($sql);

        if (!$resultado) {
            error_log(
                'CrearConvocatoriaModel::listarConveniosAliados: ' .
                $this->connection->error
            );
            return [];
        }

        $convenios = [];

        while ($fila = $resultado->fetch_assoc()) {
            $convenios[] = $fila;
        }

        return $convenios;
    }

    public function listarConveniosRecibidos()
    {
        return $this->listarConveniosAliados();
    }

    public function listarEstados()
    {
        $resultado = $this->connection->query(
            "SELECT id, nombre
             FROM estados
             ORDER BY nombre ASC"
        );

        if (!$resultado) {
            return [];
        }

        $estados = [];

        while ($fila = $resultado->fetch_assoc()) {
            $estados[] = [
                'id' => (int)($fila['id'] ?? 0),
                'nombre' => (string)($fila['nombre'] ?? '')
            ];
        }

        return $estados;
    }

    public function estructuraDisponible()
    {
        $tablaAliados = $this->connection->query(
            "SHOW TABLES LIKE 'aliados_asignaciones'"
        );

        if (!$tablaAliados || $tablaAliados->num_rows === 0) {
            return false;
        }

        $tablaSeguimientos = $this->connection->query(
            "SHOW TABLES LIKE 'seguimientos_vinculacion'"
        );

        return $tablaSeguimientos && $tablaSeguimientos->num_rows > 0;
    }
}
