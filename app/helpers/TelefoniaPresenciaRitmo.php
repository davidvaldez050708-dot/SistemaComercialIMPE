<?php
declare(strict_types=1);

/**
 * Reduce escrituras de presencia del POLL sin espaciar la detección de llamadas.
 * El registro se comparte por la sesión PHP autenticada (incluidas sus pestañas).
 * Sólo se confirma después de un sondeo completo y exitoso.
 */
final class TelefoniaPresenciaRitmo
{
    public const INTERVALO_SEGUNDOS = 6;
    private const CLAVE = 'impe_telefonia_presencia_ritmo_v1';

    public static function debeActualizar(array $sesion, int $usuario, bool $disponible, int $ahora): bool
    {
        $previo = $sesion[self::CLAVE] ?? null;
        if (!is_array($previo)
            || (int)($previo['usuario'] ?? 0) !== $usuario
            || (int)($previo['disponible'] ?? -1) !== (int)$disponible) {
            return true;
        }
        $ultima = (int)($previo['hora'] ?? 0);
        return $ultima <= 0 || $ahora < $ultima
            || ($ahora - $ultima) >= self::INTERVALO_SEGUNDOS;
    }

    public static function confirmar(array &$sesion, int $usuario, bool $disponible, int $ahora): void
    {
        $sesion[self::CLAVE] = [
            'usuario' => $usuario,
            'disponible' => (int)$disponible,
            'hora' => $ahora,
        ];
    }

    public static function invalidar(array &$sesion): void
    {
        unset($sesion[self::CLAVE]);
    }
}
