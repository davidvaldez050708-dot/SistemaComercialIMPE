<?php

/**
 * Resuelve la configuración PRIVADA del servidor sin publicarla en Git.
 *
 * Hostinger: colocar los PHP privados en el directorio hermano:
 *   domains/tu-dominio/impe-private/
 *   domains/tu-dominio/public_html/
 *
 * En XAMPP se mantiene compatibilidad con config/*.local.php (ignorado por Git).
 * También se admite IMPE_PRIVATE_CONFIG_DIR si el servidor lo proporciona.
 */
function impeRutaConfigPrivada(string $archivo): ?string
{
    if (!preg_match('/^[a-zA-Z0-9_.-]+\.php$/', $archivo) ||
        basename($archivo) !== $archivo) {
        return null;
    }

    $raiz = dirname(__DIR__);
    $directorios = [];
    $carpetaExplicita = trim((string)(getenv('IMPE_PRIVATE_CONFIG_DIR') ?: ''));
    if ($carpetaExplicita !== '') {
        $directorios[] = $carpetaExplicita;
    }

    if (basename($raiz) === 'public_html') {
        $directorios[] = dirname($raiz) . '/impe-private';
    }

    // Compatibilidad con XAMPP y instalaciones anteriores; el directorio
    // config/ sigue bloqueado para solicitudes HTTP mediante .htaccess.
    $directorios[] = __DIR__;

    foreach ($directorios as $directorio) {
        $ruta = rtrim($directorio, '/\\') . DIRECTORY_SEPARATOR . $archivo;
        if (is_file($ruta) && is_readable($ruta)) {
            return $ruta;
        }
    }

    return null;
}

function impeLeerConfigPrivada(string $archivo): array
{
    $ruta = impeRutaConfigPrivada($archivo);
    if ($ruta === null) {
        return [];
    }

    $datos = require $ruta;
    return is_array($datos) ? $datos : [];
}
