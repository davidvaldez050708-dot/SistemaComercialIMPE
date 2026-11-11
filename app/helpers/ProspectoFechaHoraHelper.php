<?php
declare(strict_types=1);

/** created_at de Registro es DATETIME UTC; la disponibilidad de contacto es local. */
final class ProspectoFechaHoraHelper
{
    public static function rango(array $filtros): array
    {
        $zona = new DateTimeZone('America/Mexico_City');
        $periodo = $filtros['periodo'] ?? '';
        if (!in_array($periodo, ['', 'hoy', 'ayer'], true)) throw new DomainException('Periodo no válido.');
        $rango = [];
        foreach (['desde', 'hasta'] as $campo) {
            $valor = $filtros[$campo] ?? '';
            if ($periodo !== '') $valor = (new DateTimeImmutable($periodo === 'hoy' ? 'today' : 'yesterday', $zona))->format('Y-m-d');
            if ($valor === '') {$rango[] = null; continue;}
            $fecha = is_string($valor) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor, $zona) : false;
            if (!$fecha || $fecha->format('Y-m-d') !== $valor) throw new DomainException('Fecha no válida.');
            $rango[] = ($campo === 'hasta' ? $fecha->modify('+1 day') : $fecha)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($rango[0] && $rango[1] && $rango[0] >= $rango[1]) throw new DomainException('Rango de fechas no válido.');
        return $rango;
    }

    public static function aMexico($fecha)
    {
        if (!is_string($fecha) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D', $fecha)) return $fecha;
        $formato = strlen($fecha) > 19 ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
        $utc = DateTimeImmutable::createFromFormat($formato, $fecha, new DateTimeZone('UTC'));
        if (!$utc || substr($fecha, 0, 4) === '0000' || $utc->format('Y-m-d H:i:s') !== substr($fecha, 0, 19)) return $fecha;
        return $utc->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
    }
}
