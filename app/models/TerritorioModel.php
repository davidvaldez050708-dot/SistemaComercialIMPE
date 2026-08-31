<?php

require_once __DIR__ . '/../../config/db_connection.php';

class TerritorioModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function buscarEstados($filtros = [])
    {
        $buscar = trim($filtros['buscar'] ?? '');
        $cuentaClave = $filtros['cuenta_clave'] ?? '';
        $analista = $filtros['analista'] ?? '';
        $estadoAsignacion = $filtros['estado_asignacion'] ?? '';

        $condiciones = [
            'estados.estado = 1'
        ];
        $parametros = [];
        $tipos = '';

        if ($buscar !== '') {
            $condiciones[] = "(
                estados.nombre LIKE ?
                OR estados.nombre_corto LIKE ?
                OR estados.capital LIKE ?
            )";

            $busqueda = '%' . $buscar . '%';
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $tipos .= 'sss';
        }

        if ($cuentaClave !== '') {
            $condiciones[] = "EXISTS (
                SELECT 1
                FROM asignaciones_territorio filtro_cuenta
                WHERE filtro_cuenta.estado_id = estados.id
                    AND filtro_cuenta.usuario_id = ?
                    AND filtro_cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                    AND filtro_cuenta.activo = 1
            )";
            $parametros[] = (int)$cuentaClave;
            $tipos .= 'i';
        }

        if ($analista !== '') {
            $condiciones[] = "EXISTS (
                SELECT 1
                FROM asignaciones_territorio filtro_analista
                WHERE filtro_analista.estado_id = estados.id
                    AND filtro_analista.usuario_id = ?
                    AND filtro_analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND filtro_analista.activo = 1
            )";
            $parametros[] = (int)$analista;
            $tipos .= 'i';
        }

        if ($estadoAsignacion === 'con_cuenta_clave') {
            $condiciones[] = "EXISTS (
                SELECT 1
                FROM asignaciones_territorio estado_cuenta
                WHERE estado_cuenta.estado_id = estados.id
                    AND estado_cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                    AND estado_cuenta.activo = 1
            )";
        } elseif ($estadoAsignacion === 'sin_cuenta_clave') {
            $condiciones[] = "NOT EXISTS (
                SELECT 1
                FROM asignaciones_territorio estado_cuenta
                WHERE estado_cuenta.estado_id = estados.id
                    AND estado_cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                    AND estado_cuenta.activo = 1
            )";
        } elseif ($estadoAsignacion === 'con_analista') {
            $condiciones[] = "EXISTS (
                SELECT 1
                FROM asignaciones_territorio estado_analista
                WHERE estado_analista.estado_id = estados.id
                    AND estado_analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND estado_analista.activo = 1
            )";
        } elseif ($estadoAsignacion === 'sin_analista') {
            $condiciones[] = "EXISTS (
                SELECT 1
                FROM asignaciones_territorio estado_cuenta
                WHERE estado_cuenta.estado_id = estados.id
                    AND estado_cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                    AND estado_cuenta.activo = 1
            ) AND NOT EXISTS (
                SELECT 1
                FROM asignaciones_territorio estado_analista
                WHERE estado_analista.estado_id = estados.id
                    AND estado_analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND estado_analista.activo = 1
            )";
        } elseif ($estadoAsignacion === 'varias_cuenta_clave') {
            $condiciones[] = "(
                SELECT COUNT(*)
                FROM asignaciones_territorio estado_cuentas
                WHERE estado_cuentas.estado_id = estados.id
                    AND estado_cuentas.tipo_asignacion = 'CUENTA_CLAVE'
                    AND estado_cuentas.activo = 1
            ) > 1";
        }

        $sql = "SELECT
                    estados.*,
                    " . $this->subconsultaTotalAsignaciones('CUENTA_CLAVE') . " AS cuenta_clave_total,
                    " . $this->subconsultaNombresAsignaciones('CUENTA_CLAVE') . " AS cuenta_clave_nombres,
                    " . $this->subconsultaPersonasAsignaciones('CUENTA_CLAVE') . " AS cuenta_clave_personas,
                    " . $this->subconsultaTotalAsignaciones('ANALISTA_DATOS') . " AS analista_total,
                    " . $this->subconsultaNombresAsignaciones('ANALISTA_DATOS') . " AS analista_nombres,
                    " . $this->subconsultaPersonasAsignaciones('ANALISTA_DATOS') . " AS analista_personas
                FROM estados";

        if (!empty($condiciones)) {
            $sql .= " WHERE " . implode(' AND ', $condiciones);
        }

        $sql .= " ORDER BY estados.nombre";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerEstados($filtros = [])
    {
        return $this->buscarEstados($filtros);
    }

    public function buscarEstadoPorId($id)
    {
        $sql = "SELECT
                    estados.*,
                    (
                        SELECT COUNT(*)
                        FROM municipios
                        WHERE municipios.estado_id = estados.id
                            AND municipios.estado = 1
                    ) AS municipios_registrados,
                    (
                        SELECT COUNT(*)
                        FROM secretarias_estatales
                        WHERE secretarias_estatales.estado_id = estados.id
                            AND secretarias_estatales.estado = 1
                    ) AS secretarias_registradas,
                    " . $this->subconsultaTotalAsignaciones('CUENTA_CLAVE') . " AS cuenta_clave_total,
                    " . $this->subconsultaTotalAsignaciones('ANALISTA_DATOS') . " AS analista_total
                FROM estados
                WHERE estados.id = ?
                    AND estados.estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    public function obtenerEquipoTerritorial($estadoId)
    {
        $cuentasClave = $this->obtenerCuentasClaveActivas($estadoId);

        foreach ($cuentasClave as $indice => $cuentaClave) {
            $cuentasClave[$indice]['analistas'] =
                $this->obtenerAnalistasPorCuentaClave((int)$cuentaClave['id']);
        }

        return $cuentasClave;
    }

    public function obtenerCuentasClaveActivas($estadoId)
    {
        $sql = "SELECT
                    asignaciones_territorio.*,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.usuario,
                    roles.nombre AS rol
                FROM asignaciones_territorio
                INNER JOIN usuarios
                    ON usuarios.id = asignaciones_territorio.usuario_id
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE asignaciones_territorio.estado_id = ?
                    AND asignaciones_territorio.tipo_asignacion = 'CUENTA_CLAVE'
                    AND asignaciones_territorio.activo = 1
                ORDER BY asignaciones_territorio.fecha_inicio DESC,
                    asignaciones_territorio.id DESC";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerAnalistasPorCuentaClave($cuentaClaveAsignacionId)
    {
        $sql = "SELECT
                    asignaciones_territorio.*,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.usuario,
                    roles.nombre AS rol
                FROM asignaciones_territorio
                INNER JOIN usuarios
                    ON usuarios.id = asignaciones_territorio.usuario_id
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE asignaciones_territorio.cuenta_clave_asignacion_id = ?
                    AND asignaciones_territorio.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignaciones_territorio.activo = 1
                ORDER BY usuarios.nombre, usuarios.apellidos";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $cuentaClaveAsignacionId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerHistorialAsignaciones($estadoId)
    {
        $sql = "SELECT
                    asignaciones_territorio.*,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.usuario,
                    roles.nombre AS rol
                FROM asignaciones_territorio
                INNER JOIN usuarios
                    ON usuarios.id = asignaciones_territorio.usuario_id
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE asignaciones_territorio.estado_id = ?
                    AND asignaciones_territorio.activo = 0
                ORDER BY
                    asignaciones_territorio.fecha_fin DESC,
                    asignaciones_territorio.fecha_inicio DESC,
                    asignaciones_territorio.id DESC";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function crearCuentaClave($datos)
    {
        $sql = "INSERT INTO asignaciones_territorio (
                    estado_id,
                    usuario_id,
                    tipo_asignacion,
                    cuenta_clave_asignacion_id,
                    es_principal,
                    fecha_inicio,
                    fecha_fin,
                    activo,
                    observaciones
                ) VALUES (?, ?, 'CUENTA_CLAVE', NULL, 0, ?, NULL, 1, ?)";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "iiss",
            $datos['estado_id'],
            $datos['usuario_id'],
            $datos['fecha_inicio'],
            $datos['observaciones']
        );

        return $stmt->execute();
    }

    public function crearAnalista($datos)
    {
        $sql = "INSERT INTO asignaciones_territorio (
                    estado_id,
                    usuario_id,
                    tipo_asignacion,
                    cuenta_clave_asignacion_id,
                    es_principal,
                    fecha_inicio,
                    fecha_fin,
                    activo,
                    observaciones
                ) VALUES (?, ?, 'ANALISTA_DATOS', ?, 0, ?, NULL, 1, ?)";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "iiiss",
            $datos['estado_id'],
            $datos['usuario_id'],
            $datos['cuenta_clave_asignacion_id'],
            $datos['fecha_inicio'],
            $datos['observaciones']
        );

        return $stmt->execute();
    }

    public function finalizarAsignacion($asignacionId, $fechaFin)
    {
        $this->connection->begin_transaction();

        try {
            $actualizado = $this->marcarAsignacionFinalizada($asignacionId, $fechaFin);
            $this->connection->commit();

            return $actualizado;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function finalizarCuentaClaveConEquipo($asignacionId, $fechaFin)
    {
        $this->connection->begin_transaction();

        try {
            $sqlAnalistas = "UPDATE asignaciones_territorio
                    SET activo = 0,
                        fecha_fin = ?,
                        updated_at = NOW()
                    WHERE cuenta_clave_asignacion_id = ?
                        AND tipo_asignacion = 'ANALISTA_DATOS'
                        AND activo = 1";

            $stmtAnalistas = $this->connection->prepare($sqlAnalistas);
            $stmtAnalistas->bind_param("si", $fechaFin, $asignacionId);

            if (!$stmtAnalistas->execute()) {
                throw new Exception('No fue posible finalizar analistas vinculados.');
            }

            $actualizado = $this->marcarAsignacionFinalizada($asignacionId, $fechaFin);
            $this->connection->commit();

            return $actualizado;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function cuentaClaveTieneAnalistasActivos($asignacionId)
    {
        $sql = "SELECT id
                FROM asignaciones_territorio
                WHERE cuenta_clave_asignacion_id = ?
                    AND tipo_asignacion = 'ANALISTA_DATOS'
                    AND activo = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $asignacionId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function buscarAsignacionPorId($id)
    {
        $sql = "SELECT
                    asignaciones_territorio.*,
                    estados.nombre AS estado_nombre,
                    usuarios.nombre,
                    usuarios.apellidos,
                    roles.nombre AS rol
                FROM asignaciones_territorio
                INNER JOIN estados
                    ON estados.id = asignaciones_territorio.estado_id
                INNER JOIN usuarios
                    ON usuarios.id = asignaciones_territorio.usuario_id
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE asignaciones_territorio.id = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    public function existeCuentaClaveActiva($estadoId, $usuarioId)
    {
        $sql = "SELECT id
                FROM asignaciones_territorio
                WHERE estado_id = ?
                    AND usuario_id = ?
                    AND tipo_asignacion = 'CUENTA_CLAVE'
                    AND activo = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("ii", $estadoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function existeAnalistaActivo($estadoId, $usuarioId, $cuentaClaveAsignacionId)
    {
        $sql = "SELECT id
                FROM asignaciones_territorio
                WHERE estado_id = ?
                    AND usuario_id = ?
                    AND cuenta_clave_asignacion_id = ?
                    AND tipo_asignacion = 'ANALISTA_DATOS'
                    AND activo = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("iii", $estadoId, $usuarioId, $cuentaClaveAsignacionId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function obtenerUsuariosCuentaClave()
    {
        return $this->obtenerUsuariosFiltroPorRol('Cuenta Clave');
    }

    public function obtenerUsuariosAnalistas()
    {
        return $this->obtenerUsuariosFiltroPorRol('Analista de Datos');
    }

    public function obtenerUsuariosFiltroPorRol($nombreRol)
    {
        $sql = "SELECT
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.usuario,
                    roles.nombre AS rol
                FROM usuarios
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE usuarios.estado = 1
                    AND roles.nombre = ?
                    AND roles.estado = 1
                ORDER BY usuarios.nombre, usuarios.apellidos";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("s", $nombreRol);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function buscarUsuarioActivoPorId($id)
    {
        $sql = "SELECT
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    roles.nombre AS rol
                FROM usuarios
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE usuarios.id = ?
                    AND usuarios.estado = 1
                    AND roles.estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    public function contarMunicipios($estadoId)
    {
        return $this->contarPorEstado('municipios', $estadoId);
    }

    public function contarSecretarias($estadoId)
    {
        return $this->contarPorEstado('secretarias_estatales', $estadoId);
    }

    public function actualizarFichaTerritorial($id, $datos)
    {
        $sql = "UPDATE estados
                SET capital = ?,
                    titular_gobierno = ?,
                    cargo_titular = ?,
                    partido_politico = ?,
                    poblacion = ?,
                    total_municipios = ?,
                    total_secretarias = ?,
                    periodo_gobierno = ?,
                    telefono = ?,
                    redes_sociales = ?,
                    fuente = ?,
                    fecha_actualizacion = ?,
                    updated_at = NOW()
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "ssssiiisssssi",
            $datos['capital'],
            $datos['titular_gobierno'],
            $datos['cargo_titular'],
            $datos['partido_politico'],
            $datos['poblacion'],
            $datos['total_municipios'],
            $datos['total_secretarias'],
            $datos['periodo_gobierno'],
            $datos['telefono'],
            $datos['redes_sociales'],
            $datos['fuente'],
            $datos['fecha_actualizacion'],
            $id
        );

        return $stmt->execute();
    }

    public function existeClaveInegi($claveInegi, $idExcluir = null)
    {
        if ($claveInegi === null || $claveInegi === '') {
            return false;
        }

        $sql = "SELECT id
                FROM estados
                WHERE clave_inegi = ?";

        $parametros = [$claveInegi];
        $tipos = 's';

        if ($idExcluir !== null) {
            $sql .= " AND id <> ?";
            $parametros[] = (int)$idExcluir;
            $tipos .= 'i';
        }

        $sql .= " LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function obtenerResumenTerritorial()
    {
        $sql = "SELECT
                    COUNT(*) AS estados_registrados,
                    SUM(CASE WHEN EXISTS (
                        SELECT 1
                        FROM asignaciones_territorio resumen_cuenta
                        WHERE resumen_cuenta.estado_id = estados.id
                            AND resumen_cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                            AND resumen_cuenta.activo = 1
                    ) THEN 1 ELSE 0 END) AS con_cuenta_clave,
                    SUM(CASE WHEN EXISTS (
                        SELECT 1
                        FROM asignaciones_territorio resumen_analista
                        WHERE resumen_analista.estado_id = estados.id
                            AND resumen_analista.tipo_asignacion = 'ANALISTA_DATOS'
                            AND resumen_analista.activo = 1
                    ) THEN 1 ELSE 0 END) AS con_analista,
                    SUM(CASE WHEN EXISTS (
                        SELECT 1
                        FROM asignaciones_territorio resumen_sin_cuenta
                        WHERE resumen_sin_cuenta.estado_id = estados.id
                            AND resumen_sin_cuenta.tipo_asignacion = 'CUENTA_CLAVE'
                            AND resumen_sin_cuenta.activo = 1
                    ) THEN 0 ELSE 1 END) AS sin_cuenta_clave
                FROM estados
                WHERE estados.estado = 1";

        $resultado = $this->connection->query($sql);

        return $resultado->fetch_assoc();
    }

    public function obtenerResumenCuentaClave($usuarioId)
    {
        $sql = "SELECT
                    COUNT(DISTINCT cuentas.estado_id) AS territorios_asignados,
                    COUNT(DISTINCT cuentas.id) AS cuentas_clave_activas,
                    COUNT(analistas.id) AS analistas_vinculados
                FROM asignaciones_territorio cuentas
                LEFT JOIN asignaciones_territorio analistas
                    ON analistas.cuenta_clave_asignacion_id = cuentas.id
                    AND analistas.tipo_asignacion = 'ANALISTA_DATOS'
                    AND analistas.activo = 1
                WHERE cuentas.usuario_id = ?
                    AND cuentas.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuentas.activo = 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    private function marcarAsignacionFinalizada($asignacionId, $fechaFin)
    {
        $sql = "UPDATE asignaciones_territorio
                SET activo = 0,
                    fecha_fin = ?,
                    updated_at = NOW()
                WHERE id = ?
                    AND activo = 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("si", $fechaFin, $asignacionId);

        if (!$stmt->execute()) {
            throw new Exception('No fue posible finalizar la asignación.');
        }

        return $stmt->affected_rows > 0;
    }

    private function contarPorEstado($tabla, $estadoId)
    {
        $sql = "SELECT COUNT(*) AS total
                FROM $tabla
                WHERE estado_id = ?
                    AND estado = 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return (int)$fila['total'];
    }

    private function subconsultaTotalAsignaciones($tipo)
    {
        return "(
            SELECT COUNT(*)
            FROM asignaciones_territorio at_total
            WHERE at_total.estado_id = estados.id
                AND at_total.tipo_asignacion = '$tipo'
                AND at_total.activo = 1
        )";
    }

    private function subconsultaNombresAsignaciones($tipo)
    {
        return "(
            SELECT GROUP_CONCAT(
                TRIM(CONCAT(usuarios.nombre, ' ', usuarios.apellidos))
                ORDER BY usuarios.nombre, usuarios.apellidos
                SEPARATOR '||'
            )
            FROM asignaciones_territorio asignaciones
            INNER JOIN usuarios
                ON usuarios.id = asignaciones.usuario_id
            WHERE asignaciones.estado_id = estados.id
                AND asignaciones.tipo_asignacion = '$tipo'
                AND asignaciones.activo = 1
        )";
    }

    private function subconsultaPersonasAsignaciones($tipo)
    {
        return "(
            SELECT GROUP_CONCAT(
                CONCAT(
                    TRIM(CONCAT(usuarios.nombre, ' ', usuarios.apellidos)),
                    '~~',
                    COALESCE(usuarios.foto_perfil, ''),
                    '~~',
                    roles.nombre
                )
                ORDER BY usuarios.nombre, usuarios.apellidos
                SEPARATOR '||'
            )
            FROM asignaciones_territorio asignaciones
            INNER JOIN usuarios
                ON usuarios.id = asignaciones.usuario_id
            INNER JOIN roles
                ON roles.id = usuarios.rol_id
            WHERE asignaciones.estado_id = estados.id
                AND asignaciones.tipo_asignacion = '$tipo'
                AND asignaciones.activo = 1
        )";
    }

    private function vincularParametros($stmt, $tipos, $parametros)
    {
        if ($tipos === '') {
            return;
        }

        $referencias = [];
        $referencias[] = &$tipos;

        foreach ($parametros as $indice => $valor) {
            $referencias[] = &$parametros[$indice];
        }

        call_user_func_array([$stmt, 'bind_param'], $referencias);
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
