<?php
require_once __DIR__.'/PermissionHelper.php';

/** El permiso habilita la consulta; el rol y Territorios limitan su alcance. */
final class VentasActividadAccess
{
    public static function alcance(bool $desempeno = false): string
    {
        if ((int)($_SESSION['usuario_id'] ?? 0) <= 0) return '';
        $base = $desempeno ? 'desempeno.ver' : 'reportes.ver';
        if (!tienePermiso($base) || (!$desempeno && !tienePermiso('reportes.ventas'))) return '';
        $role = (int)($_SESSION['rol_id'] ?? 0);
        $scope = [3=>'propio', 6=>'equipo', 1=>'global', 2=>'global'][$role] ?? '';
        $permission = $desempeno ? 'desempeno.ver_'.$scope : 'reportes.ventas.'.$scope;
        return $scope !== '' && tienePermiso($permission) ? $scope : '';
    }

    public static function esDesempenoAsesor(): bool
    {
        return (int)($_SESSION['rol_id'] ?? 0) === 3 || ($_GET['area'] ?? '') === 'asesores';
    }
}
