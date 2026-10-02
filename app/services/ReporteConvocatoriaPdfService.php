<?php

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

class ReporteConvocatoriaPdfService
{
    public function generar(array $datosReporte)
    {
        try {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->construirHtml($datosReporte), 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();

            $contenido = $dompdf->output();

            if (!is_string($contenido) || $contenido === '') {
                return [
                    'ok' => false,
                    'mensaje' => 'No fue posible generar el PDF de convocatorias.',
                    'mensaje_tecnico' => 'Dompdf devolvió contenido vacío.'
                ];
            }

            return [
                'ok' => true,
                'contenido_pdf' => $contenido,
                'nombre_archivo' => 'Reporte_Convocatorias_' . date('Y-m-d') . '.pdf'
            ];
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'mensaje' => 'No fue posible generar el PDF de convocatorias.',
                'mensaje_tecnico' => $error->getMessage()
            ];
        }
    }

    private function construirHtml(array $datosReporte)
    {
        $resumen = is_array($datosReporte['resumen'] ?? null)
            ? $datosReporte['resumen']
            : [];
        $detalle = is_array($datosReporte['detalle'] ?? null)
            ? $datosReporte['detalle']
            : [];
        $hallazgos = is_array($datosReporte['hallazgos'] ?? null)
            ? $datosReporte['hallazgos']
            : [];
        $alertasVencimiento = is_array(
            $datosReporte['alertas_vencimiento'] ?? null
        )
            ? $datosReporte['alertas_vencimiento']
            : [];
        $porTipo = is_array($datosReporte['por_tipo'] ?? null)
            ? $datosReporte['por_tipo']
            : [];
        $territorios = is_array($datosReporte['cobertura']['territorios'] ?? null)
            ? $datosReporte['cobertura']['territorios']
            : [];

        $fechaGeneracion = $this->e($datosReporte['fecha_generacion'] ?? date('d/m/Y H:i'));
        $generadoPor = $this->e($datosReporte['generado_por'] ?? '');
        $rol = $this->e($datosReporte['generado_por_rol'] ?? '');

        $filasDetalle = '';
        foreach ($detalle as $fila) {
            $estado = (string)($fila['estado_proceso'] ?? 'inactiva');

            $filasDetalle .= '<tr>' .
                '<td>' . $this->e($fila['titulo'] ?? '') . '</td>' .
                '<td>' . $this->e($this->tipoLabel($fila['tipo_convocatoria'] ?? '')) . '</td>' .
                '<td>' . $this->e($this->subtipoLabel($fila['subtipo_convocatoria'] ?? '')) . '</td>' .
                '<td>' . $this->e($this->fecha($fila['fecha_inicio'] ?? '')) . '</td>' .
                '<td>' . $this->e($this->fecha($fila['fecha_termino'] ?? '')) . '</td>' .
                '<td>' . $this->e($fila['estados'] ?? '—') . '</td>' .
                '<td><span class="status status-' . $this->e($estado) . '">' .
                    $this->e($this->estadoLabel($estado)) .
                '</span></td>' .
            '</tr>';
        }

        if ($filasDetalle === '') {
            $filasDetalle = '<tr><td colspan="7" class="empty">No hay convocatorias registradas.</td></tr>';
        }

        $filasTerritorios = '';
        foreach ($territorios as $territorio) {
            $filasTerritorios .= '<tr>' .
                '<td>' . $this->e($territorio['nombre'] ?? '') . '</td>' .
                '<td class="num">' . (int)($territorio['convocatorias_activas'] ?? 0) . '</td>' .
            '</tr>';
        }

        if ($filasTerritorios === '') {
            $filasTerritorios = '<tr><td colspan="2" class="empty">Sin cobertura activa registrada.</td></tr>';
        }

        $hallazgosHtml = '';
        foreach ($hallazgos as $hallazgo) {
            $hallazgosHtml .= '<li>' . $this->e($hallazgo) . '</li>';
        }

        $filasAlertas = '';
        foreach ($alertasVencimiento as $alerta) {
            $diasRestantes = (int)($alerta['dias_restantes'] ?? 0);
            $prioridad = $diasRestantes === 0
                ? 'Vence hoy'
                : ($diasRestantes === 1 ? 'Vence mañana' : 'Vence en 2 días');
            $clasePrioridad = $diasRestantes === 0
                ? 'priority-danger'
                : 'priority-warning';

            $filasAlertas .= '<tr>' .
                '<td>' . $this->e($alerta['titulo'] ?? '') . '</td>' .
                '<td>' . $this->e($this->tipoLabel($alerta['tipo_convocatoria'] ?? '')) . '</td>' .
                '<td>' . $this->e($alerta['estados'] ?? '—') . '</td>' .
                '<td>' . $this->e($this->fecha($alerta['fecha_termino'] ?? '')) . '</td>' .
                '<td><span class="priority ' . $clasePrioridad . '">' .
                    $this->e($prioridad) .
                '</span></td>' .
            '</tr>';
        }

        if ($filasAlertas === '') {
            $filasAlertas =
                '<tr><td colspan="5" class="empty">' .
                'No hay convocatorias con vencimiento hoy o en los próximos 2 días.' .
                '</td></tr>';
        }

        $totalBachillerato = (int)($porTipo['bachillerato']['total'] ?? 0);
        $totalTitulacion = (int)($porTipo['titulacion']['total'] ?? 0);
        $mesesBachillerato = is_array($porTipo['bachillerato']['meses'] ?? null)
            ? $porTipo['bachillerato']['meses']
            : [];
        $mesesTitulacion = is_array($porTipo['titulacion']['meses'] ?? null)
            ? $porTipo['titulacion']['meses']
            : [];

        $serieMensual = [];
        $maximoMensual = 1;
        $cantidadMeses = max(count($mesesBachillerato), count($mesesTitulacion));

        for ($indiceMes = 0; $indiceMes < $cantidadMeses; $indiceMes++) {
            $filaBachillerato = $mesesBachillerato[$indiceMes] ?? [];
            $filaTitulacion = $mesesTitulacion[$indiceMes] ?? [];

            $labelMes = (string)(
                $filaBachillerato['label'] ??
                $filaTitulacion['label'] ??
                ''
            );
            $bachilleratoMes = (int)($filaBachillerato['total'] ?? 0);
            $titulacionMes = (int)($filaTitulacion['total'] ?? 0);

            $maximoMensual = max(
                $maximoMensual,
                $bachilleratoMes,
                $titulacionMes
            );

            $serieMensual[] = [
                'label' => $labelMes,
                'bachillerato' => $bachilleratoMes,
                'titulacion' => $titulacionMes
            ];
        }

        $filasGraficaTipo = '';

        foreach ($serieMensual as $mesSerie) {
            $bachilleratoMes = (int)($mesSerie['bachillerato'] ?? 0);
            $titulacionMes = (int)($mesSerie['titulacion'] ?? 0);
            $anchoBachillerato = (int)round(
                ($bachilleratoMes / $maximoMensual) * 100
            );
            $anchoTitulacion = (int)round(
                ($titulacionMes / $maximoMensual) * 100
            );

            $filasGraficaTipo .=
                '<tr>' .
                    '<td class="chart-month">' .
                        $this->e($mesSerie['label'] ?? '') .
                    '</td>' .
                    '<td class="chart-series-label">Bachillerato</td>' .
                    '<td class="chart-cell">' .
                        '<div class="chart-track"><div class="chart-bar chart-bar-light" style="width:' .
                            max(2, $anchoBachillerato) . '%"></div></div>' .
                    '</td>' .
                    '<td class="chart-value">' . $bachilleratoMes . '</td>' .
                '</tr>' .
                '<tr>' .
                    '<td></td>' .
                    '<td class="chart-series-label">Titulación</td>' .
                    '<td class="chart-cell">' .
                        '<div class="chart-track"><div class="chart-bar chart-bar-primary" style="width:' .
                            max(2, $anchoTitulacion) . '%"></div></div>' .
                    '</td>' .
                    '<td class="chart-value">' . $titulacionMes . '</td>' .
                '</tr>';
        }

        if ($filasGraficaTipo === '') {
            $filasGraficaTipo =
                '<tr><td colspan="4" class="empty">Sin información mensual disponible.</td></tr>';
        }

        return '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 22px 28px 28px; }
    body {
        margin: 0;
        color: #16223b;
        font-family: "DejaVu Sans", sans-serif;
        font-size: 9px;
        line-height: 1.4;
        background: #ffffff;
    }
    .header {
        padding: 0 0 12px;
        border-bottom: 2px solid #273a8a;
        margin-bottom: 16px;
    }
    .brand {
        color: #273a8a;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
    }
    h1 {
        margin: 3px 0 2px;
        font-size: 20px;
        color: #16223b;
    }
    .meta {
        color: #6d7480;
        font-size: 8px;
    }
    .section-title {
        margin: 18px 0 8px;
        color: #273a8a;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .4px;
    }
    .metrics {
        width: 100%;
        border-collapse: separate;
        border-spacing: 7px;
        margin-left: -7px;
    }
    .metric {
        width: 25%;
        padding: 10px;
        border: 1px solid #e5e9ef;
        background: #f8fafc;
        border-radius: 7px;
        vertical-align: top;
    }
    .metric strong {
        display: block;
        margin-bottom: 3px;
        color: #273a8a;
        font-size: 17px;
        line-height: 1;
    }
    .metric span {
        color: #6d7480;
        font-size: 8px;
    }
    .two-col {
        width: 100%;
        border-collapse: separate;
        border-spacing: 12px 0;
        margin-left: -12px;
    }
    .two-col > tbody > tr > td {
        width: 50%;
        vertical-align: top;
    }
    .panel {
        padding: 11px;
        border: 1px solid #e5e9ef;
        background: #ffffff;
        border-radius: 7px;
    }
    table.data {
        width: 100%;
        border-collapse: collapse;
    }
    table.data th {
        padding: 7px 6px;
        background: #f2f5fa;
        border: 1px solid #e5e9ef;
        color: #16223b;
        font-size: 7.5px;
        text-align: left;
    }
    table.data td {
        padding: 6px;
        border: 1px solid #e5e9ef;
        color: #46536b;
        font-size: 7px;
        vertical-align: top;
    }
    table.data td.num {
        text-align: right;
        font-weight: 700;
        color: #273a8a;
    }
    .status {
        display: inline-block;
        padding: 3px 6px;
        border-radius: 10px;
        font-size: 6.5px;
        font-weight: 700;
    }
    .status-activa { background: #e9f7f2; color: #07866f; }
    .status-proxima { background: #fff3e6; color: #d46a13; }
    .status-finalizada,
    .status-inactiva { background: #eef1f5; color: #65738a; }
    .priority {
        display: inline-block;
        padding: 3px 6px;
        border-radius: 10px;
        font-size: 6.5px;
        font-weight: 700;
    }
    .priority-danger { background: #fff0ee; color: #b42318; }
    .priority-warning { background: #fff3e6; color: #d46a13; }
    .type-cards {
        width: 100%;
        border-collapse: separate;
        border-spacing: 10px 0;
        margin-left: -10px;
    }
    .type-card {
        width: 50%;
        padding: 12px;
        border: 1px solid #dfe5ee;
        border-left: 4px solid #273a8a;
        background: #ffffff;
        vertical-align: top;
    }
    .type-card-title {
        color: #16223b;
        font-size: 10px;
        font-weight: 700;
    }
    .type-card-total {
        margin-top: 8px;
        color: #273a8a;
        font-size: 22px;
        font-weight: 700;
    }
    .type-card-sub {
        color: #6d7480;
        font-size: 7px;
    }
    .chart-table {
        width: 100%;
        margin-top: 8px;
        border-collapse: collapse;
    }
    .chart-table td {
        padding: 4px 5px;
        border: 0;
        font-size: 7px;
        vertical-align: middle;
    }
    .chart-month {
        width: 8%;
        color: #16223b;
        font-weight: 700;
    }
    .chart-series-label {
        width: 12%;
        color: #6d7480;
    }
    .chart-cell {
        width: 72%;
    }
    .chart-value {
        width: 8%;
        color: #16223b;
        font-weight: 700;
        text-align: right;
    }
    .chart-track {
        width: 100%;
        height: 9px;
        background: #edf1f6;
        border-radius: 4px;
    }
    .chart-bar {
        height: 9px;
        border-radius: 4px;
    }
    .chart-bar-light { background: #cfdaf0; }
    .chart-bar-primary { background: #273a8a; }
    ul.findings {
        margin: 0;
        padding-left: 16px;
    }
    ul.findings li {
        margin-bottom: 6px;
        color: #46536b;
    }
    .empty {
        color: #8a96a8;
        text-align: center;
        padding: 12px !important;
    }
    .footer {
        margin-top: 14px;
        padding-top: 8px;
        border-top: 1px solid #e5e9ef;
        color: #8a96a8;
        font-size: 7px;
        text-align: right;
    }
</style>
</head>
<body>
    <div class="header">
        <div class="brand">Sistema Comercial · Convocatorias</div>
        <h1>Reporte de Convocatorias</h1>
        <div class="meta">
            Generado: ' . $fechaGeneracion .
            ($generadoPor !== '' ? ' · Por: ' . $generadoPor : '') .
            ($rol !== '' ? ' · Rol: ' . $rol : '') .
        '</div>
    </div>

    <div class="section-title">Resumen ejecutivo</div>
    <table class="metrics">
        <tr>
            <td class="metric"><strong>' . (int)($resumen['total'] ?? 0) . '</strong><span>Total registradas</span></td>
            <td class="metric"><strong>' . (int)($resumen['activas'] ?? 0) . '</strong><span>Activas</span></td>
            <td class="metric"><strong>' . (int)($resumen['inactivas'] ?? 0) . '</strong><span>Inactivas</span></td>
            <td class="metric"><strong>' . (int)($resumen['publicaciones_mes_actual'] ?? 0) . '</strong><span>Publicadas este mes</span></td>
        </tr>
        <tr>
            <td class="metric"><strong>' . (int)($resumen['publicaciones_hoy'] ?? 0) . '</strong><span>Publicadas hoy</span></td>
            <td class="metric"><strong>' . (int)($resumen['vencen_hoy'] ?? 0) . '</strong><span>Vencen hoy</span></td>
            <td class="metric"><strong>' . (int)($resumen['vencen_2_dias'] ?? 0) . '</strong><span>Vencen en 1–2 días</span></td>
            <td class="metric"><strong>' . (int)($resumen['porcentaje_cobertura'] ?? 0) . '%</strong><span>Cobertura territorial</span></td>
        </tr>
    </table>

    <div class="section-title">Publicaciones por tipo</div>
    <table class="type-cards">
        <tr>
            <td class="type-card">
                <div class="type-card-title">Bachillerato</div>
                <div class="type-card-total">' . $totalBachillerato . '</div>
                <div class="type-card-sub">publicaciones · últimos 30 días</div>
            </td>
            <td class="type-card">
                <div class="type-card-title">Titulación</div>
                <div class="type-card-total">' . $totalTitulacion . '</div>
                <div class="type-card-sub">publicaciones · últimos 30 días</div>
            </td>
        </tr>
    </table>

    <div class="section-title">Tendencia mensual de publicaciones</div>
    <div class="panel">
        <table class="chart-table">
            <tbody>' . $filasGraficaTipo . '</tbody>
        </table>
    </div>

    <table class="two-col"><tr>
        <td>
            <div class="section-title">Hallazgos</div>
            <div class="panel"><ul class="findings">' . $hallazgosHtml . '</ul></div>
        </td>
        <td>
            <div class="section-title">Cobertura territorial</div>
            <div class="panel">
                <table class="data">
                    <thead><tr><th>Estado</th><th>Convocatorias activas</th></tr></thead>
                    <tbody>' . $filasTerritorios . '</tbody>
                </table>
            </div>
        </td>
    </tr></table>

    <div class="section-title">Alertas de vencimiento</div>
    <table class="data">
        <thead>
            <tr>
                <th>Convocatoria</th>
                <th>Tipo</th>
                <th>Territorio(s)</th>
                <th>Fecha de vencimiento</th>
                <th>Prioridad</th>
            </tr>
        </thead>
        <tbody>' . $filasAlertas . '</tbody>
    </table>

    <div class="section-title">Detalle de convocatorias</div>
    <table class="data">
        <thead>
            <tr>
                <th>Título</th>
                <th>Tipo</th>
                <th>Subtipo</th>
                <th>Inicio</th>
                <th>Fin</th>
                <th>Territorio(s)</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>' . $filasDetalle . '</tbody>
    </table>

    <div class="footer">Reporte generado desde el módulo de Convocatorias.</div>
</body>
</html>';
    }

    private function e($valor)
    {
        return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function fecha($valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return '—';
        }

        $timestamp = strtotime($valor);

        return $timestamp !== false ? date('d/m/Y', $timestamp) : $valor;
    }

    private function tipoLabel($tipo)
    {
        $tipo = strtolower(trim((string)$tipo));

        if ($tipo === 'bachillerato') {
            return 'Bachillerato';
        }

        if ($tipo === 'titulacion') {
            return 'Titulación';
        }

        if ($tipo === 'sindicatos') {
            return 'Sindicatos';
        }

        return $tipo !== '' ? ucfirst($tipo) : '—';
    }

    private function subtipoLabel($subtipo)
    {
        $subtipo = trim((string)$subtipo);

        if ($subtipo === '') {
            return '—';
        }

        return ucwords(str_replace('-', ' ', $subtipo));
    }

    private function estadoLabel($estado)
    {
        $estado = strtolower(trim((string)$estado));

        $labels = [
            'activa' => 'Activa',
            'proxima' => 'Próxima a vencer',
            'finalizada' => 'Finalizada',
            'inactiva' => 'Inactiva'
        ];

        return $labels[$estado] ?? 'Inactiva';
    }
}
