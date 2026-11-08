<?php
require_once __DIR__.'/PermissionHelper.php';

/**
 * El tablero no hereda prospectos.ver_todos ni Mi cartera.
 * Cada vista tiene autorización propia; supervisión siempre es de lectura.
 */
final class EmbudoComercialAcceso
{
    public const VISTAS = [
        'embudo' => 'ventas.embudo.ver',
        'recuperacion' => 'ventas.recuperacion.ver',
        'descartados' => 'ventas.descartados.ver'
    ];

    public static function puede(string $vista): bool
    {
        if (!isset(self::VISTAS[$vista]) || (int)($_SESSION['usuario_id']??0)<1) {
            return false;
        }
        $rol=(int)($_SESSION['rol_id']??0);
        if ($rol===1) {
            return tienePermiso('ventas.embudo.supervisar');
        }
        return $rol===3 && tienePermiso(self::VISTAS[$vista]);
    }

    public static function esSupervisor(): bool
    {
        return (int)($_SESSION['rol_id']??0)===1 &&
            self::puede('embudo');
    }
}
