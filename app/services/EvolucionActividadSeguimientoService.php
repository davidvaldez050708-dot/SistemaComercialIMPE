<?php

require_once __DIR__ . '/../../config/db_connection.php';

class EvolucionActividadSeguimientoService
{
    private $connection;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function construir(
        array $seguimientos,
        array $filtros,
        $usuarioId = 0,
        $modoAcceso = ''
    ) {
        $seguimientoIds = [];

        foreach ($seguimientos as $seguimiento) {
            $id = (int)($seguimiento['id'] ?? 0);
            if ($id > 0) {
                $seguimientoIds[$id] = $id;
            }
        }

        $seguimientoIds = array_values($seguimientoIds);
        $fechaInicialFiltro = trim((string)($filtros['fecha_inicial'] ?? ''));
        $fechaFinalFiltro = trim((string)($filtros['fecha_final'] ?? ''));
        $canal = strtoupper(trim((string)($filtros['tipo_actividad'] ?? '')));
        $tipoReporte = strtolower(trim((string)($filtros['tipo_reporte'] ?? '')));
        $usuarioActividad = (
            $tipoReporte === 'actividad' &&
            strtolower(trim((string)$modoAcceso)) === 'analista'
        ) ? max(0, (int)$usuarioId) : 0;
        $fechaFinalConsulta = $fechaFinalFiltro;

        if ($fechaInicialFiltro !== '' && $fechaFinalConsulta === '') {
            $fechaFinalConsulta = date('Y-m-d');
        }

        $conteosDiarios = $this->obtenerConteosDiarios(
            $seguimientoIds,
            $fechaInicialFiltro,
            $fechaFinalConsulta,
            $canal,
            $usuarioActividad
        );

        $fechaInicial = $fechaInicialFiltro;
        $fechaFinal = $fechaFinalConsulta;

        if ($fechaInicial === '') {
            $fechaInicial = !empty($conteosDiarios)
                ? (string)array_key_first($conteosDiarios)
                : ($fechaFinal !== '' ? $fechaFinal : date('Y-m-d'));
        }

        if ($fechaFinal === '') {
            $fechaFinal = !empty($conteosDiarios)
                ? (string)array_key_last($conteosDiarios)
                : $fechaInicial;
        }

        $inicio = $this->crearFecha($fechaInicial);
        $fin = $this->crearFecha($fechaFinal);

        if (!$inicio || !$fin || $inicio > $fin) {
            return $this->estructuraVacia();
        }

        $diasPeriodo = ((int)$inicio->diff($fin)->days) + 1;
        $granularidad = $diasPeriodo <= 14
            ? 'dia'
            : ($diasPeriodo <= 90 ? 'semana' : 'mes');
        $periodos = $this->construirPeriodos(
            $inicio,
            $fin,
            $granularidad,
            $conteosDiarios
        );
        $total = array_sum(array_column($periodos, 'total'));
        $mayor = $this->extremo($periodos, true);
        $menor = $this->extremo($periodos, false);
        $comparacion = $this->compararPeriodoAnterior(
            $seguimientoIds,
            $fechaInicialFiltro,
            $fechaFinalFiltro,
            $canal,
            $total,
            $usuarioActividad
        );

        return [
            'periodos' => $periodos,
            'total' => (int)$total,
            'mayor' => $mayor,
            'menor' => $menor,
            'variacion' => $comparacion['variacion'],
            'total_anterior' => $comparacion['total_anterior'],
            'comparacion_disponible' => $comparacion['disponible'],
            'comparacion_periodo_disponible' => $comparacion['periodo_disponible'],
            'comparacion_etiqueta' => $comparacion['etiqueta'],
            'comparacion_fecha_inicial' => $comparacion['fecha_inicial'],
            'comparacion_fecha_final' => $comparacion['fecha_final'],
            'comparacion_motivo' => $comparacion['motivo'],
            'granularidad' => $granularidad,
            'fecha_inicial' => $inicio->format('Y-m-d'),
            'fecha_final' => $fin->format('Y-m-d'),
            'sin_datos' => $total === 0
        ];
    }

    private function obtenerConteosDiarios(
        array $seguimientoIds,
        $fechaInicial,
        $fechaFinal,
        $canal,
        $usuarioId = 0
    ) {
        if (empty($seguimientoIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($seguimientoIds), '?'));
        $sql = "SELECT
                    DATE(fecha_inicio) AS fecha,
                    COUNT(*) AS total
                FROM interacciones_vinculacion
                WHERE seguimiento_id IN ($placeholders)
                  AND UPPER(TRIM(COALESCE(canal, ''))) <> 'SISTEMA'";
        $parametros = array_map('intval', $seguimientoIds);
        $tipos = str_repeat('i', count($parametros));

        if ($fechaInicial !== '') {
            $sql .= " AND fecha_inicio >= ?";
            $parametros[] = $fechaInicial . ' 00:00:00';
            $tipos .= 's';
        }

        if ($fechaFinal !== '') {
            $sql .= " AND fecha_inicio <= ?";
            $parametros[] = $fechaFinal . ' 23:59:59';
            $tipos .= 's';
        }

        if ((int)$usuarioId > 0) {
            $sql .= " AND usuario_id = ?";
            $parametros[] = (int)$usuarioId;
            $tipos .= 'i';
        }

        if ($canal !== '') {
            if (in_array($canal, ['LLAMADA', 'LLAMADA_IP'], true)) {
                $sql .= " AND UPPER(TRIM(COALESCE(canal, ''))) IN ('LLAMADA', 'LLAMADA_IP')";
            } elseif ($canal === 'NOTA') {
                $sql .= " AND UPPER(TRIM(COALESCE(canal, ''))) NOT IN ('SISTEMA', 'LLAMADA', 'LLAMADA_IP', 'CORREO', 'WHATSAPP')";
            } else {
                $sql .= " AND UPPER(TRIM(COALESCE(canal, ''))) = ?";
                $parametros[] = $canal;
                $tipos .= 's';
            }
        }

        $sql .= " GROUP BY DATE(fecha_inicio) ORDER BY fecha ASC";
        $stmt = $this->connection->prepare($sql);
        $this->vincularParametros($stmt, $tipos, $parametros);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $conteos = [];

        while ($fila = $resultado->fetch_assoc()) {
            $fecha = trim((string)($fila['fecha'] ?? ''));
            if ($fecha !== '') {
                $conteos[$fecha] = (int)($fila['total'] ?? 0);
            }
        }

        return $conteos;
    }

    private function construirPeriodos(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin,
        $granularidad,
        array $conteosDiarios
    ) {
        if ($granularidad === 'dia') {
            return $this->periodosPorDia($inicio, $fin, $conteosDiarios);
        }

        if ($granularidad === 'semana') {
            return $this->periodosPorSemana($inicio, $fin, $conteosDiarios);
        }

        return $this->periodosPorMes($inicio, $fin, $conteosDiarios);
    }

    private function periodosPorDia(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin,
        array $conteosDiarios
    ) {
        $periodos = [];

        for ($fecha = $inicio; $fecha <= $fin; $fecha = $fecha->modify('+1 day')) {
            $clave = $fecha->format('Y-m-d');
            $periodos[] = [
                'clave' => $clave,
                'etiqueta' => $fecha->format('d') . ' ' . $this->mesCorto((int)$fecha->format('n')),
                'tooltip' => $fecha->format('d') . ' ' . $this->mesCorto((int)$fecha->format('n')) . ' ' . $fecha->format('Y'),
                'total' => (int)($conteosDiarios[$clave] ?? 0)
            ];
        }

        return $periodos;
    }

    private function periodosPorSemana(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin,
        array $conteosDiarios
    ) {
        $periodos = [];
        $inicioSemana = $inicio;
        $numero = 1;

        while ($inicioSemana <= $fin) {
            $finSemana = $inicioSemana->modify('+6 days');
            if ($finSemana > $fin) {
                $finSemana = $fin;
            }

            $total = 0;
            for ($fecha = $inicioSemana; $fecha <= $finSemana; $fecha = $fecha->modify('+1 day')) {
                $total += (int)($conteosDiarios[$fecha->format('Y-m-d')] ?? 0);
            }

            $periodos[] = [
                'clave' => 'semana_' . $numero,
                'etiqueta' => 'Semana ' . $numero,
                'tooltip' => 'Semana ' . $numero . ' (' .
                    $inicioSemana->format('d/m/Y') . ' - ' . $finSemana->format('d/m/Y') . ')',
                'total' => $total
            ];
            $numero++;
            $inicioSemana = $finSemana->modify('+1 day');
        }

        return $periodos;
    }

    private function periodosPorMes(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin,
        array $conteosDiarios
    ) {
        $totales = [];

        foreach ($conteosDiarios as $fecha => $total) {
            $clave = substr((string)$fecha, 0, 7);
            if (!isset($totales[$clave])) {
                $totales[$clave] = 0;
            }
            $totales[$clave] += (int)$total;
        }

        $periodos = [];
        $mes = $inicio->modify('first day of this month');
        $ultimoMes = $fin->modify('first day of this month');

        while ($mes <= $ultimoMes) {
            $clave = $mes->format('Y-m');
            $periodos[] = [
                'clave' => $clave,
                'etiqueta' => $this->mesCorto((int)$mes->format('n')) . ' ' . $mes->format('Y'),
                'tooltip' => $this->mesLargo((int)$mes->format('n')) . ' ' . $mes->format('Y'),
                'total' => (int)($totales[$clave] ?? 0)
            ];
            $mes = $mes->modify('+1 month');
        }

        return $periodos;
    }

    private function extremo(array $periodos, $mayor)
    {
        if (empty($periodos)) {
            return ['etiqueta' => '—', 'total' => 0];
        }

        $seleccionado = $periodos[0];

        foreach ($periodos as $periodo) {
            $actual = (int)$periodo['total'];
            $mejor = (int)$seleccionado['total'];

            if (($mayor && $actual > $mejor) || (!$mayor && $actual < $mejor)) {
                $seleccionado = $periodo;
            }
        }

        return [
            'etiqueta' => (string)$seleccionado['etiqueta'],
            'total' => (int)$seleccionado['total']
        ];
    }

    private function compararPeriodoAnterior(
        array $seguimientoIds,
        $fechaInicial,
        $fechaFinal,
        $canal,
        $totalActual,
        $usuarioId = 0
    ) {
        if ($fechaInicial === '' || $fechaFinal === '') {
            return $this->comparacionVacia('El reporte no tiene un periodo completo para comparar.');
        }

        $inicio = $this->crearFecha($fechaInicial);
        $fin = $this->crearFecha($fechaFinal);

        if (!$inicio || !$fin || $inicio > $fin) {
            return $this->comparacionVacia('El periodo seleccionado no es válido.');
        }

        [$inicioAnterior, $finAnterior, $etiqueta] =
            $this->resolverPeriodoAnteriorEquivalente($inicio, $fin);

        if (!$inicioAnterior || !$finAnterior) {
            return $this->comparacionVacia('No fue posible determinar un periodo anterior equivalente.');
        }

        $conteosAnterior = $this->obtenerConteosDiarios(
            $seguimientoIds,
            $inicioAnterior->format('Y-m-d'),
            $finAnterior->format('Y-m-d'),
            $canal,
            $usuarioId
        );
        $totalAnterior = (int)array_sum($conteosAnterior);

        if ($totalAnterior <= 0) {
            return [
                'disponible' => false,
                'periodo_disponible' => true,
                'variacion' => null,
                'total_anterior' => 0,
                'fecha_inicial' => $inicioAnterior->format('Y-m-d'),
                'fecha_final' => $finAnterior->format('Y-m-d'),
                'etiqueta' => $etiqueta,
                'motivo' => 'El periodo anterior equivalente registró 0 actividades; no es posible calcular una variación porcentual.'
            ];
        }

        return [
            'disponible' => true,
            'periodo_disponible' => true,
            'variacion' => (($totalActual - $totalAnterior) / $totalAnterior) * 100,
            'total_anterior' => $totalAnterior,
            'fecha_inicial' => $inicioAnterior->format('Y-m-d'),
            'fecha_final' => $finAnterior->format('Y-m-d'),
            'etiqueta' => $etiqueta,
            'motivo' => ''
        ];
    }

    private function resolverPeriodoAnteriorEquivalente(
        DateTimeImmutable $inicio,
        DateTimeImmutable $fin
    ) {
        $duracion = ((int)$inicio->diff($fin)->days) + 1;

        // Rangos de hasta una semana se comparan con los mismos días de la
        // semana anterior. Ej.: lun-mié contra lun-mié, no contra vie-dom.
        if ($duracion <= 7) {
            return [
                $inicio->modify('-7 days'),
                $fin->modify('-7 days'),
                'Mismos días de la semana anterior'
            ];
        }

        $mismoMes =
            $inicio->format('Y-m') === $fin->format('Y-m');

        if ($mismoMes) {
            $inicioMes = $inicio->modify('first day of this month');
            $finMes = $inicio->modify('last day of this month');
            $esMesCompleto =
                $inicio->format('Y-m-d') === $inicioMes->format('Y-m-d') &&
                $fin->format('Y-m-d') === $finMes->format('Y-m-d');

            $mesAnterior = $inicioMes->modify('-1 month');

            if ($esMesCompleto) {
                return [
                    $mesAnterior,
                    $mesAnterior->modify('last day of this month'),
                    'Mes anterior completo'
                ];
            }

            // Para un tramo mensual (ej. 01-15 Sep) conserva los mismos días
            // del mes anterior, ajustando únicamente si el mes es más corto.
            $diaInicio = (int)$inicio->format('j');
            $diaFin = (int)$fin->format('j');
            $ultimoDiaMesAnterior = (int)$mesAnterior->format('t');
            $inicioAnterior = $mesAnterior->setDate(
                (int)$mesAnterior->format('Y'),
                (int)$mesAnterior->format('n'),
                min($diaInicio, $ultimoDiaMesAnterior)
            );
            $finAnterior = $mesAnterior->setDate(
                (int)$mesAnterior->format('Y'),
                (int)$mesAnterior->format('n'),
                min($diaFin, $ultimoDiaMesAnterior)
            );

            return [
                $inicioAnterior,
                $finAnterior,
                'Mismo tramo del mes anterior'
            ];
        }

        // Rangos personalizados que cruzan meses conservan una ventana anterior
        // de la misma duración.
        $finAnterior = $inicio->modify('-1 day');
        $inicioAnterior = $finAnterior->modify('-' . ($duracion - 1) . ' days');

        return [
            $inicioAnterior,
            $finAnterior,
            'Periodo anterior de igual duración'
        ];
    }

    private function comparacionVacia($motivo)
    {
        return [
            'disponible' => false,
            'periodo_disponible' => false,
            'variacion' => null,
            'total_anterior' => null,
            'fecha_inicial' => '',
            'fecha_final' => '',
            'etiqueta' => '',
            'motivo' => (string)$motivo
        ];
    }

    private function crearFecha($valor)
    {
        $valor = trim((string)$valor);
        if ($valor === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valor);
        } catch (Exception $error) {
            return null;
        }
    }

    private function mesCorto($mes)
    {
        $meses = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        return $meses[(int)$mes] ?? '';
    }

    private function mesLargo($mes)
    {
        $meses = [
            1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
        ];
        return $meses[(int)$mes] ?? '';
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

    private function estructuraVacia()
    {
        return [
            'periodos' => [],
            'total' => 0,
            'mayor' => ['etiqueta' => '—', 'total' => 0],
            'menor' => ['etiqueta' => '—', 'total' => 0],
            'variacion' => null,
            'total_anterior' => null,
            'comparacion_disponible' => false,
            'comparacion_periodo_disponible' => false,
            'comparacion_etiqueta' => '',
            'comparacion_fecha_inicial' => '',
            'comparacion_fecha_final' => '',
            'comparacion_motivo' => '',
            'granularidad' => 'dia',
            'fecha_inicial' => '',
            'fecha_final' => '',
            'sin_datos' => true
        ];
    }
}
