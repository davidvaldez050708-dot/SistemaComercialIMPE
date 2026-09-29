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

        /*
         * Cuenta Clave, Analista de Datos y Asesor de Ventas pueden consultar
         * Gestión de Convocatorias. El controlador limita la información a los
         * territorios que cada usuario tenga asignados.
         *
         * Este permiso de lectura se resuelve también aquí para que el acceso
         * aparezca en el menú aunque la sesión se haya iniciado antes de que el
         * permiso fuera agregado a rol_permisos.
         */
        if (
            $codigo === 'convocatorias.ver' &&
            in_array($rolId, [3, 4, 6], true)
        ) {
            return true;
        }

        $permisos = $_SESSION['permisos'] ?? [];

        return in_array($codigo, $permisos, true);
    }
}
