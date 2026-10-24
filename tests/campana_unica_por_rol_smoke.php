<?php
/** Test real de las reglas de campana sin BD ni sesion productiva. */
declare(strict_types=1);
$root = dirname(__DIR__);
define('ROOT_PATH', $root);
$source = (string)file_get_contents($root.'/app/views/layout/topbar.php');
$inicio = strpos($source, '$puedeSolicitarReuniones =');
$fin = strpos($source, "/*\n * La campana", $inicio);
if ($inicio === false || $fin === false) throw new RuntimeException('No se identifico el bloque de configuracion de topbar');
$codigo = substr($source, $inicio, $fin - $inicio);
$GLOBALS['permisos_prueba'] = [];
function tienePermiso($permiso): bool { return in_array($permiso, $GLOBALS['permisos_prueba'], true); }
function marketingTieneRecepcionAsignada(): bool { return true; }
$casos = [
    ['Cuenta Clave con permisos compartidos de Marketing', 6, 'Cuenta Clave', ['convocatorias.gestionar', 'aliados.seguimiento_convocatorias'], false, true],
    ['Cuenta Clave con permisos de convocatorias y reuniones', 6, 'Cuenta Clave', ['convocatorias.gestionar', 'reuniones.ver', 'reuniones.gestionar'], false, true],
    ['Marketing con permisos de seguimiento de Aliados', 5, 'Marketing', ['convocatorias.gestionar', 'aliados.seguimiento_convocatorias'], true, false],
    ['Marketing habitual', 5, 'Marketing', ['convocatorias.gestionar'], true, false],
    ['Analista', 4, 'Analista de Datos', ['reuniones.ver', 'reuniones.solicitar'], false, true],
    ['Asesor con telefonia', 3, 'Asesor de Ventas', ['telefonia.usar', 'telefonia.recibir'], false, true],
    ['Administrador', 1, 'Administrador', ['convocatorias.gestionar'], false, true],
    ['Sin permisos de notificacion', 6, 'Cuenta Clave', [], false, false],
];
foreach ($casos as $caso) {
    [$nombre, $rolId, $rol, $permisos, $esperadoMarketing, $esperadoGeneral] = $caso;
    $_SESSION = ['usuario_id'=>555, 'rol_id'=>$rolId, 'rol'=>$rol];
    $GLOBALS['permisos_prueba'] = $permisos;
    eval($codigo);
    if ($esMarketingTopbar !== $esperadoMarketing || $mostrarCentroAvisos !== $esperadoGeneral) {
        throw new RuntimeException('Campana incorrecta: '.$nombre.' marketing='.($esMarketingTopbar?'1':'0').' general='.($mostrarCentroAvisos?'1':'0'));
    }
    if ((int)$esMarketingTopbar + (int)$mostrarCentroAvisos > 1) throw new RuntimeException('Dos campanas: '.$nombre);
}
if (substr_count($source,'<?php if ($esMarketingTopbar): ?>') !== 1 ||
    substr_count($source,'<?php if ($mostrarCentroAvisos): ?>') !== 1 ||
    substr_count($source,'data-convocatoria-notification-root') !== 1 ||
    substr_count($source,'data-reminder-root') !== 1) {
    throw new RuntimeException('Marcadores o condicionales de campanas duplicados');
}
echo "OK: campana unica por rol; ".count($casos)." escenarios, controles y DOM intactos\n";
