<?php

require_once __DIR__ . '/../../config/db_connection.php';

class AliadoModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function estructuraDisponible()
    {
        return $this->tablaExiste('aliados_asignaciones') &&
            $this->tablaExiste('aliados_convocatorias_envios');
    }

    public function obtenerListado($usuarioId, $esAdministrador = false, $filtros = [])
    {
        if (!$this->estructuraDisponible()) {
            return [];
        }

        $sql = $this->consultaBase() . "
                WHERE s.activo = 1
                  AND p.convenio_formalizado_at IS NOT NULL";

        $tipos = '';
        $parametros = [];

        if (!$esAdministrador) {
            $sql .= " AND aa.cuenta_clave_usuario_id = ?
                      AND aa.activo = 1";
            $tipos .= 'i';
            $parametros[] = (int)$usuarioId;
        }

        $buscar = trim((string)($filtros['buscar'] ?? ''));
        $estadoId = (int)($filtros['estado_id'] ?? 0);
        $municipioId = (int)($filtros['municipio_id'] ?? 0);
        $analistaId = (int)($filtros['analista_id'] ?? 0);

        if ($buscar !== '') {
            $sql .= " AND (
                        s.nombre_entidad LIKE ?
                        OR s.contacto_nombre LIKE ?
                        OR s.correo_verificado LIKE ?
                        OR s.correo_fuente LIKE ?
                    )";
            $termino = '%' . $buscar . '%';
            $tipos .= 'ssss';
            array_push($parametros, $termino, $termino, $termino, $termino);
        }

        if ($estadoId > 0) {
            $sql .= " AND s.estado_id = ?";
            $tipos .= 'i';
            $parametros[] = $estadoId;
        }

        if ($municipioId > 0) {
            $sql .= " AND s.municipio_id = ?";
            $tipos .= 'i';
            $parametros[] = $municipioId;
        }

        if ($analistaId > 0) {
            $sql .= " AND s.analista_id = ?";
            $tipos .= 'i';
            $parametros[] = $analistaId;
        }

        $sql .= " ORDER BY
                    p.convenio_formalizado_at DESC,
                    s.nombre_entidad ASC";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerAliado($seguimientoId, $usuarioId, $esAdministrador = false)
    {
        if (!$this->estructuraDisponible()) {
            return null;
        }

        $sql = $this->consultaBase() . "
                WHERE s.id = ?
                  AND s.activo = 1
                  AND p.convenio_formalizado_at IS NOT NULL";
        $tipos = 'i';
        $parametros = [(int)$seguimientoId];

        if (!$esAdministrador) {
            $sql .= " AND aa.cuenta_clave_usuario_id = ?
                      AND aa.activo = 1";
            $tipos .= 'i';
            $parametros[] = (int)$usuarioId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerConvocatoriasDisponibles($estadoId)
    {
        $estadoId = (int)$estadoId;
        if ($estadoId <= 0) {
            return [];
        }

        $sql = "SELECT DISTINCT
                    c.id,
                    c.titulo,
                    c.imagen,
                    c.categoria,
                    c.tipo_convocatoria,
                    c.subtipo_convocatoria,
                    c.fecha_inicio,
                    c.fecha_termino
                FROM convocatorias c
                INNER JOIN convocatoria_estados ce
                    ON ce.convocatoria_id = c.id
                WHERE ce.estado_id = ?
                  AND c.estado = 1
                  AND c.fecha_inicio <= CURDATE()
                  AND c.fecha_termino >= CURDATE()
                ORDER BY
                    c.fecha_termino ASC,
                    c.fecha_inicio DESC,
                    c.titulo ASC";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerConvocatoriaAplicable($convocatoriaId, $estadoId)
    {
        $sql = "SELECT
                    c.id,
                    c.titulo,
                    c.imagen,
                    c.categoria,
                    c.tipo_convocatoria,
                    c.subtipo_convocatoria,
                    c.fecha_inicio,
                    c.fecha_termino
                FROM convocatorias c
                INNER JOIN convocatoria_estados ce
                    ON ce.convocatoria_id = c.id
                WHERE c.id = ?
                  AND ce.estado_id = ?
                  AND c.estado = 1
                  AND c.fecha_inicio <= CURDATE()
                  AND c.fecha_termino >= CURDATE()
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $convocatoriaId = (int)$convocatoriaId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $convocatoriaId, $estadoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerHistorial($seguimientoId, $usuarioId, $esAdministrador = false)
    {
        if (!$this->obtenerAliado($seguimientoId, $usuarioId, $esAdministrador)) {
            return [];
        }

        $sql = "SELECT
                    envios.id,
                    envios.convocatoria_id,
                    envios.canal,
                    envios.destinatario,
                    envios.asunto,
                    envios.mensaje,
                    envios.convocatoria_titulo,
                    envios.convocatoria_imagen,
                    envios.convocatoria_fecha_inicio,
                    envios.convocatoria_fecha_termino,
                    envios.estado_envio,
                    envios.proveedor,
                    envios.error_detalle,
                    envios.enviado_at,
                    CONCAT_WS(' ', u.nombre, u.apellidos) AS enviado_por_nombre
                FROM aliados_convocatorias_envios envios
                INNER JOIN usuarios u
                    ON u.id = envios.cuenta_clave_usuario_id
                WHERE envios.seguimiento_id = ?
                ORDER BY envios.enviado_at DESC, envios.id DESC";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerUltimoEnvioExitoso($seguimientoId, $convocatoriaId, $canal)
    {
        $sql = "SELECT id, enviado_at, destinatario
                FROM aliados_convocatorias_envios
                WHERE seguimiento_id = ?
                  AND convocatoria_id = ?
                  AND canal = ?
                  AND estado_envio = 'ENVIADO'
                ORDER BY enviado_at DESC, id DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $convocatoriaId = (int)$convocatoriaId;
        $canal = strtoupper(trim((string)$canal));
        $stmt->bind_param('iis', $seguimientoId, $convocatoriaId, $canal);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function registrarEnvio($datos)
    {
        $sql = "INSERT INTO aliados_convocatorias_envios (
                    seguimiento_id,
                    convocatoria_id,
                    cuenta_clave_usuario_id,
                    canal,
                    destinatario,
                    asunto,
                    mensaje,
                    convocatoria_titulo,
                    convocatoria_imagen,
                    convocatoria_fecha_inicio,
                    convocatoria_fecha_termino,
                    estado_envio,
                    proveedor,
                    error_detalle,
                    enviado_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)($datos['seguimiento_id'] ?? 0);
        $convocatoriaId = (int)($datos['convocatoria_id'] ?? 0);
        $usuarioId = (int)($datos['usuario_id'] ?? 0);
        $canal = (string)($datos['canal'] ?? 'CORREO');
        $destinatario = (string)($datos['destinatario'] ?? '');
        $asunto = (string)($datos['asunto'] ?? '');
        $mensaje = (string)($datos['mensaje'] ?? '');
        $titulo = (string)($datos['convocatoria_titulo'] ?? '');
        $imagen = (string)($datos['convocatoria_imagen'] ?? '');
        $fechaInicio = (string)($datos['convocatoria_fecha_inicio'] ?? '');
        $fechaTermino = (string)($datos['convocatoria_fecha_termino'] ?? '');
        $estadoEnvio = (string)($datos['estado_envio'] ?? 'ENVIADO');
        $proveedor = (string)($datos['proveedor'] ?? '');
        $error = (string)($datos['error_detalle'] ?? '');

        $stmt->bind_param(
            'iiisssssssssss',
            $seguimientoId,
            $convocatoriaId,
            $usuarioId,
            $canal,
            $destinatario,
            $asunto,
            $mensaje,
            $titulo,
            $imagen,
            $fechaInicio,
            $fechaTermino,
            $estadoEnvio,
            $proveedor,
            $error
        );

        return $stmt->execute();
    }

    public function obtenerUsuarioRemitente($usuarioId)
    {
        $sql = "SELECT id, nombre, apellidos, correo
                FROM usuarios
                WHERE id = ?
                  AND estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function contarConvocatoriasVigentesPorEstados($estadoIds)
    {
        $estadoIds = array_values(array_unique(array_filter(array_map('intval', $estadoIds))));
        if (empty($estadoIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($estadoIds), '?'));
        $sql = "SELECT COUNT(DISTINCT c.id) AS total
                FROM convocatorias c
                INNER JOIN convocatoria_estados ce
                    ON ce.convocatoria_id = c.id
                WHERE ce.estado_id IN ($placeholders)
                  AND c.estado = 1
                  AND c.fecha_inicio <= CURDATE()
                  AND c.fecha_termino >= CURDATE()";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, str_repeat('i', count($estadoIds)), $estadoIds);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();
        return (int)($fila['total'] ?? 0);
    }

    private function consultaBase()
    {
        return "SELECT
                    s.id AS seguimiento_id,
                    s.estado_id,
                    e.nombre AS estado_nombre,
                    s.municipio_id,
                    COALESCE(m.nombre, '') AS municipio_nombre,
                    s.nombre_entidad,
                    s.tipo_entidad,
                    s.contacto_nombre,
                    s.contacto_cargo,
                    COALESCE(
                        NULLIF(TRIM(s.correo_verificado), ''),
                        NULLIF(TRIM(s.correo_fuente), '')
                    ) AS correo_contacto,
                    COALESCE(
                        NULLIF(TRIM(s.whatsapp_verificado), ''),
                        ''
                    ) AS whatsapp_contacto,
                    s.analista_id,
                    CONCAT_WS(' ', analista.nombre, analista.apellidos) AS analista_nombre,
                    p.convenio_fecha,
                    p.convenio_notas,
                    p.convenio_formalizado_at,
                    aa.id AS aliado_asignacion_id,
                    aa.cuenta_clave_usuario_id,
                    CONCAT_WS(' ', cuenta.nombre, cuenta.apellidos) AS cuenta_clave_nombre,
                    ultimo.id AS ultimo_envio_id,
                    ultimo.convocatoria_titulo AS ultima_convocatoria_titulo,
                    ultimo.canal AS ultimo_envio_canal,
                    ultimo.enviado_at AS ultimo_envio_at
                FROM seguimientos_vinculacion s
                INNER JOIN seguimientos_vinculacion_post_envio p
                    ON p.seguimiento_id = s.id
                INNER JOIN estados e
                    ON e.id = s.estado_id
                LEFT JOIN municipios m
                    ON m.id = s.municipio_id
                INNER JOIN usuarios analista
                    ON analista.id = s.analista_id
                LEFT JOIN aliados_asignaciones aa
                    ON aa.seguimiento_id = s.id
                LEFT JOIN usuarios cuenta
                    ON cuenta.id = aa.cuenta_clave_usuario_id
                LEFT JOIN aliados_convocatorias_envios ultimo
                    ON ultimo.id = (
                        SELECT envio_reciente.id
                        FROM aliados_convocatorias_envios envio_reciente
                        WHERE envio_reciente.seguimiento_id = s.id
                          AND envio_reciente.estado_envio = 'ENVIADO'
                        ORDER BY envio_reciente.enviado_at DESC, envio_reciente.id DESC
                        LIMIT 1
                    )";
    }

    private function tablaExiste($tabla)
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', (string)$tabla);
        if ($tabla === '') {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW TABLES LIKE '" . $tabla . "'"
        );

        return $resultado && $resultado->num_rows > 0;
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
