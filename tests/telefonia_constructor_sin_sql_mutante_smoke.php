<?php
declare(strict_types=1);

/**
 * Contrato estático: no realiza llamadas a MySQL, no abre sesiones
 * y no modifica ningún archivo. Completar con prueba HTTP/LSAPI.
 */
$root = dirname(__DIR__);
$index = file_get_contents($root . '/index.php');
$login = file_get_contents($root . '/app/controllers/LoginController.php');
$controller = file_get_contents($root . '/app/controllers/TelefoniaController.php');

$inicio = strpos($controller, 'public function __construct()');
$fin = strpos($controller, '/**', $inicio + 1);
if ($inicio === false || $fin === false) {
    throw new RuntimeException('Constructor TelefoniaController no localizable.');
}
$constructor = substr($controller, $inicio, $fin - $inicio);
if (strpos($constructor, 'inicializarPermisosSistema') !== false
    || strpos($constructor, 'new RolModel') !== false) {
    throw new RuntimeException('Reaprovisionamiento de permisos en cada solicitud.');
}
if (strpos($constructor, 'new TelefoniaExtensionService(Database::compartida())') === false) {
    throw new RuntimeException('ExtensionService no reutiliza conexión compartida.');
}
$refrescar = strpos($index, '$modeloRolSesion->obtenerCodigosPermisosPorRol(');
$telefono = strpos($index, 'new TelefoniaController()');
if ($refrescar === false || $telefono === false || $refrescar > $telefono) {
    throw new RuntimeException('El router no revalida permisos antes de telefonía.');
}
if (strpos($login, '->prepararCatalogoParaAutenticacion()') === false) {
    throw new RuntimeException('Autenticación ya no prepara catálogo de permisos.');
}
echo "PASS: sin siembra en POLL, permisos revalidados e inyección compartida.\n";
