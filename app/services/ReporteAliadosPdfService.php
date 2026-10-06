<?php

require_once __DIR__ . '/ReporteNombreArchivoService.php';

use Dompdf\Dompdf;
use Dompdf\Options;

class ReporteAliadosPdfService
{
    public function generar(array $reporte): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        if (!is_file($autoload)) {
            return $this->error(
                'No fue posible preparar el PDF del reporte de aliados.',
                'No se encontró vendor/autoload.php.'
            );
        }

        require_once $autoload;

        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            return $this->error(
                'No fue posible preparar el PDF del reporte de aliados.',
                'Dompdf no está disponible en el entorno.'
            );
        }

        try {
            $options = new Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->construirHtml($reporte), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $canvas = $dompdf->getCanvas();
            $fontMetrics = $dompdf->getFontMetrics();
            $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
            $bold = $fontMetrics->getFont('DejaVu Sans', 'bold');

            $canvas->page_script(
                static function (
                    $pageNumber,
                    $pageCount,
                    $canvas,
                    $fontMetrics
                ) use ($font, $bold): void {
                    $y = $canvas->get_height() - 24;
                    $canvas->line(
                        46,
                        $y - 7,
                        $canvas->get_width() - 46,
                        $y - 7,
                        [0.90, 0.91, 0.94],
                        0.5
                    );

                    $canvas->text(
                        46,
                        $y,
                        'Grupo Porcayo - Sistema de Gestión Comercial',
                        $bold,
                        6.4,
                        [0.15, 0.23, 0.54]
                    );

                    $pagina =
                        'Página ' . $pageNumber . ' de ' . $pageCount;
                    $ancho = $fontMetrics->getTextWidth(
                        $pagina,
                        $font,
                        6.4
                    );

                    $canvas->text(
                        $canvas->get_width() - 46 - $ancho,
                        $y,
                        $pagina,
                        $font,
                        6.4,
                        [0.43, 0.45, 0.50]
                    );
                }
            );

            return [
                'ok' => true,
                'contenido_pdf' => $dompdf->output(),
                'nombre_archivo' => $this->nombreArchivo($reporte)
            ];
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible generar el PDF del reporte de aliados.',
                $error->getMessage()
            );
        }
    }

    private function construirHtml(array $reporte): string
    {
        $resumen = is_array($reporte['resumen'] ?? null)
            ? $reporte['resumen']
            : [];
        $porEstado = is_array($reporte['por_estado'] ?? null)
            ? $reporte['por_estado']
            : [];
        $porMunicipio = is_array($reporte['por_municipio'] ?? null)
            ? $reporte['por_municipio']
            : [];
        $atencion = is_array($reporte['atencion'] ?? null)
            ? $reporte['atencion']
            : [];
        $detalle = is_array($reporte['detalle'] ?? null)
            ? $reporte['detalle']
            : [];
        $hallazgos = is_array($reporte['hallazgos'] ?? null)
            ? $reporte['hallazgos']
            : [];
        $filtros = is_array($reporte['filtros'] ?? null)
            ? $reporte['filtros']
            : [];
        $periodo = is_array($reporte['periodo'] ?? null)
            ? $reporte['periodo']
            : [
                'label' => 'Histórico completo',
                'fecha_desde' => '',
                'fecha_hasta' => ''
            ];
        $actividadPeriodo = is_array(
            $reporte['actividad_periodo'] ?? null
        )
            ? $reporte['actividad_periodo']
            : [];
        $modo = (string)($reporte['modo'] ?? 'red');

        $estadoNombre = trim(
            (string)($reporte['estado_nombre'] ?? '')
        );
        $municipioNombre = trim(
            (string)($reporte['municipio_nombre'] ?? '')
        );
        $situacion = trim(
            (string)($reporte['situacion_label'] ?? 'Todos los aliados')
        );
        $generadoPor = trim(
            (string)($reporte['generado_por'] ?? '')
        );
        $generadoPorRol = trim(
            (string)($reporte['generado_por_rol'] ?? '')
        );
        $fechaGeneracion = trim(
            (string)($reporte['fecha_generacion'] ?? '')
        );

        $logo = $this->logoDataUri();
        $tituloAlcance = $this->tituloAlcance(
            $modo,
            $estadoNombre,
            $municipioNombre
        );

        $html =
            '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">' .
            '<style>' . $this->css() . '</style></head><body>';

        $html .= '<div class="top-rule"></div>';
        $html .= '<table class="header"><tr>';
        $html .= '<td class="brand">';
        if ($logo !== '') {
            $html .= '<img src="' . $logo . '" alt="Grupo Porcayo">';
        }
        $html .= '</td>';
        $html .= '<td class="header-copy">';
        $html .= '<div class="system-name">Sistema de Gestión Comercial</div>';
        $html .= '<h1>Reporte de Aliados</h1>';
        $html .= '<table class="header-meta">';
        if ($generadoPor !== '') {
            $html .=
                '<tr><td>Generado por</td><th>' .
                $this->e($generadoPor) .
                '</th></tr>';
        }
        if ($generadoPorRol !== '') {
            $html .=
                '<tr><td>Rol</td><th>' .
                $this->e($generadoPorRol) .
                '</th></tr>';
        }
        $html .=
            '<tr><td>Fecha</td><th>' .
            $this->e(
                $fechaGeneracion !== ''
                    ? $fechaGeneracion
                    : date('d/m/Y H:i')
            ) .
            '</th></tr>';
        $html .=
            '<tr><td>Nivel</td><th>' .
            $this->e($this->etiquetaModo($modo)) .
            '</th></tr>';
        $html .= '</table></td></tr></table>';
        $html .= '<div class="header-rule"></div>';

        $html .= '<table class="scope"><tr>';
        $html .= $this->scopeCell('Alcance', $tituloAlcance);
        $html .= $this->scopeCell('Situación', $situacion);
        $html .= $this->scopeCell(
            'Periodo analizado',
            $this->etiquetaPeriodo($periodo)
        );
        $html .= $this->scopeCell(
            'Aliados incluidos',
            (string)((int)($resumen['total'] ?? 0))
        );
        $html .= '</tr></table>';

        $html .= '<section class="report-section keep">';
        $html .= $this->sectionTitle('Resumen ejecutivo');
        $html .= '<table class="metrics five"><tr>';

        if ($modo === 'red') {
            $html .= $this->metric(
                'Aliados',
                $this->numero($resumen['total'] ?? 0),
                'red supervisada'
            );
            $html .= $this->metric(
                'Estados',
                $this->numero($resumen['estados'] ?? 0),
                'con aliados'
            );
            $html .= $this->metric(
                'Municipios',
                $this->numero($resumen['municipios'] ?? 0),
                'con cobertura'
            );
        } elseif ($modo === 'estado') {
            $html .= $this->metric(
                'Aliados',
                $this->numero($resumen['total'] ?? 0),
                'en el estado'
            );
            $html .= $this->metric(
                'Municipios',
                $this->numero($resumen['municipios'] ?? 0),
                'con aliados'
            );
            $html .= $this->metric(
                'Con difusión',
                $this->numero($resumen['con_difusion'] ?? 0),
                $this->decimal(
                    $resumen['cobertura_difusion'] ?? 0,
                    1
                ) . '%'
            );
        } else {
            $html .= $this->metric(
                'Aliados',
                $this->numero($resumen['total'] ?? 0),
                'en el municipio'
            );
            $html .= $this->metric(
                'Con difusión',
                $this->numero($resumen['con_difusion'] ?? 0),
                $this->decimal(
                    $resumen['cobertura_difusion'] ?? 0,
                    1
                ) . '%'
            );
            $html .= $this->metric(
                'WhatsApp',
                $this->numero($resumen['con_whatsapp'] ?? 0),
                $this->decimal(
                    $resumen['cobertura_whatsapp'] ?? 0,
                    1
                ) . '% disponible'
            );
        }

        $html .= $this->metric(
            'Difusión confirmada',
            $this->numero($resumen['difusion_confirmada'] ?? 0),
            $this->decimal(
                $resumen['tasa_confirmacion'] ?? 0,
                1
            ) . '% de difundidos'
        );
        $html .= $this->metric(
            'Requieren atención',
            $this->numero($resumen['requieren_atencion'] ?? 0),
            'prioridad operativa'
        );
        $html .= '</tr></table></section>';

        $html .= '<section class="report-section keep">';
        $html .= $this->sectionTitle('Actividad del periodo');
        $html .=
            '<p class="section-note">Mide lo ocurrido dentro de ' .
            $this->e(
                strtolower(
                    (string)($periodo['label'] ?? 'el periodo')
                )
            ) .
            ', sin confundirlo con el estado actual de la red.</p>';
        $html .= '<table class="metrics five"><tr>';
        $html .= $this->metric(
            'Nuevos aliados',
            $this->numero(
                $actividadPeriodo['nuevos_aliados'] ?? 0
            ),
            'formalizados'
        );
        $html .= $this->metric(
            'Aliados trabajados',
            $this->numero(
                $actividadPeriodo['aliados_trabajados'] ?? 0
            ),
            'con actividad'
        );
        $html .= $this->metric(
            'Difusiones',
            $this->numero(
                $actividadPeriodo['difusiones'] ?? 0
            ),
            'convocatorias compartidas'
        );
        $html .= $this->metric(
            'Actualizaciones',
            $this->numero(
                $actividadPeriodo['seguimientos'] ?? 0
            ),
            'de seguimiento'
        );
        $html .= $this->metric(
            'Confirmaciones',
            $this->numero(
                $actividadPeriodo['confirmaciones'] ?? 0
            ),
            'difusión confirmada'
        );
        $html .= '</tr></table></section>';

        if ($modo === 'red') {
            $html .= $this->tablaEstados($porEstado);
        } elseif ($modo === 'estado') {
            $html .= $this->tablaMunicipios($porMunicipio);
        } else {
            $html .= '<section class="report-section keep">';
            $html .= $this->sectionTitle('Contactabilidad del municipio');
            $html .= '<table class="metrics four"><tr>';
            $html .= $this->metric(
                'WhatsApp disponible',
                $this->numero($resumen['con_whatsapp'] ?? 0),
                $this->decimal(
                    $resumen['cobertura_whatsapp'] ?? 0,
                    1
                ) . '%'
            );
            $html .= $this->metric(
                'Correo disponible',
                $this->numero($resumen['con_correo'] ?? 0),
                'contacto registrado'
            );
            $html .= $this->metric(
                'Sin respuesta',
                $this->numero($resumen['sin_respuesta'] ?? 0),
                'requieren continuidad'
            );
            $html .= $this->metric(
                'Solicita información',
                $this->numero($resumen['solicita_informacion'] ?? 0),
                'respuesta pendiente'
            );
            $html .= '</tr></table></section>';
        }

        $html .= '<section class="report-section">';
        $html .= $this->sectionTitle('Atención operativa');
        if (empty($atencion)) {
            $html .= $this->emptyBlock(
                'No hay aliados que requieran una acción prioritaria con el alcance actual.'
            );
        } else {
            $html .=
                '<table class="data-table"><thead><tr>' .
                '<th>Aliado</th><th>Territorio</th><th>Situación</th>' .
                '<th>Convocatoria</th><th>Acción recomendada</th>' .
                '<th>Fecha</th></tr></thead><tbody>';

            foreach ($atencion as $fila) {
                $territorio = trim(
                    (string)($fila['municipio'] ?? '')
                );
                if ($modo === 'red') {
                    $estado = trim(
                        (string)($fila['estado'] ?? '')
                    );
                    if ($estado !== '') {
                        $territorio .=
                            ($territorio !== '' ? ' / ' : '') .
                            $estado;
                    }
                }

                $html .= '<tr>';
                $html .=
                    '<td><strong>' .
                    $this->e($fila['institucion'] ?? 'Institución') .
                    '</strong></td>';
                $html .=
                    '<td>' .
                    $this->e($territorio !== '' ? $territorio : '-') .
                    '</td>';
                $html .=
                    '<td><span class="status">' .
                    $this->e($fila['etiqueta'] ?? '-') .
                    '</span></td>';
                $html .=
                    '<td>' .
                    $this->e(
                        trim((string)($fila['convocatoria'] ?? '')) !== ''
                            ? $fila['convocatoria']
                            : 'Sin difusión registrada'
                    ) .
                    '</td>';
                $html .=
                    '<td>' .
                    $this->e($fila['accion'] ?? '-') .
                    '</td>';
                $html .=
                    '<td>' .
                    $this->e(
                        $this->fecha(
                            (string)($fila['proximo_seguimiento_at'] ?? ''),
                            true
                        )
                    ) .
                    '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }
        $html .= '</section>';

        $html .= '<section class="report-section keep">';
        $html .= $this->sectionTitle('Situación del seguimiento');
        $html .= '<table class="status-grid"><tr>';
        $html .= $this->statusMetric(
            'Difusión confirmada',
            $resumen['difusion_confirmada'] ?? 0
        );
        $html .= $this->statusMetric(
            'Esperando respuesta',
            $resumen['esperando_respuesta'] ?? 0
        );
        $html .= $this->statusMetric(
            'Sin respuesta',
            $resumen['sin_respuesta'] ?? 0
        );
        $html .= '</tr><tr>';
        $html .= $this->statusMetric(
            'Solicita información',
            $resumen['solicita_informacion'] ?? 0
        );
        $html .= $this->statusMetric(
            'No participará',
            $resumen['no_participara'] ?? 0
        );
        $html .= $this->statusMetric(
            'Sin seguimiento',
            $resumen['sin_seguimiento'] ?? 0
        );
        $html .= '</tr></table></section>';

        $html .= '<section class="report-section keep">';
        $html .= $this->sectionTitle('Lectura ejecutiva');
        if (empty($hallazgos)) {
            $html .= $this->emptyBlock(
                'No hay hallazgos disponibles para el alcance seleccionado.'
            );
        } else {
            $html .= '<div class="insights">';
            foreach ($hallazgos as $indice => $hallazgo) {
                $html .=
                    '<div class="insight"><span>' .
                    ($indice + 1) .
                    '</span><p>' .
                    $this->e($hallazgo) .
                    '</p></div>';
            }
            $html .= '</div>';
        }
        $html .= '</section>';

        $html .= '<section class="report-section">';
        $html .= $this->sectionTitle('Detalle de aliados');
        $html .=
            '<p class="section-note">El PDF amplía la vista del sistema con datos de contacto, situación actual y una cronología de la actividad registrada dentro del periodo analizado.</p>';

        if (empty($detalle)) {
            $html .= $this->emptyBlock(
                'No hay aliados que coincidan con los filtros seleccionados.'
            );
        } else {
            foreach ($detalle as $indice => $fila) {
                $html .= $this->aliadoCard(
                    $fila,
                    $indice + 1,
                    $modo
                );
            }
        }

        $html .= '</section>';
        $html .= '</body></html>';

        return $html;
    }

    private function tablaEstados(array $filas): string
    {
        $html = '<section class="report-section">';
        $html .= $this->sectionTitle('Comparativo territorial por estado');
        $html .=
            '<p class="section-note">Combina el tamaño actual de la red con la actividad registrada en el periodo seleccionado.</p>';

        if (empty($filas)) {
            return $html .
                $this->emptyBlock(
                    'No hay estados con aliados para el alcance seleccionado.'
                ) .
                '</section>';
        }

        $html .=
            '<table class="data-table"><thead><tr>' .
            '<th>Estado</th><th class="num">Red actual</th>' .
            '<th class="num">Nuevos</th>' .
            '<th class="num">Trabajados</th>' .
            '<th class="num">Difusiones</th>' .
            '<th class="num">Confirmaciones</th>' .
            '<th class="num">Pendientes</th>' .
            '</tr></thead><tbody>';

        foreach ($filas as $fila) {
            $html .= '<tr>';
            $html .=
                '<td><strong>' .
                $this->e($fila['estado'] ?? '-') .
                '</strong><small>' .
                $this->decimal(
                    $fila['cobertura_difusion'] ?? 0,
                    1
                ) .
                '% con difusión actual</small></td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['aliados'] ?? 0) .
                '</td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['nuevos_periodo'] ?? 0) .
                '</td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['trabajados_periodo'] ?? 0) .
                '</td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['difusiones_periodo'] ?? 0) .
                '</td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['confirmaciones_periodo'] ?? 0) .
                '</td>';
            $pendientes = $this->numero($fila['pendientes'] ?? 0);
            $vencidos = (int)($fila['vencidos'] ?? 0);
            $html .=
                '<td class="num">' .
                $pendientes .
                ($vencidos > 0
                    ? '<small>' . $vencidos . ' vencido(s)</small>'
                    : '') .
                '</td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function tablaMunicipios(array $filas): string
    {
        $html = '<section class="report-section">';
        $html .= $this->sectionTitle('Desglose municipal');
        $html .=
            '<p class="section-note">Compara la red actual y la actividad del periodo dentro de los municipios del estado seleccionado.</p>';

        if (empty($filas)) {
            return $html .
                $this->emptyBlock(
                    'No hay municipios con aliados para el alcance seleccionado.'
                ) .
                '</section>';
        }

        $html .=
            '<table class="data-table"><thead><tr>' .
            '<th>Municipio</th><th class="num">Red actual</th>' .
            '<th class="num">Nuevos</th>' .
            '<th class="num">Trabajados</th>' .
            '<th class="num">Difusiones</th>' .
            '<th class="num">Confirmaciones</th>' .
            '<th class="num">Pendientes</th>' .
            '</tr></thead><tbody>';

        foreach ($filas as $fila) {
            $html .= '<tr>';
            $html .=
                '<td><strong>' .
                $this->e($fila['municipio'] ?? '-') .
                '</strong><small>' .
                $this->decimal(
                    $fila['cobertura_difusion'] ?? 0,
                    1
                ) .
                '% con difusión actual</small></td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['aliados'] ?? 0) .
                '</td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['nuevos_periodo'] ?? 0) .
                '</td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['trabajados_periodo'] ?? 0) .
                '</td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['difusiones_periodo'] ?? 0) .
                '</td>';
            $html .=
                '<td class="num">' .
                $this->numero($fila['confirmaciones_periodo'] ?? 0) .
                '</td>';
            $pendientes = $this->numero($fila['pendientes'] ?? 0);
            $vencidos = (int)($fila['vencidos'] ?? 0);
            $html .=
                '<td class="num">' .
                $pendientes .
                ($vencidos > 0
                    ? '<small>' . $vencidos . ' vencido(s)</small>'
                    : '') .
                '</td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table></section>';
    }

    private function aliadoCard(array $fila, int $numero, string $modo): string
    {
        $institucion = trim(
            (string)($fila['institucion'] ?? '')
        );
        $municipio = trim(
            (string)($fila['municipio'] ?? '')
        );
        $estado = trim(
            (string)($fila['estado'] ?? '')
        );
        $territorio = $municipio;

        if ($modo === 'red' && $estado !== '') {
            $territorio .=
                ($territorio !== '' ? ' / ' : '') .
                $estado;
        }

        $contacto = trim(
            (string)($fila['contacto_nombre'] ?? '')
        );
        $cargo = trim(
            (string)($fila['contacto_cargo'] ?? '')
        );

        if ($contacto !== '' && $cargo !== '') {
            $contacto .= ' - ' . $cargo;
        } elseif ($contacto === '') {
            $contacto = $cargo;
        }

        $ultimaConvocatoria = trim(
            (string)($fila['ultima_convocatoria'] ?? '')
        );
        $ultimoCanal = $this->canal(
            (string)($fila['ultimo_canal'] ?? '')
        );
        $ultimoEnvio = $this->fecha(
            (string)($fila['ultimo_envio_at'] ?? ''),
            true
        );
        $nota = trim(
            (string)($fila['nota_seguimiento'] ?? '')
        );

        $html =
            '<article class="ally-card">' .
            '<table class="ally-head"><tr><td>' .
            '<span>ALIADO ' . $numero . '</span>' .
            '<h3>' .
            $this->e($institucion !== '' ? $institucion : 'Institución') .
            '</h3>' .
            '<p>' .
            $this->e($territorio !== '' ? $territorio : 'Sin territorio') .
            '</p></td><td class="ally-status">' .
            '<strong>' .
            $this->e($fila['estado_seguimiento_label'] ?? 'Sin seguimiento') .
            '</strong></td></tr></table>';

        $html .= '<table class="ally-grid"><tr>';
        $html .= $this->allyInfo(
            'Analista',
            $fila['analista'] ?? '-'
        );
        $html .= $this->allyInfo(
            'Cuenta Clave',
            $fila['cuenta_clave'] ?? '-'
        );
        $html .= $this->allyInfo(
            'Formalización',
            $this->fecha(
                (string)($fila['formalizado_at'] ?? ''),
                false
            )
        );
        $html .= '</tr><tr>';
        $html .= $this->allyInfo(
            'Contacto',
            $contacto !== '' ? $contacto : '-'
        );
        $html .= $this->allyInfo(
            'WhatsApp',
            trim((string)($fila['whatsapp_contacto'] ?? '')) !== ''
                ? $fila['whatsapp_contacto']
                : 'No registrado'
        );
        $html .= $this->allyInfo(
            'Correo',
            trim((string)($fila['correo_contacto'] ?? '')) !== ''
                ? $fila['correo_contacto']
                : 'No registrado'
        );
        $html .= '</tr><tr>';
        $html .= $this->allyInfo(
            'Última convocatoria',
            $ultimaConvocatoria !== ''
                ? $ultimaConvocatoria
                : 'Sin difusión registrada'
        );
        $html .= $this->allyInfo(
            'Canal / fecha',
            $ultimaConvocatoria !== ''
                ? $ultimoCanal . ' / ' . $ultimoEnvio
                : '-'
        );
        $html .= $this->allyInfo(
            'Próximo contacto',
            $this->fecha(
                (string)($fila['proximo_seguimiento_at'] ?? ''),
                true
            )
        );
        $html .= '</tr></table>';

        if ($nota !== '') {
            $html .=
                '<div class="ally-note"><span>Nota de seguimiento</span><p>' .
                $this->e($nota) .
                '</p></div>';
        }

        $actividades = is_array($fila['actividad_periodo'] ?? null)
            ? $fila['actividad_periodo']
            : [];

        $html .= '</article>';

        $html .= '<div class="ally-history">';
        $html .= '<div class="ally-history-title">Actividad del periodo</div>';

        if (empty($actividades)) {
            $html .=
                '<div class="ally-history-empty">Sin actividad registrada dentro del periodo seleccionado.</div>';
        } else {
            $html .=
                '<table class="activity-table"><thead><tr>' .
                '<th>Fecha</th><th>Actividad</th><th>Detalle</th><th>Responsable</th>' .
                '</tr></thead><tbody>';

            foreach ($actividades as $actividad) {
                $html .= '<tr>';
                $html .=
                    '<td>' .
                    $this->e(
                        $this->fecha(
                            (string)($actividad['fecha'] ?? ''),
                            true
                        )
                    ) .
                    '</td>';
                $html .=
                    '<td><strong>' .
                    $this->e($actividad['titulo'] ?? '-') .
                    '</strong></td>';
                $html .=
                    '<td>' .
                    $this->e($actividad['detalle'] ?? '-') .
                    '</td>';
                $html .=
                    '<td>' .
                    $this->e(
                        trim((string)($actividad['usuario'] ?? '')) !== ''
                            ? $actividad['usuario']
                            : '-'
                    ) .
                    '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</div>';

        return $html;
    }

    private function scopeCell(string $label, string $value): string
    {
        return '<td><span>' . $this->e($label) .
            '</span><strong>' . $this->e($value) .
            '</strong></td>';
    }

    private function metric(
        string $label,
        string $value,
        string $meta
    ): string {
        return '<td class="metric"><span>' .
            $this->e($label) .
            '</span><strong>' .
            $this->e($value) .
            '</strong><small>' .
            $this->e($meta) .
            '</small></td>';
    }

    private function statusMetric(string $label, $value): string
    {
        return '<td><span>' . $this->e($label) .
            '</span><strong>' .
            $this->numero($value) .
            '</strong></td>';
    }

    private function allyInfo(string $label, $value): string
    {
        $value = trim((string)$value);
        return '<td><span>' . $this->e($label) .
            '</span><strong>' .
            $this->e($value !== '' ? $value : '-') .
            '</strong></td>';
    }

    private function sectionTitle(string $titulo): string
    {
        return '<div class="section-title"><h2>' .
            $this->e($titulo) .
            '</h2></div>';
    }

    private function emptyBlock(string $texto): string
    {
        return '<div class="empty">' .
            $this->e($texto) .
            '</div>';
    }

    private function tituloAlcance(
        string $modo,
        string $estado,
        string $municipio
    ): string {
        if ($modo === 'municipio') {
            return trim($municipio . ($estado !== '' ? ' / ' . $estado : ''));
        }

        if ($modo === 'estado') {
            return $estado !== '' ? $estado : 'Estado seleccionado';
        }

        return 'Todos los territorios autorizados';
    }

    private function etiquetaPeriodo(array $periodo): string
    {
        $label = trim(
            (string)($periodo['label'] ?? 'Histórico completo')
        );
        $desde = trim(
            (string)($periodo['fecha_desde'] ?? '')
        );
        $hasta = trim(
            (string)($periodo['fecha_hasta'] ?? '')
        );

        if ($desde === '' || $hasta === '') {
            return $label !== '' ? $label : 'Histórico completo';
        }

        try {
            $desdeFormateado =
                (new DateTimeImmutable($desde))->format('d/m/Y');
            $hastaFormateado =
                (new DateTimeImmutable($hasta))->format('d/m/Y');

            return
                ($label !== '' ? $label : 'Periodo seleccionado') .
                ' · ' .
                $desdeFormateado .
                ' al ' .
                $hastaFormateado;
        } catch (Throwable $error) {
            return $label !== '' ? $label : 'Periodo seleccionado';
        }
    }

    private function etiquetaModo(string $modo): string
    {
        if ($modo === 'estado') {
            return 'Estado';
        }

        if ($modo === 'municipio') {
            return 'Municipio';
        }

        return 'Red territorial';
    }

    private function canal(string $canal): string
    {
        $canal = strtoupper(trim($canal));
        $mapa = [
            'WHATSAPP_MANUAL' => 'WhatsApp manual',
            'WHATSAPP' => 'WhatsApp',
            'CORREO' => 'Correo'
        ];

        return $mapa[$canal] ?? ($canal !== '' ? $canal : '-');
    }

    private function fecha(string $valor, bool $conHora): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            return '-';
        }

        try {
            return (new DateTimeImmutable($valor))->format(
                $conHora ? 'd/m/Y H:i' : 'd/m/Y'
            );
        } catch (Exception $error) {
            return $valor;
        }
    }

    private function nombreArchivo(array $reporte): string
    {
        $modo = (string)($reporte['modo'] ?? 'red');
        $alcance = 'Red';

        if ($modo === 'estado') {
            $alcance = trim(
                (string)($reporte['estado_nombre'] ?? 'Estado')
            );
        } elseif ($modo === 'municipio') {
            $alcance = trim(
                (string)($reporte['municipio_nombre'] ?? 'Municipio')
            );
        }

        $partes = [$alcance !== '' ? $alcance : 'Red'];
        $situacion = trim(
            (string)($reporte['situacion_label'] ?? '')
        );

        if (
            $situacion !== '' &&
            strcasecmp($situacion, 'Todos los aliados') !== 0
        ) {
            $partes[] = $situacion;
        }

        return ReporteNombreArchivoService::conPeriodo(
            'Reporte_Aliados',
            $partes,
            is_array($reporte['periodo'] ?? null)
                ? $reporte['periodo']
                : [],
            date('Y-m-d')
        );
    }

    private function logoDataUri(): string
    {
        $rutas = [
            dirname(__DIR__, 2) .
                '/public/img/brand/porcayo-grupo.png',
            dirname(__DIR__, 2) .
                '/public/img/brand/porcayo-grupo8.png'
        ];

        foreach ($rutas as $ruta) {
            if (!is_file($ruta) || !is_readable($ruta)) {
                continue;
            }

            $contenido = file_get_contents($ruta);
            if ($contenido === false || $contenido === '') {
                continue;
            }

            $extension = strtolower(
                (string)pathinfo($ruta, PATHINFO_EXTENSION)
            );
            $mime = $extension === 'jpg' || $extension === 'jpeg'
                ? 'image/jpeg'
                : 'image/png';

            return 'data:' . $mime .
                ';base64,' .
                base64_encode($contenido);
        }

        return '';
    }

    private function css(): string
    {
        return '@page{margin:18mm 14mm 17mm 14mm}' .
            'body{font-family:"DejaVu Sans",sans-serif;color:#252525;font-size:7.4pt;line-height:1.35;margin:0}' .
            '.top-rule{height:4px;background:#273A8A;margin:-18mm -14mm 10px}' .
            '.header{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:6px}' .
            '.brand{width:175px;vertical-align:middle}.brand img{display:block;width:146px;height:auto;max-width:146px}' .
            '.header-copy{vertical-align:middle;text-align:right;padding-left:10px}' .
            '.system-name{font-size:6.3pt;color:#273A8A;font-weight:700;letter-spacing:.035em;margin-bottom:2px}' .
            '.header h1{font-size:12pt;line-height:1.1;color:#16223B;margin:0 0 6px;font-weight:800}' .
            '.header-meta{margin-left:auto;border-collapse:collapse;font-size:6.1pt;line-height:1.2}' .
            '.header-meta td{color:#6D7480;text-align:right;padding:.5px 0 .5px 10px}' .
            '.header-meta th{color:#16223B;text-align:right;padding:.5px 0 .5px 7px;font-weight:700}' .
            '.header-rule{height:2px;background:#273A8A;margin:0 0 10px}' .
            '.scope{width:100%;table-layout:fixed;border-collapse:collapse;background:#F7F9FC;border:1px solid #D7DFEA;margin-bottom:12px}' .
            '.scope td{width:25%;padding:8px 10px;vertical-align:middle;border-right:1px solid #D7DFEA}' .
            '.scope td:last-child{border-right:0}.scope span,.metric span,.status-grid span,.ally-grid span,.ally-note span{display:block;color:#6D7480;font-size:5.8pt;margin-bottom:2px}' .
            '.scope strong{display:block;color:#16223B;font-size:7pt}' .
            '.report-section{margin:0 0 14px}.keep{page-break-inside:avoid}' .
            '.section-title{border-left:3px solid #273A8A;padding-left:8px;margin:0 0 8px;page-break-inside:avoid;page-break-after:avoid}' .
            '.section-title h2{font-size:10.5pt;color:#16223B;margin:0;font-weight:800}' .
            '.section-note{margin:-3px 0 7px;color:#6D7480;font-size:5.8pt;line-height:1.35}' .
            '.metrics{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}' .
            '.metrics.five .metric{width:20%}.metrics.four .metric{width:25%}' .
            '.metric{padding:8px;border:1px solid #D3DCE8;background:#F9FBFE;vertical-align:middle}' .
            '.metric strong{display:block;color:#16223B;font-size:11pt;font-weight:800;line-height:1.1}' .
            '.metric small{display:block;color:#8B94A2;font-size:5.3pt;margin-top:2px}' .
            '.data-table{width:100%;border-collapse:collapse;font-size:6pt;page-break-inside:auto;margin-top:6px}' .
            '.data-table thead{display:table-header-group}.data-table tr{page-break-inside:avoid;page-break-after:auto}' .
            '.data-table th{background:#273A8A;color:#FFF;text-align:left;padding:6px 6px;font-weight:700}' .
            '.data-table td{padding:6px 6px;border-bottom:1px solid #E5E9EF;vertical-align:top}' .
            '.data-table tbody tr:nth-child(even){background:#F8FAFC}.data-table small{display:block;color:#6D7480;font-size:5.2pt;margin-top:2px}' .
            '.data-table .num{text-align:right}.status{display:inline-block;padding:2px 5px;background:#EDF2FA;color:#273A8A;font-size:5.3pt;font-weight:700}' .
            '.status-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px}' .
            '.status-grid td{width:33.33%;padding:7px 8px;border:1px solid #D9E1EB;background:#FBFCFE}' .
            '.status-grid strong{display:block;color:#16223B;font-size:10pt;font-weight:800}' .
            '.insights{border:1px solid #D9E1EB;background:#F8FAFC;padding:3px 9px}' .
            '.insight{display:table;width:100%;border-bottom:1px solid #E5E9EF;padding:6px 0}.insight:last-child{border-bottom:0}' .
            '.insight span,.insight p{display:table-cell;vertical-align:top}.insight span{width:15px;color:#273A8A;font-weight:800}.insight p{margin:0;color:#4F5968;font-size:6.1pt}' .
            '.empty{border-left:3px solid #BFD9D4;background:#F4FAF8;padding:8px 10px;color:#4F5968;font-size:6.2pt}' .
            '.ally-card{page-break-inside:avoid;border:1px solid #D9E1EB;margin:0 0 9px;background:#FFF}' .
            '.ally-head{width:100%;border-collapse:collapse;background:#F7F9FC;border-bottom:1px solid #D9E1EB}' .
            '.ally-head td{padding:8px 9px;vertical-align:middle}.ally-head span{display:block;color:#0A8F7A;font-size:5.2pt;font-weight:800;letter-spacing:.04em}' .
            '.ally-head h3{margin:1px 0 1px;color:#16223B;font-size:8.5pt}.ally-head p{margin:0;color:#6D7480;font-size:5.6pt}' .
            '.ally-status{width:125px;text-align:right}.ally-status strong{display:inline-block;padding:3px 6px;background:#EDF2FA;color:#273A8A;font-size:5.5pt}' .
            '.ally-grid{width:100%;table-layout:fixed;border-collapse:collapse}.ally-grid td{width:33.33%;padding:7px 9px;border-right:1px solid #E5E9EF;border-bottom:1px solid #E5E9EF;vertical-align:top}' .
            '.ally-grid td:nth-child(3n){border-right:0}.ally-grid strong{display:block;color:#16223B;font-size:6.2pt;line-height:1.35;overflow-wrap:anywhere}' .
            '.ally-note{padding:7px 9px;background:#FCFDFE}.ally-note p{margin:0;color:#4F5968;font-size:5.8pt;line-height:1.4}' .
            '.ally-note span{color:#273A8A;font-weight:700}' .
            '.ally-history{margin:-4px 0 11px;padding:0 9px 9px;border:1px solid #D9E1EB;border-top:0;background:#FFF}' .
            '.ally-history-title{padding:6px 0 4px;color:#273A8A;font-size:5.7pt;font-weight:800;text-transform:uppercase;letter-spacing:.03em}' .
            '.ally-history-empty{padding:6px 7px;background:#F8FAFC;color:#6D7480;font-size:5.6pt}' .
            '.activity-table{width:100%;border-collapse:collapse;font-size:5.55pt;page-break-inside:auto}' .
            '.activity-table thead{display:table-header-group}.activity-table tr{page-break-inside:avoid}' .
            '.activity-table th{padding:5px 6px;background:#EDF2FA;color:#273A8A;text-align:left;font-weight:700}' .
            '.activity-table td{padding:5px 6px;border-bottom:1px solid #E5E9EF;vertical-align:top;color:#4F5968}' .
            '.activity-table td:first-child{width:18%;white-space:nowrap}.activity-table td:nth-child(2){width:22%}.activity-table td:nth-child(4){width:20%}' .
            '.activity-table strong{color:#16223B;font-size:5.6pt}' .
            '.num{white-space:nowrap}';
    }

    private function e($valor): string
    {
        return htmlspecialchars(
            (string)$valor,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private function numero($valor): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '-';
        }

        return number_format((float)$valor, 0, '.', ',');
    }

    private function decimal($valor, int $decimales = 1): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '-';
        }

        return number_format(
            (float)$valor,
            $decimales,
            '.',
            ','
        );
    }

    private function sinAcentos(string $texto): string
    {
        $convertido = @iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $texto
        );

        return $convertido !== false
            ? $convertido
            : $texto;
    }

    private function error(
        string $mensaje,
        string $detalle
    ): array {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'mensaje_tecnico' => $detalle
        ];
    }
}
