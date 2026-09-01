<?php

$territorios = $territorios ?? [];

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$pluralSeguimientos = function ($total) {
    return (int)$total === 1 ? 'seguimiento' : 'seguimientos';
};

?>

<?php if (!empty($mensajeError)): ?>
    <div class="alert alert-danger login-alert mb-3" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <span><?= $texto($mensajeError) ?></span>
    </div>
<?php endif; ?>

<section class="dashboard-panel linkage-panel">
    <div class="linkage-heading">
        <div>
            <span>VINCULACIÓN</span>
            <h2>Seguimiento de vinculación</h2>
            <p>
                Selecciona uno de tus territorios asignados para gestionar
                el seguimiento institucional.
            </p>
        </div>
    </div>
</section>

<?php if (!empty($territorios)): ?>
    <section class="linkage-territory-grid" aria-label="Territorios asignados">
        <?php foreach ($territorios as $territorio): ?>
            <?php
                $totalSeguimientos = (int)($territorio['total_seguimientos'] ?? 0);
                $esPrincipal = (int)($territorio['es_principal'] ?? 0) === 1;
            ?>
            <article class="dashboard-panel linkage-territory-card">
                <div class="linkage-territory-title">
                    <span class="linkage-territory-icon">
                        <i class="bi bi-geo-alt"></i>
                    </span>
                    <div>
                        <h3><?= $texto($territorio['nombre'] ?? '') ?></h3>
                        <p>
                            <?= $esPrincipal
                                ? 'Territorio principal'
                                : 'Territorio asignado' ?>
                        </p>
                    </div>
                </div>

                <div class="linkage-territory-count">
                    <strong><?= $totalSeguimientos ?></strong>
                    <span><?= $pluralSeguimientos($totalSeguimientos) ?></span>
                </div>

                <a
                    class="btn btn-system-light linkage-territory-action"
                    href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=estado&estado_id=<?= (int)$territorio['id'] ?>">
                    Ver seguimiento
                    <i class="bi bi-arrow-right"></i>
                </a>
            </article>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="dashboard-panel data-empty-state linkage-empty-state">
        <span>
            <i class="bi bi-map"></i>
        </span>
        <strong>No tienes territorios asignados actualmente.</strong>
        <p>
            Cuando se te asigne un Estado podrás iniciar seguimientos de
            vinculación desde esta sección.
        </p>
    </section>
<?php endif; ?>
