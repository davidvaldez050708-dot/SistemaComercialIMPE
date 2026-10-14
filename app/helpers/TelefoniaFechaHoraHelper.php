<?php

/**
 * Fechas de sesiones WebRTC del analista: las genera MySQL en UTC.
 * Los DATETIME son ingenuos (sin offset), así que convertimos únicamente
 * los registros ZADARMA_WEBRTC, nunca las interacciones locales tradicionales.
 *
 * Usar sólo para presentación/lectura: no reescribe registros históricos.
 */
final class TelefoniaFechaHoraHelper
{
    public static function aMexico(string $fechaUtc): string
    {
        $fechaUtc = trim($fechaUtc);
        if ($fechaUtc === '') {
            return '';
        }

        try {
            $utc = new DateTimeZone('UTC');
            $mexico = new DateTimeZone('America/Mexico_City');
            $fecha = DateTimeImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                $fechaUtc,
                $utc
            );
            if (!$fecha || $fecha->format('Y-m-d H:i:s') !== $fechaUtc) {
                return $fechaUtc;
            }
            return $fecha->setTimezone($mexico)->format('Y-m-d H:i:s');
        } catch (Throwable $error) {
            return $fechaUtc;
        }
    }

    public static function normalizarInteraccion(array $interaccion): array
    {
        if (strtoupper(trim((string)($interaccion['proveedor_externo'] ?? '')))
            !== 'ZADARMA_WEBRTC') {
            return $interaccion;
        }

        foreach (['fecha_inicio', 'fecha_fin'] as $campo) {
            if (!empty($interaccion[$campo])) {
                $interaccion[$campo] = self::aMexico((string)$interaccion[$campo]);
            }
        }
        return $interaccion;
    }
}
