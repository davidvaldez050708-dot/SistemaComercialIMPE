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
                Inicia el trámite cuando tengas los documentos listos y hayas recibido el comprobante.
                El prospecto adjuntará su comprobante en el formulario.
            </p>
            <section data-v3i-preparar hidden>
                <label class="impe-v3b-confirmacion"><input type="checkbox" name="documentos" value="1"><span>Documentos listos</span></label>
                <label class="impe-v3b-confirmacion"><input type="checkbox" name="comprobante" value="1"><span>Comprobante recibido</span></label>
            </section>
            <div class="impe-v3i-link" data-v3i-ligas hidden>
                <label for="impe-v3i-programa">Elige el programa del prospecto</label>
                <select id="impe-v3i-programa" data-v3i-programa>
                    <option value="">Selecciona un programa</option>
                    <option value="bachillerato-en-2-anos">Bachillerato en 2 años</option>
                    <option value="bachillerato-286">Bachillerato General</option>
                    <option value="ingles">Inglés</option>
                    <option value="titulacion-ejecutivas">Titulación - Ejecutivas</option>
                    <option value="titulacion-experiencia-laboral">Titulación por experiencia laboral</option>
                </select>
                <button type="button" class="impe-v3i-verificar" data-v3i-copiar hidden>Copiar enlace de inscripción</button>
                <input type="url" data-v3i-url readonly hidden aria-label="Enlace de inscripción">
                <a data-v3i-enlace data-base="<?= $esc(BASE_URL.'ya-estas-inscrito/') ?>"
                   hidden target="_blank" rel="noopener noreferrer">Vista previa del formulario</a>
            </div>
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
                    Esto cierra la asignación comercial. El pago conserva su estado de revisión.</span>
                </label>
            </section>
            <p class="impe-v3b-error" data-v3i-error role="alert" hidden></p>
        </div>
        <div class="impe-embudo-dialog-footer">
            <button type="button" data-v3i-cerrar>Cancelar</button>
            <button type="button" data-v3i-arrancar hidden>Iniciar inscripción</button>
            <button type="submit" data-v3i-guardar hidden disabled>Vincular solicitud</button>
        </div>
    </form>
</dialog>
