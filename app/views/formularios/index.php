<div class="formularios-page">
    <section class="dashboard-panel formularios-hero">
        <div class="formularios-hero-copy">
            <span class="formularios-hero-icon" aria-hidden="true">
                <i class="bi bi-ui-checks-grid"></i>
            </span>

            <div>
                <span class="formularios-kicker">FORMULARIOS</span>
                <h2>Selecciona el formulario que deseas gestionar</h2>
                <p>
                    Accede de manera independiente a las vistas de Registro e Inscripción.
                </p>
            </div>
        </div>

        <div class="formularios-hero-visual" aria-hidden="true">
            <span class="formularios-hero-sheet is-back"></span>
            <span class="formularios-hero-sheet is-front">
                <i class="bi bi-person-badge"></i>
            </span>
            <i class="bi bi-cursor-fill formularios-hero-cursor"></i>
        </div>
    </section>

    <section class="formularios-options" aria-label="Tipos de formulario">
        <a
            class="dashboard-panel formularios-option-card is-registro"
            href="<?= BASE_URL ?>index.php?controller=formulario&action=registro">
            <div class="formularios-card-decoration" aria-hidden="true"></div>

            <div class="formularios-option-top">
                <span class="formularios-option-icon is-registro" aria-hidden="true">
                    <i class="bi bi-person-plus"></i>
                </span>

                <span class="formularios-option-arrow" aria-hidden="true">
                    <i class="bi bi-arrow-up-right"></i>
                </span>
            </div>

            <div class="formularios-option-copy">
                <span class="formularios-option-label">Formulario</span>
                <h3>Registro</h3>
                <p>
                    Ingresa a la vista independiente destinada al proceso de registro.
                </p>
            </div>

            <div class="formularios-card-features" aria-hidden="true">
                <div>
                    <span><i class="bi bi-file-earmark-text"></i></span>
                    <small>Configura campos y secciones</small>
                </div>
                <div>
                    <span><i class="bi bi-people"></i></span>
                    <small>Gestiona respuestas</small>
                </div>
                <div>
                    <span><i class="bi bi-gear"></i></span>
                    <small>Personaliza el formulario</small>
                </div>
            </div>

            <span class="formularios-option-action">
                Abrir formulario
                <i class="bi bi-arrow-right"></i>
            </span>
        </a>

        <a
            class="dashboard-panel formularios-option-card is-inscripcion"
            href="<?= BASE_URL ?>index.php?controller=formulario&action=inscripcion">
            <div class="formularios-card-decoration" aria-hidden="true"></div>

            <div class="formularios-option-top">
                <span class="formularios-option-icon is-inscripcion" aria-hidden="true">
                    <i class="bi bi-file-earmark-check"></i>
                </span>

                <span class="formularios-option-arrow" aria-hidden="true">
                    <i class="bi bi-arrow-up-right"></i>
                </span>
            </div>

            <div class="formularios-option-copy">
                <span class="formularios-option-label">Formulario</span>
                <h3>Inscripción</h3>
                <p>
                    Ingresa a la vista independiente destinada al proceso de inscripción.
                </p>
            </div>

            <div class="formularios-card-features" aria-hidden="true">
                <div>
                    <span><i class="bi bi-file-earmark-text"></i></span>
                    <small>Configura campos y secciones</small>
                </div>
                <div>
                    <span><i class="bi bi-people"></i></span>
                    <small>Gestiona respuestas</small>
                </div>
                <div>
                    <span><i class="bi bi-gear"></i></span>
                    <small>Personaliza el formulario</small>
                </div>
            </div>

            <span class="formularios-option-action">
                Abrir formulario
                <i class="bi bi-arrow-right"></i>
            </span>
        </a>
    </section>
</div>
