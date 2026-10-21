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

        if (
            !$this->asegurarEstructuraEstadoConvenio() ||
            !$this->asegurarEstructuraAtencionConvenio()
        ) {
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
                    usuarios.apellidos AS analista_apellidos,
                    CASE
                        WHEN atencion.seguimiento_id IS NOT NULL
                        THEN 1
                        ELSE 0
                    END AS tiene_convocatoria,
                    atencion.atendido_at AS convocatoria_creada_at
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
                LEFT JOIN marketing_convenio_atencion atencion
                    ON atencion.seguimiento_id = seguimientos.id
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

        require_once __DIR__ . '/../services/AliadoRegistroService.php';
        $registro = new AliadoRegistroService($this->connection);
        return $registro->decorar(array_merge($convenios, $registro->manuales()));
    }

    public function registrarConvocatoriaCreadaDesdeConvenio(
        $seguimientoId,
        $convocatoriaId,
        $usuarioId
    ) {
        $seguimientoId = (int)$seguimientoId;
        $convocatoriaId = (int)$convocatoriaId;
        $usuarioId = (int)$usuarioId;

        if (
            $seguimientoId <= 0 ||
            $convocatoriaId <= 0 ||
            $usuarioId <= 0 ||
            !$this->asegurarEstructuraEstadoConvenio()
        ) {
            return false;
        }

        $sql = "INSERT INTO marketing_convenio_convocatorias (
                    seguimiento_id,
                    convocatoria_id,
                    creado_por
                ) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    creado_por = VALUES(creado_por)";

        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            error_log(
                'CrearConvocatoriaModel::registrarConvocatoriaCreadaDesdeConvenio: ' .
                $this->connection->error
            );
            return false;
        }

        $stmt->bind_param(
            'iii',
            $seguimientoId,
            $convocatoriaId,
            $usuarioId
        );

        return $stmt->execute();
    }

    public function marcarConvenioAtendido($seguimientoId, $usuarioId)
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;

        if (
            $seguimientoId <= 0 ||
            $usuarioId <= 0 ||
            !$this->asegurarEstructuraAtencionConvenio()
        ) {
            return false;
        }

        $sql = "INSERT INTO marketing_convenio_atencion (
                    seguimiento_id,
                    atendido_por,
                    atendido_at
                ) VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    atendido_por = VALUES(atendido_por),
                    atendido_at = COALESCE(
                        marketing_convenio_atencion.atendido_at,
                        VALUES(atendido_at)
                    )";

        $stmt = $this->connection->prepare($sql);

        if (!$stmt) {
            error_log(
                'CrearConvocatoriaModel::marcarConvenioAtendido: ' .
                $this->connection->error
            );
            return false;
        }

        $stmt->bind_param(
            'ii',
            $seguimientoId,
            $usuarioId
        );

        return $stmt->execute();
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

    private function asegurarEstructuraAtencionConvenio()
    {
        $sql = "CREATE TABLE IF NOT EXISTS marketing_convenio_atencion (
                    seguimiento_id INT NOT NULL,
                    atendido_por INT NOT NULL,
                    atendido_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (seguimiento_id),
                    KEY idx_marketing_convenio_atendido_at (atendido_at)
                ) ENGINE=InnoDB
                  DEFAULT CHARSET=utf8mb4
                  COLLATE=utf8mb4_general_ci";

        if (!$this->connection->query($sql)) {
            error_log(
                'CrearConvocatoriaModel::asegurarEstructuraAtencionConvenio: ' .
                $this->connection->error
            );
            return false;
        }

        return true;
    }

    private function asegurarEstructuraEstadoConvenio()
    {
        $sql = "CREATE TABLE IF NOT EXISTS marketing_convenio_convocatorias (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    seguimiento_id INT NOT NULL,
                    convocatoria_id INT NOT NULL,
                    creado_por INT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_marketing_convenio_convocatoria (
                        seguimiento_id,
                        convocatoria_id
                    ),
                    KEY idx_marketing_convenio_estado (
                        seguimiento_id,
                        created_at
                    )
                ) ENGINE=InnoDB
                  DEFAULT CHARSET=utf8mb4
                  COLLATE=utf8mb4_general_ci";

        if (!$this->connection->query($sql)) {
            error_log(
                'CrearConvocatoriaModel::asegurarEstructuraEstadoConvenio: ' .
                $this->connection->error
            );
            return false;
        }

        return true;
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
