<?php
$accesosDireccion = [
    ['territorios.ver', 'territorio', 'Territorios', 'Consulta los 32 estados y sus equipos asignados.', 'map'],
    ['usuarios.ver', 'usuario', 'Equipo', 'Consulta analistas, asesores y los demás integrantes.', 'people'],
    ['seguimientos_vinculacion.ver', 'seguimientoVinculacion', 'Vinculación', 'Supervisa los seguimientos de todos los territorios.', 'kanban'],
    ['aliados.ver', 'aliado', 'Aliados', 'Consulta las instituciones aliadas a nivel nacional.', 'buildings'],
    ['prospectos.ver_todos', 'prospecto', 'Prospectos', 'Consulta registros y filtra por aliado y programa.', 'person-lines-fill'],
    ['reportes.ver', 'reporte', 'Reportes', 'Selecciona entre los reportes habilitados para tu rol.', 'file-earmark-bar-graph'],
];
$puedeAbrirReportesDireccion = tienePermiso('reportes.ver') && (
    tieneAlgunPermiso(['reportes.usuarios', 'reportes.convocatorias']) ||
    (tienePermiso('reportes.territorial') && tienePermiso('data_territorial.ver')) ||
    (tienePermiso('reportes.aliados.panorama') && tienePermiso('aliados.ver')) ||
    (tienePermiso('seguimientos_vinculacion.ver') && tieneAlgunPermiso([
        'reportes.seguimiento.cartera', 'reportes.seguimiento.actividad', 'reportes.seguimiento.institucion'
    ]))
);
$accesosDireccion = array_filter($accesosDireccion, static fn($item) =>
    $item[0] === 'reportes.ver' ? $puedeAbrirReportesDireccion : tienePermiso($item[0])
);
$textoDireccion = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<section class="dashboard-panel mb-4">
    <h2 class="panel-title">Dirección de Operaciones</h2>
    <p class="page-subtitle mb-0">Supervisión nacional. Los módulos disponibles dependen de los permisos habilitados para tu rol.</p>
</section>
<div class="row g-4">
    <?php foreach ($accesosDireccion as [, $controller, $titulo, $descripcion, $icono]): ?>
        <div class="col-12 col-md-6 col-xl-4">
            <article class="dashboard-panel h-100 d-flex flex-column align-items-start">
                <span class="metric-icon mb-3" aria-hidden="true"><i class="bi bi-<?= $textoDireccion($icono) ?>"></i></span>
                <h3 class="panel-title"><?= $textoDireccion($titulo) ?></h3>
                <p class="page-subtitle"><?= $textoDireccion($descripcion) ?></p>
                <a class="btn btn-system-save mt-auto" href="<?= $textoDireccion(BASE_URL . 'index.php?controller=' . $controller . '&action=index') ?>">
                    Consultar <?= $textoDireccion(mb_strtolower($titulo, 'UTF-8')) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i>
                </a>
            </article>
        </div>
    <?php endforeach; ?>
    <?php if (!$accesosDireccion): ?>
        <div class="col-12"><section class="dashboard-panel"><p class="page-subtitle mb-0">Aún no tienes módulos de consulta habilitados. Solicita al administrador los permisos que necesitas.</p></section></div>
    <?php endif; ?>
</div>
