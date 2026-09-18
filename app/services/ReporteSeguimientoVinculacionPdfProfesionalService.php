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
            $options->set('isRemoteEnabled', false);
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
        $individual = (int)($filtrosRaw['institucion_id'] ?? 0) > 0 && count($seguimientos) === 1;

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>' . $this->css() . '</style></head><body>';
        $html .= '<div class="top-rule"></div><table class="header"><tr><td class="brand">';
        $logo = $this->logo();
        if ($logo !== '') {
            $html .= '<img src="' . $logo . '" alt="Grupo Porcayo">';
        }
        $html .= '</td><td class="headcopy"><div class="eyebrow">SISTEMA DE GESTIÓN COMERCIAL</div>';
        $html .= '<h1>Reporte de Seguimiento de Vinculación</h1><p>Seguimiento institucional y actividad comercial</p></td>';
        $html .= '<td class="meta"><span>Generado</span><strong>' . $this->e($fecha) . '</strong>';
        $html .= '<span>Periodo</span><strong>' . $this->e((string)($filtros['Periodo'] ?? 'Todos')) . '</strong></td></tr></table>';

        $html .= $this->contexto($filtros, $individual);

        if ($individual) {
            $html .= $this->ficha($seguimientos[0], $flujo);
        }

        $total = (int)($resumen['total'] ?? count($seguimientos));
        $interacciones = (int)($analitica['interacciones'] ?? 0);
        $promedio = $this->decimal($analitica['promedio_por_seguimiento'] ?? 0, 1);
        $atencion = (int)($analitica['atencion']['total'] ?? 0);
        $atencionPct = $this->decimal($analitica['atencion']['porcentaje'] ?? 0, 1);

        $html .= $this->titulo('RESUMEN EJECUTIVO', 'Resumen operativo');
        $html .= '<table class="metrics"><tr>';
        $html .= $this->metric('Seguimientos incluidos', (string)$total, 'base analizada');
        $html .= $this->metric('Interacciones de contacto', (string)$interacciones, 'sin eventos automáticos');
        $html .= $this->metric('Interacciones por seguimiento', $promedio, 'promedio de contacto');
        $html .= $this->metric('Requieren atención', (string)$atencion, $atencionPct . '% del total');
        $html .= '</tr></table>';

        if ($total <= 0) {
            $html .= $this->vacio('No se encontraron seguimientos con los criterios seleccionados. El documento conserva el contexto utilizado para dejar constancia de la consulta.');
            return $html . '</body></html>';
        }

        $html .= $this->atencion($analitica['atencion']['casos'] ?? []);
        $html .= $this->contacto($analitica);

        if (!$individual) {
            $html .= $this->distribuciones($resumen, $etiquetas);
        }

        $html .= $this->actividad($evolucion);

        if ($individual) {
            $html .= $this->lecturaIndividual($seguimientos[0], $flujo);
        } else {
            $html .= $this->detalle($seguimientos);
        }

        $html .= '<div class="report-end">Documento generado automáticamente por el Sistema de Gestión Comercial de Grupo Porcayo.</div>';
        return $html . '</body></html>';
    }

    private function contexto(array $filtros, bool $individual): string
    {
        $html = '<div class="context">';
        $mostrados = 0;
        foreach ($filtros as $nombre => $valor) {
            $valor = trim((string)$valor);
            if ($valor === '' || in_array($valor, ['Todos', 'Todas'], true) || ($individual && $nombre === 'Institución')) {
                continue;
            }
            $html .= '<span><small>' . $this->e((string)$nombre) . '</small><strong>' . $this->e($valor) . '</strong></span>';
            $mostrados++;
        }
        if ($mostrados === 0) {
            $html .= '<span><small>Alcance</small><strong>Todos los seguimientos autorizados</strong></span>';
        }
        return $html . '</div>';
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
        $paso = $pasoActual > 0 && $totalPasos > 0 ? 'Paso ' . $pasoActual . ' de ' . $totalPasos : 'Ruta operativa';
        $ultima = trim((string)($s['ultima_interaccion_at'] ?? '')) !== '' ? (string)($s['ultima_actividad_label'] ?? '—') : 'Sin actividad registrada';
        $dias = $s['dias_sin_actividad'] ?? null;
        $diasLabel = $dias === null ? 'Sin actividad registrada' : ((int)$dias . ((int)$dias === 1 ? ' día' : ' días'));

        $html = '<div class="institution"><table class="institution-head"><tr><td><div class="eyebrow accent">INSTITUCIÓN SELECCIONADA</div>';
        $html .= '<h2>' . $this->e($nombre) . '</h2><p>' . $this->e($ubicacion !== '' ? $ubicacion : 'Ubicación no disponible') . ' · ' . $this->e($responsable !== '' ? $responsable : 'Sin responsable') . '</p></td>';
        $html .= '<td class="step">' . $this->e($paso) . '</td></tr></table>';
        $html .= '<table class="institution-grid"><tr>';
        $html .= $this->info('Etapa de vinculación', $etapa, true);
        $html .= $this->info('Acción actual', $accion, true);
        $html .= $this->info('Última actividad', $ultima, false);
        $html .= '</tr><tr>';
        $html .= $this->info('Días sin actividad', $diasLabel, false);
        $html .= $this->info('Responsable', $responsable !== '' ? $responsable : '—', false);
        $html .= $this->info('Ubicación', $ubicacion !== '' ? $ubicacion : '—', false);
        return $html . '</tr></table></div>';
    }

    private function atencion(array $casos): string
    {
        $html = $this->titulo('ATENCIÓN REQUERIDA', 'Seguimientos que requieren una acción');
        if (empty($casos)) {
            return $html . '<div class="ok"><strong>Sin pendientes operativos</strong><span>No hay acciones vencidas, confirmaciones próximas ni esperas que superen los umbrales de atención.</span></div>';
        }

        $html .= '<table class="table"><thead><tr><th>Institución</th><th>Motivo de atención</th><th class="right">Referencia</th></tr></thead><tbody>';
        foreach (array_slice($casos, 0, 8) as $caso) {
            $ubicacion = implode(', ', array_values(array_filter([
                trim((string)($caso['municipio'] ?? '')),
                trim((string)($caso['estado_nombre'] ?? ''))
            ])));
            $html .= '<tr><td><strong>' . $this->e((string)($caso['nombre_entidad'] ?? 'Institución')) . '</strong><small>' . $this->e($ubicacion) . '</small></td>';
            $html .= '<td>' . $this->e((string)($caso['motivo'] ?? 'Requiere atención')) . '</td>';
            $html .= '<td class="right">' . $this->e($this->fecha((string)($caso['fecha_referencia'] ?? ''))) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function contacto(array $analitica): string
    {
        $canales = is_array($analitica['canales'] ?? null) ? $analitica['canales'] : [];
        $llamadas = is_array($analitica['llamadas'] ?? null) ? $analitica['llamadas'] : [];
        $html = $this->titulo('ACTIVIDAD Y CONTACTO', 'Interacciones registradas');
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
        return $html;
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

        $html = $this->titulo('DISTRIBUCIÓN DEL REPORTE', 'Estatus y concentración territorial');
        $html .= '<table class="split"><tr><td><h3>Estatus registrado</h3>' . $this->bars($estatus, 'No hay información de estatus para los criterios seleccionados.') . '</td>';
        $html .= '<td><h3>Distribución territorial</h3>' . $this->bars($municipios, 'No hay información municipal para los criterios seleccionados.') . '</td></tr></table>';
        return $html;
    }

    private function actividad(array $evolucion): string
    {
        $periodos = is_array($evolucion['periodos'] ?? null) ? $evolucion['periodos'] : [];
        $html = $this->titulo('ACTIVIDAD RECIENTE', 'Evolución de interacciones');
        if (empty($periodos)) {
            return $html . $this->vacio('No se registraron actividades durante el periodo seleccionado.');
        }

        $max = 1;
        foreach ($periodos as $p) {
            $max = max($max, (int)($p['total'] ?? 0));
        }

        $html .= '<table class="timeline"><thead><tr><th>Periodo</th><th>Actividad</th><th class="right">Total</th></tr></thead><tbody>';
        foreach (array_slice($periodos, -12) as $p) {
            $valor = max(0, (int)($p['total'] ?? 0));
            $pct = min(100, max(2, ($valor / $max) * 100));
            $html .= '<tr><td>' . $this->e((string)($p['etiqueta'] ?? '—')) . '</td><td><div class="track"><div class="fill" style="width:' . number_format($pct, 2, '.', '') . '%"></div></div></td><td class="right"><strong>' . $valor . '</strong></td></tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    private function detalle(array $seguimientos): string
    {
        $html = $this->titulo('DETALLE OPERATIVO', 'Seguimientos incluidos');
        $html .= '<table class="table detail"><thead><tr><th>Institución</th><th>Municipio</th><th>Responsable</th><th>Estatus</th><th>Última actividad</th><th class="center">Días</th><th>Próxima acción</th></tr></thead><tbody>';
        foreach ($seguimientos as $s) {
            $ultima = trim((string)($s['ultima_interaccion_at'] ?? '')) !== '' ? (string)($s['ultima_actividad_label'] ?? '—') : 'Sin actividad registrada';
            $dias = $s['dias_sin_actividad'] ?? null;
            $html .= '<tr><td><strong>' . $this->e((string)($s['nombre_entidad'] ?? '—')) . '</strong></td>';
            $html .= '<td>' . $this->e((string)($s['municipio'] ?? '—')) . '</td><td>' . $this->e((string)($s['responsable_nombre'] ?? '—')) . '</td>';
            $html .= '<td><span class="status">' . $this->e((string)($s['estado_label'] ?? 'Sin estado')) . '</span></td>';
            $html .= '<td>' . $this->e($ultima) . '</td><td class="center">' . ($dias === null ? '—' : (int)$dias) . '</td>';
            $html .= '<td>' . $this->e((string)($s['proxima_accion_label'] ?? '—')) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function lecturaIndividual(array $s, array $flujo): string
    {
        $etapa = trim((string)($flujo['ventana']['actual']['titulo'] ?? $flujo['titulo'] ?? $s['estado_label'] ?? '—'));
        $accion = trim((string)($flujo['accion_principal']['etiqueta'] ?? $s['proxima_accion_label'] ?? '—'));
        $html = $this->titulo('LECTURA OPERATIVA', 'Estado actual del seguimiento');
        return $html . '<div class="operational"><table><tr>' .
            $this->opMetric('Etapa actual', $etapa) .
            $this->opMetric('Acción actual', $accion) .
            $this->opMetric('Último canal', (string)($s['canal_label'] ?? '—')) .
            '</tr></table></div>';
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

    private function titulo(string $eyebrow, string $titulo): string
    {
        return '<div class="section-title"><span>' . $this->e($eyebrow) . '</span><h2>' . $this->e($titulo) . '</h2></div>';
    }

    private function metric(string $label, string $value, string $meta): string
    {
        return '<td class="metric"><small>' . $this->e($label) . '</small><strong>' . $this->e($value) . '</strong><em>' . $this->e($meta) . '</em></td>';
    }

    private function mini(string $label, int $value): string
    {
        return '<td><small>' . $this->e($label) . '</small><strong>' . $value . '</strong></td>';
    }

    private function info(string $label, string $value, bool $key): string
    {
        return '<td class="info' . ($key ? ' key' : '') . '"><small>' . $this->e($label) . '</small><strong>' . $this->e($value !== '' ? $value : '—') . '</strong></td>';
    }

    private function callMetric(string $label, string $value): string
    {
        return '<td><small>' . $this->e($label) . '</small><strong>' . $this->e($value) . '</strong></td>';
    }

    private function opMetric(string $label, string $value): string
    {
        return '<td><small>' . $this->e($label) . '</small><strong>' . $this->e($value) . '</strong></td>';
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
        return '@page{margin:18mm 14mm 17mm 14mm}body{font-family:"DejaVu Sans",sans-serif;color:#16223B;font-size:8pt;line-height:1.38;margin:0}' .
            '.top-rule{height:4px;background:#273A8A;margin:-18mm -14mm 14px}.header{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:10px}.brand{width:105px;vertical-align:middle}.brand img{height:55px;width:auto;max-width:98px}.headcopy{vertical-align:middle;padding:0 10px}.eyebrow,.section-title span{color:#273A8A;font-size:6.3pt;font-weight:700;letter-spacing:.08em}.eyebrow.accent{color:#0A9A87}.headcopy h1{font-size:17pt;margin:3px 0 2px}.headcopy p{margin:0;color:#6D7480;font-size:7.2pt}.meta{width:145px;text-align:right;vertical-align:middle}.meta span{display:block;color:#6D7480;font-size:5.9pt;margin-top:3px}.meta strong{display:block;font-size:6.8pt}' .
            '.context{border-top:1px solid #E5E9EF;border-bottom:1px solid #E5E9EF;background:#F8FAFC;padding:7px 9px;margin-bottom:12px}.context span{display:inline-block;margin-right:15px}.context small{display:block;color:#6D7480;font-size:5.7pt}.context strong{display:block;font-size:6.8pt}' .
            '.institution{border:1px solid #E5E9EF;padding:10px 11px;margin-bottom:12px;page-break-inside:avoid}.institution-head{width:100%;border-collapse:collapse}.institution-head h2{font-size:13pt;margin:2px 0}.institution-head p{margin:0;color:#6D7480;font-size:7pt}.step{text-align:right;vertical-align:middle;color:#273A8A;font-size:6.4pt;font-weight:700}.institution-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px;margin-top:6px}.info{width:33.33%;border:1px solid #E5E9EF;padding:8px;vertical-align:middle}.info.key{background:#EDF2FA}.info small{display:block;color:#6D7480;font-size:5.9pt;margin-bottom:3px}.info strong{font-size:7.5pt}.info.key strong{color:#273A8A}' .
            '.section-title{border-bottom:1px solid #E5E9EF;padding-bottom:5px;margin:14px 0 8px;page-break-after:avoid}.section-title h2{font-size:10.5pt;margin:2px 0 0}.metrics{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:3px 0}.metric{width:25%;border:1px solid #E5E9EF;padding:9px 8px;vertical-align:top}.metric small{display:block;color:#6D7480;font-size:6pt}.metric strong{display:block;font-size:13pt;margin:3px 0}.metric em{display:block;color:#8B94A2;font-size:5.7pt;font-style:normal}' .
            '.mini{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:3px 0}.mini td{width:25%;border:1px solid #E5E9EF;background:#F8FAFC;padding:8px}.mini small,.call-summary small,.operational small{display:block;color:#6D7480;font-size:5.9pt}.mini strong{display:block;font-size:10.5pt;margin-top:2px}.call-summary{width:100%;table-layout:fixed;border-collapse:collapse;margin-top:6px;border-top:1px solid #E5E9EF;border-bottom:1px solid #E5E9EF}.call-summary td{width:25%;padding:6px 8px;border-right:1px solid #E5E9EF}.call-summary td:last-child{border-right:0}.call-summary strong,.operational strong{display:block;font-size:7.4pt;margin-top:2px}' .
            '.ok{border-left:3px solid #0A9A87;background:#F8FAFC;padding:8px 10px}.ok strong{display:block;font-size:7.2pt}.ok span{display:block;color:#6D7480;font-size:6.2pt;margin-top:2px}.table,.timeline{width:100%;border-collapse:collapse;font-size:6.4pt}.table th,.timeline th{background:#273A8A;color:#fff;text-align:left;padding:6px 7px}.table td,.timeline td{padding:6px 7px;border-bottom:1px solid #E5E9EF;vertical-align:top}.table tbody tr:nth-child(even),.timeline tbody tr:nth-child(even){background:#F8FAFC}.table small{display:block;color:#6D7480;font-size:5.6pt;margin-top:2px}.right{text-align:right!important}.center{text-align:center!important}' .
            '.split{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:7px 0}.split td{width:50%;vertical-align:top}.split h3{font-size:8pt;margin:0 0 6px}.barrow{width:100%;table-layout:fixed;border-collapse:collapse;margin-bottom:5px}.barlabel{width:36%;font-size:6pt;padding-right:5px}.bararea{width:54%}.barvalue{width:10%;text-align:right;font-weight:700;font-size:6pt}.track{height:6px;background:#E9EDF4;overflow:hidden}.fill{height:6px;background:#273A8A}.timeline td:first-child{width:23%}.timeline td:nth-child(2){width:65%}.timeline td:last-child{width:12%}' .
            '.empty{border-left:3px solid #E5E9EF;background:#F8FAFC;padding:9px 10px;color:#6D7480;font-size:6.5pt}.empty.compact{padding:6px 8px}.status{display:inline-block;background:#EDF2FA;color:#273A8A;padding:2px 5px;font-weight:700;font-size:5.6pt}.operational{background:#F8FAFC;border:1px solid #E5E9EF;padding:7px}.operational table{width:100%;table-layout:fixed;border-collapse:collapse}.operational td{width:33.33%;padding:4px 8px;border-right:1px solid #E5E9EF}.operational td:last-child{border-right:0}.report-end{text-align:center;color:#6D7480;font-size:5.8pt;margin-top:14px}' .
            'table{page-break-inside:auto}tr{page-break-inside:avoid;page-break-after:auto}thead{display:table-header-group}';
    }
}
