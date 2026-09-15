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

    public function construir(array $seguimientos, array $filtros)
    {
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
        $fechaFinalConsulta = $fechaFinalFiltro;

        if ($fechaInicialFiltro !== '' && $fechaFinalConsulta === '') {
            $fechaFinalConsulta = date('Y-m-d');
        }

        $conteosDiarios = $this->obtenerConteosDiarios(
            $seguimientoIds,
            $fechaInicialFiltro,
            $fechaFinalConsulta,
            $canal
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
            $total
        );

        return [
            'periodos' => $periodos,
            'total' => (int)$total,
            'mayor' => $mayor,
            'menor' => $menor,
            'variacion' => $comparacion['variacion'],
            'total_anterior' => $comparacion['total_anterior'],
            'comparacion_disponible' => $comparacion['disponible'],
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
        $canal
    ) {
        if (empty($seguimientoIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($seguimientoIds), '?'));
        $sql = "SELECT
                    DATE(fecha_inicio) AS fecha,
                    COUNT(*) AS total
                FROM interacciones_vinculacion
                WHERE seguimiento_id IN ($placeholders)";
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

        if ($canal !== '') {
            $sql .= " AND canal = ?";
            $parametros[] = $canal;
            $tipos .= 's';
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
        $totalActual
    ) {
        if ($fechaInicial === '' || $fechaFinal === '') {
            return [
                'disponible' => false,
                'variacion' => null,
                'total_anterior' => null
            ];
        }

        $inicio = $this->crearFecha($fechaInicial);
        $fin = $this->crearFecha($fechaFinal);

        if (!$inicio || !$fin || $inicio > $fin) {
            return [
                'disponible' => false,
                'variacion' => null,
                'total_anterior' => null
            ];
        }

        $duracion = ((int)$inicio->diff($fin)->days) + 1;
        $finAnterior = $inicio->modify('-1 day');
        $inicioAnterior = $finAnterior->modify('-' . ($duracion - 1) . ' days');
        $conteosAnterior = $this->obtenerConteosDiarios(
            $seguimientoIds,
            $inicioAnterior->format('Y-m-d'),
            $finAnterior->format('Y-m-d'),
            $canal
        );
        $totalAnterior = array_sum($conteosAnterior);

        if ($totalAnterior <= 0) {
            return [
                'disponible' => false,
                'variacion' => null,
                'total_anterior' => 0
            ];
        }

        return [
            'disponible' => true,
            'variacion' => (($totalActual - $totalAnterior) / $totalAnterior) * 100,
            'total_anterior' => (int)$totalAnterior
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
            'granularidad' => 'dia',
            'fecha_inicial' => '',
            'fecha_final' => '',
            'sin_datos' => true
        ];
    }
}
