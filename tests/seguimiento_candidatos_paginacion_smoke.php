<?php
/**
 * Auditoría estática de integración de paginación sin conexión a DENUE/DB.
 * php tests/seguimiento_candidatos_paginacion_smoke.php
 */
$root = dirname(__DIR__);
$vista = file_get_contents($root . '/app/views/seguimiento_vinculacion/estado.php');
$css = file_get_contents($root . '/public/css/seguimiento_filtros_layout.css');
$pie = file_get_contents($root . '/app/views/layout/dashboard_footer.php');
$controlador = file_get_contents($root . '/app/controllers/SeguimientoVinculacionController.php');
$checks = [
    'Candidatos completos viajan en respuesta inicial' =>
        str_contains($controlador, "'cache_candidatos' => $busquedaAutomatica ? $candidatosCompletos : []"),
    'Se utiliza utilidad de páginas locales' =>
        str_contains($vista, 'util.crearPagina(') &&
        str_contains($vista, 'catalogoAutomaticoCompleto'),
    'Validación de filtros y municipio' =>
        str_contains($vista, 'catalogo.firma !== firmaBusquedaActual()'),
    'Se muestra pagina local sin nuevas solicitudes' =>
        str_contains($vista, 'if (mostrarPaginaLocal(proxima)) {') &&
        str_contains($vista, 'buscarCandidatos(busquedaAutomaticaActiva, true, true, anterior);'),
    'Paginación remota conserva las tarjetas' =>
        str_contains($vista, 'if (paginando) {') &&
        str_contains($vista, 'marcarPaginacionEnCurso(true)') &&
        str_contains($vista, 'if (!hayCoincidenciasCache)'),
    'Conserva la página anterior en error' =>
        str_contains($vista, 'paginaCandidatos = paginaAnterior;') &&
        str_contains($vista, 'Conservamos los resultados anteriores'),
    'La selección de candidato se conserva' =>
        str_contains($vista, 'candidatosActuales[parseInt(boton.dataset.candidateIndex'),
    'El modal sigue con sus controles' =>
        str_contains($vista, 'data-candidate-page-prev') &&
        str_contains($vista, 'data-candidate-page-next') &&
        str_contains($vista, 'data-candidate-results'),
    'Carga discreta accesible' =>
        str_contains($vista, 'data-candidate-page-loading') &&
        str_contains($vista, 'aria-live="polite"') &&
        str_contains($vista, 'aria-busy'),
    'No altera modales no relacionados' =>
        str_contains($css, '#modalBuscarCandidato .linkage-candidate-results.is-page-loading') &&
        !str_contains($css, '#modalConfirmarSeguimiento .linkage-candidate-results.is-page-loading'),
    'Script se inyecta en el perfil de Seguimiento' =>
        str_contains($pie, "'seguimiento_candidatos_paginacion.js'"),
];

foreach ($checks as $nombre => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALLO: $nombre\n");
        exit(1);
    }
}
echo 'Paginación de candidatos: OK (' . count($checks) . " comprobaciones estáticas)\n";
