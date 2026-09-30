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
        $detalleInstitucion = is_array($datos['detalle_institucion'] ?? null) ? $datos['detalle_institucion'] : [];
        if (is_array($detalleInstitucion['flujo'] ?? null) && !empty($detalleInstitucion['flujo'])) {
            $flujo = $detalleInstitucion['flujo'];
        }
        $etiquetas = is_array($datos['etiquetas_estatus'] ?? null) ? $datos['etiquetas_estatus'] : [];
        $fecha = $this->fecha((string)($datos['fecha_generacion'] ?? ''));
        $generadoPor = trim((string)($datos['generado_por'] ?? ''));
        $generadoPorRol = trim((string)($datos['generado_por_rol'] ?? ''));
        $individual = (int)($filtrosRaw['institucion_id'] ?? 0) > 0 && count($seguimientos) === 1;
        $responsable = $this->responsableAlcance($seguimientos, $filtros);
        $tipoReporte = strtolower(trim((string)($filtrosRaw['tipo_reporte'] ?? 'cartera')));
        $tituloReporte = [
            'actividad' => 'Mi actividad de seguimiento',
            'cartera' => 'Mi cartera de seguimiento',
            'institucion' => 'Reporte de institución'
        ][$tipoReporte] ?? 'Reporte de Seguimiento de Vinculación';

        $periodoEncabezado = $individual
            ? 'Histórico disponible'
            : (string)($filtros['Periodo'] ?? 'Todos');

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>' . $this->css() . '</style></head><body>';
        $html .= '<div class="top-rule"></div>';
        $html .= $this->encabezado($fecha, $generadoPor, $generadoPorRol, $periodoEncabezado, $tituloReporte);
        $html .= $tipoReporte === 'actividad'
            ? $this->contextoActividad($filtros, $responsable, $analitica)
            : $this->contexto($filtros, $responsable, count($seguimientos), $individual);

        $total = (int)($resumen['total'] ?? count($seguimientos));
        if ($total <= 0) {
            $html .= '<section class="report-section keep">' . $this->titulo('Resultado de la consulta');
            $html .= $this->vacio('No se encontraron seguimientos con los criterios seleccionados.');
            return $html . '</section></body></html>';
        }

        if ($individual) {
            $html .= '<div class="individual-page individual-page-one">';
            $html .= $this->ficha($seguimientos[0], $flujo, $detalleInstitucion);
            $html .= $this->resumenIndividual($analitica, $detalleInstitucion);
            $html .= $this->contactoInstitucional($detalleInstitucion);
            $html .= $this->rutaIndividual($flujo, $detalleInstitucion);
            $html .= '</div>';

            $html .= '<div class="individual-page individual-page-two">';
            $html .= $this->contactoIndividual($analitica, $detalleInstitucion);
            $html .= $this->actividadDiariaIndividual($detalleInstitucion);
            $html .= $this->reunionesIndividual($detalleInstitucion);
            $html .= $this->documentacionIndividual($detalleInstitucion);
            $html .= $this->historialIndividual($detalleInstitucion);
            $html .= $this->observacionesIndividual($detalleInstitucion);
            $html .= '</div>';
            return $html . '</body></html>';
        }

        if ($tipoReporte === 'actividad') {
            $html .= $this->resumenEjecutivoActividad($analitica);
            $html .= $this->rendimientoTelefonicoActividad($analitica);
            $html .= $this->composicionActividad($analitica);

            $html .= '<div class="activity-pdf-page-break"></div>';
            $html .= $this->evolucionActividadEjecutiva($evolucion);
            $html .= $this->coberturaActividad($analitica);

            $actividadReciente = is_array($analitica['actividad_reciente'] ?? null)
                ? $analitica['actividad_reciente']
                : [];
            if (!empty($actividadReciente)) {
                $html .= '<div class="activity-pdf-page-break"></div>';
                $html .= $this->detalleActividad($actividadReciente);
            }

            return $html . '</body></html>';
        }

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

        $html .= $this->atencion($analitica['atencion']['casos'] ?? []);
        $html .= $this->contacto($analitica, false);
        $html .= $this->distribuciones($resumen, $etiquetas);
        $html .= $this->detalle($seguimientos);

        return $html . '</body></html>';
    }

    private function encabezado(
        string $fecha,
        string $generadoPor,
        string $rol,
        string $periodo,
        string $tituloReporte
    ): string
    {
        $logo = $this->logo();
        $html = '<table class="header"><tr><td class="brand">';
        if ($logo !== '') {
            $html .= '<img src="' . $logo . '" alt="Grupo Porcayo">';
        }
        $html .= '</td><td class="header-copy">';
        $html .= '<div class="system-name">Sistema de Gestión Comercial</div>';
        $html .= '<h1>' . $this->e($tituloReporte) . '</h1>';
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

        $alcance = !empty($ubicacion)
            ? implode(' · ', $ubicacion)
            : ($individual ? 'Institución seleccionada' : 'Todos los territorios autorizados');
        $html = '<table class="scope"><tr>';
        $html .= '<td><span>Alcance</span><strong>' . $this->e($alcance) . '</strong></td>';
        $html .= '<td><span>Responsable del alcance</span><strong>' . $this->e($responsable !== '' ? $responsable : 'Varios responsables') . '</strong></td>';
        if ($individual) {
            $html .= '<td class="scope-total"><span>Tipo de reporte</span><strong>Institución individual</strong></td>';
        } else {
            $html .= '<td class="scope-total"><span>Seguimientos</span><strong>' . $total . '</strong></td>';
        }
        $html .= '</tr></table>';
        return $html;
    }

    private function contextoActividad(array $filtros, string $responsable, array $analitica): string
    {
        $ubicacion = [];
        foreach (['Estado', 'Municipio'] as $campo) {
            $valor = trim((string)($filtros[$campo] ?? ''));
            if ($valor !== '' && !in_array($valor, ['Todos', 'Todas'], true)) {
                $ubicacion[] = $valor;
            }
        }

        $alcance = !empty($ubicacion)
            ? implode(' · ', $ubicacion)
            : 'Todos los territorios autorizados';
        $tipoInteraccion = trim((string)($filtros['Tipo de interacción'] ?? 'Todos'));
        if ($tipoInteraccion === '') {
            $tipoInteraccion = 'Todos';
        }
        $instituciones = max(0, (int)($analitica['seguimientos_con_actividad'] ?? 0));

        $html = '<table class="scope activity-scope"><tr>';
        $html .= '<td><span>Territorio</span><strong>' . $this->e($alcance) . '</strong></td>';
        $html .= '<td><span>Analista</span><strong>' .
            $this->e($responsable !== '' ? $responsable : 'Responsable del reporte') . '</strong></td>';
        $html .= '<td><span>Instituciones trabajadas</span><strong>' . $instituciones . '</strong></td>';
        $html .= '<td><span>Tipo de interacción</span><strong>' . $this->e($tipoInteraccion) . '</strong></td>';
        return $html . '</tr></table>';
    }

    private function resumenEjecutivoActividad(array $analitica): string
    {
        $llamadas = is_array($analitica['llamadas'] ?? null) ? $analitica['llamadas'] : [];
        $interacciones = max(0, (int)($analitica['interacciones'] ?? 0));
        $instituciones = max(0, (int)($analitica['seguimientos_con_actividad'] ?? 0));

        $html = '<section class="report-section keep activity-executive">' .
            $this->titulo('Resumen ejecutivo');
        $html .= '<table class="executive-metrics"><tr>';
        $html .= $this->opMetric('Actividades realizadas', (string)$interacciones);
        $html .= $this->opMetric('Instituciones trabajadas', (string)$instituciones);
        $html .= $this->opMetric('Llamadas realizadas', (string)(int)($llamadas['total'] ?? 0));
        $html .= $this->opMetric(
            'Con contacto',
            (string)(int)($llamadas['contactadas'] ?? 0) .
            ' · ' . $this->decimal($llamadas['tasa_contacto'] ?? 0, 1) . '%'
        );
        $html .= $this->opMetric(
            'Llamadas efectivas',
            (string)(int)($llamadas['verificaciones_efectivas'] ?? 0)
        );
        $html .= '</tr></table>';
        $html .= '<div class="decision-note"><strong>Criterio de efectividad:</strong> ' .
            'se contabiliza una efectiva por institución y día cuando existe evidencia telefónica válida vinculada.</div>';
        return $html . '</section>';
    }

    private function rendimientoTelefonicoActividad(array $analitica): string
    {
        $resumen = is_array($analitica['rendimiento_telefonico'] ?? null)
            ? $analitica['rendimiento_telefonico']
            : [];
        $periodos = is_array($resumen['periodos'] ?? null) ? $resumen['periodos'] : [];
        $granularidad = strtolower(trim((string)($resumen['granularidad'] ?? 'dia')));
        $granularidadLabel = [
            'dia' => 'día',
            'semana' => 'semana',
            'mes' => 'mes'
        ][$granularidad] ?? 'periodo';
        $metaDiaria = max(1, (int)($analitica['meta_diaria_efectivas'] ?? 25));

        $html = '<section class="report-section keep activity-phone-section">' .
            $this->titulo('Rendimiento telefónico por ' . $granularidadLabel);

        if (empty($periodos)) {
            return $html . $this->vacio('No se registraron llamadas dentro del periodo seleccionado.') . '</section>';
        }

        if ($granularidad === 'dia') {
            $html .= '<div class="flow-note">La referencia operativa es de ' . $metaDiaria .
                ' efectivas por día. Las efectivas se contabilizan una vez por institución y día.</div>';
        } else {
            $html .= '<div class="flow-note">Las efectivas se contabilizan una vez por institución y día y después se suman por ' .
                $this->e($granularidadLabel) . '. No se extrapola la meta diaria a una meta ' .
                $this->e($granularidadLabel) . '.</div>';
        }

        $html .= '<table class="data-table activity-phone-table"><thead><tr>';
        $html .= '<th>' . $this->e(ucfirst($granularidadLabel)) . '</th>';
        $html .= '<th class="center">Llamadas</th>';
        $html .= '<th class="center">Contacto</th>';
        $html .= '<th class="center">Efectivas</th>';
        $html .= '<th class="right">' . ($granularidad === 'dia' ? 'Avance diario' : 'Tasa contacto') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($periodos as $periodo) {
            $etiqueta = trim((string)($periodo['etiqueta'] ?? '—'));
            $sub = trim((string)($periodo['subetiqueta'] ?? ''));
            $efectivas = max(0, (int)($periodo['efectivas'] ?? 0));

            $html .= '<tr><td><strong>' . $this->e($etiqueta) . '</strong>';
            if ($sub !== '') {
                $html .= '<small>' . $this->e($sub) . '</small>';
            }
            $html .= '</td>';
            $html .= '<td class="center">' . (int)($periodo['llamadas'] ?? 0) . '</td>';
            $html .= '<td class="center">' . (int)($periodo['con_contacto'] ?? 0) . '</td>';
            $html .= '<td class="center activity-effective">' . $efectivas . '</td>';
            if ($granularidad === 'dia') {
                $html .= '<td class="right">' . $efectivas . '/' . $metaDiaria . '</td>';
            } else {
                $html .= '<td class="right">' .
                    $this->decimal($periodo['tasa_contacto'] ?? 0, 1) . '%</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function composicionActividad(array $analitica): string
    {
        $canales = is_array($analitica['canales'] ?? null) ? $analitica['canales'] : [];
        $llamadas = is_array($analitica['llamadas'] ?? null) ? $analitica['llamadas'] : [];

        $html = '<section class="report-section keep activity-composition">' .
            $this->titulo('Actividad medible y resultados');
        $html .= '<table class="activity-channel-summary"><tr>';
        $html .= '<td><span>Llamadas</span><strong>' . (int)($canales['llamadas'] ?? 0) . '</strong></td>';
        $html .= '<td><span>Correos</span><strong>' . (int)($canales['correos'] ?? 0) . '</strong></td>';
        $html .= '<td><span>Con contacto</span><strong>' . (int)($llamadas['contactadas'] ?? 0) . '</strong></td>';
        $html .= '<td><span>Tasa de contacto</span><strong>' .
            $this->decimal($llamadas['tasa_contacto'] ?? 0, 1) . '%</strong></td>';
        $html .= '</tr></table>';

        $html .= '<table class="call-summary activity-call-summary"><tr>';
        $html .= $this->callMetric('Sin respuesta', (string)(int)($llamadas['sin_respuesta'] ?? 0));
        $html .= $this->callMetric('Número incorrecto', (string)(int)($llamadas['numero_incorrecto'] ?? 0));
        $html .= $this->callMetric('Llamar después', (string)(int)($llamadas['volver_llamar'] ?? 0));
        $html .= $this->callMetric('Efectivas contabilizadas', (string)(int)($llamadas['verificaciones_efectivas'] ?? 0));
        $html .= '</tr></table>';

        return $html . '</section>';
    }

    private function evolucionActividadEjecutiva(array $evolucion): string
    {
        $periodos = is_array($evolucion['periodos'] ?? null) ? $evolucion['periodos'] : [];
        $granularidad = strtolower(trim((string)($evolucion['granularidad'] ?? 'dia')));
        $granularidadLabel = [
            'dia' => 'día',
            'semana' => 'semana',
            'mes' => 'mes'
        ][$granularidad] ?? 'periodo';

        $html = '<section class="report-section keep activity-evolution-section">' .
            $this->titulo('Evolución del periodo · Actividad por ' . $granularidadLabel);

        if (empty($periodos)) {
            return $html . $this->vacio('No se registraron actividades durante el periodo seleccionado.') . '</section>';
        }

        $mayor = is_array($evolucion['mayor'] ?? null) ? $evolucion['mayor'] : [];
        $menor = is_array($evolucion['menor'] ?? null) ? $evolucion['menor'] : [];
        $html .= '<table class="activity-evolution-summary"><tr>';
        $html .= '<td><span>Actividades</span><strong>' . (int)($evolucion['total'] ?? 0) . '</strong></td>';
        $html .= '<td><span>Mayor actividad</span><strong>' .
            $this->e((string)($mayor['etiqueta'] ?? '—')) . '</strong><small>' .
            (int)($mayor['total'] ?? 0) . ' actividades</small></td>';
        $html .= '<td><span>Menor actividad</span><strong>' .
            $this->e((string)($menor['etiqueta'] ?? '—')) . '</strong><small>' .
            (int)($menor['total'] ?? 0) . ' actividades</small></td>';
        if (!empty($evolucion['comparacion_disponible'])) {
            $variacion = (float)($evolucion['variacion'] ?? 0);
            $html .= '<td><span>Variación</span><strong>' .
                ($variacion > 0 ? '+' : '') . $this->decimal($variacion, 1) .
                '%</strong><small>vs. periodo anterior</small></td>';
        } else {
            $html .= '<td><span>Comparación</span><strong>—</strong><small>Sin periodo comparable</small></td>';
        }
        $html .= '</tr></table>';
        $html .= '<img class="line-chart activity-line-chart" src="' .
            $this->graficaLineaDataUri($periodos) . '" alt="Evolución de actividad">';
        return $html . '</section>';
    }

    private function coberturaActividad(array $analitica): string
    {
        $instituciones = is_array($analitica['instituciones_actividad'] ?? null)
            ? $analitica['instituciones_actividad']
            : [];
        $total = max(0, (int)($analitica['seguimientos_con_actividad'] ?? 0));

        $html = '<section class="report-section activity-coverage-section">' .
            $this->titulo('Instituciones con mayor actividad');

        if (empty($instituciones)) {
            return $html . $this->vacio('No hay instituciones con actividad registrada en el periodo.') . '</section>';
        }

        $html .= '<div class="flow-note">Principales ' . count($instituciones) . ' de ' . $total .
            ' instituciones trabajadas en el periodo.</div>';
        $html .= '<table class="data-table activity-coverage-table"><thead><tr>';
        $html .= '<th>Institución</th><th>Municipio</th>';
        $html .= '<th class="center">Interacciones</th><th class="center">Llamadas</th>';
        $html .= '<th class="center">Contacto</th><th class="center">Efectivas</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($instituciones as $institucion) {
            $html .= '<tr><td><strong>' .
                $this->e((string)($institucion['nombre_entidad'] ?? 'Institución')) .
                '</strong></td>';
            $html .= '<td>' . $this->e((string)($institucion['municipio'] ?? '—')) . '</td>';
            $html .= '<td class="center">' . (int)($institucion['interacciones'] ?? 0) . '</td>';
            $html .= '<td class="center">' . (int)($institucion['llamadas'] ?? 0) . '</td>';
            $html .= '<td class="center">' . (int)($institucion['con_contacto'] ?? 0) . '</td>';
            $html .= '<td class="center activity-effective">' . (int)($institucion['efectivas'] ?? 0) . '</td></tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function detalleActividad(array $actividades): string
    {
        $html = '<section class="report-section activity-detail-section">' .
            $this->titulo('Detalle de actividad');
        $html .= '<div class="flow-note">Se muestran ' . count($actividades) .
            ' interacciones humanas del periodo, ordenadas de la más reciente a la más antigua.</div>';
        $html .= '<table class="data-table activity-detail-table"><thead><tr>';
        $html .= '<th>Fecha</th><th>Institución</th><th>Interacción</th><th>Resultado</th><th>Detalle</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($actividades as $actividad) {
            $canal = strtoupper(trim((string)($actividad['canal'] ?? '')));
            $resultado = strtoupper(trim((string)($actividad['resultado'] ?? '')));
            $notas = (string)($actividad['notas'] ?? '');
            $duracion = max(0, (int)($actividad['duracion_segundos'] ?? 0));
            $esLlamada = in_array($canal, ['LLAMADA', 'LLAMADA_IP'], true);
            $verificacionValida =
                $esLlamada &&
                strpos($notas, '[VERIFICACION_EFECTIVA]') !== false &&
                trim((string)($actividad['proveedor_externo'] ?? '')) !== '' &&
                trim((string)($actividad['id_externo'] ?? '')) !== '' &&
                $duracion > 0;
            $contacto =
                $esLlamada &&
                strpos($notas, '[SIN_CONTACTO_EFECTIVO]') === false &&
                (
                    strpos($notas, '[CONTACTO_EFECTIVO]') !== false ||
                    in_array(
                        $resultado,
                        [
                            'CONTACTADO',
                            'CONTACTO_CORRECTO',
                            'CONTACTO_REFERIDO',
                            'SOLICITO_INFORMACION',
                            'SOLICITO_LLAMAR_DESPUES',
                            'NO_INTERESADO'
                        ],
                        true
                    )
                );

            if ($verificacionValida) {
                $resultadoLabel = 'Verificación válida';
            } elseif ($contacto) {
                $resultadoLabel = 'Con contacto';
            } elseif ($resultado === 'OTRO') {
                $resultadoLabel = 'Sin clasificación';
            } else {
                $resultadoLabel = $this->resultadoLabel($resultado);
            }

            $detalle = '';
            if ($esLlamada) {
                $partes = [];
                if ($duracion > 0) {
                    $minutos = intdiv($duracion, 60);
                    $segundos = $duracion % 60;
                    $partes[] = $minutos > 0
                        ? $minutos . ' min ' . str_pad((string)$segundos, 2, '0', STR_PAD_LEFT) . ' s'
                        : $segundos . ' s';
                }
                if ($verificacionValida) {
                    $partes[] = 'evidencia vinculada';
                } elseif ($contacto) {
                    $partes[] = 'contacto registrado';
                } else {
                    $partes[] = 'intento telefónico';
                }
                $detalle = implode(' · ', $partes);
            } elseif ($canal === 'CORREO') {
                $detalle = 'Correo registrado en el seguimiento';
            } else {
                $detalle = 'Actividad registrada';
            }

            $resultadoClass = $verificacionValida
                ? ' activity-status-effective'
                : ($contacto ? ' activity-status-contact' : '');

            $html .= '<tr>';
            $html .= '<td>' . $this->e($this->fechaDato((string)($actividad['fecha_inicio'] ?? ''))) . '</td>';
            $html .= '<td><strong>' . $this->e((string)($actividad['nombre_entidad'] ?? '—')) . '</strong></td>';
            $html .= '<td>' . $this->e($this->canalLabel($canal)) . '</td>';
            $html .= '<td><span class="activity-status' . $resultadoClass . '">' .
                $this->e($resultadoLabel) . '</span></td>';
            $html .= '<td>' . $this->e($detalle) . '</td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table></section>';
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

    private function ficha(array $s, array $flujo, array $detalle): string
    {
        $detalleSeguimiento = is_array($detalle['seguimiento'] ?? null) ? $detalle['seguimiento'] : [];
        $nombre = trim((string)($s['nombre_entidad'] ?? 'Institución seleccionada'));
        $ubicacion = implode(', ', array_values(array_filter([
            trim((string)($s['municipio'] ?? $detalleSeguimiento['municipio'] ?? '')),
            trim((string)($detalleSeguimiento['estado_nombre'] ?? $s['estado_nombre'] ?? ''))
        ])));
        $responsable = trim((string)($s['responsable_nombre'] ?? ''));

        $etapa = trim((string)($flujo['ventana']['actual']['titulo'] ?? $flujo['titulo'] ?? ''));
        if ($etapa === '') {
            $etapa = trim((string)($s['estado_label'] ?? 'Sin etapa disponible'));
        }

        $accion = trim((string)($flujo['accion_principal']['etiqueta'] ?? ''));
        if ($accion === '') {
            $accion = trim((string)($s['proxima_accion_label'] ?? ''));
        }

        $pasoActual = max(1, (int)($flujo['paso_actual'] ?? 1));
        $totalPasos = max($pasoActual, (int)($flujo['total_pasos'] ?? 13));
        $paso = 'Paso ' . $pasoActual . ' de ' . $totalPasos;

        if ($pasoActual >= $totalPasos) {
            $accion = 'Continuidad con Cuenta Clave';
        } elseif ($accion === '') {
            $accion = 'Sin acción pendiente registrada';
        }

        $ultimaHumana = is_array($detalle['ultima_interaccion_humana'] ?? null)
            ? $detalle['ultima_interaccion_humana']
            : [];
        $ultimaFecha = trim((string)($ultimaHumana['fecha_inicio'] ?? ''));
        $ultimaCanal = $this->canalLabel((string)($ultimaHumana['canal'] ?? ''));
        $ultima = $ultimaFecha !== '' ? $this->fechaDato($ultimaFecha) : 'Sin contacto humano registrado';
        $dias = $this->diasDesde($ultimaFecha);
        $diasLabel = $dias === null
            ? 'Sin contacto humano'
            : ($dias . ($dias === 1 ? ' día' : ' días'));

        $html = '<section class="report-section institution keep">';
        $html .= '<table class="institution-head"><tr><td><span>Institución seleccionada</span>';
        $html .= '<h2>' . $this->e($nombre) . '</h2><p>' .
            $this->e($ubicacion !== '' ? $ubicacion : 'Ubicación no disponible') . '</p></td>';
        $html .= '<td class="step">' . $this->e($paso) . '</td></tr></table>';
        $html .= '<table class="institution-grid"><tr>';
        $html .= $this->info('Responsable', $responsable !== '' ? $responsable : '—', false);
        $html .= $this->info('Etapa de vinculación', $etapa, true);
        $html .= $this->info('Siguiente acción', $accion, true);
        $html .= '</tr><tr>';
        $html .= $this->info('Último contacto humano', $ultima, false);
        $html .= $this->info('Días desde último contacto', $diasLabel, false);
        $html .= $this->info('Último canal humano', $ultimaCanal !== '' ? $ultimaCanal : '—', false);
        $html .= '</tr></table>';

        $descripcion = trim((string)($flujo['descripcion'] ?? ''));
        if ($descripcion !== '') {
            $html .= '<div class="flow-note">' . $this->e($descripcion) . '</div>';
        }

        return $html . '</section>';
    }

    private function resumenIndividual(array $analitica, array $detalle): string
    {
        $ultima = is_array($detalle['ultima_interaccion_humana'] ?? null)
            ? $detalle['ultima_interaccion_humana']
            : [];
        $fechaUltima = trim((string)($ultima['fecha_inicio'] ?? ''));
        $dias = $this->diasDesde($fechaUltima);
        $llamadas = is_array($analitica['llamadas'] ?? null) ? $analitica['llamadas'] : [];
        $canales = is_array($analitica['canales'] ?? null) ? $analitica['canales'] : [];

        $html = '<section class="report-section keep">' . $this->titulo('Panorama de la relación');
        $html .= '<table class="metrics individual-metrics"><tr>';
        $html .= $this->metric('Interacciones', (string)(int)($analitica['interacciones'] ?? 0));
        $html .= $this->metric('Llamadas realizadas', (string)(int)($llamadas['total'] ?? 0));
        $html .= $this->metric('Llamadas con contacto', (string)(int)($llamadas['contactadas'] ?? 0));
        $html .= $this->metric('Correos', (string)(int)($canales['correos'] ?? 0));
        return $html . '</tr></table></section>';
    }

    private function contactoInstitucional(array $detalle): string
    {
        $contacto = is_array($detalle['contacto'] ?? null) ? $detalle['contacto'] : [];
        $seguimiento = is_array($detalle['seguimiento'] ?? null) ? $detalle['seguimiento'] : [];

        $filas = [
            ['Persona de contacto', trim((string)($contacto['nombre'] ?? '')), 'Cargo / área', trim((string)($contacto['cargo'] ?? ''))],
            ['Teléfono', trim((string)($contacto['telefono'] ?? '')), 'WhatsApp', trim((string)($contacto['whatsapp'] ?? ''))],
            ['Correo', trim((string)($contacto['correo'] ?? '')), 'Sitio web', trim((string)($contacto['sitio_web'] ?? ''))],
            ['Actividad / giro', trim((string)($contacto['actividad_giro'] ?? '')), 'Datos de contacto', ($contacto['datos_verificados'] ?? false) ? 'Verificados' : 'Pendientes de verificación']
        ];

        $direccion = trim((string)($contacto['direccion'] ?? ''));
        $hayDato = $direccion !== '';
        foreach ($filas as $fila) {
            if ($fila[1] !== '' || $fila[3] !== '') {
                $hayDato = true;
                break;
            }
        }

        if (!$hayDato) {
            return '';
        }

        $html = '<section class="report-section keep">' . $this->titulo('Datos institucionales y contacto');
        $html .= '<table class="profile-table">';
        foreach ($filas as $fila) {
            if ($fila[1] === '' && $fila[3] === '') {
                continue;
            }
            $html .= '<tr><td class="profile-label">' . $this->e($fila[0]) . '</td><td class="profile-value">' .
                $this->e($fila[1] !== '' ? $fila[1] : '—') . '</td>';
            $html .= '<td class="profile-label">' . $this->e($fila[2]) . '</td><td class="profile-value">' .
                $this->e($fila[3] !== '' ? $fila[3] : '—') . '</td></tr>';
        }
        if ($direccion !== '') {
            $html .= '<tr><td class="profile-label">Dirección</td><td class="profile-value" colspan="3">' .
                $this->e($direccion) . '</td></tr>';
        }
        $observacionGeneral = trim((string)($seguimiento['observaciones'] ?? ''));
        if ($observacionGeneral !== '') {
            $html .= '<tr><td class="profile-label">Observación</td><td class="profile-value" colspan="3">' .
                $this->e($this->resumirTexto($observacionGeneral, 180)) . '</td></tr>';
        }
        return $html . '</table></section>';
    }

    private function rutaIndividual(array $flujo, array $detalle): string
    {
        $actual = max(1, min(13, (int)($flujo['paso_actual'] ?? 1)));
        $porcentaje = max(0, min(100, (int)($flujo['porcentaje'] ?? round(($actual / 13) * 100))));
        $hitos = is_array($detalle['hitos'] ?? null) ? $detalle['hitos'] : [];

        $html = '<section class="report-section keep executive-route">' . $this->titulo('Ruta de vinculación');
        $html .= '<table class="route-summary"><tr><td><span>Avance de la ruta</span><strong>' .
            $actual . ' de 13 etapas</strong></td><td class="route-percent">' . $porcentaje . '%</td></tr></table>';
        $html .= '<div class="route-progress"><div class="route-fill" style="width:' .
            number_format($porcentaje, 1, '.', '') . '%"></div></div>';

        if (!empty($hitos)) {
            $html .= '<table class="route-executive"><tr>';
            foreach (array_slice($hitos, 0, 5) as $hito) {
                $estado = strtoupper(trim((string)($hito['estado'] ?? 'PENDIENTE')));
                $clase = $estado === 'COMPLETADO'
                    ? 'done'
                    : ($estado === 'EN_PROCESO' ? 'current' : 'pending');
                $detalleHito = trim((string)($hito['detalle'] ?? ''));
                $html .= '<td class="' . $clase . '"><span class="route-check">' .
                    ($estado === 'COMPLETADO' ? '✓' : ($estado === 'EN_PROCESO' ? '→' : '•')) .
                    '</span><strong>' . $this->e((string)($hito['titulo'] ?? 'Hito')) . '</strong>';
                if ($detalleHito !== '') {
                    $html .= '<small>' . $this->e($this->resumirTexto($detalleHito, 60)) . '</small>';
                }
                $html .= '</td>';
            }
            $html .= '</tr></table>';
        }

        return $html . '</section>';
    }

    private function historialIndividual(array $detalle): string
    {
        $interacciones = is_array($detalle['interacciones_todas'] ?? null)
            ? $detalle['interacciones_todas']
            : (is_array($detalle['interacciones_recientes'] ?? null)
                ? $detalle['interacciones_recientes']
                : []);

        if (empty($interacciones)) {
            return '';
        }

        $html = '<section class="report-section history-section">' . $this->titulo('Interacciones del expediente');
        $html .= '<table class="data-table history-table"><thead><tr>';
        $html .= '<th>Fecha</th><th>Interacción</th><th>Resultado</th><th>Resumen</th><th>Responsable</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($interacciones as $interaccion) {
            $responsable = trim(
                (string)($interaccion['nombre'] ?? '') . ' ' .
                (string)($interaccion['apellidos'] ?? '')
            );
            $presentacion = is_array($interaccion['presentacion'] ?? null)
                ? $interaccion['presentacion']
                : [];
            $titulo = trim((string)($presentacion['titulo'] ?? ''));
            $resultado = trim((string)($presentacion['resultado_label'] ?? ''));
            $resumen = trim((string)($presentacion['resumen'] ?? ''));

            if (strcasecmp($titulo, 'Llamada') === 0) {
                $notasLlamada = (string)($interaccion['notas'] ?? '');
                $resultadoCodigo = strtoupper(trim((string)($interaccion['resultado'] ?? '')));
                $duracion = max(0, (int)($interaccion['duracion_segundos'] ?? 0));
                $telefono = trim((string)($interaccion['telefono_destino'] ?? ''));
                $contactoEfectivo = strpos($notasLlamada, '[CONTACTO_EFECTIVO]') !== false
                    && strpos($notasLlamada, '[SIN_CONTACTO_EFECTIVO]') === false;
                $sinContacto = strpos($notasLlamada, '[SIN_CONTACTO_EFECTIVO]') !== false;

                if ($contactoEfectivo) {
                    $resultado = 'Contacto efectivo';
                } elseif ($sinContacto) {
                    $resultado = 'Sin contacto';
                } elseif ($resultadoCodigo === 'BUZON_VOZ' || strpos($notasLlamada, '[BUZON_VOZ]') !== false) {
                    $resultado = 'Buzón de voz';
                } elseif ($resultadoCodigo === 'FUERA_SERVICIO' || strpos($notasLlamada, '[FUERA_SERVICIO]') !== false) {
                    $resultado = 'Fuera de servicio';
                } elseif ($resultado === '' || strcasecmp($resultado, 'Otro') === 0) {
                    $resultado = 'Resultado no clasificado';
                }

                if (
                    $resumen === '' ||
                    strcasecmp($resumen, 'Otro') === 0 ||
                    strcasecmp($resumen, 'Llamada') === 0
                ) {
                    $partes = [];
                    if ($contactoEfectivo) {
                        $partes[] = 'Se logró contacto';
                    } elseif ($sinContacto) {
                        $partes[] = 'No se logró contacto';
                    } else {
                        $partes[] = 'Llamada registrada';
                    }

                    if ($telefono !== '') {
                        $partes[] = 'al ' . $telefono;
                    }

                    if ($duracion > 0) {
                        $minutos = intdiv($duracion, 60);
                        $segundos = $duracion % 60;
                        $partes[] = 'duración ' .
                            ($minutos > 0
                                ? $minutos . ' min ' . str_pad((string)$segundos, 2, '0', STR_PAD_LEFT) . ' s'
                                : $segundos . ' s');
                    }

                    $resumen = implode(' · ', $partes);
                }
            }

            $html .= '<tr><td>' . $this->e($this->fechaDato((string)($interaccion['fecha_inicio'] ?? ''))) . '</td>';
            $html .= '<td>' . $this->e($titulo !== '' ? $titulo : $this->canalLabel((string)($interaccion['canal'] ?? ''))) . '</td>';
            $html .= '<td>' . $this->e($resultado !== '' ? $resultado : $this->resultadoLabel((string)($interaccion['resultado'] ?? ''))) . '</td>';
            $html .= '<td>' . $this->e($this->resumirTexto($resumen !== '' ? $resumen : (string)($interaccion['notas'] ?? ''), 145)) . '</td>';
            $html .= '<td>' . $this->e($responsable !== '' ? $responsable : '—') . '</td></tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function actividadDiariaIndividual(array $detalle): string
    {
        $periodos = is_array($detalle['interacciones_por_dia'] ?? null)
            ? $detalle['interacciones_por_dia']
            : [];

        if (empty($periodos)) {
            return '';
        }

        $total = array_sum(array_map(static function ($periodo) {
            return (int)($periodo['total'] ?? 0);
        }, $periodos));

        $html = '<section class="report-section keep daily-activity">' . $this->titulo('Interacciones por día');
        $html .= '<div class="daily-chart-caption"><strong>' . $total .
            ' interacciones registradas</strong><span>Distribución diaria de la actividad humana de esta institución.</span></div>';
        $html .= '<img class="daily-chart" src="' .
            $this->graficaLineaDiariaDataUri($periodos) .
            '" alt="Interacciones por día">';
        return $html . '</section>';
    }

    private function graficaLineaDiariaDataUri(array $periodos): string
    {
        $periodos = array_values(array_slice($periodos, -14));
        $width = 960;
        $height = 170;
        $left = 42;
        $right = 18;
        $top = 16;
        $bottom = 32;
        $plotW = $width - $left - $right;
        $plotH = $height - $top - $bottom;

        $values = array_map(static function ($periodo) {
            return max(0, (int)($periodo['total'] ?? 0));
        }, $periodos);

        $max = max(1, max($values));
        $max = max(4, (int)(ceil($max / 2) * 2));
        $count = count($periodos);
        $stepX = $count > 1 ? $plotW / ($count - 1) : 0;
        $points = [];
        $grid = '';
        $labels = '';

        for ($i = 0; $i <= 4; $i++) {
            $y = $top + ($plotH * $i / 4);
            $value = (int)round($max * (1 - ($i / 4)));
            $grid .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($width - $right) .
                '" y2="' . $y . '" stroke="#E5E9EF" stroke-width="1"/>';
            $grid .= '<text x="' . ($left - 8) . '" y="' . ($y + 3) .
                '" text-anchor="end" font-size="9" fill="#6D7480">' . $value . '</text>';
        }

        foreach ($periodos as $i => $periodo) {
            $x = $count > 1 ? $left + ($stepX * $i) : $left + ($plotW / 2);
            $total = max(0, (int)($periodo['total'] ?? 0));
            $y = $top + $plotH - (($total / $max) * $plotH);
            $points[] = [$x, $y, $total];
            $labels .= '<text x="' . $x . '" y="' . ($height - 10) .
                '" text-anchor="middle" font-size="8.5" fill="#6D7480">' .
                $this->eSvg((string)($periodo['etiqueta'] ?? '')) . '</text>';
        }

        $polyline = implode(' ', array_map(static function ($point) {
            return number_format($point[0], 1, '.', '') . ',' . number_format($point[1], 1, '.', '');
        }, $points));

        $dots = '';
        foreach ($points as $point) {
            $dots .= '<circle cx="' . $point[0] . '" cy="' . $point[1] .
                '" r="4" fill="#273A8A" stroke="#FFFFFF" stroke-width="2"/>';
            $dots .= '<text x="' . $point[0] . '" y="' . max(11, $point[1] - 8) .
                '" text-anchor="middle" font-size="9" font-weight="700" fill="#16223B">' .
                $point[2] . '</text>';
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height .
            '" viewBox="0 0 ' . $width . ' ' . $height . '">' .
            '<rect width="100%" height="100%" fill="#FFFFFF"/>' . $grid .
            '<polyline points="' . $polyline .
            '" fill="none" stroke="#273A8A" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>' .
            $dots . $labels . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function reunionesIndividual(array $detalle): string
    {
        $reuniones = is_array($detalle['reuniones'] ?? null) ? $detalle['reuniones'] : [];
        $post = is_array($detalle['post_envio'] ?? null) ? $detalle['post_envio'] : [];
        $reunion = $reuniones[0] ?? [];

        if (empty($reunion) && trim((string)($post['reunion_fecha'] ?? '')) === '') {
            return '';
        }

        $fechaReunion = trim((string)($reunion['fecha_propuesta'] ?? $post['reunion_fecha'] ?? ''));
        $modalidad = $this->modalidadLabel((string)($reunion['modalidad'] ?? $post['reunion_modalidad'] ?? ''));
        $cuentaClave = trim((string)($reunion['cuenta_clave_nombre'] ?? ''));
        $objetivo = trim((string)($reunion['objetivo'] ?? ''));
        $resultadoCodigo = trim((string)(
            $reunion['reunion_resultado']
                ?? $post['reunion_resultado']
                ?? ''
        ));
        $resultado = $this->resultadoReunionLabel($resultadoCodigo);
        $resultadoAt = trim((string)(
            $reunion['realizada_at']
                ?? $post['reunion_realizada_at']
                ?? ''
        ));
        $acuerdos = trim((string)(
            $reunion['reunion_resultado_notas']
                ?? $post['reunion_resultado_notas']
                ?? $reunion['notas_kam']
                ?? $reunion['notas_analista']
                ?? ''
        ));

        $html = '<section class="report-section keep meeting-executive">' . $this->titulo('Reunión y acuerdos');
        $html .= '<table class="meeting-grid"><tr>';
        $html .= '<td><span>Reunión</span><strong>' . $this->e($this->fechaDato($fechaReunion)) . '</strong><small>' .
            $this->e($modalidad) . ($cuentaClave !== '' ? ' · Cuenta Clave: ' . $cuentaClave : '') . '</small></td>';
        $html .= '<td><span>Resultado</span><strong>' . $this->e($resultado !== '—' ? $resultado : 'Sin resultado registrado') . '</strong>';
        if ($resultadoAt !== '') {
            $html .= '<small>Resultado registrado: ' . $this->e($this->fechaDato($resultadoAt)) . '</small>';
        }
        $html .= '</td></tr></table>';

        if ($objetivo !== '') {
            $html .= '<div class="meeting-note"><span>Objetivo</span><p>' .
                $this->e($this->resumirTexto($objetivo, 180)) . '</p></div>';
        }
        if ($acuerdos !== '') {
            $html .= '<div class="meeting-note key"><span>Acuerdo principal</span><p>' .
                $this->e($this->resumirTexto($acuerdos, 260)) . '</p></div>';
        }

        return $html . '</section>';
    }

    private function documentacionIndividual(array $detalle): string
    {
        $oficios = is_array($detalle['oficios'] ?? null) ? $detalle['oficios'] : [];
        $correos = is_array($detalle['correos_todos'] ?? null)
            ? $detalle['correos_todos']
            : (is_array($detalle['correos_recientes'] ?? null) ? $detalle['correos_recientes'] : []);
        $post = is_array($detalle['post_envio'] ?? null) ? $detalle['post_envio'] : [];

        $hayPost = trim((string)($post['respuesta_at'] ?? '')) !== '' ||
            trim((string)($post['convenio_formalizado_at'] ?? '')) !== '';

        if (empty($oficios) && empty($correos) && !$hayPost) {
            return '';
        }

        $html = '<section class="report-section keep documentation-executive">' . $this->titulo('Comunicación formal y avance');

        $oficio = $oficios[0] ?? [];
        if (!empty($oficio)) {
            $destinatario = trim((string)($oficio['destinatario_nombre'] ?? ''));
            $cargo = trim((string)($oficio['destinatario_cargo'] ?? ''));
            if ($cargo !== '') {
                $destinatario .= ($destinatario !== '' ? ' · ' : '') . $cargo;
            }
            $html .= '<table class="document-summary"><tr>';
            $html .= '<td><span>Oficio</span><strong>' . $this->e(trim((string)($oficio['folio'] ?? 'Oficio'))) . '</strong><small>' .
                $this->e($this->estadoOficioLabel((string)($oficio['estado_oficio'] ?? ''))) . ' · enviado ' .
                $this->e($this->fechaDato((string)($oficio['fecha_envio'] ?? ''))) . '</small></td>';
            $html .= '<td><span>Destinatario</span><strong>' . $this->e($destinatario !== '' ? $destinatario : '—') . '</strong></td>';
            $html .= '</tr></table>';
        }

        if (trim((string)($post['respuesta_at'] ?? '')) !== '') {
            $respuesta = $this->respuestaTipoLabel((string)($post['respuesta_tipo'] ?? ''));
            $detalleRespuesta = trim((string)($post['respuesta_texto'] ?? ''));
            $html .= '<div class="formal-answer"><span>Respuesta de la institución</span><strong>' .
                $this->e($respuesta !== '' ? $respuesta : 'Respuesta registrada') . '</strong><small>' .
                $this->e($this->fechaDato((string)$post['respuesta_at'])) . '</small>';
            if ($detalleRespuesta !== '') {
                $html .= '<p>' . $this->e($this->resumirTexto($detalleRespuesta, 190)) . '</p>';
            }
            $html .= '</div>';
        }

        if (!empty($correos)) {
            $html .= '<div class="email-block"><span class="email-title">Actividad por correo</span>';
            foreach ($correos as $correo) {
                $presentacion = is_array($correo['presentacion'] ?? null)
                    ? $correo['presentacion']
                    : [];
                $tituloCorreo = trim((string)($presentacion['titulo'] ?? ''));
                $resumen = trim((string)($presentacion['resumen'] ?? ''));
                if (stripos($resumen, 'Asunto: ') === 0) {
                    $resumen = trim(substr($resumen, 8));
                }
                if ($tituloCorreo === '') {
                    $tituloCorreo = 'Correo';
                }
                if ($resumen === '') {
                    $resumen = $this->resultadoLabel((string)($correo['resultado'] ?? ''));
                }

                $html .= '<div class="email-row"><span>' .
                    $this->e($this->fechaDato((string)($correo['fecha_inicio'] ?? ''))) .
                    '</span><div><strong>' . $this->e($tituloCorreo) . '</strong>';
                if ($resumen !== '' && strcasecmp($resumen, $tituloCorreo) !== 0) {
                    $html .= '<small>' . $this->e($this->resumirTexto($resumen, 130)) . '</small>';
                }
                $html .= '</div></div>';
            }
            $html .= '</div>';
        }

        $timeline = [];
        if (trim((string)($post['respuesta_at'] ?? '')) !== '') {
            $timeline[] = ['Respuesta recibida', $this->fechaDato((string)$post['respuesta_at']), $this->respuestaTipoLabel((string)($post['respuesta_tipo'] ?? ''))];
        }
        if (trim((string)($post['reunion_realizada_at'] ?? '')) !== '') {
            $timeline[] = ['Resultado de reunión', $this->fechaDato((string)$post['reunion_realizada_at']), $this->resultadoReunionLabel((string)($post['reunion_resultado'] ?? ''))];
        }
        if (trim((string)($post['convenio_formalizado_at'] ?? '')) !== '') {
            $timeline[] = ['Convenio formalizado', $this->fechaDato((string)$post['convenio_formalizado_at']), 'Formalización concluida'];
        }

        if (!empty($timeline)) {
            $html .= '<div class="milestone-line">';
            foreach ($timeline as $evento) {
                $html .= '<div class="milestone-item"><span class="formal-dot">●</span><div><strong>' .
                    $this->e($evento[0]) . '</strong><small>' . $this->e($evento[1]) .
                    ($evento[2] !== '' ? ' · ' . $this->e($evento[2]) : '') . '</small></div></div>';
            }
            $html .= '</div>';
        }

        return $html . '</section>';
    }

    private function observacionesIndividual(array $detalle): string
    {
        $observaciones = is_array($detalle['observaciones'] ?? null) ? $detalle['observaciones'] : [];
        if (empty($observaciones)) {
            return '';
        }

        $html = '<section class="report-section keep observations-section">' . $this->titulo('Observaciones recientes');
        foreach (array_slice($observaciones, 0, 3) as $observacion) {
            $autor = trim(
                (string)($observacion['nombre'] ?? '') . ' ' .
                (string)($observacion['apellidos'] ?? '')
            );
            $texto = trim((string)($observacion['observacion'] ?? ''));
            if ($texto === '') {
                continue;
            }
            $html .= '<div class="observation"><strong>' . $this->e($autor !== '' ? $autor : 'Equipo de vinculación') . '</strong>';
            $html .= '<span>' . $this->e($this->fechaDato((string)($observacion['created_at'] ?? ''))) . '</span>';
            $html .= '<p>' . $this->e($this->resumirTexto($texto, 220)) . '</p></div>';
        }
        return $html . '</section>';
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

    private function contactoIndividual(array $analitica, array $detalle): string
    {
        $llamadas = is_array($analitica['llamadas'] ?? null) ? $analitica['llamadas'] : [];
        $canales = is_array($analitica['canales'] ?? null) ? $analitica['canales'] : [];
        $ultima = is_array($detalle['ultima_interaccion_humana'] ?? null)
            ? $detalle['ultima_interaccion_humana']
            : [];

        $html = '<section class="report-section keep individual-contact">' . $this->titulo('Actividad y contacto');
        $html .= '<table class="executive-metrics"><tr>';
        $html .= $this->opMetric('Llamadas', (string)(int)($llamadas['total'] ?? 0));
        $html .= $this->opMetric('Con contacto', (string)(int)($llamadas['contactadas'] ?? 0));
        $html .= $this->opMetric('Sin respuesta', (string)(int)($llamadas['sin_respuesta'] ?? 0));
        $html .= $this->opMetric('Correos', (string)(int)($canales['correos'] ?? 0));
        $html .= $this->opMetric('Contacto telefónico', $this->decimal($llamadas['tasa_contacto'] ?? 0, 1) . '%');
        $html .= '</tr></table>';

        if (!empty($ultima)) {
            $html .= '<div class="decision-note"><strong>Última interacción humana:</strong> ' .
                $this->e($this->fechaDato((string)($ultima['fecha_inicio'] ?? ''))) . ' · ' .
                $this->e($this->canalLabel((string)($ultima['canal'] ?? ''))) . '</div>';
        }

        return $html . '</section>';
    }

    private function contacto(array $analitica, bool $soloCanalesMedibles = false): string
    {
        $canales = is_array($analitica['canales'] ?? null) ? $analitica['canales'] : [];
        $llamadas = is_array($analitica['llamadas'] ?? null) ? $analitica['llamadas'] : [];

        $html = '<section class="report-section keep">' . $this->titulo(
            $soloCanalesMedibles ? 'Actividad medible' : 'Actividad y contacto'
        );
        $html .= '<table class="mini"><tr>';
        $html .= $this->mini('Llamadas', (int)($canales['llamadas'] ?? 0));
        $html .= $this->mini('Correos', (int)($canales['correos'] ?? 0));
        if (!$soloCanalesMedibles) {
            $html .= $this->mini('WhatsApp', (int)($canales['whatsapp'] ?? 0));
            $html .= $this->mini('Otros', (int)($canales['otros'] ?? 0));
        }
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
        $right = 48;
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
            $anchor = $i === 0
                ? 'start'
                : ($i === $count - 1 ? 'end' : 'middle');
            $labels .= '<text x="' . $x . '" y="' . ($height - 18) .
                '" text-anchor="' . $anchor . '" font-size="10.5" fill="#6D7480">' .
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
        return '';
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

    private function fechaDato(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return '—';
        }

        try {
            return (new DateTime($valor))->format('d/m/Y H:i');
        } catch (Throwable $error) {
            return $valor;
        }
    }

    private function fechaSoloDia(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return '—';
        }

        try {
            return (new DateTime($valor))->format('d/m/Y');
        } catch (Throwable $error) {
            return $valor;
        }
    }

    private function diasDesde(string $valor)
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        try {
            $fecha = (new DateTimeImmutable($valor))->setTime(0, 0);
            $hoy = (new DateTimeImmutable('today'))->setTime(0, 0);
            return $fecha >= $hoy ? 0 : (int)$fecha->diff($hoy)->days;
        } catch (Throwable $error) {
            return null;
        }
    }

    private function canalLabel(string $canal): string
    {
        $canal = strtoupper(trim($canal));
        $labels = [
            'LLAMADA_IP' => 'Llamada',
            'LLAMADA' => 'Llamada',
            'CORREO' => 'Correo',
            'WHATSAPP' => 'WhatsApp',
            'NOTA' => 'Nota'
        ];
        return $labels[$canal] ?? ($canal !== '' ? ucfirst(strtolower(str_replace('_', ' ', $canal))) : '');
    }

    private function resultadoLabel(string $resultado): string
    {
        $resultado = strtoupper(trim($resultado));
        $labels = [
            'CONTACTADO' => 'Contactado',
            'NO_CONTESTO' => 'No contestó',
            'OCUPADO' => 'Ocupado',
            'SIN_RESPUESTA' => 'Sin respuesta',
            'NUMERO_INCORRECTO' => 'Número incorrecto',
            'CONTACTO_INCORRECTO' => 'Contacto incorrecto',
            'SOLICITO_LLAMAR_DESPUES' => 'Volver a llamar',
            'SOLICITO_INFORMACION' => 'Solicitó información',
            'MENSAJE_ENVIADO' => 'Mensaje enviado',
            'CORREO_ENVIADO' => 'Correo enviado',
            'NO_INTERESADO' => 'No interesado',
            'OTRO' => 'Otro'
        ];
        return $labels[$resultado] ?? ($resultado !== '' ? ucfirst(strtolower(str_replace('_', ' ', $resultado))) : '—');
    }

    private function modalidadLabel(string $modalidad): string
    {
        $modalidad = strtoupper(trim($modalidad));
        $labels = [
            'VIRTUAL' => 'Virtual',
            'PRESENCIAL' => 'Presencial',
            'HIBRIDA' => 'Híbrida'
        ];
        return $labels[$modalidad] ?? ($modalidad !== '' ? ucfirst(strtolower($modalidad)) : '—');
    }

    private function estadoReunionLabel(string $estado): string
    {
        $estado = strtoupper(trim($estado));
        $labels = [
            'SOLICITADA' => 'Solicitada',
            'CONFIRMADA' => 'Confirmada',
            'CORREO_ENVIADO' => 'Confirmación enviada',
            'CAMBIO_SOLICITADO' => 'Cambio solicitado',
            'REALIZADA' => 'Realizada',
            'CANCELADA' => 'Cancelada'
        ];
        return $labels[$estado] ?? ($estado !== '' ? ucfirst(strtolower(str_replace('_', ' ', $estado))) : '—');
    }

    private function resultadoReunionLabel(string $resultado): string
    {
        $resultado = strtoupper(trim($resultado));
        $labels = [
            'AVANZAR_CONVENIO' => 'Avanzar a convenio',
            'REQUIERE_SEGUIMIENTO' => 'Requiere seguimiento',
            'NO_INTERESADO' => 'No interesado'
        ];
        return $labels[$resultado] ?? ($resultado !== '' ? ucfirst(strtolower(str_replace('_', ' ', $resultado))) : '—');
    }

    private function respuestaTipoLabel(string $tipo): string
    {
        $tipo = strtoupper(trim($tipo));
        $labels = [
            'INTERESADO' => 'Interesado',
            'MAS_INFORMACION' => 'Solicita más información',
            'QUIERE_REUNION' => 'Solicita reunión',
            'CONTACTAR_DESPUES' => 'Contactar después',
            'NO_INTERESADO' => 'No interesado'
        ];
        return $labels[$tipo] ?? ($tipo !== '' ? ucfirst(strtolower(str_replace('_', ' ', $tipo))) : '');
    }

    private function estadoOficioLabel(string $estado): string
    {
        $estado = strtoupper(trim($estado));
        $labels = [
            'BORRADOR' => 'Borrador',
            'GENERADO' => 'Generado',
            'ENVIADO' => 'Enviado'
        ];
        return $labels[$estado] ?? ($estado !== '' ? ucfirst(strtolower(str_replace('_', ' ', $estado))) : '—');
    }

    private function resumirTexto(string $texto, int $limite): string
    {
        $texto = trim(preg_replace('/\\s+/u', ' ', $texto) ?? '');
        if ($texto === '') {
            return '—';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($texto, 'UTF-8') > $limite
                ? rtrim(mb_substr($texto, 0, $limite - 1, 'UTF-8')) . '…'
                : $texto;
        }

        return strlen($texto) > $limite
            ? rtrim(substr($texto, 0, $limite - 1)) . '…'
            : $texto;
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
            '.top-rule{height:4px;background:#273A8A;margin:-18mm -14mm 10px}' .
            '.header{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:6px}' .
            '.brand{width:175px;vertical-align:middle}.brand img{display:block;width:146px;height:auto;max-width:146px}' .
            '.header-copy{vertical-align:middle;text-align:right;padding-left:10px}' .
            '.system-name{font-size:6.3pt;color:#273A8A;font-weight:800;letter-spacing:.035em;margin-bottom:2px}' .
            '.header h1{font-size:11.8pt;line-height:1.1;color:#16223B;margin:0 0 6px;font-weight:800;white-space:nowrap}' .
            '.header-meta{margin-left:auto;border-collapse:collapse;font-size:6.1pt;line-height:1.2}' .
            '.header-meta td{color:#6D7480;text-align:right;padding:.5px 0 .5px 10px}.header-meta th{color:#16223B;text-align:right;padding:.5px 0 .5px 7px;font-weight:700}' .
            '.header-rule{height:2px;background:#273A8A;margin:0 0 10px}' .
            '.scope{width:100%;table-layout:fixed;border-collapse:collapse;background:#F7F9FC;border:1px solid #D7DFEA;margin-bottom:12px}' .
            '.scope td{padding:8px 10px;vertical-align:middle;text-align:left;border-right:1px solid #D7DFEA}.scope td:last-child{border-right:0}' .
            '.scope span,.info span,.metric span,.mini span,.call-summary span,.operational span{display:block;color:#6D7480;font-size:6pt;margin-bottom:2px}' .
            '.scope strong{font-size:7.1pt;color:#16223B}.scope-total{width:118px;text-align:left}.scope-total strong{font-size:7.2pt;color:#273A8A}' .
            '.report-section{margin:0 0 15px}.keep{page-break-inside:avoid}' .
            '.section-title{border-left:3px solid #273A8A;padding-left:8px;margin:0 0 11px;page-break-inside:avoid;page-break-after:avoid}' .
            '.section-title h2{font-size:10.7pt;color:#16223B;margin:0;font-weight:800}' .
            '.metrics{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}' .
            '.metric{width:25%;background:#F9FBFE;border:1px solid #D3DCE8;padding:9px;vertical-align:middle}.metric strong{display:block;color:#16223B;font-size:13pt;font-weight:800;margin-top:2px}' .
            '.institution{border:1px solid #E5E9EF;padding:9px 10px;background:#FFFFFF}' .
            '.institution-head{width:100%;border-collapse:collapse}.institution-head td{vertical-align:middle}.institution-head span{color:#0A8F7A;font-size:6.2pt;font-weight:800;letter-spacing:.04em}' .
            '.institution-head h2{font-size:12.6pt;margin:2px 0;color:#16223B}.institution-head p{margin:0;color:#6D7480;font-size:6.8pt}.step{text-align:right;font-size:6.4pt!important;color:#273A8A!important;font-weight:800;width:90px}' .
            '.institution-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px;margin-top:7px}.info{width:33.33%;border:1px solid #E5E9EF;padding:7px 8px;vertical-align:middle}.info.key{background:#EDF2FA}.info strong{display:block;font-size:7.3pt;color:#16223B}.info.key strong{color:#273A8A}' .
            '.data-table{width:100%;border-collapse:collapse;font-size:6.25pt;page-break-inside:auto}.data-table thead{display:table-header-group}.data-table tr{page-break-inside:avoid;page-break-after:auto}' .
            '.data-table th{background:#273A8A;color:#FFFFFF;text-align:left;padding:6px 7px;font-weight:700}.data-table td{padding:6px 7px;border-bottom:1px solid #E5E9EF;vertical-align:top}.data-table tbody tr:nth-child(even){background:#F8FAFC}.data-table small{display:block;color:#6D7480;font-size:5.5pt;margin-top:2px}' .
            '.right{text-align:right!important}.center{text-align:center!important}.ok{border-left:3px solid #0A8F7A;background:#F8FAFC;padding:8px 10px}.ok strong{display:block;font-size:7pt}.ok span{display:block;color:#6D7480;font-size:6pt;margin-top:2px}' .
            '.mini{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.mini td{width:25%;border:1px solid #D3DCE8;background:#F5F8FC;padding:7px 8px}.mini strong{display:block;font-size:10.5pt;color:#16223B}' .
            '.call-summary{width:100%;table-layout:fixed;border-collapse:collapse;margin-top:6px;border:1px solid #D9E1EB;background:#FFFFFF}.call-summary td{width:25%;padding:7px 8px;border-right:1px solid #D9E1EB}.call-summary td:last-child{border-right:0}.call-summary strong{display:block;font-size:7.2pt}' .
            '.activity-section{page-break-inside:avoid}.line-chart{display:block;width:100%;height:auto;border:1px solid #E5E9EF;background:#FFFFFF;padding:4px}' .
            '.split{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:9px 0}.split td{width:50%;vertical-align:top}.split h3{font-size:7.8pt;margin:0 0 6px;color:#16223B}' .
            '.barrow{width:100%;table-layout:fixed;border-collapse:collapse;margin-bottom:5px}.barlabel{width:37%;font-size:6pt;padding-right:6px}.bararea{width:53%}.barvalue{width:10%;text-align:right;font-weight:700;font-size:6pt}.track{height:6px;background:#E9EDF4;overflow:hidden}.fill{height:6px;background:#273A8A}' .
            '.status{display:inline-block;background:#EDF2FA;color:#273A8A;padding:2px 5px;font-weight:700;font-size:5.5pt}.detail-section .section-title{page-break-after:avoid}' .
            '.operational{width:100%;table-layout:fixed;border-collapse:collapse;background:#F8FAFC;border:1px solid #E5E9EF}.operational td{width:33.33%;padding:8px;border-right:1px solid #E5E9EF}.operational td:last-child{border-right:0}.operational strong{display:block;font-size:7.2pt}' .
            '.empty{border-left:3px solid #E5E9EF;background:#F8FAFC;padding:8px 10px;color:#6D7480;font-size:6.4pt}.empty.compact{padding:6px 8px}.flow-note{margin-top:7px;padding:7px 9px;background:#F8FAFC;border-left:2px solid #D3DCE8;color:#4F5968;font-size:6.3pt;line-height:1.4}.individual-metrics .metric{background:#F7F9FC}.individual-metrics .metric strong{font-size:11.5pt}.profile-table{width:100%;border-collapse:collapse;border:1px solid #D7DFEA}.profile-table td{padding:6px 7px;border-bottom:1px solid #E5E9EF;vertical-align:top}.profile-label{width:16%;background:#F7F9FC;color:#6D7480;font-size:5.8pt}.profile-value{width:34%;font-size:6.6pt;font-weight:600;color:#16223B}.route-progress{height:7px;background:#E7ECF3;margin:2px 2px 8px;overflow:hidden}.route-fill{height:7px;background:#273A8A}.route-milestones{width:100%;table-layout:fixed;border-collapse:collapse}.route-milestones td{text-align:center;color:#8B94A2;font-size:5.4pt;padding:3px 3px;vertical-align:top}.route-milestones .route-number{display:inline-block;min-width:19px;padding:4px 1px 3px;margin:0 auto 4px;border-radius:11px;background:#E7ECF3;color:#6D7480;font-size:6.1pt;line-height:1;text-align:center}.route-milestones td span{display:block}.route-milestones td.done{color:#273A8A;font-weight:700}.route-milestones td.done .route-number{background:#273A8A;color:#FFFFFF}.route-milestones td.current .route-number{background:#0A8F7A;color:#FFFFFF}.documentation-section{page-break-inside:avoid}.history-table th:first-child{width:15%}.history-table th:nth-child(2){width:10%}.history-table th:nth-child(3){width:15%}.history-table th:nth-child(4){width:42%}.history-table th:nth-child(5){width:18%}.docs-table th:first-child{width:16%}.docs-table th:nth-child(2){width:30%}.docs-table th:nth-child(3){width:14%}.docs-table th:nth-child(4),.docs-table th:nth-child(5){width:20%}.formal-timeline{width:100%;border-collapse:collapse;margin-top:7px;background:#F8FAFC}.formal-timeline td{padding:5px 7px;border-bottom:1px solid #E5E9EF;vertical-align:top}.formal-timeline .formal-dot{width:12px;color:#0A8F7A;padding-right:0}.formal-timeline strong{display:block;font-size:6.5pt}.formal-timeline span{display:block;color:#6D7480;font-size:5.8pt;margin-top:1px}.observation{border:1px solid #D7DFEA;background:#FFFFFF;padding:7px 8px;margin-bottom:5px;page-break-inside:avoid}.observation strong{font-size:6.4pt;color:#16223B}.observation span{float:right;color:#8B94A2;font-size:5.5pt}.observation p{margin:4px 0 0;color:#4F5968;font-size:6.2pt;line-height:1.4}' .
            '.page-break{display:none}.individual-page{page-break-inside:auto}.individual-page .report-section{margin-bottom:10px}.individual-page .section-title{margin-bottom:7px}.individual-page .section-title h2{font-size:9.6pt}' .
            '.individual-page-two{padding-top:1px}.continuation-head{border-bottom:2px solid #273A8A;padding:0 0 7px;margin:0 0 10px;page-break-after:avoid}.continuation-head span{display:block;color:#6D7480;font-size:6.1pt;margin-bottom:2px}.continuation-head strong{display:block;color:#16223B;font-size:11pt;font-weight:800}' .
            '.executive-metrics{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.executive-metrics td{width:20%;border:1px solid #D7DFEA;background:#F8FAFC;padding:7px 8px}.executive-metrics span{display:block;color:#6D7480;font-size:5.7pt;margin-bottom:2px}.executive-metrics strong{display:block;color:#16223B;font-size:9.2pt;font-weight:800}.decision-note{margin-top:5px;padding:6px 8px;background:#F8FAFC;border-left:2px solid #273A8A;color:#4F5968;font-size:6pt}' .
            '.route-summary{width:100%;border-collapse:collapse;margin-bottom:4px}.route-summary td{vertical-align:bottom}.route-summary span{display:block;color:#6D7480;font-size:5.6pt}.route-summary strong{display:block;color:#16223B;font-size:7.2pt}.route-summary .route-percent{text-align:right;color:#273A8A;font-size:11.5pt;font-weight:800}.route-executive{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0;margin-top:6px}.route-executive td{width:20%;padding:6px;border:1px solid #D7DFEA;vertical-align:top}.route-executive .route-check{display:block;width:16px;height:16px;margin:0 auto 3px;border-radius:50%;background:#E7ECF3;color:#6D7480;font-size:6.8pt;font-weight:700;text-align:center;line-height:14px;overflow:hidden;vertical-align:middle}.route-executive td.done .route-check{background:#E5F5F1;color:#0A8F7A}.route-executive td.current .route-check{background:#EDF2FA;color:#273A8A}.route-executive strong{display:block;color:#16223B;font-size:6pt;line-height:1.25}.route-executive small{display:block;color:#6D7480;font-size:5.1pt;line-height:1.25;margin-top:2px}' .
            '.meeting-executive,.documentation-executive,.individual-contact{page-break-inside:avoid}.meeting-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.meeting-grid td{width:50%;padding:7px 8px;border:1px solid #D7DFEA;background:#F8FAFC;vertical-align:top}.meeting-grid span,.meeting-note span,.document-summary span,.formal-answer span,.email-title{display:block;color:#6D7480;font-size:5.7pt;margin-bottom:2px}.meeting-grid strong,.document-summary strong,.formal-answer strong{display:block;color:#16223B;font-size:7pt}.meeting-grid small,.document-summary small,.formal-answer small{display:block;color:#6D7480;font-size:5.4pt;margin-top:2px}.meeting-note{margin-top:5px;padding:6px 8px;border:1px solid #E5E9EF}.meeting-note.key{background:#F8FAFC;border-left:2px solid #0A8F7A}.meeting-note p,.formal-answer p{margin:2px 0 0;color:#4F5968;font-size:5.9pt;line-height:1.35}' .
            '.document-summary{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0;margin-bottom:5px}.document-summary td{width:50%;padding:7px 8px;border:1px solid #D7DFEA;background:#F8FAFC;vertical-align:top}.formal-answer{padding:6px 8px;border:1px solid #E5E9EF;margin-bottom:5px}.email-block{padding-top:2px}.email-title{font-weight:700;color:#16223B;margin-bottom:3px}.email-row{display:table;width:100%;table-layout:fixed;border-top:1px solid #E5E9EF}.email-row>span,.email-row>div{display:table-cell;vertical-align:top;padding:4px 5px}.email-row>span{width:24%;color:#6D7480;font-size:5.4pt}.email-row>div{width:76%}.email-row>div strong{display:block;color:#16223B;font-size:5.8pt;font-weight:700}.email-row>div small{display:block;color:#6D7480;font-size:5.2pt;line-height:1.3;margin-top:1px}.milestone-line{display:table;width:100%;table-layout:fixed;margin-top:5px;background:#F8FAFC}.milestone-item{display:table-cell;width:33.33%;padding:5px 6px;vertical-align:top;border-right:1px solid #E5E9EF}.milestone-item:last-child{border-right:0}.milestone-item .formal-dot{display:inline-block;color:#0A8F7A;margin-right:4px}.milestone-item>div{display:inline-block;vertical-align:top;max-width:88%}.milestone-item strong{display:block;font-size:5.8pt;color:#16223B}.milestone-item small{display:block;font-size:5.1pt;color:#6D7480;margin-top:1px}' .
            '.daily-activity{page-break-inside:avoid}.daily-chart-caption{display:table;width:100%;margin-bottom:4px}.daily-chart-caption strong,.daily-chart-caption span{display:table-cell;vertical-align:bottom}.daily-chart-caption strong{width:32%;color:#16223B;font-size:6.2pt}.daily-chart-caption span{color:#6D7480;font-size:5.5pt;text-align:right}.daily-chart{display:block;width:100%;height:auto;border:1px solid #E5E9EF;background:#FFFFFF;padding:3px}.individual-page-two{page-break-inside:auto}.continuation-head{display:none}' .
            '.individual-page-two .history-table{font-size:5.7pt}.individual-page-two .history-table th,.individual-page-two .history-table td{padding:4.5px 5px}.individual-page-two .history-table th:first-child{width:15%}.individual-page-two .history-table th:nth-child(2){width:15%}.individual-page-two .history-table th:nth-child(3){width:17%}.individual-page-two .history-table th:nth-child(4){width:35%}.individual-page-two .history-table th:nth-child(5){width:18%}' .
            '.activity-pdf-page-break{page-break-before:always;height:0;line-height:0;margin:0;padding:0}' .
            '.activity-scope td{width:25%}.activity-scope strong{font-size:6.8pt}' .
            '.activity-executive .executive-metrics td{background:#F8FAFC}.activity-executive .executive-metrics td:last-child{background:#F0FAF8;border-color:#B9E2DA}.activity-executive .executive-metrics td:last-child strong{color:#087966}' .
            '.activity-phone-section,.activity-composition,.activity-evolution-section{page-break-inside:avoid}' .
            '.activity-phone-table{margin-top:6px;font-size:6pt}.activity-phone-table th:first-child{width:30%}.activity-phone-table th:nth-child(2),.activity-phone-table th:nth-child(3),.activity-phone-table th:nth-child(4){width:14%}.activity-phone-table th:nth-child(5){width:28%}.activity-phone-table td{padding:5px 7px}.activity-effective{color:#087966;font-weight:800}' .
            '.activity-channel-summary{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.activity-channel-summary td{width:25%;padding:7px 8px;border:1px solid #D7DFEA;background:#F8FAFC}.activity-channel-summary span{display:block;color:#6D7480;font-size:5.7pt;margin-bottom:2px}.activity-channel-summary strong{display:block;color:#16223B;font-size:9.2pt;font-weight:800}.activity-call-summary{margin-top:5px}' .
            '.activity-evolution-summary{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0;margin-bottom:6px}.activity-evolution-summary td{width:25%;padding:7px 8px;border:1px solid #D7DFEA;background:#F8FAFC;vertical-align:top}.activity-evolution-summary span{display:block;color:#6D7480;font-size:5.6pt;margin-bottom:2px}.activity-evolution-summary strong{display:block;color:#16223B;font-size:8pt;font-weight:800}.activity-evolution-summary small{display:block;color:#6D7480;font-size:5.2pt;margin-top:2px}.activity-line-chart{margin-top:3px}' .
            '.activity-coverage-section{page-break-inside:avoid}.activity-coverage-table{font-size:5.9pt}.activity-coverage-table th:first-child{width:34%}.activity-coverage-table th:nth-child(2){width:17%}.activity-coverage-table th:nth-child(3){width:13%}.activity-coverage-table th:nth-child(4),.activity-coverage-table th:nth-child(5),.activity-coverage-table th:nth-child(6){width:12%}' .
            '.activity-detail-section{page-break-inside:auto}.activity-detail-section .section-title{page-break-after:avoid}.activity-detail-table{font-size:5.75pt}.activity-detail-table th:first-child{width:14%}.activity-detail-table th:nth-child(2){width:25%}.activity-detail-table th:nth-child(3){width:12%}.activity-detail-table th:nth-child(4){width:16%}.activity-detail-table th:nth-child(5){width:33%}.activity-detail-table td{padding:5px 6px}.activity-status{display:inline-block;padding:2px 5px;border:1px solid #D7DFEA;background:#F8FAFC;color:#16223B;font-size:5.3pt;font-weight:700}.activity-status-contact{background:#EDF2FA;border-color:#D4DEF1;color:#273A8A}.activity-status-effective{background:#EAF7F4;border-color:#B9E2DA;color:#087966}' .
            '.activity-detail-table thead{display:table-header-group}.activity-detail-table tr{page-break-inside:avoid}';
    }

}

