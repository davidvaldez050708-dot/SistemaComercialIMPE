<?php
/**
 * Marketing puede compartir permisos de rol entre varias personas, pero
 * recepción solamente se muestra a quien tiene extensión PBX propia activa.
 * La asignación se verifica en servidor por petición, nunca por nombre.
 */
require_once __DIR__ . '/../services/TelefoniaExtensionService.php';

if (!function_exists('marketingTieneRecepcionAsignada')) {
    function marketingTieneRecepcionAsignada(): bool
    {
        static $resultado = null;
        if ($resultado !== null) return $resultado;
        $resultado = false;

        if (
            strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') !== 0 ||
            (int)($_SESSION['usuario_id'] ?? 0) <= 0 ||
            !tienePermiso('telefonia.usar') ||
            !tienePermiso('telefonia.recibir')
        ) {
            return false;
        }

        try {
            $asignacion = (new TelefoniaExtensionService())
                ->resolverParaUsuario((int)$_SESSION['usuario_id']);
            $extension = trim((string)($asignacion['extension'] ?? ''));
            $resultado =
                !empty($asignacion) &&
                !empty($asignacion['permite_entrantes']) &&
                (string)($asignacion['origen'] ?? '') !== 'LEGACY_CONFIG' &&
                preg_match('/^\d{3,6}$/', $extension) === 1;
        } catch (Throwable $error) {
            error_log('[marketing_recepcion_visibilidad] ' . $error->getMessage());
        }
        return $resultado;
    }
}
