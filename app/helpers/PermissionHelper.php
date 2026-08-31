<?php

if (!function_exists('tienePermiso')) {
    function tienePermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            return false;
        }

        if ((int)($_SESSION['rol_id'] ?? 0) === 1) {
            return true;
        }

        $permisos = $_SESSION['permisos'] ?? [];

        return in_array($codigo, $permisos, true);
    }
}
