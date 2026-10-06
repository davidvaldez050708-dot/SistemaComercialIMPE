<?php

/**
 * Convención única para nombres de archivos de reportes.
 *
 * - Reportes con periodo: Tipo_Alcance_Periodo_FechaInicial_a_FechaFinal.pdf
 * - Históricos: Tipo_Alcance_Historico_hasta_FechaCorte.pdf
 * - Estado actual: Tipo_Alcance_Corte_Fecha.pdf
 */
class ReporteNombreArchivoService
{
    public static function conPeriodo(
        string $tipo,
        array $alcance,
        array $periodo,
        ?string $fechaCorte = null,
        bool $conExtension = true
    ): string {
        $segmentos = [self::seguro($tipo)];

        foreach ($alcance as $parte) {
            $parte = self::seguro((string)$parte);
            if ($parte !== '') {
                $segmentos[] = $parte;
            }
        }

        $segmentos[] = self::descriptorPeriodo(
            $periodo,
            $fechaCorte
        );

        return self::finalizar($segmentos, $conExtension);
    }

    public static function corte(
        string $tipo,
        array $alcance = [],
        ?string $fechaCorte = null,
        bool $conExtension = true
    ): string {
        $segmentos = [self::seguro($tipo)];

        foreach ($alcance as $parte) {
            $parte = self::seguro((string)$parte);
            if ($parte !== '') {
                $segmentos[] = $parte;
            }
        }

        $segmentos[] =
            'Corte_' .
            self::fechaSegura($fechaCorte ?: date('Y-m-d'));

        return self::finalizar($segmentos, $conExtension);
    }

    public static function simple(
        string $tipo,
        array $alcance = [],
        bool $conExtension = true
    ): string {
        $segmentos = [self::seguro($tipo)];

        foreach ($alcance as $parte) {
            $parte = self::seguro((string)$parte);
            if ($parte !== '') {
                $segmentos[] = $parte;
            }
        }

        return self::finalizar($segmentos, $conExtension);
    }

    public static function seguimiento(
        array $datos,
        bool $conExtension = true
    ): string {
        $filtros = is_array($datos['filtros_reporte'] ?? null)
            ? $datos['filtros_reporte']
            : [];
        $resumen = is_array($datos['resumen_filtros'] ?? null)
            ? $datos['resumen_filtros']
            : [];

        $tipo = strtolower(trim(
            (string)($filtros['tipo_reporte'] ?? 'cartera')
        ));
        if (!in_array($tipo, ['cartera', 'actividad', 'institucion'], true)) {
            $tipo = 'cartera';
        }

        $nombres = [
            'cartera' => 'Seguimiento_Cartera',
            'actividad' => 'Seguimiento_Actividad',
            'institucion' => 'Seguimiento_Institucion'
        ];

        $alcance = [];
        $institucion = trim(
            (string)($resumen['Institución'] ?? '')
        );
        $responsable = trim(
            (string)($resumen['Responsable'] ?? '')
        );
        $estado = trim(
            (string)($resumen['Estado'] ?? '')
        );
        $municipio = trim(
            (string)($resumen['Municipio'] ?? '')
        );

        if (
            $tipo === 'institucion' &&
            $institucion !== '' &&
            strcasecmp($institucion, 'Todas') !== 0
        ) {
            $alcance[] = $institucion;
        } elseif (
            $responsable !== '' &&
            strcasecmp($responsable, 'Todos') !== 0
        ) {
            $alcance[] = $responsable;
        }

        if (
            $municipio !== '' &&
            strcasecmp($municipio, 'Todos') !== 0
        ) {
            $alcance[] = $municipio;
        } elseif (
            $estado !== '' &&
            strcasecmp($estado, 'Todos') !== 0
        ) {
            $alcance[] = $estado;
        }

        if ($tipo === 'actividad') {
            return self::conPeriodo(
                $nombres[$tipo],
                $alcance,
                [
                    'clave' => '',
                    'fecha_desde' => (string)(
                        $filtros['fecha_inicial'] ?? ''
                    ),
                    'fecha_hasta' => (string)(
                        $filtros['fecha_final'] ?? ''
                    )
                ],
                date('Y-m-d'),
                $conExtension
            );
        }

        return self::corte(
            $nombres[$tipo],
            $alcance,
            date('Y-m-d'),
            $conExtension
        );
    }

    public static function seguro(string $texto, int $maximo = 36): string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return '';
        }

        $ascii = @iconv(
            'UTF-8',
            'ASCII//TRANSLIT//IGNORE',
            $texto
        );
        if ($ascii !== false) {
            $texto = $ascii;
        }

        $texto = preg_replace(
            '/[^A-Za-z0-9_-]+/',
            '_',
            $texto
        );
        $texto = preg_replace('/_+/', '_', (string)$texto);
        $texto = trim((string)$texto, '_-');

        if ($texto === '') {
            return '';
        }

        if (strlen($texto) > $maximo) {
            $texto = rtrim(
                substr($texto, 0, $maximo),
                '_-'
            );
        }

        return $texto;
    }

    private static function descriptorPeriodo(
        array $periodo,
        ?string $fechaCorte
    ): string {
        $clave = strtolower(trim(
            (string)($periodo['clave'] ?? '')
        ));
        $desde = self::fechaValida(
            (string)($periodo['fecha_desde'] ?? '')
        );
        $hasta = self::fechaValida(
            (string)($periodo['fecha_hasta'] ?? '')
        );

        if ($clave === 'historico') {
            return
                'Historico_hasta_' .
                self::fechaSegura(
                    $fechaCorte ?: date('Y-m-d')
                );
        }

        $etiquetas = [
            'hoy' => 'Hoy',
            'ayer' => 'Ayer',
            'semana' => 'Esta_Semana',
            'ultimos_7' => 'Ultimos_7_Dias',
            'ultimos_30' => 'Ultimos_30_Dias',
            'mes' => 'Este_Mes',
            'este_mes' => 'Este_Mes',
            'mes_anterior' => 'Mes_Anterior',
            'personalizado' => 'Personalizado'
        ];

        $etiqueta = $etiquetas[$clave] ?? '';

        if ($desde !== '' && $hasta !== '') {
            if ($desde === $hasta && $etiqueta === '') {
                return 'Dia_' . $desde;
            }

            if ($etiqueta === '') {
                $etiqueta = 'Periodo';
            }

            return
                $etiqueta . '_' .
                $desde . '_a_' . $hasta;
        }

        if ($desde !== '') {
            return
                ($etiqueta !== '' ? $etiqueta . '_' : '') .
                'Desde_' . $desde;
        }

        if ($hasta !== '') {
            return
                ($etiqueta !== '' ? $etiqueta . '_' : '') .
                'Hasta_' . $hasta;
        }

        if ($etiqueta !== '') {
            return
                $etiqueta . '_Corte_' .
                self::fechaSegura(
                    $fechaCorte ?: date('Y-m-d')
                );
        }

        return
            'Corte_' .
            self::fechaSegura(
                $fechaCorte ?: date('Y-m-d')
            );
    }

    private static function fechaValida(string $fecha): string
    {
        $fecha = trim($fecha);
        if ($fecha === '') {
            return '';
        }

        $fecha = substr($fecha, 0, 10);
        $objeto = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $fecha
        );

        return (
            $objeto &&
            $objeto->format('Y-m-d') === $fecha
        )
            ? $fecha
            : '';
    }

    private static function fechaSegura(string $fecha): string
    {
        $normalizada = self::fechaValida($fecha);
        return $normalizada !== ''
            ? $normalizada
            : date('Y-m-d');
    }

    private static function finalizar(
        array $segmentos,
        bool $conExtension
    ): string {
        $segmentos = array_values(array_filter(
            array_map(
                static fn($valor) => trim((string)$valor, '_'),
                $segmentos
            ),
            static fn($valor) => $valor !== ''
        ));

        $nombre = implode('_', $segmentos);
        if ($nombre === '') {
            $nombre = 'Reporte';
        }

        $limite = $conExtension ? 145 : 149;
        if (strlen($nombre) > $limite) {
            $nombre = rtrim(
                substr($nombre, 0, $limite),
                '_-'
            );
        }

        return $nombre . ($conExtension ? '.pdf' : '');
    }
}
