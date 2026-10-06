<?php

require_once __DIR__ . '/../../config/db_connection.php';

class DesempenoModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerUsuariosPorRol($rolNombre)
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
                  AND roles.estado = 1
                  AND roles.nombre = ?
                ORDER BY usuarios.nombre, usuarios.apellidos";

        $stmt = $this->connection->prepare($sql);
        $rolNombre = (string)$rolNombre;
        $stmt->bind_param('s', $rolNombre);
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    public function obtenerUsuariosPorIds(array $usuarioIds)
    {
        $ids = $this->normalizarIds($usuarioIds);
        if (empty($ids)) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
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
                  AND usuarios.id IN ($marcadores)
                ORDER BY usuarios.nombre, usuarios.apellidos";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros(
            $stmt,
            str_repeat('i', count($ids)),
            $ids
        );
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    public function obtenerMetricasAnalistas(
        array $usuarios,
        $desde,
        $hasta,
        $estadoId = 0
    ) {
        $ids = $this->normalizarIds(array_column($usuarios, 'id'));
        if (empty($ids)) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $condicionEstado = '';
        $tipos = str_repeat('i', count($ids)) . 'ss';
        $parametros = $ids;
        $parametros[] = (string)$desde;
        $parametros[] = (string)$hasta;

        if ((int)$estadoId > 0) {
            $condicionEstado =
                " AND EXISTS (
                    SELECT 1
                    FROM seguimientos_vinculacion filtro_seguimiento
                    WHERE filtro_seguimiento.id = interacciones.seguimiento_id
                      AND filtro_seguimiento.estado_id = ?
                )";
            $tipos .= 'i';
            $parametros[] = (int)$estadoId;
        }

        $sql = "SELECT
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.usuario,
                    COALESCE(SUM(
                        CASE
                            WHEN interacciones.id IS NOT NULL
                             AND UPPER(TRIM(COALESCE(interacciones.canal, ''))) <> 'SISTEMA'
                             AND COALESCE(interacciones.notas, '') NOT LIKE '%[REGISTRO_LLAMADA_PRUEBA]%'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS interacciones,
                    COALESCE(SUM(
                        CASE
                            WHEN interacciones.canal = 'LLAMADA_IP'
                             AND TRIM(COALESCE(interacciones.proveedor_externo, '')) <> ''
                             AND TRIM(COALESCE(interacciones.id_externo, '')) <> ''
                             AND COALESCE(interacciones.duracion_segundos, 0) > 0
                             AND COALESCE(interacciones.notas, '') NOT LIKE '%[REGISTRO_LLAMADA_PRUEBA]%'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS llamadas_realizadas,
                    COALESCE(SUM(
                        CASE
                            WHEN interacciones.canal = 'LLAMADA_IP'
                             AND TRIM(COALESCE(interacciones.proveedor_externo, '')) <> ''
                             AND TRIM(COALESCE(interacciones.id_externo, '')) <> ''
                             AND COALESCE(interacciones.duracion_segundos, 0) > 0
                             AND (
                                UPPER(TRIM(COALESCE(interacciones.resultado, ''))) IN (
                                    'CONTACTADO',
                                    'SOLICITO_INFORMACION',
                                    'SOLICITO_LLAMAR_DESPUES',
                                    'NO_INTERESADO'
                                )
                                OR COALESCE(interacciones.notas, '') LIKE '%[CONTACTO_EFECTIVO]%'
                             )
                             AND COALESCE(interacciones.notas, '') NOT LIKE '%[SIN_CONTACTO_EFECTIVO]%'
                             AND COALESCE(interacciones.notas, '') NOT LIKE '%[REGISTRO_LLAMADA_PRUEBA]%'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS llamadas_efectivas,
                    COUNT(DISTINCT CASE
                        WHEN interacciones.canal = 'LLAMADA_IP'
                         AND COALESCE(interacciones.notas, '') LIKE '%[VERIFICACION_EFECTIVA]%'
                         AND TRIM(COALESCE(interacciones.proveedor_externo, '')) <> ''
                         AND TRIM(COALESCE(interacciones.id_externo, '')) <> ''
                         AND COALESCE(interacciones.duracion_segundos, 0) > 0
                         AND COALESCE(interacciones.notas, '') NOT LIKE '%[REGISTRO_LLAMADA_PRUEBA]%'
                        THEN CONCAT(
                            interacciones.seguimiento_id,
                            ':',
                            DATE(interacciones.fecha_inicio)
                        )
                        ELSE NULL
                    END) AS verificaciones_efectivas,
                    COUNT(DISTINCT CASE
                        WHEN interacciones.id IS NOT NULL
                         AND UPPER(TRIM(COALESCE(interacciones.canal, ''))) <> 'SISTEMA'
                         AND COALESCE(interacciones.notas, '') NOT LIKE '%[REGISTRO_LLAMADA_PRUEBA]%'
                        THEN interacciones.seguimiento_id
                        ELSE NULL
                    END) AS instituciones_trabajadas
                FROM usuarios
                LEFT JOIN interacciones_vinculacion interacciones
                    ON interacciones.usuario_id = usuarios.id
                    AND interacciones.fecha_inicio >= ?
                    AND interacciones.fecha_inicio <= ?
                    $condicionEstado
                WHERE usuarios.id IN ($marcadores)
                  AND usuarios.estado = 1
                GROUP BY
                    usuarios.id,
                    usuarios.nombre,
                    usuarios.apellidos,
                    usuarios.foto_perfil,
                    usuarios.usuario
                ORDER BY usuarios.nombre, usuarios.apellidos";

        /*
         * Los dos parámetros de fecha aparecen antes de los IDs en SQL por
         * estar dentro del JOIN. Reordena tipos y valores para respetarlo.
         */
        $tiposFinal = 'ss';
        $parametrosFinal = [(string)$desde, (string)$hasta];

        if ((int)$estadoId > 0) {
            $tiposFinal .= 'i';
            $parametrosFinal[] = (int)$estadoId;
        }

        $tiposFinal .= str_repeat('i', count($ids));
        foreach ($ids as $id) {
            $parametrosFinal[] = $id;
        }

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros(
            $stmt,
            $tiposFinal,
            $parametrosFinal
        );
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    public function obtenerTendenciaAnalistas(
        array $usuarioIds,
        $desde,
        $hasta,
        $estadoId = 0
    ) {
        $ids = $this->normalizarIds($usuarioIds);
        if (empty($ids)) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    DATE(interacciones.fecha_inicio) AS fecha,
                    COUNT(*) AS interacciones,
                    COALESCE(SUM(
                        CASE
                            WHEN interacciones.canal = 'LLAMADA_IP'
                             AND TRIM(COALESCE(interacciones.proveedor_externo, '')) <> ''
                             AND TRIM(COALESCE(interacciones.id_externo, '')) <> ''
                             AND COALESCE(interacciones.duracion_segundos, 0) > 0
                             AND (
                                UPPER(TRIM(COALESCE(interacciones.resultado, ''))) IN (
                                    'CONTACTADO',
                                    'SOLICITO_INFORMACION',
                                    'SOLICITO_LLAMAR_DESPUES',
                                    'NO_INTERESADO'
                                )
                                OR COALESCE(interacciones.notas, '') LIKE '%[CONTACTO_EFECTIVO]%'
                             )
                             AND COALESCE(interacciones.notas, '') NOT LIKE '%[SIN_CONTACTO_EFECTIVO]%'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS efectivas
                FROM interacciones_vinculacion interacciones
                INNER JOIN seguimientos_vinculacion seguimientos
                    ON seguimientos.id = interacciones.seguimiento_id
                WHERE interacciones.usuario_id IN ($marcadores)
                  AND interacciones.fecha_inicio >= ?
                  AND interacciones.fecha_inicio <= ?
                  AND UPPER(TRIM(COALESCE(interacciones.canal, ''))) <> 'SISTEMA'
                  AND COALESCE(interacciones.notas, '') NOT LIKE '%[REGISTRO_LLAMADA_PRUEBA]%'";

        $tipos = str_repeat('i', count($ids)) . 'ss';
        $parametros = $ids;
        $parametros[] = (string)$desde;
        $parametros[] = (string)$hasta;

        if ((int)$estadoId > 0) {
            $sql .= " AND seguimientos.estado_id = ?";
            $tipos .= 'i';
            $parametros[] = (int)$estadoId;
        }

        $sql .= " GROUP BY DATE(interacciones.fecha_inicio)
                  ORDER BY fecha ASC";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    public function obtenerMetricasCuentaClave(
        array $usuarios,
        $desde,
        $hasta,
        $estadoId = 0
    ) {
        $salida = [];

        foreach ($usuarios as $usuario) {
            $usuarioId = (int)($usuario['id'] ?? 0);
            if ($usuarioId <= 0) {
                continue;
            }

            $metricas = [
                'id' => $usuarioId,
                'nombre' => (string)($usuario['nombre'] ?? ''),
                'apellidos' => (string)($usuario['apellidos'] ?? ''),
                'foto_perfil' => (string)($usuario['foto_perfil'] ?? ''),
                'usuario' => (string)($usuario['usuario'] ?? ''),
                'aliados_trabajados' => 0,
                'difusiones' => 0,
                'seguimientos' => 0,
                'confirmaciones' => 0
            ];

            $sqlEnvios = "SELECT
                    COUNT(*) AS difusiones,
                    COUNT(DISTINCT envios.seguimiento_id) AS aliados_envios
                FROM aliados_convocatorias_envios envios
                INNER JOIN seguimientos_vinculacion vinculacion
                    ON vinculacion.id = envios.seguimiento_id
                WHERE envios.cuenta_clave_usuario_id = ?
                  AND envios.estado_envio IN ('ENVIADO', 'COMPARTIDO')
                  AND envios.enviado_at >= ?
                  AND envios.enviado_at <= ?";

            $tiposEnvios = 'iss';
            $paramsEnvios = [$usuarioId, (string)$desde, (string)$hasta];

            if ((int)$estadoId > 0) {
                $sqlEnvios .= " AND vinculacion.estado_id = ?";
                $tiposEnvios .= 'i';
                $paramsEnvios[] = (int)$estadoId;
            }

            $stmt = $this->connection->prepare($sqlEnvios);
            $this->vincularParametros($stmt, $tiposEnvios, $paramsEnvios);
            $stmt->execute();
            $envios = $stmt->get_result()->fetch_assoc() ?: [];

            $metricas['difusiones'] = (int)($envios['difusiones'] ?? 0);

            $sqlEventos = "SELECT
                    COUNT(*) AS seguimientos,
                    COUNT(DISTINCT seguimiento.seguimiento_id) AS aliados_eventos,
                    COALESCE(SUM(
                        CASE
                            WHEN UPPER(TRIM(COALESCE(evento.estado_nuevo, ''))) = 'DIFUSION_CONFIRMADA'
                             AND UPPER(TRIM(COALESCE(evento.estado_anterior, ''))) <> 'DIFUSION_CONFIRMADA'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS confirmaciones
                FROM aliados_convocatorias_seguimiento_eventos evento
                INNER JOIN aliados_convocatorias_seguimientos seguimiento
                    ON seguimiento.id = evento.seguimiento_convocatoria_id
                INNER JOIN seguimientos_vinculacion vinculacion
                    ON vinculacion.id = seguimiento.seguimiento_id
                WHERE evento.usuario_id = ?
                  AND evento.created_at >= ?
                  AND evento.created_at <= ?
                  AND NOT (
                    evento.estado_anterior IS NULL
                    AND UPPER(TRIM(COALESCE(evento.estado_nuevo, ''))) = 'ESPERANDO_RESPUESTA'
                  )";

            $tiposEventos = 'iss';
            $paramsEventos = [$usuarioId, (string)$desde, (string)$hasta];

            if ((int)$estadoId > 0) {
                $sqlEventos .= " AND vinculacion.estado_id = ?";
                $tiposEventos .= 'i';
                $paramsEventos[] = (int)$estadoId;
            }

            $stmt = $this->connection->prepare($sqlEventos);
            $this->vincularParametros($stmt, $tiposEventos, $paramsEventos);
            $stmt->execute();
            $eventos = $stmt->get_result()->fetch_assoc() ?: [];

            $metricas['seguimientos'] =
                (int)($eventos['seguimientos'] ?? 0);
            $metricas['confirmaciones'] =
                (int)($eventos['confirmaciones'] ?? 0);

            $sqlTrabajados = "SELECT COUNT(DISTINCT movimientos.seguimiento_id) AS total
                FROM (
                    SELECT envios.seguimiento_id
                    FROM aliados_convocatorias_envios envios
                    INNER JOIN seguimientos_vinculacion vinculacion_envio
                        ON vinculacion_envio.id = envios.seguimiento_id
                    WHERE envios.cuenta_clave_usuario_id = ?
                      AND envios.estado_envio IN ('ENVIADO', 'COMPARTIDO')
                      AND envios.enviado_at >= ?
                      AND envios.enviado_at <= ?";

            $tiposTrabajados = 'iss';
            $paramsTrabajados = [
                $usuarioId,
                (string)$desde,
                (string)$hasta
            ];

            if ((int)$estadoId > 0) {
                $sqlTrabajados .= " AND vinculacion_envio.estado_id = ?";
                $tiposTrabajados .= 'i';
                $paramsTrabajados[] = (int)$estadoId;
            }

            $sqlTrabajados .= "
                    UNION ALL
                    SELECT seguimiento.seguimiento_id
                    FROM aliados_convocatorias_seguimiento_eventos evento
                    INNER JOIN aliados_convocatorias_seguimientos seguimiento
                        ON seguimiento.id = evento.seguimiento_convocatoria_id
                    INNER JOIN seguimientos_vinculacion vinculacion_evento
                        ON vinculacion_evento.id = seguimiento.seguimiento_id
                    WHERE evento.usuario_id = ?
                      AND evento.created_at >= ?
                      AND evento.created_at <= ?
                      AND NOT (
                        evento.estado_anterior IS NULL
                        AND UPPER(TRIM(COALESCE(evento.estado_nuevo, ''))) = 'ESPERANDO_RESPUESTA'
                      )";

            $tiposTrabajados .= 'iss';
            $paramsTrabajados[] = $usuarioId;
            $paramsTrabajados[] = (string)$desde;
            $paramsTrabajados[] = (string)$hasta;

            if ((int)$estadoId > 0) {
                $sqlTrabajados .= " AND vinculacion_evento.estado_id = ?";
                $tiposTrabajados .= 'i';
                $paramsTrabajados[] = (int)$estadoId;
            }

            $sqlTrabajados .= ") movimientos";

            $stmt = $this->connection->prepare($sqlTrabajados);
            $this->vincularParametros(
                $stmt,
                $tiposTrabajados,
                $paramsTrabajados
            );
            $stmt->execute();
            $trabajados = $stmt->get_result()->fetch_assoc() ?: [];

            $metricas['aliados_trabajados'] =
                (int)($trabajados['total'] ?? 0);

            $salida[] = $metricas;
        }

        return $salida;
    }

    public function obtenerTendenciaCuentaClave(
        array $usuarioIds,
        $desde,
        $hasta,
        $estadoId = 0
    ) {
        $ids = $this->normalizarIds($usuarioIds);
        if (empty($ids)) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $params = [];
        $tipos = '';

        $sql = "SELECT
                    movimientos.fecha,
                    SUM(movimientos.difusiones) AS difusiones,
                    SUM(movimientos.seguimientos) AS seguimientos,
                    SUM(movimientos.confirmaciones) AS confirmaciones
                FROM (
                    SELECT
                        DATE(envios.enviado_at) AS fecha,
                        COUNT(*) AS difusiones,
                        0 AS seguimientos,
                        0 AS confirmaciones
                    FROM aliados_convocatorias_envios envios
                    INNER JOIN seguimientos_vinculacion vinculacion_envio
                        ON vinculacion_envio.id = envios.seguimiento_id
                    WHERE envios.cuenta_clave_usuario_id IN ($marcadores)
                      AND envios.estado_envio IN ('ENVIADO', 'COMPARTIDO')
                      AND envios.enviado_at >= ?
                      AND envios.enviado_at <= ?";

        foreach ($ids as $id) {
            $tipos .= 'i';
            $params[] = $id;
        }
        $tipos .= 'ss';
        $params[] = (string)$desde;
        $params[] = (string)$hasta;

        if ((int)$estadoId > 0) {
            $sql .= " AND vinculacion_envio.estado_id = ?";
            $tipos .= 'i';
            $params[] = (int)$estadoId;
        }

        $sql .= " GROUP BY DATE(envios.enviado_at)
                    UNION ALL
                    SELECT
                        DATE(evento.created_at) AS fecha,
                        0 AS difusiones,
                        COUNT(*) AS seguimientos,
                        COALESCE(SUM(
                            CASE
                                WHEN UPPER(TRIM(COALESCE(evento.estado_nuevo, ''))) = 'DIFUSION_CONFIRMADA'
                                 AND UPPER(TRIM(COALESCE(evento.estado_anterior, ''))) <> 'DIFUSION_CONFIRMADA'
                                THEN 1 ELSE 0
                            END
                        ), 0) AS confirmaciones
                    FROM aliados_convocatorias_seguimiento_eventos evento
                    INNER JOIN aliados_convocatorias_seguimientos seguimiento
                        ON seguimiento.id = evento.seguimiento_convocatoria_id
                    INNER JOIN seguimientos_vinculacion vinculacion_evento
                        ON vinculacion_evento.id = seguimiento.seguimiento_id
                    WHERE evento.usuario_id IN ($marcadores)
                      AND evento.created_at >= ?
                      AND evento.created_at <= ?
                      AND NOT (
                        evento.estado_anterior IS NULL
                        AND UPPER(TRIM(COALESCE(evento.estado_nuevo, ''))) = 'ESPERANDO_RESPUESTA'
                      )";

        foreach ($ids as $id) {
            $tipos .= 'i';
            $params[] = $id;
        }
        $tipos .= 'ss';
        $params[] = (string)$desde;
        $params[] = (string)$hasta;

        if ((int)$estadoId > 0) {
            $sql .= " AND vinculacion_evento.estado_id = ?";
            $tipos .= 'i';
            $params[] = (int)$estadoId;
        }

        $sql .= " GROUP BY DATE(evento.created_at)
                ) movimientos
                GROUP BY movimientos.fecha
                ORDER BY movimientos.fecha ASC";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $params);
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    public function obtenerMetricasMarketing(
        array $usuarios,
        $desde,
        $hasta,
        $estadoId = 0
    ) {
        $salida = [];

        foreach ($usuarios as $usuario) {
            $usuarioId = (int)($usuario['id'] ?? 0);
            if ($usuarioId <= 0) {
                continue;
            }

            $metricas = [
                'id' => $usuarioId,
                'nombre' => (string)($usuario['nombre'] ?? ''),
                'apellidos' => (string)($usuario['apellidos'] ?? ''),
                'foto_perfil' => (string)($usuario['foto_perfil'] ?? ''),
                'usuario' => (string)($usuario['usuario'] ?? ''),
                'publicaciones' => 0,
                'territorios_cubiertos' => 0,
                'actualizaciones' => 0,
                'vigentes' => 0
            ];

            $sqlCreadas = "SELECT
                    COUNT(DISTINCT convocatorias.id) AS publicaciones,
                    COUNT(DISTINCT convocatoria_estados.estado_id) AS territorios_cubiertos,
                    COUNT(DISTINCT CASE
                        WHEN convocatorias.estado = 1
                        THEN convocatorias.id
                        ELSE NULL
                    END) AS vigentes
                FROM convocatorias
                LEFT JOIN convocatoria_estados
                    ON convocatoria_estados.convocatoria_id = convocatorias.id
                WHERE convocatorias.creado_por = ?
                  AND convocatorias.created_at >= ?
                  AND convocatorias.created_at <= ?";

            $tiposCreadas = 'iss';
            $paramsCreadas = [
                $usuarioId,
                (string)$desde,
                (string)$hasta
            ];

            if ((int)$estadoId > 0) {
                $sqlCreadas .= " AND EXISTS (
                    SELECT 1
                    FROM convocatoria_estados filtro_estado
                    WHERE filtro_estado.convocatoria_id = convocatorias.id
                      AND filtro_estado.estado_id = ?
                )";
                $tiposCreadas .= 'i';
                $paramsCreadas[] = (int)$estadoId;
            }

            $stmt = $this->connection->prepare($sqlCreadas);
            $this->vincularParametros(
                $stmt,
                $tiposCreadas,
                $paramsCreadas
            );
            $stmt->execute();
            $creadas = $stmt->get_result()->fetch_assoc() ?: [];

            $metricas['publicaciones'] =
                (int)($creadas['publicaciones'] ?? 0);
            $metricas['territorios_cubiertos'] =
                (int)($creadas['territorios_cubiertos'] ?? 0);
            $metricas['vigentes'] =
                (int)($creadas['vigentes'] ?? 0);

            $sqlActualizaciones = "SELECT
                    COUNT(*) AS actualizaciones
                FROM convocatorias
                WHERE convocatorias.actualizado_por = ?
                  AND convocatorias.updated_at >= ?
                  AND convocatorias.updated_at <= ?
                  AND convocatorias.updated_at > convocatorias.created_at";

            $tiposActualizaciones = 'iss';
            $paramsActualizaciones = [
                $usuarioId,
                (string)$desde,
                (string)$hasta
            ];

            if ((int)$estadoId > 0) {
                $sqlActualizaciones .= " AND EXISTS (
                    SELECT 1
                    FROM convocatoria_estados filtro_estado
                    WHERE filtro_estado.convocatoria_id = convocatorias.id
                      AND filtro_estado.estado_id = ?
                )";
                $tiposActualizaciones .= 'i';
                $paramsActualizaciones[] = (int)$estadoId;
            }

            $stmt = $this->connection->prepare($sqlActualizaciones);
            $this->vincularParametros(
                $stmt,
                $tiposActualizaciones,
                $paramsActualizaciones
            );
            $stmt->execute();
            $actualizaciones =
                $stmt->get_result()->fetch_assoc() ?: [];

            $metricas['actualizaciones'] =
                (int)($actualizaciones['actualizaciones'] ?? 0);

            $salida[] = $metricas;
        }

        return $salida;
    }

    public function obtenerTendenciaMarketing(
        array $usuarioIds,
        $desde,
        $hasta,
        $estadoId = 0
    ) {
        $ids = $this->normalizarIds($usuarioIds);
        if (empty($ids)) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    movimientos.fecha,
                    SUM(movimientos.publicaciones) AS publicaciones,
                    SUM(movimientos.actualizaciones) AS actualizaciones
                FROM (
                    SELECT
                        DATE(convocatorias.created_at) AS fecha,
                        COUNT(*) AS publicaciones,
                        0 AS actualizaciones
                    FROM convocatorias
                    WHERE convocatorias.creado_por IN ($marcadores)
                      AND convocatorias.created_at >= ?
                      AND convocatorias.created_at <= ?";

        $tipos = str_repeat('i', count($ids)) . 'ss';
        $params = $ids;
        $params[] = (string)$desde;
        $params[] = (string)$hasta;

        if ((int)$estadoId > 0) {
            $sql .= " AND EXISTS (
                SELECT 1
                FROM convocatoria_estados filtro_estado_creada
                WHERE filtro_estado_creada.convocatoria_id = convocatorias.id
                  AND filtro_estado_creada.estado_id = ?
            )";
            $tipos .= 'i';
            $params[] = (int)$estadoId;
        }

        $sql .= " GROUP BY DATE(convocatorias.created_at)
                    UNION ALL
                    SELECT
                        DATE(convocatorias.updated_at) AS fecha,
                        0 AS publicaciones,
                        COUNT(*) AS actualizaciones
                    FROM convocatorias
                    WHERE convocatorias.actualizado_por IN ($marcadores)
                      AND convocatorias.updated_at >= ?
                      AND convocatorias.updated_at <= ?
                      AND convocatorias.updated_at > convocatorias.created_at";

        foreach ($ids as $id) {
            $tipos .= 'i';
            $params[] = $id;
        }
        $tipos .= 'ss';
        $params[] = (string)$desde;
        $params[] = (string)$hasta;

        if ((int)$estadoId > 0) {
            $sql .= " AND EXISTS (
                SELECT 1
                FROM convocatoria_estados filtro_estado_actualizada
                WHERE filtro_estado_actualizada.convocatoria_id = convocatorias.id
                  AND filtro_estado_actualizada.estado_id = ?
            )";
            $tipos .= 'i';
            $params[] = (int)$estadoId;
        }

        $sql .= " GROUP BY DATE(convocatorias.updated_at)
                ) movimientos
                GROUP BY movimientos.fecha
                ORDER BY movimientos.fecha ASC";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $params);
        $stmt->execute();

        return $this->resultadoArreglo($stmt->get_result());
    }

    private function normalizarIds(array $ids)
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn($id) => $id > 0
        )));
    }

    private function vincularParametros($stmt, $tipos, array $parametros)
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

    private function resultadoArreglo($resultado)
    {
        $filas = [];

        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = $fila;
        }

        return $filas;
    }
}
