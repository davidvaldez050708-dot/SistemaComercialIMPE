<?php

require_once __DIR__ . '/AdminRolePolicy.php';
require_once __DIR__ . '/SupervisionRolePolicy.php';

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

if (!function_exists('puedeConsultarDirectorioConvenios')) {
    function puedeConsultarDirectorioConvenios()
    {
        return tieneAlgunPermiso(['convenios.ver', 'convenios.gestionar']) ||
            (
                strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0 &&
                tienePermiso('convocatorias.ver')
            );
    }
}

/**
 * Convenios sólo habilita la consulta. La publicación requiere también
 * convocatorias.crear; los roles territoriales conservan su asignación vigente.
 */
if (!function_exists('puedeCrearConvocatoriaDesdeConvenio')) {
    function puedeCrearConvocatoriaDesdeConvenio()
    {
        if (!puedeConsultarDirectorioConvenios() ||
            !tienePermiso('convocatorias.ver') ||
            !tienePermiso('convocatorias.crear')) {
            return false;
        }
        // Crear publicaciones y administrar enlaces son permisos independientes.
        return true;
    }
}

/** Sólo los tres roles operativos necesitan una asignación territorial vigente. */
function tipoAsignacionTerritorialRol(): ?string
{
    return [3 => 'ASESOR', 4 => 'ANALISTA_DATOS', 6 => 'CUENTA_CLAVE']
        [(int)($_SESSION['rol_id'] ?? 0)] ?? null;
}

/** Alcance de consulta: cada operación sigue exigiendo su permiso propio. */
if (!function_exists('puedeConsultarAliadosNacional')) {
    function puedeConsultarAliadosNacional()
    {
        return (int)($_SESSION['usuario_id'] ?? 0) > 0 &&
            (int)($_SESSION['rol_id'] ?? 0) > 0 &&
            tipoAsignacionTerritorialRol() === null;
    }
}
