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

    public function listarConveniosRecibidos()
    {
        if (!$this->estructuraDisponible()) {
            return [];
        }

        $sql = "SELECT
                    seguimientos.id AS seguimiento_id,
                    seguimientos.estado_id,
                    estados.nombre AS estado_nombre,
                    seguimientos.municipio_id,
                    municipios.nombre AS municipio_nombre,
                    seguimientos.nombre_entidad,
                    seguimientos.tipo_entidad,
                    post.convenio_recibido_fecha,
                    post.convenio_recibido_at,
                    post.convenio_recibido_nombre_original,
                    post.convenio_recibido_notas,
                    post.convenio_formalizado_at,
                    usuarios.nombre AS analista_nombre,
                    usuarios.apellidos AS analista_apellidos
                FROM seguimientos_vinculacion_post_envio post
                INNER JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.id = post.seguimiento_id
                INNER JOIN estados
                    ON estados.id = seguimientos.estado_id
                LEFT JOIN municipios
                    ON municipios.id = seguimientos.municipio_id
                LEFT JOIN usuarios
                    ON usuarios.id = seguimientos.analista_id
                WHERE post.convenio_recibido_at IS NOT NULL
                  AND seguimientos.activo = 1
                ORDER BY post.convenio_recibido_at DESC, seguimientos.id DESC
                LIMIT 300";

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
             LIKE 'convenio_recibido_at'"
        );

        return $columna && $columna->num_rows > 0;
    }
}
