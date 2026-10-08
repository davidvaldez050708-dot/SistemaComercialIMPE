<?php

if (!function_exists('permisosOperativosRestringidosAdministrador')) {
    /**
     * Permisos que pertenecen a la ejecución diaria de un área.
     *
     * El Administrador conserva acceso transversal de consulta, reportes,
     * configuración y supervisión, pero no sustituye a Analistas, Cuenta Clave,
     * Marketing, Ventas o Finanzas en acciones operativas.
     */
    function permisosOperativosRestringidosAdministrador()
    {
        return [
            'prospectos.editar',
            'prospectos.asignar',
            'seguimientos_comerciales.crear',
            'seguimientos_comerciales.editar',
            'seguimientos_comerciales.editar_propios',
            'pagos.validar',
            'oficios.generar',
            'oficios.enviar',
            'reuniones.solicitar',
            'reuniones.gestionar',
            'convenios.gestionar',
            'seguimientos_vinculacion.crear',
            'seguimientos_vinculacion.editar',
            'seguimientos_vinculacion.operar_propios',
            'seguimientos_vinculacion.supervisar',
            'seguimientos_vinculacion.comentar',
            'aliados.gestionar_contactos',
            'aliados.compartir_correo',
            'aliados.preparar_whatsapp',
            'aliados.seguimiento_convocatorias',
            'whatsapp.enviar',
            'telefonia.usar',
            'telefonia.salientes',
            'telefonia.recibir',
            'telefonia.transferir',
            'convocatorias.crear',
            'convocatorias.editar',
            'convocatorias.gestionar',
            'convocatorias.cambiar_estado',
            'difusion.crear',
            'difusion.enviar',
            'difusion.gestionar'
        ];
    }
}

if (!function_exists('esAdministradorSistema')) {
    function esAdministradorSistema()
    {
        return (int)($_SESSION['rol_id'] ?? 0) === 1;
    }
}

if (!function_exists('permisoOperativoRestringidoAdministrador')) {
    function permisoOperativoRestringidoAdministrador($codigo)
    {
        return in_array(
            trim((string)$codigo),
            permisosOperativosRestringidosAdministrador(),
            true
        );
    }
}
