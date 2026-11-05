<?php
$programasInscripcion = is_array($programasInscripcion ?? null)
    ? $programasInscripcion
    : [];
$texto = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<div class="formularios-page formularios-detail-page formularios-inscripcion-theme">
    <div class="formularios-back-row">
        <a class="data-back-link"
           href="<?= BASE_URL ?>index.php?controller=formulario&amp;action=index">
            <i class="bi bi-arrow-left" aria-hidden="true"></i>
            Volver a formularios
        </a>
    </div>

    <section class="dashboard-panel formularios-detail-hero">
        <div class="formularios-detail-heading">
            <span class="formularios-detail-icon is-inscripcion" aria-hidden="true">
                <i class="bi bi-file-earmark-check"></i>
            </span>
            <div>
                <span class="formularios-kicker">FORMULARIOS DE INSCRIPCIÓN</span>
                <h2>Selecciona el programa</h2>
                <p>Gestiona la captura de cada programa de manera independiente.</p>
            </div>
        </div>
    </section>

    <section class="formularios-inscripcion-catalogo" aria-label="Programas de inscripción">
        <div class="formularios-inscripcion-catalogo-heading">
            <div>
                <span class="formularios-kicker">PROGRAMAS</span>
                <h3>Formularios por programa</h3>
                <p>Las capturas internas están disponibles. Los enlaces públicos se habilitarán
                    cuando cada formulario incluya comprobantes y documentación legal.</p>
            </div>
            <span class="formularios-inscripcion-total">
                <i class="bi bi-ui-checks-grid" aria-hidden="true"></i>
                <?= count($programasInscripcion) ?> programas
            </span>
        </div>

        <div class="formularios-inscripcion-catalogo-grid">
            <?php foreach ($programasInscripcion as $programa): ?>
                <article class="dashboard-panel formularios-inscripcion-programa">
                    <div class="formularios-inscripcion-programa-head">
                        <span class="formularios-inscripcion-programa-icon" aria-hidden="true">
                            <i class="bi <?= $texto($programa['icono']) ?>"></i>
                        </span>
                        <span class="formularios-inscripcion-pendiente">
                            <i class="bi bi-clock" aria-hidden="true"></i>
                            Enlace público en preparación
                        </span>
                    </div>

                    <div class="formularios-inscripcion-programa-copy">
                        <span class="formularios-kicker">INSCRIPCIÓN</span>
                        <h4><?= $texto($programa['nombre']) ?></h4>
                        <p>Accede al formulario interno de este programa.</p>
                    </div>

                    <div class="formularios-inscripcion-programa-action">
                        <a class="btn btn-system-save"
                           href="<?= BASE_URL ?>index.php?controller=formulario&amp;action=inscripcion&amp;programa=<?= rawurlencode($programa['slug']) ?>">
                            Abrir formulario
                            <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="formularios-inscripcion-catalogo-nota">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            Todavía no se pueden compartir enlaces públicos de inscripción por programa.
            Las inscripciones públicas anteriores permanecen sin cambios.
        </p>
    </section>
</div>
