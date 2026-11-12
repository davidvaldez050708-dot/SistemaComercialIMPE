<?php
require_once __DIR__.'/../helpers/PermissionHelper.php';

/**
 * Acceso gradual a la INTERFAZ operativa V3 de asesores.
 *
 * Separado deliberadamente del piloto de decisiones automáticas PBX:
 * activar esta pantalla NO habilita los tres intentos ni mueve llamadas.
 * No admite activación por GET, POST, cookie o JavaScript.
 *
 * Sin configuración privada válida ni las dos variables antiguas,
 * permanece desactivado.
 */
final class EmbudoComercialV3AccesoControlado
{
    private const LLAVE = 'PERMITIR_SOLO_PANTALLA_V3_ASESORES_IMPE_20261111';

    /**
     * Activación privada fuera de public_html para el embudo de todos los
     * asesores autorizados. No requiere IDs fijos: el rol y el permiso se
     * validan ANTES de consultar el indicador de activación.
     *
     * La configuración no es ejecutable. Sin archivo válido, V3 sigue apagada
     * salvo que esté habilitado el mecanismo anterior de lista explícita.
     */
    private static function habilitacionPrivada(): bool
    {
        // __DIR__: dominio/public_html/app/services
        // dirname(...,3): dominio; la configuración queda FUERA de public_html.
        $directorio = dirname(__DIR__, 3) . '/private-impe';
        $archivo = $directorio . '/ventas_v3_asesores.json';

        if (is_link($directorio) || is_link($archivo) ||
            !is_file($archivo) || !is_readable($archivo)) {
            return false;
        }

        $contenido = @file_get_contents($archivo);
        if (!is_string($contenido) || strlen($contenido) > 1024) {
            return false;
        }
        $config = json_decode($contenido, true, 8);
        return is_array($config) &&
            ($config['version'] ?? null) === 1 &&
            ($config['habilitado'] ?? null) === true &&
            ($config['audiencia'] ?? null) === 'asesores_rol_3_con_permiso';
    }

    public static function habilitadoParaAsesor(int $usuarioId): bool
    {
        if ($usuarioId<=0 ||
            (int)($_SESSION['usuario_id']??0)!==$usuarioId ||
            (int)($_SESSION['rol_id']??0)!==3 ||
            !tienePermiso('ventas.embudo.ver')) {
            return false;
        }
        if (self::habilitacionPrivada()) {
            return true;
        }
        // Compatibilidad: conserva el piloto original por lista de IDs.
        if (getenv('IMPE_VENTAS_V3_UI') !== self::LLAVE) {
            return false;
        }
        $ids=getenv('IMPE_VENTAS_V3_UI_ASESORES');
        if (!is_string($ids) ||
            !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*){0,19}$/D',$ids)) {
            return false;
        }
        $autorizados=[];
        foreach(explode(',',$ids) as $txt) {
            $validado=filter_var($txt,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>1,'max_range'=>PHP_INT_MAX]]);
            // La lista completa es inválida si UNO de sus elementos
            // rebasa PHP_INT_MAX; no aceptar la coincidencia previa.
            if($validado===false) return false;
            $autorizados[$validado]=true;
        }
        return isset($autorizados[$usuarioId]);
    }
}
