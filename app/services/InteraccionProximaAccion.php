<?php

/** Programación de atención, independiente de la verificación y de la etapa. */
final class InteraccionProximaAccion
{
    public static function validar(array $datos, ?int $ahora = null): array
    {
        foreach (['resultado','proxima_accion','proxima_accion_at','fecha_inicio'] as $campo) {
            if (isset($datos[$campo]) && !is_scalar($datos[$campo])) {
                throw new DomainException('La programación de la próxima acción no es válida.');
            }
        }
        $resultado = strtoupper(trim((string)($datos['resultado'] ?? '')));
        $accion = trim((string)($datos['proxima_accion'] ?? ''));
        $valor = trim((string)($datos['proxima_accion_at'] ?? ''));
        if ($resultado === 'NO_INTERESADO') return ['accion'=>'', 'fecha'=>null];
        if ($resultado === 'SOLICITO_LLAMAR_DESPUES') {
            if ($accion !== '' && $accion !== 'Volver a llamar') {
                throw new DomainException('Para este resultado, la próxima acción debe ser Volver a llamar.');
            }
            $accion = 'Volver a llamar';
        }
        if (in_array($accion, ['Verificar información de contacto', 'Generar oficio'], true)) {
            return ['accion'=>$accion, 'fecha'=>null];
        }
        $programables = ['Volver a llamar','Enviar WhatsApp','Confirmar contacto de RH',
            'Revisar correo','Preparar oficio','Investigar nuevo contacto','Enviar oficio/correo'];
        if ($accion !== '' && !in_array($accion, $programables, true)) {
            throw new DomainException('Selecciona una próxima acción válida.');
        }
        if ($accion === '') {
            if ($valor !== '') throw new DomainException('Selecciona la acción que deseas programar.');
            return ['accion'=>'', 'fecha'=>null];
        }
        if ($valor === '') throw new DomainException('Indica la fecha y hora de la próxima acción.');
        $fecha = null;
        foreach (['Y-m-d\TH:i','Y-m-d H:i:s','Y-m-d H:i'] as $formato) {
            $candidata = DateTimeImmutable::createFromFormat('!'.$formato, $valor, new DateTimeZone('America/Mexico_City'));
            if ($candidata && $candidata->format($formato) === $valor) { $fecha=$candidata; break; }
        }
        $interaccion = strtotime((string)($datos['fecha_inicio'] ?? '')) ?: 0;
        if (!$fecha || $fecha->getTimestamp() <= max($ahora ?? time(), $interaccion)) {
            throw new DomainException('La próxima acción debe programarse en una fecha y hora futuras válidas.');
        }
        return ['accion'=>$accion, 'fecha'=>$fecha->format('Y-m-d H:i:s')];
    }

    /** Distingue una programación nueva de un recordatorio anterior a la verificación. */
    public static function condicionSql(string $alias): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Alias inválido.');
        return "EXISTS (SELECT 1 FROM interacciones_vinculacion accion_programada
            WHERE accion_programada.seguimiento_id = {$alias}.id
              AND accion_programada.notas LIKE '%Próxima acción: %'
              AND LOCATE(CONCAT('Fecha próxima acción: ', DATE_FORMAT({$alias}.proxima_accion_at, '%Y-%m-%d %H:%i:%s')), accion_programada.notas) > 0)";
    }
}
