<?php
/**
 * Alcance de lectura de Información Territorial.
 *
 * El permiso data_territorial.ver se verifica en los controladores.
 * Esta política solo decide si un rol autorizado requiere asignaciones
 * territoriales o consulta el catálogo completo de Estados activos.
 */
final class DataTerritorialAccessPolicy
{
    public static function resolver(int $rolId, string $nombreRol): string
    {
        if ($rolId <= 0) {
            return '';
        }

        if ($rolId === 1) {
            return 'TODOS';
        }

        // Preservar las restricciones aunque se renombre uno de los
        // tres roles históricos que trabajan con asignaciones.
        $tiposProtegidos = [
            3 => 'ASESOR',
            4 => 'ANALISTA_DATOS',
            6 => 'CUENTA_CLAVE'
        ];
        if (isset($tiposProtegidos[$rolId])) {
            return $tiposProtegidos[$rolId];
        }

        $nombre = strtolower(trim($nombreRol));
        if ($nombre === '') {
            return '';
        }

        switch ($nombre) {
            case 'cuenta clave':
                return 'CUENTA_CLAVE';
            case 'analista de datos':
                return 'ANALISTA_DATOS';
            case 'asesor':
            case 'asesor de ventas':
                return 'ASESOR';
            default:
                return 'TODOS';
        }
    }
}
