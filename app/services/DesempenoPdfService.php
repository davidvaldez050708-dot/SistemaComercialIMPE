<?php

use Dompdf\Dompdf;
use Dompdf\Options;

require_once __DIR__ . '/ReporteNombreArchivoService.php';

class DesempenoPdfService
{
    public function generar(array $datos)
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        if (!is_file($autoload)) {
            return $this->error(
                'No fue posible preparar el PDF de desempeño.',
                'No se encontró vendor/autoload.php.'
            );
        }

        require_once $autoload;

        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            return $this->error(
                'No fue posible preparar el PDF de desempeño.',
                'Dompdf no está disponible.'
            );
        }

        try {
            $options = new Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml(
                $this->html($datos),
                'UTF-8'
            );
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $canvas = $dompdf->getCanvas();
            $fontMetrics = $dompdf->getFontMetrics();
            $font = $fontMetrics->getFont(
                'DejaVu Sans',
                'normal'
            );
            $bold = $fontMetrics->getFont(
                'DejaVu Sans',
                'bold'
            );

            $canvas->page_script(
                static function (
                    $pageNumber,
                    $pageCount,
                    $canvas,
                    $fontMetrics
                ) use ($font, $bold) {
                    $y = $canvas->get_height() - 24;
                    $canvas->line(
                        44,
                        $y - 7,
                        $canvas->get_width() - 44,
                        $y - 7,
                        [0.90, 0.91, 0.94],
                        0.5
                    );

                    $canvas->text(
                        44,
                        $y,
                        'Grupo Porcayo - Sistema de Gestión Comercial',
                        $bold,
                        6.2,
                        [0.15, 0.23, 0.54]
                    );

                    $pagina =
                        'Página ' . $pageNumber . ' de ' . $pageCount;
                    $ancho = $fontMetrics->getTextWidth(
                        $pagina,
                        $font,
                        6.2
                    );

                    $canvas->text(
                        $canvas->get_width() - 44 - $ancho,
                        $y,
                        $pagina,
                        $font,
                        6.2,
                        [0.43, 0.45, 0.50]
                    );
                }
            );

            return [
                'ok' => true,
                'contenido_pdf' => $dompdf->output(),
                'nombre_archivo' => $this->nombreArchivo($datos)
            ];
        } catch (Throwable $error) {
            return $this->error(
                'No fue posible generar el PDF de desempeño.',
                $error->getMessage()
            );
        }
    }

    private function html(array $datos)
    {
        $area = (string)($datos['area'] ?? 'analistas');
        $areaLabel = (string)(
            $datos['area_label'] ?? 'Analistas'
        );
        $vista = (string)($datos['vista'] ?? 'propio');
        $periodo = is_array($datos['periodo'] ?? null)
            ? $datos['periodo']
            : [];
        $resumen = is_array($datos['resumen'] ?? null)
            ? $datos['resumen']
            : [];
        $ranking = is_array($datos['ranking'] ?? null)
            ? $datos['ranking']
            : [];
        $reconocimientos = is_array(
            $datos['reconocimientos'] ?? null
        )
            ? $datos['reconocimientos']
            : [];
        $tendencia = is_array($datos['tendencia'] ?? null)
            ? $datos['tendencia']
            : [];
        $tendenciaPersonas = is_array(
            $datos['tendencia_personas'] ?? null
        )
            ? $datos['tendencia_personas']
            : [];
        $criterios = is_array($datos['criterios'] ?? null)
            ? $datos['criterios']
            : [];

        $logo = $this->logoDataUri();
        $html =
            '<!doctype html><html lang="es"><head><meta charset="UTF-8">' .
            '<style>' . $this->css() . '</style></head><body>';

        $html .= '<div class="top-rule"></div>';
        $html .= '<table class="header"><tr>';
        $html .= '<td class="brand">';
        if ($logo !== '') {
            $html .= '<img src="' . $logo . '" alt="Grupo Porcayo">';
        }
        $html .= '</td><td class="header-copy">';
        $html .= '<div class="system-name">Sistema de Gestión Comercial</div>';
        $html .= '<h1>Desempeño y Reconocimientos</h1>';
        $html .= '<table class="header-meta">';

        if (trim((string)($datos['generado_por'] ?? '')) !== '') {
            $html .=
                '<tr><td>Generado por</td><th>' .
                $this->e($datos['generado_por']) .
                '</th></tr>';
        }

        $html .=
            '<tr><td>Rol</td><th>' .
            $this->e($datos['generado_por_rol'] ?? '') .
            '</th></tr>';
        $html .=
            '<tr><td>Fecha</td><th>' .
            $this->e(
                $datos['fecha_generacion']
                    ?? date('d/m/Y H:i')
            ) .
            '</th></tr>';
        $html .= '</table></td></tr></table>';
        $html .= '<div class="header-rule"></div>';

        $html .= '<table class="scope"><tr>';
        $html .= $this->scope(
            'Área',
            $areaLabel
        );
        $html .= $this->scope(
            'Vista',
            $this->vistaLabel($vista)
        );
        $html .= $this->scope(
            'Periodo',
            (string)($periodo['label'] ?? '')
        );
        $html .= $this->scope(
            'Rango',
            $this->rangoPeriodo($periodo)
        );
        $html .= '</tr></table>';

        $html .= '<section class="section keep">';
        $html .= $this->titulo('Resumen del periodo');
        $html .= '<table class="metrics"><tr>';

        if ($area === 'cuenta_clave') {
            $html .= $this->metric(
                'Aliados trabajados',
                $resumen['aliados_trabajados'] ?? 0
            );
            $html .= $this->metric(
                'Difusiones',
                $resumen['difusiones'] ?? 0
            );
            $html .= $this->metric(
                'Actualizaciones',
                $resumen['seguimientos'] ?? 0
            );
            $html .= $this->metric(
                'Confirmaciones',
                $resumen['confirmaciones'] ?? 0
            );
        } elseif ($area === 'marketing') {
            $html .= $this->metric(
                'Publicaciones',
                $resumen['publicaciones'] ?? 0
            );
            $html .= $this->metric(
                'Cobertura acumulada',
                $resumen['territorios_cubiertos'] ?? 0
            );
            $html .= $this->metric(
                'Actualizaciones',
                $resumen['actualizaciones'] ?? 0
            );
            $html .= $this->metric(
                'Vigentes creadas',
                $resumen['vigentes'] ?? 0
            );
        } else {
            $html .= $this->metric(
                'Llamadas válidas',
                $resumen['llamadas_realizadas'] ?? 0
            );
            $html .= $this->metric(
                'Con contacto',
                $resumen['llamadas_con_contacto'] ?? 0
            );
            $html .= $this->metric(
                'Tasa de contacto',
                number_format(
                    (float)($resumen['tasa_contacto'] ?? 0),
                    1
                ) . '%'
            );
            $html .= $this->metric(
                'Interacciones útiles',
                $resumen['interacciones'] ?? 0
            );
            $html .= $this->metric(
                'Llamadas efectivas',
                $resumen['llamadas_efectivas'] ?? 0
            );
        }

        $html .= '</tr></table></section>';

        $esComparativo =
            count($ranking) > 1 &&
            in_array($vista, ['global', 'equipo'], true);

        $tituloDesempeno = $vista === 'propio'
            ? 'Detalle de desempeño'
            : (
                $vista === 'equipo'
                    ? (
                        $esComparativo
                            ? 'Ranking operativo de mi equipo'
                            : 'Desempeño de mi equipo'
                    )
                    : (
                        $esComparativo
                            ? 'Ranking operativo del periodo'
                            : 'Desempeño del área'
                    )
            );

        $html .= '<section class="section">';
        $html .= $this->titulo($tituloDesempeno);

        if (empty($ranking)) {
            $html .=
                '<div class="empty">No existen datos de desempeño para el alcance seleccionado.</div>';
        } else {
            $html .= $this->tablaRanking(
                $ranking,
                $area,
                $esComparativo
            );
        }
        $html .= '</section>';

        if ($esComparativo) {
            $html .= '<section class="section ranking-chart-section">';
            $html .= $this->titulo('Gráfica comparativa del ranking');
            $html .=
                '<div class="ranking-chart-note">Índice operativo por participante en escala de 0 a 100. La gráfica utiliza el mismo índice y el mismo orden mostrados en la tabla del ranking.</div>';
            $html .= $this->graficaRanking($ranking);
            $html .= '</section>';
        }

        if (!empty($reconocimientos)) {
            $html .= '<section class="section keep">';
            $html .= $this->titulo('Reconocimientos del periodo');
            $html .= '<table class="recognitions"><tr>';

            foreach ($reconocimientos as $reconocimiento) {
                $html .=
                    '<td><span>' .
                    $this->e($reconocimiento['titulo'] ?? '') .
                    '</span><strong>' .
                    $this->e($reconocimiento['nombre'] ?? '') .
                    '</strong><small>' .
                    $this->e($reconocimiento['valor'] ?? '') .
                    ' ' .
                    $this->e($reconocimiento['unidad'] ?? '') .
                    '</small></td>';
            }

            $html .= '</tr></table></section>';
        }

        $tendenciaActiva = $this->filtrarTendenciaActiva(
            $tendencia
        );
        $hayDetalleAnalistas =
            $area === 'analistas' &&
            !empty($tendenciaPersonas);

        if (!empty($tendenciaActiva) || $hayDetalleAnalistas) {
            $html .= '<section class="section">';
            $tituloHistorial = 'Historial diario del periodo';

            if ($area === 'analistas') {
                if (count($ranking) === 1) {
                    $nombreHistorial = trim(
                        (string)(
                            $ranking[0]['nombre_completo'] ?? ''
                        )
                    );
                    if ($nombreHistorial !== '') {
                        $tituloHistorial =
                            'Historial diario · ' .
                            $nombreHistorial;
                    }
                } elseif (count($ranking) > 1) {
                    $tituloHistorial =
                        'Historial diario por analista';
                }
            }

            $html .= $this->titulo($tituloHistorial);

            if ($area === 'analistas') {
                $html .=
                    '<div class="history-note">Se muestran únicamente los días y analistas con movimientos registrados.</div>';
            } else {
                $html .=
                    '<div class="history-note">Se muestran únicamente los días con movimientos registrados.</div>';
            }

            if ($hayDetalleAnalistas) {
                $html .= $this->tablaTendenciaAnalistasPorPersona(
                    $tendenciaPersonas,
                    count($ranking) > 1
                );
            } else {
                $html .= $this->tablaTendencia(
                    $tendenciaActiva,
                    $area
                );
            }

            if ($area === 'analistas') {
                $sinActividad =
                    $this->analistasSinActividad($ranking);

                if (!empty($sinActividad)) {
                    $html .=
                        '<div class="inactive-analysts"><strong>Sin actividad en el periodo:</strong> ' .
                        $this->e(implode(', ', $sinActividad)) .
                        '.</div>';
                }
            }

            $html .= '</section>';
        }

        $html .= '<section class="section keep">';
        $html .= $this->titulo('Criterios de medición');
        $html .= '<ol class="criteria">';
        foreach ($criterios as $criterio) {
            $html .= '<li>' . $this->e($criterio) . '</li>';
        }
        $html .= '</ol>';
        $html .=
            '<div class="notice"><strong>Uso de la información.</strong> Este reporte apoya la supervisión y el reconocimiento del trabajo registrado en el sistema. No asigna automáticamente bonos, sanciones o decisiones laborales.</div>';
        $html .= '</section>';

        return $html . '</body></html>';
    }

    private function tablaRanking(
        array $ranking,
        $area,
        $esComparativo = true
    ) {
        if ($area === 'cuenta_clave') {
            $html =
                '<table class="table"><thead><tr>' .
                ($esComparativo ? '<th>#</th>' : '') .
                '<th>Persona</th>' .
                '<th class="num">Aliados</th>' .
                '<th class="num">Difusiones</th>' .
                '<th class="num">Actualizaciones</th>' .
                '<th class="num">Confirmaciones</th>' .
                ($esComparativo ? '<th class="num">Índice</th>' : '') .
                '</tr></thead><tbody>';

            foreach ($ranking as $fila) {
                $html .= '<tr>';

                if ($esComparativo) {
                    $html .= '<td>' .
                        (int)($fila['posicion'] ?? 0) .
                        '</td>';
                }

                $html .= $this->tdPersona($fila);
                $html .= $this->tdNum(
                    $fila['aliados_trabajados'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['difusiones'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['seguimientos'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['confirmaciones'] ?? 0
                );

                if ($esComparativo) {
                    $html .= $this->tdNum(
                        $fila['indice'] === null
                            ? '—'
                            : number_format(
                                (float)$fila['indice'],
                                1
                            )
                    );
                }

                $html .= '</tr>';
            }

            return $html . '</tbody></table>';
        }

        if ($area === 'marketing') {
            $html =
                '<table class="table"><thead><tr>' .
                '<th>Persona</th>' .
                '<th class="num">Publicaciones</th>' .
                '<th class="num">Territorios</th>' .
                '<th class="num">Actualizaciones</th>' .
                '<th class="num">Vigentes</th>' .
                '</tr></thead><tbody>';

            foreach ($ranking as $fila) {
                $html .= '<tr>';
                $html .= $this->tdPersona($fila);
                $html .= $this->tdNum(
                    $fila['publicaciones'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['territorios_cubiertos'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['actualizaciones'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['vigentes'] ?? 0
                );
                $html .= '</tr>';
            }

            return $html . '</tbody></table>';
        }

        $html =
            '<table class="table"><thead><tr>' .
            ($esComparativo ? '<th>#</th>' : '') .
            '<th>Persona</th>' .
            '<th class="num">Llamadas</th>' .
            '<th class="num">Con contacto</th>' .
            '<th class="num">Tasa contacto</th>' .
            '<th class="num">Interacciones</th>' .
            '<th class="num">Efectivas</th>' .
            ($esComparativo ? '<th class="num">Índice</th>' : '') .
            '</tr></thead><tbody>';

        foreach ($ranking as $fila) {
            $html .= '<tr>';

            if ($esComparativo) {
                $html .= '<td>' .
                    (int)($fila['posicion'] ?? 0) .
                    '</td>';
            }

            $html .= $this->tdPersona($fila);
            $html .= $this->tdNum(
                $fila['llamadas_realizadas'] ?? 0
            );
            $html .= $this->tdNum(
                $fila['llamadas_con_contacto'] ?? 0
            );
            $html .= $this->tdNum(
                number_format(
                    (float)($fila['tasa_contacto'] ?? 0),
                    1
                ) . '%'
            );
            $html .= $this->tdNum(
                $fila['interacciones'] ?? 0
            );
            $html .= $this->tdNum(
                $fila['llamadas_efectivas'] ?? 0
            );

            if ($esComparativo) {
                $html .= $this->tdNum(
                    $fila['indice'] === null
                        ? '—'
                        : number_format(
                            (float)$fila['indice'],
                            1
                        )
                );
            }

            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function graficaRanking(array $ranking)
    {
        if (count($ranking) < 2) {
            return '';
        }

        $html = '<div class="ranking-chart">';

        foreach ($ranking as $fila) {
            $nombre = trim(
                (string)($fila['nombre_completo'] ?? '')
            );
            $posicion = max(
                1,
                (int)($fila['posicion'] ?? 0)
            );
            $indiceRaw = $fila['indice'] ?? null;
            $indice = $indiceRaw === null
                ? 0.0
                : min(
                    100.0,
                    max(0.0, (float)$indiceRaw)
                );
            $restante = max(0.0, 100.0 - $indice);
            $valor = $indiceRaw === null
                ? '—'
                : number_format($indice, 1);

            $html .=
                '<div class="ranking-chart-row">' .
                '<table class="ranking-chart-row-table"><tr>' .
                '<td class="ranking-chart-person">' .
                '<strong>#' . $posicion . '</strong>' .
                '<span>' . $this->e($nombre) . '</span>' .
                '</td>' .
                '<td class="ranking-chart-bar-cell">' .
                '<table class="ranking-chart-track"><tr>' .
                '<td class="ranking-chart-fill" style="width:' .
                number_format($indice, 2, '.', '') .
                '%">&nbsp;</td>' .
                '<td class="ranking-chart-rest" style="width:' .
                number_format($restante, 2, '.', '') .
                '%">&nbsp;</td>' .
                '</tr></table>' .
                '</td>' .
                '<td class="ranking-chart-value">' .
                $this->e($valor) .
                '</td>' .
                '</tr></table>' .
                '</div>';
        }

        $html .=
            '<table class="ranking-chart-axis"><tr>' .
            '<td>0</td>' .
            '<td>25</td>' .
            '<td>50</td>' .
            '<td>75</td>' .
            '<td class="is-end">100</td>' .
            '</tr></table>';

        return $html . '</div>';
    }

    private function tablaTendencia(array $filas, $area)
    {
        if ($area === 'cuenta_clave') {
            $html =
                '<table class="table compact"><thead><tr>' .
                '<th>Fecha</th>' .
                '<th class="num">Difusiones</th>' .
                '<th class="num">Actualizaciones</th>' .
                '<th class="num">Confirmaciones</th>' .
                '</tr></thead><tbody>';

            foreach ($filas as $fila) {
                $html .= '<tr><td>' .
                    $this->e($fila['fecha'] ?? '') .
                    '</td>';
                $html .= $this->tdNum(
                    $fila['principal'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['secundario'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['terciario'] ?? 0
                );
                $html .= '</tr>';
            }

            return $html . '</tbody></table>';
        }

        if ($area === 'marketing') {
            $html =
                '<table class="table compact"><thead><tr>' .
                '<th>Fecha</th>' .
                '<th class="num">Publicaciones</th>' .
                '<th class="num">Actualizaciones</th>' .
                '</tr></thead><tbody>';

            foreach ($filas as $fila) {
                $html .= '<tr><td>' .
                    $this->e($fila['fecha'] ?? '') .
                    '</td>';
                $html .= $this->tdNum(
                    $fila['principal'] ?? 0
                );
                $html .= $this->tdNum(
                    $fila['secundario'] ?? 0
                );
                $html .= '</tr>';
            }

            return $html . '</tbody></table>';
        }

        $html =
            '<table class="table compact"><thead><tr>' .
            '<th>Fecha</th>' .
            '<th class="num">Interacciones</th>' .
            '<th class="num">Con contacto</th>' .
            '<th class="num">Llamadas efectivas</th>' .
            '</tr></thead><tbody>';

        foreach ($filas as $fila) {
            $html .= '<tr><td>' .
                $this->e($fila['fecha'] ?? '') .
                '</td>';
            $html .= $this->tdNum(
                $fila['principal'] ?? 0
            );
            $html .= $this->tdNum(
                $fila['secundario'] ?? 0
            );
            $html .= $this->tdNum(
                $fila['terciario'] ?? 0
            );
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function tablaTendenciaAnalistasPorPersona(
        array $filas,
        $mostrarPersona
    ) {
        $filas = array_values(array_filter(
            $filas,
            static function ($fila) {
                return
                    (int)($fila['interacciones'] ?? 0) > 0 ||
                    (int)($fila['contactos'] ?? 0) > 0 ||
                    (int)($fila['efectivas'] ?? 0) > 0;
            }
        ));

        if (empty($filas)) {
            return '<div class="empty">No hubo movimientos registrados en el periodo.</div>';
        }

        $html =
            '<table class="table compact history-table' .
            ($mostrarPersona ? ' has-person' : '') .
            '"><thead><tr>' .
            '<th>Fecha</th>';

        if ($mostrarPersona) {
            $html .= '<th>Analista</th>';
        }

        $html .=
            '<th class="num">Interacciones</th>' .
            '<th class="num">Con contacto</th>' .
            '<th class="num">Llamadas efectivas</th>' .
            '</tr></thead><tbody>';

        foreach ($filas as $fila) {
            $nombre = trim(
                (string)($fila['nombre'] ?? '') . ' ' .
                (string)($fila['apellidos'] ?? '')
            );

            $html .= '<tr><td>' .
                $this->e($this->fechaLegible($fila['fecha'] ?? '')) .
                '</td>';

            if ($mostrarPersona) {
                $html .= '<td><strong>' .
                    $this->e($nombre) .
                    '</strong></td>';
            }

            $html .= $this->tdNum(
                $fila['interacciones'] ?? 0
            );
            $html .= $this->tdNum(
                $fila['contactos'] ?? 0
            );
            $html .= $this->tdNum(
                $fila['efectivas'] ?? 0
            );
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function analistasSinActividad(array $ranking)
    {
        $nombres = [];

        foreach ($ranking as $fila) {
            $actividad =
                (int)($fila['llamadas_realizadas'] ?? 0) +
                (int)($fila['llamadas_con_contacto'] ?? 0) +
                (int)($fila['interacciones'] ?? 0) +
                (int)($fila['llamadas_efectivas'] ?? 0);

            if ($actividad > 0) {
                continue;
            }

            $nombre = trim(
                (string)($fila['nombre_completo'] ?? '')
            );

            if ($nombre !== '') {
                $nombres[] = $nombre;
            }
        }

        return array_values(array_unique($nombres));
    }

    private function filtrarTendenciaActiva(array $filas)
    {
        return array_values(array_filter(
            $filas,
            static function ($fila) {
                return
                    (int)($fila['principal'] ?? 0) > 0 ||
                    (int)($fila['secundario'] ?? 0) > 0 ||
                    (int)($fila['terciario'] ?? 0) > 0;
            }
        ));
    }

    private function tdPersona(array $fila)
    {
        $nombre = trim(
            (string)($fila['nombre_completo'] ?? '')
        );
        $foto = $this->fotoPerfilDataUri(
            $fila['foto_perfil'] ?? ''
        );

        if ($foto !== '') {
            $avatar =
                '<span class="person-avatar">' .
                '<img src="' . $this->e($foto) . '" alt="">' .
                '</span>';
        } else {
            $avatar =
                '<span class="person-avatar is-initials">' .
                $this->e($this->iniciales($nombre)) .
                '</span>';
        }

        return
            '<td class="person-column">' .
            '<span class="person-wrap">' .
            $avatar .
            '<strong class="person-name">' .
            $this->e($nombre) .
            '</strong>' .
            '</span></td>';
    }

    private function fotoPerfilDataUri($ruta)
    {
        $ruta = trim((string)$ruta);
        if ($ruta === '') {
            return '';
        }

        if (strpos($ruta, 'data:image/') === 0) {
            return $ruta;
        }

        $rutaUrl = parse_url($ruta, PHP_URL_PATH);
        $rutaLimpia = urldecode(
            trim((string)($rutaUrl ?: $ruta))
        );

        $posicionPublic = strpos($rutaLimpia, '/public/');
        if ($posicionPublic !== false) {
            $rutaLimpia = substr(
                $rutaLimpia,
                $posicionPublic + 1
            );
        }

        $rutaLimpia = ltrim(
            str_replace('\\', '/', $rutaLimpia),
            '/'
        );

        $raiz = realpath(dirname(__DIR__, 2));
        if ($raiz === false) {
            return '';
        }

        $candidatas = [
            $raiz . DIRECTORY_SEPARATOR .
                str_replace('/', DIRECTORY_SEPARATOR, $rutaLimpia),
            $raiz . DIRECTORY_SEPARATOR . 'public' .
                DIRECTORY_SEPARATOR .
                str_replace('/', DIRECTORY_SEPARATOR, $rutaLimpia)
        ];

        foreach (array_unique($candidatas) as $candidata) {
            $real = realpath($candidata);
            if (
                $real === false ||
                strpos($real, $raiz . DIRECTORY_SEPARATOR) !== 0 ||
                !is_file($real) ||
                !is_readable($real)
            ) {
                continue;
            }

            $mime = '';
            if (function_exists('mime_content_type')) {
                $mime = (string)mime_content_type($real);
            }

            if ($mime === 'image/webp') {
                if (
                    !function_exists('imagecreatefromwebp') ||
                    !function_exists('imagepng')
                ) {
                    continue;
                }

                $imagen = @imagecreatefromwebp($real);
                if ($imagen === false) {
                    continue;
                }

                ob_start();
                imagepng($imagen);
                $contenidoPng = ob_get_clean();
                imagedestroy($imagen);

                if (
                    !is_string($contenidoPng) ||
                    $contenidoPng === ''
                ) {
                    continue;
                }

                return
                    'data:image/png;base64,' .
                    base64_encode($contenidoPng);
            }

            if (
                !in_array(
                    $mime,
                    ['image/png', 'image/jpeg', 'image/gif'],
                    true
                )
            ) {
                continue;
            }

            $contenido = file_get_contents($real);
            if ($contenido === false || $contenido === '') {
                continue;
            }

            return
                'data:' . $mime . ';base64,' .
                base64_encode($contenido);
        }

        return '';
    }

    private function iniciales($nombre)
    {
        $partes = preg_split(
            '/\s+/u',
            trim((string)$nombre),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (empty($partes)) {
            return 'U';
        }

        $resultado = '';
        foreach (array_slice($partes, 0, 2) as $parte) {
            $resultado .= function_exists('mb_substr')
                ? mb_substr($parte, 0, 1, 'UTF-8')
                : substr($parte, 0, 1);
        }

        return strtoupper($resultado);
    }

    private function fechaLegible($fecha)
    {
        $fecha = trim((string)$fecha);
        if ($fecha === '') {
            return '—';
        }

        try {
            return (new DateTimeImmutable($fecha))->format('d/m/Y');
        } catch (Throwable $error) {
            return $fecha;
        }
    }

    private function scope($label, $value)
    {
        return '<td><span>' .
            $this->e($label) .
            '</span><strong>' .
            $this->e($value) .
            '</strong></td>';
    }

    private function metric($label, $value)
    {
        return '<td><span>' .
            $this->e($label) .
            '</span><strong>' .
            $this->e($value) .
            '</strong></td>';
    }

    private function tdNum($value)
    {
        return '<td class="num">' .
            $this->e($value) .
            '</td>';
    }

    private function titulo($titulo)
    {
        return '<div class="section-title"><h2>' .
            $this->e($titulo) .
            '</h2></div>';
    }

    private function vistaLabel($vista)
    {
        $mapa = [
            'global' => 'Vista global',
            'equipo' => 'Mi equipo',
            'propio' => 'Mi desempeño'
        ];

        return $mapa[$vista] ?? 'Desempeño';
    }

    private function rangoPeriodo(array $periodo)
    {
        $desde = trim(
            (string)($periodo['fecha_desde'] ?? '')
        );
        $hasta = trim(
            (string)($periodo['fecha_hasta'] ?? '')
        );

        if ($desde === '' || $hasta === '') {
            return '—';
        }

        try {
            return
                (new DateTimeImmutable($desde))->format('d/m/Y') .
                ' - ' .
                (new DateTimeImmutable($hasta))->format('d/m/Y');
        } catch (Throwable $error) {
            return $desde . ' - ' . $hasta;
        }
    }

    private function nombreArchivo(array $datos)
    {
        $areaActual = (string)($datos['area'] ?? 'analistas');
        $area = $areaActual === 'cuenta_clave'
            ? 'Cuenta_Clave'
            : ($areaActual === 'marketing' ? 'Marketing' : 'Analistas');

        $alcance = [$area];
        $estadoId = max(0, (int)($datos['estado_id'] ?? 0));

        if ($estadoId > 0) {
            foreach (($datos['territorios'] ?? []) as $territorio) {
                if ((int)($territorio['id'] ?? 0) === $estadoId) {
                    $nombreEstado = trim(
                        (string)($territorio['nombre'] ?? '')
                    );
                    if ($nombreEstado !== '') {
                        $alcance[] = $nombreEstado;
                    }
                    break;
                }
            }
        }

        $vista = (string)($datos['vista'] ?? '');
        $personaId = max(0, (int)($datos['persona_id'] ?? 0));
        $ranking = is_array($datos['ranking'] ?? null)
            ? $datos['ranking']
            : [];

        if (
            ($vista === 'propio' || $personaId > 0) &&
            count($ranking) === 1
        ) {
            $nombrePersona = trim(
                (string)($ranking[0]['nombre_completo'] ?? '')
            );
            if ($nombrePersona !== '') {
                $alcance[] = $nombrePersona;
            }
        }

        return ReporteNombreArchivoService::conPeriodo(
            'Desempeno',
            $alcance,
            is_array($datos['periodo'] ?? null)
                ? $datos['periodo']
                : [],
            date('Y-m-d')
        );
    }

    private function logoDataUri()
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

            return
                'data:image/png;base64,' .
                base64_encode($contenido);
        }

        return '';
    }

    private function css()
    {
        return '@page{margin:17mm 14mm 17mm 14mm}' .
            'body{font-family:"DejaVu Sans",sans-serif;color:#263247;font-size:7pt;line-height:1.35;margin:0}' .
            '.top-rule{height:4px;background:#273A8A;margin:-17mm -14mm 10px}' .
            '.header{width:100%;border-collapse:collapse;table-layout:fixed}.brand{width:170px;vertical-align:middle}.brand img{width:145px;height:auto}' .
            '.header-copy{text-align:right;vertical-align:middle}.system-name{font-size:6pt;font-weight:700;color:#273A8A}.header h1{margin:2px 0 5px;font-size:13pt;color:#16223B}' .
            '.header-meta{margin-left:auto;border-collapse:collapse;font-size:5.8pt}.header-meta td{color:#7A8493;padding:1px 0 1px 10px}.header-meta th{padding:1px 0 1px 7px;color:#16223B;text-align:right}' .
            '.header-rule{height:2px;background:#273A8A;margin:8px 0 10px}' .
            '.scope{width:100%;table-layout:fixed;border-collapse:collapse;border:1px solid #D9E1EB;background:#F8FAFC;margin-bottom:12px}.scope td{width:25%;padding:7px 8px;border-right:1px solid #D9E1EB}.scope td:last-child{border-right:0}.scope span,.metrics span,.recognitions span{display:block;color:#737F90;font-size:5.5pt}.scope strong{display:block;margin-top:2px;color:#16223B;font-size:6.6pt}' .
            '.section{margin:0 0 13px}.keep{page-break-inside:avoid}.section-title{border-left:3px solid #273A8A;padding-left:7px;margin-bottom:7px;page-break-after:avoid}.section-title h2{margin:0;color:#16223B;font-size:10pt}' .
            '.metrics{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px 0}.metrics td{padding:8px;border:1px solid #D9E1EB;background:#F9FBFE}.metrics strong{display:block;margin-top:2px;color:#16223B;font-size:11pt}' .
            '.table{width:100%;border-collapse:collapse;font-size:5.8pt}.table thead{display:table-header-group}.table tr{page-break-inside:avoid}.table th{padding:5px 5px;background:#273A8A;color:#FFF;text-align:left}.table td{padding:5px;border-bottom:1px solid #E5EAF0;vertical-align:middle}.table tbody tr:nth-child(even){background:#F8FAFC}.table .num{text-align:right;white-space:nowrap}.table.compact{width:84%;margin-left:auto;margin-right:auto}.table.compact.has-person{width:94%}.history-table th,.history-table td{padding:3.6px 5px}.history-note{width:84%;margin:0 auto 4px;color:#7A8493;font-size:5.4pt}.inactive-analysts{width:84%;margin:5px auto 0;padding:5px 7px;border:1px solid #E1E7EF;background:#F8FAFC;color:#667386;font-size:5.4pt;line-height:1.3}.inactive-analysts strong{color:#263247}.person-column{min-width:112px}.person-wrap{display:inline-block;vertical-align:middle}.person-avatar{display:inline-block;width:20px;height:20px;margin-right:5px;border:1px solid #D7E0EC;border-radius:50%;overflow:hidden;background:#EEF3FB;color:#273A8A;text-align:center;vertical-align:middle;font-size:6.2pt;font-weight:700;line-height:20px}.person-avatar img{width:20px;height:20px;border-radius:50%}.person-avatar.is-initials{line-height:20px}.person-name{display:inline-block;max-width:118px;vertical-align:middle;line-height:1.25}' .
            '.ranking-chart-section{page-break-inside:auto}.ranking-chart-note{margin:-1px 0 7px;color:#737F90;font-size:5.6pt;line-height:1.35}.ranking-chart{width:100%;padding:7px 8px;border:1px solid #D9E1EB;background:#FBFCFE}.ranking-chart-row{page-break-inside:avoid;margin:0 0 5px}.ranking-chart-row-table{width:100%;table-layout:fixed;border-collapse:collapse}.ranking-chart-person{width:31%;padding-right:7px;vertical-align:middle}.ranking-chart-person strong{display:inline-block;width:23px;color:#273A8A;font-size:6pt}.ranking-chart-person span{display:inline-block;max-width:125px;overflow:hidden;color:#263247;font-size:6pt;font-weight:700;white-space:nowrap}.ranking-chart-bar-cell{width:59%;vertical-align:middle}.ranking-chart-track{width:100%;height:9px;table-layout:fixed;border-collapse:collapse;background:#EDF1F7}.ranking-chart-track td{height:9px;padding:0;border:0}.ranking-chart-fill{background:#273A8A}.ranking-chart-rest{background:#EDF1F7}.ranking-chart-value{width:10%;padding-left:7px;color:#16223B;font-size:6pt;font-weight:700;text-align:right;vertical-align:middle}.ranking-chart-axis{width:59%;margin:2px 10% 0 31%;table-layout:fixed;border-collapse:collapse;color:#8A94A3;font-size:5pt}.ranking-chart-axis td{text-align:left}.ranking-chart-axis .is-end{text-align:right}.recognitions{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px}.recognitions td{padding:8px;border:1px solid #D9E1EB;background:#FBFCFE}.recognitions strong{display:block;margin-top:2px;color:#16223B;font-size:7pt}.recognitions small{display:block;margin-top:2px;color:#273A8A;font-size:5.6pt;font-weight:700}' .
            '.criteria{margin:0;padding-left:17px;color:#556274;font-size:6pt}.criteria li{margin-bottom:4px}.notice{margin-top:8px;padding:7px 8px;border-left:3px solid #0A8F7A;background:#F3FAF8;color:#52635F;font-size:5.8pt}.empty{padding:10px;border:1px dashed #D9E1EB;background:#FAFBFD;color:#737F90}';
    }

    private function e($value)
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private function error($mensaje, $detalle)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'mensaje_tecnico' => (string)$detalle
        ];
    }
}
