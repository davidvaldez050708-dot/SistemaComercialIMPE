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
