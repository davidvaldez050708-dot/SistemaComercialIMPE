<?php
/** El tipo describe la tarea del asesor; no acredita contactos, pagos ni documentos. */
final class EmbudoComercialV3TipoActividad
{
    public const TIPOS = [
        'LLAMADA'=>'Llamar al prospecto',
        'MENSAJE'=>'Enviar mensaje o información',
        'DOCUMENTOS'=>'Revisar documentos',
        'COMPROBANTE'=>'Revisar pago o comprobante',
        'INSCRIPCION'=>'Revisar solicitud de inscripción',
        'OTRA'=>'Otra gestión',
    ];

    public static function validar(?string $tipo): string
    {
        // null queda reservado a llamadores internos anteriores a este contrato.
        if ($tipo===null) return 'GESTION';
        if (!isset(self::TIPOS[$tipo])) {
            throw new DomainException('Selecciona qué vas a hacer en la próxima actividad.');
        }
        return $tipo;
    }

    public static function etiqueta(?string $tipo): string
    {
        return self::TIPOS[$tipo??'']??'Gestión programada';
    }
}
