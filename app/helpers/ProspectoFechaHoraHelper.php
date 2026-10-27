<?php
declare(strict_types=1);

/** created_at de Registro es DATETIME UTC; la disponibilidad de contacto es local. */
final class ProspectoFechaHoraHelper
{
    public static function aMexico($fecha)
    {
        if (!is_string($fecha) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D', $fecha)) return $fecha;
        $formato = strlen($fecha) > 19 ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
        $utc = DateTimeImmutable::createFromFormat($formato, $fecha, new DateTimeZone('UTC'));
        if (!$utc || substr($fecha, 0, 4) === '0000' || $utc->format('Y-m-d H:i:s') !== substr($fecha, 0, 19)) return $fecha;
        return $utc->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
    }
}
