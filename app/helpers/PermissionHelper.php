<?php

if (!function_exists('tienePermiso')) {
    function tienePermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            return false;
        }

        $codigo = trim((string)$codigo);

        if ($codigo === '') {
            return false;
        }

        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        /*
         * El Administrador es el único rol protegido del sistema. En la ruta
         * de vinculación conserva deliberadamente modo de observación, aunque
         * el catálogo administrativo mantenga sus relaciones completas.
         */
        if ($rolId === 1 && strpos($codigo, 'seguimientos_vinculacion.') === 0) {
            return $codigo === 'seguimientos_vinculacion.ver';
        }

        $permisos = is_array($_SESSION['permisos'] ?? null)
            ? $_SESSION['permisos']
            : [];

        return in_array($codigo, $permisos, true);
    }
}

if (!function_exists('tieneAlgunPermiso')) {
    function tieneAlgunPermiso(array $codigos)
    {
        foreach ($codigos as $codigo) {
            if (tienePermiso($codigo)) {
                return true;
            }
        }

        return false;
    }
}
