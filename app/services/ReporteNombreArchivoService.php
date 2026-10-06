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

    public static function seguro(string $texto, int $maximo = 64): string
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

        $limite = $conExtension ? 190 : 194;
        if (strlen($nombre) > $limite) {
            $nombre = rtrim(
                substr($nombre, 0, $limite),
                '_-'
            );
        }

        return $nombre . ($conExtension ? '.pdf' : '');
    }
}
