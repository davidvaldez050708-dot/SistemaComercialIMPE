<?php

/** Las asignaciones MySQL usan UTC; los eventos PBX se guardan en hora de México. */
final class TelefoniaAsignacionFechaHelper
{
    public static function aMexico(string $utc): string
    {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        if (!$fecha || $fecha->format('Y-m-d H:i:s') !== $utc) {
            throw new DomainException('Fecha de asignación telefónica inválida.');
        }
        return $fecha->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
    }
}
