<?php
require_once __DIR__ . '/../../config/private_config.php';

/** Lista cerrada del piloto. Nunca se activa mediante GET, JS ni localStorage. */
function telefoniaAudioDispositivosPilot(array $sesion, string $extension, ?array $config = null): bool
{
    try {
        $config = $config ?? impeLeerConfigPrivada('telefonia_audio.local.php');
    } catch (Throwable $e) {
        // La configuración de una función opcional no debe bloquear el teléfono.
        return false;
    }
    return ($config['enabled'] ?? false) === true &&
        (int)($sesion['usuario_id'] ?? 0) > 0 &&
        (int)($sesion['rol_id'] ?? 0) === 3 &&
        strcasecmp(trim((string)($sesion['usuario'] ?? '')), 'Prueba.llamadas') === 0 &&
        $extension === '155';
}
