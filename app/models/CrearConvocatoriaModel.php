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
     * Bandeja de Marketing.
     *
     * En el sistema un seguimiento adquiere la condición de Aliado cuando su
     * convenio queda formalizado. Marketing consulta directamente esa fuente
     * de verdad, sin duplicar los convenios en otra tabla.
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
                    post.convenio_notas,
                    post.convenio_formalizado_at,
                    post.convenio_formalizado_por,
                    usuarios.nombre AS analista_nombre,
                    usuarios.apellidos AS analista_apellidos
                FROM seguimientos_vinculacion seguimientos
                INNER JOIN seguimientos_vinculacion_post_envio post
                    ON post.seguimiento_id = seguimientos.id
                INNER JOIN estados
                    ON estados.id = seguimientos.estado_id
                LEFT JOIN municipios
                    ON municipios.id = seguimientos.municipio_id
                LEFT JOIN usuarios
                    ON usuarios.id = seguimientos.analista_id
                WHERE post.convenio_formalizado_at IS NOT NULL
                  AND seguimientos.activo = 1
                ORDER BY
                    post.convenio_formalizado_at DESC,
                    seguimientos.id DESC
                LIMIT 500";

        $resultado = $this->connection->query($sql);

        if (!$resultado) {
            return [];
        }

        $convenios = [];

        while ($fila = $resultado->fetch_assoc()) {
            $convenios[] = $fila;
        }

        return $convenios;
    }

    // Conserva compatibilidad con el controlador existente.
    public function listarConveniosRecibidos()
    {
        return $this->listarConveniosAliados();
    }

    public function estructuraDisponible()
    {
        $tablaPost = $this->connection->query(
            "SHOW TABLES LIKE 'seguimientos_vinculacion_post_envio'"
        );

        if (!$tablaPost || $tablaPost->num_rows === 0) {
            return false;
        }

        $columna = $this->connection->query(
            "SHOW COLUMNS FROM seguimientos_vinculacion_post_envio
             LIKE 'convenio_formalizado_at'"
        );

        return $columna && $columna->num_rows > 0;
    }
}
