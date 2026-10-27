<?php
declare(strict_types=1);

/** Fechas comerciales en México; disponibilidad almacena sus fechas en UTC. */
final class VentasPendientesPolicy
{
    public static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
    }

    public static function programada(string $fecha, ?DateTimeImmutable $ahora = null): array
    {
        $ahora = ($ahora ?? self::ahora())->setTimezone(new DateTimeZone('America/Mexico_City'));
        $limite = new DateTimeImmutable($fecha, new DateTimeZone('America/Mexico_City'));
        return ['vencida'=>$limite <= $ahora, 'limite'=>$limite->format('Y-m-d H:i:s'),
            'fecha_iso'=>$limite->format(DateTimeInterface::ATOM),
            'avisar'=>$limite <= $ahora->modify('+15 minutes')];
    }

    public static function devolucion(array $fila, ?DateTimeImmutable $ahora = null): array
    {
        $ruta = json_decode((string)($fila['ruta_json'] ?? '{}'), true) ?: [];
        // Las antiguas conservan la fecha original de la solicitud de Recepción.
        // Nunca usar updated_at: sincronizar una transferencia no prorroga el plazo.
        $fecha = (string)($ruta['devolucion_solicitada_at'] ?? $fila['created_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $fecha)) {
            return ['vencida'=>false, 'limite'=>null, 'fecha_iso'=>null, 'solicitada_at'=>null, 'fecha_original'=>false];
        }
        $solicitud = (new DateTimeImmutable($fecha, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('America/Mexico_City'));
        $limite = $solicitud->setTime(23, 59, 59);
        return ['vencida'=>($fila['devolucion'] ?? '') === 'PENDIENTE' && ($ahora ?? self::ahora()) > $limite,
            'limite'=>$limite->format('Y-m-d H:i:s'), 'fecha_iso'=>$limite->format(DateTimeInterface::ATOM),
            'solicitada_at'=>$solicitud->format('Y-m-d H:i:s'),
            'fecha_original'=>isset($ruta['devolucion_solicitada_at'])];
    }
}
