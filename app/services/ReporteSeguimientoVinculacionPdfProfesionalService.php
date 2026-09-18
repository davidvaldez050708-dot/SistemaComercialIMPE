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

        $total = (int)($resumen['total'] ?? count($seguimientos));
        if ($total <= 0) {
            $html .= '<section class="report-section keep">' . $this->titulo('Resultado de la consulta');
            $html .= $this->vacio('No se encontraron seguimientos con los criterios seleccionados.');
            return $html . '</section></body></html>';
        }

        if ($individual) {
            $html .= $this->ficha($seguimientos[0], $flujo, $detalleInstitucion);
            $html .= $this->resumenIndividual($analitica, $detalleInstitucion);
            $html .= $this->contactoInstitucional($detalleInstitucion);
            $html .= $this->rutaIndividual($flujo);
            $html .= $this->contacto($analitica);
            $html .= $this->actividad($evolucion);
            $html .= $this->historialIndividual($detalleInstitucion);
            $html .= $this->reunionesIndividual($detalleInstitucion);
            $html .= $this->documentacionIndividual($detalleInstitucion);
            $html .= $this->observacionesIndividual($detalleInstitucion);
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
        $html .= $this->contacto($analitica);
        $html .= $this->actividad($evolucion);
        $html .= $this->distribuciones($resumen, $etiquetas);
        $html .= $this->detalle($seguimientos);

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
            $accion = trim((string)($flujo['titulo'] ?? $s['proxima_accion_label'] ?? '—'));
        }

        $pasoActual = max(1, (int)($flujo['paso_actual'] ?? 1));
        $totalPasos = max($pasoActual, (int)($flujo['total_pasos'] ?? 13));
        $paso = 'Paso ' . $pasoActual . ' de ' . $totalPasos;

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
        $html .= $this->info('Acción actual', $accion, true);
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

        $html = '<section class="report-section keep">' . $this->titulo('Panorama de la relación');
        $html .= '<table class="metrics individual-metrics"><tr>';
        $html .= $this->metric('Interacciones registradas', (string)(int)($analitica['interacciones'] ?? 0));
        $html .= $this->metric('Último contacto', $fechaUltima !== '' ? $this->fechaSoloDia($fechaUltima) : '—');
        $html .= $this->metric('Días sin contacto', $dias === null ? '—' : (string)$dias);
        $html .= $this->metric('Tasa de contacto', $this->decimal($llamadas['tasa_contacto'] ?? 0, 1) . '%');
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
        return $html . '</table></section>';
    }

    private function rutaIndividual(array $flujo): string
    {
        $actual = max(1, min(13, (int)($flujo['paso_actual'] ?? 1)));
        $porcentaje = round(($actual / 13) * 100, 1);
        $milestones = [
            [2, 'Investigación'],
            [4, 'Verificación'],
            [7, 'Envío'],
            [9, 'Respuesta'],
            [12, 'Reunión'],
            [13, 'Convenio']
        ];

        $html = '<section class="report-section keep">' . $this->titulo('Ruta de vinculación');
        $html .= '<div class="route-progress"><div class="route-fill" style="width:' .
            number_format($porcentaje, 1, '.', '') . '%"></div></div>';
        $html .= '<table class="route-milestones"><tr>';
        foreach ($milestones as $milestone) {
            $estado = $actual >= $milestone[0] ? ' done' : '';
            if ($actual === $milestone[0]) {
                $estado .= ' current';
            }
            $html .= '<td class="' . trim($estado) . '"><strong>' . $milestone[0] .
                '</strong><span>' . $this->e($milestone[1]) . '</span></td>';
        }
        $html .= '</tr></table>';
        return $html . '</section>';
    }

    private function historialIndividual(array $detalle): string
    {
        $interacciones = is_array($detalle['interacciones_recientes'] ?? null)
            ? $detalle['interacciones_recientes']
            : [];

        if (empty($interacciones)) {
            return '';
        }

        $html = '<section class="report-section history-section">' . $this->titulo('Historial reciente de contacto');
        $html .= '<table class="data-table history-table"><thead><tr>';
        $html .= '<th>Fecha</th><th>Canal</th><th>Resultado</th><th>Registro</th><th>Responsable</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($interacciones as $interaccion) {
            $responsable = trim(
                (string)($interaccion['nombre'] ?? '') . ' ' .
                (string)($interaccion['apellidos'] ?? '')
            );
            $html .= '<tr><td>' . $this->e($this->fechaDato((string)($interaccion['fecha_inicio'] ?? ''))) . '</td>';
            $html .= '<td>' . $this->e($this->canalLabel((string)($interaccion['canal'] ?? ''))) . '</td>';
            $html .= '<td>' . $this->e($this->resultadoLabel((string)($interaccion['resultado'] ?? ''))) . '</td>';
            $html .= '<td>' . $this->e($this->resumirTexto((string)($interaccion['notas'] ?? ''), 145)) . '</td>';
            $html .= '<td>' . $this->e($responsable !== '' ? $responsable : '—') . '</td></tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function reunionesIndividual(array $detalle): string
    {
        $reuniones = is_array($detalle['reuniones'] ?? null) ? $detalle['reuniones'] : [];
        $post = is_array($detalle['post_envio'] ?? null) ? $detalle['post_envio'] : [];

        if (empty($reuniones) && trim((string)($post['reunion_fecha'] ?? '')) === '') {
            return '';
        }

        $html = '<section class="report-section">' . $this->titulo('Reuniones y acuerdos');
        $html .= '<table class="data-table"><thead><tr><th>Fecha</th><th>Modalidad</th><th>Estado / resultado</th><th>Objetivo o acuerdo</th><th>Cuenta Clave</th></tr></thead><tbody>';

        if (!empty($reuniones)) {
            foreach (array_slice($reuniones, 0, 4) as $reunion) {
                $notas = trim((string)($reunion['objetivo'] ?? ''));
                if ($notas === '') {
                    $notas = trim((string)($reunion['notas_kam'] ?? $reunion['notas_analista'] ?? ''));
                }
                $html .= '<tr><td>' . $this->e($this->fechaDato((string)($reunion['fecha_propuesta'] ?? ''))) . '</td>';
                $html .= '<td>' . $this->e($this->modalidadLabel((string)($reunion['modalidad'] ?? ''))) . '</td>';
                $html .= '<td>' . $this->e($this->estadoReunionLabel((string)($reunion['estado'] ?? ''))) . '</td>';
                $html .= '<td>' . $this->e($this->resumirTexto($notas, 125)) . '</td>';
                $html .= '<td>' . $this->e(trim((string)($reunion['cuenta_clave_nombre'] ?? '')) ?: '—') . '</td></tr>';
            }
        } else {
            $resultado = trim((string)($post['reunion_resultado'] ?? ''));
            $detalleResultado = trim((string)($post['reunion_resultado_notas'] ?? $post['reunion_notas'] ?? ''));
            $html .= '<tr><td>' . $this->e($this->fechaDato((string)($post['reunion_fecha'] ?? ''))) . '</td>';
            $html .= '<td>' . $this->e($this->modalidadLabel((string)($post['reunion_modalidad'] ?? ''))) . '</td>';
            $html .= '<td>' . $this->e($this->resultadoReunionLabel($resultado)) . '</td>';
            $html .= '<td>' . $this->e($this->resumirTexto($detalleResultado, 125)) . '</td><td>—</td></tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function documentacionIndividual(array $detalle): string
    {
        $oficios = is_array($detalle['oficios'] ?? null) ? $detalle['oficios'] : [];
        $post = is_array($detalle['post_envio'] ?? null) ? $detalle['post_envio'] : [];

        $hayPost = trim((string)($post['respuesta_at'] ?? '')) !== '' ||
            trim((string)($post['seguimiento_correo_at'] ?? '')) !== '' ||
            trim((string)($post['convenio_formalizado_at'] ?? '')) !== '';

        if (empty($oficios) && !$hayPost) {
            return '';
        }

        $html = '<section class="report-section">' . $this->titulo('Documentación y avance formal');

        if (!empty($oficios)) {
            $html .= '<table class="data-table docs-table"><thead><tr><th>Documento</th><th>Destinatario</th><th>Estado</th><th>Generado</th><th>Enviado</th></tr></thead><tbody>';
            foreach (array_slice($oficios, 0, 3) as $oficio) {
                $destinatario = trim((string)($oficio['destinatario_nombre'] ?? ''));
                $cargo = trim((string)($oficio['destinatario_cargo'] ?? ''));
                if ($cargo !== '') {
                    $destinatario .= ($destinatario !== '' ? ' · ' : '') . $cargo;
                }
                $html .= '<tr><td><strong>' . $this->e(trim((string)($oficio['folio'] ?? 'Oficio'))) . '</strong></td>';
                $html .= '<td>' . $this->e($destinatario !== '' ? $destinatario : '—') . '</td>';
                $html .= '<td>' . $this->e($this->estadoOficioLabel((string)($oficio['estado_oficio'] ?? ''))) . '</td>';
                $html .= '<td>' . $this->e($this->fechaDato((string)($oficio['fecha_generacion'] ?? ''))) . '</td>';
                $html .= '<td>' . $this->e($this->fechaDato((string)($oficio['fecha_envio'] ?? ''))) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $timeline = [];
        if (trim((string)($post['respuesta_at'] ?? '')) !== '') {
            $timeline[] = ['Respuesta recibida', $this->fechaDato((string)$post['respuesta_at']), $this->respuestaTipoLabel((string)($post['respuesta_tipo'] ?? ''))];
        }
        if (trim((string)($post['seguimiento_correo_at'] ?? '')) !== '') {
            $timeline[] = ['Seguimiento por correo', $this->fechaDato((string)$post['seguimiento_correo_at']), $this->resumirTexto((string)($post['seguimiento_correo_notas'] ?? ''), 110)];
        }
        if (trim((string)($post['reunion_realizada_at'] ?? '')) !== '') {
            $timeline[] = ['Reunión realizada', $this->fechaDato((string)$post['reunion_realizada_at']), $this->resultadoReunionLabel((string)($post['reunion_resultado'] ?? ''))];
        }
        if (trim((string)($post['convenio_formalizado_at'] ?? '')) !== '') {
            $detalleConvenio = trim((string)($post['convenio_referencia'] ?? ''));
            if (trim((string)($post['convenio_fecha'] ?? '')) !== '') {
                $detalleConvenio .= ($detalleConvenio !== '' ? ' · ' : '') . $this->fechaSoloDia((string)$post['convenio_fecha']);
            }
            $timeline[] = ['Convenio formalizado', $this->fechaDato((string)$post['convenio_formalizado_at']), $detalleConvenio];
        }

        if (!empty($timeline)) {
            $html .= '<table class="formal-timeline">';
            foreach ($timeline as $evento) {
                $html .= '<tr><td class="formal-dot">●</td><td><strong>' . $this->e($evento[0]) . '</strong>';
                $html .= '<span>' . $this->e($evento[1]) . ($evento[2] !== '' ? ' · ' . $evento[2] : '') . '</span></td></tr>';
            }
            $html .= '</table>';
        }

        return $html . '</section>';
    }

    private function observacionesIndividual(array $detalle): string
    {
        $observaciones = is_array($detalle['observaciones'] ?? null) ? $detalle['observaciones'] : [];
        if (empty($observaciones)) {
            return '';
        }

        $html = '<section class="report-section observations-section">' . $this->titulo('Observaciones recientes');
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
            'SIN_RESPUESTA' => 'Sin respuesta',
            'NUMERO_INCORRECTO' => 'Número incorrecto',
            'SOLICITO_LLAMAR_DESPUES' => 'Volver a llamar',
            'MENSAJE_ENVIADO' => 'Mensaje enviado',
            'CORREO_ENVIADO' => 'Correo enviado',
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
            '.scope strong{font-size:7.1pt;color:#16223B}.scope-total{width:92px;text-align:left}.scope-total strong{font-size:9.5pt;color:#273A8A}' .
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
            '.empty{border-left:3px solid #E5E9EF;background:#F8FAFC;padding:8px 10px;color:#6D7480;font-size:6.4pt}.empty.compact{padding:6px 8px}.flow-note{margin-top:7px;padding:7px 9px;background:#F8FAFC;border-left:2px solid #D3DCE8;color:#4F5968;font-size:6.3pt;line-height:1.4}.individual-metrics .metric{background:#F7F9FC}.individual-metrics .metric strong{font-size:11.5pt}.profile-table{width:100%;border-collapse:collapse;border:1px solid #D7DFEA}.profile-table td{padding:6px 7px;border-bottom:1px solid #E5E9EF;vertical-align:top}.profile-label{width:16%;background:#F7F9FC;color:#6D7480;font-size:5.8pt}.profile-value{width:34%;font-size:6.6pt;font-weight:600;color:#16223B}.route-progress{height:7px;background:#E7ECF3;margin:2px 2px 8px;overflow:hidden}.route-fill{height:7px;background:#273A8A}.route-milestones{width:100%;table-layout:fixed;border-collapse:collapse}.route-milestones td{text-align:center;color:#8B94A2;font-size:5.4pt;padding:2px 3px}.route-milestones td strong{display:block;width:15px;height:15px;line-height:15px;margin:0 auto 3px;border-radius:50%;background:#E7ECF3;color:#6D7480;font-size:5.5pt}.route-milestones td.done{color:#273A8A;font-weight:700}.route-milestones td.done strong{background:#273A8A;color:#FFFFFF}.route-milestones td.current strong{background:#0A8F7A}.history-table th:first-child{width:15%}.history-table th:nth-child(2){width:10%}.history-table th:nth-child(3){width:15%}.history-table th:nth-child(4){width:42%}.history-table th:nth-child(5){width:18%}.docs-table th:first-child{width:16%}.docs-table th:nth-child(2){width:30%}.docs-table th:nth-child(3){width:14%}.docs-table th:nth-child(4),.docs-table th:nth-child(5){width:20%}.formal-timeline{width:100%;border-collapse:collapse;margin-top:7px;background:#F8FAFC}.formal-timeline td{padding:5px 7px;border-bottom:1px solid #E5E9EF;vertical-align:top}.formal-timeline .formal-dot{width:12px;color:#0A8F7A;padding-right:0}.formal-timeline strong{display:block;font-size:6.5pt}.formal-timeline span{display:block;color:#6D7480;font-size:5.8pt;margin-top:1px}.observation{border:1px solid #D7DFEA;background:#FFFFFF;padding:7px 8px;margin-bottom:5px;page-break-inside:avoid}.observation strong{font-size:6.4pt;color:#16223B}.observation span{float:right;color:#8B94A2;font-size:5.5pt}.observation p{margin:4px 0 0;color:#4F5968;font-size:6.2pt;line-height:1.4}';
    }

}
