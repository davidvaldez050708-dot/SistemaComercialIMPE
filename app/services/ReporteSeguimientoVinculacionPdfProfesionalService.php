<?php

use Dompdf\Dompdf;
use Dompdf\Options;

class ReporteSeguimientoVinculacionPdfProfesionalService
{
    private const PRIMARY = '#273A8A';
    private const TEXT = '#16223B';
    private const MUTED = '#6D7480';
    private const BORDER = '#E5E9EF';
    private const BG = '#F8FAFC';
    private const PRIMARY_BG = '#EDF2FA';
    private const ACCENT = '#0A9A87';

    public function generar(array $datos): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return $this->error('No fue posible preparar el PDF profesional del reporte.', 'No se encontró vendor/autoload.php.');
        }

        require_once $autoload;
        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            return $this->error('No fue posible preparar el PDF profesional del reporte.', 'Dompdf no está disponible.');
        }

        try {
            $options = new Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->html($datos), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $canvas = $dompdf->getCanvas();
            $metrics = $dompdf->getFontMetrics();
            $font = $metrics->getFont('DejaVu Sans', 'normal');
            $bold = $metrics->getFont('DejaVu Sans', 'bold');

            $canvas->page_script(static function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($font, $bold): void {
                $y = $canvas->get_height() - 24;
                $canvas->line(46, $y - 7, $canvas->get_width() - 46, $y - 7, [0.90, 0.91, 0.94], 0.5);
                $canvas->text(46, $y, 'Grupo Porcayo · Sistema de Gestión Comercial', $bold, 6.4, [0.15, 0.23, 0.54]);
                $pagina = 'Página ' . $pageNumber . ' de ' . $pageCount;
                $ancho = $fontMetrics->getTextWidth($pagina, $font, 6.4);
                $canvas->text($canvas->get_width() - 46 - $ancho, $y, $pagina, $font, 6.4, [0.43, 0.45, 0.50]);
            });

            return [
                'ok' => true,
                'contenido_pdf' => $dompdf->output(),
                'nombre_archivo' => $this->nombreArchivo($datos),
                'conversor' => 'DOMPDF'
            ];
        } catch (Throwable $error) {
            return $this->error('No fue posible generar el PDF profesional del reporte.', $error->getMessage());
        }
    }

    private function html(array $datos): string
    {
        $filtros = is_array($datos['resumen_filtros'] ?? null) ? $datos['resumen_filtros'] : [];
        $filtrosRaw = is_array($datos['filtros_reporte'] ?? null) ? $datos['filtros_reporte'] : [];
        $resumen = is_array($datos['resumen_reporte'] ?? null) ? $datos['resumen_reporte'] : [];
        $seguimientos = is_array($datos['seguimientos'] ?? null) ? $datos['seguimientos'] : [];
        $analitica = is_array($datos['analitica'] ?? null) ? $datos['analitica'] : [];
        $evolucion = is_array($datos['evolucion_actividad'] ?? null) ? $datos['evolucion_actividad'] : [];
        $flujo = is_array($datos['flujo_individual'] ?? null) ? $datos['flujo_individual'] : [];
        $etiquetas = is_array($datos['etiquetas_estatus'] ?? null) ? $datos['etiquetas_estatus'] : [];
        $fecha = $this->fecha((string)($datos['fecha_generacion'] ?? ''));
        $generadoPor = trim((string)($datos['generado_por'] ?? ''));
        $generadoPorRol = trim((string)($datos['generado_por_rol'] ?? ''));
        $individual = (int)($filtrosRaw['institucion_id'] ?? 0) > 0 && count($seguimientos) === 1;
        $responsable = $this->responsableAlcance($seguimientos, $filtros);

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>' . $this->css() . '</style></head><body>';
        $html .= '<div class="top-rule"></div>';
        $html .= $this->encabezado($fecha, $generadoPor, $generadoPorRol, (string)($filtros['Periodo'] ?? 'Todos'));
        $html .= $this->contexto($filtros, $responsable, count($seguimientos), $individual);

        if ($individual) {
            $html .= $this->ficha($seguimientos[0], $flujo);
        }

        $total = (int)($resumen['total'] ?? count($seguimientos));
        $interacciones = (int)($analitica['interacciones'] ?? 0);
        $promedio = $this->decimal($analitica['promedio_por_seguimiento'] ?? 0, 1);
        $atencion = (int)($analitica['atencion']['total'] ?? 0);

        $html .= '<section class="report-section keep">' . $this->titulo('Resumen operativo');
        $html .= '<table class="metrics"><tr>';
        $html .= $this->metric('Seguimientos', (string)$total);
        $html .= $this->metric('Interacciones', (string)$interacciones);
        $html .= $this->metric('Promedio / seguimiento', $promedio);
        $html .= $this->metric('Requieren atención', (string)$atencion);
        $html .= '</tr></table></section>';

        if ($total <= 0) {
            $html .= '<section class="report-section keep">' . $this->titulo('Resultado de la consulta');
            $html .= $this->vacio('No se encontraron seguimientos con los criterios seleccionados.');
            return $html . '</section></body></html>';
        }

        $html .= $this->atencion($analitica['atencion']['casos'] ?? []);
        $html .= $this->contacto($analitica);
        $html .= $this->actividad($evolucion);

        if (!$individual) {
            $html .= $this->distribuciones($resumen, $etiquetas);
            $html .= $this->detalle($seguimientos);
        } else {
            $html .= $this->lecturaIndividual($seguimientos[0], $flujo);
        }

        return $html . '</body></html>';
    }

    private function encabezado(string $fecha, string $generadoPor, string $rol, string $periodo): string
    {
        $logo = $this->logo();
        $html = '<table class="header"><tr><td class="brand">';
        if ($logo !== '') {
            $html .= '<img src="' . $logo . '" alt="Grupo Porcayo">';
        }
        $html .= '</td><td class="header-copy">';
        $html .= '<div class="system-name">Sistema de Gestión Comercial</div>';
        $html .= '<h1>Reporte de Seguimiento de Vinculación</h1>';
        $html .= '<table class="header-meta">';
        if ($generadoPor !== '') {
            $html .= '<tr><td>Generado por</td><th>' . $this->e($generadoPor) . '</th></tr>';
        }
        if ($rol !== '') {
            $html .= '<tr><td>Rol</td><th>' . $this->e($rol) . '</th></tr>';
        }
        $html .= '<tr><td>Fecha</td><th>' . $this->e($fecha) . '</th></tr>';
        $html .= '<tr><td>Periodo</td><th>' . $this->e($periodo) . '</th></tr>';
        $html .= '</table></td></tr></table><div class="header-rule"></div>';
        return $html;
    }

    private function contexto(array $filtros, string $responsable, int $total, bool $individual): string
    {
        $ubicacion = [];
        foreach (['Estado', 'Municipio'] as $campo) {
            $valor = trim((string)($filtros[$campo] ?? ''));
            if ($valor !== '' && !in_array($valor, ['Todos', 'Todas'], true)) {
                $ubicacion[] = $valor;
            }
        }

        $alcance = !empty($ubicacion) ? implode(' · ', $ubicacion) : 'Todos los territorios autorizados';
        $html = '<table class="scope"><tr>';
        $html .= '<td><span>Alcance</span><strong>' . $this->e($alcance) . '</strong></td>';
        $html .= '<td><span>Responsable del alcance</span><strong>' . $this->e($responsable !== '' ? $responsable : 'Varios responsables') . '</strong></td>';
        $html .= '<td class="scope-total"><span>Seguimientos</span><strong>' . $total . '</strong></td>';
        $html .= '</tr></table>';
        return $html;
    }

    private function responsableAlcance(array $seguimientos, array $filtros): string
    {
        $filtrado = trim((string)($filtros['Responsable'] ?? ''));
        if ($filtrado !== '' && $filtrado !== 'Todos') {
            return $filtrado;
        }

        $responsables = [];
        foreach ($seguimientos as $seguimiento) {
            $nombre = trim((string)($seguimiento['responsable_nombre'] ?? ''));
            if ($nombre !== '') {
                $responsables[$nombre] = true;
            }
        }

        if (count($responsables) === 1) {
            return (string)array_key_first($responsables);
        }

        return count($responsables) > 1 ? 'Varios responsables' : '';
    }

    private function ficha(array $s, array $flujo): string
    {
        $nombre = trim((string)($s['nombre_entidad'] ?? 'Institución seleccionada'));
        $ubicacion = implode(', ', array_values(array_filter([
            trim((string)($s['municipio'] ?? '')),
            trim((string)($s['estado_nombre'] ?? ''))
        ])));
        $responsable = trim((string)($s['responsable_nombre'] ?? ''));

        $etapa = trim((string)($flujo['ventana']['actual']['titulo'] ?? $flujo['titulo'] ?? ''));
        if ($etapa === '') {
            $etapa = trim((string)($s['estado_label'] ?? 'Sin etapa disponible'));
        }

        $accion = trim((string)($flujo['accion_principal']['etiqueta'] ?? ''));
        if ($accion === '') {
            $accion = trim((string)($s['proxima_accion_label'] ?? '—'));
        }

        $pasoActual = (int)($flujo['paso_actual'] ?? 0);
        $totalPasos = (int)($flujo['total_pasos'] ?? 0);
        $paso = $pasoActual > 0 && $totalPasos > 0
            ? 'Paso ' . $pasoActual . ' de ' . $totalPasos
            : 'Ruta operativa';

        $ultima = trim((string)($s['ultima_interaccion_at'] ?? '')) !== ''
            ? (string)($s['ultima_actividad_label'] ?? '—')
            : 'Sin actividad registrada';
        $dias = $s['dias_sin_actividad'] ?? null;
        $diasLabel = $dias === null
            ? 'Sin actividad registrada'
            : ((int)$dias . ((int)$dias === 1 ? ' día' : ' días'));

        $html = '<section class="report-section institution keep">';
        $html .= '<table class="institution-head"><tr><td><span>Institución seleccionada</span>';
        $html .= '<h2>' . $this->e($nombre) . '</h2><p>' .
            $this->e($ubicacion !== '' ? $ubicacion : 'Ubicación no disponible') . '</p></td>';
        $html .= '<td class="step">' . $this->e($paso) . '</td></tr></table>';
        $html .= '<table class="institution-grid"><tr>';
        $html .= $this->info('Responsable', $responsable !== '' ? $responsable : '—', false);
        $html .= $this->info('Etapa de vinculación', $etapa, true);
        $html .= $this->info('Acción actual', $accion, true);
        $html .= '</tr><tr>';
        $html .= $this->info('Última actividad', $ultima, false);
        $html .= $this->info('Días sin actividad', $diasLabel, false);
        $html .= $this->info('Último canal', (string)($s['canal_label'] ?? '—'), false);
        $html .= '</tr></table></section>';
        return $html;
    }

    private function atencion(array $casos): string
    {
        $html = '<section class="report-section keep">' . $this->titulo('Atención requerida');

        if (empty($casos)) {
            $html .= '<div class="ok"><strong>Sin pendientes operativos</strong>';
            $html .= '<span>No hay acciones o reuniones que requieran atención inmediata.</span></div>';
            return $html . '</section>';
        }

        $html .= '<table class="data-table"><thead><tr><th>Institución</th><th>Motivo</th><th class="right">Referencia</th></tr></thead><tbody>';
        foreach (array_slice($casos, 0, 8) as $caso) {
            $ubicacion = implode(', ', array_values(array_filter([
                trim((string)($caso['municipio'] ?? '')),
                trim((string)($caso['estado_nombre'] ?? ''))
            ])));
            $html .= '<tr><td><strong>' . $this->e((string)($caso['nombre_entidad'] ?? 'Institución')) .
                '</strong><small>' . $this->e($ubicacion) . '</small></td>';
            $html .= '<td>' . $this->e((string)($caso['motivo'] ?? 'Requiere atención')) . '</td>';
            $html .= '<td class="right">' . $this->e($this->fecha((string)($caso['fecha_referencia'] ?? ''))) . '</td></tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function contacto(array $analitica): string
    {
        $canales = is_array($analitica['canales'] ?? null) ? $analitica['canales'] : [];
        $llamadas = is_array($analitica['llamadas'] ?? null) ? $analitica['llamadas'] : [];

        $html = '<section class="report-section keep">' . $this->titulo('Actividad y contacto');
        $html .= '<table class="mini"><tr>';
        $html .= $this->mini('Llamadas', (int)($canales['llamadas'] ?? 0));
        $html .= $this->mini('Correos', (int)($canales['correos'] ?? 0));
        $html .= $this->mini('WhatsApp', (int)($canales['whatsapp'] ?? 0));
        $html .= $this->mini('Otros', (int)($canales['otros'] ?? 0));
        $html .= '</tr></table>';

        if ((int)($llamadas['total'] ?? 0) > 0) {
            $html .= '<table class="call-summary"><tr>';
            $html .= $this->callMetric('Llamadas realizadas', (string)(int)$llamadas['total']);
            $html .= $this->callMetric('Contactadas', (string)(int)($llamadas['contactadas'] ?? 0));
            $html .= $this->callMetric('Sin respuesta', (string)(int)($llamadas['sin_respuesta'] ?? 0));
            $html .= $this->callMetric('Tasa de contacto', $this->decimal($llamadas['tasa_contacto'] ?? 0, 1) . '%');
            $html .= '</tr></table>';
        }

        return $html . '</section>';
    }

    private function distribuciones(array $resumen, array $etiquetas): string
    {
        $estatus = [];
        foreach (($resumen['por_estatus'] ?? []) as $codigo => $total) {
            $estatus[] = ['label' => (string)($etiquetas[$codigo] ?? $codigo), 'value' => (int)$total];
        }

        $municipios = [];
        foreach (array_slice($resumen['por_municipio'] ?? [], 0, 8, true) as $municipio => $total) {
            $municipios[] = ['label' => (string)$municipio, 'value' => (int)$total];
        }

        $html = '<section class="report-section keep">' . $this->titulo('Distribución del reporte');
        $html .= '<table class="split"><tr><td><h3>Estatus registrado</h3>' .
            $this->bars($estatus, 'No hay información de estatus.') . '</td>';
        $html .= '<td><h3>Distribución territorial</h3>' .
            $this->bars($municipios, 'No hay información territorial.') . '</td></tr></table>';
        return $html . '</section>';
    }

    private function actividad(array $evolucion): string
    {
        $periodos = is_array($evolucion['periodos'] ?? null) ? $evolucion['periodos'] : [];
        $html = '<section class="report-section keep activity-section">' . $this->titulo('Evolución de interacciones');

        if (empty($periodos)) {
            return $html . $this->vacio('No se registraron actividades durante el periodo seleccionado.') . '</section>';
        }

        $html .= '<img class="line-chart" src="' . $this->graficaLineaDataUri($periodos) . '" alt="Evolución de interacciones">';
        return $html . '</section>';
    }

    private function graficaLineaDataUri(array $periodos): string
    {
        $periodos = array_values(array_slice($periodos, -12));
        $width = 960;
        $height = 270;
        $left = 52;
        $right = 24;
        $top = 18;
        $bottom = 44;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $values = array_map(static function ($periodo) {
            return max(0, (int)($periodo['total'] ?? 0));
        }, $periodos);

        $max = max(1, max($values));
        $max = max(4, (int)(ceil($max / 4) * 4));
        $count = count($periodos);
        $stepX = $count > 1 ? $plotW / ($count - 1) : 0;
        $points = [];
        $labels = '';
        $grid = '';

        for ($i = 0; $i <= 4; $i++) {
            $y = $top + ($plotH * $i / 4);
            $value = (int)round($max * (1 - ($i / 4)));
            $grid .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($width - $right) .
                '" y2="' . $y . '" stroke="#E5E9EF" stroke-width="1"/>';
            $grid .= '<text x="' . ($left - 12) . '" y="' . ($y + 4) .
                '" text-anchor="end" font-size="11" fill="#6D7480">' . $value . '</text>';
        }

        foreach ($periodos as $i => $periodo) {
            $x = $count > 1 ? $left + ($stepX * $i) : $left + ($plotW / 2);
            $total = max(0, (int)($periodo['total'] ?? 0));
            $y = $top + $plotH - (($total / $max) * $plotH);
            $points[] = [$x, $y, $total];
            $labels .= '<text x="' . $x . '" y="' . ($height - 18) .
                '" text-anchor="middle" font-size="10.5" fill="#6D7480">' .
                $this->eSvg((string)($periodo['etiqueta'] ?? '')) . '</text>';
        }

        $polyline = implode(' ', array_map(static function ($point) {
            return number_format($point[0], 1, '.', '') . ',' . number_format($point[1], 1, '.', '');
        }, $points));

        $dots = '';
        foreach ($points as $point) {
            $dots .= '<circle cx="' . $point[0] . '" cy="' . $point[1] .
                '" r="4.5" fill="#273A8A" stroke="#FFFFFF" stroke-width="2"/>';
            $dots .= '<text x="' . $point[0] . '" y="' . max(12, $point[1] - 11) .
                '" text-anchor="middle" font-size="11" font-weight="700" fill="#16223B">' .
                $point[2] . '</text>';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height .
            '" viewBox="0 0 ' . $width . ' ' . $height . '">' .
            '<rect width="100%" height="100%" fill="#FFFFFF"/>' . $grid .
            '<line x1="' . $left . '" y1="' . ($top + $plotH) . '" x2="' . ($width - $right) .
            '" y2="' . ($top + $plotH) . '" stroke="#C9D0DB" stroke-width="1"/>' .
            '<polyline points="' . $polyline .
            '" fill="none" stroke="#273A8A" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>' .
            $dots . $labels . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function eSvg($valor): string
    {
        return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function detalle(array $seguimientos): string
    {
        $html = '<section class="report-section detail-section">' . $this->titulo('Detalle de seguimientos');
        $html .= '<table class="data-table detail"><thead><tr>';
        $html .= '<th>Institución</th><th>Municipio</th><th>Responsable</th><th>Estatus</th>';
        $html .= '<th>Última actividad</th><th class="center">Días</th><th>Próxima acción</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($seguimientos as $s) {
            $ultima = trim((string)($s['ultima_interaccion_at'] ?? '')) !== ''
                ? (string)($s['ultima_actividad_label'] ?? '—')
                : 'Sin actividad registrada';
            $dias = $s['dias_sin_actividad'] ?? null;
            $html .= '<tr><td><strong>' . $this->e((string)($s['nombre_entidad'] ?? '—')) . '</strong></td>';
            $html .= '<td>' . $this->e((string)($s['municipio'] ?? '—')) . '</td>';
            $html .= '<td>' . $this->e((string)($s['responsable_nombre'] ?? '—')) . '</td>';
            $html .= '<td><span class="status">' . $this->e((string)($s['estado_label'] ?? 'Sin estado')) . '</span></td>';
            $html .= '<td>' . $this->e($ultima) . '</td>';
            $html .= '<td class="center">' . ($dias === null ? '—' : (int)$dias) . '</td>';
            $html .= '<td>' . $this->e((string)($s['proxima_accion_label'] ?? '—')) . '</td></tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function lecturaIndividual(array $s, array $flujo): string
    {
        $etapa = trim((string)($flujo['ventana']['actual']['titulo'] ?? $flujo['titulo'] ?? $s['estado_label'] ?? '—'));
        $accion = trim((string)($flujo['accion_principal']['etiqueta'] ?? $s['proxima_accion_label'] ?? '—'));

        $html = '<section class="report-section keep">' . $this->titulo('Lectura operativa');
        $html .= '<table class="operational"><tr>';
        $html .= $this->opMetric('Etapa actual', $etapa);
        $html .= $this->opMetric('Acción actual', $accion);
        $html .= $this->opMetric('Último canal', (string)($s['canal_label'] ?? '—'));
        return $html . '</tr></table></section>';
    }

    private function bars(array $datos, string $mensaje): string
    {
        if (empty($datos)) {
            return '<div class="empty compact">' . $this->e($mensaje) . '</div>';
        }
        $max = max(1, max(array_map(static function ($d) { return max(0, (int)($d['value'] ?? 0)); }, $datos)));
        $html = '';
        foreach ($datos as $d) {
            $v = max(0, (int)($d['value'] ?? 0));
            $pct = min(100, max(2, ($v / $max) * 100));
            $html .= '<table class="barrow"><tr><td class="barlabel">' . $this->e((string)($d['label'] ?? '—')) . '</td><td class="bararea"><div class="track"><div class="fill" style="width:' . number_format($pct, 2, '.', '') . '%"></div></div></td><td class="barvalue">' . $v . '</td></tr></table>';
        }
        return $html;
    }

    private function titulo(string $titulo): string
    {
        return '<div class="section-title"><h2>' . $this->e($titulo) . '</h2></div>';
    }

    private function metric(string $label, string $value): string
    {
        return '<td class="metric"><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong></td>';
    }

    private function mini(string $label, int $value): string
    {
        return '<td><span>' . $this->e($label) . '</span><strong>' . $value . '</strong></td>';
    }

    private function info(string $label, string $value, bool $key): string
    {
        return '<td class="info' . ($key ? ' key' : '') . '"><span>' . $this->e($label) .
            '</span><strong>' . $this->e($value !== '' ? $value : '—') . '</strong></td>';
    }

    private function callMetric(string $label, string $value): string
    {
        return '<td><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong></td>';
    }

    private function opMetric(string $label, string $value): string
    {
        return '<td><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong></td>';
    }

    private function vacio(string $texto): string
    {
        return '<div class="empty">' . $this->e($texto) . '</div>';
    }

    private function logo(): string
    {
        foreach ([
            dirname(__DIR__, 2) . '/public/img/brand/porcayo-grupo.png',
            dirname(__DIR__, 2) . '/public/img/brand/porcayo-grupo8.png'
        ] as $ruta) {
            if (is_file($ruta) && is_readable($ruta)) {
                $contenido = file_get_contents($ruta);
                if (is_string($contenido) && $contenido !== '') {
                    return 'data:image/png;base64,' . base64_encode($contenido);
                }
            }
        }
        return '';
    }

    private function nombreArchivo(array $datos): string
    {
        $f = is_array($datos['resumen_filtros'] ?? null) ? $datos['resumen_filtros'] : [];
        $institucion = trim((string)($f['Institución'] ?? ''));
        $estado = trim((string)($f['Estado'] ?? ''));
        $sufijo = '';
        if ($institucion !== '' && strcasecmp($institucion, 'Todas') !== 0) {
            $sufijo = '_' . $this->seguro($institucion);
        } elseif ($estado !== '' && strcasecmp($estado, 'Todos') !== 0) {
            $sufijo = '_' . $this->seguro($estado);
        }
        return 'Reporte_Seguimiento_Vinculacion' . $sufijo . '_' . date('Y-m-d') . '.pdf';
    }

    private function seguro(string $texto): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        $texto = $ascii !== false ? $ascii : $texto;
        $texto = preg_replace('/[^A-Za-z0-9_-]+/', '_', $texto);
        return trim((string)$texto, '_') ?: 'Reporte';
    }

    private function fecha(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return date('d/m/Y H:i');
        }
        try {
            return (new DateTime($valor))->format('d/m/Y H:i');
        } catch (Throwable $error) {
            return $valor;
        }
    }

    private function decimal($valor, int $decimales): string
    {
        return is_numeric($valor) ? number_format((float)$valor, $decimales, '.', ',') : number_format(0, $decimales, '.', ',');
    }

    private function e($valor): string
    {
        return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function error(string $mensaje, string $tecnico): array
    {
        return ['ok' => false, 'mensaje' => $mensaje, 'mensaje_tecnico' => $tecnico];
    }

    private function css(): string
    {
        return '@import url("https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap");' .
            '@page{margin:18mm 14mm 17mm 14mm}' .
            'body{font-family:"Manrope","DejaVu Sans",sans-serif;color:#252525;font-size:8pt;line-height:1.35;margin:0}' .
            '.top-rule{height:4px;background:#273A8A;margin:-18mm -14mm 14px}' .
            '.header{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:8px}' .
            '.brand{width:180px;vertical-align:middle}.brand img{width:145px;height:auto;max-height:88px}' .
            '.header-copy{vertical-align:middle;text-align:right;padding-left:12px}' .
            '.system-name{font-size:7pt;color:#273A8A;font-weight:800;letter-spacing:.04em;margin-bottom:3px}' .
            '.header h1{font-size:16.5pt;line-height:1.16;color:#16223B;margin:0 0 8px;font-weight:800}' .
            '.header-meta{margin-left:auto;border-collapse:collapse;font-size:6.4pt}' .
            '.header-meta td{color:#6D7480;text-align:right;padding:1px 0 1px 12px}.header-meta th{color:#16223B;text-align:right;padding:1px 0 1px 8px;font-weight:700}' .
            '.header-rule{height:2px;background:#273A8A;margin:0 0 9px}' .
            '.scope{width:100%;table-layout:fixed;border-collapse:collapse;background:#F8FAFC;border:1px solid #E5E9EF;margin-bottom:12px}' .
            '.scope td{padding:7px 9px;vertical-align:middle;border-right:1px solid #E5E9EF}.scope td:last-child{border-right:0}' .
            '.scope span,.info span,.metric span,.mini span,.call-summary span,.operational span{display:block;color:#6D7480;font-size:6pt;margin-bottom:2px}' .
            '.scope strong{font-size:7.1pt;color:#16223B}.scope-total{width:75px;text-align:center}.scope-total strong{font-size:10.5pt;color:#273A8A}' .
            '.report-section{margin:0 0 13px}.keep{page-break-inside:avoid}' .
            '.section-title{border-left:3px solid #273A8A;padding-left:8px;margin:0 0 7px;page-break-inside:avoid;page-break-after:avoid}' .
            '.section-title h2{font-size:10.7pt;color:#16223B;margin:0;font-weight:800}' .
            '.metrics{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}' .
            '.metric{width:25%;background:#FFFFFF;border:1px solid #E5E9EF;padding:9px;vertical-align:middle}.metric strong{display:block;color:#16223B;font-size:13pt;font-weight:800;margin-top:2px}' .
            '.institution{border:1px solid #E5E9EF;padding:9px 10px;background:#FFFFFF}' .
            '.institution-head{width:100%;border-collapse:collapse}.institution-head td{vertical-align:middle}.institution-head span{color:#0A8F7A;font-size:6.2pt;font-weight:800;letter-spacing:.04em}' .
            '.institution-head h2{font-size:12.6pt;margin:2px 0;color:#16223B}.institution-head p{margin:0;color:#6D7480;font-size:6.8pt}.step{text-align:right;font-size:6.4pt!important;color:#273A8A!important;font-weight:800;width:90px}' .
            '.institution-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px;margin-top:7px}.info{width:33.33%;border:1px solid #E5E9EF;padding:7px 8px;vertical-align:middle}.info.key{background:#EDF2FA}.info strong{display:block;font-size:7.3pt;color:#16223B}.info.key strong{color:#273A8A}' .
            '.data-table{width:100%;border-collapse:collapse;font-size:6.25pt;page-break-inside:auto}.data-table thead{display:table-header-group}.data-table tr{page-break-inside:avoid;page-break-after:auto}' .
            '.data-table th{background:#273A8A;color:#FFFFFF;text-align:left;padding:6px 7px;font-weight:700}.data-table td{padding:6px 7px;border-bottom:1px solid #E5E9EF;vertical-align:top}.data-table tbody tr:nth-child(even){background:#F8FAFC}.data-table small{display:block;color:#6D7480;font-size:5.5pt;margin-top:2px}' .
            '.right{text-align:right!important}.center{text-align:center!important}.ok{border-left:3px solid #0A8F7A;background:#F8FAFC;padding:8px 10px}.ok strong{display:block;font-size:7pt}.ok span{display:block;color:#6D7480;font-size:6pt;margin-top:2px}' .
            '.mini{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.mini td{width:25%;border:1px solid #E5E9EF;background:#F8FAFC;padding:7px 8px}.mini strong{display:block;font-size:10.5pt;color:#16223B}' .
            '.call-summary{width:100%;table-layout:fixed;border-collapse:collapse;margin-top:5px;border-top:1px solid #E5E9EF;border-bottom:1px solid #E5E9EF}.call-summary td{width:25%;padding:6px 8px;border-right:1px solid #E5E9EF}.call-summary td:last-child{border-right:0}.call-summary strong{display:block;font-size:7.2pt}' .
            '.activity-section{page-break-inside:avoid}.line-chart{display:block;width:100%;height:auto;border:1px solid #E5E9EF;background:#FFFFFF;padding:4px}' .
            '.split{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:9px 0}.split td{width:50%;vertical-align:top}.split h3{font-size:7.8pt;margin:0 0 6px;color:#16223B}' .
            '.barrow{width:100%;table-layout:fixed;border-collapse:collapse;margin-bottom:5px}.barlabel{width:37%;font-size:6pt;padding-right:6px}.bararea{width:53%}.barvalue{width:10%;text-align:right;font-weight:700;font-size:6pt}.track{height:6px;background:#E9EDF4;overflow:hidden}.fill{height:6px;background:#273A8A}' .
            '.status{display:inline-block;background:#EDF2FA;color:#273A8A;padding:2px 5px;font-weight:700;font-size:5.5pt}.detail-section .section-title{page-break-after:avoid}' .
            '.operational{width:100%;table-layout:fixed;border-collapse:collapse;background:#F8FAFC;border:1px solid #E5E9EF}.operational td{width:33.33%;padding:8px;border-right:1px solid #E5E9EF}.operational td:last-child{border-right:0}.operational strong{display:block;font-size:7.2pt}' .
            '.empty{border-left:3px solid #E5E9EF;background:#F8FAFC;padding:8px 10px;color:#6D7480;font-size:6.4pt}.empty.compact{padding:6px 8px}';
    }

}
