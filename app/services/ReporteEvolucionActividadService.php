<?php

require_once __DIR__ . '/../models/SeguimientoVinculacionReporteActividadModel.php';

class ReporteEvolucionActividadService
{
    private $modeloActividad;

    public function __construct($modeloActividad = null)
    {
        $this->modeloActividad = $modeloActividad ?: new SeguimientoVinculacionReporteActividadModel();
    }

    public function construir(
        array $seguimientosReporte,
        array $seguimientosPeriodoAnterior,
        array $filtrosReporte,
        array $filtrosPeriodoAnterior
    )
    {
        $resultado = $this->crearResultadoBase();
        $fechaInicial = trim((string)($filtrosReporte['fecha_inicial'] ?? ''));
        $fechaFinal = trim((string)($filtrosReporte['fecha_final'] ?? ''));

        if ($fechaInicial === '' || $fechaFinal === '') {
            $resultado['mensaje'] = 'Selecciona una fecha inicial y una fecha final para visualizar la evolución de la actividad.';
            return $resultado;
        }

        try {
            $inicio = new DateTimeImmutable($fechaInicial . ' 00:00:00');
            $fin = new DateTimeImmutable($fechaFinal . ' 00:00:00');
        } catch (Throwable $error) {
            $resultado['mensaje'] = 'No fue posible interpretar el periodo seleccionado.';
            return $resultado;
        }

        if ($inicio > $fin) {
            $resultado['mensaje'] = 'No fue posible interpretar el periodo seleccionado.';
            return $resultado;
        }

        $seguimientoIds = $this->obtenerSeguimientoIds($seguimientosReporte);
        $canal = strtoupper(trim((string)($filtrosReporte['tipo_actividad'] ?? '')));

        try {
            $conteosDiarios = $this->modeloActividad->obtenerConteosDiarios(
                $seguimientoIds,
                $inicio->format('Y-m-d 00:00:00'),
                $fin->modify('+1 day')->format('Y-m-d 00:00:00'),
                $canal
            );

            $periodos = $this->construirPeriodos($inicio, $fin, $conteosDiarios);
            $total = 0;

            foreach ($periodos['periodos'] as $periodo) {
                $total += (int)$periodo['total'];
            }

            $mayor = $this->obtenerExtremo($periodos['periodos'], true, $total);
            $menor = $this->obtenerExtremo($periodos['periodos'], false, $total);
            $inicioAnterior = $this->crearFechaFiltro($filtrosPeriodoAnterior['fecha_inicial'] ?? '');
            $finAnterior = $this->crearFechaFiltro($filtrosPeriodoAnterior['fecha_final'] ?? '');
            $conteosAnteriores = [];

            if ($inicioAnterior && $finAnterior && $inicioAnterior <= $finAnterior) {
                $conteosAnteriores = $this->modeloActividad->obtenerConteosDiarios(
                    $this->obtenerSeguimientoIds($seguimientosPeriodoAnterior),
                    $inicioAnterior->format('Y-m-d 00:00:00'),
                    $finAnterior->modify('+1 day')->format('Y-m-d 00:00:00'),
                    $canal
                );
            }
            $totalAnterior = array_sum(array_map('intval', $conteosAnteriores));
            $variacion = null;
            $variacionLabel = 'Sin comparación disponible';

            if ($totalAnterior > 0) {
                $variacion = round((($total - $totalAnterior) / $totalAnterior) * 100, 1);
                $variacionLabel = ($variacion > 0 ? '+' : '') . number_format($variacion, 1, '.', '') . '%';
            }

            return [
                'disponible' => true,
                'mensaje' => $total === 0
                    ? 'No se registraron actividades durante el periodo seleccionado.'
                    : '',
                'granularidad' => $periodos['granularidad'],
                'granularidad_label' => $periodos['granularidad_label'],
                'periodos' => $periodos['periodos'],
                'total' => $total,
                'mayor' => $mayor,
                'menor' => $menor,
                'total_anterior' => (int)$totalAnterior,
                'variacion_porcentaje' => $variacion,
                'variacion_label' => $variacionLabel,
                'periodo_anterior' => $inicioAnterior && $finAnterior
                    ? $inicioAnterior->format('d/m/Y') . ' - ' . $finAnterior->format('d/m/Y')
                    : ''
            ];
        } catch (Throwable $error) {
            error_log('[reporte_evolucion_actividad] ' . $error->getMessage());
            $resultado['mensaje'] = 'No fue posible obtener la evolución de la actividad en este momento.';
            return $resultado;
        }
    }

    private function crearFechaFiltro($valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($valor . ' 00:00:00');
        } catch (Throwable $error) {
            return null;
        }
    }

    private function crearResultadoBase()
    {
        return [
            'disponible' => false,
            'mensaje' => '',
            'granularidad' => '',
            'granularidad_label' => '',
            'periodos' => [],
            'total' => 0,
            'mayor' => null,
            'menor' => null,
            'total_anterior' => null,
            'variacion_porcentaje' => null,
            'variacion_label' => 'Sin comparación disponible',
            'periodo_anterior' => ''
        ];
    }

    private function obtenerSeguimientoIds(array $seguimientos)
    {
        $ids = [];

        foreach ($seguimientos as $seguimiento) {
            $id = (int)($seguimiento['id'] ?? 0);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function construirPeriodos(DateTimeImmutable $inicio, DateTimeImmutable $fin, array $conteosDiarios)
    {
        $duracionDias = ((int)$inicio->diff($fin)->days) + 1;

        if ($duracionDias <= 14) {
            return [
                'granularidad' => 'dia',
                'granularidad_label' => 'Por día',
                'periodos' => $this->construirPeriodosDiarios($inicio, $fin, $conteosDiarios)
            ];
        }

        if ($duracionDias <= 90) {
            return [
                'granularidad' => 'semana',
                'granularidad_label' => 'Por semana',
                'periodos' => $this->construirPeriodosSemanales($inicio, $fin, $conteosDiarios)
            ];
        }

        return [
            'granularidad' => 'mes',
            'granularidad_label' => 'Por mes',
            'periodos' => $this->construirPeriodosMensuales($inicio, $fin, $conteosDiarios)
        ];
    }

    private function construirPeriodosDiarios(DateTimeImmutable $inicio, DateTimeImmutable $fin, array $conteosDiarios)
    {
        $periodos = [];
        $cursor = $inicio;

        while ($cursor <= $fin) {
            $clave = $cursor->format('Y-m-d');
            $periodos[] = [
                'clave' => $clave,
                'label' => $cursor->format('d') . ' ' . $this->mesCorto((int)$cursor->format('n')),
                'tooltip' => $cursor->format('d') . ' ' . $this->mesLargo((int)$cursor->format('n')) . ' ' . $cursor->format('Y'),
                'total' => (int)($conteosDiarios[$clave] ?? 0)
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $periodos;
    }

    private function construirPeriodosSemanales(DateTimeImmutable $inicio, DateTimeImmutable $fin, array $conteosDiarios)
    {
        $periodos = [];
        $cursor = $inicio;
        $semana = 1;

        while ($cursor <= $fin) {
            $finSemana = $cursor->modify('+6 days');

            if ($finSemana > $fin) {
                $finSemana = $fin;
            }

            $periodos[] = [
                'clave' => 'semana_' . $semana,
                'label' => 'Semana ' . $semana,
                'tooltip' => 'Semana ' . $semana . ' · ' . $cursor->format('d/m/Y') . ' - ' . $finSemana->format('d/m/Y'),
                'total' => $this->sumarConteos($cursor, $finSemana, $conteosDiarios)
            ];
            $cursor = $finSemana->modify('+1 day');
            $semana++;
        }

        return $periodos;
    }

    private function construirPeriodosMensuales(DateTimeImmutable $inicio, DateTimeImmutable $fin, array $conteosDiarios)
    {
        $periodos = [];
        $cursor = $inicio;

        while ($cursor <= $fin) {
            $finMes = $cursor->modify('last day of this month');

            if ($finMes > $fin) {
                $finMes = $fin;
            }

            $mes = (int)$cursor->format('n');
            $periodos[] = [
                'clave' => $cursor->format('Y-m'),
                'label' => $this->mesCorto($mes) . ' ' . $cursor->format('Y'),
                'tooltip' => $this->mesLargo($mes) . ' ' . $cursor->format('Y'),
                'total' => $this->sumarConteos($cursor, $finMes, $conteosDiarios)
            ];
            $cursor = $finMes->modify('+1 day');
        }

        return $periodos;
    }

    private function sumarConteos(DateTimeImmutable $inicio, DateTimeImmutable $fin, array $conteosDiarios)
    {
        $total = 0;
        $cursor = $inicio;

        while ($cursor <= $fin) {
            $total += (int)($conteosDiarios[$cursor->format('Y-m-d')] ?? 0);
            $cursor = $cursor->modify('+1 day');
        }

        return $total;
    }

    private function obtenerExtremo(array $periodos, $mayor, $totalGeneral)
    {
        if (empty($periodos)) {
            return null;
        }

        if ((int)$totalGeneral === 0) {
            return [
                'label' => 'Todos los periodos',
                'tooltip' => 'Todos los periodos',
                'total' => 0
            ];
        }

        $seleccionado = $periodos[0];

        foreach ($periodos as $periodo) {
            $actual = (int)$periodo['total'];
            $referencia = (int)$seleccionado['total'];

            if (($mayor && $actual > $referencia) || (!$mayor && $actual < $referencia)) {
                $seleccionado = $periodo;
            }
        }

        return $seleccionado;
    }

    private function mesCorto($mes)
    {
        $meses = [
            1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
        ];

        return $meses[(int)$mes] ?? '';
    }

    private function mesLargo($mes)
    {
        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        return $meses[(int)$mes] ?? '';
    }
}
