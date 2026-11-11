<?php
/**
 * Selector de los meses recientes de Gestión de Convocatorias.
 *
 * La rotación elimina opciones del selector, NUNCA filas de la base de datos.
 * Las convocatorias vigentes de meses anteriores siguen visibles en Activas;
 * las desactivadas antiguas están disponibles en "Otros meses / archivo".
 */
final class ConvocatoriaVentanaMensual
{
    public static function meses(?DateTimeImmutable $hoy = null): array
    {
        $actual = ($hoy ?? new DateTimeImmutable('now'))->modify('first day of this month')->setTime(0, 0);
        $inicio = $actual->modify('-3 months');
        // El historial de esta interfaz comienza con el ciclo septiembre 2026.
        // Desde enero de 2027 la ventana se desplaza automáticamente.
        $primerMes = new DateTimeImmutable('2026-09-01 00:00:00');
        if ($actual >= $primerMes && $inicio < $primerMes) {
            $inicio = $primerMes;
        }
        $nombres = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];
        $meses = [];
        for ($fecha = $inicio; $fecha <= $actual; $fecha = $fecha->modify('+1 month')) {
            $meses[] = [
                'valor' => $fecha->format('Y-m'),
                'etiqueta' => $nombres[(int)$fecha->format('n')] . ' ' . $fecha->format('Y')
            ];
        }
        return $meses;
    }

    public static function seleccionar(string $valor, array $meses): string
    {
        if ($valor === 'archivo') {
            return 'archivo';
        }
        foreach ($meses as $mes) {
            if ($valor === $mes['valor']) {
                return $valor;
            }
        }
        return '';
    }

    public static function filtrar(array $filas, string $periodo, array $meses): array
    {
        if (!$meses) {
            return $filas;
        }
        $primero = $meses[0]['valor'];
        $ultimo = $meses[count($meses) - 1]['valor'];
        return array_values(array_filter($filas, static function (array $fila) use ($periodo, $primero, $ultimo): bool {
            // La agrupación mensual existente usa fecha_inicio. No aplicar
            // fechas de vencimiento ni modificar el estatus del registro.
            $fecha = trim((string)($fila['fecha_inicio'] ?? ''));
            $mes = preg_match('/^\\d{4}-(0[1-9]|1[0-2])-\\d{2}$/D', $fecha)
                ? substr($fecha, 0, 7)
                : '';
            $estaEnVentana = $mes !== '' && $mes >= $primero && $mes <= $ultimo;
            if ($periodo === 'archivo') {
                return !$estaEnVentana;
            }
            if ($periodo !== '') {
                return $mes === $periodo;
            }
            // Nunca esconder una convocatoria aún activa por antigüedad.
            // El historial predeterminado muestra la ventana de cuatro meses.
            return $estaEnVentana || (int)($fila['estado'] ?? 0) === 1;
        }));
    }
}
