<?php

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/ReporteNombreArchivoService.php';

class ReporteTerritorialPdfService
{
    public function generar(array $reporte): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        if (!is_file($autoload)) {
            return $this->error(
                'No fue posible preparar el PDF del reporte.',
                'No se encontró vendor/autoload.php.'
            );
        }

        require_once $autoload;

        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            return $this->error(
                'No fue posible preparar el PDF del reporte.',
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

            $canvas->page_script(static function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($font, $bold): void {
                $y = $canvas->get_height() - 24;
                $canvas->line(46, $y - 7, $canvas->get_width() - 46, $y - 7, [0.90, 0.91, 0.94], 0.5);
                $canvas->text(46, $y, 'Grupo Porcayo · Sistema de Gestión Comercial', $bold, 6.4, [0.15, 0.23, 0.54]);
                $pagina = 'Página ' . $pageNumber . ' de ' . $pageCount;
                $ancho = $fontMetrics->getTextWidth($pagina, $font, 6.4);
                $canvas->text($canvas->get_width() - 46 - $ancho, $y, $pagina, $font, 6.4, [0.43, 0.45, 0.50]);
            });

            $estado = $reporte['estado'] ?? [];
            $nombreEstado = trim(
                (string)($estado['nombre'] ?? 'Territorio')
            );

            return [
                'ok' => true,
                'contenido_pdf' => $dompdf->output(),
                'nombre_archivo' =>
                    ReporteNombreArchivoService::corte(
                        'Informacion_Territorial',
                        [
                            $nombreEstado !== ''
                                ? $nombreEstado
                                : 'Territorio'
                        ],
                        date('Y-m-d')
                    )
            ];
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible generar el PDF del reporte territorial.',
                $error->getMessage()
            );
        }
    }

    private function construirHtml(array $reporte): string
    {
        $estado = is_array($reporte['estado'] ?? null) ? $reporte['estado'] : [];
        $actividad = is_array($reporte['actividad_economica'] ?? null) ? $reporte['actividad_economica'] : [];
        $poder = is_array($reporte['poder_adquisitivo'] ?? null) ? $reporte['poder_adquisitivo'] : [];
        $rezago = is_array($reporte['rezago_educativo'] ?? null) ? $reporte['rezago_educativo'] : [];
        $perfil = is_array($reporte['perfil_educativo'] ?? null) ? $reporte['perfil_educativo'] : [];
        $perfil2549 = is_array($reporte['perfil_educativo_25_49'] ?? null) ? $reporte['perfil_educativo_25_49'] : [];
        $escolaridadAdulta = is_array($reporte['escolaridad_adulta'] ?? null) ? $reporte['escolaridad_adulta'] : [];
        $indicadores = is_array($reporte['indicadores_educativos'] ?? null) ? $reporte['indicadores_educativos'] : [];
        $priorizacion = is_array($reporte['priorizacion_municipal'] ?? null) ? $reporte['priorizacion_municipal'] : [];
        $secretarias = is_array($reporte['secretarias'] ?? null) ? $reporte['secretarias'] : [];
        $fuentes = is_array($reporte['fuentes'] ?? null) ? $reporte['fuentes'] : [];
        $calculos = is_array($reporte['calculos'] ?? null) ? $reporte['calculos'] : [];
        $resumen = is_array($reporte['resumen_ejecutivo'] ?? null) ? $reporte['resumen_ejecutivo'] : [];
        $lecturas = is_array($reporte['lecturas'] ?? null) ? $reporte['lecturas'] : [];

        $fechaGeneracion = $this->fecha((string)($reporte['fecha_generacion'] ?? ''));
        $generadoPor = trim((string)($reporte['generado_por'] ?? ''));
        $generadoPorRol = trim((string)($reporte['generado_por_rol'] ?? ''));
        $nombreEstado = trim((string)($estado['nombre'] ?? 'Territorio'));
        $logo = $this->logoDataUri();
        $mapa = $this->mapaEstadoData($estado['mapa_estado'] ?? '');

        $poblacion = $this->numero($estado['poblacion'] ?? null);
        $municipios = $this->numero($estado['total_municipios'] ?? $estado['municipios_cargados'] ?? null);
        $establecimientos = $this->numero($actividad['total_establecimientos'] ?? null);
        $densidad = ($calculos['establecimientos_por_10000_habitantes'] ?? null) !== null
            ? $this->decimal($calculos['establecimientos_por_10000_habitantes'], 1)
            : '—';

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>' . $this->css() . '</style></head><body>';
        $html .= '<div class="top-rule"></div>';

        $html .= '<table class="header"><tr><td class="brand">';
        if ($logo !== '') {
            $html .= '<img src="' . $logo . '" alt="Grupo Porcayo">';
        }
        $html .= '</td><td class="header-copy">';
        $html .= '<div class="system-name">Sistema de Gestión Comercial</div>';
        $html .= '<h1>Reporte de Información Territorial</h1>';
        $html .= '<table class="header-meta">';
        if ($generadoPor !== '') {
            $html .= '<tr><td>Generado por</td><th>' . $this->e($generadoPor) . '</th></tr>';
        }
        if ($generadoPorRol !== '') {
            $html .= '<tr><td>Rol</td><th>' . $this->e($generadoPorRol) . '</th></tr>';
        }
        $html .= '<tr><td>Fecha</td><th>' . $this->e($fechaGeneracion) . '</th></tr>';
        $html .= '<tr><td>Territorio</td><th>' . $this->e($nombreEstado) . '</th></tr>';
        $html .= '</table></td></tr></table><div class="header-rule"></div>';

        $html .= '<table class="scope"><tr>';
        $html .= '<td><span>Territorio</span><strong>' . $this->e($nombreEstado) . '</strong></td>';
        $html .= '<td><span>Actualización del expediente</span><strong>' . $this->e($this->fecha((string)($estado['fecha_actualizacion'] ?? ''))) . '</strong></td>';
        $html .= '<td><span>Fuentes / referencias</span><strong>' . $this->numero($resumen['fuentes_disponibles'] ?? 0) . '</strong></td>';
        $html .= '</tr></table>';

        $capital = trim((string)($estado['capital'] ?? ''));

        $html .= '<section class="report-section territory-focus keep"><table class="territory-head"><tr><td>';
        $html .= '<span>CONTEXTO DEL REPORTE</span><h2>Territorio analizado</h2>';
        $html .= '<p>Lectura ejecutiva de la información territorial registrada y de sus referencias disponibles.</p></td>';
        if (($mapa['src'] ?? '') !== '') {
            $html .= '<td class="territory-map"><img src="' . $this->e($mapa['src']) . '" alt="Mapa de ' . $this->e($nombreEstado) . '"></td>';
        }
        $html .= '</tr></table>';
        $html .= '<table class="territory-grid four"><tr>';
        $html .= $this->focusInfo('Territorio', $nombreEstado);
        $html .= $this->focusInfo('Capital', $capital !== '' ? $capital : '—');
        $html .= $this->focusInfo('Periodo de gobierno', $estado['periodo_gobierno'] ?? '—');
        $html .= $this->focusInfo('Municipios clasificados', $this->numero($resumen['municipios_clasificables'] ?? 0));
        $html .= '</tr></table></section>';

        $html .= '<section class="report-section keep">' . $this->sectionTitle('Panorama territorial');
        $html .= '<table class="metrics compact-five"><tr>';
        $html .= $this->metric('Población', $this->numero($resumen['poblacion'] ?? null), 'habitantes');
        $html .= $this->metric('Municipios', $this->numero($resumen['municipios'] ?? null), 'registrados');
        $html .= $this->metric('Establecimientos', $this->numero($resumen['establecimientos'] ?? null), 'actividad económica');
        $html .= $this->metric('Est. / 10 mil hab.', ($resumen['establecimientos_por_10000_habitantes'] ?? null) !== null ? $this->decimal($resumen['establecimientos_por_10000_habitantes'], 1) : '—', 'densidad');
        $html .= $this->metric(
            'ATACAR',
            ($resumen['priorizacion_disponible'] ?? false) === true
                ? $this->numero($resumen['prioridad_alta'] ?? 0)
                : '—',
            ($resumen['priorizacion_disponible'] ?? false) === true
                ? 'prioridad alta'
                : 'sin datos suficientes'
        );
        $html .= '</tr></table></section>';

        $html .= '<section class="report-section keep">' . $this->sectionTitle('Lectura estratégica');
        $html .= '<table class="strategic-grid"><tr>';

        $sectorPrincipal = is_array($resumen['sector_principal'] ?? null)
            ? $resumen['sector_principal']
            : [];
        $html .= '<td><span>ECONOMÍA</span><strong>' . $this->e($sectorPrincipal['nombre_sector'] ?? 'Sin dato disponible') . '</strong>';
        $html .= '<small>Sector con mayor presencia</small><b>' .
            (($resumen['concentracion_top_5'] ?? null) !== null ? $this->decimal($resumen['concentracion_top_5'], 2) . '% Top 5' : '—') .
            '</b></td>';

        $html .= '<td><span>CONDICIONES SOCIOECONÓMICAS</span><strong>' .
            (($resumen['pobreza_laboral'] ?? null) !== null ? $this->decimal($resumen['pobreza_laboral'], 2) . '%' : '—') .
            '</strong><small>Pobreza laboral</small><b>' .
            $this->e($this->diferenciaPuntos($resumen['diferencia_pobreza_nacional'] ?? null)) .
            ' vs. nacional</b></td>';

        if (($perfil2549['disponible'] ?? false) === true) {
            $html .= '<td><span>EDUCACIÓN</span><strong>' .
                $this->decimal($perfil2549['sin_media_superior_25_49_pct'] ?? 0, 2) . '%' .
                '</strong><small>25–49 sin media superior concluida</small><b>' .
                $this->numero($perfil2549['sin_media_superior_25_49'] ?? null) .
                ' personas</b></td>';
        } else {
            $html .= '<td><span>EDUCACIÓN</span><strong>' .
                (($resumen['rezago_educativo'] ?? null) !== null ? $this->decimal($resumen['rezago_educativo'], 2) . '%' : '—') .
                '</strong><small>Rezago educativo</small><b>' .
                $this->e($this->diferenciaPuntos($resumen['diferencia_rezago_nacional'] ?? null)) .
                ' vs. nacional</b></td>';
        }

        $html .= '</tr></table></section>';

        $recomendados = is_array($priorizacion['recomendados'] ?? null)
            ? $priorizacion['recomendados']
            : [];
        if (($priorizacion['disponible'] ?? false) === true) {
            $conteos = is_array($priorizacion['conteos'] ?? null)
                ? $priorizacion['conteos']
                : [];

            $html .= '<div class="page-break"></div>';
            $html .= '<section class="report-section table-section">' . $this->sectionTitle('Priorización territorial');
            $html .= '<p class="section-note">Priorización orientativa del módulo territorial; compara municipios dentro del mismo Estado y conserva la cobertura de datos.</p>';
            $html .= '<table class="priority-summary four"><tr>';
            $html .= $this->priorityMetric('ATACAR', (int)($conteos['ALTA'] ?? 0), 'Prioridad alta');
            $html .= $this->priorityMetric('OFRECER', (int)($conteos['MEDIA'] ?? 0), 'Prioridad media');
            $html .= $this->priorityMetric('OBSERVAR', (int)($conteos['BAJA'] ?? 0), 'Seguimiento');
            $html .= $this->priorityMetric('CLASIFICADOS', (int)($priorizacion['total_municipios_clasificables'] ?? 0), 'Con datos');
            $html .= '</tr></table>';

            if (!empty($recomendados)) {
                $html .= '<table class="data-table priority-table"><thead><tr><th>Municipio / motivo</th><th>Estrategia</th><th>Puntaje</th><th class="num">Cobertura</th><th class="num">Ranking</th></tr></thead><tbody>';
                foreach ($recomendados as $municipio) {
                    $ranking = ($municipio['ranking'] ?? null) !== null
                        ? ((int)$municipio['ranking'] . ' de ' . (int)($municipio['total_ranking'] ?? 0))
                        : '—';
                    $puntaje = max(0, min(100, (int)($municipio['puntaje'] ?? 0)));
                    $cobertura = max(0, min(100, (int)($municipio['cobertura_datos'] ?? 0)));
                    $html .= '<tr><td><strong>' . $this->e($municipio['nombre'] ?? '—') . '</strong>';
                    $html .= '<small>' . $this->e($municipio['motivo'] ?? 'Priorización calculada con los datos disponibles.') . '</small></td>';
                    $html .= '<td><span class="strategy">' . $this->e($municipio['accion'] ?? '—') . '</span></td>';
                    $html .= '<td><div class="score"><strong>' . $puntaje . '</strong><span><i style="width:' . $puntaje . '%"></i></span></div></td>';
                    $html .= '<td class="num">' . $cobertura . '%</td>';
                    $html .= '<td class="num">' . $this->e($ranking) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
            $html .= '</section>';
        }

        $html .= '<section class="report-section activity-section">' . $this->sectionTitle('Actividad económica');
        if (!empty($actividad['sectores'])) {
            $html .= '<table class="mini-metrics"><tr>';
            $html .= $this->miniMetric('Sector principal', $sectorPrincipal['nombre_sector'] ?? '—');
            $html .= $this->miniMetric('Concentración Top 5', ($calculos['concentracion_top_5_sectores'] ?? null) !== null ? $this->decimal($calculos['concentracion_top_5_sectores'], 2) . '%' : '—');
            $html .= $this->miniMetric('Participación nacional', ($calculos['participacion_establecimientos_nacional'] ?? null) !== null ? $this->decimal($calculos['participacion_establecimientos_nacional'], 2) . '%' : '—');
            $html .= '</tr></table>';
            $html .= $this->sectorChart(array_slice(array_values($actividad['sectores']), 0, 5));

            $otrosSectores = array_slice(array_values($actividad['sectores']), 5, 3);
            if (!empty($otrosSectores)) {
                $html .= '<table class="data-table compact secondary-sectors"><thead><tr><th>Otros sectores registrados</th><th class="num">Establecimientos</th><th class="num">Participación</th></tr></thead><tbody>';
                foreach ($otrosSectores as $sector) {
                    $html .= '<tr><td>' . $this->e($sector['nombre_sector'] ?? '—') . '</td>';
                    $html .= '<td class="num">' . $this->numero($sector['establecimientos'] ?? null) . '</td>';
                    $html .= '<td class="num">' . $this->decimal($sector['porcentaje'] ?? 0, 2) . '%</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        } else {
            $html .= $this->emptyBlock('No hay actividad económica oficial registrada para este territorio.');
        }
        $html .= '</section>';

        $html .= '<div class="page-break"></div>';
        $html .= '<section class="report-section keep">' . $this->sectionTitle('Condiciones socioeconómicas');
        if (($poder['disponible'] ?? false) === true) {
            $periodo = 'T' . (int)($poder['trimestre'] ?? 0) . ' ' . (int)($poder['anio'] ?? 0);
            $html .= '<table class="metrics two"><tr>';
            $html .= $this->metric('Ingreso laboral real per cápita', '$' . $this->decimal($poder['ingreso_laboral_real_per_capita'] ?? 0, 2), $periodo);
            $html .= $this->metric('Pobreza laboral', $this->decimal($poder['pobreza_laboral'] ?? 0, 2) . '%', $periodo);
            $html .= '</tr></table>';
            $html .= '<table class="comparison-grid"><tr>';
            $html .= '<td><span>Diferencia de ingreso vs. nacional</span><strong>' . $this->e($this->diferenciaMoneda($poder['diferencia_ingreso_nacional'] ?? null)) . '</strong></td>';
            $html .= '<td><span>Diferencia de pobreza vs. nacional</span><strong>' . $this->e($this->diferenciaPuntos($poder['diferencia_pobreza_nacional'] ?? null)) . '</strong></td>';
            $html .= '</tr></table>';
        } else {
            $html .= $this->emptyBlock('No hay indicadores oficiales de poder adquisitivo disponibles.');
        }
        $html .= '</section>';

        $html .= '<section class="report-section keep">' . $this->sectionTitle('Contexto educativo');
        if (($rezago['disponible'] ?? false) === true || ($perfil['disponible'] ?? false) === true) {
            $html .= '<table class="metrics two"><tr>';
            $html .= $this->metric(
                'Rezago educativo',
                ($rezago['disponible'] ?? false) === true ? $this->decimal($rezago['porcentaje'] ?? 0, 2) . '%' : '—',
                ($rezago['disponible'] ?? false) === true ? (string)($rezago['anio'] ?? '') : 'Sin dato oficial'
            );
            $html .= $this->metric(
                (string)($perfil['nombre_indicador'] ?? 'Perfil educativo'),
                ($perfil['disponible'] ?? false) === true ? $this->decimal($perfil['porcentaje'] ?? 0, 2) . '%' : '—',
                ($perfil['disponible'] ?? false) === true ? (string)($perfil['anio'] ?? '') : 'Sin dato compatible'
            );
            $html .= '</tr></table>';
            $html .= '<table class="comparison-grid"><tr>';
            $html .= '<td><span>Diferencia de rezago vs. nacional</span><strong>' . $this->e($this->diferenciaPuntos($rezago['diferencia_nacional'] ?? null)) . '</strong></td>';
            $html .= '<td><span>Población base del perfil</span><strong>' . $this->numero($perfil['poblacion_base'] ?? null) . '</strong></td>';
            $html .= '</tr></table>';
        } else {
            $html .= $this->emptyBlock('No hay indicadores educativos oficiales suficientes para este apartado.');
        }

        if (!empty($indicadores)) {
            $html .= '<table class="data-table compact"><thead><tr><th>Indicador complementario</th><th class="num">Valor</th><th class="num">Periodo</th></tr></thead><tbody>';
            foreach ($indicadores as $indicador) {
                $porcentaje = $indicador['porcentaje'] ?? null;
                $valorIndicador = $porcentaje !== null && $porcentaje !== ''
                    ? $this->decimal($porcentaje, 2) . '%'
                    : $this->valor($indicador['valor'] ?? null) .
                        (trim((string)($indicador['unidad'] ?? '')) !== '' ? ' ' . $this->e($indicador['unidad']) : '');
                $html .= '<tr><td>' . $this->e($indicador['situacion'] ?? '—') . '</td>';
                $html .= '<td class="num">' . $valorIndicador . '</td>';
                $html .= '<td class="num">' . $this->e($indicador['periodo'] ?? '—') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</section>';

        if (($perfil2549['disponible'] ?? false) === true) {
            $html .= '<section class="report-section keep education-priority">' .
                $this->sectionTitle('Perfil educativo prioritario · 25 a 49 años');
            $html .= '<p class="section-note">Universo educativo utilizado por la priorización municipal para dimensionar la brecha de media superior dentro del territorio.</p>';
            $html .= '<table class="education-profile-grid"><tr>';
            $html .= '<td><span>Población 25–49 con perfil</span><strong>' .
                $this->numero($perfil2549['poblacion_25_49'] ?? null) .
                '</strong><small>Base agregada disponible</small></td>';
            $html .= '<td class="emphasis"><span>Sin estudios de media superior</span><strong>' .
                $this->numero($perfil2549['sin_media_superior_25_49'] ?? null) .
                '</strong><small>' .
                $this->decimal($perfil2549['sin_media_superior_25_49_pct'] ?? 0, 2) .
                '% del grupo 25–49</small></td>';
            $html .= '<td><span>Media superior, sin superior</span><strong>' .
                $this->numero($perfil2549['media_superior_sin_superior_25_49'] ?? null) .
                '</strong><small>' .
                $this->decimal($perfil2549['media_superior_sin_superior_25_49_pct'] ?? 0, 2) .
                '% del grupo 25–49</small></td>';
            $html .= '<td><span>Con educación superior</span><strong>' .
                $this->numero($perfil2549['con_educacion_superior_25_49'] ?? null) .
                '</strong><small>' .
                $this->decimal($perfil2549['con_educacion_superior_25_49_pct'] ?? 0, 2) .
                '% del grupo 25–49</small></td>';
            $html .= '</tr></table>';

            $html .= '<div class="education-profile-note">Perfil disponible en <strong>' .
                (int)($perfil2549['municipios_con_datos'] ?? 0) . '</strong> de <strong>' .
                (int)($perfil2549['municipios_clasificables'] ?? 0) .
                '</strong> municipios clasificables';
            if (trim((string)($perfil2549['fuente'] ?? '')) !== '') {
                $html .= ' · Fuente: ' . $this->e($perfil2549['fuente']);
            }
            if (($perfil2549['anio'] ?? null) !== null && (string)$perfil2549['anio'] !== '') {
                $html .= ' · Periodo: ' . $this->e($perfil2549['anio']);
            }
            $html .= '.</div></section>';
        }

        $html .= '<section class="report-section keep education-priority">' .
            $this->sectionTitle('Brecha de escolaridad: adultos y jóvenes');
        $html .= '<p class="section-note">Rangos y definiciones independientes del perfil 25–49. Los valores se importan con referencia oficial declarada, sin estimaciones.</p>';
        $html .= '<table class="education-profile-grid"><tr>';
        foreach ([
            ['codigo' => 'SIN_EDUCACION_SUPERIOR_25_MAS', 'titulo' => 'Sin educación superior · 25 años o más'],
            ['codigo' => 'SIN_MEDIA_SUPERIOR_CONCLUIDA_18_MAS', 'titulo' => 'Sin media superior concluida · 18 años o más'],
            ['codigo' => 'SIN_MEDIA_SUPERIOR_CONCLUIDA_15_17', 'titulo' => 'Jóvenes sin media superior concluida · 15 a 17 años']
        ] as $definicionAdulta) {
            $dato = $escolaridadAdulta[$definicionAdulta['codigo']] ?? [];
            $disponible = ($dato['disponible'] ?? false) === true;
            $esMinimo = str_contains((string)($dato['metodologia'] ?? ''), 'Conteo mínimo identificable');
            $html .= '<td><span>' . $this->e($definicionAdulta['titulo']) .
                ($esMinimo ? ' (mínimo identificado)' : '') . '</span>';
            $html .= '<strong>' . ($disponible
                ? $this->numero($dato['cantidad_personas'])
                : 'Pendiente') . '</strong>';
            if ($disponible) {
                $html .= '<small>' . $this->decimal($dato['porcentaje'], 2) .
                    '% de ' . $this->numero($dato['poblacion_base']) . ' personas</small>';
                $html .= '<small>Periodo: ' . (int)$dato['anio'] .
                    ' · Fuente declarada: ' . $this->e($dato['fuente']) . '</small>';
                $html .= '<small>Metodología: ' . $this->e($dato['metodologia']) . '</small>';
                $html .= '<small><a href="' . $this->e($dato['referencia_url']) . '">Consultar referencia INEGI</a></small>';
            } else {
                $html .= '<small>Sin cifra del rango con metodología documentada.</small>';
            }
            $html .= '</td>';
        }
        $html .= '</tr></table>';
        $html .= '<p class="section-note">Los datos juveniles se extraen de edades individuales 15, 16 y 17 de INEGI. El indicador es un mínimo identificable y no implica abandono escolar. La importación CSV requiere cotejo documental.</p></section>';

        $html .= '<section class="report-section keep">' . $this->sectionTitle('Contexto institucional');
        $html .= '<table class="territory-grid government"><tr>';
        $html .= $this->focusInfo('Titular del gobierno', $estado['titular_gobierno'] ?? '—');
        $html .= $this->focusInfo('Cargo', $estado['cargo_titular'] ?? '—');
        $html .= $this->focusInfo('Partido político', $estado['partido_politico'] ?? '—');
        $html .= '</tr><tr>';
        $html .= $this->focusInfo('Periodo de gobierno', $estado['periodo_gobierno'] ?? '—');
        $html .= $this->focusInfo('Teléfono', $estado['telefono'] ?? '—');
        $html .= $this->focusInfo('Secretarías activas', $this->numero($calculos['total_secretarias_activas'] ?? null));
        $html .= '</tr></table></section>';

        $secretariasActivas = array_values(array_filter(
            $secretarias,
            static function ($secretaria) {
                return (int)($secretaria['estado'] ?? 0) === 1;
            }
        ));

        if (!empty($secretariasActivas)) {
            $html .= '<section class="report-section table-section">' . $this->sectionTitle('Estructura institucional');
            $html .= '<table class="data-table compact"><thead><tr><th>Secretaría</th><th>Titular</th><th>Contacto</th></tr></thead><tbody>';
            foreach ($secretariasActivas as $secretaria) {
                $contacto = trim((string)($secretaria['correo'] ?? ''));
                if ($contacto === '') {
                    $contacto = trim((string)($secretaria['telefono'] ?? ''));
                }
                $html .= '<tr><td><strong>' . $this->e($secretaria['nombre'] ?? '—') . '</strong></td>';
                $html .= '<td>' . $this->e($secretaria['titular'] ?? '—') . '</td>';
                $html .= '<td>' . $this->e($contacto !== '' ? $contacto : '—') . '</td></tr>';
            }
            $html .= '</tbody></table></section>';
        }

        $html .= '<section class="report-section keep">' . $this->sectionTitle('Hallazgos de los datos disponibles');
        if (!empty($lecturas)) {
            $html .= '<div class="insights">';
            foreach ($lecturas as $lectura) {
                $tituloLectura = is_array($lectura) ? (string)($lectura['titulo'] ?? 'Hallazgo') : 'Hallazgo';
                $textoLectura = is_array($lectura) ? (string)($lectura['texto'] ?? '') : (string)$lectura;
                $html .= '<div class="insight"><span>•</span><p><strong>' . $this->e($tituloLectura) . '.</strong> ' . $this->e($textoLectura) . '</p></div>';
            }
            $html .= '</div>';
        } else {
            $html .= $this->emptyBlock('Aún no hay datos suficientes para generar una lectura territorial.');
        }
        $html .= '</section>';

        $html .= '<section class="report-section table-section">' . $this->sectionTitle('Fuentes y vigencia');
        $html .= '<p class="section-note">La fecha de actualización del expediente no sustituye el periodo estadístico propio de cada fuente.</p>';
        $html .= '<table class="data-table compact"><thead><tr><th>Información</th><th>Fuente</th><th class="num">Periodo</th></tr></thead><tbody>';
        $hayFuente = false;

        foreach ($fuentes as $seccion => $fuente) {
            if (!is_array($fuente) || trim((string)($fuente['fuente'] ?? '')) === '') {
                continue;
            }
            $hayFuente = true;
            $html .= '<tr><td>' . $this->e($this->etiquetaSeccion((string)$seccion)) . '</td>';
            $html .= '<td>' . $this->e($fuente['fuente'] ?? '—') . '</td>';
            $html .= '<td class="num">' . $this->e($fuente['periodo'] ?? '—') . '</td></tr>';
        }

        if (($perfil2549['disponible'] ?? false) === true && trim((string)($perfil2549['fuente'] ?? '')) !== '') {
            $hayFuente = true;
            $html .= '<tr><td>Perfil educativo 25–49</td><td>' .
                $this->e($perfil2549['fuente']) .
                '</td><td class="num">' .
                $this->e($perfil2549['anio'] ?? '—') .
                '</td></tr>';
        }

        if (($perfil['disponible'] ?? false) === true && trim((string)($perfil['fuente'] ?? '')) !== '') {
            $hayFuente = true;
            $html .= '<tr><td>Perfil educativo</td><td>' . $this->e($perfil['fuente']) . '</td><td class="num">' . $this->e($perfil['anio'] ?? '—') . '</td></tr>';
        }

        if (!$hayFuente) {
            $html .= '<tr><td colspan="3">No hay fuentes registradas para mostrar.</td></tr>';
        }

        $html .= '</tbody></table></section>';
        return $html . '</body></html>';
    }

    private function css(): string
    {
        return '@import url("https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap");' .
            '@page{margin:18mm 14mm 17mm 14mm}' .
            'body{font-family:"Manrope","DejaVu Sans",sans-serif;color:#252525;font-size:8pt;line-height:1.35;margin:0}' .
            '.top-rule{height:4px;background:#273A8A;margin:-18mm -14mm 10px}' .
            '.header{width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:6px}.brand{width:175px;vertical-align:middle}.brand img{display:block;width:146px;height:auto;max-width:146px}' .
            '.header-copy{vertical-align:middle;text-align:right;padding-left:10px}.system-name{font-size:6.3pt;color:#273A8A;font-weight:800;letter-spacing:.035em;margin-bottom:2px}' .
            '.header h1{font-size:11.8pt;line-height:1.1;color:#16223B;margin:0 0 6px;font-weight:800;white-space:nowrap}.header-meta{margin-left:auto;border-collapse:collapse;font-size:6.1pt;line-height:1.2}' .
            '.header-meta td{color:#6D7480;text-align:right;padding:.5px 0 .5px 10px}.header-meta th{color:#16223B;text-align:right;padding:.5px 0 .5px 7px;font-weight:700}.header-rule{height:2px;background:#273A8A;margin:0 0 10px}' .
            '.scope{width:100%;table-layout:fixed;border-collapse:collapse;background:#F7F9FC;border:1px solid #D7DFEA;margin-bottom:12px}.scope td{padding:8px 10px;vertical-align:middle;text-align:left;border-right:1px solid #D7DFEA}.scope td:last-child{border-right:0}.scope span,.focus-info span,.metric .label,.mini-metric small,.comparison-grid span,.priority-summary span{display:block;color:#6D7480;font-size:6pt;margin-bottom:2px}.scope strong{font-size:7.1pt;color:#16223B}' .
            '.report-section{margin:0 0 15px}.keep{page-break-inside:avoid}.section-title{border-left:3px solid #273A8A;padding-left:8px;margin:0 0 11px;page-break-inside:avoid;page-break-after:avoid}.section-title h2{font-size:10.7pt;color:#16223B;margin:0;font-weight:800}' .
            '.territory-focus{border:1px solid #E5E9EF;padding:9px 10px;background:#FFFFFF}.territory-head{width:100%;border-collapse:collapse}.territory-head td{vertical-align:middle}.territory-head span{color:#0A8F7A;font-size:6.2pt;font-weight:800;letter-spacing:.04em}.territory-head h2{font-size:12.6pt;margin:2px 0;color:#16223B}.territory-head p{margin:0;color:#6D7480;font-size:6.8pt}.territory-map{width:120px;text-align:right}.territory-map img{max-width:105px;max-height:65px}' .
            '.territory-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px;margin-top:7px}.focus-info{width:33.33%;border:1px solid #E5E9EF;padding:7px 8px;vertical-align:middle;background:#F9FBFE}.focus-info strong{display:block;font-size:7.2pt;color:#16223B}' .
            '.metrics,.mini-metrics,.comparison-grid,.priority-summary{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.metric{background:#F9FBFE;border:1px solid #D3DCE8;padding:9px;vertical-align:middle}.metric .value{display:block;color:#16223B;font-size:12.5pt;font-weight:800;margin-top:2px}.metric .meta{display:block;color:#8B94A2;font-size:5.7pt;margin-top:2px}.metrics.two .metric{width:50%}' .
            '.mini-metric{width:33.33%;border:1px solid #D3DCE8;background:#F5F8FC;padding:7px 8px}.mini-metric strong{display:block;font-size:7.5pt;color:#16223B}.comparison-grid{margin-top:6px}.comparison-grid td{width:50%;padding:7px 8px;border:1px solid #D9E1EB;background:#FFFFFF}.comparison-grid strong{display:block;color:#16223B;font-size:7.2pt}' .
            '.sector-chart{margin:7px 0 9px;padding:7px 8px;border:1px solid #D9E1EB;background:#FBFCFE;page-break-inside:avoid}.chart-row{width:100%;border-collapse:collapse;margin-bottom:5px}.chart-label{width:35%;padding-right:7px;font-size:5.9pt}.chart-track-cell{width:55%}.chart-value{width:10%;text-align:right;font-size:5.9pt;font-weight:700}.chart-track{height:6px;background:#E9EDF4;overflow:hidden}.chart-fill{height:6px;background:#273A8A}' .
            '.data-table{width:100%;border-collapse:collapse;font-size:6.25pt;page-break-inside:auto;margin-top:7px}.data-table thead{display:table-header-group}.data-table tr{page-break-inside:avoid;page-break-after:auto}.data-table th{background:#273A8A;color:#FFFFFF;text-align:left;padding:6px 7px;font-weight:700}.data-table td{padding:6px 7px;border-bottom:1px solid #E5E9EF;vertical-align:top}.data-table tbody tr:nth-child(even){background:#F8FAFC}.data-table small{display:block;color:#6D7480;font-size:5.4pt;margin-top:2px}.data-table .num{text-align:right}.data-table.compact{font-size:6pt}' .
            '.insights{border:1px solid #D9E1EB;background:#F8FAFC;padding:3px 9px}.insight{display:table;width:100%;border-bottom:1px solid #E5E9EF;padding:6px 0}.insight:last-child{border-bottom:0}.insight span,.insight p{display:table-cell;vertical-align:top}.insight span{width:14px;color:#273A8A;font-weight:800}.insight p{margin:0;color:#4F5968;font-size:6.2pt}' .
            '.priority-summary{margin-bottom:7px;page-break-inside:avoid;page-break-after:avoid}.priority-summary td{width:33.33%;padding:7px 8px;border:1px solid #D9E1EB;background:#F8FAFC}.priority-summary strong{display:block;color:#16223B;font-size:9pt}.priority-summary em{display:block;color:#8B94A2;font-size:5.4pt;font-style:normal}.strategy{display:inline-block;padding:2px 5px;background:#EDF2FA;color:#273A8A;font-size:5.5pt;font-weight:800}.score{display:table;width:100%}.score strong,.score span{display:table-cell;vertical-align:middle}.score strong{width:23px;color:#16223B;font-size:6pt}.score span{height:5px;background:#E9EDF4;overflow:hidden}.score i{display:block;height:5px;background:#273A8A}' .
            '.empty{border-left:3px solid #E5E9EF;background:#F8FAFC;padding:8px 10px;color:#6D7480;font-size:6.4pt}.table-section{page-break-inside:auto}' .
            '.page-break{page-break-before:always;height:0}.section-note{margin:-4px 0 7px;color:#6D7480;font-size:5.9pt;line-height:1.35}' .
            '.territory-grid.four .focus-info{width:25%}.territory-grid.government .focus-info{width:33.33%}' .
            '.metrics.compact-five{border-spacing:3px 0}.metrics.compact-five .metric{width:20%;padding:7px 5px}.metrics.compact-five .metric .value{font-size:10pt}.metrics.compact-five .metric .label{font-size:5.5pt}' .
            '.strategic-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.strategic-grid td{width:33.33%;padding:8px;border:1px solid #D9E1EB;background:#FBFCFE;vertical-align:top}.strategic-grid span{display:block;color:#273A8A;font-size:5.3pt;font-weight:800;letter-spacing:.03em;margin-bottom:3px}.strategic-grid strong{display:block;color:#16223B;font-size:8pt;line-height:1.2}.strategic-grid small{display:block;color:#6D7480;font-size:5.5pt;margin:2px 0 6px}.strategic-grid b{display:block;padding-top:5px;border-top:1px solid #E5E9EF;color:#252525;font-size:6pt}' .
            '.priority-summary.four td{width:25%}.priority-table th:first-child{width:43%}.priority-table td:first-child small{line-height:1.35}.secondary-sectors{margin-top:5px}' .
            '.insight p strong{color:#16223B;font-size:6.2pt}' .
            '.education-profile-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.education-profile-grid td{width:25%;padding:8px;border:1px solid #D9E1EB;background:#FBFCFE;vertical-align:top}.education-profile-grid td.emphasis{background:#F0FAF8;border-color:#B9E2DA}.education-profile-grid span{display:block;color:#6D7480;font-size:5.5pt;margin-bottom:4px}.education-profile-grid strong{display:block;color:#16223B;font-size:9.3pt;line-height:1}.education-profile-grid td.emphasis strong{color:#087966}.education-profile-grid small{display:block;margin-top:4px;color:#6D7480;font-size:5.3pt;line-height:1.3}.education-profile-note{margin-top:6px;padding:6px 8px;border:1px solid #D9E1EB;background:#F8FAFC;color:#6D7480;font-size:5.6pt;line-height:1.35}.education-profile-note strong{color:#16223B}';
    }

    private function sectionTitle(string $titulo): string
    {
        return '<div class="section-title"><h2>' . $this->e($titulo) . '</h2></div>';
    }

    private function focusInfo(string $label, $value): string
    {
        $mostrar = $value === null || trim((string)$value) === ''
            ? '—'
            : (string)$value;

        return '<td class="focus-info"><span>' . $this->e($label) . '</span><strong>' .
            $this->e($mostrar) . '</strong></td>';
    }

    private function sectorChart(array $sectores): string
    {
        if (empty($sectores)) {
            return '';
        }

        $maximo = 1;
        foreach ($sectores as $sector) {
            $maximo = max($maximo, (int)($sector['establecimientos'] ?? 0));
        }

        $html = '<div class="sector-chart">';
        foreach ($sectores as $sector) {
            $valor = max(0, (int)($sector['establecimientos'] ?? 0));
            $ancho = min(100, max(2, ($valor / $maximo) * 100));
            $html .= '<table class="chart-row"><tr>';
            $html .= '<td class="chart-label">' . $this->e($sector['nombre_sector'] ?? '—') . '</td>';
            $html .= '<td class="chart-track-cell"><div class="chart-track"><div class="chart-fill" style="width:' .
                number_format($ancho, 2, '.', '') . '%"></div></div></td>';
            $html .= '<td class="chart-value">' . $this->numero($valor) . '</td></tr></table>';
        }
        return $html . '</div>';
    }

    private function priorityMetric(string $accion, int $total, string $meta): string
    {
        return '<td><span>' . $this->e($accion) . '</span><strong>' . $total .
            '</strong><em>' . $this->e($meta) . '</em></td>';
    }

    private function metric(string $label, string $value, string $meta): string
    {
        return '<td class="metric"><div class="label">' . $this->e($label) . '</div><div class="value">' . $this->e($value) . '</div><div class="meta">' . $this->e($meta) . '</div></td>';
    }

    private function miniMetric(string $label, $value): string
    {
        return '<td class="mini-metric"><small>' . $this->e($label) . '</small><strong>' . $this->e($value) . '</strong></td>';
    }

    private function infoRow(string $label1, $value1, string $label2, $value2): string
    {
        return '<tr><td class="info-label">' . $this->e($label1) . '</td><td class="info-value">' . $this->valor($value1) . '</td><td class="info-label">' . $this->e($label2) . '</td><td class="info-value">' . $this->valor($value2) . '</td></tr>';
    }

    private function emptyBlock(string $texto): string
    {
        return '<div class="empty">' . $this->e($texto) . '</div>';
    }

    private function mapaEstadoData($rutaAlmacenada): array
    {
        $vacio = ['src' => '', 'width' => 0, 'height' => 0];
        $ruta = trim(str_replace('\\', '/', (string)$rutaAlmacenada));

        if ($ruta === '' || strpos($ruta, '..') !== false) {
            return $vacio;
        }

        $ruta = ltrim($ruta, '/');
        $directorioPermitido = 'public/uploads/territorios/mapas/';

        if (strpos($ruta, $directorioPermitido) !== 0) {
            return $vacio;
        }

        $extension = strtolower((string)pathinfo($ruta, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $vacio;
        }

        $rutaFisica = dirname(__DIR__, 2) . '/' . $ruta;
        if (!is_file($rutaFisica) || !is_readable($rutaFisica)) {
            return $vacio;
        }

        $contenido = file_get_contents($rutaFisica);
        if ($contenido === false || $contenido === '') {
            return $vacio;
        }

        $mime = $extension === 'jpg' || $extension === 'jpeg'
            ? 'image/jpeg'
            : ($extension === 'png' ? 'image/png' : 'image/webp');

        if ($extension === 'webp') {
            if (!function_exists('imagecreatefromwebp') || !function_exists('imagepng')) {
                return $vacio;
            }

            $imagenWebp = @imagecreatefromwebp($rutaFisica);
            if ($imagenWebp === false) {
                return $vacio;
            }

            ob_start();
            $convertida = imagepng($imagenWebp);
            $contenidoPng = ob_get_clean();

            if (function_exists('imagedestroy')) {
                imagedestroy($imagenWebp);
            }

            if (!$convertida || !is_string($contenidoPng) || $contenidoPng === '') {
                return $vacio;
            }

            $contenido = $contenidoPng;
            $mime = 'image/png';
        }

        $dimensiones = @getimagesizefromstring($contenido);
        if (!is_array($dimensiones) || (int)($dimensiones[0] ?? 0) <= 0 || (int)($dimensiones[1] ?? 0) <= 0) {
            return $vacio;
        }

        $anchoOriginal = (int)$dimensiones[0];
        $altoOriginal = (int)$dimensiones[1];
        $factor = min(255 / $anchoOriginal, 145 / $altoOriginal, 1);

        return [
            'src' => 'data:' . $mime . ';base64,' . base64_encode($contenido),
            'width' => max(1, (int)round($anchoOriginal * $factor)),
            'height' => max(1, (int)round($altoOriginal * $factor))
        ];
    }

    private function logoDataUri(): string
    {
        $rutas = [
            dirname(__DIR__, 2) . '/public/img/brand/porcayo-grupo.png',
            dirname(__DIR__, 2) . '/public/img/brand/porcayo-grupo8.png'
        ];

        foreach ($rutas as $ruta) {
            if (!is_file($ruta) || !is_readable($ruta)) {
                continue;
            }
            $contenido = file_get_contents($ruta);
            if ($contenido === false || $contenido === '') {
                continue;
            }
            $extension = strtolower((string)pathinfo($ruta, PATHINFO_EXTENSION));
            $mime = $extension === 'jpg' || $extension === 'jpeg' ? 'image/jpeg' : 'image/png';
            return 'data:' . $mime . ';base64,' . base64_encode($contenido);
        }

        return '';
    }

    private function e($valor): string
    {
        return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function valor($valor): string
    {
        if ($valor === null || trim((string)$valor) === '') {
            return '—';
        }
        return $this->e($valor);
    }

    private function numero($valor): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '—';
        }
        return number_format((float)$valor, 0, '.', ',');
    }

    private function decimal($valor, int $decimales = 2): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '—';
        }
        return number_format((float)$valor, $decimales, '.', ',');
    }

    private function diferenciaPuntos($valor): string
    {
        if ($valor === null || !is_numeric($valor)) {
            return '—';
        }
        $valor = (float)$valor;
        $signo = $valor > 0 ? '+' : ($valor < 0 ? '−' : '');
        return $signo . number_format(abs($valor), 2, '.', ',') . ' pts.';
    }

    private function diferenciaMoneda($valor): string
    {
        if ($valor === null || !is_numeric($valor)) {
            return '—';
        }
        $valor = (float)$valor;
        $signo = $valor > 0 ? '+' : ($valor < 0 ? '−' : '');
        return $signo . '$' . number_format(abs($valor), 2, '.', ',');
    }

    private function fecha(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return '—';
        }
        try {
            return (new DateTime($valor))->format('d/m/Y H:i');
        } catch (Exception $error) {
            return $valor;
        }
    }

    private function etiquetaSeccion(string $seccion): string
    {
        $etiquetas = [
            'GENERAL' => 'Información general',
            'ACTIVIDAD_ECONOMICA' => 'Actividad económica',
            'PODER_ADQUISITIVO' => 'Poder adquisitivo',
            'EDUCACION' => 'Educación',
            'SECRETARIAS' => 'Secretarías',
            'MUNICIPIOS' => 'Municipios'
        ];
        return $etiquetas[$seccion] ?? ucfirst(strtolower(str_replace('_', ' ', $seccion)));
    }

    private function sinAcentos(string $texto): string
    {
        $convertido = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        return $convertido !== false ? $convertido : $texto;
    }

    private function error(string $mensaje, string $tecnico): array
    {
        return [
            'ok' => false,
            'mensaje' => $mensaje,
            'mensaje_tecnico' => $tecnico
        ];
    }
}
