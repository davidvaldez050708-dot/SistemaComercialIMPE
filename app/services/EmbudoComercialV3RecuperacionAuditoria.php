<?php
/**
 * Auditoría comercial V3 de solo lectura.
 *
 * Ningún estado de este evaluador ejecuta movimientos, tareas, llamadas,
 * notificaciones, bajas ni reactivaciones. Los intentos deben provenir
 * exclusivamente del lector PBX verificado, no del navegador.
 */
final class EmbudoComercialV3RecuperacionAuditoria
{
    public static function clasificar(string $etapa, int $intentos): array
    {
        if ($intentos < 0) {
            throw new InvalidArgumentException('Contador de intentos inválido.');
        }
        if ($etapa === 'NUEVO') {
            if ($intentos === 0) $codigo = 'SIN_INTENTOS';
            elseif ($intentos === 1) $codigo = 'INTENTO_1';
            elseif ($intentos === 2) $codigo = 'INTENTO_2';
            else $codigo = 'CANDIDATO_REVISION_3';
            return ['codigo' => $codigo, 'candidato' => $intentos >= 3];
        }
        if ($etapa === 'RECUPERACION') {
            // Un prospecto puede haber ingresado en otra jornada.
            // Tener menos de tres intentos HOY no constituye anomalía por sí solo.
            return ['codigo' => 'YA_EN_RECUPERACION', 'candidato' => false];
        }
        if ($etapa === 'EN_CONTACTO') {
            return ['codigo' => 'LEGADO_REQUIERE_REVISION', 'candidato' => false];
        }
        if ($etapa === 'DESCARTADO') {
            return ['codigo' => 'DESCARTADO_SIN_REACTIVACION', 'candidato' => false];
        }
        // CONTACTADO, POTENCIAL, INTERESADO histórico, SEGUIMIENTO,
        // PROCESO_INSCRIPCION y PAGO_CONFIRMADO histórico nunca se degradan.
        return ['codigo' => 'ETAPA_PROTEGIDA', 'candidato' => false];
    }

    public static function inconsistenciaBridgeLegacy(string $codigoEtapas, string $codigoBridge): bool
    {
        // Indicio estático, no prueba de un movimiento ocurrido en BD.
        return strpos($codigoEtapas, "return 'RECUPERACION';") !== false &&
            strpos($codigoBridge, 'sugerenciaPorResultado(') !== false &&
            strpos($codigoBridge, "if (\$nueva===\$etapaAnterior)") !== false;
    }
}
