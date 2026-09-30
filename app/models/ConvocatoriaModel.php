<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ConvocatoriaModel
{
    private $connection;
    private $soportaActivacionAutomatica = null;
    private $soportaNotificacionesConvocatorias = null;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function desactivarConvocatoriasVencidas()
    {
        if ($this->soportaActivacionAutomatica()) {
            return $this->sincronizarConvocatoriasPorFecha();
        }

        return $this->desactivarSoloVencidas();
    }

    public function sincronizarConvocatoriasPorFecha()
    {
        if (!$this->soportaActivacionAutomatica()) {
            return $this->desactivarSoloVencidas();
        }

        $this->connection->begin_transaction();

        try {
            // Una convocatoria futura siempre permanece inactiva y programada.
            $this->connection->query(
                "UPDATE convocatorias
                 SET estado = 0,
                     activacion_automatica = 1,
                     updated_at = NOW()
                 WHERE fecha_inicio > CURDATE()
                   AND fecha_termino >= fecha_inicio
                   AND (estado <> 0 OR activacion_automatica <> 1)"
            );

            $resultado = $this->connection->query(
                "SELECT
                    id,
                    titulo,
                    tipo_convocatoria,
                    subtipo_convocatoria,
                    fecha_inicio,
                    fecha_termino
                 FROM convocatorias
                 WHERE estado = 0
                   AND activacion_automatica = 1
                   AND fecha_inicio <= CURDATE()
                   AND fecha_termino >= CURDATE()
                 ORDER BY fecha_inicio ASC, id ASC
                 FOR UPDATE"
            );

            $activadas = [];

            while ($convocatoria = $resultado->fetch_assoc()) {
                $convocatoriaId = (int)$convocatoria['id'];

                $stmt = $this->connection->prepare(
                    "UPDATE convocatorias
                     SET estado = 1,
                         activacion_automatica = 0,
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->bind_param('i', $convocatoriaId);
                $stmt->execute();

                $this->registrarNotificacionesActivacion($convocatoria);
                $activadas[] = $convocatoria;
            }

            // Genera alertas de vencimiento para convocatorias todavía activas.
            $this->registrarNotificacionesVencimientoProximo();

            $this->connection->query(
                "UPDATE convocatorias
                 SET estado = 0,
                     activacion_automatica = 0,
                     updated_at = NOW()
                 WHERE fecha_termino < CURDATE()
                   AND (estado <> 0 OR activacion_automatica <> 0)"
            );

            $this->connection->commit();

            return [
                'ok' => true,
                'activadas' => $activadas
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log('Sincronización de convocatorias: ' . $error->getMessage());

            return [
                'ok' => false,
                'activadas' => []
            ];
        }
    }

    public function obtenerEstados()
    {
        $sql = "SELECT id, nombre
                FROM estados
                WHERE estado = 1
                ORDER BY nombre";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerListado(
        $buscar = '',
        $estadoId = 0,
        $estatus = '',
        $categoria = '',
        $tipoConvocatoria = '',
        $subtipoConvocatoria = ''
    )
    {
        $sql = "SELECT
                    convocatorias.id,
                    convocatorias.titulo,
                    convocatorias.imagen,
                    convocatorias.fecha_inicio,
                    convocatorias.fecha_termino,
                    convocatorias.estado,
                    convocatorias.categoria,
                    convocatorias.tipo_convocatoria,
                    convocatorias.subtipo_convocatoria,
                    convocatorias.created_at,
                    convocatorias.updated_at,
                    GROUP_CONCAT(
                        DISTINCT estados.nombre
                        ORDER BY estados.nombre
                        SEPARATOR ', '
                    ) AS estados
                FROM convocatorias
                LEFT JOIN convocatoria_estados
                    ON convocatoria_estados.convocatoria_id = convocatorias.id
                LEFT JOIN estados
                    ON estados.id = convocatoria_estados.estado_id
                WHERE 1 = 1";

        $tipos = '';
        $parametros = [];

        if ($buscar !== '') {
            $sql .= " AND convocatorias.titulo LIKE ?";
            $tipos .= 's';
            $parametros[] = '%' . $buscar . '%';
        }

        if ($estadoId > 0) {
            $sql .= " AND EXISTS (
                        SELECT 1
                        FROM convocatoria_estados filtro_estado
                        WHERE filtro_estado.convocatoria_id = convocatorias.id
                          AND filtro_estado.estado_id = ?
                    )";
            $tipos .= 'i';
            $parametros[] = $estadoId;
        }

        if ($estatus === '0' || $estatus === '1') {
            $sql .= " AND convocatorias.estado = ?";
            $tipos .= 'i';
            $parametros[] = (int)$estatus;
        }

        if ($categoria !== '') {
            $sql .= " AND convocatorias.categoria = ?";
            $tipos .= 's';
            $parametros[] = $categoria;
        }

        if ($tipoConvocatoria !== '' && $subtipoConvocatoria !== '') {
            $sql .= " AND convocatorias.tipo_convocatoria = ?
                      AND convocatorias.subtipo_convocatoria = ?";
            $tipos .= 'ss';
            $parametros[] = $tipoConvocatoria;
            $parametros[] = $subtipoConvocatoria;
        }

        $sql .= " GROUP BY convocatorias.id
                  ORDER BY
                      convocatorias.estado DESC,
                      convocatorias.fecha_inicio DESC,
                      convocatorias.fecha_termino DESC,
                      convocatorias.id DESC";

        if ($tipos === '') {
            $resultado = $this->connection->query($sql);
            return $this->convertirResultadoEnArreglo($resultado);
        }

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function buscarPorId($id)
    {
        $sql = "SELECT
                    convocatorias.*,
                    creador.nombre AS creador_nombre,
                    creador.apellidos AS creador_apellidos,
                    editor.nombre AS editor_nombre,
                    editor.apellidos AS editor_apellidos
                FROM convocatorias
                LEFT JOIN usuarios creador
                    ON creador.id = convocatorias.creado_por
                LEFT JOIN usuarios editor
                    ON editor.id = convocatorias.actualizado_por
                WHERE convocatorias.id = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $convocatoria = $stmt->get_result()->fetch_assoc();

        if (!$convocatoria) {
            return null;
        }

        $convocatoria['estados_ids'] = $this->obtenerEstadosIds($id);
        $convocatoria['estados'] = $this->obtenerNombresEstados($id);

        return $convocatoria;
    }

    public function crear($datos, $estadosIds)
    {
        $this->connection->begin_transaction();

        try {
            $categoria = strcasecmp(trim((string)$datos['titulo']), 'IMJUVE') === 0
                ? 'IMJUVE'
                : null;

            $estado = (int)$datos['estado'];
            $activacionAutomatica = 0;

            if (
                $this->soportaActivacionAutomatica() &&
                (string)$datos['fecha_inicio'] > date('Y-m-d')
            ) {
                $estado = 0;
                $activacionAutomatica = 1;
            }

            if ($this->soportaActivacionAutomatica()) {
                $sql = "INSERT INTO convocatorias (
                            titulo,
                            categoria,
                            tipo_convocatoria,
                            subtipo_convocatoria,
                            imagen,
                            fecha_inicio,
                            fecha_termino,
                            estado,
                            activacion_automatica,
                            creado_por,
                            actualizado_por
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $this->connection->prepare($sql);
                $stmt->bind_param(
                    'sssssssiiii',
                    $datos['titulo'],
                    $categoria,
                    $datos['tipo_convocatoria'],
                    $datos['subtipo_convocatoria'],
                    $datos['imagen'],
                    $datos['fecha_inicio'],
                    $datos['fecha_termino'],
                    $estado,
                    $activacionAutomatica,
                    $datos['usuario_id'],
                    $datos['usuario_id']
                );
            } else {
                $sql = "INSERT INTO convocatorias (
                            titulo,
                            categoria,
                            tipo_convocatoria,
                            subtipo_convocatoria,
                            imagen,
                            fecha_inicio,
                            fecha_termino,
                            estado,
                            creado_por,
                            actualizado_por
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $this->connection->prepare($sql);
                $stmt->bind_param(
                    'sssssssiii',
                    $datos['titulo'],
                    $categoria,
                    $datos['tipo_convocatoria'],
                    $datos['subtipo_convocatoria'],
                    $datos['imagen'],
                    $datos['fecha_inicio'],
                    $datos['fecha_termino'],
                    $estado,
                    $datos['usuario_id'],
                    $datos['usuario_id']
                );
            }

            if (!$stmt->execute()) {
                throw new Exception('No fue posible registrar la convocatoria.');
            }

            $convocatoriaId = (int)$this->connection->insert_id;
            $this->guardarEstados($convocatoriaId, $estadosIds);

            $this->connection->commit();

            return $convocatoriaId;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function actualizar($id, $datos, $estadosIds)
    {
        $this->connection->begin_transaction();

        try {
            $categoria = strcasecmp(trim((string)$datos['titulo']), 'IMJUVE') === 0
                ? 'IMJUVE'
                : null;

            $estado = (int)$datos['estado'];
            $activacionAutomatica = 0;

            if (
                $this->soportaActivacionAutomatica() &&
                (string)$datos['fecha_inicio'] > date('Y-m-d')
            ) {
                $estado = 0;
                $activacionAutomatica = 1;
            }

            if ($this->soportaActivacionAutomatica()) {
                $sql = "UPDATE convocatorias
                        SET titulo = ?,
                            categoria = ?,
                            tipo_convocatoria = ?,
                            subtipo_convocatoria = ?,
                            imagen = ?,
                            fecha_inicio = ?,
                            fecha_termino = ?,
                            estado = ?,
                            activacion_automatica = ?,
                            actualizado_por = ?
                        WHERE id = ?";

                $stmt = $this->connection->prepare($sql);
                $stmt->bind_param(
                    'sssssssiiii',
                    $datos['titulo'],
                    $categoria,
                    $datos['tipo_convocatoria'],
                    $datos['subtipo_convocatoria'],
                    $datos['imagen'],
                    $datos['fecha_inicio'],
                    $datos['fecha_termino'],
                    $estado,
                    $activacionAutomatica,
                    $datos['usuario_id'],
                    $id
                );
            } else {
                $sql = "UPDATE convocatorias
                        SET titulo = ?,
                            categoria = ?,
                            tipo_convocatoria = ?,
                            subtipo_convocatoria = ?,
                            imagen = ?,
                            fecha_inicio = ?,
                            fecha_termino = ?,
                            estado = ?,
                            actualizado_por = ?
                        WHERE id = ?";

                $stmt = $this->connection->prepare($sql);
                $stmt->bind_param(
                    'sssssssiii',
                    $datos['titulo'],
                    $categoria,
                    $datos['tipo_convocatoria'],
                    $datos['subtipo_convocatoria'],
                    $datos['imagen'],
                    $datos['fecha_inicio'],
                    $datos['fecha_termino'],
                    $estado,
                    $datos['usuario_id'],
                    $id
                );
            }

            if (!$stmt->execute()) {
                throw new Exception('No fue posible actualizar la convocatoria.');
            }

            $sqlEliminar = "DELETE FROM convocatoria_estados
                            WHERE convocatoria_id = ?";
            $stmtEliminar = $this->connection->prepare($sqlEliminar);
            $stmtEliminar->bind_param('i', $id);
            $stmtEliminar->execute();

            $this->guardarEstados($id, $estadosIds);

            $this->connection->commit();

            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function cambiarEstado($id, $estado, $usuarioId)
    {
        if ($this->soportaActivacionAutomatica()) {
            $sql = "UPDATE convocatorias
                    SET estado = ?,
                        activacion_automatica = 0,
                        actualizado_por = ?
                    WHERE id = ?";
        } else {
            $sql = "UPDATE convocatorias
                    SET estado = ?,
                        actualizado_por = ?
                    WHERE id = ?";
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('iii', $estado, $usuarioId, $id);

        return $stmt->execute();
    }

    public function obtenerRecientesPorTerritorio($estadoId, $limite = 12)
    {
        $estadoId = (int)$estadoId;
        $limite = max(1, min(20, (int)$limite));

        if ($estadoId <= 0) {
            return [];
        }

        $sql = "SELECT
                    convocatorias.id,
                    convocatorias.titulo,
                    convocatorias.tipo_convocatoria,
                    convocatorias.subtipo_convocatoria,
                    convocatorias.fecha_inicio,
                    convocatorias.fecha_termino,
                    convocatorias.estado,
                    convocatorias.updated_at,
                    CASE
                        WHEN convocatorias.fecha_termino < CURDATE()
                            THEN 'finalizada'
                        WHEN convocatorias.estado = 1
                            AND convocatorias.fecha_termino BETWEEN CURDATE()
                                AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)
                            THEN 'proxima'
                        WHEN convocatorias.estado = 1
                            THEN 'activa'
                        ELSE 'inactiva'
                    END AS estado_proceso
                FROM convocatorias
                WHERE EXISTS (
                    SELECT 1
                    FROM convocatoria_estados
                    WHERE convocatoria_estados.convocatoria_id = convocatorias.id
                      AND convocatoria_estados.estado_id = ?
                )
                ORDER BY convocatorias.updated_at DESC, convocatorias.id DESC
                LIMIT " . $limite;

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerRecientesDashboard($limite = 3)
    {
        $limite = max(1, min(10, (int)$limite));

        $sql = "SELECT
                    convocatorias.id,
                    convocatorias.titulo,
                    convocatorias.tipo_convocatoria,
                    convocatorias.subtipo_convocatoria,
                    convocatorias.fecha_inicio,
                    convocatorias.fecha_termino,
                    convocatorias.estado,
                    convocatorias.created_at,
                    convocatorias.updated_at,
                    (
                        SELECT MIN(convocatoria_estados.estado_id)
                        FROM convocatoria_estados
                        WHERE convocatoria_estados.convocatoria_id = convocatorias.id
                    ) AS territorio_id,
                    CASE
                        WHEN convocatorias.fecha_termino < CURDATE()
                            THEN 'finalizada'
                        WHEN convocatorias.estado = 1
                            AND convocatorias.fecha_termino BETWEEN CURDATE()
                                AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                            THEN 'proxima'
                        WHEN convocatorias.estado = 1
                            THEN 'activa'
                        ELSE 'inactiva'
                    END AS estado_proceso
                FROM convocatorias
                ORDER BY
                    COALESCE(convocatorias.created_at, convocatorias.updated_at) DESC,
                    convocatorias.id DESC
                LIMIT " . $limite;

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerResumenDashboard()
    {
        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) AS activas,
                    SUM(CASE WHEN estado = 0 THEN 1 ELSE 0 END) AS inactivas,
                    SUM(
                        CASE
                            WHEN estado = 1
                                AND CURDATE() BETWEEN fecha_inicio AND fecha_termino
                            THEN 1 ELSE 0
                        END
                    ) AS vigentes,
                    SUM(
                        CASE
                            WHEN estado = 1
                                AND fecha_termino BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                            THEN 1 ELSE 0
                        END
                    ) AS proximas_finalizar
                FROM convocatorias";

        $resultado = $this->connection->query($sql);
        $fila = $resultado->fetch_assoc();

        return [
            'total' => (int)($fila['total'] ?? 0),
            'activas' => (int)($fila['activas'] ?? 0),
            'inactivas' => (int)($fila['inactivas'] ?? 0),
            'vigentes' => (int)($fila['vigentes'] ?? 0),
            'proximas_finalizar' => (int)($fila['proximas_finalizar'] ?? 0)
        ];
    }

    public function obtenerCoberturaTerritorialDashboard($limite = 4)
    {
        $sqlTotalEstados = "SELECT COUNT(*) AS total_estados
                            FROM estados
                            WHERE estado = 1";

        $resultadoTotalEstados = $this->connection->query($sqlTotalEstados);
        $filaTotalEstados = $resultadoTotalEstados->fetch_assoc();
        $totalEstados = (int)($filaTotalEstados['total_estados'] ?? 0);

        $sqlCobertura = "SELECT
                            COUNT(DISTINCT asociaciones.estado_id) AS estados_cubiertos
                         FROM (
                            SELECT DISTINCT
                                convocatoria_estados.convocatoria_id,
                                convocatoria_estados.estado_id
                            FROM convocatoria_estados
                            INNER JOIN convocatorias
                                ON convocatorias.id = convocatoria_estados.convocatoria_id
                            WHERE convocatorias.estado = 1
                         ) asociaciones";

        $resultadoCobertura = $this->connection->query($sqlCobertura);
        $filaCobertura = $resultadoCobertura->fetch_assoc();
        $estadosCubiertos = (int)($filaCobertura['estados_cubiertos'] ?? 0);

        $limite = max(1, min(5, (int)$limite));

        $sqlTop = "SELECT
                        estados.id,
                        estados.nombre,
                        COUNT(*) AS convocatorias_activas
                   FROM (
                        SELECT DISTINCT
                            convocatoria_estados.convocatoria_id,
                            convocatoria_estados.estado_id
                        FROM convocatoria_estados
                        INNER JOIN convocatorias
                            ON convocatorias.id = convocatoria_estados.convocatoria_id
                        WHERE convocatorias.estado = 1
                   ) asociaciones
                   INNER JOIN estados
                       ON estados.id = asociaciones.estado_id
                   WHERE estados.estado = 1
                   GROUP BY estados.id, estados.nombre
                   ORDER BY convocatorias_activas DESC, estados.nombre ASC
                   LIMIT " . $limite;

        $resultadoTop = $this->connection->query($sqlTop);
        $territorios = $this->convertirResultadoEnArreglo($resultadoTop);

        return [
            'total_estados' => $totalEstados,
            'estados_cubiertos' => $estadosCubiertos,
            'territorios' => array_map(
                static function ($fila) {
                    return [
                        'id' => (int)($fila['id'] ?? 0),
                        'nombre' => (string)($fila['nombre'] ?? ''),
                        'convocatorias_activas' => (int)($fila['convocatorias_activas'] ?? 0)
                    ];
                },
                $territorios
            )
        ];
    }

    public function obtenerEstadosSinConvocatoriaActivaDashboard()
    {
        $sql = "SELECT
                    estados.id,
                    estados.nombre
                FROM estados
                WHERE estados.estado = 1
                  AND NOT EXISTS (
                      SELECT 1
                      FROM convocatoria_estados
                      INNER JOIN convocatorias
                          ON convocatorias.id = convocatoria_estados.convocatoria_id
                      WHERE convocatoria_estados.estado_id = estados.id
                        AND convocatorias.estado = 1
                  )
                ORDER BY estados.nombre ASC";

        $resultado = $this->connection->query($sql);

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerPublicacionesPorPeriodoDashboard($dias = 30)
    {
        $diasPermitidos = [7, 30, 90];
        $dias = in_array((int)$dias, $diasPermitidos, true) ? (int)$dias : 30;

        $sql = "SELECT
                    COUNT(*) AS publicaciones
                FROM convocatorias
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . $dias . " DAY)";

        $resultado = $this->connection->query($sql);
        $fila = $resultado->fetch_assoc();

        return (int)($fila['publicaciones'] ?? 0);
    }

    public function obtenerPublicacionesPorTipoDashboard($dias = 30)
    {
        $diasPermitidos = [7, 30, 90];
        $dias = in_array((int)$dias, $diasPermitidos, true) ? (int)$dias : 30;

        $mesesNombres = [
            '01' => 'Ene',
            '02' => 'Feb',
            '03' => 'Mar',
            '04' => 'Abr',
            '05' => 'May',
            '06' => 'Jun',
            '07' => 'Jul',
            '08' => 'Ago',
            '09' => 'Sep',
            '10' => 'Oct',
            '11' => 'Nov',
            '12' => 'Dic'
        ];

        $mesActual = new DateTimeImmutable('first day of this month');
        $mesesBase = [];

        for ($i = 3; $i >= 0; $i--) {
            $fechaMes = $mesActual->modify('-' . $i . ' months');
            $clave = $fechaMes->format('Y-m');
            $numeroMes = $fechaMes->format('m');

            $mesesBase[$clave] = [
                'periodo' => $clave,
                'label' => $mesesNombres[$numeroMes] ?? $numeroMes,
                'total' => 0
            ];
        }

        $salida = [
            'bachillerato' => [
                'total' => 0,
                'meses' => array_values($mesesBase)
            ],
            'titulacion' => [
                'total' => 0,
                'meses' => array_values($mesesBase)
            ]
        ];

        $sqlTotales = "SELECT
                           tipo_convocatoria,
                           COUNT(*) AS total
                       FROM convocatorias
                       WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . $dias . " DAY)
                         AND tipo_convocatoria IN ('bachillerato', 'titulacion')
                       GROUP BY tipo_convocatoria";

        $resultadoTotales = $this->connection->query($sqlTotales);

        while ($fila = $resultadoTotales->fetch_assoc()) {
            $tipo = (string)($fila['tipo_convocatoria'] ?? '');

            if (isset($salida[$tipo])) {
                $salida[$tipo]['total'] = (int)($fila['total'] ?? 0);
            }
        }

        $sqlMeses = "SELECT
                         tipo_convocatoria,
                         DATE_FORMAT(created_at, '%Y-%m') AS periodo,
                         COUNT(*) AS total
                     FROM convocatorias
                     WHERE created_at >= DATE_FORMAT(
                         DATE_SUB(CURDATE(), INTERVAL 3 MONTH),
                         '%Y-%m-01'
                     )
                       AND tipo_convocatoria IN ('bachillerato', 'titulacion')
                     GROUP BY tipo_convocatoria, DATE_FORMAT(created_at, '%Y-%m')
                     ORDER BY periodo ASC";

        $resultadoMeses = $this->connection->query($sqlMeses);

        $indicesMeses = [];
        foreach (array_keys($mesesBase) as $indice => $periodo) {
            $indicesMeses[$periodo] = $indice;
        }

        while ($fila = $resultadoMeses->fetch_assoc()) {
            $tipo = (string)($fila['tipo_convocatoria'] ?? '');
            $periodo = (string)($fila['periodo'] ?? '');

            if (!isset($salida[$tipo], $indicesMeses[$periodo])) {
                continue;
            }

            $indiceMes = $indicesMeses[$periodo];
            $salida[$tipo]['meses'][$indiceMes]['total'] = (int)($fila['total'] ?? 0);
        }

        return $salida;
    }

    private function desactivarSoloVencidas()
    {
        $sql = "UPDATE convocatorias
                SET estado = 0,
                    updated_at = NOW()
                WHERE estado = 1
                  AND fecha_termino < CURDATE()";

        return $this->connection->query($sql);
    }

    private function soportaActivacionAutomatica()
    {
        if ($this->soportaActivacionAutomatica !== null) {
            return $this->soportaActivacionAutomatica;
        }

        try {
            $resultado = $this->connection->query(
                "SHOW COLUMNS FROM convocatorias LIKE 'activacion_automatica'"
            );
            $this->soportaActivacionAutomatica = $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            $this->soportaActivacionAutomatica = false;
        }

        return $this->soportaActivacionAutomatica;
    }

    private function soportaNotificacionesConvocatorias()
    {
        if ($this->soportaNotificacionesConvocatorias !== null) {
            return $this->soportaNotificacionesConvocatorias;
        }

        try {
            $resultado = $this->connection->query(
                "SHOW TABLES LIKE 'notificaciones_convocatorias'"
            );
            $this->soportaNotificacionesConvocatorias =
                $resultado && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            $this->soportaNotificacionesConvocatorias = false;
        }

        return $this->soportaNotificacionesConvocatorias;
    }

    private function registrarNotificacionesActivacion($convocatoria)
    {
        if (!$this->soportaNotificacionesConvocatorias()) {
            return;
        }

        $convocatoriaId = (int)($convocatoria['id'] ?? 0);
        if ($convocatoriaId <= 0) {
            return;
        }

        $tituloConvocatoria = trim((string)($convocatoria['titulo'] ?? 'Convocatoria'));
        $tipo = trim((string)($convocatoria['tipo_convocatoria'] ?? ''));
        $subtipo = trim((string)($convocatoria['subtipo_convocatoria'] ?? ''));
        $estadosIds = $this->obtenerEstadosIds($convocatoriaId);
        $estadosNombres = $this->obtenerNombresEstados($convocatoriaId);
        $territorios = !empty($estadosNombres)
            ? implode(', ', $estadosNombres)
            : 'el territorio asociado';

        $mensaje = 'La convocatoria "' . $tituloConvocatoria .
            '" se activó automáticamente al llegar su fecha de inicio. Estado(s): ' .
            $territorios . '.';

        $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        $url = $baseUrl . 'index.php?controller=convocatoria&action=index';

        if (!empty($estadosIds)) {
            $url .= '&territorio_id=' . (int)$estadosIds[0];
        }

        if ($tipo !== '') {
            $url .= '&tipo=' . rawurlencode($tipo);
        }

        if ($subtipo !== '') {
            $url .= '&subtipo=' . rawurlencode($subtipo);
        }

        if ($tituloConvocatoria !== '') {
            $url .= '&buscar=' . rawurlencode($tituloConvocatoria);
        }

        $tituloNotificacion = 'Convocatoria activada: ' . $tituloConvocatoria;

        $sql = "INSERT INTO notificaciones_convocatorias (
                    usuario_id,
                    convocatoria_id,
                    titulo,
                    mensaje,
                    url,
                    tipo_evento,
                    leida
                )
                SELECT
                    usuarios.id,
                    ?,
                    ?,
                    ?,
                    ?,
                    'activacion_automatica',
                    0
                FROM usuarios
                INNER JOIN roles
                    ON roles.id = usuarios.rol_id
                WHERE usuarios.estado = 1
                  AND LOWER(roles.nombre) = 'marketing'";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'isss',
            $convocatoriaId,
            $tituloNotificacion,
            $mensaje,
            $url
        );
        $stmt->execute();
    }

    private function registrarNotificacionesVencimientoProximo()
    {
        if (!$this->soportaNotificacionesConvocatorias()) {
            return;
        }

        $resultado = $this->connection->query(
            "SELECT
                id,
                titulo,
                tipo_convocatoria,
                subtipo_convocatoria,
                fecha_termino,
                DATEDIFF(fecha_termino, CURDATE()) AS dias_restantes
             FROM convocatorias
             WHERE estado = 1
               AND fecha_termino BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
             ORDER BY fecha_termino ASC, id ASC"
        );

        while ($convocatoria = $resultado->fetch_assoc()) {
            $convocatoriaId = (int)($convocatoria['id'] ?? 0);
            $diasRestantes = (int)($convocatoria['dias_restantes'] ?? -1);

            if ($convocatoriaId <= 0 || !in_array($diasRestantes, [0, 1, 2], true)) {
                continue;
            }

            $tituloConvocatoria = trim((string)($convocatoria['titulo'] ?? 'Convocatoria'));
            $tipo = trim((string)($convocatoria['tipo_convocatoria'] ?? ''));
            $subtipo = trim((string)($convocatoria['subtipo_convocatoria'] ?? ''));
            $fechaTermino = trim((string)($convocatoria['fecha_termino'] ?? ''));
            $fechaLegible = $fechaTermino !== ''
                ? date('d/m/Y', strtotime($fechaTermino))
                : 'la fecha programada';

            $estadosIds = $this->obtenerEstadosIds($convocatoriaId);
            $estadosNombres = $this->obtenerNombresEstados($convocatoriaId);
            $territorios = !empty($estadosNombres)
                ? implode(', ', $estadosNombres)
                : 'el territorio asociado';

            if ($diasRestantes === 2) {
                $tipoEvento = 'vencimiento_2_dias';
                $tituloNotificacion = 'Convocatoria vence en 2 días: ' . $tituloConvocatoria;
                $mensaje = 'La convocatoria "' . $tituloConvocatoria .
                    '" vence en 2 días (' . $fechaLegible . '). Estado(s): ' .
                    $territorios . '.';
            } elseif ($diasRestantes === 1) {
                $tipoEvento = 'vencimiento_1_dia';
                $tituloNotificacion = 'Convocatoria vence mañana: ' . $tituloConvocatoria;
                $mensaje = 'La convocatoria "' . $tituloConvocatoria .
                    '" vence mañana (' . $fechaLegible . '). Estado(s): ' .
                    $territorios . '.';
            } else {
                $tipoEvento = 'vencimiento_hoy';
                $tituloNotificacion = 'Convocatoria vence hoy: ' . $tituloConvocatoria;
                $mensaje = 'La convocatoria "' . $tituloConvocatoria .
                    '" vence hoy (' . $fechaLegible . '). Estado(s): ' .
                    $territorios . '.';
            }

            $baseUrl = defined('BASE_URL') ? BASE_URL : '';
            $url = $baseUrl . 'index.php?controller=convocatoria&action=index';

            if (!empty($estadosIds)) {
                $url .= '&territorio_id=' . (int)$estadosIds[0];
            }

            if ($tipo !== '') {
                $url .= '&tipo=' . rawurlencode($tipo);
            }

            if ($subtipo !== '') {
                $url .= '&subtipo=' . rawurlencode($subtipo);
            }

            if ($tituloConvocatoria !== '') {
                $url .= '&buscar=' . rawurlencode($tituloConvocatoria);
            }

            $sql = "INSERT INTO notificaciones_convocatorias (
                        usuario_id,
                        convocatoria_id,
                        titulo,
                        mensaje,
                        url,
                        tipo_evento,
                        leida
                    )
                    SELECT
                        usuarios.id,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        0
                    FROM usuarios
                    INNER JOIN roles
                        ON roles.id = usuarios.rol_id
                    WHERE usuarios.estado = 1
                      AND LOWER(roles.nombre) = 'marketing'
                      AND NOT EXISTS (
                          SELECT 1
                          FROM notificaciones_convocatorias existentes
                          WHERE existentes.usuario_id = usuarios.id
                            AND existentes.convocatoria_id = ?
                            AND existentes.tipo_evento = ?
                      )";

            $stmt = $this->connection->prepare($sql);
            $stmt->bind_param(
                'issssis',
                $convocatoriaId,
                $tituloNotificacion,
                $mensaje,
                $url,
                $tipoEvento,
                $convocatoriaId,
                $tipoEvento
            );
            $stmt->execute();
        }
    }

    private function obtenerEstadosIds($convocatoriaId)
    {
        $sql = "SELECT estado_id
                FROM convocatoria_estados
                WHERE convocatoria_id = ?
                ORDER BY estado_id";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $convocatoriaId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $ids = [];

        while ($fila = $resultado->fetch_assoc()) {
            $ids[] = (int)$fila['estado_id'];
        }

        return $ids;
    }

    private function obtenerNombresEstados($convocatoriaId)
    {
        $sql = "SELECT estados.nombre
                FROM convocatoria_estados
                INNER JOIN estados
                    ON estados.id = convocatoria_estados.estado_id
                WHERE convocatoria_estados.convocatoria_id = ?
                ORDER BY estados.nombre";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $convocatoriaId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $nombres = [];

        while ($fila = $resultado->fetch_assoc()) {
            $nombres[] = $fila['nombre'];
        }

        return $nombres;
    }

    private function guardarEstados($convocatoriaId, $estadosIds)
    {
        $sql = "INSERT INTO convocatoria_estados (
                    convocatoria_id,
                    estado_id
                ) VALUES (?, ?)";

        $stmt = $this->connection->prepare($sql);

        foreach ($estadosIds as $estadoId) {
            $estadoId = (int)$estadoId;
            $stmt->bind_param('ii', $convocatoriaId, $estadoId);

            if (!$stmt->execute()) {
                throw new Exception('No fue posible asociar los estados.');
            }
        }
    }

    private function vincularParametros($stmt, $tipos, $parametros)
    {
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
