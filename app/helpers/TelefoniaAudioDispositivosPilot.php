<?php
require_once __DIR__ . '/../../config/private_config.php';

/** Activación privada: piloto o extensiones asignadas. Nunca por GET o localStorage. */
function telefoniaAudioDispositivosPilot(array $sesion, string $extension, ?array $config = null): bool
{
    try {
        $config = $config ?? impeLeerConfigPrivada('telefonia_audio.local.php');
    } catch (Throwable $e) {
        // La configuración de una función opcional no debe bloquear el teléfono.
        return false;
    }
    if (($config['enabled'] ?? false) !== true ||
        (int)($sesion['usuario_id'] ?? 0) <= 0 ||
        (int)($sesion['rol_id'] ?? 0) <= 0 || !preg_match('/^[0-9]{3,6}$/D', $extension)) {
        return false;
    }
    // La asignación activa y los permisos se comprueban además en el host.
    if (($config['scope'] ?? 'pilot') === 'assigned') return true;
    return ($config['scope'] ?? 'pilot') === 'pilot' &&
        (int)($sesion['rol_id'] ?? 0) === 3 &&
        strcasecmp(trim((string)($sesion['usuario'] ?? '')), 'Prueba.llamadas') === 0 &&
        $extension === '155';
}
