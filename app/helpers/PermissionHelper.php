<?php

if (!function_exists('tienePermiso')) {
    function tienePermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            return false;
        }

        $rolId = (int)($_SESSION['rol_id'] ?? 0);
        $codigo = trim((string)$codigo);

        if ($rolId === 1) {
            /*
             * En Seguimiento de vinculación el Administrador funciona como
             * observador general: puede consultar todos los territorios y
             * expedientes, pero no crear, editar, comentar ni operar la ruta.
             * En el resto del sistema conserva el comportamiento administrativo
             * habitual.
             */
            if (strpos($codigo, 'seguimientos_vinculacion.') === 0) {
                return $codigo === 'seguimientos_vinculacion.ver';
            }

            return true;
        }

        $permisos = $_SESSION['permisos'] ?? [];

        return in_array($codigo, $permisos, true);
    }
}
