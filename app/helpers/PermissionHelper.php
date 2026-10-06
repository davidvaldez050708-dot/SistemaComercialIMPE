<?php

require_once __DIR__ . '/AdminRolePolicy.php';

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
         * El Administrador es un rol transversal de supervisión y gobierno del
         * sistema. Puede consultar información de todas las áreas, pero no
         * ejecutar acciones operativas que corresponden al trabajo diario de
         * Analistas, Cuenta Clave, Marketing, Ventas o Finanzas.
         */
        if (
            $rolId === 1 &&
            permisoOperativoRestringidoAdministrador($codigo)
        ) {
            return false;
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
