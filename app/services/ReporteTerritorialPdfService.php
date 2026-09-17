<?php

use Dompdf\Dompdf;
use Dompdf\Options;

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
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($this->construirHtml($reporte), 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $canvas = $dompdf->getCanvas();
            $fontMetrics = $dompdf->getFontMetrics();
            $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
            $fontSize = 6.7;
            $footerColor = [0.43, 0.45, 0.50];

            $canvas->page_script(static function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($font, $fontSize, $footerColor): void {
                $texto = 'Página ' . $pageNumber . ' de ' . $pageCount;
                $anchoTexto = $fontMetrics->getTextWidth($texto, $font, $fontSize);
                $x = ($canvas->get_width() - $anchoTexto) / 2;
                $y = $canvas->get_height() - 24;
                $canvas->text($x, $y, $texto, $font, $fontSize, $footerColor);
            });

            $estado = $reporte['estado'] ?? [];
            $nombreEstado = trim((string)($estado['nombre'] ?? 'Territorio'));
            $nombreSeguro = preg_replace('/[^A-Za-z0-9_-]+/', '_', $this->sinAcentos($nombreEstado));
            $nombreSeguro = trim((string)$nombreSeguro, '_');

            return [
                'ok' => true,
                'contenido_pdf' => $dompdf->output(),
                'nombre_archivo' => 'Reporte_Informacion_Territorial_' . ($nombreSeguro !== '' ? $nombreSeguro : 'Territorio') . '.pdf'
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
        $estado = $reporte['estado'] ?? [];
        $actividad = $reporte['actividad_economica'] ?? [];
        $poder = $reporte['poder_adquisitivo'] ?? [];
        $rezago = $reporte['rezago_educativo'] ?? [];
        $perfil = $reporte['perfil_educativo'] ?? [];
        $indicadores = $reporte['indicadores_educativos'] ?? [];
        $priorizacion = $reporte['priorizacion_municipal'] ?? [];
        $secretarias = $reporte['secretarias'] ?? [];
        $fuentes = $reporte['fuentes'] ?? [];
        $calculos = $reporte['calculos'] ?? [];
        $lecturas = $reporte['lecturas'] ?? [];
        $fechaGeneracion = $this->fecha((string)($reporte['fecha_generacion'] ?? ''));

        $logo = $this->logoDataUri();
        $mapaEstado = $this->mapaEstadoData($estado['mapa_estado'] ?? '');
        $nombreEstado = $this->e($estado['nombre'] ?? 'Territorio');
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
        $html .= '</td><td class="header-copy"><div class="eyebrow">SISTEMA COMERCIAL</div><h1>Reporte de Información Territorial</h1><p>' . $nombreEstado . ' · Generado ' . $this->e($fechaGeneracion) . '</p></td></tr></table>';

        if (($mapaEstado['src'] ?? '') !== '') {
            $html .= '<div class="state-map"><img src="' . $this->e($mapaEstado['src']) . '" alt="Mapa de ' . $nombreEstado . '" width="' . (int)($mapaEstado['width'] ?? 0) . '" height="' . (int)($mapaEstado['height'] ?? 0) . '"></div>';
        }

        $html .= '<div class="section-title first"><span>RESUMEN EJECUTIVO</span><h2>Panorama territorial</h2></div>';
        $html .= '<table class="metrics"><tr>';
        $html .= $this->metric('Población', $poblacion, 'habitantes');
        $html .= $this->metric('Municipios', $municipios, 'registrados');
        $html .= $this->metric('Establecimientos', $establecimientos, 'DENUE');
        $html .= $this->metric('Est. / 10 mil hab.', $densidad, 'cálculo territorial');
        $html .= '</tr></table>';

        $html .= '<div class="section-title"><span>FICHA TERRITORIAL</span><h2>Datos generales del Estado</h2></div>';
        $html .= '<table class="info-table">';
        $html .= $this->infoRow('Capital', $estado['capital'] ?? null, 'Titular del gobierno', $estado['titular_gobierno'] ?? null);
        $html .= $this->infoRow('Cargo', $estado['cargo_titular'] ?? null, 'Periodo de gobierno', $estado['periodo_gobierno'] ?? null);
        $html .= $this->infoRow('Partido político', $estado['partido_politico'] ?? null, 'Teléfono', $estado['telefono'] ?? null);
        $html .= $this->infoRow('Secretarías activas', $calculos['total_secretarias_activas'] ?? null, 'Última actualización', $this->fecha((string)($estado['fecha_actualizacion'] ?? '')));
        $html .= '</table>';

        $html .= '<div class="section-title"><span>ACTIVIDAD ECONÓMICA</span><h2>Distribución y concentración</h2></div>';
        if (!empty($actividad['sectores'])) {
            $sectorPrincipal = $calculos['sector_principal'] ?? null;
            $concentracionTop5 = $calculos['concentracion_top_5_sectores'] ?? null;
            $participacionNacional = $calculos['participacion_establecimientos_nacional'] ?? null;

            $html .= '<table class="mini-metrics"><tr>';
            $html .= $this->miniMetric('Sector principal', is_array($sectorPrincipal) ? ($sectorPrincipal['nombre_sector'] ?? '—') : '—');
            $html .= $this->miniMetric('Concentración Top 5', $concentracionTop5 !== null ? $this->decimal($concentracionTop5, 2) . '%' : '—');
            $html .= $this->miniMetric('Participación nacional', $participacionNacional !== null ? $this->decimal($participacionNacional, 2) . '%' : '—');
            $html .= '</tr></table>';

            $html .= '<table class="data-table"><thead><tr><th>Sector</th><th class="num">Establecimientos</th><th class="num">Participación</th></tr></thead><tbody>';
            foreach (array_slice(array_values($actividad['sectores']), 0, 8) as $sector) {
                $html .= '<tr><td>' . $this->e($sector['nombre_sector'] ?? '—') . '</td><td class="num">' . $this->numero($sector['establecimientos'] ?? null) . '</td><td class="num">' . $this->decimal($sector['porcentaje'] ?? 0, 2) . '%</td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= $this->emptyBlock('No hay actividad económica oficial registrada para este territorio.');
        }

        if (trim((string)($estado['actividad_economica'] ?? '')) !== '') {
            $html .= '<div class="note"><strong>Contexto económico registrado</strong><br>' . nl2br($this->e($estado['actividad_economica'])) . '</div>';
        }

        $html .= '<div class="section-title"><span>CONDICIONES SOCIOECONÓMICAS</span><h2>Poder adquisitivo y pobreza laboral</h2></div>';
        if (($poder['disponible'] ?? false) === true) {
            $periodoPoder = 'T' . (int)($poder['trimestre'] ?? 0) . ' ' . (int)($poder['anio'] ?? 0);
            $html .= '<table class="metrics two"><tr>';
            $html .= $this->metric('Ingreso laboral real per cápita', '$' . $this->decimal($poder['ingreso_laboral_real_per_capita'] ?? 0, 2), $periodoPoder);
            $html .= $this->metric('Pobreza laboral', $this->decimal($poder['pobreza_laboral'] ?? 0, 2) . '%', $periodoPoder);
            $html .= '</tr></table>';

            if (is_array($poder['referencia_nacional'] ?? null)) {
                $html .= '<div class="comparison">Referencia nacional del mismo periodo: ingreso <strong>$' . $this->decimal($poder['referencia_nacional']['ingreso_laboral_real_per_capita'] ?? 0, 2) . '</strong> y pobreza laboral <strong>' . $this->decimal($poder['referencia_nacional']['pobreza_laboral'] ?? 0, 2) . '%</strong>. Diferencias del Estado: <strong>' . $this->diferenciaMoneda($poder['diferencia_ingreso_nacional'] ?? null) . '</strong> en ingreso y <strong>' . $this->diferenciaPuntos($poder['diferencia_pobreza_nacional'] ?? null) . '</strong> en pobreza laboral.</div>';
            }
        } else {
            $html .= $this->emptyBlock('No hay indicadores oficiales de poder adquisitivo disponibles.');
        }

        if (trim((string)($estado['poder_adquisitivo'] ?? '')) !== '') {
            $html .= '<div class="note"><strong>Contexto registrado sobre poder adquisitivo</strong><br>' . nl2br($this->e($estado['poder_adquisitivo'])) . '</div>';
        }

        $html .= '<div class="section-title"><span>EDUCACIÓN</span><h2>Rezago y perfil educativo</h2></div>';
        if (($rezago['disponible'] ?? false) === true || ($perfil['disponible'] ?? false) === true) {
            $html .= '<table class="metrics two"><tr>';
            if (($rezago['disponible'] ?? false) === true) {
                $html .= $this->metric('Rezago educativo', $this->decimal($rezago['porcentaje'] ?? 0, 2) . '%', (string)($rezago['anio'] ?? ''));
            } else {
                $html .= $this->metric('Rezago educativo', '—', 'Sin dato oficial');
            }
            if (($perfil['disponible'] ?? false) === true) {
                $html .= $this->metric($perfil['nombre_indicador'] ?? 'Perfil educativo', $this->decimal($perfil['porcentaje'] ?? 0, 2) . '%', (string)($perfil['anio'] ?? ''));
            } else {
                $html .= $this->metric('Perfil educativo', '—', 'Sin dato compatible');
            }
            $html .= '</tr></table>';

            if (($rezago['disponible'] ?? false) === true && ($rezago['diferencia_nacional'] ?? null) !== null) {
                $html .= '<div class="comparison">Rezago educativo frente a referencia nacional del mismo año: <strong>' . $this->diferenciaPuntos($rezago['diferencia_nacional']) . '</strong>.</div>';
            }

            if (($perfil['disponible'] ?? false) === true) {
                $html .= '<div class="comparison">Base del indicador educativo: <strong>' . $this->numero($perfil['poblacion_base'] ?? null) . '</strong> personas; cantidad registrada: <strong>' . $this->numero($perfil['cantidad_personas'] ?? null) . '</strong>.</div>';
            }
        } else {
            $html .= $this->emptyBlock('No hay indicadores educativos oficiales suficientes para este apartado.');
        }

        if (!empty($indicadores)) {
            $html .= '<table class="data-table compact"><thead><tr><th>Indicador registrado</th><th class="num">Valor</th><th class="num">Periodo</th></tr></thead><tbody>';
            foreach ($indicadores as $indicador) {
                $porcentaje = $indicador['porcentaje'] ?? null;
                $valorIndicador = $porcentaje !== null && $porcentaje !== ''
                    ? $this->decimal($porcentaje, 2) . '%'
                    : $this->e($indicador['valor'] ?? '—') . (trim((string)($indicador['unidad'] ?? '')) !== '' ? ' ' . $this->e($indicador['unidad']) : '');
                $html .= '<tr><td>' . $this->e($indicador['situacion'] ?? '—') . '</td><td class="num">' . $valorIndicador . '</td><td class="num">' . $this->e($indicador['periodo'] ?? '—') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div class="section-title page-break-avoid"><span>LECTURA TERRITORIAL</span><h2>Cálculos derivados de la información disponible</h2></div>';
        if (!empty($lecturas)) {
            $html .= '<div class="insights">';
            foreach ($lecturas as $lectura) {
                $html .= '<div class="insight"><span>•</span><p>' . $this->e($lectura) . '</p></div>';
            }
            $html .= '</div>';
        } else {
            $html .= $this->emptyBlock('Aún no hay datos suficientes para generar cálculos complementarios.');
        }

        $recomendados = $priorizacion['recomendados'] ?? [];
        if (!empty($recomendados)) {
            $html .= '<div class="section-title"><span>MUNICIPIOS</span><h2>Municipios destacados en la priorización territorial</h2></div>';
            $html .= '<p class="section-copy">La clasificación retoma la priorización ya calculada por Información territorial; no sustituye la decisión del Analista.</p>';
            $html .= '<table class="data-table"><thead><tr><th>Municipio</th><th class="num">Población</th><th class="num">Prioridad</th><th class="num">Ranking</th></tr></thead><tbody>';
            foreach ($recomendados as $municipio) {
                $ranking = ($municipio['ranking'] ?? null) !== null
                    ? ((int)$municipio['ranking'] . ' de ' . (int)($municipio['total_ranking'] ?? 0))
                    : '—';
                $html .= '<tr><td>' . $this->e($municipio['nombre'] ?? '—') . '</td><td class="num">' . $this->numero($municipio['poblacion'] ?? null) . '</td><td class="num">' . $this->e($municipio['prioridad'] ?? '—') . '</td><td class="num">' . $this->e($ranking) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $secretariasActivas = array_values(array_filter($secretarias, static fn(array $secretaria): bool => (int)($secretaria['estado'] ?? 0) === 1));
        if (!empty($secretariasActivas)) {
            $html .= '<div class="section-title"><span>ESTRUCTURA INSTITUCIONAL</span><h2>Secretarías registradas</h2></div>';
            $html .= '<table class="data-table compact"><thead><tr><th>Secretaría</th><th>Titular</th><th>Contacto</th></tr></thead><tbody>';
            foreach ($secretariasActivas as $secretaria) {
                $contacto = trim((string)($secretaria['correo'] ?? ''));
                if ($contacto === '') {
                    $contacto = trim((string)($secretaria['telefono'] ?? ''));
                }
                $html .= '<tr><td>' . $this->e($secretaria['nombre'] ?? '—') . '</td><td>' . $this->e($secretaria['titular'] ?? '—') . '</td><td>' . $this->e($contacto !== '' ? $contacto : '—') . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '<div class="section-title"><span>FUENTES</span><h2>Fuentes y periodos de referencia</h2></div>';
        $html .= '<table class="data-table compact"><thead><tr><th>Sección</th><th>Fuente</th><th class="num">Periodo</th></tr></thead><tbody>';
        $hayFuente = false;
        foreach ($fuentes as $seccion => $fuente) {
            if (!is_array($fuente) || trim((string)($fuente['fuente'] ?? '')) === '') {
                continue;
            }
            $hayFuente = true;
            $html .= '<tr><td>' . $this->e($this->etiquetaSeccion((string)$seccion)) . '</td><td>' . $this->e($fuente['fuente'] ?? '—') . '</td><td class="num">' . $this->e($fuente['periodo'] ?? '—') . '</td></tr>';
        }
        if (($perfil['disponible'] ?? false) === true && trim((string)($perfil['fuente'] ?? '')) !== '') {
            $hayFuente = true;
            $html .= '<tr><td>Perfil educativo</td><td>' . $this->e($perfil['fuente']) . '</td><td class="num">' . $this->e($perfil['anio'] ?? '—') . '</td></tr>';
        }
        if (!$hayFuente) {
            $html .= '<tr><td colspan="3">No hay fuentes registradas para mostrar.</td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<div class="footer-note">Los cálculos complementarios se derivan de los datos disponibles en Información territorial y acompañan, pero no sustituyen, los valores ni las fuentes oficiales registradas.</div>';
        $html .= '</body></html>';

        return $html;
    }

    private function css(): string
    {
        return '@page{margin:24mm 16mm 18mm 16mm}body{font-family:"DejaVu Sans",sans-serif;color:#252525;font-size:8.3pt;line-height:1.38;margin:0}.top-rule{height:4px;background:#0A8F7A;margin:-24mm -16mm 18px}.header{width:100%;border-collapse:collapse;margin-bottom:20px}.brand{width:25%;vertical-align:top}.brand img{width:112px;max-height:58px}.header-copy{text-align:right;vertical-align:top}.eyebrow,.section-title span{color:#16223B;font-size:6.7pt;font-weight:700;letter-spacing:.08em}.header h1{font-size:17pt;color:#16223B;margin:3px 0}.header p{color:#6D7480;margin:0;font-size:7.4pt}.state-map{text-align:right;margin:0 0 12px;page-break-inside:avoid}.state-map img{display:inline-block}.section-title{border-bottom:1px solid #E5E9EF;padding-bottom:6px;margin:18px 0 10px}.section-title.first{margin-top:4px}.section-title h2{font-size:11pt;color:#16223B;margin:2px 0 0}.metrics,.mini-metrics,.info-table,.data-table{width:100%;border-collapse:collapse}.metrics{table-layout:fixed;margin-bottom:8px}.metric{border:1px solid #E5E9EF;background:#F8FAFC;padding:10px;vertical-align:top}.metric .label{font-size:6.8pt;color:#6D7480;margin-bottom:5px}.metric .value{font-size:13pt;color:#16223B;font-weight:700}.metric .meta{font-size:6.6pt;color:#8B94A2;margin-top:3px}.metrics.two .metric{width:50%}.info-table td{border-bottom:1px solid #EEF1F5;padding:7px 8px;vertical-align:top}.info-label{width:17%;color:#6D7480;font-size:6.8pt}.info-value{width:33%;font-weight:600;color:#252525}.mini-metrics{table-layout:fixed;margin-bottom:10px}.mini-metric{width:33.33%;border:1px solid #E5E9EF;padding:8px}.mini-metric small{display:block;color:#6D7480;font-size:6.6pt;margin-bottom:3px}.mini-metric strong{color:#16223B;font-size:8pt}.data-table{margin:6px 0 9px;font-size:7.2pt}.data-table th{background:#EDF2FA;color:#273A8A;text-align:left;padding:7px;border:1px solid #D9E2EF;font-weight:700}.data-table td{padding:6px 7px;border:1px solid #E5E9EF;vertical-align:top}.data-table .num{text-align:right}.data-table.compact{font-size:7pt}.note,.comparison,.empty,.footer-note{border:1px solid #E5E9EF;background:#F8FAFC;padding:8px 10px;margin:8px 0;color:#4F5968}.comparison strong{color:#16223B}.empty{color:#6D7480}.insights{border:1px solid #E5E9EF;padding:4px 10px}.insight{display:table;width:100%;border-bottom:1px solid #EEF1F5;padding:7px 0}.insight:last-child{border-bottom:0}.insight span,.insight p{display:table-cell;vertical-align:top}.insight span{width:14px;color:#0A8F7A;font-weight:700}.insight p{margin:0}.section-copy{color:#6D7480;font-size:7.1pt;margin:-4px 0 8px}.footer-note{margin-top:18px;font-size:6.7pt;color:#6D7480}.page-break-avoid{page-break-after:avoid}';
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
