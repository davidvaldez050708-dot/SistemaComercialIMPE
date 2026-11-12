<?php
require_once __DIR__.'/../helpers/PermissionHelper.php';

/**
 * Acceso gradual a la INTERFAZ operativa V3 de asesores.
 *
 * Separado deliberadamente del piloto de decisiones automáticas PBX:
 * activar esta pantalla NO habilita los tres intentos ni mueve llamadas.
 * No admite activación por GET, POST, cookie o JavaScript.
 *
 * Sin ambas variables exactas de entorno, permanece desactivado.
 */
final class EmbudoComercialV3AccesoControlado
{
    private const LLAVE = 'PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111';

    public static function habilitadoParaAsesor(int $usuarioId): bool
    {
        if ($usuarioId<=0 ||
            (int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3 ||
            !tienePermiso('ventas.embudo.ver') ||
            getenv('IMPE_VENTAS_V3_UI')!==self::LLAVE) {
            return false;
        }
        $ids=getenv('IMPE_VENTAS_V3_UI_ASESORES');
        if (!is_string($ids) ||
            !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*){0,19}$/D',$ids)) {
            return false;
        }
        foreach(explode(',',$ids) as $txt) {
            $validado=filter_var($txt,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX]]);
            if($validado===$usuarioId) return true;
        }
        return false;
    }
}
