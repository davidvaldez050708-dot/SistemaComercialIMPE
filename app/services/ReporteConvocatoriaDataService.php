<?php

require_once __DIR__ . '/../models/ConvocatoriaModel.php';

class ReporteConvocatoriaDataService
{
    private $modelo;

    public function __construct()
    {
        $this->modelo = new ConvocatoriaModel();
    }

    public function prepararDatos()
    {
        $this->modelo->desactivarConvocatoriasVencidas();

        $resumen = $this->modelo->obtenerResumenDashboard();
        $cobertura = $this->modelo->obtenerCoberturaTerritorialDashboard(32);
        $porTipo = $this->modelo->obtenerPublicacionesPorTipoDashboard(30);
        $detalle = $this->modelo->obtenerDetalleReporteConvocatorias();

        $estadosCubiertos = (int)($cobertura['estados_cubiertos'] ?? 0);
        $totalEstados = (int)($cobertura['total_estados'] ?? 0);
        $porcentajeCobertura = $totalEstados > 0
            ? (int)round(($estadosCubiertos / $totalEstados) * 100)
            : 0;

        $totalBachillerato = (int)($porTipo['bachillerato']['total'] ?? 0);
        $totalTitulacion = (int)($porTipo['titulacion']['total'] ?? 0);

        $hallazgos = [];

        $proximas = (int)($resumen['proximas_finalizar'] ?? 0);
        $hallazgos[] = $proximas > 0
            ? 'Existen ' . $proximas . ' convocatorias próximas a vencer en los siguientes 7 días.'
            : 'No hay convocatorias próximas a vencer en los siguientes 7 días.';

        $activas = (int)($resumen['activas'] ?? 0);
        $inactivas = (int)($resumen['inactivas'] ?? 0);
        $hallazgos[] = 'Actualmente hay ' . $activas . ' convocatorias activas y ' .
            $inactivas . ' inactivas.';

        $hallazgos[] = 'La cobertura territorial activa alcanza ' .
            $estadosCubiertos . ' de ' . $totalEstados . ' estados (' .
            $porcentajeCobertura . '%).';

        $hallazgos[] = 'En los últimos 30 días se registraron ' .
            $totalBachillerato . ' publicaciones de Bachillerato y ' .
            $totalTitulacion . ' de Titulación.';

        return [
            'resumen' => [
                'total' => (int)($resumen['total'] ?? 0),
                'activas' => $activas,
                'inactivas' => $inactivas,
                'vigentes' => (int)($resumen['vigentes'] ?? 0),
                'proximas_finalizar' => $proximas,
                'estados_cubiertos' => $estadosCubiertos,
                'total_estados' => $totalEstados,
                'porcentaje_cobertura' => $porcentajeCobertura,
                'bachillerato_30' => $totalBachillerato,
                'titulacion_30' => $totalTitulacion
            ],
            'cobertura' => $cobertura,
            'por_tipo' => $porTipo,
            'detalle' => $detalle,
            'hallazgos' => $hallazgos
        ];
    }
}
