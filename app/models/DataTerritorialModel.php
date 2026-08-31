<?php

require_once __DIR__ . '/../../config/db_connection.php';

class DataTerritorialModel
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function obtenerEstadosActivosParaActualizacionOficial(): array
    {
        $sql = "SELECT id, nombre
                FROM estados
                WHERE estado = 1
                ORDER BY nombre";

        $resultado = $this->connection->query($sql);

        if (!$resultado) {
            return [];
        }

        return $this->convertirResultadoEnArreglo($resultado);
    }

    public function obtenerTerritoriosUsuario($usuarioId, $rolId, $buscar = '')
    {
        $buscar = trim((string)$buscar);

        if ((int)$rolId === 1) {
            $sql = "SELECT
                        estados.id,
                        estados.nombre,
                        estados.nombre_corto,
                        estados.capital,
                        estados.total_secretarias,
                        estados.total_municipios,
                        estados.fecha_actualizacion,
                        (
                            SELECT COUNT(*)
                            FROM municipios
                            WHERE municipios.estado_id = estados.id
                                AND municipios.estado = 1
                        ) AS municipios_cargados,
                        (
                            SELECT COUNT(*)
                            FROM secretarias_estatales
                            WHERE secretarias_estatales.estado_id = estados.id
                                AND secretarias_estatales.estado = 1
                        ) AS secretarias_cargadas,
                        (
                            SELECT COUNT(*)
                            FROM rezago_educativo
                            WHERE rezago_educativo.estado_id = estados.id
                                AND rezago_educativo.estado = 1
                        ) AS indicadores_cargados,
                        " . $this->expresionTieneInformacionTerritorial() . "
                            AS tiene_informacion_territorial
                    FROM estados
                    WHERE estados.estado = 1";
            $parametros = [];
            $tipos = '';

            if ($buscar !== '') {
                $sql .= " AND (
                    estados.nombre LIKE ?
                    OR estados.nombre_corto LIKE ?
                    OR estados.capital LIKE ?
                )";
                $busqueda = '%' . $buscar . '%';
                $parametros = [$busqueda, $busqueda, $busqueda];
                $tipos = 'sss';
            }

            $sql .= " ORDER BY estados.nombre";

            $stmt = $this->connection->prepare($sql);
            $this->vincularParametros($stmt, $tipos, $parametros);
            $stmt->execute();

            return $this->convertirResultadoEnArreglo($stmt->get_result());
        }

        $tipoAsignacion = $this->obtenerTipoAsignacionPorRol($rolId);

        if ($tipoAsignacion === '') {
            return [];
        }

        $sql = "SELECT DISTINCT
                    estados.id,
                    estados.nombre,
                    estados.nombre_corto,
                    estados.capital,
                    estados.total_secretarias,
                    estados.total_municipios,
                    estados.fecha_actualizacion,
                    (
                        SELECT COUNT(*)
                        FROM municipios
                        WHERE municipios.estado_id = estados.id
                            AND municipios.estado = 1
                    ) AS municipios_cargados,
                    (
                        SELECT COUNT(*)
                        FROM secretarias_estatales
                        WHERE secretarias_estatales.estado_id = estados.id
                            AND secretarias_estatales.estado = 1
                    ) AS secretarias_cargadas,
                    (
                        SELECT COUNT(*)
                        FROM rezago_educativo
                        WHERE rezago_educativo.estado_id = estados.id
                            AND rezago_educativo.estado = 1
                    ) AS indicadores_cargados,
                    " . $this->expresionTieneInformacionTerritorial() . "
                        AS tiene_informacion_territorial
                FROM asignaciones_territorio
                INNER JOIN estados
                    ON estados.id = asignaciones_territorio.estado_id
                WHERE asignaciones_territorio.usuario_id = ?
                    AND asignaciones_territorio.tipo_asignacion = ?
                    AND asignaciones_territorio.activo = 1
                    AND estados.estado = 1";

        $parametros = [(int)$usuarioId, $tipoAsignacion];
        $tipos = 'is';

        if ($buscar !== '') {
            $sql .= " AND (
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

        $sql .= " ORDER BY estados.nombre";

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function puedeAccederEstado($usuarioId, $rolId, $estadoId)
    {
        if ((int)$rolId === 1) {
            return $this->existeEstadoActivo($estadoId);
        }

        $tipoAsignacion = $this->obtenerTipoAsignacionPorRol($rolId);

        if ($tipoAsignacion === '') {
            return false;
        }

        $sql = "SELECT asignaciones_territorio.id
                FROM asignaciones_territorio
                INNER JOIN estados
                    ON estados.id = asignaciones_territorio.estado_id
                WHERE asignaciones_territorio.usuario_id = ?
                    AND asignaciones_territorio.estado_id = ?
                    AND asignaciones_territorio.tipo_asignacion = ?
                    AND asignaciones_territorio.activo = 1
                    AND estados.estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("iis", $usuarioId, $estadoId, $tipoAsignacion);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    public function obtenerEstado($estadoId)
    {
        $sql = "SELECT
                    id,
                    clave_inegi,
                    nombre,
                    nombre_corto,
                    capital,
                    mapa_estado,
                    titular_gobierno,
                    foto_titular,
                    cargo_titular,
                    partido_politico,
                    poblacion,
                    total_municipios,
                    total_secretarias,
                    periodo_gobierno,
                    telefono,
                    redes_sociales,
                    actividad_economica,
                    poder_adquisitivo,
                    fuente,
                    fecha_actualizacion,
                    estado,
                    (
                        SELECT COUNT(*)
                        FROM municipios
                        WHERE municipios.estado_id = estados.id
                            AND municipios.estado = 1
                    ) AS municipios_cargados,
                    (
                        SELECT COUNT(*)
                        FROM secretarias_estatales
                        WHERE secretarias_estatales.estado_id = estados.id
                            AND secretarias_estatales.estado = 1
                    ) AS secretarias_cargadas,
                    (
                        SELECT COUNT(*)
                        FROM rezago_educativo
                        WHERE rezago_educativo.estado_id = estados.id
                            AND rezago_educativo.estado = 1
                    ) AS indicadores_cargados,
                    " . $this->expresionTieneInformacionTerritorial() . "
                        AS tiene_informacion_territorial
                FROM estados
                WHERE estados.id = ?
                    AND estados.estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    public function obtenerSecretarias($estadoId)
    {
        $sql = "SELECT
                    id,
                    estado_id,
                    nombre,
                    titular,
                    cargo_titular,
                    correo,
                    telefono,
                    sitio_web,
                    estado
                FROM secretarias_estatales
                WHERE estado_id = ?
                ORDER BY estado DESC, nombre";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerIndicadoresEducativos($estadoId)
    {
        $sql = "SELECT
                    id,
                    estado_id,
                    situacion,
                    valor,
                    unidad,
                    porcentaje,
                    cantidad_aproximada,
                    fuente,
                    periodo,
                    fecha_consulta,
                    orden,
                    estado
                FROM rezago_educativo
                WHERE estado_id = ?
                    AND estado = 1
                ORDER BY orden, situacion";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function obtenerActividadEconomicaEstado(int $estadoId): array
    {
        if ($estadoId <= 0) {
            return [
                'total_establecimientos' => 0,
                'sectores' => []
            ];
        }

        $sql = "SELECT
                    clave_sector,
                    nombre_sector,
                    establecimientos,
                    porcentaje,
                    periodo,
                    fuente,
                    fecha_consulta,
                    tipo_actualizacion
                FROM actividad_economica_estado
                WHERE estado_id = ?
                ORDER BY establecimientos DESC, nombre_sector";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        $sectores = $this->convertirResultadoEnArreglo($stmt->get_result());
        $totalEstablecimientos = 0;

        foreach ($sectores as $sector) {
            $totalEstablecimientos += (int)($sector['establecimientos'] ?? 0);
        }

        return [
            'total_establecimientos' => $totalEstablecimientos,
            'sectores' => $sectores
        ];
    }

    public function obtenerComparacionEconomicaNacional(int $estadoId): array
    {
        $respuestaVacia = [
            'disponible' => false,
            'mensaje' => '',
            'total_establecimientos_estado' => 0,
            'total_establecimientos_nacional' => 0,
            'sectores' => []
        ];

        if ($estadoId <= 0) {
            $respuestaVacia['mensaje'] = 'El estado solicitado no es válido.';
            return $respuestaVacia;
        }

        $sqlReferencia = "SELECT
                    COUNT(DISTINCT estado_id) AS total_estados,
                    COALESCE(SUM(establecimientos), 0) AS total_nacional
                FROM actividad_economica_estado";

        $stmtReferencia = $this->connection->prepare($sqlReferencia);
        $stmtReferencia->execute();
        $referencia = $stmtReferencia->get_result()->fetch_assoc();
        $totalEstados = (int)($referencia['total_estados'] ?? 0);
        $totalNacional = (int)($referencia['total_nacional'] ?? 0);

        if ($totalEstados !== 32 || $totalNacional <= 0) {
            $respuestaVacia['mensaje'] = 'La referencia nacional aún no está completa.';
            return $respuestaVacia;
        }

        $sqlEstado = "SELECT
                    clave_sector,
                    nombre_sector,
                    establecimientos,
                    porcentaje
                FROM actividad_economica_estado
                WHERE estado_id = ?
                ORDER BY establecimientos DESC, nombre_sector";

        $stmtEstado = $this->connection->prepare($sqlEstado);
        $stmtEstado->bind_param("i", $estadoId);
        $stmtEstado->execute();
        $sectoresEstado = $this->convertirResultadoEnArreglo($stmtEstado->get_result());

        if (empty($sectoresEstado)) {
            $respuestaVacia['mensaje'] = 'El estado no tiene actividad económica oficial registrada.';
            $respuestaVacia['total_establecimientos_nacional'] = $totalNacional;
            return $respuestaVacia;
        }

        $totalEstado = 0;

        foreach ($sectoresEstado as $sector) {
            $totalEstado += (int)($sector['establecimientos'] ?? 0);
        }

        if ($totalEstado <= 0) {
            $respuestaVacia['mensaje'] = 'El estado no tiene establecimientos registrados.';
            $respuestaVacia['total_establecimientos_nacional'] = $totalNacional;
            return $respuestaVacia;
        }

        $sqlSectoresNacionales = "SELECT
                    clave_sector,
                    COALESCE(SUM(establecimientos), 0) AS establecimientos_nacionales
                FROM actividad_economica_estado
                GROUP BY clave_sector";

        $stmtSectoresNacionales = $this->connection->prepare($sqlSectoresNacionales);
        $stmtSectoresNacionales->execute();
        $sectoresNacionales = $this->convertirResultadoEnArreglo(
            $stmtSectoresNacionales->get_result()
        );
        $establecimientosNacionalesPorSector = [];

        foreach ($sectoresNacionales as $sectorNacional) {
            $establecimientosNacionalesPorSector[(string)$sectorNacional['clave_sector']] =
                (int)($sectorNacional['establecimientos_nacionales'] ?? 0);
        }

        $sectoresComparados = [];

        foreach ($sectoresEstado as $sectorEstado) {
            $claveSector = (string)($sectorEstado['clave_sector'] ?? '');
            $establecimientosNacionales =
                $establecimientosNacionalesPorSector[$claveSector] ?? 0;
            $porcentajeEstatal = round((float)($sectorEstado['porcentaje'] ?? 0), 2);
            $porcentajeNacional = $totalNacional > 0
                ? round(($establecimientosNacionales / $totalNacional) * 100, 2)
                : 0.0;
            $diferenciaPuntos = round($porcentajeEstatal - $porcentajeNacional, 2);
            $indiceRelativo = $porcentajeNacional > 0
                ? round($porcentajeEstatal / $porcentajeNacional, 2)
                : null;

            $sectoresComparados[] = [
                'clave_sector' => $claveSector,
                'nombre_sector' => $sectorEstado['nombre_sector'] ?? '',
                'establecimientos_estado' => (int)($sectorEstado['establecimientos'] ?? 0),
                'porcentaje_estatal' => $porcentajeEstatal,
                'porcentaje_nacional' => $porcentajeNacional,
                'diferencia_puntos' => $diferenciaPuntos,
                'indice_relativo' => $indiceRelativo
            ];
        }

        usort($sectoresComparados, function ($sectorA, $sectorB) {
            return $sectorB['diferencia_puntos'] <=> $sectorA['diferencia_puntos'];
        });

        return [
            'disponible' => true,
            'total_establecimientos_estado' => $totalEstado,
            'total_establecimientos_nacional' => $totalNacional,
            'sectores' => $sectoresComparados
        ];
    }

    public function obtenerPoderAdquisitivoEstado(int $estadoId): array
    {
        $respuestaVacia = [
            'disponible' => false,
            'anio' => null,
            'trimestre' => null,
            'ingreso_laboral_real_per_capita' => null,
            'pobreza_laboral' => null,
            'diferencia_ingreso_nacional' => null,
            'diferencia_pobreza_nacional' => null,
            'referencia_nacional' => null,
            'fuente' => '',
            'archivo_origen' => '',
            'fecha_consulta' => null,
            'tipo_actualizacion' => ''
        ];

        if ($estadoId <= 0) {
            return $respuestaVacia;
        }

        $sqlEstado = "SELECT
                    anio,
                    trimestre,
                    ingreso_laboral_real_per_capita,
                    pobreza_laboral,
                    fuente,
                    archivo_origen,
                    fecha_consulta,
                    tipo_actualizacion
                FROM poder_adquisitivo_estado
                WHERE estado_id = ?
                ORDER BY anio DESC, trimestre DESC
                LIMIT 1";

        $stmtEstado = $this->connection->prepare($sqlEstado);
        $stmtEstado->bind_param("i", $estadoId);
        $stmtEstado->execute();
        $estado = $stmtEstado->get_result()->fetch_assoc();

        if (!$estado) {
            return $respuestaVacia;
        }

        $anio = (int)($estado['anio'] ?? 0);
        $trimestre = (int)($estado['trimestre'] ?? 0);
        $ingresoEstado = isset($estado['ingreso_laboral_real_per_capita'])
            ? (float)$estado['ingreso_laboral_real_per_capita']
            : null;
        $pobrezaEstado = isset($estado['pobreza_laboral'])
            ? (float)$estado['pobreza_laboral']
            : null;

        if (
            $anio <= 0 ||
            $trimestre < 1 ||
            $trimestre > 4 ||
            $ingresoEstado === null ||
            $pobrezaEstado === null
        ) {
            return $respuestaVacia;
        }

        $sqlNacional = "SELECT
                    ingreso_laboral_real_per_capita,
                    pobreza_laboral
                FROM poder_adquisitivo_estado
                WHERE clave_geografica = '00'
                    AND anio = ?
                    AND trimestre = ?
                LIMIT 1";

        $stmtNacional = $this->connection->prepare($sqlNacional);
        $stmtNacional->bind_param("ii", $anio, $trimestre);
        $stmtNacional->execute();
        $nacional = $stmtNacional->get_result()->fetch_assoc();

        $referenciaNacional = null;
        $diferenciaIngreso = null;
        $diferenciaPobreza = null;

        if ($nacional) {
            $ingresoNacional = isset($nacional['ingreso_laboral_real_per_capita'])
                ? (float)$nacional['ingreso_laboral_real_per_capita']
                : null;
            $pobrezaNacional = isset($nacional['pobreza_laboral'])
                ? (float)$nacional['pobreza_laboral']
                : null;

            if ($ingresoNacional !== null && $pobrezaNacional !== null) {
                $referenciaNacional = [
                    'ingreso_laboral_real_per_capita' => round($ingresoNacional, 2),
                    'pobreza_laboral' => round($pobrezaNacional, 2)
                ];
                $diferenciaIngreso = round($ingresoEstado - $ingresoNacional, 2);
                $diferenciaPobreza = round($pobrezaEstado - $pobrezaNacional, 2);
            }
        }

        return [
            'disponible' => true,
            'anio' => $anio,
            'trimestre' => $trimestre,
            'ingreso_laboral_real_per_capita' => round($ingresoEstado, 2),
            'pobreza_laboral' => round($pobrezaEstado, 2),
            'diferencia_ingreso_nacional' => $diferenciaIngreso,
            'diferencia_pobreza_nacional' => $diferenciaPobreza,
            'referencia_nacional' => $referenciaNacional,
            'fuente' => (string)($estado['fuente'] ?? ''),
            'archivo_origen' => (string)($estado['archivo_origen'] ?? ''),
            'fecha_consulta' => $estado['fecha_consulta'] ?? null,
            'tipo_actualizacion' => (string)($estado['tipo_actualizacion'] ?? '')
        ];
    }

    public function contarRegistrosPoderAdquisitivoPeriodo(int $anio, int $trimestre): int
    {
        if ($anio <= 0 || $trimestre < 1 || $trimestre > 4) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS total
                FROM poder_adquisitivo_estado
                WHERE anio = ?
                    AND trimestre = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("ii", $anio, $trimestre);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return (int)($fila['total'] ?? 0);
    }

    public function importarPoderAdquisitivoOficial(
        array $lectura,
        string $archivoOrigen,
        int $usuarioId
    ): array {
        $periodo = $lectura['periodo'] ?? [];
        $datos = $lectura['datos'] ?? [];
        $anio = (int)($periodo['anio'] ?? 0);
        $trimestre = (int)($periodo['trimestre'] ?? 0);
        $archivoOrigen = trim(basename($archivoOrigen));

        if ($anio <= 0 || $trimestre < 1 || $trimestre > 4) {
            throw new InvalidArgumentException('El periodo del archivo no es válido.');
        }

        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('El usuario que realiza la importación no es válido.');
        }

        if (count($datos) !== 33) {
            throw new InvalidArgumentException('La importación debe contener 32 Estados y la referencia nacional.');
        }

        if ($archivoOrigen === '' || strlen($archivoOrigen) > 255) {
            throw new InvalidArgumentException('El nombre del archivo de origen no es válido.');
        }

        $sqlEstados = "SELECT id, clave_inegi
                FROM estados
                WHERE estado = 1
                    AND clave_inegi REGEXP '^[0-9]{2}$'";
        $stmtEstados = $this->connection->prepare($sqlEstados);
        $stmtEstados->execute();
        $estados = $this->convertirResultadoEnArreglo($stmtEstados->get_result());
        $estadoIdPorClave = [];

        foreach ($estados as $estado) {
            $clave = str_pad((string)($estado['clave_inegi'] ?? ''), 2, '0', STR_PAD_LEFT);

            if (preg_match('/^\\d{2}$/', $clave)) {
                $estadoIdPorClave[$clave] = (int)$estado['id'];
            }
        }

        if (count($estadoIdPorClave) !== 32) {
            throw new RuntimeException('El catálogo de Estados no está completo para realizar la importación.');
        }

        $registrosPorClave = [];

        foreach ($datos as $registro) {
            $clave = trim((string)($registro['clave_geografica'] ?? ''));
            $ingreso = $registro['ingreso_laboral_real_per_capita'] ?? null;
            $pobreza = $registro['pobreza_laboral'] ?? null;

            if (!preg_match('/^\\d{2}$/', $clave) || isset($registrosPorClave[$clave])) {
                throw new InvalidArgumentException('El archivo contiene claves geográficas inválidas o duplicadas.');
            }

            if ($clave !== '00' && !isset($estadoIdPorClave[$clave])) {
                throw new InvalidArgumentException('El archivo contiene un Estado que no existe en el catálogo territorial.');
            }

            if (!is_numeric($ingreso) || (float)$ingreso <= 0) {
                throw new InvalidArgumentException('El archivo contiene un ingreso laboral no válido.');
            }

            if (!is_numeric($pobreza) || (float)$pobreza < 0 || (float)$pobreza > 100) {
                throw new InvalidArgumentException('El archivo contiene un porcentaje de pobreza laboral no válido.');
            }

            $registrosPorClave[$clave] = [
                'clave_geografica' => $clave,
                'ingreso_laboral_real_per_capita' => round((float)$ingreso, 2),
                'pobreza_laboral' => round((float)$pobreza, 2)
            ];
        }

        $clavesEsperadas = array_map(
            static fn ($numero) => str_pad((string)$numero, 2, '0', STR_PAD_LEFT),
            range(0, 32)
        );

        foreach ($clavesEsperadas as $clave) {
            if (!isset($registrosPorClave[$clave])) {
                throw new InvalidArgumentException('La importación no contiene todas las geografías requeridas.');
            }
        }

        $periodoYaExistia = $this->contarRegistrosPoderAdquisitivoPeriodo($anio, $trimestre) > 0;
        $fuente = 'INEGI - Pobreza Laboral (PL)';
        $tipoActualizacion = 'IMPORTACION';
        $periodoTexto = $anio . ' ' . $trimestre . 'T';
        $this->connection->begin_transaction();

        try {
            $sqlImportar = "INSERT INTO poder_adquisitivo_estado (
                        estado_id,
                        clave_geografica,
                        anio,
                        trimestre,
                        ingreso_laboral_real_per_capita,
                        pobreza_laboral,
                        fuente,
                        archivo_origen,
                        fecha_consulta,
                        tipo_actualizacion,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        estado_id = VALUES(estado_id),
                        ingreso_laboral_real_per_capita = VALUES(ingreso_laboral_real_per_capita),
                        pobreza_laboral = VALUES(pobreza_laboral),
                        fuente = VALUES(fuente),
                        archivo_origen = VALUES(archivo_origen),
                        fecha_consulta = NOW(),
                        tipo_actualizacion = VALUES(tipo_actualizacion),
                        updated_at = NOW()";
            $stmtImportar = $this->connection->prepare($sqlImportar);

            foreach ($clavesEsperadas as $clave) {
                $registro = $registrosPorClave[$clave];
                $estadoId = $clave === '00' ? null : $estadoIdPorClave[$clave];
                $ingreso = $registro['ingreso_laboral_real_per_capita'];
                $pobreza = $registro['pobreza_laboral'];

                $stmtImportar->bind_param(
                    "isiiddsss",
                    $estadoId,
                    $clave,
                    $anio,
                    $trimestre,
                    $ingreso,
                    $pobreza,
                    $fuente,
                    $archivoOrigen,
                    $tipoActualizacion
                );

                if (!$stmtImportar->execute()) {
                    throw new RuntimeException('No fue posible guardar los indicadores de poder adquisitivo.');
                }
            }

            $sqlActualizarEstados = "UPDATE estados
                    SET fecha_actualizacion = NOW(),
                        updated_at = NOW()
                    WHERE estado = 1
                        AND clave_inegi REGEXP '^[0-9]{2}$'";
            $stmtActualizarEstados = $this->connection->prepare($sqlActualizarEstados);

            if (!$stmtActualizarEstados->execute()) {
                throw new RuntimeException('No fue posible actualizar la fecha territorial de los Estados.');
            }

            foreach ($estadoIdPorClave as $estadoId) {
                $this->guardarFuentePoderAdquisitivoImportada(
                    (int)$estadoId,
                    $periodoTexto,
                    $usuarioId
                );
            }

            $this->connection->commit();

            return [
                'ok' => true,
                'anio' => $anio,
                'trimestre' => $trimestre,
                'estados_importados' => 32,
                'referencia_nacional_importada' => true,
                'registros_procesados' => 33,
                'periodo_ya_existia' => $periodoYaExistia,
                'archivo_origen' => $archivoOrigen
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            throw $error;
        }
    }

    private function guardarFuentePoderAdquisitivoImportada(
        int $estadoId,
        string $periodo,
        int $usuarioId
    ): bool {
        $seccion = 'PODER_ADQUISITIVO';
        $fuente = 'INEGI - Pobreza Laboral (PL)';
        $tipoActualizacion = 'IMPORTACION';
        $urlFuente = 'https://www.inegi.org.mx/desarrollosocial/pl/';
        $sqlBuscar = "SELECT id
                FROM fuentes_datos_territoriales
                WHERE estado_id = ?
                    AND seccion = ?
                    AND fuente = ?
                    AND tipo_actualizacion = ?
                ORDER BY id DESC
                LIMIT 1";
        $stmtBuscar = $this->connection->prepare($sqlBuscar);
        $stmtBuscar->bind_param(
            "isss",
            $estadoId,
            $seccion,
            $fuente,
            $tipoActualizacion
        );

        if (!$stmtBuscar->execute()) {
            throw new RuntimeException('No fue posible consultar la fuente de poder adquisitivo.');
        }

        $existente = $stmtBuscar->get_result()->fetch_assoc();

        if ($existente) {
            $sqlActualizar = "UPDATE fuentes_datos_territoriales
                    SET url_fuente = ?,
                        periodo = ?,
                        fecha_consulta = NOW(),
                        usuario_verifico_id = ?,
                        updated_at = NOW()
                    WHERE id = ?";
            $stmtActualizar = $this->connection->prepare($sqlActualizar);
            $idFuente = (int)$existente['id'];
            $stmtActualizar->bind_param(
                "ssii",
                $urlFuente,
                $periodo,
                $usuarioId,
                $idFuente
            );

            if (!$stmtActualizar->execute()) {
                throw new RuntimeException('No fue posible actualizar la fuente de poder adquisitivo.');
            }

            return true;
        }

        $sqlInsertar = "INSERT INTO fuentes_datos_territoriales (
                    estado_id,
                    seccion,
                    fuente,
                    url_fuente,
                    periodo,
                    tipo_actualizacion,
                    fecha_consulta,
                    usuario_verifico_id,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())";
        $stmtInsertar = $this->connection->prepare($sqlInsertar);
        $stmtInsertar->bind_param(
            "isssssi",
            $estadoId,
            $seccion,
            $fuente,
            $urlFuente,
            $periodo,
            $tipoActualizacion,
            $usuarioId
        );

        if (!$stmtInsertar->execute()) {
            throw new RuntimeException('No fue posible registrar la fuente de poder adquisitivo.');
        }

        return true;
    }

    public function obtenerRezagoEducativoOficialEstado(int $estadoId): array
    {
        $respuestaVacia = [
            'disponible' => false,
            'anio' => null,
            'cantidad_personas' => null,
            'porcentaje' => null,
            'diferencia_nacional' => null,
            'referencia_nacional' => null,
            'historico' => [],
            'fuente' => '',
            'archivo_origen' => '',
            'fecha_consulta' => null,
            'tipo_actualizacion' => ''
        ];

        if ($estadoId <= 0) {
            return $respuestaVacia;
        }

        $sqlEstado = "SELECT
                    anio,
                    cantidad_personas,
                    porcentaje,
                    fuente,
                    archivo_origen,
                    fecha_consulta,
                    tipo_actualizacion
                FROM rezago_educativo_oficial
                WHERE estado_id = ?
                ORDER BY anio DESC
                LIMIT 1";
        $stmtEstado = $this->connection->prepare($sqlEstado);
        $stmtEstado->bind_param("i", $estadoId);
        $stmtEstado->execute();
        $estado = $stmtEstado->get_result()->fetch_assoc();

        if (!$estado) {
            return $respuestaVacia;
        }

        $anio = (int)($estado['anio'] ?? 0);
        $cantidadEstado = isset($estado['cantidad_personas'])
            ? (int)$estado['cantidad_personas']
            : null;
        $porcentajeEstado = isset($estado['porcentaje'])
            ? (float)$estado['porcentaje']
            : null;

        if ($anio <= 0 || $cantidadEstado === null || $porcentajeEstado === null) {
            return $respuestaVacia;
        }

        $sqlNacional = "SELECT cantidad_personas, porcentaje
                FROM rezago_educativo_oficial
                WHERE clave_geografica = '00'
                    AND anio = ?
                LIMIT 1";
        $stmtNacional = $this->connection->prepare($sqlNacional);
        $stmtNacional->bind_param("i", $anio);
        $stmtNacional->execute();
        $nacional = $stmtNacional->get_result()->fetch_assoc();
        $referenciaNacional = null;
        $diferenciaNacional = null;

        if ($nacional) {
            $cantidadNacional = isset($nacional['cantidad_personas'])
                ? (int)$nacional['cantidad_personas']
                : null;
            $porcentajeNacional = isset($nacional['porcentaje'])
                ? (float)$nacional['porcentaje']
                : null;

            if ($cantidadNacional !== null && $porcentajeNacional !== null) {
                $referenciaNacional = [
                    'cantidad_personas' => $cantidadNacional,
                    'porcentaje' => round($porcentajeNacional, 2)
                ];
                $diferenciaNacional = round($porcentajeEstado - $porcentajeNacional, 2);
            }
        }

        $sqlHistorico = "SELECT anio, cantidad_personas, porcentaje
                FROM rezago_educativo_oficial
                WHERE estado_id = ?
                ORDER BY anio ASC";
        $stmtHistorico = $this->connection->prepare($sqlHistorico);
        $stmtHistorico->bind_param("i", $estadoId);
        $stmtHistorico->execute();
        $historicoFilas = $this->convertirResultadoEnArreglo($stmtHistorico->get_result());
        $historico = [];

        foreach ($historicoFilas as $fila) {
            $historico[] = [
                'anio' => (int)($fila['anio'] ?? 0),
                'cantidad_personas' => (int)($fila['cantidad_personas'] ?? 0),
                'porcentaje' => round((float)($fila['porcentaje'] ?? 0), 2)
            ];
        }

        return [
            'disponible' => true,
            'anio' => $anio,
            'cantidad_personas' => $cantidadEstado,
            'porcentaje' => round($porcentajeEstado, 2),
            'diferencia_nacional' => $diferenciaNacional,
            'referencia_nacional' => $referenciaNacional,
            'historico' => $historico,
            'fuente' => (string)($estado['fuente'] ?? ''),
            'archivo_origen' => (string)($estado['archivo_origen'] ?? ''),
            'fecha_consulta' => $estado['fecha_consulta'] ?? null,
            'tipo_actualizacion' => (string)($estado['tipo_actualizacion'] ?? '')
        ];
    }

    public function contarRegistrosRezagoEducativoOficialPeriodo(int $anio): int
    {
        if ($anio < 2000 || $anio > 2100) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS total
                FROM rezago_educativo_oficial
                WHERE anio = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $anio);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        return (int)($fila['total'] ?? 0);
    }

    public function importarRezagoEducativoOficial(
        array $lectura,
        string $archivoOrigen,
        int $usuarioId
    ): array {
        $periodos = array_values(array_unique(array_map('intval', $lectura['periodos'] ?? [])));
        sort($periodos, SORT_NUMERIC);
        $datos = $lectura['datos'] ?? [];
        $archivoOrigen = trim(basename($archivoOrigen));

        if (empty($periodos)) {
            throw new InvalidArgumentException('El archivo no contiene periodos válidos de rezago educativo.');
        }

        foreach ($periodos as $anio) {
            if ($anio < 2000 || $anio > 2100) {
                throw new InvalidArgumentException('El archivo contiene un año no válido.');
            }
        }

        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('El usuario que realiza la importación no es válido.');
        }

        if ($archivoOrigen === '' || strlen($archivoOrigen) > 255) {
            throw new InvalidArgumentException('El nombre del archivo de origen no es válido.');
        }

        $totalEsperado = 33 * count($periodos);

        if (count($datos) !== $totalEsperado) {
            throw new InvalidArgumentException('La importación no contiene todos los periodos y geografías esperados.');
        }

        $sqlEstados = "SELECT id, clave_inegi
                FROM estados
                WHERE estado = 1
                    AND clave_inegi REGEXP '^[0-9]{2}$'";
        $stmtEstados = $this->connection->prepare($sqlEstados);
        $stmtEstados->execute();
        $estados = $this->convertirResultadoEnArreglo($stmtEstados->get_result());
        $estadoIdPorClave = [];

        foreach ($estados as $estado) {
            $clave = str_pad((string)($estado['clave_inegi'] ?? ''), 2, '0', STR_PAD_LEFT);

            if (preg_match('/^\\d{2}$/', $clave)) {
                $estadoIdPorClave[$clave] = (int)$estado['id'];
            }
        }

        if (count($estadoIdPorClave) !== 32) {
            throw new RuntimeException('El catálogo de Estados no está completo para realizar la importación.');
        }

        $registros = [];

        foreach ($datos as $registro) {
            $clave = trim((string)($registro['clave_geografica'] ?? ''));
            $anio = (int)($registro['anio'] ?? 0);
            $cantidad = $registro['cantidad_personas'] ?? null;
            $porcentaje = $registro['porcentaje'] ?? null;
            $llave = $clave . '|' . $anio;

            if (
                !preg_match('/^\\d{2}$/', $clave) ||
                !in_array($anio, $periodos, true) ||
                isset($registros[$llave])
            ) {
                throw new InvalidArgumentException('El archivo contiene claves o periodos inválidos o duplicados.');
            }

            if ($clave !== '00' && !isset($estadoIdPorClave[$clave])) {
                throw new InvalidArgumentException('El archivo contiene un Estado que no existe en el catálogo territorial.');
            }

            if (!is_numeric($cantidad) || (int)$cantidad < 0 || (int)$cantidad > 200000000) {
                throw new InvalidArgumentException('El archivo contiene una cantidad de personas no válida.');
            }

            if (!is_numeric($porcentaje) || (float)$porcentaje < 0 || (float)$porcentaje > 100) {
                throw new InvalidArgumentException('El archivo contiene un porcentaje de rezago educativo no válido.');
            }

            $registros[$llave] = [
                'clave_geografica' => $clave,
                'anio' => $anio,
                'cantidad_personas' => (int)$cantidad,
                'porcentaje' => round((float)$porcentaje, 2)
            ];
        }

        $clavesEsperadas = array_map(
            static fn ($numero) => str_pad((string)$numero, 2, '0', STR_PAD_LEFT),
            range(0, 32)
        );

        foreach ($clavesEsperadas as $clave) {
            foreach ($periodos as $anio) {
                if (!isset($registros[$clave . '|' . $anio])) {
                    throw new InvalidArgumentException('La importación no contiene todas las geografías y periodos requeridos.');
                }
            }
        }

        $periodosExistentes = [];
        $periodosNuevos = [];

        foreach ($periodos as $anio) {
            if ($this->contarRegistrosRezagoEducativoOficialPeriodo($anio) > 0) {
                $periodosExistentes[] = $anio;
            } else {
                $periodosNuevos[] = $anio;
            }
        }

        $fuente = 'INEGI - Pobreza Multidimensional';
        $tipoActualizacion = 'IMPORTACION';
        $ultimoPeriodo = max($periodos);
        $this->connection->begin_transaction();

        try {
            $sqlImportar = "INSERT INTO rezago_educativo_oficial (
                        estado_id,
                        clave_geografica,
                        anio,
                        cantidad_personas,
                        porcentaje,
                        fuente,
                        archivo_origen,
                        fecha_consulta,
                        tipo_actualizacion,
                        usuario_importo_id,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        estado_id = VALUES(estado_id),
                        cantidad_personas = VALUES(cantidad_personas),
                        porcentaje = VALUES(porcentaje),
                        fuente = VALUES(fuente),
                        archivo_origen = VALUES(archivo_origen),
                        fecha_consulta = NOW(),
                        tipo_actualizacion = VALUES(tipo_actualizacion),
                        usuario_importo_id = VALUES(usuario_importo_id),
                        updated_at = NOW()";
            $stmtImportar = $this->connection->prepare($sqlImportar);

            foreach ($clavesEsperadas as $clave) {
                foreach ($periodos as $anio) {
                    $registro = $registros[$clave . '|' . $anio];
                    $estadoId = $clave === '00' ? null : $estadoIdPorClave[$clave];
                    $cantidad = $registro['cantidad_personas'];
                    $porcentaje = $registro['porcentaje'];

                    $stmtImportar->bind_param(
                        "isiidsssi",
                        $estadoId,
                        $clave,
                        $anio,
                        $cantidad,
                        $porcentaje,
                        $fuente,
                        $archivoOrigen,
                        $tipoActualizacion,
                        $usuarioId
                    );

                    if (!$stmtImportar->execute()) {
                        throw new RuntimeException('No fue posible guardar los indicadores oficiales de rezago educativo.');
                    }
                }
            }

            $sqlActualizarEstados = "UPDATE estados
                    SET fecha_actualizacion = NOW(),
                        updated_at = NOW()
                    WHERE estado = 1
                        AND clave_inegi REGEXP '^[0-9]{2}$'";
            $stmtActualizarEstados = $this->connection->prepare($sqlActualizarEstados);

            if (!$stmtActualizarEstados->execute()) {
                throw new RuntimeException('No fue posible actualizar la fecha territorial de los Estados.');
            }

            foreach ($estadoIdPorClave as $estadoId) {
                $this->guardarFuenteRezagoEducativoImportada(
                    (int)$estadoId,
                    (string)$ultimoPeriodo,
                    $usuarioId
                );
            }

            $this->connection->commit();

            return [
                'ok' => true,
                'periodos' => $periodos,
                'ultimo_periodo' => $ultimoPeriodo,
                'periodos_nuevos' => $periodosNuevos,
                'periodos_actualizados' => $periodosExistentes,
                'estados_importados' => 32,
                'referencia_nacional_importada' => true,
                'registros_procesados' => $totalEsperado,
                'archivo_origen' => $archivoOrigen
            ];
        } catch (Throwable $error) {
            $this->connection->rollback();
            throw $error;
        }
    }

    private function guardarFuenteRezagoEducativoImportada(
        int $estadoId,
        string $periodo,
        int $usuarioId
    ): bool {
        $seccion = 'EDUCACION';
        $fuente = 'INEGI - Pobreza Multidimensional';
        $tipoActualizacion = 'IMPORTACION';
        $urlFuente = 'https://www.inegi.org.mx/desarrollosocial/pm/';
        $sqlBuscar = "SELECT id
                FROM fuentes_datos_territoriales
                WHERE estado_id = ?
                    AND seccion = ?
                    AND fuente = ?
                    AND tipo_actualizacion = ?
                ORDER BY id DESC
                LIMIT 1";
        $stmtBuscar = $this->connection->prepare($sqlBuscar);
        $stmtBuscar->bind_param(
            "isss",
            $estadoId,
            $seccion,
            $fuente,
            $tipoActualizacion
        );

        if (!$stmtBuscar->execute()) {
            throw new RuntimeException('No fue posible consultar la fuente de rezago educativo.');
        }

        $existente = $stmtBuscar->get_result()->fetch_assoc();

        if ($existente) {
            $sqlActualizar = "UPDATE fuentes_datos_territoriales
                    SET url_fuente = ?,
                        periodo = ?,
                        fecha_consulta = NOW(),
                        usuario_verifico_id = ?,
                        updated_at = NOW()
                    WHERE id = ?";
            $stmtActualizar = $this->connection->prepare($sqlActualizar);
            $idFuente = (int)$existente['id'];
            $stmtActualizar->bind_param(
                "ssii",
                $urlFuente,
                $periodo,
                $usuarioId,
                $idFuente
            );

            if (!$stmtActualizar->execute()) {
                throw new RuntimeException('No fue posible actualizar la fuente de rezago educativo.');
            }

            return true;
        }

        $sqlInsertar = "INSERT INTO fuentes_datos_territoriales (
                    estado_id,
                    seccion,
                    fuente,
                    url_fuente,
                    periodo,
                    tipo_actualizacion,
                    fecha_consulta,
                    usuario_verifico_id,
                    created_at,
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), NOW())";
        $stmtInsertar = $this->connection->prepare($sqlInsertar);
        $stmtInsertar->bind_param(
            "isssssi",
            $estadoId,
            $seccion,
            $fuente,
            $urlFuente,
            $periodo,
            $tipoActualizacion,
            $usuarioId
        );

        if (!$stmtInsertar->execute()) {
            throw new RuntimeException('No fue posible registrar la fuente de rezago educativo.');
        }

        return true;
    }

    public function obtenerMunicipios($estadoId, $filtros = [], $pagina = 1, $limite = 10)
    {
        $buscar = trim((string)($filtros['buscar'] ?? ''));
        $pagina = max(1, (int)$pagina);
        $limite = in_array((int)$limite, [10, 15, 20], true)
            ? (int)$limite
            : 10;
        $offset = ($pagina - 1) * $limite;
        $condiciones = ['estado_id = ?'];
        $parametros = [(int)$estadoId];
        $tipos = 'i';

        if ($buscar !== '') {
            $condiciones[] = "(
                nombre LIKE ?
                OR presidente_municipal LIKE ?
                OR clave_inegi LIKE ?
            )";
            $busqueda = '%' . $buscar . '%';
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $tipos .= 'sss';
        }

        $sql = "SELECT
                    id,
                    estado_id,
                    clave_inegi,
                    numero_excel,
                    nombre,
                    poblacion,
                    presidente_municipal,
                    partido_politico,
                    redes_sociales,
                    fotografia,
                    fecha_actualizacion,
                    estado
                FROM municipios
                WHERE " . implode(' AND ', $condiciones) . "
                ORDER BY estado DESC, COALESCE(numero_excel, 9999), nombre
                LIMIT ? OFFSET ?";

        $parametros[] = $limite;
        $parametros[] = $offset;
        $tipos .= 'ii';

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        return $this->convertirResultadoEnArreglo($stmt->get_result());
    }

    public function contarMunicipiosFiltrados($estadoId, $filtros = [])
    {
        $buscar = trim((string)($filtros['buscar'] ?? ''));
        $condiciones = ['estado_id = ?'];
        $parametros = [(int)$estadoId];
        $tipos = 'i';

        if ($buscar !== '') {
            $condiciones[] = "(
                nombre LIKE ?
                OR presidente_municipal LIKE ?
                OR clave_inegi LIKE ?
            )";
            $busqueda = '%' . $buscar . '%';
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $parametros[] = $busqueda;
            $tipos .= 'sss';
        }

        $sql = "SELECT COUNT(*) AS total
                FROM municipios
                WHERE " . implode(' AND ', $condiciones);

        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return (int)$fila['total'];
    }

    public function contarMunicipiosActivos($estadoId)
    {
        $sql = "SELECT COUNT(*) AS total
                FROM municipios
                WHERE estado_id = ?
                    AND estado = 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return (int)$fila['total'];
    }

    public function obtenerPriorizacionMunicipal($estadoId, $limiteRecomendados = 3)
    {
        $estadoId = (int)$estadoId;
        $limiteRecomendados = max(1, min(5, (int)$limiteRecomendados));

        $resultado = [
            'disponible' => false,
            'total_municipios_con_poblacion' => 0,
            'total_municipios_sin_poblacion' => 0,
            'poblacion_municipal_registrada' => 0,
            'total_municipios_clasificables' => 0,
            'conteos' => [
                'ALTA' => 0,
                'MEDIA' => 0,
                'BAJA' => 0
            ],
            'recomendados' => [],
            'por_municipio' => []
        ];

        $sql = "SELECT
                    id,
                    nombre,
                    clave_inegi,
                    poblacion,
                    presidente_municipal,
                    partido_politico,
                    redes_sociales
                FROM municipios
                WHERE estado_id = ?
                    AND estado = 1
                ORDER BY
                    CASE
                        WHEN poblacion IS NULL OR poblacion <= 0 THEN 1
                        ELSE 0
                    END,
                    poblacion DESC,
                    nombre ASC";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $estadoId);
        $stmt->execute();

        $municipios = $this->convertirResultadoEnArreglo($stmt->get_result());

        if (empty($municipios)) {
            return $resultado;
        }

        $accionesPorPrioridad = [
            'ALTA' => 'ATACAR',
            'MEDIA' => 'OFRECER',
            'BAJA' => 'OBSERVAR'
        ];

        $municipiosConPoblacion = array_values(
            array_filter(
                $municipios,
                function ($municipio) {
                    return isset($municipio['poblacion']) &&
                        is_numeric($municipio['poblacion']) &&
                        (float)$municipio['poblacion'] > 0;
                }
            )
        );

        $resultado['total_municipios_con_poblacion'] = count($municipiosConPoblacion);
        $resultado['total_municipios_sin_poblacion'] =
            count($municipios) - count($municipiosConPoblacion);

        if (empty($municipiosConPoblacion)) {
            return $resultado;
        }

        $poblacionTotal = array_sum(
            array_map(
                function ($municipio) {
                    return (float)$municipio['poblacion'];
                },
                $municipiosConPoblacion
            )
        );

        if ($poblacionTotal > 0) {
            $resultado['poblacion_municipal_registrada'] = (int)round($poblacionTotal);
        }

        $puntosPoblacionPorMunicipio = [];
        $totalConPoblacion = count($municipiosConPoblacion);

        foreach ($municipiosConPoblacion as $indice => $municipio) {
            $posicionPorcentual = $totalConPoblacion > 0
                ? ($indice / $totalConPoblacion) * 100
                : 100;

            if ($posicionPorcentual < 20) {
                $puntosPoblacion = 50;
            } elseif ($posicionPorcentual < 40) {
                $puntosPoblacion = 40;
            } elseif ($posicionPorcentual < 60) {
                $puntosPoblacion = 30;
            } elseif ($posicionPorcentual < 80) {
                $puntosPoblacion = 20;
            } else {
                $puntosPoblacion = 10;
            }

            $puntosPoblacionPorMunicipio[(int)$municipio['id']] = $puntosPoblacion;
        }

        $componenteEducativo = [
            'disponible' => false,
            'puntaje' => 0,
            'motivo' => ''
        ];
        $rezagoEducativo = $this->obtenerRezagoEducativoOficialEstado($estadoId);

        if (
            ($rezagoEducativo['disponible'] ?? false) === true &&
            ($rezagoEducativo['diferencia_nacional'] ?? null) !== null
        ) {
            $diferenciaEducativa = (float)$rezagoEducativo['diferencia_nacional'];

            if ($diferenciaEducativa >= 5) {
                $puntosEducacion = 15;
            } elseif ($diferenciaEducativa >= 2) {
                $puntosEducacion = 12;
            } elseif ($diferenciaEducativa >= -2) {
                $puntosEducacion = 8;
            } else {
                $puntosEducacion = 4;
            }

            $componenteEducativo = [
                'disponible' => true,
                'puntaje' => $puntosEducacion,
                'motivo' => 'Contexto educativo estatal relevante'
            ];
        }

        $componentePoder = [
            'disponible' => false,
            'puntaje' => 0
        ];
        $poderAdquisitivo = $this->obtenerPoderAdquisitivoEstado($estadoId);
        $referenciaPoder = $poderAdquisitivo['referencia_nacional'] ?? null;

        if (
            ($poderAdquisitivo['disponible'] ?? false) === true &&
            is_array($referenciaPoder) &&
            isset(
                $poderAdquisitivo['ingreso_laboral_real_per_capita'],
                $poderAdquisitivo['pobreza_laboral'],
                $referenciaPoder['ingreso_laboral_real_per_capita'],
                $referenciaPoder['pobreza_laboral']
            )
        ) {
            $ingresoEstado = (float)$poderAdquisitivo['ingreso_laboral_real_per_capita'];
            $ingresoNacional = (float)$referenciaPoder['ingreso_laboral_real_per_capita'];
            $pobrezaEstado = (float)$poderAdquisitivo['pobreza_laboral'];
            $pobrezaNacional = (float)$referenciaPoder['pobreza_laboral'];
            $puntosIngreso = 0;
            $puntosPobreza = 0;

            if ($ingresoNacional > 0) {
                if ($ingresoEstado >= $ingresoNacional) {
                    $puntosIngreso = 4;
                } elseif ($ingresoEstado >= ($ingresoNacional * 0.90)) {
                    $puntosIngreso = 2;
                }
            }

            if ($pobrezaEstado <= $pobrezaNacional) {
                $puntosPobreza = 4;
            } elseif (($pobrezaEstado - $pobrezaNacional) <= 5) {
                $puntosPobreza = 2;
            }

            $componentePoder = [
                'disponible' => $ingresoNacional > 0,
                'puntaje' => $puntosIngreso + $puntosPobreza
            ];
        }

        $componenteActividad = [
            'disponible' => false,
            'puntaje' => 0
        ];
        $sqlActividad = "SELECT
                    estado_id,
                    COALESCE(SUM(establecimientos), 0) AS total_establecimientos
                FROM actividad_economica_estado
                GROUP BY estado_id
                HAVING total_establecimientos > 0
                ORDER BY total_establecimientos DESC";

        $stmtActividad = $this->connection->prepare($sqlActividad);
        $stmtActividad->execute();
        $estadosActividad = $this->convertirResultadoEnArreglo($stmtActividad->get_result());
        $totalEstadosActividad = count($estadosActividad);

        foreach ($estadosActividad as $indice => $estadoActividad) {
            if ((int)$estadoActividad['estado_id'] !== $estadoId) {
                continue;
            }

            $posicionActividad = $totalEstadosActividad > 0
                ? ($indice / $totalEstadosActividad) * 100
                : 100;

            if ($posicionActividad < 25) {
                $puntosActividad = 7;
            } elseif ($posicionActividad < 50) {
                $puntosActividad = 5;
            } elseif ($posicionActividad < 75) {
                $puntosActividad = 3;
            } else {
                $puntosActividad = 1;
            }

            $componenteActividad = [
                'disponible' => true,
                'puntaje' => $puntosActividad
            ];
            break;
        }

        $puntajeEconomiaEstado =
            (int)$componentePoder['puntaje'] +
            (int)$componenteActividad['puntaje'];
        $puntajeDisponibleEconomia =
            ($componentePoder['disponible'] ? 8 : 0) +
            ($componenteActividad['disponible'] ? 7 : 0);
        $economiaDisponible = $puntajeDisponibleEconomia > 0;
        $municipiosPriorizados = [];

        foreach ($municipiosConPoblacion as $indice => $municipio) {
            $poblacion = (float)$municipio['poblacion'];
            $municipioId = (int)$municipio['id'];
            $puntajeObtenido = 0;
            $puntajeDisponible = 0;
            $componentes = [
                'poblacion' => 0,
                'institucional' => 0,
                'educacion' => 0,
                'economia' => 0
            ];
            $motivos = [];

            if (isset($puntosPoblacionPorMunicipio[$municipioId])) {
                $componentes['poblacion'] = $puntosPoblacionPorMunicipio[$municipioId];
                $puntajeObtenido += $componentes['poblacion'];
                $puntajeDisponible += 50;
                $motivos[] = $componentes['poblacion'] >= 40
                    ? 'Alto alcance poblacional'
                    : 'Alcance poblacional dentro del territorio';
            }

            if (trim((string)($municipio['presidente_municipal'] ?? '')) !== '') {
                $componentes['institucional'] += 8;
            }

            if (trim((string)($municipio['redes_sociales'] ?? '')) !== '') {
                $componentes['institucional'] += 8;
            }

            if (trim((string)($municipio['partido_politico'] ?? '')) !== '') {
                $componentes['institucional'] += 4;
            }

            $puntajeObtenido += $componentes['institucional'];
            $puntajeDisponible += 20;

            if ($componentes['institucional'] > 0) {
                $motivos[] = 'Información institucional disponible';
            }

            if ($componenteEducativo['disponible']) {
                $componentes['educacion'] = (int)$componenteEducativo['puntaje'];
                $puntajeObtenido += $componentes['educacion'];
                $puntajeDisponible += 15;
                $motivos[] = 'Contexto educativo estatal relevante';
            }

            if ($economiaDisponible) {
                $componentes['economia'] = $puntajeEconomiaEstado;
                $puntajeObtenido += $componentes['economia'];
                $puntajeDisponible += $puntajeDisponibleEconomia;
                $motivos[] = 'Contexto económico estatal favorable';
            }

            $puntaje = $puntajeDisponible > 0
                ? (int)round(($puntajeObtenido / $puntajeDisponible) * 100)
                : 0;
            $coberturaDatos = (int)round(($puntajeDisponible / 100) * 100);
            $porcentajeIndividual = $poblacionTotal > 0
                ? ($poblacion / $poblacionTotal) * 100
                : 0;
            $datosPriorizacion = [
                'puntaje' => $puntaje,
                'puntaje_obtenido' => $puntajeObtenido,
                'puntaje_disponible' => $puntajeDisponible,
                'cobertura_datos' => $coberturaDatos,
                'ranking' => null,
                'total_ranking' => $totalConPoblacion,
                'percentil_territorial' => null,
                'prioridad' => 'BAJA',
                'accion' => $accionesPorPrioridad['BAJA'],
                'componentes' => $componentes,
                'motivos' => $motivos
            ];

            $resultado['por_municipio'][$municipioId] = $datosPriorizacion;
            $municipiosPriorizados[] = array_merge(
                [
                    'id' => $municipioId,
                    'nombre' => (string)$municipio['nombre'],
                    'clave_inegi' => (string)($municipio['clave_inegi'] ?? ''),
                    'poblacion' => (int)round($poblacion),
                    'porcentaje_poblacion' => round($porcentajeIndividual, 2),
                    'motivo' => implode('. ', $motivos)
                ],
                $datosPriorizacion
            );
        }

        foreach ($municipios as $municipio) {
            $municipioId = (int)$municipio['id'];

            if (isset($resultado['por_municipio'][$municipioId])) {
                continue;
            }

            $puntajeObtenido = 0;
            $puntajeDisponible = 20;
            $componentes = [
                'poblacion' => 0,
                'institucional' => 0,
                'educacion' => 0,
                'economia' => 0
            ];
            $motivos = [];

            if (trim((string)($municipio['presidente_municipal'] ?? '')) !== '') {
                $componentes['institucional'] += 8;
            }

            if (trim((string)($municipio['redes_sociales'] ?? '')) !== '') {
                $componentes['institucional'] += 8;
            }

            if (trim((string)($municipio['partido_politico'] ?? '')) !== '') {
                $componentes['institucional'] += 4;
            }

            $puntajeObtenido += $componentes['institucional'];

            if ($componentes['institucional'] > 0) {
                $motivos[] = 'Información institucional disponible';
            }

            if ($componenteEducativo['disponible']) {
                $componentes['educacion'] = (int)$componenteEducativo['puntaje'];
                $puntajeObtenido += $componentes['educacion'];
                $puntajeDisponible += 15;
                $motivos[] = 'Contexto educativo estatal relevante';
            }

            if ($economiaDisponible) {
                $componentes['economia'] = $puntajeEconomiaEstado;
                $puntajeObtenido += $componentes['economia'];
                $puntajeDisponible += $puntajeDisponibleEconomia;
                $motivos[] = 'Contexto económico estatal favorable';
            }

            $puntaje = $puntajeDisponible > 0
                ? (int)round(($puntajeObtenido / $puntajeDisponible) * 100)
                : 0;
            $coberturaDatos = (int)round(($puntajeDisponible / 100) * 100);
            $datosPriorizacion = [
                'puntaje' => $puntaje,
                'puntaje_obtenido' => $puntajeObtenido,
                'puntaje_disponible' => $puntajeDisponible,
                'cobertura_datos' => $coberturaDatos,
                'ranking' => null,
                'total_ranking' => $totalConPoblacion,
                'percentil_territorial' => null,
                'prioridad' => 'BAJA',
                'accion' => $accionesPorPrioridad['BAJA'],
                'componentes' => $componentes,
                'motivos' => $motivos
            ];

            $resultado['por_municipio'][$municipioId] = $datosPriorizacion;
            $municipiosPriorizados[] = array_merge(
                [
                    'id' => $municipioId,
                    'nombre' => (string)$municipio['nombre'],
                    'clave_inegi' => (string)($municipio['clave_inegi'] ?? ''),
                    'poblacion' => 0,
                    'porcentaje_poblacion' => 0,
                    'motivo' => implode('. ', $motivos)
                ],
                $datosPriorizacion
            );
        }

        $ordenarMunicipiosPorOportunidad = function ($municipioA, $municipioB) {
            $comparacionPuntaje = $municipioB['puntaje'] <=> $municipioA['puntaje'];

            if ($comparacionPuntaje !== 0) {
                return $comparacionPuntaje;
            }

            $comparacionPoblacion = $municipioB['poblacion'] <=> $municipioA['poblacion'];

            if ($comparacionPoblacion !== 0) {
                return $comparacionPoblacion;
            }

            return strcasecmp((string)$municipioA['nombre'], (string)$municipioB['nombre']);
        };

        $municipiosClasificables = array_values(
            array_filter(
                $municipiosPriorizados,
                function ($municipio) {
                    return (int)($municipio['poblacion'] ?? 0) > 0;
                }
            )
        );

        usort($municipiosClasificables, $ordenarMunicipiosPorOportunidad);

        $totalClasificables = count($municipiosClasificables);
        $resultado['total_municipios_clasificables'] = $totalClasificables;
        $cupoAtacar = $totalClasificables > 0
            ? min($totalClasificables, max(1, (int)ceil($totalClasificables * 0.20)))
            : 0;
        $cupoOfrecer = $totalClasificables > 0
            ? min(max(0, $totalClasificables - $cupoAtacar), (int)ceil($totalClasificables * 0.30))
            : 0;
        $clasificacionPorMunicipio = [];

        foreach ($municipiosClasificables as $indice => $municipio) {
            $ranking = $indice + 1;
            $esCandidatoAtacar = $indice < $cupoAtacar;
            $cumpleMinimosAtacar =
                (int)($municipio['puntaje'] ?? 0) >= 50 &&
                (int)($municipio['cobertura_datos'] ?? 0) >= 50 &&
                (int)($municipio['poblacion'] ?? 0) > 0;

            if ($esCandidatoAtacar && $cumpleMinimosAtacar) {
                $prioridad = 'ALTA';
            } elseif ($esCandidatoAtacar || $indice < ($cupoAtacar + $cupoOfrecer)) {
                $prioridad = 'MEDIA';
            } else {
                $prioridad = 'BAJA';
            }

            $clasificacionPorMunicipio[(int)$municipio['id']] = [
                'ranking' => $ranking,
                'total_ranking' => $totalClasificables,
                'percentil_territorial' => $totalClasificables > 0
                    ? round(($ranking / $totalClasificables) * 100, 2)
                    : null,
                'prioridad' => $prioridad,
                'accion' => $accionesPorPrioridad[$prioridad]
            ];
        }

        $resultado['conteos'] = [
            'ALTA' => 0,
            'MEDIA' => 0,
            'BAJA' => 0
        ];

        foreach ($resultado['por_municipio'] as $municipioId => $datosPriorizacion) {
            $clasificacion = $clasificacionPorMunicipio[$municipioId] ?? [
                'ranking' => null,
                'total_ranking' => $totalClasificables,
                'percentil_territorial' => null,
                'prioridad' => 'BAJA',
                'accion' => $accionesPorPrioridad['BAJA']
            ];

            $resultado['por_municipio'][$municipioId] = array_merge(
                $datosPriorizacion,
                $clasificacion
            );
            $resultado['conteos'][$clasificacion['prioridad']]++;
        }

        foreach ($municipiosPriorizados as &$municipioPriorizado) {
            $municipioId = (int)$municipioPriorizado['id'];

            if (!isset($resultado['por_municipio'][$municipioId])) {
                continue;
            }

            $municipioPriorizado = array_merge(
                $municipioPriorizado,
                $resultado['por_municipio'][$municipioId]
            );
        }
        unset($municipioPriorizado);

        usort($municipiosPriorizados, function ($municipioA, $municipioB) {
            $ordenPrioridad = [
                'ALTA' => 1,
                'MEDIA' => 2,
                'BAJA' => 3
            ];
            $prioridadA = $ordenPrioridad[$municipioA['prioridad'] ?? 'BAJA'] ?? 3;
            $prioridadB = $ordenPrioridad[$municipioB['prioridad'] ?? 'BAJA'] ?? 3;
            $comparacionPrioridad = $prioridadA <=> $prioridadB;

            if ($comparacionPrioridad !== 0) {
                return $comparacionPrioridad;
            }

            $rankingA = $municipioA['ranking'] ?? PHP_INT_MAX;
            $rankingB = $municipioB['ranking'] ?? PHP_INT_MAX;
            $comparacionRanking = $rankingA <=> $rankingB;

            if ($comparacionRanking !== 0) {
                return $comparacionRanking;
            }

            $comparacionPuntaje = $municipioB['puntaje'] <=> $municipioA['puntaje'];

            if ($comparacionPuntaje !== 0) {
                return $comparacionPuntaje;
            }

            $comparacionPoblacion = $municipioB['poblacion'] <=> $municipioA['poblacion'];

            if ($comparacionPoblacion !== 0) {
                return $comparacionPoblacion;
            }

            return strcasecmp((string)$municipioA['nombre'], (string)$municipioB['nombre']);
        });

        $municipiosRecomendados = array_values(
            array_filter(
                $municipiosPriorizados,
                function ($municipio) {
                    return in_array($municipio['prioridad'] ?? 'BAJA', ['ALTA', 'MEDIA'], true);
                }
            )
        );

        if (count($municipiosRecomendados) < $limiteRecomendados) {
            $municipiosRecomendados = $municipiosPriorizados;
        }

        $resultado['disponible'] = true;
        $resultado['recomendados'] = array_slice(
            $municipiosRecomendados,
            0,
            $limiteRecomendados
        );

        return $resultado;
    }

    public function obtenerFuenteSeccion($estadoId, $seccion)
    {
        $sql = "SELECT
                    fuentes_datos_territoriales.fuente,
                    fuentes_datos_territoriales.url_fuente,
                    fuentes_datos_territoriales.periodo,
                    fuentes_datos_territoriales.tipo_actualizacion,
                    fuentes_datos_territoriales.fecha_consulta,
                    TRIM(CONCAT(usuarios.nombre, ' ', usuarios.apellidos)) AS verificado_por
                FROM fuentes_datos_territoriales
                LEFT JOIN usuarios
                    ON usuarios.id = fuentes_datos_territoriales.usuario_verifico_id
                WHERE fuentes_datos_territoriales.estado_id = ?
                    AND fuentes_datos_territoriales.seccion = ?
                ORDER BY fuentes_datos_territoriales.fecha_consulta DESC,
                    fuentes_datos_territoriales.id DESC
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("is", $estadoId, $seccion);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    public function obtenerFuentesPorEstado($estadoId)
    {
        $secciones = [
            'GENERAL',
            'ACTIVIDAD_ECONOMICA',
            'PODER_ADQUISITIVO',
            'EDUCACION',
            'SECRETARIAS',
            'MUNICIPIOS'
        ];
        $fuentes = [];

        foreach ($secciones as $seccion) {
            $fuentes[$seccion] = $this->obtenerFuenteSeccion($estadoId, $seccion);
        }

        return $fuentes;
    }

    public function actualizarFichaGeneral($estadoId, $datos)
    {
        $sql = "UPDATE estados
                SET capital = ?,
                    titular_gobierno = ?,
                    foto_titular = ?,
                    mapa_estado = ?,
                    cargo_titular = ?,
                    partido_politico = ?,
                    poblacion = ?,
                    total_municipios = ?,
                    total_secretarias = ?,
                    periodo_gobierno = ?,
                    telefono = ?,
                    redes_sociales = ?,
                    actividad_economica = ?,
                    poder_adquisitivo = ?,
                    fecha_actualizacion = ?,
                    updated_at = NOW()
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "ssssssiiissssssi",
            $datos['capital'],
            $datos['titular_gobierno'],
            $datos['foto_titular'],
            $datos['mapa_estado'],
            $datos['cargo_titular'],
            $datos['partido_politico'],
            $datos['poblacion'],
            $datos['total_municipios'],
            $datos['total_secretarias'],
            $datos['periodo_gobierno'],
            $datos['telefono'],
            $datos['redes_sociales'],
            $datos['actividad_economica'],
            $datos['poder_adquisitivo'],
            $datos['fecha_actualizacion'],
            $estadoId
        );

        return $stmt->execute();
    }

    public function actualizarPoblacionOficial(int $estadoId, int $poblacion, string $periodo): bool
    {
        $periodo = trim($periodo);

        if ($estadoId <= 0) {
            throw new InvalidArgumentException('El estado solicitado no es válido.');
        }

        if ($poblacion < 0) {
            throw new InvalidArgumentException('La población no puede ser negativa.');
        }

        if ($periodo === '') {
            throw new InvalidArgumentException('El periodo de la fuente es obligatorio.');
        }

        $this->connection->begin_transaction();

        try {
            $sqlEstado = "UPDATE estados
                    SET poblacion = ?,
                        fecha_actualizacion = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                        AND estado = 1";

            $stmtEstado = $this->connection->prepare($sqlEstado);
            $stmtEstado->bind_param("ii", $poblacion, $estadoId);

            if (!$stmtEstado->execute() || $stmtEstado->affected_rows < 1) {
                throw new Exception('No fue posible actualizar la población del estado.');
            }

            $this->guardarFuenteAutomaticaPoblacion($estadoId, $periodo);

            $this->connection->commit();

            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function actualizarActividadEconomicaOficial(
        int $estadoId,
        int $totalEstablecimientos,
        array $sectores
    ): bool {
        if ($estadoId <= 0) {
            throw new InvalidArgumentException('El estado solicitado no es válido.');
        }

        if ($totalEstablecimientos <= 0) {
            throw new InvalidArgumentException('El total de establecimientos no es válido.');
        }

        if (empty($sectores)) {
            throw new InvalidArgumentException('La información económica no puede estar vacía.');
        }

        if (!$this->existeEstadoActivo($estadoId)) {
            throw new InvalidArgumentException('El estado solicitado no es válido.');
        }

        $sectoresValidados = $this->validarSectoresActividadEconomica($sectores);
        $totalCalculado = array_sum(array_column($sectoresValidados, 'establecimientos'));

        if ($totalCalculado !== $totalEstablecimientos) {
            throw new InvalidArgumentException('El total de establecimientos no coincide con los sectores.');
        }

        $sumaPorcentajes = array_sum(array_column($sectoresValidados, 'porcentaje'));

        if ($sumaPorcentajes < 99.90 || $sumaPorcentajes > 100.10) {
            throw new InvalidArgumentException('La suma de porcentajes no es válida.');
        }

        $this->connection->begin_transaction();

        try {
            $sqlSector = "INSERT INTO actividad_economica_estado (
                        estado_id,
                        clave_sector,
                        nombre_sector,
                        establecimientos,
                        porcentaje,
                        periodo,
                        fuente,
                        fecha_consulta,
                        tipo_actualizacion,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, NULL, ?, NOW(), ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        nombre_sector = VALUES(nombre_sector),
                        establecimientos = VALUES(establecimientos),
                        porcentaje = VALUES(porcentaje),
                        periodo = NULL,
                        fuente = VALUES(fuente),
                        fecha_consulta = NOW(),
                        tipo_actualizacion = VALUES(tipo_actualizacion),
                        updated_at = NOW()";

            $stmtSector = $this->connection->prepare($sqlSector);
            $fuente = 'INEGI - DENUE';
            $tipoActualizacion = 'AUTOMATICA';

            foreach ($sectoresValidados as $sector) {
                $claveSector = $sector['clave_sector'];
                $nombreSector = $sector['nombre_sector'];
                $establecimientos = $sector['establecimientos'];
                $porcentaje = $sector['porcentaje'];

                $stmtSector->bind_param(
                    "issidss",
                    $estadoId,
                    $claveSector,
                    $nombreSector,
                    $establecimientos,
                    $porcentaje,
                    $fuente,
                    $tipoActualizacion
                );

                if (!$stmtSector->execute()) {
                    throw new Exception('No fue posible guardar la actividad económica oficial.');
                }
            }

            $sqlEstado = "UPDATE estados
                    SET fecha_actualizacion = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                        AND estado = 1";

            $stmtEstado = $this->connection->prepare($sqlEstado);
            $stmtEstado->bind_param("i", $estadoId);

            if (!$stmtEstado->execute()) {
                throw new Exception('No fue posible actualizar la fecha territorial del estado.');
            }

            $this->guardarFuenteDenueAutomatica($estadoId);

            $this->connection->commit();

            return true;
        } catch (Throwable $error) {
            $this->connection->rollback();
            error_log($error->getMessage());

            return false;
        }
    }

    public function actualizarEconomia($estadoId, $datos)
    {
        $sql = "UPDATE estados
                SET actividad_economica = ?,
                    poder_adquisitivo = ?,
                    fecha_actualizacion = ?,
                    updated_at = NOW()
                WHERE id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "sssi",
            $datos['actividad_economica'],
            $datos['poder_adquisitivo'],
            $datos['fecha_actualizacion'],
            $estadoId
        );

        return $stmt->execute();
    }

    private function guardarFuenteAutomaticaPoblacion(int $estadoId, string $periodo): bool
    {
        $seccion = 'GENERAL';
        $fuente = 'INEGI - Banco de Indicadores';
        $tipoActualizacion = 'AUTOMATICA';
        $urlFuente = 'https://www.inegi.org.mx/servicios/api_indicadores.html';

        $sqlBuscar = "SELECT id
                FROM fuentes_datos_territoriales
                WHERE estado_id = ?
                    AND seccion = ?
                    AND fuente = ?
                    AND tipo_actualizacion = ?
                LIMIT 1";

        $stmtBuscar = $this->connection->prepare($sqlBuscar);
        $stmtBuscar->bind_param(
            "isss",
            $estadoId,
            $seccion,
            $fuente,
            $tipoActualizacion
        );

        if (!$stmtBuscar->execute()) {
            throw new Exception('No fue posible consultar la fuente automática.');
        }

        $fuenteExistente = $stmtBuscar->get_result()->fetch_assoc();

        if ($fuenteExistente) {
            $sqlActualizar = "UPDATE fuentes_datos_territoriales
                    SET periodo = ?,
                        url_fuente = ?,
                        fecha_consulta = NOW(),
                        updated_at = NOW()
                    WHERE id = ?";

            $stmtActualizar = $this->connection->prepare($sqlActualizar);
            $stmtActualizar->bind_param(
                "ssi",
                $periodo,
                $urlFuente,
                $fuenteExistente['id']
            );

            if (!$stmtActualizar->execute()) {
                throw new Exception('No fue posible actualizar la fuente automática.');
            }

            return true;
        }

        $sqlInsertar = "INSERT INTO fuentes_datos_territoriales (
                    estado_id,
                    seccion,
                    fuente,
                    url_fuente,
                    periodo,
                    tipo_actualizacion,
                    fecha_consulta,
                    usuario_verifico_id
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL)";

        $stmtInsertar = $this->connection->prepare($sqlInsertar);
        $stmtInsertar->bind_param(
            "isssss",
            $estadoId,
            $seccion,
            $fuente,
            $urlFuente,
            $periodo,
            $tipoActualizacion
        );

        if (!$stmtInsertar->execute()) {
            throw new Exception('No fue posible registrar la fuente automática.');
        }

        return true;
    }

    private function guardarFuenteDenueAutomatica(int $estadoId): bool
    {
        $seccion = 'ACTIVIDAD_ECONOMICA';
        $fuente = 'INEGI - DENUE';
        $tipoActualizacion = 'AUTOMATICA';
        $urlFuente = 'https://www.inegi.org.mx/app/mapa/denue/';
        $periodo = null;

        $sqlBuscar = "SELECT id
                FROM fuentes_datos_territoriales
                WHERE estado_id = ?
                    AND seccion = ?
                    AND fuente = ?
                    AND tipo_actualizacion = ?
                LIMIT 1";

        $stmtBuscar = $this->connection->prepare($sqlBuscar);
        $stmtBuscar->bind_param(
            "isss",
            $estadoId,
            $seccion,
            $fuente,
            $tipoActualizacion
        );

        if (!$stmtBuscar->execute()) {
            throw new Exception('No fue posible consultar la fuente DENUE.');
        }

        $fuenteExistente = $stmtBuscar->get_result()->fetch_assoc();

        if ($fuenteExistente) {
            $sqlActualizar = "UPDATE fuentes_datos_territoriales
                    SET url_fuente = ?,
                        periodo = ?,
                        fecha_consulta = NOW(),
                        updated_at = NOW()
                    WHERE id = ?";

            $stmtActualizar = $this->connection->prepare($sqlActualizar);
            $stmtActualizar->bind_param(
                "ssi",
                $urlFuente,
                $periodo,
                $fuenteExistente['id']
            );

            if (!$stmtActualizar->execute()) {
                throw new Exception('No fue posible actualizar la fuente DENUE.');
            }

            return true;
        }

        $sqlInsertar = "INSERT INTO fuentes_datos_territoriales (
                    estado_id,
                    seccion,
                    fuente,
                    url_fuente,
                    periodo,
                    tipo_actualizacion,
                    fecha_consulta,
                    usuario_verifico_id
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL)";

        $stmtInsertar = $this->connection->prepare($sqlInsertar);
        $stmtInsertar->bind_param(
            "isssss",
            $estadoId,
            $seccion,
            $fuente,
            $urlFuente,
            $periodo,
            $tipoActualizacion
        );

        if (!$stmtInsertar->execute()) {
            throw new Exception('No fue posible registrar la fuente DENUE.');
        }

        return true;
    }

    private function validarSectoresActividadEconomica(array $sectores): array
    {
        $sectoresValidados = [];
        $clavesSectorPermitidas = [
            '11',
            '21',
            '22',
            '23',
            '31-33',
            '43',
            '46',
            '48-49',
            '51',
            '52',
            '53',
            '54',
            '55',
            '56',
            '61',
            '62',
            '71',
            '72',
            '81',
            '93'
        ];

        foreach ($sectores as $sector) {
            if (!is_array($sector)) {
                throw new InvalidArgumentException('La información de sectores no es válida.');
            }

            foreach (['clave_sector', 'nombre_sector', 'establecimientos', 'porcentaje'] as $campo) {
                if (!array_key_exists($campo, $sector)) {
                    throw new InvalidArgumentException('La información de sectores está incompleta.');
                }
            }

            $claveSector = trim((string)$sector['clave_sector']);

            if (!in_array($claveSector, $clavesSectorPermitidas, true)) {
                throw new InvalidArgumentException('La clave del sector no es válida.');
            }

            if (!is_string($sector['nombre_sector']) || trim($sector['nombre_sector']) === '') {
                throw new InvalidArgumentException('El nombre del sector no es válido.');
            }

            if (!is_int($sector['establecimientos']) || $sector['establecimientos'] < 0) {
                throw new InvalidArgumentException('Los establecimientos del sector no son válidos.');
            }

            if (!is_numeric($sector['porcentaje'])) {
                throw new InvalidArgumentException('El porcentaje del sector no es válido.');
            }

            $porcentaje = (float)$sector['porcentaje'];

            if ($porcentaje < 0 || $porcentaje > 100) {
                throw new InvalidArgumentException('El porcentaje del sector no es válido.');
            }

            $sectoresValidados[] = [
                'clave_sector' => $claveSector,
                'nombre_sector' => trim($sector['nombre_sector']),
                'establecimientos' => $sector['establecimientos'],
                'porcentaje' => $porcentaje
            ];
        }

        return $sectoresValidados;
    }

    public function crearSecretaria($datos)
    {
        $sql = "INSERT INTO secretarias_estatales (
                    estado_id,
                    nombre,
                    titular,
                    cargo_titular,
                    correo,
                    telefono,
                    sitio_web,
                    estado
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "issssss",
            $datos['estado_id'],
            $datos['nombre'],
            $datos['titular'],
            $datos['cargo_titular'],
            $datos['correo'],
            $datos['telefono'],
            $datos['sitio_web']
        );

        return $stmt->execute();
    }

    public function actualizarSecretaria($id, $datos)
    {
        $sql = "UPDATE secretarias_estatales
                SET nombre = ?,
                    titular = ?,
                    cargo_titular = ?,
                    correo = ?,
                    telefono = ?,
                    sitio_web = ?,
                    updated_at = NOW()
                WHERE id = ?
                    AND estado_id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "ssssssii",
            $datos['nombre'],
            $datos['titular'],
            $datos['cargo_titular'],
            $datos['correo'],
            $datos['telefono'],
            $datos['sitio_web'],
            $id,
            $datos['estado_id']
        );

        return $stmt->execute();
    }

    public function cambiarEstadoSecretaria($id, $estadoId, $estado)
    {
        return $this->cambiarEstadoRegistro(
            'secretarias_estatales',
            $id,
            $estadoId,
            $estado
        );
    }

    public function crearIndicador($datos)
    {
        $sql = "INSERT INTO rezago_educativo (
                    estado_id,
                    situacion,
                    valor,
                    unidad,
                    porcentaje,
                    cantidad_aproximada,
                    fuente,
                    periodo,
                    fecha_consulta,
                    orden,
                    estado
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, NOW(),
                    COALESCE(
                        (
                            SELECT MAX(orden) + 1
                            FROM (
                                SELECT orden
                                FROM rezago_educativo
                                WHERE estado_id = ?
                            ) AS indicadores_estado
                        ),
                        1
                    ),
                    1
                )";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "isdsdissi",
            $datos['estado_id'],
            $datos['situacion'],
            $datos['valor'],
            $datos['unidad'],
            $datos['porcentaje'],
            $datos['cantidad_aproximada'],
            $datos['fuente'],
            $datos['periodo'],
            $datos['estado_id']
        );

        return $stmt->execute();
    }

    public function actualizarIndicador($id, $datos)
    {
        $sql = "UPDATE rezago_educativo
                SET situacion = ?,
                    valor = ?,
                    unidad = ?,
                    porcentaje = ?,
                    cantidad_aproximada = ?,
                    fuente = ?,
                    periodo = ?,
                    fecha_consulta = NOW(),
                    updated_at = NOW()
                WHERE id = ?
                    AND estado_id = ?
                    AND estado = 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "sdsdissii",
            $datos['situacion'],
            $datos['valor'],
            $datos['unidad'],
            $datos['porcentaje'],
            $datos['cantidad_aproximada'],
            $datos['fuente'],
            $datos['periodo'],
            $id,
            $datos['estado_id']
        );

        return $stmt->execute();
    }

    public function cambiarEstadoIndicador($id, $estadoId, $estado)
    {
        return $this->cambiarEstadoRegistro(
            'rezago_educativo',
            $id,
            $estadoId,
            $estado
        );
    }

    public function existeMunicipioPorNombre($estadoId, $nombre, $idExcluir = null)
    {
        $sql = "SELECT id
                FROM municipios
                WHERE estado_id = ?
                    AND nombre = ?";
        $parametros = [(int)$estadoId, $nombre];
        $tipos = 'is';

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

    public function crearMunicipio($datos)
    {
        $sql = "INSERT INTO municipios (
                    estado_id,
                    clave_inegi,
                    numero_excel,
                    nombre,
                    poblacion,
                    presidente_municipal,
                    partido_politico,
                    redes_sociales,
                    fotografia,
                    fecha_actualizacion,
                    estado
                ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 1)";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "ississsss",
            $datos['estado_id'],
            $datos['clave_inegi'],
            $datos['nombre'],
            $datos['poblacion'],
            $datos['presidente_municipal'],
            $datos['partido_politico'],
            $datos['redes_sociales'],
            $datos['fotografia'],
            $datos['fecha_actualizacion']
        );

        return $stmt->execute();
    }

    public function actualizarMunicipio($id, $datos)
    {
        $sql = "UPDATE municipios
                SET presidente_municipal = ?,
                    partido_politico = ?,
                    redes_sociales = ?,
                    fotografia = ?,
                    fecha_actualizacion = ?,
                    updated_at = NOW()
                WHERE id = ?
                    AND estado_id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            "sssssii",
            $datos['presidente_municipal'],
            $datos['partido_politico'],
            $datos['redes_sociales'],
            $datos['fotografia'],
            $datos['fecha_actualizacion'],
            $id,
            $datos['estado_id']
        );

        return $stmt->execute();
    }

    public function cambiarEstadoMunicipio($id, $estadoId, $estado)
    {
        return $this->cambiarEstadoRegistro(
            'municipios',
            $id,
            $estadoId,
            $estado
        );
    }

    public function buscarMunicipioPorId($id)
    {
        return $this->buscarRegistroPorId('municipios', $id);
    }

    public function contarMunicipiosConFotografia($ruta)
    {
        $sql = "SELECT COUNT(*) AS total
                FROM municipios
                WHERE fotografia = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("s", $ruta);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return (int)$fila['total'];
    }

    public function contarEstadosConArchivo($campo, $ruta)
    {
        if (!in_array($campo, ['foto_titular', 'mapa_estado'], true)) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS total
                FROM estados
                WHERE $campo = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("s", $ruta);
        $stmt->execute();

        $fila = $stmt->get_result()->fetch_assoc();

        return (int)$fila['total'];
    }

    private function existeEstadoActivo($estadoId)
    {
        $sql = "SELECT id
                FROM estados
                WHERE id = ?
                    AND estado = 1
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $estadoId);
        $stmt->execute();

        return $stmt->get_result()->num_rows > 0;
    }

    private function expresionTieneInformacionTerritorial()
    {
        return "CASE WHEN
                    TRIM(COALESCE(estados.capital, '')) <> ''
                    OR TRIM(COALESCE(estados.titular_gobierno, '')) <> ''
                    OR TRIM(COALESCE(estados.partido_politico, '')) <> ''
                    OR estados.poblacion IS NOT NULL
                    OR TRIM(COALESCE(estados.periodo_gobierno, '')) <> ''
                    OR TRIM(COALESCE(estados.telefono, '')) <> ''
                    OR TRIM(COALESCE(estados.redes_sociales, '')) <> ''
                    OR TRIM(COALESCE(estados.actividad_economica, '')) <> ''
                    OR TRIM(COALESCE(estados.poder_adquisitivo, '')) <> ''
                    OR TRIM(COALESCE(estados.foto_titular, '')) <> ''
                    OR TRIM(COALESCE(estados.mapa_estado, '')) <> ''
                    OR EXISTS (
                        SELECT 1
                        FROM municipios
                        WHERE municipios.estado_id = estados.id
                            AND municipios.estado = 1
                        LIMIT 1
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM secretarias_estatales
                        WHERE secretarias_estatales.estado_id = estados.id
                            AND secretarias_estatales.estado = 1
                        LIMIT 1
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM poder_adquisitivo_estado
                        WHERE poder_adquisitivo_estado.estado_id = estados.id
                        LIMIT 1
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM rezago_educativo
                        WHERE rezago_educativo.estado_id = estados.id
                            AND rezago_educativo.estado = 1
                        LIMIT 1
                    )
                THEN 1 ELSE 0 END";
    }

    private function obtenerTipoAsignacionPorRol($rolId)
    {
        $sql = "SELECT nombre
                FROM roles
                WHERE id = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $rolId);
        $stmt->execute();
        $rol = $stmt->get_result()->fetch_assoc();
        $nombreRol = strtolower(trim($rol['nombre'] ?? ''));

        if ($nombreRol === 'cuenta clave') {
            return 'CUENTA_CLAVE';
        }

        if ($nombreRol === 'analista de datos') {
            return 'ANALISTA_DATOS';
        }

        return '';
    }

    private function cambiarEstadoRegistro($tabla, $id, $estadoId, $estado)
    {
        $tablasPermitidas = [
            'secretarias_estatales',
            'rezago_educativo',
            'municipios'
        ];

        if (!in_array($tabla, $tablasPermitidas, true)) {
            return false;
        }

        $sql = "UPDATE $tabla
                SET estado = ?,
                    updated_at = NOW()
                WHERE id = ?
                    AND estado_id = ?";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("iii", $estado, $id, $estadoId);

        return $stmt->execute();
    }

    private function buscarRegistroPorId($tabla, $id)
    {
        if ($tabla !== 'municipios') {
            return null;
        }

        $sql = "SELECT *
                FROM municipios
                WHERE id = ?
                LIMIT 1";

        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
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

    public function actualizarMunicipiosOficiales(
        int $estadoId,
        array $municipios
    ): array {
        if ($estadoId <= 0 || empty($municipios)) {
            return [
                'ok' => false,
                'procesados' => 0,
                'mensaje' => 'No hay municipios válidos para actualizar.'
            ];
        }

        $estado = $this->obtenerEstado($estadoId);
        $claveEstado = str_pad(trim((string)($estado['clave_inegi'] ?? '')), 2, '0', STR_PAD_LEFT);

        if (!$estado || !preg_match('/^\d{2}$/', $claveEstado)) {
            return [
                'ok' => false,
                'procesados' => 0,
                'mensaje' => 'El territorio no tiene una clave INEGI válida.'
            ];
        }

        $transaccionIniciada = false;
        $stmt = null;

        try {
            if (!$this->connection->begin_transaction()) {
                throw new RuntimeException(
                    'No fue posible iniciar la transacción municipal.'
                );
            }

            $transaccionIniciada = true;

            /*
            |--------------------------------------------------------------------------
            | UPSERT de datos oficiales
            |--------------------------------------------------------------------------
            |
            | La actualización oficial únicamente modifica:
            | - clave INEGI
            | - nombre
            | - población
            | - fecha de actualización
            |
            | Se conservan intactos:
            | - presidente_municipal
            | - partido_politico
            | - redes_sociales
            | - fotografia
            |
            */

            $sql = "
                INSERT INTO municipios (
                    estado_id,
                    clave_inegi,
                    nombre,
                    poblacion,
                    fecha_actualizacion,
                    estado,
                    created_at,
                    updated_at
                )
                VALUES (?, ?, ?, ?, NOW(), 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    clave_inegi = VALUES(clave_inegi),
                    nombre = VALUES(nombre),
                    poblacion = VALUES(poblacion),
                    fecha_actualizacion = NOW(),
                    estado = 1,
                    updated_at = NOW()
            ";

            $stmt = $this->connection->prepare($sql);

            if (!$stmt) {
                throw new RuntimeException(
                    'No fue posible preparar la actualización municipal: '
                    . $this->connection->error
                );
            }

            $procesados = 0;

            foreach ($municipios as $municipio) {
                $clave = trim((string)($municipio['clave_geoestadistica'] ?? ''));
                $nombre = trim((string)($municipio['nombre'] ?? ''));
                $poblacionRaw = $municipio['poblacion'] ?? null;
                $claveEstadoMunicipio = str_pad(
                    trim((string)($municipio['clave_estado'] ?? '')),
                    2,
                    '0',
                    STR_PAD_LEFT
                );

                if (!preg_match('/^\d{5}$/', $clave)) {
                    throw new RuntimeException(
                        'Se encontró una clave INEGI municipal inválida.'
                    );
                }

                if (
                    $claveEstadoMunicipio !== $claveEstado ||
                    substr($clave, 0, 2) !== $claveEstado
                ) {
                    throw new RuntimeException(
                        'Se encontró un municipio que no corresponde al territorio.'
                    );
                }

                if ($nombre === '') {
                    throw new RuntimeException(
                        'Se encontró un municipio sin nombre.'
                    );
                }

                if (
                    $poblacionRaw !== null &&
                    (!is_numeric($poblacionRaw) || (int)$poblacionRaw < 0)
                ) {
                    throw new RuntimeException(
                        'Se encontró una población municipal inválida.'
                    );
                }

                $poblacion = $poblacionRaw !== null
                    ? (int)$poblacionRaw
                    : null;

                if (!$stmt->bind_param(
                    'issi',
                    $estadoId,
                    $clave,
                    $nombre,
                    $poblacion
                )) {
                    throw new RuntimeException(
                        'No fue posible preparar los datos del municipio.'
                    );
                }

                if (!$stmt->execute()) {
                    throw new RuntimeException(
                        'No fue posible guardar el municipio '
                        . $nombre
                        . ': '
                        . $stmt->error
                    );
                }

                $procesados++;
            }

            $stmt->close();
            $stmt = null;

            if ($procesados !== count($municipios)) {
                throw new RuntimeException(
                    'No se procesaron todos los municipios recibidos.'
                );
            }

            $sqlEstado = "UPDATE estados
                    SET total_municipios = ?,
                        fecha_actualizacion = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                        AND estado = 1";
            $stmtEstado = $this->connection->prepare($sqlEstado);

            if (!$stmtEstado) {
                throw new RuntimeException(
                    'No fue posible preparar la actualización del territorio.'
                );
            }

            $stmtEstado->bind_param('ii', $procesados, $estadoId);

            if (!$stmtEstado->execute()) {
                throw new RuntimeException(
                    'No fue posible actualizar el total de municipios del territorio.'
                );
            }

            $stmtEstado->close();
            $this->guardarFuenteMunicipiosAutomatica($estadoId);

            if (!$this->connection->commit()) {
                throw new RuntimeException(
                    'No fue posible confirmar la actualización municipal.'
                );
            }

            $transaccionIniciada = false;

            return [
                'ok' => true,
                'procesados' => $procesados,
                'mensaje' => 'Municipios actualizados correctamente.'
            ];
        } catch (Throwable $e) {
            if ($stmt instanceof mysqli_stmt) {
                try {
                    $stmt->close();
                } catch (Throwable $closeError) {
                    // No interrumpir el manejo del error principal.
                }
            }

            if ($transaccionIniciada) {
                try {
                    $this->connection->rollback();
                } catch (Throwable $rollbackError) {
                    error_log(
                        'Error al revertir actualización municipal: '
                        . $rollbackError->getMessage()
                    );
                }
            }

            error_log(
                'Error actualizando municipios oficiales: '
                . $e->getMessage()
            );

            return [
                'ok' => false,
                'procesados' => 0,
                'mensaje' => 'No fue posible actualizar la información municipal.'
            ];
        }
    }

    private function guardarFuenteMunicipiosAutomatica(int $estadoId): bool
    {
        $seccion = 'MUNICIPIOS';
        $fuente = 'INEGI - Catálogo Único de Claves Geoestadísticas';
        $tipoActualizacion = 'AUTOMATICA';
        $urlFuente = 'https://www.inegi.org.mx/servicios/catalogounico.html';
        $periodo = null;

        $sqlBuscar = "SELECT id
                FROM fuentes_datos_territoriales
                WHERE estado_id = ?
                    AND seccion = ?
                    AND fuente = ?
                    AND tipo_actualizacion = ?
                LIMIT 1";
        $stmtBuscar = $this->connection->prepare($sqlBuscar);

        if (!$stmtBuscar) {
            throw new RuntimeException('No fue posible preparar la consulta de la fuente municipal.');
        }

        $stmtBuscar->bind_param(
            'isss',
            $estadoId,
            $seccion,
            $fuente,
            $tipoActualizacion
        );

        if (!$stmtBuscar->execute()) {
            throw new RuntimeException('No fue posible consultar la fuente municipal.');
        }

        $fuenteExistente = $stmtBuscar->get_result()->fetch_assoc();
        $stmtBuscar->close();

        if ($fuenteExistente) {
            $sqlActualizar = "UPDATE fuentes_datos_territoriales
                    SET url_fuente = ?,
                        periodo = ?,
                        fecha_consulta = NOW(),
                        usuario_verifico_id = NULL,
                        updated_at = NOW()
                    WHERE id = ?";
            $stmtActualizar = $this->connection->prepare($sqlActualizar);

            if (!$stmtActualizar) {
                throw new RuntimeException('No fue posible preparar la actualización de la fuente municipal.');
            }

            $idFuente = (int)$fuenteExistente['id'];
            $stmtActualizar->bind_param('ssi', $urlFuente, $periodo, $idFuente);

            if (!$stmtActualizar->execute()) {
                throw new RuntimeException('No fue posible actualizar la fuente municipal.');
            }

            $stmtActualizar->close();
            return true;
        }

        $sqlInsertar = "INSERT INTO fuentes_datos_territoriales (
                    estado_id,
                    seccion,
                    fuente,
                    url_fuente,
                    periodo,
                    tipo_actualizacion,
                    fecha_consulta,
                    usuario_verifico_id
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL)";
        $stmtInsertar = $this->connection->prepare($sqlInsertar);

        if (!$stmtInsertar) {
            throw new RuntimeException('No fue posible preparar el registro de la fuente municipal.');
        }

        $stmtInsertar->bind_param(
            'isssss',
            $estadoId,
            $seccion,
            $fuente,
            $urlFuente,
            $periodo,
            $tipoActualizacion
        );

        if (!$stmtInsertar->execute()) {
            throw new RuntimeException('No fue posible registrar la fuente municipal.');
        }

        $stmtInsertar->close();
        return true;
    }

}
