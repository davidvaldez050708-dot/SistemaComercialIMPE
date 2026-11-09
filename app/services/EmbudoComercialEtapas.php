<?php
/**
 * Etapas comerciales vigentes y clasificación segura del resultado TELEFÓNICO.
 * La sugerencia no es evidencia de vinculación de una llamada con un prospecto.
 */
final class EmbudoComercialEtapas
{
    public const ACTIVAS = [
        'NUEVO' => 'Nuevos',
        'CONTACTADO' => 'Contactados',
        'INTERESADO' => 'Interesados',
        'SEGUIMIENTO' => 'Seguimiento',
        'PAGO_CONFIRMADO' => 'Pago confirmado / Inscripción pendiente'
    ];
    public const EXTERNAS = [
        'RECUPERACION' => 'En recuperación',
        'DESCARTADO' => 'Descartados'
    ];

    public static function todas(): array
    {
        return self::ACTIVAS + self::EXTERNAS;
    }

    public static function visual(string $etapa): string
    {
        // Compatibilidad exclusiva de visualización con la etapa antigua.
        return $etapa === 'EN_CONTACTO' ? 'RECUPERACION' : $etapa;
    }

    public static function sugerenciaPorResultado(string $resultado, ?string $proximaAccion): ?string
    {
        if (in_array($resultado, [
            'SIN_RESPUESTA', 'OCUPADO', 'BUZON_VOZ',
            'MENSAJE_AUTOMATICO', 'OTRO_SIN_CONTACTO'
        ], true)) {
            return 'RECUPERACION';
        }
        if ($resultado === 'CONVERSACION_PERSONA') return 'CONTACTADO';
        if (in_array($resultado, ['INTERESADO','SOLICITA_INFORMACION'],true)) {
            return 'INTERESADO';
        }
        if ($resultado === 'REQUIERE_SEGUIMIENTO') {
            return $proximaAccion ? 'SEGUIMIENTO' : null;
        }
        // No interesado necesita determinar si es rechazo definitivo.
        // "answered", buzón, interrupción y número erróneo NO son contacto.
        return null;
    }

    public static function requiereAcreditacion(string $etapa): bool
    {
        return $etapa === 'PAGO_CONFIRMADO';
    }
}
