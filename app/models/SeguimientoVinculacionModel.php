<?php

require_once __DIR__ . '/../../config/db_connection.php';

class SeguimientoVinculacionModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerEstadosAsignadosAnalista($usuarioId)
    {
        $sql = "SELECT
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto,
                    MAX(asignaciones_territorio.es_principal) AS es_principal,
                    COUNT(DISTINCT seguimientos_vinculacion.id) AS total_seguimientos,
                    0 AS total_analistas
                FROM asignaciones_territorio
                INNER JOIN estados
                    ON estados.id = asignaciones_territorio.estado_id
                LEFT JOIN seguimientos_vinculacion
                    ON seguimientos_vinculacion.estado_id = estados.id
                    AND seguimientos_vinculacion.analista_id = ?
                    AND seguimientos_vinculacion.activo = 1
                WHERE asignaciones_territorio.usuario_id = ?
                    AND asignaciones_territorio.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignaciones_territorio.activo = 1
                    AND estados.estado = 1
                    AND " . $this->condicionAsignacionVigente('asignaciones_territorio') . "
                GROUP BY
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto
                ORDER BY
                    es_principal DESC,
                    estados.nombre ASC";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('ii', $usuarioId, $usuarioId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerEstadosSupervisadosCuentaClave($usuarioId)
    {
        $sql = "SELECT
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto,
                    MAX(cuentas.es_principal) AS es_principal,
                    COUNT(DISTINCT analistas.usuario_id) AS total_analistas,
                    COUNT(DISTINCT seguimientos.id) AS total_seguimientos
                FROM asignaciones_territorio cuentas
                INNER JOIN estados
                    ON estados.id = cuentas.estado_id
                LEFT JOIN asignaciones_territorio analistas
                    ON analistas.cuenta_clave_asignacion_id = cuentas.id
                    AND analistas.estado_id = cuentas.estado_id
                    AND analistas.tipo_asignacion = 'ANALISTA_DATOS'
                    AND analistas.activo = 1
                    AND " . $this->condicionAsignacionVigente('analistas') . "
                LEFT JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.analista_id = analistas.usuario_id
                    AND seguimientos.estado_id = cuentas.estado_id
                    AND seguimientos.activo = 1
                WHERE cuentas.usuario_id = ?
                    AND cuentas.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuentas.activo = 1
                    AND estados.estado = 1
                    AND " . $this->condicionAsignacionVigente('cuentas') . "
                GROUP BY
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto
                ORDER BY
                    es_principal DESC,
                    estados.nombre ASC";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerEstadosAdministrador()
    {
        $sql = "SELECT
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto,
                    0 AS es_principal,
                    COUNT(DISTINCT analistas.usuario_id) AS total_analistas,
                    COUNT(DISTINCT seguimientos.id) AS total_seguimientos
                FROM estados
                LEFT JOIN asignaciones_territorio analistas
                    ON analistas.estado_id = estados.id
                    AND analistas.tipo_asignacion = 'ANALISTA_DATOS'
                    AND analistas.activo = 1
                    AND " . $this->condicionAsignacionVigente('analistas') . "
                LEFT JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.estado_id = estados.id
                    AND seguimientos.activo = 1
                WHERE estados.estado = 1
                GROUP BY
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto
                ORDER BY estados.nombre ASC";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerEstadoAsignadoAnalista($usuarioId, $estadoId)
    {
        $sql = "SELECT
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto,
                    asignaciones_territorio.es_principal
                FROM asignaciones_territorio
                INNER JOIN estados
                    ON estados.id = asignaciones_territorio.estado_id
                WHERE asignaciones_territorio.usuario_id = ?
                    AND asignaciones_territorio.estado_id = ?
                    AND asignaciones_territorio.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignaciones_territorio.activo = 1
                    AND estados.estado = 1
                    AND " . $this->condicionAsignacionVigente('asignaciones_territorio') . "
                ORDER BY asignaciones_territorio.es_principal DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $usuarioId, $estadoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerEstadoSupervisadoCuentaClave($usuarioId, $estadoId)
    {
        $sql = "SELECT
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto,
                    MAX(cuentas.es_principal) AS es_principal
                FROM asignaciones_territorio cuentas
                INNER JOIN estados
                    ON estados.id = cuentas.estado_id
                WHERE cuentas.usuario_id = ?
                    AND cuentas.estado_id = ?
                    AND cuentas.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuentas.activo = 1
                    AND estados.estado = 1
                    AND " . $this->condicionAsignacionVigente('cuentas') . "
                GROUP BY
                    estados.id,
                    estados.clave_inegi,
                    estados.nombre,
                    estados.nombre_corto
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $usuarioId, $estadoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerEstadoAdministrador($estadoId)
    {
        $sql = "SELECT
                    id,
                    clave_inegi,
                    nombre,
                    nombre_corto,
                    0 AS es_principal
                FROM estados
                WHERE id = ?
                    AND estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerAnalistasCuentaClaveEstado($usuarioId, $estadoId)
    {
        $sql = "SELECT DISTINCT
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.usuario
                FROM asignaciones_territorio cuentas
                INNER JOIN asignaciones_territorio analistas
                    ON analistas.cuenta_clave_asignacion_id = cuentas.id
                    AND analistas.estado_id = cuentas.estado_id
                    AND analistas.tipo_asignacion = 'ANALISTA_DATOS'
                    AND analistas.activo = 1
                    AND " . $this->condicionAsignacionVigente('analistas') . "
                INNER JOIN usuarios
                    ON usuarios.id = analistas.usuario_id
                WHERE cuentas.usuario_id = ?
                    AND cuentas.estado_id = ?
                    AND cuentas.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuentas.activo = 1
                    AND usuarios.estado = 1
                    AND usuarios.rol_id = 4
                    AND " . $this->condicionAsignacionVigente('cuentas') . "
                ORDER BY usuarios.nombre, usuarios.apellidos";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $usuarioId, $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerAnalistasAdministradorEstado($estadoId)
    {
        $sql = "SELECT DISTINCT
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.usuario
                FROM asignaciones_territorio analistas
                INNER JOIN usuarios
                    ON usuarios.id = analistas.usuario_id
                WHERE analistas.estado_id = ?
                    AND analistas.tipo_asignacion = 'ANALISTA_DATOS'
                    AND analistas.activo = 1
                    AND usuarios.estado = 1
                    AND usuarios.rol_id = 4
                    AND " . $this->condicionAsignacionVigente('analistas') . "
                ORDER BY usuarios.nombre, usuarios.apellidos";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerMunicipiosActivosEstado($estadoId)
    {
        $sql = "SELECT
                    id,
                    clave_inegi,
                    nombre
                FROM municipios
                WHERE estado_id = ?
                    AND estado = 1
                ORDER BY nombre ASC";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function buscarCandidatosSecretarias($estadoId, $buscar, $limite = 10, $offset = 0)
    {
        $sql = "SELECT
                    id,
                    nombre,
                    titular,
                    cargo_titular,
                    correo,
                    telefono,
                    sitio_web,
                    fuente_datos,
                    clave_denue,
                    fecha_actualizacion_denue
                FROM secretarias_estatales
                WHERE estado_id = ?
                    AND estado = 1
                    AND (
                        nombre LIKE ?
                        OR titular LIKE ?
                        OR cargo_titular LIKE ?
                        OR correo LIKE ?
                    )
                ORDER BY nombre ASC
                LIMIT ? OFFSET ?";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $busqueda = '%' . trim((string)$buscar) . '%';
        $limite = max(1, (int)$limite);
        $offset = max(0, (int)$offset);
        $stmt->bind_param(
            'issssii',
            $estadoId,
            $busqueda,
            $busqueda,
            $busqueda,
            $busqueda,
            $limite,
            $offset
        );
        $stmt->execute();

        $candidatos = [];

        foreach ($this->convertirResultadoEnArreglo($stmt->get_result()) as $secretaria) {
            $candidatos[] = $this->normalizarCandidatoSecretaria($secretaria);
        }

        return $candidatos;
    }

    public function obtenerSecretariasParaActualizarDenue($estadoId)
    {
        $sql = "SELECT id, nombre, telefono, correo, sitio_web, clave_denue
                FROM secretarias_estatales
                WHERE estado_id = ?
                    AND estado = 1
                ORDER BY nombre ASC";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function enriquecerSecretariaDesdeDenue($estadoId, $secretariaId, $candidatoDenue)
    {
        $sql = "UPDATE secretarias_estatales
                SET telefono = COALESCE(NULLIF(telefono, ''), NULLIF(?, '')),
                    correo = COALESCE(NULLIF(correo, ''), NULLIF(?, '')),
                    sitio_web = COALESCE(NULLIF(sitio_web, ''), NULLIF(?, '')),
                    fuente_datos = 'DENUE',
                    clave_denue = ?,
                    fecha_actualizacion_denue = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                    AND estado_id = ?
                    AND estado = 1";

        $stmt = $this->connection->prepare($sql);
        $telefono = trim((string)($candidatoDenue['telefono'] ?? ''));
        $correo = trim((string)($candidatoDenue['correo'] ?? ''));
        $sitioWeb = trim((string)($candidatoDenue['sitio_web'] ?? ''));
        $claveDenue = trim((string)($candidatoDenue['id_origen'] ?? ''));
        $secretariaId = (int)$secretariaId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param(
            'ssssii',
            $telefono,
            $correo,
            $sitioWeb,
            $claveDenue,
            $secretariaId,
            $estadoId
        );

        return $stmt->execute();
    }

    public function buscarCandidatosMunicipios($estadoId, $buscar, $municipioId = 0, $limite = 10, $offset = 0)
    {
        $sql = "SELECT
                    id,
                    clave_inegi,
                    nombre,
                    presidente_municipal,
                    partido_politico,
                    redes_sociales
                FROM municipios
                WHERE estado_id = ?
                    AND estado = 1";

        $parametros = [(int)$estadoId];
        $tipos = 'i';

        if ((int)$municipioId > 0) {
            $sql .= " AND id = ?";
            $parametros[] = (int)$municipioId;
            $tipos .= 'i';
        }

        $sql .= " AND (
                    nombre LIKE ?
                    OR presidente_municipal LIKE ?
                    OR partido_politico LIKE ?
                    OR redes_sociales LIKE ?
                )
                ORDER BY nombre ASC
                LIMIT ? OFFSET ?";

        $busqueda = '%' . trim((string)$buscar) . '%';
        $limite = max(1, (int)$limite);
        $offset = max(0, (int)$offset);
        array_push(
            $parametros,
            $busqueda,
            $busqueda,
            $busqueda,
            $busqueda,
            $limite,
            $offset
        );
        $tipos .= 'ssssii';

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        $candidatos = [];

        foreach ($this->convertirResultadoEnArreglo($stmt->get_result()) as $municipio) {
            $candidatos[] = $this->normalizarCandidatoMunicipio($municipio);
        }

        return $candidatos;
    }

    public function obtenerSecretariaActivaPorId($estadoId, $secretariaId)
    {
        $sql = "SELECT
                    id,
                    nombre,
                    titular,
                    cargo_titular,
                    correo,
                    telefono,
                    sitio_web
                FROM secretarias_estatales
                WHERE id = ?
                    AND estado_id = ?
                    AND estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $secretariaId = (int)$secretariaId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $secretariaId, $estadoId);
        $stmt->execute();
        $secretaria = $stmt->get_result()->fetch_assoc();

        return $secretaria ? $this->normalizarCandidatoSecretaria($secretaria) : null;
    }

    public function obtenerMunicipioActivoPorId($estadoId, $municipioId)
    {
        $sql = "SELECT
                    id,
                    clave_inegi,
                    nombre,
                    presidente_municipal,
                    partido_politico,
                    redes_sociales
                FROM municipios
                WHERE id = ?
                    AND estado_id = ?
                    AND estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $municipioId = (int)$municipioId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('ii', $municipioId, $estadoId);
        $stmt->execute();
        $municipio = $stmt->get_result()->fetch_assoc();

        return $municipio ? $this->normalizarCandidatoMunicipio($municipio) : null;
    }

    public function obtenerMunicipioActivoPorClave($estadoId, $claveInegi)
    {
        $sql = "SELECT
                    id,
                    clave_inegi,
                    nombre
                FROM municipios
                WHERE estado_id = ?
                    AND (
                        clave_inegi = ?
                        OR RIGHT(LPAD(clave_inegi, 5, '0'), 3) = ?
                    )
                    AND estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $claveInegi = trim((string)$claveInegi);
        $claveMunicipal = ctype_digit($claveInegi)
            ? str_pad(substr($claveInegi, -3), 3, '0', STR_PAD_LEFT)
            : $claveInegi;
        $stmt->bind_param('iss', $estadoId, $claveInegi, $claveMunicipal);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerSeguimientoPorClaveOrigen($estadoId, $claveOrigen)
    {
        $sql = "SELECT
                    id,
                    activo
                FROM seguimientos_vinculacion
                WHERE estado_id = ?
                    AND clave_origen = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $claveOrigen = trim((string)$claveOrigen);
        $stmt->bind_param('is', $estadoId, $claveOrigen);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function analistaValidoParaAdministradorEstado($estadoId, $analistaId)
    {
        $sql = "SELECT usuarios.id
                FROM asignaciones_territorio asignaciones
                INNER JOIN usuarios
                    ON usuarios.id = asignaciones.usuario_id
                WHERE asignaciones.estado_id = ?
                    AND asignaciones.usuario_id = ?
                    AND asignaciones.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignaciones.activo = 1
                    AND usuarios.estado = 1
                    AND usuarios.rol_id = 4
                    AND " . $this->condicionAsignacionVigente('asignaciones') . "
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $analistaId = (int)$analistaId;
        $stmt->bind_param('ii', $estadoId, $analistaId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() !== null;
    }

    public function analistaValidoParaCuentaClaveEstado($cuentaClaveId, $estadoId, $analistaId)
    {
        $sql = "SELECT usuarios.id
                FROM asignaciones_territorio cuentas
                INNER JOIN asignaciones_territorio analistas
                    ON analistas.cuenta_clave_asignacion_id = cuentas.id
                    AND analistas.estado_id = cuentas.estado_id
                    AND analistas.usuario_id = ?
                    AND analistas.tipo_asignacion = 'ANALISTA_DATOS'
                    AND analistas.activo = 1
                    AND " . $this->condicionAsignacionVigente('analistas') . "
                INNER JOIN usuarios
                    ON usuarios.id = analistas.usuario_id
                WHERE cuentas.usuario_id = ?
                    AND cuentas.estado_id = ?
                    AND cuentas.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuentas.activo = 1
                    AND usuarios.estado = 1
                    AND usuarios.rol_id = 4
                    AND " . $this->condicionAsignacionVigente('cuentas') . "
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $analistaId = (int)$analistaId;
        $cuentaClaveId = (int)$cuentaClaveId;
        $estadoId = (int)$estadoId;
        $stmt->bind_param('iii', $analistaId, $cuentaClaveId, $estadoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() !== null;
    }

    public function crearSeguimientoDesdeCandidato($datos)
    {
        $sql = "INSERT INTO seguimientos_vinculacion (
                    estado_id,
                    municipio_id,
                    analista_id,
                    origen,
                    clave_origen,
                    tipo_entidad,
                    nombre_entidad,
                    actividad_giro,
                    direccion_fuente,
                    telefono_fuente,
                    correo_fuente,
                    sitio_web_fuente,
                    estado_seguimiento,
                    datos_verificados,
                    fecha_inicio,
                    activo
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'NUEVO', 0, NOW(), 1)";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$datos['estado_id'];
        $municipioId = isset($datos['municipio_id']) && (int)$datos['municipio_id'] > 0
            ? (int)$datos['municipio_id']
            : null;
        $analistaId = (int)$datos['analista_id'];
        $origen = (string)$datos['origen'];
        $claveOrigen = (string)$datos['clave_origen'];
        $tipoEntidad = (string)$datos['tipo_entidad'];
        $nombreEntidad = (string)$datos['nombre'];
        $actividad = $datos['actividad'] ?? null;
        $direccion = $datos['direccion'] ?? null;
        $telefono = $datos['telefono'] ?? null;
        $correo = $datos['correo'] ?? null;
        $sitioWeb = $datos['sitio_web'] ?? null;

        $stmt->bind_param(
            'iiisssssssss',
            $estadoId,
            $municipioId,
            $analistaId,
            $origen,
            $claveOrigen,
            $tipoEntidad,
            $nombreEntidad,
            $actividad,
            $direccion,
            $telefono,
            $correo,
            $sitioWeb
        );

        if (!$stmt->execute()) {
            return 0;
        }

        return (int)$this->connection->insert_id;
    }

    public function obtenerSeguimientoManualExacto($estadoId, $analistaId, $tipoEntidad, $nombre)
    {
        $sql = "SELECT id, activo
                FROM seguimientos_vinculacion
                WHERE estado_id = ?
                    AND analista_id = ?
                    AND origen = 'MANUAL'
                    AND tipo_entidad = ?
                    AND LOWER(TRIM(nombre_entidad)) = LOWER(TRIM(?))
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $estadoId = (int)$estadoId;
        $analistaId = (int)$analistaId;
        $stmt->bind_param('iiss', $estadoId, $analistaId, $tipoEntidad, $nombre);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function crearSeguimientoManual($datos)
    {
        $this->connection->begin_transaction();

        try {
            $sqlSeguimiento = "INSERT INTO seguimientos_vinculacion (
                        estado_id,
                        municipio_id,
                        analista_id,
                        origen,
                        clave_origen,
                        tipo_entidad,
                        nombre_entidad,
                        telefono_fuente,
                        correo_fuente,
                        telefono_verificado,
                        whatsapp_verificado,
                        correo_verificado,
                        contacto_nombre,
                        contacto_cargo,
                        datos_verificados,
                        estado_seguimiento,
                        motivo_descarte,
                        observaciones,
                        ultima_interaccion_at,
                        proxima_accion_at,
                        fecha_inicio,
                        activo
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";

            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $parametros = [
                (int)$datos['estado_id'],
                (int)$datos['municipio_id'] > 0 ? (int)$datos['municipio_id'] : null,
                (int)$datos['analista_id'],
                'MANUAL',
                (string)$datos['clave_origen'],
                (string)$datos['tipo_entidad'],
                (string)$datos['nombre'],
                $this->valorONulo($datos['telefono'] ?? ''),
                $this->valorONulo($datos['correo'] ?? ''),
                $this->valorONulo($datos['telefono'] ?? ''),
                $this->valorONulo($datos['whatsapp'] ?? ''),
                $this->valorONulo($datos['correo'] ?? ''),
                $this->valorONulo($datos['contacto_nombre'] ?? ''),
                $this->valorONulo($datos['contacto_cargo'] ?? ''),
                (int)$datos['datos_verificados'],
                (string)$datos['estado_seguimiento'],
                $this->valorONulo($datos['motivo_descarte'] ?? ''),
                $this->valorONulo($datos['observaciones'] ?? ''),
                (string)$datos['ultima_actividad_at'],
                $this->valorONulo($datos['proxima_accion_at'] ?? ''),
                (string)$datos['ultima_actividad_at']
            ];
            $this->vincularParametros(
                $stmtSeguimiento,
                'iiisssssssssssissssss',
                $parametros
            );
            $stmtSeguimiento->execute();
            $seguimientoId = (int)$this->connection->insert_id;

            if ($seguimientoId <= 0) {
                throw new RuntimeException('No fue posible crear el seguimiento manual.');
            }

            $notas = trim(implode("\n", array_filter([
                trim((string)($datos['persona_atendio'] ?? '')) !== ''
                    ? 'Persona atendió: ' . trim((string)$datos['persona_atendio'])
                    : '',
                ($datos['resultado_formulario'] ?? '') === 'NO_INTERESADO'
                    ? 'Resultado registrado: No interesado'
                    : '',
                trim((string)($datos['observaciones'] ?? '')),
                trim((string)($datos['proxima_accion'] ?? '')) !== ''
                    ? 'Próxima acción: ' . trim((string)$datos['proxima_accion'])
                    : ''
            ])));
            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                        seguimiento_id,
                        usuario_id,
                        canal,
                        telefono_destino,
                        correo_destino,
                        fecha_inicio,
                        resultado,
                        notas
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $usuarioId = (int)$datos['usuario_id'];
            $canal = (string)$datos['canal'];
            $telefono = $this->valorONulo($datos['telefono'] ?? '');
            $correo = $this->valorONulo($datos['correo'] ?? '');
            $ultimaActividad = (string)$datos['ultima_actividad_at'];
            $resultado = (string)$datos['resultado'];
            $notas = $this->valorONulo($notas);
            $stmtInteraccion->bind_param(
                'iissssss',
                $seguimientoId,
                $usuarioId,
                $canal,
                $telefono,
                $correo,
                $ultimaActividad,
                $resultado,
                $notas
            );
            $stmtInteraccion->execute();

            $this->connection->commit();

            return $seguimientoId;
        } catch (Throwable $error) {
            $this->connection->rollback();
            throw $error;
        }
    }

    public function obtenerResumenSeguimientosAnalistaEstado($usuarioId, $estadoId, $filtros = [])
    {
        $sql = "SELECT
                    COALESCE(SUM(seguimientos.estado_seguimiento <> 'DESCARTADO'), 0) AS en_seguimiento,
                    COALESCE(SUM(seguimientos.estado_seguimiento = 'CONTACTANDO'), 0) AS contactando,
                    COALESCE(SUM(seguimientos.estado_seguimiento = 'DATOS_VERIFICADOS'), 0) AS datos_verificados,
                    COALESCE(SUM(seguimientos.estado_seguimiento = 'ESPERANDO_RESPUESTA'), 0) AS esperando_respuesta
                FROM seguimientos_vinculacion seguimientos
                WHERE seguimientos.analista_id = ?
                    AND seguimientos.estado_id = ?
                    AND seguimientos.activo = 1";

        $parametros = [(int)$usuarioId, (int)$estadoId];
        $tipos = 'ii';
        $this->agregarFiltrosSeguimiento($sql, $parametros, $tipos, $filtros, 'seguimientos');

        return $this->obtenerResumenDesdeConsulta($sql, $parametros, $tipos);
    }

    public function obtenerResumenSeguimientosSupervisorEstado($usuarioId, $estadoId, $filtros = [])
    {
        $sql = "SELECT
                    COALESCE(COUNT(DISTINCT CASE WHEN seguimientos.estado_seguimiento <> 'DESCARTADO' THEN seguimientos.id END), 0) AS en_seguimiento,
                    COALESCE(COUNT(DISTINCT CASE WHEN seguimientos.estado_seguimiento = 'CONTACTANDO' THEN seguimientos.id END), 0) AS contactando,
                    COALESCE(COUNT(DISTINCT CASE WHEN seguimientos.estado_seguimiento = 'DATOS_VERIFICADOS' THEN seguimientos.id END), 0) AS datos_verificados,
                    COALESCE(COUNT(DISTINCT CASE WHEN seguimientos.estado_seguimiento = 'ESPERANDO_RESPUESTA' THEN seguimientos.id END), 0) AS esperando_respuesta
                FROM seguimientos_vinculacion seguimientos
                INNER JOIN asignaciones_territorio analistas
                    ON analistas.usuario_id = seguimientos.analista_id
                    AND analistas.estado_id = seguimientos.estado_id
                    AND analistas.tipo_asignacion = 'ANALISTA_DATOS'
                    AND analistas.activo = 1
                    AND " . $this->condicionAsignacionVigente('analistas') . "
                INNER JOIN asignaciones_territorio cuentas
                    ON cuentas.id = analistas.cuenta_clave_asignacion_id
                    AND cuentas.estado_id = seguimientos.estado_id
                    AND cuentas.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuentas.activo = 1
                    AND cuentas.usuario_id = ?
                    AND " . $this->condicionAsignacionVigente('cuentas') . "
                WHERE seguimientos.estado_id = ?
                    AND seguimientos.activo = 1";

        $parametros = [(int)$usuarioId, (int)$estadoId];
        $tipos = 'ii';
        $this->agregarFiltrosSeguimiento($sql, $parametros, $tipos, $filtros, 'seguimientos');

        return $this->obtenerResumenDesdeConsulta($sql, $parametros, $tipos);
    }

    public function obtenerResumenSeguimientosAdministradorEstado($estadoId, $filtros = [])
    {
        $sql = "SELECT
                    COALESCE(SUM(seguimientos.estado_seguimiento <> 'DESCARTADO'), 0) AS en_seguimiento,
                    COALESCE(SUM(seguimientos.estado_seguimiento = 'CONTACTANDO'), 0) AS contactando,
                    COALESCE(SUM(seguimientos.estado_seguimiento = 'DATOS_VERIFICADOS'), 0) AS datos_verificados,
                    COALESCE(SUM(seguimientos.estado_seguimiento = 'ESPERANDO_RESPUESTA'), 0) AS esperando_respuesta
                FROM seguimientos_vinculacion seguimientos
                WHERE seguimientos.estado_id = ?
                    AND seguimientos.activo = 1";

        $parametros = [(int)$estadoId];
        $tipos = 'i';
        $this->agregarFiltrosSeguimiento($sql, $parametros, $tipos, $filtros, 'seguimientos');

        return $this->obtenerResumenDesdeConsulta($sql, $parametros, $tipos);
    }

    public function obtenerSeguimientosAnalistaEstado($usuarioId, $estadoId, $filtros = [])
    {
        $sql = $this->consultaSeguimientosBase() . "
                WHERE seguimientos.analista_id = ?
                    AND seguimientos.estado_id = ?
                    AND seguimientos.activo = 1";

        $parametros = [(int)$usuarioId, (int)$estadoId];
        $tipos = 'ii';
        $this->agregarFiltrosSeguimiento($sql, $parametros, $tipos, $filtros, 'seguimientos');
        $sql .= $this->ordenSeguimientos();

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerSeguimientosSupervisorEstado($usuarioId, $estadoId, $filtros = [])
    {
        $sql = $this->consultaSeguimientosBase() . "
                INNER JOIN asignaciones_territorio asignacion_analista
                    ON asignacion_analista.usuario_id = seguimientos.analista_id
                    AND asignacion_analista.estado_id = seguimientos.estado_id
                    AND asignacion_analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignacion_analista.activo = 1
                    AND " . $this->condicionAsignacionVigente('asignacion_analista') . "
                INNER JOIN asignaciones_territorio cuenta_clave
                    ON cuenta_clave.id = asignacion_analista.cuenta_clave_asignacion_id
                    AND cuenta_clave.estado_id = seguimientos.estado_id
                    AND cuenta_clave.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuenta_clave.activo = 1
                    AND cuenta_clave.usuario_id = ?
                    AND " . $this->condicionAsignacionVigente('cuenta_clave') . "
                WHERE seguimientos.estado_id = ?
                    AND seguimientos.activo = 1";

        $parametros = [(int)$usuarioId, (int)$estadoId];
        $tipos = 'ii';
        $this->agregarFiltrosSeguimiento($sql, $parametros, $tipos, $filtros, 'seguimientos');
        $sql .= $this->ordenSeguimientos();

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerSeguimientosAdministradorEstado($estadoId, $filtros = [])
    {
        $sql = $this->consultaSeguimientosBase() . "
                WHERE seguimientos.estado_id = ?
                    AND seguimientos.activo = 1";

        $parametros = [(int)$estadoId];
        $tipos = 'i';
        $this->agregarFiltrosSeguimiento($sql, $parametros, $tipos, $filtros, 'seguimientos');
        $sql .= $this->ordenSeguimientos();

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerSeguimientoAnalista($usuarioId, $seguimientoId)
    {
        $sql = $this->consultaDetalleSeguimientoBase() . "
                WHERE seguimientos.id = ?
                    AND seguimientos.analista_id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $stmt->bind_param('ii', $seguimientoId, $usuarioId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerSeguimientoSupervisor($usuarioId, $seguimientoId)
    {
        $sql = $this->consultaDetalleSeguimientoBase() . "
                INNER JOIN asignaciones_territorio asignacion_analista
                    ON asignacion_analista.usuario_id = seguimientos.analista_id
                    AND asignacion_analista.estado_id = seguimientos.estado_id
                    AND asignacion_analista.tipo_asignacion = 'ANALISTA_DATOS'
                    AND asignacion_analista.activo = 1
                    AND " . $this->condicionAsignacionVigente('asignacion_analista') . "
                INNER JOIN asignaciones_territorio cuenta_clave
                    ON cuenta_clave.id = asignacion_analista.cuenta_clave_asignacion_id
                    AND cuenta_clave.estado_id = seguimientos.estado_id
                    AND cuenta_clave.tipo_asignacion = 'CUENTA_CLAVE'
                    AND cuenta_clave.activo = 1
                    AND cuenta_clave.usuario_id = ?
                    AND " . $this->condicionAsignacionVigente('cuenta_clave') . "
                WHERE seguimientos.id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('ii', $usuarioId, $seguimientoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function obtenerSeguimientoAdministrador($seguimientoId)
    {
        $sql = $this->consultaDetalleSeguimientoBase() . "
                WHERE seguimientos.id = ?
                    AND seguimientos.activo = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function kamPuedeAccederSeguimiento($usuarioId, $seguimientoId)
    {
        return $this->obtenerSeguimientoSupervisor($usuarioId, $seguimientoId) !== null;
    }

    public function obtenerInteraccionesSeguimiento($seguimientoId)
    {
        $sql = "SELECT
                    interacciones.*,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    roles.nombre AS rol
                FROM interacciones_vinculacion interacciones
                INNER JOIN usuarios
                    ON usuarios.id = interacciones.usuario_id
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE interacciones.seguimiento_id = ?
                ORDER BY interacciones.fecha_inicio DESC, interacciones.id DESC";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerOficiosSeguimiento($seguimientoId)
    {
        $sql = "SELECT
                    id,
                    folio,
                    destinatario_nombre,
                    destinatario_cargo,
                    destinatario_correo,
                    estado_oficio,
                    fecha_generacion,
                    fecha_envio
                FROM oficios_vinculacion
                WHERE seguimiento_id = ?
                ORDER BY created_at DESC, id DESC";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerObservacionesSeguimiento($seguimientoId)
    {
        $sql = "SELECT
                    observaciones.*,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    roles.nombre AS rol
                FROM observaciones_seguimiento observaciones
                INNER JOIN usuarios
                    ON usuarios.id = observaciones.autor_id
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE observaciones.seguimiento_id = ?
                    AND observaciones.activo = 1
                ORDER BY observaciones.created_at DESC, observaciones.id DESC";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerUltimasInteraccionesSeguimiento($seguimientoId, $limite = 3)
    {
        $limite = max(1, min(5, (int)$limite));
        $sql = "SELECT
                    interacciones.*,
                    usuarios.nombre,
                    usuarios.apellidos
                FROM interacciones_vinculacion interacciones
                INNER JOIN usuarios
                    ON usuarios.id = interacciones.usuario_id
                WHERE interacciones.seguimiento_id = ?
                ORDER BY interacciones.fecha_inicio DESC, interacciones.id DESC
                LIMIT $limite";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerUltimasObservacionesSeguimiento($seguimientoId, $limite = 2)
    {
        $limite = max(1, min(5, (int)$limite));
        $sql = "SELECT
                    observaciones.*,
                    usuarios.nombre,
                    usuarios.apellidos
                FROM observaciones_seguimiento observaciones
                INNER JOIN usuarios
                    ON usuarios.id = observaciones.autor_id
                WHERE observaciones.seguimiento_id = ?
                    AND observaciones.activo = 1
                ORDER BY observaciones.created_at DESC, observaciones.id DESC
                LIMIT $limite";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('i', $seguimientoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function actualizarContactoSeguimiento($seguimientoId, $usuarioId, $datos, $marcarVerificado)
    {
        $sql = "UPDATE seguimientos_vinculacion
                SET telefono_verificado = ?,
                    whatsapp_verificado = ?,
                    correo_verificado = ?,
                    contacto_nombre = ?,
                    contacto_cargo = ?,
                    observaciones = ?";

        $parametros = [
            $this->valorONulo($datos['telefono_verificado'] ?? ''),
            $this->valorONulo($datos['whatsapp_verificado'] ?? ''),
            $this->valorONulo($datos['correo_verificado'] ?? ''),
            $this->valorONulo($datos['contacto_nombre'] ?? ''),
            $this->valorONulo($datos['contacto_cargo'] ?? ''),
            $this->valorONulo($datos['observaciones'] ?? '')
        ];
        $tipos = 'ssssss';

        if ($marcarVerificado) {
            $sql .= ",
                    datos_verificados = 1,
                    datos_verificados_at = NOW(),
                    datos_verificados_por = ?,
                    estado_seguimiento = 'DATOS_VERIFICADOS'";
            $parametros[] = (int)$usuarioId;
            $tipos .= 'i';
        }

        $sql .= " WHERE id = ? AND activo = 1";
        $parametros[] = (int)$seguimientoId;
        $tipos .= 'i';

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);

        return $stmt->execute();
    }

    public function marcarDatosVerificadosSeguimiento($seguimientoId, $usuarioId)
    {
        $sql = "UPDATE seguimientos_vinculacion
                SET datos_verificados = 1,
                    datos_verificados_at = NOW(),
                    datos_verificados_por = ?,
                    estado_seguimiento = 'DATOS_VERIFICADOS'
                WHERE id = ?
                    AND activo = 1";

        $stmt = $this->connection->prepare($sql);
        $usuarioId = (int)$usuarioId;
        $seguimientoId = (int)$seguimientoId;
        $stmt->bind_param('ii', $usuarioId, $seguimientoId);

        return $stmt->execute();
    }

    public function registrarInteraccionManual($seguimientoId, $usuarioId, $datos)
    {
        $canal = (string)$datos['canal'];
        $resultado = $this->valorONulo($datos['resultado'] ?? '');
        $fechaInicio = (string)$datos['fecha_inicio'];
        $notas = $this->valorONulo($datos['notas'] ?? '');
        $telefonoDestino = $this->valorONulo($datos['telefono_destino'] ?? '');
        $correoDestino = $this->valorONulo($datos['correo_destino'] ?? '');
        $proximaAccionAt = $this->valorONulo($datos['proxima_accion_at'] ?? '');
        $datosVerificados = (int)($datos['datos_verificados'] ?? 0) === 1;
        $descartar = (int)($datos['descartar'] ?? 0) === 1;
        $motivoDescarte = $this->valorONulo($datos['motivo_descarte'] ?? '');

        if ($descartar) {
            $proximaAccionAt = null;
        }

        $estadoSeguimiento = $this->resolverEstadoDespuesInteraccion(
            $canal,
            $resultado,
            $datosVerificados,
            $descartar
        );

        $this->connection->begin_transaction();

        try {
            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                    seguimiento_id,
                    usuario_id,
                    canal,
                    telefono_destino,
                    correo_destino,
                    fecha_inicio,
                    resultado,
                    notas
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $seguimientoId = (int)$seguimientoId;
            $usuarioId = (int)$usuarioId;
            $stmtInteraccion->bind_param(
                'iissssss',
                $seguimientoId,
                $usuarioId,
                $canal,
                $telefonoDestino,
                $correoDestino,
                $fechaInicio,
                $resultado,
                $notas
            );
            $stmtInteraccion->execute();

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                    SET ultima_interaccion_at = ?,
                        proxima_accion_at = ?,
                        estado_seguimiento = ?";

            if ($descartar) {
                $sqlSeguimiento .= ",
                        motivo_descarte = ?";
            }

            $sqlSeguimiento .= "
                    WHERE id = ?
                        AND activo = 1";

            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $parametrosSeguimiento = [
                $fechaInicio,
                $proximaAccionAt,
                $estadoSeguimiento
            ];
            $tiposSeguimiento = 'sss';

            if ($descartar) {
                $parametrosSeguimiento[] = $motivoDescarte;
                $tiposSeguimiento .= 's';
            }

            $parametrosSeguimiento[] = $seguimientoId;
            $tiposSeguimiento .= 'i';
            $this->vincularParametros($stmtSeguimiento, $tiposSeguimiento, $parametrosSeguimiento);
            $stmtSeguimiento->execute();

            $this->connection->commit();

            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            return false;
        }
    }

    public function descartarSeguimientoTrabajo($seguimientoId, $motivoDescarte)
    {
        $sql = "UPDATE seguimientos_vinculacion
                SET estado_seguimiento = 'DESCARTADO',
                    motivo_descarte = ?,
                    proxima_accion_at = NULL
                WHERE id = ?
                    AND activo = 1
                    AND estado_seguimiento <> 'DESCARTADO'";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $motivoDescarte = trim((string)$motivoDescarte);
        $stmt->bind_param('si', $motivoDescarte, $seguimientoId);

        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    public function reactivarSeguimientoTrabajo($seguimientoId, $usuarioId, $motivoReactivacion, $observacion = '')
    {
        $seguimientoId = (int)$seguimientoId;
        $usuarioId = (int)$usuarioId;
        $motivoReactivacion = trim((string)$motivoReactivacion);
        $observacion = trim((string)$observacion);

        $notas = trim(implode("\n", array_filter([
            'Seguimiento reactivado',
            'Motivo de reactivación: ' . $motivoReactivacion,
            $observacion !== '' ? 'Observación: ' . $observacion : '',
            'Próxima acción: Retomar contacto'
        ])));

        $this->connection->begin_transaction();

        try {
            $sqlInteraccion = "INSERT INTO interacciones_vinculacion (
                    seguimiento_id,
                    usuario_id,
                    canal,
                    fecha_inicio,
                    resultado,
                    notas
                ) VALUES (?, ?, 'SISTEMA', NOW(), 'OTRO', ?)";

            $stmtInteraccion = $this->connection->prepare($sqlInteraccion);
            $stmtInteraccion->bind_param('iis', $seguimientoId, $usuarioId, $notas);
            $stmtInteraccion->execute();

            $sqlSeguimiento = "UPDATE seguimientos_vinculacion
                    SET estado_seguimiento = 'CONTACTANDO',
                        ultima_interaccion_at = NOW(),
                        proxima_accion_at = NULL
                    WHERE id = ?
                        AND activo = 1
                        AND estado_seguimiento = 'DESCARTADO'";

            $stmtSeguimiento = $this->connection->prepare($sqlSeguimiento);
            $stmtSeguimiento->bind_param('i', $seguimientoId);
            $stmtSeguimiento->execute();

            if ($stmtSeguimiento->affected_rows <= 0) {
                $this->connection->rollback();
                return false;
            }

            $this->connection->commit();

            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            return false;
        }
    }

    private function resolverEstadoDespuesInteraccion($canal, $resultado, $datosVerificados, $descartar)
    {
        if ($descartar) {
            return 'DESCARTADO';
        }

        if ($datosVerificados) {
            return 'DATOS_VERIFICADOS';
        }

        return 'CONTACTANDO';
    }

    public function contarObservacionesNoLeidas($seguimientoId, $destinatarioId)
    {
        $sql = "SELECT COUNT(*) AS total
                FROM observaciones_seguimiento
                WHERE seguimiento_id = ?
                    AND destinatario_id = ?
                    AND leida = 0
                    AND activo = 1";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $destinatarioId = (int)$destinatarioId;
        $stmt->bind_param('ii', $seguimientoId, $destinatarioId);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc() ?: [];

        return (int)($fila['total'] ?? 0);
    }

    public function crearObservacion($seguimientoId, $autorId, $destinatarioId, $observacion)
    {
        $sql = "INSERT INTO observaciones_seguimiento (
                    seguimiento_id,
                    autor_id,
                    destinatario_id,
                    observacion,
                    leida,
                    activo
                ) VALUES (?, ?, ?, ?, 0, 1)";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $autorId = (int)$autorId;
        $destinatarioId = (int)$destinatarioId;
        $stmt->bind_param('iiis', $seguimientoId, $autorId, $destinatarioId, $observacion);

        return $stmt->execute();
    }

    public function marcarObservacionesLeidas($seguimientoId, $destinatarioId)
    {
        $sql = "UPDATE observaciones_seguimiento
                SET leida = 1,
                    leida_at = NOW()
                WHERE seguimiento_id = ?
                    AND destinatario_id = ?
                    AND leida = 0
                    AND activo = 1";

        $stmt = $this->connection->prepare($sql);
        $seguimientoId = (int)$seguimientoId;
        $destinatarioId = (int)$destinatarioId;
        $stmt->bind_param('ii', $seguimientoId, $destinatarioId);

        return $stmt->execute();
    }

    private function normalizarCandidatoSecretaria($secretaria)
    {
        $titular = trim((string)($secretaria['titular'] ?? ''));
        $cargo = trim((string)($secretaria['cargo_titular'] ?? ''));
        $contexto = trim($titular . ($cargo !== '' ? ' · ' . $cargo : ''));

        return [
            'origen' => 'SECRETARIA',
            'clave_origen' => 'SECRETARIA:' . (int)$secretaria['id'],
            'id_origen' => (int)$secretaria['id'],
            'tipo_entidad' => 'SECRETARIA',
            'nombre' => (string)$secretaria['nombre'],
            'actividad' => 'Administración pública estatal',
            'direccion' => null,
            'telefono' => $this->valorONulo($secretaria['telefono'] ?? null),
            'correo' => $this->valorONulo($secretaria['correo'] ?? null),
            'sitio_web' => $this->valorONulo($secretaria['sitio_web'] ?? null),
            'fuente' => (string)($secretaria['fuente_datos'] ?? 'Sistema'),
            'clave_denue' => $this->valorONulo($secretaria['clave_denue'] ?? null),
            'fecha_actualizacion_denue' => $this->valorONulo(
                $secretaria['fecha_actualizacion_denue'] ?? null
            ),
            'municipio_nombre' => 'Estatal',
            'municipio_id' => null,
            'contexto' => $contexto !== '' ? $contexto : null
        ];
    }

    private function normalizarCandidatoMunicipio($municipio)
    {
        $presidente = trim((string)($municipio['presidente_municipal'] ?? ''));
        $partido = trim((string)($municipio['partido_politico'] ?? ''));
        $redes = trim((string)($municipio['redes_sociales'] ?? ''));
        $contexto = implode(
            ' · ',
            array_values(array_filter([$presidente, $partido, $redes]))
        );

        return [
            'origen' => 'MUNICIPIO',
            'clave_origen' => 'MUNICIPIO:' . (int)$municipio['id'],
            'id_origen' => (int)$municipio['id'],
            'clave_inegi' => (string)($municipio['clave_inegi'] ?? ''),
            'tipo_entidad' => 'MUNICIPIO',
            'nombre' => 'Municipio de ' . (string)$municipio['nombre'],
            'actividad' => 'Administración pública municipal',
            'direccion' => null,
            'telefono' => null,
            'correo' => null,
            'sitio_web' => null,
            'municipio_nombre' => (string)$municipio['nombre'],
            'municipio_id' => (int)$municipio['id'],
            'fuente' => 'Sistema',
            'contexto' => $contexto !== '' ? $contexto : null
        ];
    }

    private function valorONulo($valor)
    {
        $valor = trim((string)$valor);

        return $valor === '' ? null : $valor;
    }

    private function obtenerResumenDesdeConsulta($sql, $parametros, $tipos)
    {
        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        $resumen = $stmt->get_result()->fetch_assoc() ?: [];

        return [
            'en_seguimiento' => (int)($resumen['en_seguimiento'] ?? 0),
            'contactando' => (int)($resumen['contactando'] ?? 0),
            'datos_verificados' => (int)($resumen['datos_verificados'] ?? 0),
            'esperando_respuesta' => (int)($resumen['esperando_respuesta'] ?? 0)
        ];
    }

    private function consultaSeguimientosBase()
    {
        return "SELECT DISTINCT
                    seguimientos.id,
                    seguimientos.nombre_entidad,
                    seguimientos.tipo_entidad,
                    seguimientos.estado_seguimiento,
                    seguimientos.ultima_interaccion_at,
                    seguimientos.fecha_inicio,
                    seguimientos.proxima_accion_at,
                    seguimientos.analista_id,
                    (
                        SELECT TRIM(
                            SUBSTRING_INDEX(
                                SUBSTRING_INDEX(interacciones_accion.notas, 'Próxima acción: ', -1),
                                '\n',
                                1
                            )
                        )
                        FROM interacciones_vinculacion interacciones_accion
                        WHERE interacciones_accion.seguimiento_id = seguimientos.id
                            AND interacciones_accion.notas LIKE '%Próxima acción:%'
                        ORDER BY interacciones_accion.fecha_inicio DESC, interacciones_accion.id DESC
                        LIMIT 1
                    ) AS proxima_accion_texto,
                    municipios.nombre AS municipio,
                    usuarios.nombre AS analista_nombre,
                    usuarios.apellidos AS analista_apellidos,
                    usuarios.foto_perfil AS analista_foto,
                    oficio_reciente.folio,
                    interaccion_reciente.canal AS ultimo_canal
                FROM seguimientos_vinculacion seguimientos
                LEFT JOIN municipios
                    ON municipios.id = seguimientos.municipio_id
                INNER JOIN usuarios
                    ON usuarios.id = seguimientos.analista_id
                LEFT JOIN (
                    SELECT
                        seguimiento_id,
                        MAX(folio) AS folio
                    FROM oficios_vinculacion
                    WHERE folio IS NOT NULL
                        AND folio <> ''
                    GROUP BY seguimiento_id
                ) oficio_reciente
                    ON oficio_reciente.seguimiento_id = seguimientos.id
                LEFT JOIN (
                    SELECT
                        seguimiento_id,
                        SUBSTRING_INDEX(
                            GROUP_CONCAT(canal ORDER BY fecha_inicio DESC, id DESC),
                            ',',
                            1
                        ) AS canal
                    FROM interacciones_vinculacion
                    GROUP BY seguimiento_id
                ) interaccion_reciente
                    ON interaccion_reciente.seguimiento_id = seguimientos.id";
    }

    private function consultaDetalleSeguimientoBase()
    {
        return "SELECT
                    seguimientos.*,
                    estados.nombre AS estado_nombre,
                    (
                        SELECT TRIM(
                            SUBSTRING_INDEX(
                                SUBSTRING_INDEX(interacciones_accion.notas, 'Próxima acción: ', -1),
                                '\n',
                                1
                            )
                        )
                        FROM interacciones_vinculacion interacciones_accion
                        WHERE interacciones_accion.seguimiento_id = seguimientos.id
                            AND interacciones_accion.notas LIKE '%Próxima acción:%'
                        ORDER BY interacciones_accion.fecha_inicio DESC, interacciones_accion.id DESC
                        LIMIT 1
                    ) AS proxima_accion_texto,
                    municipios.nombre AS municipio,
                    usuarios.nombre AS analista_nombre,
                    usuarios.apellidos AS analista_apellidos,
                    usuarios.foto_perfil AS analista_foto,
                    usuarios.correo AS analista_correo
                FROM seguimientos_vinculacion seguimientos
                INNER JOIN estados
                    ON estados.id = seguimientos.estado_id
                LEFT JOIN municipios
                    ON municipios.id = seguimientos.municipio_id
                INNER JOIN usuarios
                    ON usuarios.id = seguimientos.analista_id";
    }

    private function agregarFiltrosSeguimiento(&$sql, &$parametros, &$tipos, $filtros, $alias)
    {
        $analistaId = (int)($filtros['analista_id'] ?? 0);
        $estadoSeguimiento = trim((string)($filtros['estado_seguimiento'] ?? ''));
        $buscar = trim((string)($filtros['buscar'] ?? ''));

        if ($analistaId > 0) {
            $sql .= " AND $alias.analista_id = ?";
            $parametros[] = $analistaId;
            $tipos .= 'i';
        }

        if ($estadoSeguimiento !== '' && $this->estadoSeguimientoValido($estadoSeguimiento)) {
            $sql .= " AND $alias.estado_seguimiento = ?";
            $parametros[] = $estadoSeguimiento;
            $tipos .= 's';
        }

        if ($buscar !== '') {
            $sql .= " AND (
                $alias.nombre_entidad LIKE ?
                OR $alias.actividad_giro LIKE ?
                OR $alias.correo_fuente LIKE ?
                OR $alias.telefono_fuente LIKE ?
            )";
            $busqueda = '%' . $buscar . '%';
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $tipos .= 'ssss';
        }
    }

    private function estadoSeguimientoValido($estado)
    {
        return in_array($estado, [
            'NUEVO',
            'CONTACTANDO',
            'DATOS_VERIFICADOS',
            'NO_LOCALIZADO',
            'DESCARTADO',
            'OFICIO_PREPARADO',
            'ESPERANDO_RESPUESTA'
        ], true);
    }

    private function ordenSeguimientos()
    {
        return " ORDER BY
                    CASE
                        WHEN seguimientos.proxima_accion_at IS NOT NULL
                            AND seguimientos.proxima_accion_at <= NOW()
                        THEN 0
                        ELSE 1
                    END ASC,
                    seguimientos.proxima_accion_at ASC,
                    seguimientos.ultima_interaccion_at DESC,
                    seguimientos.fecha_inicio DESC";
    }

    private function condicionAsignacionVigente($alias)
    {
        return "(
            ($alias.fecha_inicio IS NULL OR $alias.fecha_inicio <= CURDATE())
            AND ($alias.fecha_fin IS NULL OR $alias.fecha_fin >= CURDATE())
        )";
    }

    private function convertirResultadoEnArreglo($resultado)
    {
        $filas = [];

        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
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
}
