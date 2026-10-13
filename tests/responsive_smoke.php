<?php
/**
 * Verificación estática del patrón responsive entre todos los roles.
 * No necesita la BD, ni una sesión, ni navegador.
 * Ejecutar: php tests/responsive_smoke.php
 */
$raiz = dirname(__DIR__);
$head = file_get_contents($raiz . '/app/views/layout/dashboard_head.php');
$top = file_get_contents($raiz . '/app/views/layout/topbar.php');
$css = file_get_contents($raiz . '/public/css/responsive_sistema.css');
$dashboardCss = file_get_contents($raiz . '/public/css/dashboard.css');
$usuarios = file_get_contents($raiz . '/app/views/usuarios/index.php');
$roles = file_get_contents($raiz . '/app/views/roles/index.php');
$territorios = file_get_contents($raiz . '/app/views/territorios/index.php');

$exigencias = [
    'dashboard: CSS común' => strpos($head, 'responsive_sistema.css') !== false,
    'dashboard: stylesheet con versionado' => strpos($head, "filemtime(ROOT_PATH . '/public/css/responsive_sistema.css')") !== false,
    'topbar: encabezado adaptable' => strpos($top, 'class="topbar-leading"') !== false,
    'topbar: grupo de accesos' => strpos($top, 'class="topbar-actions" role="group"') !== false,
    'tablet: ajuste topbar' => strpos($css, 'max-width: 1199.98px') !== false,
    'móvil: ajuste topbar' => strpos($css, 'max-width: 575.98px') !== false,
    'pantalla estrecha: ajuste' => strpos($css, 'max-width: 380px') !== false,
    'seguimiento: resumen adaptable' => strpos($css, '.linkage-summary-grid.metric-grid') !== false,
    'seguimiento: navegación territorial' => strpos($css, '.linkage-state-nav') !== false,
    'tablas: scroll interno' => strpos($css, '.table-responsive') !== false,
    'teclado: foco visible' => strpos($css, ':focus-visible') !== false,
    'modales: sin regla global que los estire al ancho del visor' =>
        !str_contains($css, ".admin-content .offcanvas,\n.admin-content .modal-dialog {"),
    'modales: móvil conserva ancho máximo específico' =>
        preg_match_all('/\.admin-content \.modal-dialog:not\(\.modal-fullscreen-sm-down\)\s*\{([^}]+)\}/', $css, $modalMovil) === 1 &&
        str_contains($modalMovil[1][0], 'width: calc(100% - 24px)') &&
        !str_contains($modalMovil[1][0], 'max-width:'),
    'modales: no subsiste la ampliación móvil duplicada' =>
        !str_contains($css, 'Evita modales más anchos que el visor'),
    'modales: usuarios usa formulario de 760px' =>
        str_contains($dashboardCss, ".system-form-dialog {\n    max-width: 760px;") &&
        str_contains($usuarios, 'modal-lg system-form-dialog'),
    'modales: roles y confirmaciones usan 430px' =>
        str_contains($dashboardCss, ".system-confirm-dialog {\n    max-width: 430px;") &&
        str_contains($roles, 'system-confirm-dialog'),
    'modales: equipos territoriales conservan 740px' =>
        str_contains($dashboardCss, ".territory-team-dialog {\n    max-width: 740px;") &&
        str_contains($territorios, 'system-form-dialog territory-team-dialog'),
    'modales: telefonía conserva excepción visual' =>
        str_contains($css, '#modalTelefoniaExtension .modal-dialog.telephony-modal-dialog') &&
        str_contains($css, 'width: min(650px, calc(100vw - 32px));'),
];

$falla = false;
foreach ($exigencias as $nombre => $cumple) {
    if (!$cumple) {
        fwrite(STDERR, "FALTA: {$nombre}\n");
        $falla = true;
    }
}

if ($falla) {
    exit(1);
}

echo "Responsive compartido: OK (" . count($exigencias) . " comprobaciones estáticas)\n";
