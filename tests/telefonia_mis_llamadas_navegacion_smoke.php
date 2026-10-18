<?php
// Prueba sin base de datos: las acciones visibles deben seguir el rol,
 // y las pestañas deben generar URL del módulo único.
define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', '/');
$puedeGestionAnalista = true;
$puedeRecepcionMarketing = false;
$vistaActual = 'recibidas';
ob_start();
require ROOT_PATH.'/app/views/telefonia/mis_llamadas_navegacion.php';
$analista = ob_get_clean();
if (!str_contains($analista, 'Mis llamadas') && !str_contains($analista, 'Historial')) {
    fwrite(STDERR,"No aparece historial en navegación.\n");exit(1);
}
if (!str_contains($analista, 'vista=recibidas') ||
    !str_contains($analista, 'vista=devoluciones') ||
    !str_contains($analista, 'bi-telephone-inbound') ||
    str_contains($analista, 'vista=recepcion') ||
    !str_contains($analista, 'aria-current="page"')) {
    fwrite(STDERR,"FALLO: secciones de Analista incorrectas.\n");exit(1);
}
$puedeGestionAnalista = false;
$puedeRecepcionMarketing = true;
$vistaActual = 'recepcion';
ob_start();
require ROOT_PATH.'/app/views/telefonia/mis_llamadas_navegacion.php';
$marketing = ob_get_clean();
if (!str_contains($marketing, 'vista=recepcion') ||
    str_contains($marketing, 'vista=devoluciones') ||
    str_contains($marketing, 'vista=recibidas')) {
    fwrite(STDERR,"FALLO: recepción o permisos del menú.\n");exit(1);
}
$menu = file_get_contents(ROOT_PATH.'/app/views/layout/sidebar.php');
if (substr_count($menu, '$mostrarMisLlamadas') < 3 ||
    str_contains($menu, 'bi bi-telephone-history')) {
    fwrite(STDERR,"FALLO: entrada móvil/escritorio o icono inválido.\n");exit(1);
}
$vista = file_get_contents(ROOT_PATH.'/app/views/telefonia/mis_llamadas.php');
if (!str_contains($vista, 'seguimiento_llamadas_expediente.css') ||
    !str_contains($vista, 'linkage-incoming-pagination-number') ||
    !str_contains($vista, 'misLlamadasTipo')) {
    fwrite(STDERR,"FALLO: estilos y paginación no alineados.\n");exit(1);
}
echo "OK: pestañas por rol, iconos del menú, estilos y paginación numérica\n";
