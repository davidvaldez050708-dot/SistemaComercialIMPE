<?php
/** La evidencia se verifica siempre en el servidor, nunca en JavaScript. */
?>
<dialog id="impe-v3i-dialog" class="impe-embudo-dialog impe-v3b-dialog impe-v3i-dialog"
        aria-labelledby="impe-v3i-titulo">
    <form id="impe-v3i-form" method="dialog">
        <header>
            <h3 id="impe-v3i-titulo">Inicio de inscripción institucional</h3>
            <button type="button" data-v3i-cerrar aria-label="Cerrar">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </header>
        <div class="impe-v3b-form-cuerpo">
            <p class="impe-v3b-datos" id="impe-v3i-datos"></p>
            <p class="impe-v3b-ayuda">
                Antes de pasar a Proceso de Inscripción, debe existir un
                formulario institucional recibido y vinculado a este prospecto.
                Este paso no confirma pagos ni inscripción definitiva.
            </p>
            <p class="impe-v3i-link">
                <a href="<?= $esc(BASE_URL.'index.php?controller=formularioPublico&action=inscripcion') ?>"
                   target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                    Abrir formulario público de inscripción
                </a>
            </p>
            <button type="button" class="impe-v3i-verificar" data-v3i-buscar>
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                Buscar solicitud recibida
            </button>
            <p class="impe-v3i-estado" data-v3i-estado role="status" aria-live="polite">
                La búsqueda será por móvil y correo; si existen varias coincidencias,
                se requiere revisión institucional.
            </p>
            <section data-v3i-confirmar hidden>
                <label>Motivo de validación
                    <textarea name="motivo" required minlength="8" maxlength="125" rows="3"
                              placeholder="Confirma los datos revisados y el inicio del trámite"></textarea>
                </label>
                <label class="impe-v3b-confirmacion">
                    <input type="checkbox" name="confirmacion" value="1">
                    <span>Confirmo que revisé la solicitud del prospecto.
                    Esto inicia su trámite; no confirma pago ni matrícula.</span>
                </label>
            </section>
            <p class="impe-v3b-error" data-v3i-error role="alert" hidden></p>
        </div>
        <div class="impe-embudo-dialog-footer">
            <button type="button" data-v3i-cerrar>Cancelar</button>
            <button type="submit" data-v3i-guardar disabled>Vincular solicitud</button>
        </div>
    </form>
</dialog>
